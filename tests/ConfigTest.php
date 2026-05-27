<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\Config;
use RuntimeException;

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/TestRunner.php';

/** @param array<string,mixed> $files */
function writeTmpConfig(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'isocfg_');
    file_put_contents($path, json_encode(['files' => $files], JSON_UNESCAPED_SLASHES));
    return $path;
}

function expectThrow(callable $fn, string $msg = ''): void
{
    $threw = false;
    try { $fn(); } catch (RuntimeException $e) { $threw = true; }
    assertTrue($threw, $msg !== '' ? $msg : 'ожидали RuntimeException');
}

test('ip_version по умолчанию v4', function () {
    $p = writeTmpConfig(['x.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso']]);
    $cfg = Config::loadFromFile($p);
    assertEquals('v4', $cfg->files['x.iso']->ipVersion);
    @unlink($p);
});

test('ip_version v6 принимается', function () {
    $p = writeTmpConfig(['x.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso', 'ip_version' => 'v6']]);
    $cfg = Config::loadFromFile($p);
    assertEquals('v6', $cfg->files['x.iso']->ipVersion);
    @unlink($p);
});

test('ip_version any принимается', function () {
    $p = writeTmpConfig(['x.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso', 'ip_version' => 'any']]);
    $cfg = Config::loadFromFile($p);
    assertEquals('any', $cfg->files['x.iso']->ipVersion);
    @unlink($p);
});

test('ip_version с невалидным значением отвергается', function () {
    $p = writeTmpConfig(['x.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso', 'ip_version' => 'v5']]);
    expectThrow(fn() => Config::loadFromFile($p), 'ip_version=v5 должен падать');
    @unlink($p);
});

test('remote_name и remote_pattern взаимоисключающи', function () {
    $p = writeTmpConfig(['x.iso' => ['url_dir' => 'https://e/', 'remote_name' => 'x', 'remote_pattern' => '/x/']]);
    expectThrow(fn() => Config::loadFromFile($p));
    @unlink($p);
});

test('discovery: url_template требует folder_enum/remote_pattern/local_name_template', function () {
    $p = writeTmpConfig(['d' => ['url_template' => 'https://x/{folder}/']]);
    expectThrow(fn() => Config::loadFromFile($p), 'нужны folder_enum и др.');
    @unlink($p);
});

test('discovery: полная валидная запись парсится', function () {
    $p = writeTmpConfig(['ubuntu-lts' => [
        'local_subdir'        => 'Ubuntu',
        'url_template'        => 'https://releases.ubuntu.com/{folder}/',
        'folder_enum'         => ['from' => 22, 'to' => 26, 'step' => 2, 'format' => '{0}.04'],
        'remote_pattern'      => '/^ubuntu-{folder}-x\\.iso$/',
        'local_name_template' => 'Ubuntu_{folder}.iso',
    ]]);
    $cfg = Config::loadFromFile($p);
    $e = $cfg->files['ubuntu-lts'];
    assertTrue($e->isDiscovery(), 'должна быть discovery');
    assertFalse($e->isFamily());
    assertFalse($e->isLatest());
    @unlink($p);
});

test('family: remote_pattern + local_name_template', function () {
    $p = writeTmpConfig(['pve' => [
        'url_dir' => 'https://x/',
        'remote_pattern' => '/^pve_(\\d+)\\.iso$/',
        'local_name_template' => 'PVE_{1}.iso',
    ]]);
    $cfg = Config::loadFromFile($p);
    assertTrue($cfg->files['pve']->isFamily());
    @unlink($p);
});

test('_comment ключи пропускаются', function () {
    $p = writeTmpConfig([
        '_comment_x' => 'просто заметка',
        'x.iso'      => ['url_dir' => 'https://e/', 'remote_name' => 'x.iso'],
    ]);
    $cfg = Config::loadFromFile($p);
    assertEquals(1, count($cfg->files));
    assertTrue(isset($cfg->files['x.iso']));
    @unlink($p);
});

exit(TestRunner::run());
