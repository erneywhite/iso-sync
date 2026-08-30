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

const API = 'https://api.uupdump.net';
const UA  = 'iso-sync/1.0 (+https://github.com/erneywhite/iso-sync)';

$args    = $argv ?? [];
$dryRun  = in_array('--dry-run', $args, true);
$only    = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--entry=')) $only = substr($a, 8);
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

/** GET к API с разбором JSON. @return array{code:int,json:mixed,error:string} */
function api(string $path): array
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
if (!$dryRun) {
    line();
    warn('Сейчас реализован только сухой прогон (этап 3a).');
    inf('Загрузка и конвертация — следующий шаг. Запусти с --dry-run.');
    line();
    exit(0);
}
line('режим: сухой прогон, ничего не качается');

$exit = 0;

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

    /* 1. Найти сборку */
    $r = api('/listid.php?search=' . rawurlencode(trim($pattern, '/^$ ')) . '&sortByDate=1');
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        // Поиск по шаблону мог ничего не дать — пробуем общий список
        $r = api('/listid.php?sortByDate=1');
    }
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        bad('listid.php: HTTP ' . $r['code'] . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
        $exit = 1;
        continue;
    }
    $builds = $r['json']['response']['builds'] ?? [];
    if (!is_array($builds) || $builds === []) { bad('пустой список сборок'); $exit = 1; continue; }

    $pick = UupResolver::pickBuild($builds, $pattern, $arch);
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
    if ($edition !== '' && !in_array($edition, $eds, true)) {
        bad("редакция {$edition} недоступна");
        inf('доступны: ' . implode(', ', $eds));
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
    if (is_file($target)) {
        ok('уже собран — пересборка не нужна');
    } else {
        inf('ещё не собран');
        // Прикидка пикового расхода: пакеты + распаковка + итоговый ISO
        inf('оценка пика на диске: ~' . human((float)$size * 3.5));
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
}

line();
exit($exit);
