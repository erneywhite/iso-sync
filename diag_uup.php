<?php
declare(strict_types=1);

/**
 * Диагностика: потянет ли сервер сборку Windows-образов через UUP dump.
 *
 * Запуск на сервере, из корня проекта:
 *     php diag_uup.php
 *
 * Проверяет ТОЛЬКО чтение и сеть: ничего не устанавливает, ничего не пишет на
 * диск (кроме ~1 МБ во временный файл при замере скорости, который тут же
 * удаляется). Вывод можно копировать в чат целиком.
 *
 * Что важно понимать: UUP dump сам образы не хранит. Он отдаёт список файлов
 * официального обновления, которые качаются напрямую с CDN Microsoft, а ISO
 * собирается уже на нашей стороне. Поэтому проверяем три вещи:
 *   1) есть ли чем собирать (тулчейн + место + CPU),
 *   2) доступен ли API UUP dump,
 *   3) доступен ли САМ CDN Microsoft с этого сервера — по IPv4 и IPv6
 *      (прецедент Proxmox: официальный хост таймаутил по IPv4 из netcup).
 */

const API = 'https://api.uupdump.net';
const UA  = 'iso-sync-diag/1.0 (+https://github.com/erneywhite/iso-sync)';

/* ─────────── вывод ─────────── */
function h(string $t): void { echo "\n\033[1m{$t}\033[0m\n" . str_repeat('─', 66) . "\n"; }
function ok(string $t): void   { echo "  \033[32m✓\033[0m {$t}\n"; }
function bad(string $t): void  { echo "  \033[31m✗\033[0m {$t}\n"; }
function warn(string $t): void { echo "  \033[33m!\033[0m {$t}\n"; }
function info(string $t): void { echo "    {$t}\n"; }
function human(float $b): string {
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return sprintf('%.1f %s', $b, $u[$i]);
}
/**
 * Обрезка строки без зависимости от mbstring: на сервере CLI-PHP может быть
 * собран без него (диагностика обязана работать в любом окружении).
 */
function cut(string $s, int $len): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len);
    $s = substr($s, 0, $len);
    // не оставляем оборванный UTF-8 хвост
    return (string)preg_replace('/[\x80-\xBF]+$|[\xC0-\xFD]$/', '', $s);
}

$problems = [];
$warnings = [];

echo "\n=== iso-sync: готовность сервера к сборке через UUP dump ===\n";
echo "PHP " . PHP_VERSION . " (" . PHP_SAPI . "), " . php_uname('s') . ' ' . php_uname('r') . "\n";

/* ─────────── 1. Окружение PHP ─────────── */
h('1. Окружение PHP: расширения и запуск процессов');

info('бинарник: ' . (PHP_BINARY !== '' ? PHP_BINARY : '?'));
$extNeeded = ['curl' => false, 'json' => true, 'mbstring' => false, 'openssl' => false];
foreach ($extNeeded as $ext => $required) {
    $has = extension_loaded($ext);
    if ($has)            { ok("расширение {$ext}"); }
    elseif ($required)   { bad("расширение {$ext} отсутствует"); $problems[] = "нет PHP-расширения {$ext}"; }
    else                 { info("расширение {$ext}: нет (не критично)"); }
}
// На сервере обычно два PHP: системный (в PATH у root) и тот, под которым
// работает сайт/cron. Пайплайн этапа 3 поедет под тем, что пропишем в cron —
// важно не перепутать, у них разный набор расширений.
$sitePhp = null;
foreach (glob('/www/server/php/*/bin/php') ?: [] as $cand) { $sitePhp = $cand; }
if ($sitePhp !== null && $sitePhp !== PHP_BINARY) {
    warn('на сервере есть второй PHP: ' . $sitePhp);
    info('  сайт работает под ним; для cron-задач указывать полный путь явно');
}
echo "\n";

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$needFns  = ['shell_exec', 'proc_open', 'exec'];
$haveAny  = false;
foreach ($needFns as $fn) {
    $blocked = in_array($fn, $disabled, true) || !function_exists($fn);
    if ($blocked) {
        warn("{$fn}() недоступна");
    } else {
        ok("{$fn}() доступна");
        $haveAny = true;
    }
}
if (!$haveAny) {
    bad('PHP не может запускать процессы — оркестрация сборки отсюда невозможна');
    $problems[] = 'PHP не может вызывать внешние процессы (disable_functions)';
    info('Сборку придётся вешать отдельным shell-скриптом в cron, минуя PHP');
}

