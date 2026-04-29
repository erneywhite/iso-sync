<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Универсальный парсер форматов SHA256SUMS / SHA256SUM / CHECKSUM.
 *
 * Поддерживает:
 *  - GNU coreutils:  "<hash>  filename" или "<hash> *filename"
 *  - GNU с произвольным числом пробелов
 *  - BSD:            "SHA256 (filename) = <hash>"
 *  - имена с пробелами (BSD-формат и обратный слэш-эскейп GNU: "\\")
 *
 * Возвращает map: filename => lowercase-hex hash.
 */
final class ChecksumParser
{
    /**
     * @return array<string,string>
     */
    public static function parse(string $content): array
    {
        $hashes = [];
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // GNU: "<64 hex>  [*]filename"
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', $line, $m)) {
                $name = self::unescapeGnu($m[2]);
                $hashes[$name] = strtolower($m[1]);
                continue;
            }

            // BSD: "SHA256 (filename) = <64 hex>"
            if (preg_match('/^SHA(?:2)?-?256\s*\((.+)\)\s*=\s*([a-f0-9]{64})$/i', $line, $m)) {
                $hashes[$m[1]] = strtolower($m[2]);
                continue;
            }
        }

        return $hashes;
    }

    /**
     * GNU coreutils эскейпит spec-символы в имени файла, добавляя префикс '\' к строке.
     * Формально: '\\' -> '\', '\n' -> "\n". Поддерживаем минимально необходимое.
     */
    private static function unescapeGnu(string $name): string
    {
        // sha256sum --tag не использует префикс, обычные строки тоже редко эскейплены.
        // Покрываем самый частый случай: строка может начинаться с '\' если содержит '\\' или '\n'.
        if (str_starts_with($name, '\\')) {
            $name = substr($name, 1);
            $name = strtr($name, ['\\\\' => '\\', '\\n' => "\n"]);
        }
        return $name;
    }
}
