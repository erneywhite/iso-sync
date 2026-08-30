<?php
declare(strict_types=1);

/**
 * Сборка образов Windows из официальных пакетов Microsoft (UUP dump).
 *
 * Запуск:
 *     php build_uup.php --dry-run     # показать, что будет собрано (без загрузки)
 *     php build_uup.php --dry-run --entry=windows-11-25h2
 *
 * ЭТАП 3a: сейчас реализован ТОЛЬКО сухой прогон — резолв сборки, список файлов
 * и объём загрузки. Сама загрузка и конвертация появятся следующим шагом; без
 * --dry-run скрипт честно сообщает об этом и ничего не делает.
 *
 * Почему отдельный скрипт, а не режим в update_iso.php: сборка идёт 30-60 минут
 * и упирается в диск и процессор, тогда как update_iso — быстрый проверяющий
 * прогон. Смешивать их значит рисковать тем, что зависшая сборка заблокирует
 * обычную синхронизацию зеркала.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\UupResolver;
use IsoSync\UupBuilder;
use IsoSync\Logger;
use IsoSync\Lock;

const API = 'https://api.uupdump.net';
const UA  = 'iso-sync/1.0 (+https://github.com/erneywhite/iso-sync)';

$args    = $argv ?? [];
$dryRun  = in_array('--dry-run', $args, true);
$only    = null;
$search  = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--entry=')) $only = substr($a, 8);
    if (str_starts_with($a, '--list='))  $search = substr($a, 7);
}

function line(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void   { line("  \033[32m✓\033[0m {$s}"); }
function bad(string $s): void  { line("  \033[31m✗\033[0m {$s}"); }
function warn(string $s): void { line("  \033[33m!\033[0m {$s}"); }
function inf(string $s): void  { line("    {$s}"); }
function human(float $b): string {
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return sprintf('%.2f %s', $b, $u[$i]);
}

/**
 * GET к API с разбором JSON.
 *
 * Между вызовами выдерживается пауза, и один раз повторяем при 429: API
 * ограничивает частоту, а прогон по нескольким записям легко упирается в лимит
 * (на четырёх записях это ловилось стабильно).
 *
 * @return array{code:int,json:mixed,error:string}
 */
function api(string $path, bool $retryOn429 = true): array
{
    static $last = 0.0;
    $wait = 1.4 - (microtime(true) - $last);
    if ($wait > 0) usleep((int)($wait * 1_000_000));
    $last = microtime(true);

    $r = apiRaw($path);
    if ($r['code'] === 429 && $retryOn429) {
        warn('rate limit (429) — пауза 8 c и повтор');
        sleep(8);
        $last = microtime(true);
        $r = apiRaw($path);
    }
    return $r;
}

/** @return array{code:int,json:mixed,error:string} */
function apiRaw(string $path): array
{
    $cmd = 'curl -sS --max-time 45 -A ' . escapeshellarg(UA)
         . ' -w ' . escapeshellarg("\n__CODE__%{http_code}")
         . ' ' . escapeshellarg(API . $path) . ' 2>&1';
    $out = (string)@shell_exec($cmd);
    if (!str_contains($out, '__CODE__')) {
        return ['code' => 0, 'json' => null, 'error' => 'нет ответа (таймаут?)'];
    }
    [$body, $code] = explode('__CODE__', $out, 2);
    $json = json_decode(trim($body), true);
    $err  = '';
    if (is_array($json)) {
        $e = $json['response']['error'] ?? ($json['error'] ?? null);
        if (is_string($e)) $err = $e;
    }
    return ['code' => (int)trim($code), 'json' => $json, 'error' => $err];
}

/* ─────────── конфиг ─────────── */
$cfgPath = __DIR__ . '/config/uup-builds.json';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "Нет конфига {$cfgPath}\n");
    exit(1);
}
$cfg = json_decode((string)file_get_contents($cfgPath), true);
if (!is_array($cfg) || !isset($cfg['builds']) || !is_array($cfg['builds'])) {
    fwrite(STDERR, "Конфиг не разобран или не содержит builds\n");
    exit(1);
}

line();
line("=== iso-sync: сборка Windows через UUP dump ===");

/**
 * Запуск длительной команды с живым выводом.
 * Конвертация идёт десятки минут — прятать её прогресс нельзя.
 */
function runLive(string $cmd, ?string $cwd = null): int
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd : $cmd;
    $code = 0;
    passthru($full, $code);
    return $code;
}

function rmrf(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        rmrf($path . DIRECTORY_SEPARATOR . $e);
    }
    @rmdir($path);
}

/* ─────────── режим разведки ───────────
   Показывает, что реально лежит в базе UUP по запросу: заголовки, номера
   сборок, архитектуры. Нужен, чтобы задавать title_pattern по фактическим
   данным, а не по догадке — у серверных редакций свои имена, и наличие
   конкретной ветки в UUP надо проверять, а не предполагать. */