/** Безопасный вызов команды. */
function run(string $cmd, int $timeout = 30): string {
    if (!function_exists('shell_exec')) return '';
    return (string)@shell_exec('timeout ' . $timeout . ' ' . $cmd . ' 2>&1');
}
function which(string $bin): ?string {
    $p = trim(run('command -v ' . escapeshellarg($bin)));
    return $p !== '' && !str_contains($p, 'not found') ? $p : null;
}

/* ─────────── 2. Тулчейн ─────────── */
h('2. Тулчейн конвертера');

// Требования uup-dump/converter: aria2c качает, cabextract распаковывает CAB,
// wimlib-imagex работает с WIM/ESD, chntpw правит реестр в boot.wim,
// genisoimage/mkisofs/xorriso собирает итоговый ISO.
$tools = [
    'aria2c'        => ['pkg' => 'aria2',        'role' => 'загрузка пакетов с CDN Microsoft', 'req' => true],
    'cabextract'    => ['pkg' => 'cabextract',   'role' => 'распаковка CAB',                   'req' => true],
    'wimlib-imagex' => ['pkg' => 'wimtools',     'role' => 'работа с WIM/ESD',                 'req' => true],
    'chntpw'        => ['pkg' => 'chntpw',       'role' => 'правка реестра в boot.wim',        'req' => true],
    'genisoimage'   => ['pkg' => 'genisoimage',  'role' => 'сборка ISO',                       'req' => false],
    'mkisofs'       => ['pkg' => 'genisoimage',  'role' => 'сборка ISO (альтернатива)',        'req' => false],
    'xorriso'       => ['pkg' => 'xorriso',      'role' => 'сборка ISO (альтернатива)',        'req' => false],
    'curl'          => ['pkg' => 'curl',         'role' => 'диагностика и фолбэк загрузки',    'req' => true],
    'unzip'         => ['pkg' => 'unzip',        'role' => 'распаковка конвертера',            'req' => true],
];

$missingReq = [];
$haveIso    = false;
foreach ($tools as $bin => $meta) {
    $path = which($bin);
    if ($path !== null) {
        $ver = '';
        foreach ([' --version', ' -V', ''] as $flag) {
            $out = trim(run(escapeshellarg($path) . $flag, 8));
            if ($out !== '') { $ver = strtok($out, "\n") ?: ''; break; }
        }
        ok(sprintf('%-14s %s', $bin, $ver !== '' ? cut($ver, 46) : $path));
        if (in_array($bin, ['genisoimage', 'mkisofs', 'xorriso'], true)) $haveIso = true;
    } else {
        if ($meta['req']) {
            bad(sprintf('%-14s НЕТ — %s', $bin, $meta['role']));
            $missingReq[$meta['pkg']] = true;
        } else {
            info(sprintf('%-14s нет (%s)', $bin, $meta['role']));
        }
    }
}

if (!$haveIso) {
    bad('нет ни genisoimage, ни mkisofs, ни xorriso — собрать ISO нечем');
    $missingReq['genisoimage'] = true;
}

if ($missingReq !== []) {
    $pkgs = implode(' ', array_keys($missingReq));
    $problems[] = 'не хватает пакетов: ' . $pkgs;
    echo "\n";
    warn('Установить одной командой:');
    info('  apt update && apt install -y ' . $pkgs);
} else {
    ok('тулчейн полный');
}

/* ─────────── 3. Ресурсы ─────────── */
h('3. Место, CPU, память');

