<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\Config;
use IsoSync\OnlySelector;
use RuntimeException;

// Прямые require без bootstrap — как в остальных тестах: пусть файл работает, даже
// если на сервере при неполном деплое не обновился lib/bootstrap.php.
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/OnlySelector.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * Конфиг во временном файле — чтобы тесты не читали config/iso-list.json:
 * боевой конфиг правится руками, и завязываться на него было бы хрупко.
 *
 * @param array<string,mixed> $files
 */
function writeConfigMap(array $files): Config
{
    $path = tempnam(sys_get_temp_dir(), 'isocfg_');
    file_put_contents($path, json_encode(['files' => $files], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $cfg = Config::loadFromFile($path);
    @unlink($path);
    return $cfg;
}

/**
 * Конфиг из записей заданных ключей (fixed-режим — для отбора он ничем не
 * отличается от других режимов, те проверяются отдельным тестом).
 *
 * @param list<string> $names
 */
function selectorConfig(array $names): Config
{
    $files = [];
    foreach ($names as $name) {
        $files[$name] = ['local_subdir' => 'D', 'url_dir' => 'https://example/', 'remote_name' => $name . '.iso'];
    }
    return writeConfigMap($files);
}

/** Пускает коллбэк и возвращает текст RuntimeException, либо null если не бросил. */
function onlyError(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

// =================================================================
// parseArgs — раз argv-строк
// =================================================================

test('parseArgs: без флага — null (полный прогон)', function () {
    assertEquals(null, OnlySelector::parseArgs([]));
});

test('parseArgs: один ключ через =', function () {
    assertEquals(['Debian_12.iso'], OnlySelector::parseArgs(['--only=Debian_12.iso']));
});

test('parseArgs: несколько ключей через запятую', function () {
    assertEquals(
        ['Debian_12.iso', 'ubuntu-lts', 'proxmox-ve-8'],
        OnlySelector::parseArgs(['--only=Debian_12.iso,ubuntu-lts,proxmox-ve-8'])
    );
});

test('parseArgs: два флага --only складываются, дубликаты схлопываются', function () {
    assertEquals(
        ['a.iso', 'b.iso'],
        OnlySelector::parseArgs(['--only=a.iso', '--only=b.iso,a.iso'])
    );
});

test('parseArgs: ключ в другой форме: --only <ключ> отдельным аргументом', function () {
    assertEquals(['a.iso'], OnlySelector::parseArgs(['--only', 'a.iso']));
    assertEquals(['a.iso', 'b.iso'], OnlySelector::parseArgs(['--only', 'a.iso,b.iso']));
});

test('parseArgs: пробелы вокруг ключей не значимы', function () {
    assertEquals(['a.iso', 'b.iso'], OnlySelector::parseArgs(['--only= a.iso , b.iso ']));
});

test('parseArgs: пустое значение --only= — ошибка', function () {
    $msg = onlyError(fn() => OnlySelector::parseArgs(['--only=']));
    assertTrue($msg !== null, 'пустой --only должен бросать');
    assertContains('пуст', $msg);
});

test('parseArgs: пустой ключ в списке (двойная запятая) — ошибка', function () {
    $msg = onlyError(fn() => OnlySelector::parseArgs(['--only=a.iso,,b.iso']));
    assertTrue($msg !== null, 'пустой элемент должен бросать');
    assertContains('пуст', $msg);
});

test('parseArgs: --only без следующего аргумента — ошибка', function () {
    assertTrue(onlyError(fn() => OnlySelector::parseArgs(['--only'])) !== null);
});

test('parseArgs: --only, после которого идёт другой флаг — ошибка', function () {
    assertTrue(onlyError(fn() => OnlySelector::parseArgs(['--only', '--dry-run'])) !== null);
});

test('parseArgs: неизвестный флаг — ошибка', function () {
    $msg = onlyError(fn() => OnlySelector::parseArgs(['--foo=bar']));
    assertTrue($msg !== null, 'неизвестный флаг должен бросать');
    assertContains('Неизвестный аргумент: --foo=bar', $msg);
});

test('parseArgs: аргумент без флага — ошибка, а не ключ', function () {
    $msg = onlyError(fn() => OnlySelector::parseArgs(['Debian_12.iso']));
    assertTrue($msg !== null, 'голый ключ не должен приниматься');
    assertContains('Лишний аргумент: Debian_12.iso', $msg);
});

test('parseArgs: ошибка на битом флаге не оставляет частично разобранного списка', function () {
    assertTrue(onlyError(fn() => OnlySelector::parseArgs(['--only=a.iso', '--only'])) !== null);
});

// =================================================================
// select — отбор записей из конфига
// =================================================================

test('select: без флага остаются все записи, порядок конфига', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso', 'c.iso']);
    // Флаг отсутствует: parseArgs отдаёт null, и вызывающий скрипт оставляет конфиг как есть.
    $only = OnlySelector::parseArgs([]);
    assertEquals(null, $only, 'без --only выбора нет');
    $files = $cfg->files;
    if ($only !== null) {
        $files = OnlySelector::select($files, $only);
    }
    assertEquals(['a.iso', 'b.iso', 'c.iso'], array_keys($files));
});

test('select: явное перечисление всех ключей даёт тот же набор', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso', 'c.iso']);
    assertEquals(
        ['a.iso', 'b.iso', 'c.iso'],
        array_keys(OnlySelector::select($cfg->files, ['c.iso', 'a.iso', 'b.iso']))
    );
});

test('select: один ключ', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso']);
    assertEquals(['b.iso'], array_keys(OnlySelector::select($cfg->files, ['b.iso'])));
});