if ($search !== null) {
    line("разведка: что есть в базе по запросу «{$search}»");
    line();
    $r = api('/listid.php?search=' . rawurlencode($search) . '&sortByDate=1');
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        bad('listid.php: HTTP ' . $r['code'] . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
        exit(1);
    }
    $builds = $r['json']['response']['builds'] ?? [];
    if (!is_array($builds) || $builds === []) { warn('ничего не найдено'); exit(0); }

    // Только сборки ОС; группируем по заголовку без номера, чтобы из сотен
    // ревизий одной ветки показать самую свежую и не утопить вывод.
    $byBranch = [];
    foreach ($builds as $key => $b) {
        if (!is_array($b)) continue;
        $title = (string)($b['title'] ?? '');
        if (!UupResolver::isOsBuild($title)) continue;
        $arch  = strtolower((string)($b['arch'] ?? ''));
        $build = (string)($b['build'] ?? '');
        if ($build === '' && preg_match('/\((\d{4,6}\.\d+)\)/', $title, $m)) $build = $m[1];
        $branch = trim((string)preg_replace('/\s*\(\d{4,6}\.\d+\)\s*/', '', $title));
        $gk = $branch . '|' . $arch;
        if (!isset($byBranch[$gk]) || UupResolver::compareBuild($build, $byBranch[$gk]['build']) > 0) {
            $byBranch[$gk] = ['branch' => $branch, 'arch' => $arch, 'build' => $build, 'title' => $title];
        }
    }
    if ($byBranch === []) { warn('нашлись только пакеты обновлений, сборок ОС нет'); exit(0); }

    uasort($byBranch, static fn($a, $b) => UupResolver::compareBuild($b['build'], $a['build']));
    line(sprintf('  %-46s %-8s %s', 'ВЕТКА', 'АРХ', 'СВЕЖАЙШАЯ'));
    line('  ' . str_repeat('─', 72));
    foreach (array_slice($byBranch, 0, 30) as $g) {
        line(sprintf('  %-46s %-8s %s', substr($g['branch'], 0, 46), $g['arch'] ?: '?', $g['build']));
    }
    line();
    inf('Для нужной ветки задай title_pattern в config/uup-builds.json,');
    inf('например: /^' . preg_quote((string)(reset($byBranch)['branch'] ?? ''), '/') . '/');
    line();
    exit(0);
}

$workDir   = (string)($cfg['work_dir'] ?? (__DIR__ . '/.uup-work'));
$statePath = __DIR__ . '/logs/uup-state.json';
$state     = UupBuilder::loadState($statePath);
$log       = new Logger(__DIR__ . '/logs', 'uup');

if ($dryRun) {
    line('режим: сухой прогон, ничего не качается');
} else {
    line('режим: СБОРКА — будет загрузка и конвертация');
    inf('рабочий каталог: ' . $workDir);

    // Блокировка на весь прогон: сборки обязаны идти по очереди. Пики
    // расхода диска у них десятки гигабайт, параллельный запуск сложит их
    // и упрётся в место на самом интересном месте.
    $lock = new Lock(__DIR__ . '/logs/uup-build.lock');
    if (!$lock->acquire()) {
        bad('другая сборка уже идёт (logs/uup-build.lock) — выходим');
        exit(1);
    }
    register_shutdown_function(static function () use ($lock) { $lock->release(); });
}

$exit = 0;

/* Список сборок тянем ОДИН раз на весь прогон, а не по разу на запись:
   раньше это давало лишние вызовы и упиралось в rate limit API. */
