<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Кэш SHA256-хэшей. Ключ — md5(абсолютный путь). Инвалидация по mtime+size.
 *
 * Унифицированный формат записи (всегда сохраняется так):
 *   {
 *     "hash":  "sha256:<64 hex>",
 *     "mtime": <int>,
 *     "size":  <int>
 *   }
 *
 * При чтении старый формат без префикса корректно нормализуется.
 */
final class HashCache
{
    public function __construct(
        private readonly string $cacheDir
    ) {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Возвращает кэшированный хэш в формате "sha256:..." или null, если кэша нет / он устарел.
     * НЕ вычисляет хэш сам (это делает getOrCompute()).
     */
    public function get(string $localPath): ?string
    {
        $cacheFile = $this->cacheFileFor($localPath);
        if (!is_file($cacheFile) || !is_file($localPath)) {
            return null;
        }

        $data = @json_decode((string)@file_get_contents($cacheFile), true);
        if (!is_array($data) || !isset($data['hash'], $data['mtime'], $data['size'])) {
            return null;
        }

        if ((int)$data['mtime'] !== filemtime($localPath) || (int)$data['size'] !== filesize($localPath)) {
            return null;
        }

        return self::normalize((string)$data['hash']);
    }

    /**
     * Возвращает хэш, вычисляя при необходимости и сохраняя в кэш.
     * Возвращает в формате "sha256:..." или null, если файл недоступен.
     */
    public function getOrCompute(string $localPath): ?string
    {
        $cached = $this->get($localPath);
        if ($cached !== null) {
            return $cached;
        }

        if (!is_file($localPath) || !is_readable($localPath)) {
            return null;
        }

        $hex = hash_file('sha256', $localPath);
        if ($hex === false) {
            return null;
        }

        $value = 'sha256:' . $hex;
        $this->put($localPath, $value);
        return $value;
    }

    public function put(string $localPath, string $hashWithPrefix): void
    {
        $cacheFile = $this->cacheFileFor($localPath);

        $data = [
            'hash'  => self::normalize($hashWithPrefix),
            'mtime' => filemtime($localPath),
            'size'  => filesize($localPath),
        ];

        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function forget(string $localPath): void
    {
        $cacheFile = $this->cacheFileFor($localPath);
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Удаляет записи кэша, у которых нет соответствующего файла на диске.
     * @param iterable<string> $existingPaths
     * @return int количество удалённых записей
     */
    public function pruneOrphans(iterable $existingPaths): int
    {
        $valid = [];
        foreach ($existingPaths as $p) {
            $valid[md5($p)] = true;
        }

        $removed = 0;
        $iter = @scandir($this->cacheDir);
        if ($iter === false) {
            return 0;
        }
        foreach ($iter as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $this->cacheDir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($full)) continue;
            $base = pathinfo($name, PATHINFO_FILENAME);
            if (!isset($valid[$base])) {
                @unlink($full);
                $removed++;
            }
        }
        return $removed;
    }

    /** Возвращает только hex-часть хэша (без префикса sha256:). */
    public static function stripPrefix(?string $hash): ?string
    {
        if ($hash === null) return null;
        return str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
    }

    private function cacheFileFor(string $localPath): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5($localPath) . '.cache';
    }

    private static function normalize(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if (!str_starts_with($hash, 'sha256:')) {
            $hash = 'sha256:' . $hash;
        }
        return $hash;
    }
}
