<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\UupResolver;

require_once __DIR__ . '/../lib/UupResolver.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * Слепок того, что реально отдаёт listid.php: сборки ОС разных веток
 * вперемешку с пакетами обновлений. Номера — из живого ответа API
 * (см. docs/SESSION-HANDOFF.md, этап 2).
 */
$sample = [
    'a1' => ['uuid' => 'a1', 'title' => '.NET Framework Security Update for Windows 11 - KB5120711 (28000.9344)', 'build' => '28000.9344', 'arch' => 'amd64'],
    'a2' => ['uuid' => 'a2', 'title' => 'Windows 11, version 26H1 (28000.2804)',  'build' => '28000.2804', 'arch' => 'amd64'],
    'a3' => ['uuid' => 'a3', 'title' => 'Windows 11, version 26H2 (26300.9278)',  'build' => '26300.9278', 'arch' => 'amd64'],
    'a4' => ['uuid' => 'a4', 'title' => 'Windows 11, version 25H2 (26200.9278)',  'build' => '26200.9278', 'arch' => 'amd64'],
    'a5' => ['uuid' => 'a5', 'title' => 'Windows 11, version 25H2 (26200.1000)',  'build' => '26200.1000', 'arch' => 'amd64'],
    'a6' => ['uuid' => 'a6', 'title' => 'Windows 11, version 24H2 (26100.9278)',  'build' => '26100.9278', 'arch' => 'amd64'],
    'a7' => ['uuid' => 'a7', 'title' => 'Windows 11, version 25H2 (26200.9278)',  'build' => '26200.9278', 'arch' => 'arm64'],
    'a8' => ['uuid' => 'a8', 'title' => 'Servicing Stack Update for Windows 11 (26200.9278)', 'build' => '26200.9278', 'arch' => 'amd64'],
];

// ---------- isOsBuild ----------

test('пакеты обновлений не считаются сборкой ОС', function () {
    assertFalse(UupResolver::isOsBuild('.NET Framework Security Update for Windows 11 - KB5120711 (28000.9344)'));
    assertFalse(UupResolver::isOsBuild('Servicing Stack Update for Windows 11 (26200.9278)'));
    assertFalse(UupResolver::isOsBuild('Cumulative Update for Windows 11 KB5062553 (26200.1)'));
    assertFalse(UupResolver::isOsBuild('Dynamic Update for Windows 11 (26200.9278)'));
});

test('настоящая сборка ОС распознаётся', function () {
    assertTrue(UupResolver::isOsBuild('Windows 11, version 25H2 (26200.9278)'));
    assertTrue(UupResolver::isOsBuild('Windows Server 2025 (26100.33296)'));
});

test('«Feature update to …» — это образ, а не патч', function () {
    // Под таким заголовком в базе лежит полноценная Windows 10 22H2.
    // Если отфильтровать его вместе с обновлениями, десятку не собрать.
    assertTrue(UupResolver::isOsBuild('Feature update to Windows 10, version 22H2 (19045.7663)'));
    assertTrue(UupResolver::isOsBuild('Feature update to Windows Server, version 2004 (19041.1415)'));
});

test('«Cumulative Update Preview for …» отсекается', function () {
    // Фраза не содержит «Update for», поэтому раньше проскакивала фильтр
    // и попадала в выдачу разведки как сборка ОС.
    assertFalse(UupResolver::isOsBuild('Cumulative Update Preview for Windows Server 2016 (19043.844)'));
    assertFalse(UupResolver::isOsBuild('Cumulative Update Preview for Windows 11 Version 24H2 (26100.6725)'));
});

test('заголовок без номера сборки не проходит', function () {
    assertFalse(UupResolver::isOsBuild('Windows 11'));
    assertFalse(UupResolver::isOsBuild(''));
});

// ---------- compareBuild ----------

test('сравнение сборок: ревизия внутри одной ветки', function () {
    assertTrue(UupResolver::compareBuild('26200.9278', '26200.1000') > 0);
    assertTrue(UupResolver::compareBuild('26200.1000', '26200.9278') < 0);
    assertEquals(0, UupResolver::compareBuild('26200.9278', '26200.9278'));
});

test('сравнение сборок: ветка важнее ревизии', function () {
    assertTrue(UupResolver::compareBuild('26300.1', '26200.9999') > 0);
});

test('сравнение сборок: ревизия сравнивается числом, а не строкой', function () {
    // строкой '9278' < '10000', числом наоборот
    assertTrue(UupResolver::compareBuild('26200.10000', '26200.9278') > 0);
});

// ---------- pickBuild: главное ----------

test('выбирается стабильная 25H2, а не более свежая preview-ветка', function () use ($sample) {
    // Ровно та ловушка, ради которой всё затевалось: по дате свежее 26H1/26H2,
    // но нужна именно GA-ветка, заданная шаблоном.
    $pick = UupResolver::pickBuild($sample, '/^Windows 11, version 25H2 /', 'amd64');
    assertTrue($pick !== null, 'сборка должна найтись');
    assertEquals('a4', $pick['id']);
    assertEquals('26200.9278', $pick['build']);
});

