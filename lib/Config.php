<?php
declare(strict_types=1);

namespace IsoSync;

use RuntimeException;

/**
 * Описание одной записи из config/iso-list.json.
 * Поля типизированы и нормализованы; дефолты прописаны здесь.
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
    ) {}

    public function isLatest(): bool
    {
        return $this->remoteName === 'latest' || $this->remoteName === '';
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
            $entries[$localName] = self::parseEntry((string)$localName, $info);
        }

        return new self($entries);
    }

    /** @param array<string,mixed> $info */
    private static function parseEntry(string $localName, array $info): IsoEntry
    {
        if (!isset($info['url_dir'], $info['remote_name'])) {
            throw new RuntimeException("Запись '{$localName}': обязательны поля 'url_dir' и 'remote_name'");
        }

        $gpg = $info['gpg'] ?? null;

        return new IsoEntry(
            localName:                    $localName,
            localSubdir:                  (string)($info['local_subdir'] ?? ''),
            urlDir:                       rtrim((string)$info['url_dir'], '/') . '/',
            remoteName:                   (string)$info['remote_name'],
            forceDownloadWithoutChecksum: (bool)($info['force_download_without_checksum'] ?? false),
            skipIfUnchanged:              (bool)($info['skip_if_unchanged'] ?? false),
            insecureSsl:                  (bool)($info['insecure_ssl'] ?? false),
            latestPattern:                (string)($info['latest_pattern'] ?? '/dvd/i'),
            checksumFiles:                isset($info['checksum_files']) && is_array($info['checksum_files'])
                ? array_values(array_map('strval', $info['checksum_files']))
                : null,
            gpgSignatureUrl:              is_array($gpg) && isset($gpg['signature_url']) ? (string)$gpg['signature_url'] : null,
            gpgKeyFingerprint:            is_array($gpg) && isset($gpg['key_fingerprint']) ? (string)$gpg['key_fingerprint'] : null,
        );
    }
}