$filesDir = __DIR__ . '/files';
$workDirs = array_unique([sys_get_temp_dir(), $filesDir, __DIR__]);
// Пиковая потребность: ~5 ГБ пакетов + распаковка + итоговый ISO.
// Для Windows 11 реально нужно ~30-40 ГБ, для Server больше.
$needGb = 40;
foreach ($workDirs as $d) {
    if (!is_dir($d)) continue;
    $free  = @disk_free_space($d);
    $total = @disk_total_space($d);
    if ($free === false) { warn("{$d}: не удалось определить"); continue; }
    $freeGb = $free / 1073741824;
    $line = sprintf('%-28s свободно %s из %s', $d, human((float)$free), human((float)$total));
    if ($freeGb < $needGb) { warn($line); } else { ok($line); }
}
$maxFree = 0.0;
foreach ($workDirs as $d) {
    if (is_dir($d)) $maxFree = max($maxFree, (float)@disk_free_space($d) / 1073741824);
}
if ($maxFree < $needGb) {
    $warnings[] = sprintf('свободно менее %d ГБ (максимум %.1f ГБ) — сборке может не хватить', $needGb, $maxFree);
    info("Ориентир: пик сборки Windows 11 ~{$needGb} ГБ, Server больше.");
    info('Можно собирать во внешний каталог и удалять промежуточные файлы.');
}

$cores = (int)trim(run('nproc', 8));
if ($cores > 0) {
    $line = "ядер CPU: {$cores}";
    if ($cores < 2) { warn($line . ' — конвертация упрётся в процессор, это надолго'); $warnings[] = 'мало ядер CPU'; }
    else { ok($line); }
}
$memRaw = @file_get_contents('/proc/meminfo');
if (is_string($memRaw) && preg_match('/MemTotal:\s+(\d+) kB/', $memRaw, $m)) {
    $gb = (int)$m[1] / 1048576;
    $line = sprintf('память: %.1f ГБ', $gb);
    if ($gb < 2) { warn($line . ' — маловато'); $warnings[] = 'мало RAM'; } else { ok($line); }
}

/* ─────────── 4. Сеть ─────────── */
h('4. Сеть: API UUP dump');

/** Возвращает [http_code, time, body] или null. */
function fetch(string $url, string $ipFlag, int $timeout = 25, array $extra = []): ?array {
    $cmd = 'curl -sS ' . $ipFlag . ' --max-time ' . $timeout
         . ' -A ' . escapeshellarg(UA)
         . ' -w ' . escapeshellarg("\n__META__%{http_code} %{time_total} %{size_download}")
         . ' ' . implode(' ', $extra)
         . ' ' . escapeshellarg($url);
    $out = run($cmd, $timeout + 5);
    if ($out === '' || !str_contains($out, '__META__')) return null;
    [$body, $meta] = explode('__META__', $out, 2);
    $parts = preg_split('/\s+/', trim($meta)) ?: [];
    return [(int)($parts[0] ?? 0), (float)($parts[1] ?? 0), rtrim($body, "\n"), (int)($parts[2] ?? 0)];
}

$apiOkV4 = false;
$buildId = null;
$buildTitle = null;

foreach (['-4' => 'IPv4', '-6' => 'IPv6'] as $flag => $label) {
    $r = fetch(API . '/listid.php?search=Windows%2011&sortByDate=1', $flag, 25);
    if ($r === null) { bad("{$label}: нет ответа (таймаут или curl недоступен)"); continue; }
    [$code, $time, $body] = $r;
    if ($code === 429) {
        warn("{$label}: HTTP 429 — сработал rate limit API, повторить позже");
        $warnings[] = 'API UUP dump ответил 429 (rate limit)';
        continue;
    }
    if ($code !== 200) { bad("{$label}: HTTP {$code}"); continue; }

    $json = json_decode($body, true);
    $builds = $json['response']['builds'] ?? null;
    if (!is_array($builds) || $builds === []) {
        warn("{$label}: HTTP 200 за " . sprintf('%.2f', $time) . " c, но список сборок пуст/не разобран");
        continue;
    }
    ok("{$label}: HTTP 200 за " . sprintf('%.2f', $time) . " c, сборок в ответе: " . count($builds));

    if ($flag === '-4') $apiOkV4 = true;
    if ($buildId === null) {
        $first = is_array(reset($builds)) ? reset($builds) : null;
        if (is_array($first)) {
            $buildId    = (string)($first['uuid'] ?? $first['id'] ?? '');
            $buildTitle = trim((string)($first['title'] ?? '') . ' ' . (string)($first['build'] ?? ''));
            if ($buildId !== '') {
                info('свежайшая сборка в базе: ' . ($buildTitle !== '' ? $buildTitle : $buildId));
            }
        }
    }
}
if (!$apiOkV4) {
    $problems[] = 'API UUP dump недоступен по IPv4';
}