test('среди нескольких ревизий одной ветки берётся самая свежая', function () use ($sample) {
    $pick = UupResolver::pickBuild($sample, '/^Windows 11, version 25H2 /', 'amd64');
    assertEquals('26200.9278', $pick['build'], 'не должна выбраться 26200.1000');
});

test('пакет обновления не выбирается, даже если подходит под шаблон', function () use ($sample) {
    // Шаблон нарочно широкий — отсечь должен фильтр isOsBuild
    $pick = UupResolver::pickBuild($sample, '/Windows 11/', 'amd64');
    assertTrue($pick !== null);
    assertTrue(UupResolver::isOsBuild($pick['title']), 'выбран не образ ОС: ' . $pick['title']);
    assertFalse(str_contains($pick['title'], 'KB'), 'выбран пакет обновления');
});

test('архитектура учитывается', function () use ($sample) {
    $arm = UupResolver::pickBuild($sample, '/^Windows 11, version 25H2 /', 'arm64');
    assertEquals('a7', $arm['id']);
});

test('нет совпадений — null, а не случайная сборка', function () use ($sample) {
    assertEquals(null, UupResolver::pickBuild($sample, '/^Windows 42/', 'amd64'));
});

test('Windows 10 находится по фактическому заголовку из базы', function () {
    // Реальные записи: у десятки заголовок начинается с «Feature update to»,
    // и шаблон /^Windows 10, version 22H2/ её не находил.
    $b = [
        'w1' => ['uuid' => 'w1', 'title' => 'Feature update to Windows 10, version 22H2 (19045.7663)', 'build' => '19045.7663', 'arch' => 'amd64'],
        'w2' => ['uuid' => 'w2', 'title' => 'Feature update to Windows 10, version 21H2 (19044.7663)', 'build' => '19044.7663', 'arch' => 'amd64'],
        'w3' => ['uuid' => 'w3', 'title' => 'Cumulative Update Preview for Windows 10 Version 22H2 (19045.6282)', 'build' => '19045.6282', 'arch' => 'amd64'],
    ];
    $pick = UupResolver::pickBuild($b, '/^Feature update to Windows 10, version 22H2/', 'amd64');
    assertTrue($pick !== null, 'сборка должна найтись');
    assertEquals('w1', $pick['id']);
    assertEquals('19045.7663', $pick['build']);
});

test('Server 2025 отделяется от накопительных обновлений', function () {
    $b = [
        's1' => ['uuid' => 's1', 'title' => 'Windows Server 2025 (26100.33296)', 'build' => '26100.33296', 'arch' => 'amd64'],
        's2' => ['uuid' => 's2', 'title' => 'Cumulative Update Preview for Windows Server 2016 (19043.844)', 'build' => '19043.844', 'arch' => 'amd64'],
        's3' => ['uuid' => 's3', 'title' => 'Windows Server, version 23H2 (25398.2330)', 'build' => '25398.2330', 'arch' => 'amd64'],
    ];
    $pick = UupResolver::pickBuild($b, '/^Windows Server 2025/', 'amd64');
    assertEquals('s1', $pick['id']);
});

test('битый regex не роняет и не пропускает лишнего', function () use ($sample) {
    assertEquals(null, @UupResolver::pickBuild($sample, '/[незакрытый', 'amd64'));
});

test('номер сборки берётся из заголовка, если поля build нет', function () {
    $b = ['x' => ['uuid' => 'x', 'title' => 'Windows 11, version 25H2 (26200.9278)', 'arch' => 'amd64']];
    $pick = UupResolver::pickBuild($b, '/25H2/', 'amd64');
    assertEquals('26200.9278', $pick['build']);
});

// ---------- needsRebuild ----------

test('пересборка нужна, если раньше не собирали', function () {
    assertTrue(UupResolver::needsRebuild('26200.9278', null));
    assertTrue(UupResolver::needsRebuild('26200.9278', ''));
});

test('пересборка нужна только при более свежей сборке', function () {
    assertTrue(UupResolver::needsRebuild('26200.9278', '26200.1000'));
    assertFalse(UupResolver::needsRebuild('26200.9278', '26200.9278'), 'та же сборка — не пересобираем');
    assertFalse(UupResolver::needsRebuild('26200.1000', '26200.9278'), 'откат назад не пересобираем');
});

// ---------- localName / totalSize ----------

test('имя файла содержит номер сборки', function () {
    $n = UupResolver::localName('Windows_11_25H2_{build}_{lang}_x64.iso', '26200.9278', 'en-us', 'PROFESSIONAL');
    assertEquals('Windows_11_25H2_26200.9278_en-us_x64.iso', $n);
});

test('в имени файла редакция приводится к нижнему регистру', function () {
    $n = UupResolver::localName('win_{edition}.iso', '26200.1', 'en-us', 'PROFESSIONAL');
    assertEquals('win_professional.iso', $n);
});

test('суммарный размер считается по полю size', function () {
    $files = [
        'a.cab' => ['size' => 1000, 'url' => 'https://x/a'],
        'b.esd' => ['size' => 2500, 'url' => 'https://x/b'],
        'c.txt' => ['url' => 'https://x/c'],   // без size
        'bad'   => 'не массив',
    ];
    assertEquals(3500, UupResolver::totalSize($files));
});

exit(TestRunner::run());
