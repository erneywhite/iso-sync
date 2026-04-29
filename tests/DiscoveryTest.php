<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\Updater;
use IsoSync\FamilyResolver;

// Прямые require без bootstrap — чтобы тест не падал, если на сервере
// не обновился bootstrap.php (страховка против неполного ручного деплоя).
// Тестируем static-методы Updater::generateFolders и FamilyResolver::* —
// для них достаточно загрузить эти два файла.
require_once __DIR__ . '/../lib/FamilyResolver.php';
require_once __DIR__ . '/../lib/Updater.php';
require_once __DIR__ . '/TestRunner.php';

// =================================================================
// generateFolders — чистая функция, удобно тестируется
// =================================================================

test('generateFolders: Ubuntu .04 диапазон step=1', function () {
    $folders = Updater::generateFolders(['from' => 22, 'to' => 26, 'step' => 1, 'format' => '{0}.04']);
    assertEquals(['22.04','23.04','24.04','25.04','26.04'], $folders);
});

test('generateFolders: только LTS (step=2 от чётного)', function () {
    $folders = Updater::generateFolders(['from' => 22, 'to' => 28, 'step' => 2, 'format' => '{0}.04']);
    assertEquals(['22.04','24.04','26.04','28.04'], $folders);
});

test('generateFolders: одиночный элемент (from=to)', function () {
    $folders = Updater::generateFolders(['from' => 24, 'to' => 24, 'step' => 1, 'format' => '{0}.04']);
    assertEquals(['24.04'], $folders);
});

test('generateFolders: format без {0} оставляет литерал (но валидация в Config это не пропустит)', function () {
    $folders = Updater::generateFolders(['from' => 1, 'to' => 3, 'step' => 1, 'format' => 'static']);
    assertEquals(['static','static','static'], $folders);
});

test('generateFolders: пустой при невалидных параметрах', function () {
    assertEquals([], Updater::generateFolders([]));
    assertEquals([], Updater::generateFolders(['from' => 10, 'to' => 5, 'step' => 1, 'format' => '{0}']));
    assertEquals([], Updater::generateFolders(['from' => 1, 'to' => 5, 'step' => 0, 'format' => '{0}']));
});

test('generateFolders: произвольный формат (не только .04)', function () {
    // Например, версии CentOS Stream через discovery — гипотетический случай
    $folders = Updater::generateFolders(['from' => 7, 'to' => 11, 'step' => 1, 'format' => 'centos-{0}']);
    assertEquals(['centos-7','centos-8','centos-9','centos-10','centos-11'], $folders);
});

// =================================================================
// Подстановка {folder} в шаблоны (FamilyResolver уже тестирован,
// здесь только специфика discovery — двойная подстановка)
// =================================================================

test('discovery: {folder} → preg_quote в remote_pattern', function () {
    // Шаблон содержит литеральные точки + плейсхолдер
    $template = '/^ubuntu-{folder}(?:\\.\\d+)?-live-server-amd64\\.iso$/';
    $resolved = str_replace('{folder}', preg_quote('22.04', '/'), $template);
    assertEquals('/^ubuntu-22\\.04(?:\\.\\d+)?-live-server-amd64\\.iso$/', $resolved);

    // Проверяем что он матчит реальные имена
    assertTrue(preg_match($resolved, 'ubuntu-22.04-live-server-amd64.iso') === 1, 'без point release');
    assertTrue(preg_match($resolved, 'ubuntu-22.04.5-live-server-amd64.iso') === 1, 'с point release');
    assertTrue(preg_match($resolved, 'ubuntu-24.04.1-live-server-amd64.iso') === 0, 'другая версия не должна матчиться');
    assertTrue(preg_match($resolved, 'ubuntu-22.04-desktop-amd64.iso') === 0, 'desktop не должен матчиться');
});

test('discovery: {folder} в local_name_template без preg_quote', function () {
    $resolved = str_replace('{folder}', '22.04', 'Ubuntu_{folder}.iso');
    assertEquals('Ubuntu_22.04.iso', $resolved);
});

// =================================================================
// End-to-end сценарий: имитируем что вернул бы SHA256SUMS из /22.04/
// =================================================================

test('discovery+family end-to-end: ubuntu 22.04', function () {
    // Имитация SHA256SUMS из releases.ubuntu.com/22.04/
    $hashes = [
        'ubuntu-22.04.5-desktop-amd64.iso'        => 'aaa',
        'ubuntu-22.04.5-live-server-amd64.iso'    => 'bbb',
        'ubuntu-22.04.5-netboot-amd64.tar.gz'     => 'ccc',
    ];
    // 1) Подставляем {folder}
    $pattern = str_replace('{folder}', preg_quote('22.04', '/'),
        '/^ubuntu-{folder}(?:\\.\\d+)?-live-server-amd64\\.iso$/');
    $localTpl = str_replace('{folder}', '22.04', 'Ubuntu_{folder}.iso');

    // 2) FamilyResolver выбирает старшего
    $picked = FamilyResolver::pickHighest($hashes, $pattern);
    assertTrue($picked !== null);
    assertEquals('ubuntu-22.04.5-live-server-amd64.iso', $picked['name']);

    // 3) Локальное имя — без {N} плейсхолдеров (только {folder} был, уже подставили)
    $local = FamilyResolver::applyTemplate($localTpl, $picked['matches']);
    assertEquals('Ubuntu_22.04.iso', $local);
});

test('discovery+family: симуляция отсутствующего релиза 27.04', function () {
    // Имитация — папка существует, но нет .04-server ISO (например, в 27.10 / .04 не релизились)
    $hashes = [
        'ubuntu-27.10-live-server-amd64.iso' => 'xxx',  // не .04
    ];
    $pattern = str_replace('{folder}', preg_quote('27.04', '/'),
        '/^ubuntu-{folder}(?:\\.\\d+)?-live-server-amd64\\.iso$/');

    $picked = FamilyResolver::pickHighest($hashes, $pattern);
    assertEquals(null, $picked);
});

exit(TestRunner::run());
