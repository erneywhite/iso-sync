<?php
declare(strict_types=1);

/**
 * Временный диагностический эндпоинт: показывает, каким сервер видит клиента.
 *
 * Нужен потому, что REMOTE_ADDR существует только внутри HTTP-запроса — из CLI
 * его не увидеть. Открыть в браузере: https://iso.erney.monster/whoami.php
 *
 * УДАЛИТЬ ПОСЛЕ ИСПОЛЬЗОВАНИЯ:
 *     rm /www/wwwroot/iso.erney.monster/whoami.php
 *
 * Сам по себе он показывает посетителю только его собственный адрес (который тот
 * и так знает), но заодно раскрывает заголовки прокси — держать постоянно смысла нет.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\PrivateDirs;

$filesDir = __DIR__ . '/files';
$trusted  = PrivateDirs::trustedProxies(__DIR__ . '/config');
$remote   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ip       = PrivateDirs::clientIp($_SERVER, $trusted);

echo "=== Каким тебя видит сервер ===\n\n";

echo "REMOTE_ADDR       : " . ($remote !== '' ? $remote : '(не определён)') . "\n";
echo "доверенных прокси : " . (count($trusted) ?: '0 (X-Forwarded-For игнорируется)') . "\n";
foreach ($trusted as $t) {
    echo "    " . $t . "\n";
}
echo "итоговый IP       : " . ($ip ?? '(не определён)') . "\n";
echo "  ^ именно это значение сравнивается с правилами в .private\n\n";

/* ─── Признаки прокси ─── */
$fwdKeys = [
    'HTTP_X_FORWARDED_FOR',
    'HTTP_X_REAL_IP',
    'HTTP_CF_CONNECTING_IP',
    'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_CLIENT_IP',
    'HTTP_FORWARDED',
    'HTTP_X_FORWARDED_PROTO',
    'HTTP_CF_RAY',
];
$present = [];
foreach ($fwdKeys as $k) {
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
        $present[$k] = (string)$_SERVER[$k];
    }
}

if ($present === []) {
    echo "Заголовков прокси нет — REMOTE_ADDR это и есть реальный клиент.\n\n";
} else {
    echo "Заголовки прокси (значит перед сайтом что-то стоит):\n";
    foreach ($present as $k => $v) {
        echo sprintf("  %-24s %s\n", $k, $v);
    }
    echo "\n";
    $realCandidate = $present['HTTP_CF_CONNECTING_IP']
        ?? $present['HTTP_X_REAL_IP']
        ?? (isset($present['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $present['HTTP_X_FORWARDED_FOR'])[0])
            : null);
    if ($realCandidate !== null && $realCandidate !== $ip) {
        $hdr = isset($present['HTTP_CF_CONNECTING_IP']) ? 'CF-Connecting-IP' : 'X-Forwarded-For';
        // Петля/приватная сеть в REMOTE_ADDR = локальный обратный прокси
        // (обычно nginx → Apache), а не внешний CDN.
        $isLocalProxy = $remote !== '' && (
            $remote === '127.0.0.1' || $remote === '::1'
            || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
        );
        $sapi = PHP_SAPI;
        $viaApache = str_contains(strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? '')), 'apache')
            || str_contains($sapi, 'apache');

        echo "!! Настоящий адрес клиента: {$realCandidate}\n";
        echo "   PHP видит {$remote} — это прокси, поэтому allowlist не срабатывает.\n\n";
        echo "   SAPI: {$sapi}   SERVER_SOFTWARE: "
            . ((string)($_SERVER['SERVER_SOFTWARE'] ?? '?')) . "\n\n";

        if ($isLocalProxy && $viaApache) {
            echo "   Топология: nginx (фронт) → Apache → PHP.\n";
            echo "   Чинить надо в APACHE — set_real_ip_from это директива nginx,\n";
            echo "   а REMOTE_ADDR сюда ставит именно Apache:\n\n";
            echo "     # a2enmod remoteip  (или LoadModule remoteip_module ...)\n";
            echo "     RemoteIPHeader        {$hdr}\n";
            echo "     RemoteIPInternalProxy {$remote}\n\n";
            echo "   Затем перезапустить Apache.\n\n";
        } elseif ($isLocalProxy) {
            echo "   Топология: локальный обратный прокси перед PHP.\n";
            echo "   Чинить на стороне того сервера, который запускает PHP:\n";
            echo "     Apache : RemoteIPHeader {$hdr} + RemoteIPInternalProxy {$remote}\n";
            echo "     nginx  : set_real_ip_from {$remote}; real_ip_header {$hdr};\n\n";
        } else {
            echo "   Похоже на внешний CDN/прокси. В nginx:\n\n";
            echo "     set_real_ip_from {$remote};\n";
            echo "     real_ip_header   {$hdr};\n\n";
        }

        echo "   Быстрая альтернатива без правки конфигов веб-сервера —\n";
        echo "   раскомментировать адрес прокси в config/trusted-proxies.txt:\n\n";
        echo "     {$remote}\n\n";
        echo "   Но сперва убедись, что до бэкенда нельзя достучаться снаружи\n";
        echo "   (иначе заголовок подставит кто угодно):  ss -tlnp | grep 8181\n\n";
    }
}

/* ─── Вердикт по каждому приватному каталогу ─── */
echo "=== Приватные каталоги ===\n\n";

$found = 0;
foreach (scandir($filesDir) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    $dir    = $filesDir . DIRECTORY_SEPARATOR . $name;
    $marker = $dir . DIRECTORY_SEPARATOR . PrivateDirs::MARKER;
    if (!is_dir($dir) || !is_file($marker)) continue;

    $found++;
    $rules = PrivateDirs::parseAllowlist($marker);
    $allow = PrivateDirs::ipAllowed($ip, $rules);

    echo "files/{$name}/\n";
    echo "  правил в .private : " . (count($rules) ?: '0 (скрыт от всех)') . "\n";
    foreach ($rules as $r) {
        $hit = $ip !== null && PrivateDirs::matchRule($ip, $r);
        echo sprintf("    %s %s\n", $hit ? '✓' : '·', $r);
    }
    echo "  вердикт           : " . ($allow ? 'ВИДЕН тебе' : 'СКРЫТ от тебя') . "\n\n";
}

if ($found === 0) {
    echo "(каталогов с маркером .private не найдено)\n\n";
}

echo "=== Не забудь ===\n";
echo "rm " . __FILE__ . "\n";
