<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\UupBuilder;

require_once __DIR__ . '/../lib/UupBuilder.php';
require_once __DIR__ . '/TestRunner.php';

$tmpRoot = sys_get_temp_dir() . '/iso_sync_uupbuild_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0755, true);

// ---------- aria2Input ----------

test('aria2Input: url, dir, out и sha1 для каждого файла', function () {
    $files = [
        'a.cab' => ['url' => 'https://cdn/a.cab', 'sha1' => 'DA39A3EE5E6B4B0D3255BFEF95601890AFD80709', 'size' => 10],
        'b.esd' => ['url' => 'https://cdn/b.esd', 'sha1' => '0000000000000000000000000000000000000001', 'size' => 20],
    ];
    $out = UupBuilder::aria2Input($files, '/work/UUPs');

    assertContains('https://cdn/a.cab', $out);
    assertContains('  dir=/work/UUPs', $out);
    assertContains('  out=a.cab', $out);
    // SHA1 приводится к нижнему регистру — aria2 иначе не сматчит
    assertContains('  checksum=sha-1=da39a3ee5e6b4b0d3255bfef95601890afd80709', $out);
    assertContains('  out=b.esd', $out);
});

test('aria2Input: файлы без url пропускаются', function () {
    $files = [
        'good.cab' => ['url' => 'https://cdn/good', 'size' => 1],
        'bad.cab'  => ['size' => 1],
        'junk'     => 'не массив',
    ];
    $out = UupBuilder::aria2Input($files, '/w');
    assertContains('out=good.cab', $out);
    assertFalse(str_contains($out, 'bad.cab'), 'файл без url не должен попасть в загрузку');
});

test('aria2Input: некорректный sha1 не пишется', function () {
    $files = ['x' => ['url' => 'https://cdn/x', 'sha1' => 'не-хэш']];
    $out = UupBuilder::aria2Input($files, '/w');
    assertFalse(str_contains($out, 'checksum'), 'мусорный хэш ломал бы aria2');
});

test('countDownloadable считает только файлы с url', function () {
    $files = [
        'a' => ['url' => 'https://x/a'],
        'b' => ['url' => ''],
        'c' => ['url' => 'https://x/c'],
        'd' => 'мусор',
    ];
    assertEquals(2, UupBuilder::countDownloadable($files));
});

// ---------- staleFiles: ротация ----------

test('staleFiles: старые сборки той же записи под удаление', function () {
    $existing = [
        'Windows_11_25H2_26200.9278_en-us_x64.iso',   // текущая
        'Windows_11_25H2_26200.1000_en-us_x64.iso',   // прошлая
        'Windows_11_25H2_26100.5_en-us_x64.iso',      // позапрошлая
    ];
    $stale = UupBuilder::staleFiles(
        $existing,
        'Windows_11_25H2_{build}_{lang}_x64.iso',
        'Windows_11_25H2_26200.9278_en-us_x64.iso',
        'en-us',
        'PROFESSIONAL'
    );
    assertEquals(
        ['Windows_11_25H2_26200.1000_en-us_x64.iso', 'Windows_11_25H2_26100.5_en-us_x64.iso'],
        $stale
    );
});

test('staleFiles: чужие файлы в общем каталоге не трогаются', function () {
    // Каталог общий: там же лежат другие образы и совсем посторонние файлы.
    // Ротация обязана бить только по своей записи.
    $existing = [
        'Windows_11_25H2_26200.9278_en-us_x64.iso',
        'Windows_11_25H2_26200.1000_en-us_x64.iso',
        'Windows_Server_2025_26100.33296_en-us_x64.iso',
        'Windows_10_22H2_19045.7663_en-us_x64.iso',
        'my_personal_backup.iso',
        '.private',
    ];
    $stale = UupBuilder::staleFiles(
        $existing,
        'Windows_11_25H2_{build}_{lang}_x64.iso',
        'Windows_11_25H2_26200.9278_en-us_x64.iso',
        'en-us',
        'PROFESSIONAL'
    );
    assertEquals(['Windows_11_25H2_26200.1000_en-us_x64.iso'], $stale);
});

