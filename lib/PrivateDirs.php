<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Приватные каталоги витрины.
 *
 * Каталог внутри files/ считается приватным, если в нём лежит файл-маркер
 * `.private`. Такой каталог не попадает ни в листинг, ни в тоталы, ни в
 * спарклайн, ни в «Историю».
 *
 * Маркер может быть пустым (приватно для всех), а может содержать allowlist —
 * IP-адреса и/или подсети, с которых каталог всё-таки показывается:
 *
 *     # дом
 *     203.0.113.42
 *     # офис
 *     198.51.100.0/24
 *     2001:db8:1234::/48
 *
 * Пустые строки и строки, начинающиеся с `#`, игнорируются.
 *
 * ВАЖНО: это только витрина. Сам факт скрытия из UI НЕ мешает скачать файл по
 * прямой ссылке — раздачу закрывает веб-сервер. Тот же список IP надо прописать
 * в nginx (`allow` / `deny all`), см. docs/PRIVATE-DIRS.md.
 */
final class PrivateDirs
{
    public const MARKER = '.private';

    /**
     * Разбор маркера в список правил.
     *
     * @return list<string> пустой список = приватно для всех
     */
    public static function parseAllowlist(string $markerPath): array
    {
        $raw = @file_get_contents($markerPath);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $rules = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            // Комментарий может идти и в конце строки: "10.0.0.0/8  # офис"
            $line = trim((string)preg_replace('/#.*$/', '', $line));
            if ($line !== '') {
                $rules[] = $line;
            }
        }
        return $rules;
    }

    /**
     * IP клиента.
     *
     * Намеренно берём только REMOTE_ADDR и НЕ смотрим X-Forwarded-For: этот
     * заголовок подделывается кем угодно, и доверие к нему открыло бы приватный
     * листинг любому, кто его подставит. Если сайт стоит за CDN/прокси и
     * REMOTE_ADDR показывает адрес прокси — чинить надо на уровне nginx
     * (ngx_http_realip_module: set_real_ip_from + real_ip_header), тогда сюда
     * приедет уже настоящий адрес клиента.
     *
     * @param array<string,mixed> $server обычно $_SERVER
     */
    public static function clientIp(array $server): ?string
    {
        $ip = trim((string)($server['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : null;
    }

    /**
     * Подходит ли IP хотя бы под одно правило allowlist.
     *
     * @param list<string> $rules
     */
    public static function ipAllowed(?string $ip, array $rules): bool
    {
        if ($ip === null || $rules === []) {
            return false;
        }
        foreach ($rules as $rule) {
            if (self::matchRule($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Одно правило: literal-IP («203.0.113.42») либо CIDR («10.0.0.0/8»).
     * Поддерживаются обе версии протокола. Кривое правило = не совпало
     * (fail-closed: приватное остаётся приватным).
     */
    public static function matchRule(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        $prefix = null;
        $net    = $rule;
        if (str_contains($rule, '/')) {
            [$net, $lenRaw] = explode('/', $rule, 2);
            $lenRaw = trim($lenRaw);
            // Только числовая длина префикса; форма 255.255.255.0 не поддержана
            if ($lenRaw === '' || !ctype_digit($lenRaw)) {
                return false;
            }
            $prefix = (int)$lenRaw;
        }

        $ipBin  = self::toBinary($ip);
        $netBin = self::toBinary($net);
        if ($ipBin === null || $netBin === null) {
            return false;
        }
        // v4-правило против v6-адреса (и наоборот) — разная длина, не совпадает
        if (strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($prefix === null) {
            $prefix = $maxBits;
        }
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }
        if ($prefix === 0) {
            return true;  // ::/0 или 0.0.0.0/0 — «откуда угодно»
        }

        $fullBytes = intdiv($prefix, 8);
        $restBits  = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $restBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /**
     * Скан files/: что скрыть от этого клиента.
     *
     * @return array{dirs: array<string,true>, files: array<string,true>}
     *   dirs  — имена каталогов, которые не показываем
     *   files — basename'ы файлов внутри них (чтобы вычистить «Историю»)
     */
    public static function scan(string $filesDir, ?string $clientIp): array
    {
        $dirs  = [];
        $files = [];
        if (!is_dir($filesDir)) {
            return ['dirs' => $dirs, 'files' => $files];
        }

        foreach (scandir($filesDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path   = $filesDir . DIRECTORY_SEPARATOR . $name;
            $marker = $path . DIRECTORY_SEPARATOR . self::MARKER;
            if (!is_dir($path) || !is_file($marker)) {
                continue;
            }

            // Клиент из allowlist видит каталог как обычный
            if (self::ipAllowed($clientIp, self::parseAllowlist($marker))) {
                continue;
            }

            $dirs[$name] = true;
            foreach (scandir($path) ?: [] as $c) {
                if (str_starts_with($c, '.')) {
                    continue;
                }
                if (is_file($path . DIRECTORY_SEPARATOR . $c)) {
                    $files[$c] = true;
                }
            }
        }

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Приватен ли каталог сам по себе (независимо от того, кто смотрит).
     * Нужно, чтобы пометить в UI то, что видно только по allowlist.
     */
    public static function isPrivate(string $dirPath): bool
    {
        return is_file($dirPath . DIRECTORY_SEPARATOR . self::MARKER);
    }

    /**
     * Нормализация адреса в бинарный вид для побитового сравнения.
     */
    private static function toBinary(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        // Форма [::1] встречается в логах и у части прокси
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        // IPv4-mapped (::ffff:203.0.113.5) сводим к чистому v4: иначе правило
        // «203.0.113.5» не совпало бы с клиентом, пришедшим в dual-stack сокет.
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $tail;
            }
        }
        $bin = @inet_pton($ip);
        return $bin === false ? null : $bin;
    }
}
