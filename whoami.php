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
$ip       = PrivateDirs::clientIp($_SERVER);

echo "=== Каким тебя видит сервер ===\n\n";

echo "REMOTE_ADDR : " . ($ip ?? '(не определён)') . "\n";
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
        echo "!! Настоящий адрес клиента, похоже: {$realCandidate}\n";
        echo "   а PHP видит {$ip} — это адрес прокси.\n";
        echo "   Чинится в nginx (НЕ в PHP: заголовок подделывается кем угодно):\n\n";
        echo "     set_real_ip_from " . $ip . ";\n";
        echo "     real_ip_header   "
            . (isset($present['HTTP_CF_CONNECTING_IP']) ? 'CF-Connecting-IP' : 'X-Forwarded-For') . ";\n\n";
        echo "   После этого REMOTE_ADDR станет настоящим, и allowlist заработает.\n\n";
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
