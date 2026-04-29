<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\HashCache;

require_once __DIR__ . '/../lib/HashCache.php';
require_once __DIR__ . '/TestRunner.php';

$tmpRoot = sys_get_temp_dir() . '/iso_sync_test_' . bin2hex(random_bytes(4));

test('compute and read back', function () use ($tmpRoot) {
    $cacheDir = $tmpRoot . '/cache_a';
    $filesDir = $tmpRoot . '/files_a';
    @mkdir($filesDir, 0755, true);
    $f = $filesDir . '/sample.txt';
    file_put_contents($f, 'hello world');

    $cache = new HashCache($cacheDir);
    $hash = $cache->getOrCompute($f);
    assertTrue($hash !== null && str_starts_with($hash, 'sha256:'), "ожидали 'sha256:...', получили {$hash}");

    $second = $cache->get($f);
    assertEquals($hash, $second, 'кэш должен вернуть тот же хэш');
});

test('cache invalidates on size change', function () use ($tmpRoot) {
    $cacheDir = $tmpRoot . '/cache_b';
    $filesDir = $tmpRoot . '/files_b';
    @mkdir($filesDir, 0755, true);
    $f = $filesDir . '/changing.txt';

    file_put_contents($f, 'one');
    $cache = new HashCache($cacheDir);
    $h1 = $cache->getOrCompute($f);

    // sleep чтобы поменялся mtime, и заодно меняем содержимое
    file_put_contents($f, 'a much longer payload than the original');
    touch($f, time() + 5);

    $h2 = $cache->getOrCompute($f);
    assertTrue($h1 !== $h2, 'хэш должен поменяться при изменении файла');
});

test('stripPrefix removes sha256:', function () {
    assertEquals('abc123', HashCache::stripPrefix('sha256:abc123'));
    assertEquals('abc123', HashCache::stripPrefix('abc123'));
    assertEquals(null, HashCache::stripPrefix(null));
});

test('pruneOrphans removes stale cache entries', function () use ($tmpRoot) {
    $cacheDir = $tmpRoot . '/cache_c';
    $filesDir = $tmpRoot . '/files_c';
    @mkdir($filesDir, 0755, true);

    $cache = new HashCache($cacheDir);

    $f1 = $filesDir . '/a.txt';
    $f2 = $filesDir . '/b.txt';
    file_put_contents($f1, 'aaa');
    file_put_contents($f2, 'bbb');
    $cache->getOrCompute($f1);
    $cache->getOrCompute($f2);

    // Удаляем второй файл с диска, но кэш-запись остаётся
    @unlink($f2);

    $removed = $cache->pruneOrphans([$f1]);
    assertEquals(1, $removed, 'должна удалиться 1 осиротевшая запись');
    assertTrue($cache->get($f1) !== null, 'для существующего файла кэш должен остаться');
});

// cleanup при выходе
register_shutdown_function(function () use ($tmpRoot) {
    if (is_dir($tmpRoot)) {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmpRoot);
    }
});

exit(TestRunner::run());
