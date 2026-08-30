<?php
declare(strict_types=1);

/**
 * Диагностика приватных каталогов: почему каталог не видно (или наоборот видно).
 *
 * Запуск на сервере, из корня проекта:
 *     php diag_private.php                 # общий отчёт
 *     php diag_private.php 203.0.113.42    # плюс проверка конкретного IP
 *
 * Ничего не меняет — только читает и печатает. Вывод можно копировать в чат:
 * публичные IP в нём есть, паролей и путей за пределами проекта нет.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\PrivateDirs;

$filesDir = __DIR__ . '/files';
$testIp   = $argv[1] ?? null;

function h(string $t): void { echo "\n\033[1m{$t}\033[0m\n" . str_repeat('─', 62) . "\n"; }
function ok(string $t): void   { echo "  \033[32m✓\033[0m {$t}\n"; }
function bad(string $t): void  { echo "  \033[31m✗\033[0m {$t}\n"; }
function warn(string $t): void { echo "  \033[33m!\033[0m {$t}\n"; }
function info(string $t): void { echo "    {$t}\n"; }

echo "\n=== iso-sync: диагностика приватных каталогов ===\n";
echo "PHP " . PHP_VERSION . " (CLI), проект: " . __DIR__ . "\n";

/* ─────────────── 1. Развёрнут ли нужный код ─────────────── */
h('1. Код на сервере');

$libPath = __DIR__ . '/lib/PrivateDirs.php';
if (!is_file($libPath)) {
    bad('lib/PrivateDirs.php НЕ НАЙДЕН — на сервере старая версия кода');
    info('Деплой: git fetch origin main && git reset --hard origin/main');
    exit(1);
}
ok('lib/PrivateDirs.php на месте');

// Наличие allowlist-логики: в первой версии её не было, каталог прятался всегда
$libSrc = (string)file_get_contents($libPath);
if (!str_contains($libSrc, 'parseAllowlist')) {
    bad('в PrivateDirs.php нет parseAllowlist — версия без allowlist по IP');
    info('Деплой: git fetch origin main && git reset --hard origin/main');
    exit(1);
}
ok('поддержка allowlist в коде есть');

$head = @shell_exec('git -C ' . escapeshellarg(__DIR__) . ' log --oneline -1 2>&1');
if (is_string($head) && trim($head) !== '') {
    info('HEAD: ' . trim($head));
}

/* ─────────────── 2. OPcache ─────────────── */
h('2. OPcache (частая причина «код обновил, а ничего не изменилось»)');

warn('CLI и php-fpm используют РАЗНЫЕ инстансы opcache — отсюда точно не видно');
$vt = ini_get('opcache.validate_timestamps');
info('в CLI opcache.validate_timestamps = ' . ($vt === false ? 'n/a' : var_export($vt, true)));
info('Если после деплоя UI не изменился — перезапустить пул:');
info('  aaPanel → PHP → перезагрузить, либо: systemctl reload php-fpm');

/* ─────────────── 3. Маркеры на диске ─────────────── */
h('3. Каталоги с маркером .private');

if (!is_dir($filesDir)) {
    bad("нет каталога {$filesDir}");
    exit(1);
}

