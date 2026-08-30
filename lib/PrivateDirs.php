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

        // UTF-8 BOM от блокнотных редакторов: иначе первое правило приезжает
        // как "\xEF\xBB\xBF203.0.113.42" и молча не матчится.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $rules = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            // Комментарий может идти и в конце строки: "10.0.0.0/8  # офис"
            $line = (string)preg_replace('/#.*$/', '', $line);
            $rules[] = self::cleanToken($line);
        }
        return array_values(array_filter($rules, static fn(string $r): bool => $r !== ''));
    }

    /**
     * Чистка строки правила от невидимого мусора.
     *
     * IP часто копируют с сайтов «какой у меня IP», и вместе с адресом
     * приезжают неразрывные пробелы, zero-width и LTR/RTL-марки. Обычный trim()
     * их не берёт, inet_pton на таком падает, и правило тихо не срабатывает —
     * симптом «вписал свой IP, а каталог всё равно не виден».
     */
    private static function cleanToken(string $s): string
    {
        // NBSP, узкий NBSP, zero-width space/joiner, BOM в середине, LTR/RTL-марки
        $cleaned = preg_replace(
            '/[\x{00A0}\x{202F}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{200E}\x{200F}]/u',
            '',
            $s
        );
        // На битом UTF-8 preg_replace с /u вернёт null — тогда работаем с
        // исходной строкой, а не молча теряем правило
        return trim($cleaned ?? $s);
    }

    /**
     * Список доверенных прокси из config/trusted-proxies.txt.
     *
     * Пустой список (файла нет или всё закомментировано) = не доверяем никому,
     * X-Forwarded-For игнорируется.
     *
     * @return list<string>
     */
    public static function trustedProxies(string $configDir): array
    {
        return self::parseAllowlist($configDir . DIRECTORY_SEPARATOR . 'trusted-proxies.txt');
    }

    /**
     * IP клиента.
     *
     * По умолчанию — только REMOTE_ADDR: X-Forwarded-For подделывается кем
     * угодно, и слепое доверие к нему открыло бы приватный листинг любому, кто
     * подставит заголовок.
     *
     * Заголовок читается ТОЛЬКО если сам REMOTE_ADDR попал в список доверенных
     * прокси. Это классическая связка nginx → Apache → PHP (aaPanel): Apache
     * видит соединение с 127.0.0.1, а настоящий адрес приезжает в заголовке.
     * Цепочка XFF разбирается справа налево — первый адрес, не являющийся
     * доверенным прокси, и есть клиент (то, что левее, мог дописать он сам).
     *
     * Более чистая альтернатива — починить на уровне веб-сервера
     * (Apache mod_remoteip, nginx set_real_ip_from), тогда сюда приедет уже
     * настоящий REMOTE_ADDR и список доверенных прокси не нужен.
     *
     * @param array<string,mixed> $server         обычно $_SERVER
     * @param list<string>        $trustedProxies IP/CIDR прокси, которым доверяем
     */
    public static function clientIp(array $server, array $trustedProxies = []): ?string
    {
        $remote = self::cleanToken((string)($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return null;
        }
        // Не доверяем никому либо пришли не от прокси — адрес как есть
        if ($trustedProxies === [] || !self::ipAllowed($remote, $trustedProxies)) {
            return $remote;
        }

        // Цепочка X-Forwarded-For: client, proxy1, proxy2...
        $chain = [];
        foreach (explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? '')) as $part) {
            $p = self::cleanToken($part);
            if ($p !== '' && self::toBinary($p) !== null) {
                $chain[] = $p;
            }
        }
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::ipAllowed($chain[$i], $trustedProxies)) {
                return $chain[$i];
            }
        }
        if ($chain !== []) {
            return $chain[0];   // вся цепочка из доверенных прокси
        }

        // XFF нет — пробуем X-Real-IP (nginx ставит его одним значением)
        $real = self::cleanToken((string)($server['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && self::toBinary($real) !== null) {
            return $real;
        }
        return $remote;
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
        $rule = self::cleanToken($rule);
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
        $ip = self::cleanToken($ip);
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
