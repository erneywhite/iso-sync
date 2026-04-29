<?php
declare(strict_types=1);

namespace IsoSync;

use RuntimeException;

/**
 * Описание одной записи из config/iso-list.json.
 *
 * Поддерживаются четыре режима выбора удалённого файла:
 *
 *   1. Fixed — `remote_name: "ubuntu-22.04.5-live-server-amd64.iso"`.
 *      Качаем именно это имя из url_dir.
 *
 *   2. Latest — `remote_name: "latest"` + `latest_pattern: "/regex/"`.
 *      Среди файлов из SHA256SUMS, совпавших по regex, выбираем версионно-старший
 *      (strnatcasecmp). Локальное имя файла фиксированное (= ключ записи в JSON).
 *
 *   3. Family — `remote_pattern: "/regex/"` + `local_name_template: "Name_{1}.iso"`
 *      + опц. `cleanup_old: true`. Среди файлов в SHA256SUMS, совпавших по regex,
 *      выбираем версионно-старший, имя локального файла строим по шаблону, подставляя
 *      {1}, {2}, ... — capture groups. Если `cleanup_old: true`, после успешной
 *      загрузки удаляются братья в той же подпапке, чьё имя матчится под template
 *      (но не равно текущему).
 *
 *   4. Discovery — `url_template: "https://...{folder}/"` + `folder_enum: {...}`
 *      + `remote_pattern` + `local_name_template`. Скрипт сам генерирует список
 *      имён папок по диапазону (например 22.04..30.04 для Ubuntu LTS),
 *      пробует скачать SHA256SUMS из каждой, и для каждой существующей запускает
 *      family-обработку с подставленным {folder}. Используется когда упстрим
 *      разносит версии по разным папкам и хочется подхватывать будущие релизы
 *      без ручной правки конфига.
 */
final class IsoEntry
{
    /**
     * @param array<int,string>|null $checksumFiles
     * @param array{from:int,to:int,step:int,format:string}|null $folderEnum
     */
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
        // family
        public readonly ?string $remotePattern,
        public readonly ?string $localNameTemplate,
        public readonly bool $cleanupOld,
        // discovery
        public readonly ?string $urlTemplate,
        public readonly ?array $folderEnum,
    ) {}

    public function isDiscovery(): bool
    {
        return $this->urlTemplate !== null;
    }

    public function isFamily(): bool
    {
        return !$this->isDiscovery() && $this->remotePattern !== null;
    }

    public function isLatest(): bool
    {
        return !$this->isDiscovery() && !$this->isFamily()
            && ($this->remoteName === 'latest' || $this->remoteName === '');
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
        $hasName     = isset($info['remote_name']);
        $hasPattern  = isset($info['remote_pattern']);
        $hasTemplate = isset($info['url_template']);

        // Определяем режим
        $modes = (int)$hasName + (int)$hasPattern + (int)$hasTemplate;
        if ($modes === 0) {
            throw new RuntimeException(
                "Запись '{$localName}': требуется один из ключей: 'remote_name' (fixed/latest), " .
                "'remote_pattern' (family) или 'url_template' (discovery)"
            );
        }
        if ($modes > 1) {
            throw new RuntimeException(
                "Запись '{$localName}': 'remote_name', 'remote_pattern' и 'url_template' взаимоисключающи"
            );
        }

        // === Discovery ===
        $urlTemplate = null;
        $folderEnum  = null;
        if ($hasTemplate) {
            $urlTemplate = (string)$info['url_template'];
            if (!str_contains($urlTemplate, '{folder}')) {
                throw new RuntimeException("Запись '{$localName}': 'url_template' должен содержать '{folder}' плейсхолдер");
            }
            if (!isset($info['folder_enum']) || !is_array($info['folder_enum'])) {
                throw new RuntimeException("Запись '{$localName}': при 'url_template' обязателен объект 'folder_enum'");
            }
            $folderEnum = self::parseFolderEnum($localName, $info['folder_enum']);

            if (!isset($info['remote_pattern'])) {
                throw new RuntimeException("Запись '{$localName}': discovery-режим требует 'remote_pattern'");
            }
            if (!isset($info['local_name_template'])) {
                throw new RuntimeException("Запись '{$localName}': discovery-режим требует 'local_name_template'");
            }
            // url_dir в discovery не нужен
            if (isset($info['url_dir'])) {
                throw new RuntimeException("Запись '{$localName}': в discovery не используется 'url_dir' (URL строится из 'url_template')");
            }
        } else {
            // fixed/latest/family: url_dir обязателен
            if (!isset($info['url_dir'])) {
                throw new RuntimeException("Запись '{$localName}': обязательно поле 'url_dir'");
            }
        }

        // === Family / Discovery: общие поля remote_pattern + local_name_template ===
        $remotePattern = null;
        $localTemplate = null;
        if ($hasPattern || $hasTemplate) {
            $remotePattern = (string)$info['remote_pattern'];
            // В discovery шаблон может содержать {folder} который не валидный regex до подстановки
            $patternForValidation = $hasTemplate
                ? str_replace('{folder}', 'placeholder', $remotePattern)
                : $remotePattern;
            if (@preg_match($patternForValidation, '') === false) {
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
            urlDir:                       isset($info['url_dir']) ? rtrim((string)$info['url_dir'], '/') . '/' : '',
            remoteName:                   $hasName ? (string)$info['remote_name'] : '',
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
            urlTemplate:                  $urlTemplate,
            folderEnum:                   $folderEnum,
        );
    }

    /**
     * @param array<string,mixed> $info
     * @return array{from:int,to:int,step:int,format:string}
     */
    private static function parseFolderEnum(string $localName, array $info): array
    {
        if (!isset($info['from'], $info['to'])) {
            throw new RuntimeException("Запись '{$localName}': 'folder_enum' требует 'from' и 'to'");
        }
        $from = (int)$info['from'];
        $to   = (int)$info['to'];
        $step = isset($info['step']) ? (int)$info['step'] : 1;
        $format = isset($info['format']) ? (string)$info['format'] : '{0}';

        if ($step <= 0) {
            throw new RuntimeException("Запись '{$localName}': 'folder_enum.step' должен быть > 0");
        }
        if ($from > $to) {
            throw new RuntimeException("Запись '{$localName}': 'folder_enum.from' > 'folder_enum.to'");
        }
        if (!str_contains($format, '{0}')) {
            throw new RuntimeException("Запись '{$localName}': 'folder_enum.format' должен содержать '{0}' плейсхолдер");
        }

        return [
            'from'   => $from,
            'to'     => $to,
            'step'   => $step,
            'format' => $format,
        ];
    }
}
