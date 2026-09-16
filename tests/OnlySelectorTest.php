<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\Config;
use IsoSync\OnlySelector;

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/OnlySelector.php';
require_once __DIR__ . '/TestRunner.php';

function makeOnlyConfig(): Config
{
    return new Config([
        'Debian_12.iso'  => new \IsoSync\IsoEntry('Debian_12.iso', 'Debian', 'https://x/d/', 'debian.iso', false, false, false, '/dvd/i', null, null, null, null, null, false, null, null, 'v4'),
        'ubuntu-lts'     => new \IsoSync\IsoEntry('ubuntu-lts', 'Ubuntu', '', '', false, false, false, '/dvd/i', null, null, null, null, null, false, 'https://x/{folder}/', ['from' => 22, 'to' => 24, 'step' => 2, 'format' => '{0}.04'], 'v4'),
        'proxmox-ve-8'   => new \IsoSync\IsoEntry('proxmox-ve-8', 'Proxmox', 'https://p/', '', false, false, false, '/dvd/i', null, null, null, null, '/^p_8\\\\.iso$/', 'P_{1}.iso', true, null, null, 'v4'),
        'CentOS_9.iso'   => new \IsoSync\IsoEntry('CentOS_9.iso', 'CentOS', 'https://c/', 'latest', false, false, false, '/dvd/i', null, null, null, null, null, false, null, null, 'v4'),
    ]);
}

test('без флага — пустой список (полный прогон)', function () {
    assertEquals([], OnlySelector::parseArgs(['update_iso.php']));
    assertEquals([], OnlySelector::parseArgs([]));
    assertEquals([], OnlySelector::parseArgs(['update_iso.php', '--dry-run']));
});

test('один ключ', function () {
    assertEquals(['Debian_12.iso'], OnlySelector::parseArgs(['update_iso.php', '--only=Debian_12.iso']));
});

test('несколько ключей через запятую, порядок сохраняется', function () {
    $keys = OnlySelector::parseArgs(['update_iso.php', '--only=Debian_12.iso,ubuntu-lts,proxmox-ve-8']);
    assertEquals(['Debian_12.iso', 'ubuntu-lts', 'proxmox-ve-8'], $keys);
});

test('пробелы вокруг ключей обрезаются', function () {
    $keys = OnlySelector::parseArgs(['update_iso.php', '--only=Debian_12.iso, ubuntu-lts ']);
    assertEquals(['Debian_12.iso', 'ubuntu-lts'], $keys);
});

test('несколько --only: последний выигрывает', function () {
    $keys = OnlySelector::parseArgs(['--only=a,b', '--only=c']);
    assertEquals(['c'], $keys);
});

test('все существующие ключи валидны', function () {
    $cfg = makeOnlyConfig();
    $keys = ['Debian_12.iso', 'ubuntu-lts', 'proxmox-ve-8', 'CentOS_9.iso'];
    assertEquals($keys, OnlySelector::validate($keys, $cfg));
});

test('неизвестный ключ: ошибка со списком допустимых', function () {
    $threw = null;
    try {
        OnlySelector::validate(['ubuntu-lts', 'nope.iso'], makeOnlyConfig());
    } catch (\RuntimeException $e) {
        $threw = $e;
    }
    assertTrue($threw !== null, 'должен бросить RuntimeException');
    assertContains('nope.iso', (string)$threw, 'название ключа в ошибке');
    assertContains('Debian_12.iso', (string)$threw, 'список допустимых');
    assertContains('proxmox-ve-8', (string)$threw, 'список допустимых (family)');
});

test('пустое значение флага: ошибка', function () {
    $threw = false;
    try {
        OnlySelector::validate([], makeOnlyConfig());
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assertTrue($threw, '--only= без значения должен падать');
});

test('пустой элемент в списке: ошибка', function () {
    $threw = false;
    try {
        OnlySelector::validate(['ubuntu-lts', ''], makeOnlyConfig());
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assertTrue($threw, 'пустой элемент "a,,b" должен падать');
});

test('дублирующийся ключ: ошибка', function () {
    $threw = false;
    try {
        OnlySelector::validate(['ubuntu-lts', 'ubuntu-lts'], makeOnlyConfig());
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assertTrue($threw, 'дубли должны падать');
});