$found = 0;
foreach (scandir($filesDir) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    $dir = $filesDir . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($dir)) continue;
    $marker = $dir . DIRECTORY_SEPARATOR . PrivateDirs::MARKER;
    if (!is_file($marker)) continue;

    $found++;
    echo "\n  \033[1mfiles/{$name}/\033[0m\n";

    // Читаемость веб-юзером — самая частая тихая причина
    $perms = @fileperms($marker);
    $owner = function_exists('posix_getpwuid') ? @posix_getpwuid((int)@fileowner($marker)) : null;
    $group = function_exists('posix_getgrgid') ? @posix_getgrgid((int)@filegroup($marker)) : null;
    info('права: ' . ($perms !== false ? substr(sprintf('%o', $perms), -4) : '?')
        . '  владелец: ' . ($owner['name'] ?? '?') . ':' . ($group['name'] ?? '?'));
    if (!is_readable($marker)) {
        bad('маркер НЕ ЧИТАЕТСЯ текущим пользователем');
        info('Веб-сервер работает от другого юзера (www/nginx) — проверь и его доступ:');
        info('  chmod 0644 ' . escapeshellarg($marker));
    } else {
        ok('маркер читается');
    }

    $raw = (string)@file_get_contents($marker);
    info('размер: ' . strlen($raw) . ' байт');

    if ($raw === '') {
        warn('маркер ПУСТОЙ → каталог скрыт от всех (allowlist не задан)');
        continue;
    }

    // BOM и переводы строк
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        warn('в начале файла UTF-8 BOM (редактор добавил) — код его снимает, но лучше пересохранить без BOM');
    }
    $eol = str_contains($raw, "\r\n") ? 'CRLF' : (str_contains($raw, "\r") ? 'CR' : 'LF');
    info("переводы строк: {$eol}");

    // Невидимый мусор из копипаста
    if (preg_match('/[\x{00A0}\x{202F}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{200E}\x{200F}]/u', $raw)) {
        warn('найдены невидимые символы (NBSP / zero-width) — обычно из копипаста с сайта');
        info('код их вычищает, но если правило всё равно не матчится — перенаберите IP руками');
    }

    // Что реально распозналось
    $rules = PrivateDirs::parseAllowlist($marker);
    if ($rules === []) {
        bad('НИ ОДНОГО правила не распознано → каталог скрыт от всех');
        info('Сырое содержимое построчно (с hex первых байт):');
        foreach (preg_split('/\R/', $raw) ?: [] as $i => $line) {
            if (trim($line) === '') continue;
            info(sprintf('  [%d] %-30s hex: %s', $i + 1, '«' . $line . '»',
                substr(bin2hex($line), 0, 40)));
        }
    } else {
        ok('распознано правил: ' . count($rules));
        foreach ($rules as $r) {
            $valid = PrivateDirs::matchRule($r, $r);   // правило само себе должно матчиться
            info(($valid ? '  ✓ ' : '  ✗ ') . $r . ($valid ? '' : '   ← НЕ РАСПОЗНАНО как IP/CIDR'));
        }
    }

    // Проверка переданного IP
    if ($testIp !== null) {
        $hit = PrivateDirs::ipAllowed($testIp, $rules);
        if ($hit) {
            ok("IP {$testIp} → каталог БУДЕТ ВИДЕН");
        } else {
            bad("IP {$testIp} → каталог остаётся СКРЫТ");
            foreach ($rules as $r) {
                info(sprintf('  %s vs %-24s → %s', $testIp, $r,
                    PrivateDirs::matchRule($testIp, $r) ? 'совпало' : 'нет'));
            }
        }
    }
}

if ($found === 0) {
    warn('маркеров .private не найдено ни в одном каталоге files/');
    info('Проверь имя файла (точка в начале) и что он лежит ВНУТРИ каталога с образами');
}

/* ─────────────── 4. Что веб-сервер видит как адрес клиента ─────────────── */
h('4. Реальные IP из access-логов nginx');

warn('PHP видит клиента как REMOTE_ADDR. Если тут твой адрес выглядит иначе,');
warn('чем в .private (например IPv6 вместо IPv4) — вот и причина.');

$logCandidates = glob('/www/wwwlogs/*.log') ?: [];
$logCandidates = array_values(array_filter($logCandidates, static fn($p) => !str_contains($p, 'error')));
if ($logCandidates === []) {
    $logCandidates = array_merge(
        glob('/var/log/nginx/*access*.log') ?: [],
        glob('/www/wwwlogs/*.log') ?: []
    );
}

if ($logCandidates === []) {
    warn('access-логи не найдены в /www/wwwlogs и /var/log/nginx');
    info('Найди лог сайта вручную и посмотри последние строки:');
    info('  tail -50 /путь/к/access.log | awk \'{print $1}\' | sort | uniq -c | sort -rn');
} else {
    foreach (array_slice($logCandidates, 0, 3) as $log) {
        echo "\n  \033[1m{$log}\033[0m\n";
        $lines = @shell_exec('tail -n 400 ' . escapeshellarg($log) . ' 2>/dev/null');
        if (!is_string($lines) || trim($lines) === '') {
            info('(пусто или нет прав на чтение)');
            continue;
        }
        $counts = [];
        foreach (explode("\n", $lines) as $l) {
            $ip = strtok(trim($l), ' ');
            if ($ip === false || $ip === '') continue;
            if (@inet_pton($ip) === false) continue;
            $counts[$ip] = ($counts[$ip] ?? 0) + 1;
        }
        arsort($counts);
        if ($counts === []) { info('(IP не распознались)'); continue; }
        foreach (array_slice($counts, 0, 8, true) as $ip => $n) {
            $mark = '';
            if ($testIp !== null && $ip === $testIp) $mark = '  ← совпадает с переданным IP';
            info(sprintf('%-42s %4d запр.%s', $ip, $n, $mark));
        }
    }
}

/* ─────────────── 5. Итог ─────────────── */
h('5. Что делать дальше');
info('1. Открой сайт в браузере, затем перезапусти этот скрипт — в разделе 4');
info('   верхней строкой будет твой настоящий адрес, каким его видит сервер.');
info('2. Впиши ИМЕННО его в .private (частая засада: в браузере IPv6, а в файле IPv4).');
info('3. Если правка не подхватилась — перезагрузи php-fpm (раздел 2).');
info('4. Помни: маркер убирает каталог только из UI. Файлы закрывает nginx');
info('   (allow/deny), см. docs/PRIVATE-DIRS.md.');
echo "\n";