test('select: несколько ключей, порядок — как в конфиге', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso', 'c.iso']);
    assertEquals(
        ['a.iso', 'c.iso'],
        array_keys(OnlySelector::select($cfg->files, ['c.iso', 'a.iso']))
    );
});

test('select: неизвестный ключ — ошибка со списком допустимых', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso']);
    // 'nope.iso' нет в конфиге намеренно: тест ровно о неизвестном ключе.
    $msg = onlyError(fn() => OnlySelector::select($cfg->files, ['a.iso', 'nope.iso']));
    assertTrue($msg !== null, 'неизвестный ключ должен бросать');
    assertContains('Неизвестные ключи в --only: nope.iso', $msg);
    assertContains('Допустимо: a.iso, b.iso', $msg);
});

test('select: все ключи известны — отбор без ошибок', function () {
    $cfg = selectorConfig(['a.iso', 'b.iso', 'c.iso']);
    assertEquals(
        ['a.iso', 'c.iso'],
        array_keys(OnlySelector::select($cfg->files, ['a.iso', 'c.iso']))
    );
});

test('select: все неизвестные ключи перечисляются одним сообщением', function () {
    $cfg = selectorConfig(['a.iso']);
    $msg = onlyError(fn() => OnlySelector::select($cfg->files, ['xx', 'yy']));
    assertTrue($msg !== null);
    assertContains('xx, yy', $msg);
});

test('select: режим записи значения не имеет — fixed/latest/family/discovery', function () {
    $cfg = writeConfigMap([
        'fixed.iso'  => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso'],
        'latest.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'latest', 'latest_pattern' => '/dvd/i'],
        'family'     => [
            'url_dir'             => 'https://e/',
            'remote_pattern'      => '/^pve_(\\d+)\\.iso$/',
            'local_name_template' => 'PVE_{1}.iso',
        ],
        'ubuntu-lts' => [
            'url_template'        => 'https://releases.ubuntu.com/{folder}/',
            'folder_enum'         => ['from' => 22, 'to' => 24, 'step' => 1, 'format' => '{0}.04'],
            'remote_pattern'      => '/^ubuntu-{folder}-server\\.iso$/',
            'local_name_template' => 'Ubuntu_{folder}.iso',
        ],
    ]);

    assertTrue($cfg->files['latest.iso']->isLatest(), 'latest-режим распознан');
    assertTrue($cfg->files['family']->isFamily(), 'family-режим распознан');
    assertTrue($cfg->files['ubuntu-lts']->isDiscovery(), 'discovery-режим распознан');

    assertEquals(
        ['fixed.iso', 'family', 'ubuntu-lts'],
        array_keys(OnlySelector::select($cfg->files, ['ubuntu-lts', 'family', 'fixed.iso']))
    );
});

test('select: комментарийные _comment_* не в списке допустимых', function () {
    // Config отбрасывает '_comment_*' на парсинге, значит и в ошибке их быть не может.
    $cfg = writeConfigMap([
        '_comment_note' => 'просто заметка',
        'a.iso'         => ['url_dir' => 'https://e/', 'remote_name' => 'a.iso'],
    ]);
    $msg = onlyError(fn() => OnlySelector::select($cfg->files, ['_comment_note']));
    assertTrue($msg !== null, 'комментарий нельзя выбрать как запись');
    assertContains('Допустимо: a.iso', $msg);
});

// =================================================================
// allowedList — форматирование списка в тексте ошибки
// =================================================================

test('allowedList: пустой список', function () {
    assertEquals('список в config/iso-list.json пуст', OnlySelector::allowedList([]));
});

test('allowedList: короткий список не обрезается', function () {
    assertEquals('a.iso, b.iso', OnlySelector::allowedList(['a.iso', 'b.iso']));
});

test('allowedList: длинный список обрезается со счётчиком', function () {
    $keys = [];
    foreach (range(1, 40) as $i) {
        $keys[] = 'entry-with-a-long-name-' . $i . '.iso';
    }
    $line = OnlySelector::allowedList($keys, 60);
    assertTrue(strlen($line) <= 60, 'строка не должна перерастать лимит: ' . strlen($line));
    assertContains('и ещё', $line);
});

test('allowedList: единственный ключ длиннее лимита остаётся целиком', function () {
    $long = str_repeat('x', 500);
    assertEquals($long, OnlySelector::allowedList([$long], 60));
});

exit(TestRunner::run());