line();
$r = api('/listid.php?sortByDate=1');
if ($r['code'] !== 200 || !is_array($r['json'])) {
    bad('listid.php: HTTP ' . $r['code'] . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
    exit(1);
}
$allBuilds = $r['json']['response']['builds'] ?? [];
if (!is_array($allBuilds) || $allBuilds === []) {
    bad('пустой список сборок');
    exit(1);
}
ok('получен каталог сборок: ' . count($allBuilds));

foreach ($cfg['builds'] as $key => $e) {
    if (!is_array($e) || str_starts_with((string)$key, '_comment')) continue;
    if ($only !== null && $key !== $only) continue;
    if (!($e['enabled'] ?? false)) { line("\n[{$key}] выключена в конфиге — пропуск"); continue; }

    $pattern = (string)($e['title_pattern'] ?? '');
    $arch    = (string)($e['arch'] ?? 'amd64');
    $lang    = (string)($e['lang'] ?? 'en-us');
    $edition = (string)($e['edition'] ?? '');

    line("\n\033[1m[{$key}]\033[0m");
    inf("шаблон канала: {$pattern}");

    /* 1. Найти сборку в уже загруженном каталоге */
    $pick = UupResolver::pickBuild($allBuilds, $pattern, $arch);
    if ($pick === null) {
        bad('под шаблон не подошла ни одна сборка ОС');
        inf('Проверь title_pattern: канал задаётся именно им.');
        $exit = 1;
        continue;
    }
    ok('сборка: ' . $pick['title']);
    inf('build: ' . $pick['build'] . '   id: ' . $pick['id']);

    /* 2. Язык и редакция — сверяем с тем, что реально доступно */
    $id = rawurlencode($pick['id']);

    $r = api('/listlangs.php?id=' . $id);
    $langs = is_array($r['json'])
        ? ($r['json']['response']['langList'] ?? array_keys((array)($r['json']['response']['langFancyNames'] ?? [])))
        : [];
    if (!in_array($lang, (array)$langs, true)) {
        bad("язык {$lang} недоступен для этой сборки");
        inf('доступны: ' . implode(', ', array_slice((array)$langs, 0, 12)));
        $exit = 1;
        continue;
    }
    ok("язык {$lang} доступен");

    $r = api('/listeditions.php?id=' . $id . '&lang=' . rawurlencode($lang));
    $eds = is_array($r['json'])
        ? ($r['json']['response']['editionList'] ?? array_keys((array)($r['json']['response']['editionFancyNames'] ?? [])))
        : [];
    $eds = array_map('strval', (array)$eds);
    // Список показываем всегда: у серверных веток имена редакций свои, и
    // подобрать их можно только глядя на то, что реально отдаёт API.
    inf('доступные редакции: ' . (implode(', ', array_slice($eds, 0, 14)) ?: '(пусто)'));
    if ($edition !== '' && !in_array($edition, $eds, true)) {
        bad("редакция {$edition} недоступна — поправь edition в конфиге");
        $exit = 1;
        continue;
    }
    $edition = $edition !== '' ? $edition : (string)($eds[0] ?? '');
    ok("редакция {$edition} доступна");

    /* 3. Файлы и объём */
    $r = api('/get.php?id=' . $id . '&lang=' . rawurlencode($lang) . '&edition=' . rawurlencode($edition));
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        bad('get.php: HTTP ' . $r['code'] . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
        $exit = 1;
        continue;
    }
    $files = $r['json']['response']['files'] ?? [];
    if (!is_array($files) || $files === []) { bad('пустой список файлов'); $exit = 1; continue; }

    $size = UupResolver::totalSize($files);
    ok('файлов к загрузке: ' . count($files) . ', объём: ' . human((float)$size));

    /* 4. Что получится на выходе */
    $name = UupResolver::localName(
        (string)($e['name_template'] ?? '{build}.iso'),
        $pick['build'], $lang, $edition
    );
    $subdir = (string)($e['local_subdir'] ?? '');
    $target = __DIR__ . '/files' . ($subdir !== '' ? '/' . $subdir : '') . '/' . $name;

    inf('итоговый файл: ' . $name);
    $alreadyBuilt = is_file($target);
    if ($alreadyBuilt) {
        ok('уже собран — пересборка не нужна');
    } else {
        inf('ещё не собран');
        inf('оценка пика на диске: ~' . human((float)$size * UupBuilder::PEAK_FACTOR));
    }

    $dir = dirname($target);
    if (!is_dir($dir)) {
        warn('каталог не существует: ' . $subdir);
    } elseif (!is_file($dir . '/.private')) {
        warn('в каталоге нет маркера .private — образ попадёт на публичную витрину');
        inf('  touch ' . escapeshellarg($dir . '/.private'));
    } else {
        ok('каталог приватный (.private на месте)');
    }

    if ($dryRun) continue;

    /* ─────────── сборка ─────────── */

    $builtBefore = (string)($state[$key]['build'] ?? '');
    if ($alreadyBuilt && !UupResolver::needsRebuild($pick['build'], $builtBefore)) {
        inf('пропуск: эта сборка уже собрана');
        continue;
    }
    if (!is_dir($dir)) { bad('нет целевого каталога — пропуск'); $exit = 1; continue; }

    $space = UupBuilder::checkSpace($workDir !== '' && is_dir(dirname($workDir)) ? dirname($workDir) : __DIR__, $size);
    if (!$space['ok']) {
        bad('мало места: нужно ~' . human((float)$space['need']) . ', свободно ' . human((float)$space['free']));
        $exit = 1;
        continue;
    }

    $entryWork = $workDir . '/' . $key;
    $uupsDir   = $entryWork . '/UUPs';
    if (!is_dir($uupsDir) && !@mkdir($uupsDir, 0755, true)) {
        bad('не удалось создать ' . $uupsDir);
        $exit = 1;
        continue;
    }

    /* 5. Загрузка пакетов через aria2c */
    $inputFile = $entryWork . '/aria2.txt';
    file_put_contents($inputFile, UupBuilder::aria2Input($files, $uupsDir));
    $cnt = UupBuilder::countDownloadable($files);
    line();
    inf("загрузка {$cnt} файлов (" . human((float)$size) . ") через aria2c…");
    $log->info('UUP: старт загрузки', ['entry' => $key, 'build' => $pick['build'], 'files' => $cnt]);

    // -c продолжает прерванную загрузку, поэтому повторный запуск после
    // обрыва не начинает всё заново. SHA1 каждого файла проверяет сам aria2
    // (checksum= в input-файле).
    $rc = runLive('aria2c --no-conf --console-log-level=warn --summary-interval=30 '
        . '-x8 -s8 -j5 -c -R --auto-file-renaming=false --allow-overwrite=true '
        . '-i ' . escapeshellarg($inputFile));
    if ($rc !== 0) {
        bad("aria2c завершился с кодом {$rc} — загрузка не полная");
        inf('Повторный запуск продолжит с места обрыва.');
        $log->error('UUP: загрузка не удалась', ['entry' => $key, 'code' => $rc]);
        $exit = 1;
        continue;
    }
    ok('загрузка завершена, SHA1 всех файлов сошлись');

    /* 6. Конвертер */
    $convDir = $workDir . '/converter';
    if (!is_file($convDir . '/convert.sh')) {
        inf('получаю конвертер uup-dump…');
        @mkdir($convDir, 0755, true);
        $tar = $workDir . '/converter.tar.gz';
        $url = (string)($cfg['converter_url'] ?? 'https://git.uupdump.net/uup-dump/converter/archive/master.tar.gz');
        $rc  = runLive('curl -sSL -o ' . escapeshellarg($tar) . ' ' . escapeshellarg($url));
        if ($rc !== 0 || !is_file($tar)) {
            bad('не удалось скачать конвертер: ' . $url);
            $exit = 1;
            continue;
        }
        $rc = runLive('tar -xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($convDir) . ' --strip-components=1');
        @unlink($tar);
        if ($rc !== 0 || !is_file($convDir . '/convert.sh')) {
            bad('распаковка конвертера не удалась (нет convert.sh)');
            $exit = 1;
            continue;
        }
        @chmod($convDir . '/convert.sh', 0755);
        ok('конвертер готов');
    } else {
        inf('конвертер уже скачан');
    }

    /* 7. Конвертация. ISO появляется в каталоге, откуда запущен convert.sh */
    line();
    inf('конвертация в ISO — это надолго (десятки минут)…');
    $log->info('UUP: старт конвертации', ['entry' => $key, 'build' => $pick['build']]);
    foreach (glob($convDir . '/*.iso') ?: [] as $old) @unlink($old);

    $rc = runLive('./convert.sh wim ' . escapeshellarg($uupsDir) . ' 0', $convDir);
    if ($rc !== 0) {
        bad("convert.sh завершился с кодом {$rc}");
        $log->error('UUP: конвертация не удалась', ['entry' => $key, 'code' => $rc]);
        $exit = 1;
        continue;
    }
    $iso = UupBuilder::findIso($convDir);
    if ($iso === null) {
        bad('convert.sh отработал, но ISO не найден');
        $exit = 1;
        continue;
    }
    ok('собран ISO: ' . human((float)filesize($iso)));

    /* 8. Переносим на место */
    if (!@rename($iso, $target)) {
        // rename не работает между разными файловыми системами
        if (!@copy($iso, $target)) {
            bad('не удалось перенести ISO в ' . $target);
            $exit = 1;
            continue;
        }
        @unlink($iso);
    }
    @chmod($target, 0644);
    ok('размещён: ' . $name);

    /* 9. Ротация прошлых версий */
    if ($e['cleanup_old'] ?? false) {
        $existing = array_values(array_filter(scandir($dir) ?: [], static fn($f) => is_file($dir . '/' . $f)));
        foreach (UupBuilder::staleFiles($existing, (string)($e['name_template'] ?? ''), $name, $lang, $edition) as $old) {
            if (@unlink($dir . '/' . $old)) {
                inf('удалена прошлая версия: ' . $old);
                $log->info('UUP: удалена прошлая версия', ['entry' => $key, 'removed' => $old]);
            }
        }
    }

    /* 10. Состояние и уборка */
    $state[$key] = ['build' => $pick['build'], 'iso' => $name, 'built_at' => date('c')];
    UupBuilder::saveState($statePath, $state);
    rmrf($entryWork);
    ok('готово: ' . $key . ' → ' . $pick['build']);
    $log->info('UUP: сборка завершена', ['entry' => $key, 'build' => $pick['build'], 'iso' => $name]);
}

if (!$dryRun) {
    line();
    inf('Хэши пересчитываются отдельно: php generate_all_hashes.php');
}

line();
exit($exit);