test('staleFiles: другой язык не считается своей версией', function () {
    $existing = [
        'Windows_11_25H2_26200.9278_en-us_x64.iso',
        'Windows_11_25H2_26200.1000_ru-ru_x64.iso',
    ];
    $stale = UupBuilder::staleFiles(
        $existing,
        'Windows_11_25H2_{build}_{lang}_x64.iso',
        'Windows_11_25H2_26200.9278_en-us_x64.iso',
        'en-us',
        'PROFESSIONAL'
    );
    assertEquals([], $stale, 'русская сборка — отдельная запись, не наша прошлая версия');
});

test('staleFiles: текущий файл никогда не попадает под удаление', function () {
    $keep = 'Windows_10_22H2_19045.7663_en-us_x64.iso';
    $stale = UupBuilder::staleFiles([$keep], 'Windows_10_22H2_{build}_{lang}_x64.iso', $keep, 'en-us', 'PROFESSIONAL');
    assertEquals([], $stale);
});

// ---------- состояние ----------

test('state: сохраняется и читается', function () use ($tmpRoot) {
    $p = $tmpRoot . '/state.json';
    $s = ['windows-11-25h2' => ['build' => '26200.9278', 'iso' => 'x.iso', 'built_at' => '2026-08-30T12:00:00+00:00']];
    assertTrue(UupBuilder::saveState($p, $s));
    assertEquals($s, UupBuilder::loadState($p));
});

test('state: отсутствующий файл — пустой массив, а не ошибка', function () use ($tmpRoot) {
    assertEquals([], UupBuilder::loadState($tmpRoot . '/nope.json'));
});

test('state: битый JSON не роняет прогон', function () use ($tmpRoot) {
    $p = $tmpRoot . '/broken.json';
    file_put_contents($p, '{обрезанный');
    assertEquals([], UupBuilder::loadState($p));
});

test('state: временный файл после записи не остаётся', function () use ($tmpRoot) {
    $p = $tmpRoot . '/atomic.json';
    UupBuilder::saveState($p, ['a' => ['build' => '1', 'iso' => 'i', 'built_at' => 't']]);
    assertFalse(is_file($p . '.tmp'), 'запись должна быть атомарной, без хвостов');
});

// ---------- место ----------

test('checkSpace: нужный объём считается с запасом на распаковку', function () use ($tmpRoot) {
    $r = UupBuilder::checkSpace($tmpRoot, 1000, 3.5);
    assertEquals(3500, $r['need']);
    assertTrue($r['free'] > 0, 'свободное место должно определяться');
});

test('checkSpace: заведомо недостижимый объём не проходит', function () use ($tmpRoot) {
    $r = UupBuilder::checkSpace($tmpRoot, PHP_INT_MAX >> 10, 3.5);
    assertFalse($r['ok']);
});

// ---------- findIso ----------

test('findIso: берётся самый крупный ISO', function () use ($tmpRoot) {
    $d = $tmpRoot . '/iso';
    @mkdir($d, 0755, true);
    file_put_contents($d . '/small.iso', str_repeat('x', 10));
    file_put_contents($d . '/big.iso', str_repeat('x', 5000));
    assertEquals($d . DIRECTORY_SEPARATOR . 'big.iso', UupBuilder::findIso($d));
});

test('findIso: пусто — null', function () use ($tmpRoot) {
    $d = $tmpRoot . '/empty';
    @mkdir($d, 0755, true);
    assertEquals(null, UupBuilder::findIso($d));
});

test('cleanup', function () use ($tmpRoot) {
    $rm = function (string $p) use (&$rm): void {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $rm($p . DIRECTORY_SEPARATOR . $e);
        }
        @rmdir($p);
    };
    $rm($tmpRoot);
    assertFalse(is_dir($tmpRoot));
});

exit(TestRunner::run());