/* ─────────── 5. CDN Microsoft ─────────── */
h('5. Сеть: CDN Microsoft (главная проверка)');
info('Файлы качаются НЕ с uupdump, а напрямую с серверов Microsoft.');
info('Здесь берём реальную ссылку из API и тянем первый мегабайт.');
echo "\n";

$fileUrl  = null;
$fileName = null;

if ($buildId === null || $buildId === '') {
    warn('нет id сборки из раздела 4 — пропускаю проверку CDN');
    $warnings[] = 'не удалось получить ссылки на файлы MS (API не отдал сборку)';
} else {
    $r = fetch(API . '/get.php?id=' . rawurlencode($buildId) . '&lang=en-us&edition=core', '-4', 40);
    if ($r === null || $r[0] !== 200) {
        bad('get.php не отдал список файлов' . ($r !== null ? " (HTTP {$r[0]})" : ''));
        $warnings[] = 'get.php не отдал список файлов';
    } else {
        $json  = json_decode($r[2], true);
        $files = $json['response']['files'] ?? [];
        if (!is_array($files) || $files === []) {
            bad('в ответе get.php нет файлов');
            $warnings[] = 'get.php вернул пустой список файлов';
        } else {
            ok('получен список файлов обновления: ' . count($files) . ' шт.');
            foreach ($files as $name => $meta) {
                $u = is_array($meta) ? (string)($meta['url'] ?? '') : '';
                if ($u !== '') { $fileUrl = $u; $fileName = (string)$name; break; }
            }
            if ($fileUrl === null) {
                warn('ссылки на скачивание в ответе нет (API мог их скрыть)');
                $warnings[] = 'API не отдал прямые ссылки на файлы';
            } else {
                info('пробный файл: ' . cut((string)$fileName, 58));
                info('хост CDN: ' . (parse_url($fileUrl, PHP_URL_HOST) ?: '?'));
            }
        }
    }
}

if ($fileUrl !== null) {
    $tmp = sys_get_temp_dir() . '/iso_sync_uup_probe.bin';
    foreach (['-4' => 'IPv4', '-6' => 'IPv6'] as $flag => $label) {
        @unlink($tmp);
        $r = fetch($fileUrl, $flag, 40, [
            '-H ' . escapeshellarg('Range: bytes=0-1048575'),
            '-o ' . escapeshellarg($tmp),
        ]);
        if ($r === null) {
            bad("{$label}: нет ответа от CDN (таймаут)");
            if ($flag === '-4') $problems[] = 'CDN Microsoft недоступен по IPv4';
            continue;
        }
        [$code, $time, , $size] = $r;
        $got = is_file($tmp) ? (int)filesize($tmp) : $size;

        if ($code === 206) {
            $speed = $time > 0 ? $got / $time : 0;
            ok(sprintf('%s: HTTP 206 — Range поддержан, %s за %.2f c (~%s/s)',
                $label, human((float)$got), $time, human($speed)));
            info('  → aria2c сможет качать в несколько потоков');
        } elseif ($code === 200) {
            warn("{$label}: HTTP 200 вместо 206 — Range не поддержан, многопоточность не заработает");
            $warnings[] = "CDN не поддержал Range по {$label}";
        } else {
            bad("{$label}: HTTP {$code}");
            if ($flag === '-4') $problems[] = 'CDN Microsoft вернул HTTP ' . $code . ' по IPv4';
        }
    }
    @unlink($tmp);
}

/* ─────────── 6. Вердикт ─────────── */
h('6. Вердикт');

if ($problems === [] && $warnings === []) {
    ok('Блокеров нет — сервер готов к сборке, можно проектировать пайплайн.');
} else {
    if ($problems !== []) {
        echo "  \033[31mБлокеры:\033[0m\n";
        foreach ($problems as $p) info('• ' . $p);
    }
    if ($warnings !== []) {
        echo "\n  \033[33mПредупреждения:\033[0m\n";
        foreach ($warnings as $w) info('• ' . $w);
    }
}

echo "\n";
info('Скрипт ничего не установил и не изменил — только читал и качал пробный мегабайт.');
echo "\n";
