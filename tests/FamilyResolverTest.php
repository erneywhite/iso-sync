<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\FamilyResolver;

require_once __DIR__ . '/../lib/FamilyResolver.php';
require_once __DIR__ . '/TestRunner.php';

// =================================================================
// applyTemplate
// =================================================================

test('applyTemplate подставляет одну capture group', function () {
    $r = FamilyResolver::applyTemplate(
        'Proxmox_BackUP_{1}.iso',
        [0 => 'proxmox-backup-server_4.2-1.iso', 1 => '4.2-1']
    );
    assertEquals('Proxmox_BackUP_4.2-1.iso', $r);
});

test('applyTemplate подставляет несколько групп', function () {
    $r = FamilyResolver::applyTemplate(
        '{1}_v{2}.zip',
        [0 => 'whole', 1 => 'tool', 2 => '3.4']
    );
    assertEquals('tool_v3.4.zip', $r);
});

test('applyTemplate с {0} = whole match', function () {
    $r = FamilyResolver::applyTemplate(
        'copy-of-{0}',
        [0 => 'foo.iso']
    );
    assertEquals('copy-of-foo.iso', $r);
});

test('applyTemplate без плейсхолдеров возвращает шаблон as-is', function () {
    $r = FamilyResolver::applyTemplate('static-name.iso', [0 => 'whatever']);
    assertEquals('static-name.iso', $r);
});

test('applyTemplate с отсутствующей группой подставляет пусто', function () {
    $r = FamilyResolver::applyTemplate('{1}-{2}', [0 => 'x', 1 => 'a']);
    assertEquals('a-', $r);
});

// =================================================================
// templateToRegex
// =================================================================

test('templateToRegex для простого шаблона', function () {
    $regex = FamilyResolver::templateToRegex('Proxmox_BackUP_{1}.iso');
    assertEquals('/^Proxmox_BackUP_.+\\.iso$/', $regex);
    // Должна матчиться под старые версии
    assertTrue(preg_match($regex, 'Proxmox_BackUP_4.0-1.iso') === 1, 'старая версия');
    assertTrue(preg_match($regex, 'Proxmox_BackUP_4.2-1.iso') === 1, 'новая версия');
    // Не должна матчиться чужие файлы
    assertTrue(preg_match($regex, 'ProxmoxVE_8.4.iso') === 0, 'другое семейство');
    assertTrue(preg_match($regex, 'random.iso') === 0, 'случайный файл');
});

test('templateToRegex с двумя плейсхолдерами', function () {
    $regex = FamilyResolver::templateToRegex('Foo_{1}_{2}.bar');
    assertEquals('/^Foo_.+_.+\\.bar$/', $regex);
    assertTrue(preg_match($regex, 'Foo_a_b.bar') === 1);
    assertTrue(preg_match($regex, 'Foo_xyz.bar') === 0, 'нужны оба разделителя');
});

test('templateToRegex со спецсимволами в литерале', function () {
    // Точки и плюс должны быть escape'нуты
    $regex = FamilyResolver::templateToRegex('a+b.{1}.iso');
    assertEquals('/^a\\+b\\..+\\.iso$/', $regex);
});

// =================================================================
// pickHighest
// =================================================================

test('pickHighest возвращает старшего по strnatcasecmp', function () {
    $hashes = [
        'proxmox-backup-server_1.1-1.iso' => 'aaa',
        'proxmox-backup-server_2.4-1.iso' => 'bbb',
        'proxmox-backup-server_4.1-1.iso' => 'ccc',
        'proxmox-backup-server_4.2-1.iso' => 'ddd',
    ];
    $r = FamilyResolver::pickHighest($hashes, '/^proxmox-backup-server_4\\.(\\d+)-\\d+\\.iso$/');

    assertTrue($r !== null);
    assertEquals('proxmox-backup-server_4.2-1.iso', $r['name']);
    assertEquals('ddd', $r['hash']);
    assertEquals('2', $r['matches'][1]);
});

test('pickHighest учитывает natural sort (4.10 > 4.9)', function () {
    $hashes = [
        'app_4.9.iso'  => 'old',
        'app_4.10.iso' => 'new',
        'app_4.2.iso'  => 'older',
    ];
    $r = FamilyResolver::pickHighest($hashes, '/^app_(\\d+\\.\\d+)\\.iso$/');
    assertTrue($r !== null);
    assertEquals('app_4.10.iso', $r['name']);
});

test('pickHighest возвращает null при отсутствии матчей', function () {
    $hashes = ['nothing-matches.iso' => 'h'];
    $r = FamilyResolver::pickHighest($hashes, '/^foo_(.+)\\.iso$/');
    assertEquals(null, $r);
});

test('pickHighest с одним кандидатом', function () {
    $hashes = ['only-one_3.0.iso' => 'h'];
    $r = FamilyResolver::pickHighest($hashes, '/^only-one_(.+)\\.iso$/');
    assertTrue($r !== null);
    assertEquals('only-one_3.0.iso', $r['name']);
    assertEquals('3.0', $r['matches'][1]);
});

test('pickHighest игнорирует невалидный regex (возвращает null без падения)', function () {
    $hashes = ['file.iso' => 'h'];
    // Невалидный regex (не закрытая скобка) — preg_match вернёт false
    $r = @FamilyResolver::pickHighest($hashes, '/^(unclosed/');
    assertEquals(null, $r);
});

// =================================================================
// Интеграционный сценарий: applyTemplate с результатом pickHighest
// =================================================================

test('end-to-end: pickHighest + applyTemplate для proxmox-backup', function () {
    $hashes = [
        'proxmox-backup-server_1.1-1.iso' => 'aaa',
        'proxmox-backup-server_4.0-1.iso' => 'bbb',
        'proxmox-backup-server_4.2-1.iso' => 'ccc',
    ];
    $picked = FamilyResolver::pickHighest($hashes, '/^proxmox-backup-server_4\\.(\\d+)-\\d+\\.iso$/');
    $local  = FamilyResolver::applyTemplate('Proxmox_BackUP_4.{1}.iso', $picked['matches']);
    assertEquals('Proxmox_BackUP_4.2.iso', $local);
});

test('end-to-end: для proxmox-ve-7 берём только 7.x', function () {
    $hashes = [
        'proxmox-ve_7.4-1.iso' => 'a',
        'proxmox-ve_8.4-1.iso' => 'b',
        'proxmox-ve_9.1-1.iso' => 'c',
    ];
    $picked = FamilyResolver::pickHighest($hashes, '/^proxmox-ve_7\\.(\\d+)-\\d+\\.iso$/');
    assertTrue($picked !== null);
    assertEquals('proxmox-ve_7.4-1.iso', $picked['name']);
    $local = FamilyResolver::applyTemplate('ProxmoxVE_7.{1}.iso', $picked['matches']);
    assertEquals('ProxmoxVE_7.4.iso', $local);
});

exit(TestRunner::run());
