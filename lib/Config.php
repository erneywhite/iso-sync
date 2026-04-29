<?php
declare(strict_types=1);

namespace IsoSync;

use RuntimeException;

/**
 * Описание одной записи из config/iso-list.json.
 *
 * Поддерживаются три режима выбора удалённого файла:
 *
 *   1. Фиксированный — `remote_name: "ubuntu-22.04.5-live-server-amd64.iso"`.
 *      Качаем именно это имя.
 *
 *   2. Легаси-`latest` — `remote_name: "latest"` + `latest_pattern: "/regex/"`.
 *      Среди файлов из SHA256SUMS, совпавших по regex'у, выбираем версионно-старший
 *      (strnatcasecmp). Локальное имя файла фиксированное (= ключ записи в JSON).
 *
 *   3. Family — `remote_pattern: "/regex/"` + `local_name_template: "Name_{1}.iso"`
 *      + опц. `cleanup_old: true`. Среди файлов в SHA256SUMS, совпавших по regex'у
 *      (regex должен иметь минимум одну capture group), выбираем версионно-старший,
 *      имя локального файла строим по шаблону, подставляя {1}, {2}, ... — capture
 *      groups. Если `cleanup_old: true`, после успешной загрузки удаляются братья
 *      в той же подпапке, чьё имя матчится под template-как-regex (но не равно
 *      текущему).
 */
final class IsoEntry
{
    /** @param array<int,string>|null $checksumFiles */
    public function __construct(
        public readonly string $localName,
        public readonly string $localSubdir,
        public readonly string $urlDir,
        public readonly string $remoteName,
        public readonly bool $forceDownloadWithoutChecksum,
        public readonly bool $skipIfUnchanged,
        public readonly bool $insecureSsl,
        public readonly string $latestPattern,
        public readonly ?array $checksumFiles,
        public readonly ?string $gpgSignatureUrl,
        public readonly ?string $gpgKeyFingerprint,
        // family mode
        public readonly ?string $remotePattern,
        public readonly ?string $localNameTemplate,
        public readonly bool $cleanupOld,
    ) {}

    public function isFamily(): bool
    {
        return $this->remotePattern !== null;
    }

    public function isLatest(): bool
    {
        return !$this->isFamily() && ($this->remoteName === 'latest' || $this->remoteName === '');
    }
}

final class Config
{
    /** @var array<string,IsoEntry> */
    public readonly array $files;

    /** @param array<string,IsoEntry> $files */
    public function __construct(array $files)
    {
        $this->files = $files;
    }

    public static function loadFromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Конфиг не найден или нечитаем: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать конфиг: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Невалидный JSON в {$path}: " . $e->getMessage(), 0, $e);
        }

        if (!isset($data['files']) || !is_array($data['files'])) {
            throw new RuntimeException("В {$path} отсутствует ключ 'files'");
        }

        $entries = [];
        foreach ($data['files'] as $localName => $info) {
            // Ключи, начинающиеся с '_', считаются комментариями и пропускаются.
            // Удобно для пометок в JSON: "_comment_proxmox": "...что описывает блок"
            if (is_string($localName) && str_starts_with($localName, '_')) {
                continue;
            }
            if (!is_array($info)) {
                throw new RuntimeException("Запись '{$localName}': ожидается объект, получен " . gettype($info));
            }
            $entries[$localName] = self::parseEntry((string)$localName, $info);
        }

        return new self($entries);
    }

    /** @param array<string,mixed> $info */
    private static function parseEntry(string $localName, array $info): IsoEntry
    {
        if (!isset($info['url_dir'])) {
            throw new RuntimeException("Запись '{$localName}': обязательно поле 'url_dir'");
        }

        $hasRemoteName    = isset($info['remote_name']);
        $hasRemotePattern = isset($info['remote_pattern']);

        if (!$hasRemoteName && !$hasRemotePattern) {
            throw new RuntimeException("Запись '{$localName}': требуется либо 'remote_name', либо 'remote_pattern'");
        }
        if ($hasRemoteName && $hasRemotePattern) {
            throw new RuntimeException("Запись '{$localName}': 'remote_name' и 'remote_pattern' взаимоисключающи");
        }

        $remotePattern    = null;
        $localTemplate    = null;
        if ($hasRemotePattern) {
            $remotePattern = (string)$info['remote_pattern'];
            if (@preg_match($remotePattern, '') === false) {
                throw new RuntimeException("Запись '{$localName}': невалидный regex в 'remote_pattern'");
            }
            if (!isset($info['local_name_template'])) {
                throw new RuntimeException("Запись '{$localName}': при 'remote_pattern' обязателен 'local_name_template'");
            }
            $localTemplate = (string)$info['local_name_template'];
        }

        $gpg = $info['gpg'] ?? null;

        return new IsoEntry(
            localName:                    $localName,
            localSubdir:                  (string)($info['local_subdir'] ?? ''),
            urlDir:                       rtrim((string)$info['url_dir'], '/') . '/',
            remoteName:                   $hasRemoteName ? (string)$info['remote_name'] : '',
            forceDownloadWithoutChecksum: (bool)($info['force_download_without_checksum'] ?? false),
            skipIfUnchanged:              (bool)($info['skip_if_unchanged'] ?? false),
            insecureSsl:                  (bool)($info['insecure_ssl'] ?? false),
            latestPattern:                (string)($info['latest_pattern'] ?? '/dvd/i'),
            checksumFiles:                isset($info['checksum_files']) && is_array($info['checksum_files'])
                ? array_values(array_map('strval', $info['checksum_files']))
                : null,
            gpgSignatureUrl:              is_array($gpg) && isset($gpg['signature_url']) ? (string)$gpg['signature_url'] : null,
            gpgKeyFingerprint:            is_array($gpg) && isset($gpg['key_fingerprint']) ? (string)$gpg['key_fingerprint'] : null,
            remotePattern:                $remotePattern,
            localNameTemplate:            $localTemplate,
            cleanupOld:                   (bool)($info['cleanup_old'] ?? false),
        );
    }
}
