<?php
declare(strict_types=1);

/**
 * Предварительное вычисление SHA256 для всех файлов в files/
 * + удаление устаревших записей кэша.
 *
 * Запуск:    php generate_all_hashes.php
 * Кэш:       .hash_cache/
 * Логи:      logs/hashes.log
 *
 * Идемпотентен: если хэш уже посчитан и mtime+size не изменились — пересчёт пропускается.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\HashCache;
use IsoSync\Lock;
use IsoSync\Logger;

$baseDir  = __DIR__;
$filesDir = $baseDir . '/files';
$cacheDir = $baseDir . '/.hash_cache';
$logDir   = $baseDir . '/logs';
$lockPath = $baseDir . '/.hashes.lock';

$logger = new Logger($logDir, channel: 'hashes');

$lock = new Lock($lockPath);
if (!$lock->acquire()) {
    $logger->info('Другой экземпляр генерации хэшей уже запущен, выходим', ['event' => 'lock_busy']);
    exit(0);
}
register_shutdown_function([$lock, 'release']);

if (!is_dir($filesDir)) {
    $logger->warn("Каталог {$filesDir} не существует — нечего хэшировать");
    exit(0);
}

$hashCache = new HashCache($cacheDir);

$logger->info('Начинаем вычисление хэшей', ['event' => 'hashes_start']);

$paths = collectFiles($filesDir);
$computed = 0;
$cached   = 0;

foreach ($paths as $path) {
    $existed = $hashCache->get($path);
    if ($existed !== null) {
        $cached++;
        continue;
    }

    $size = @filesize($path);
    $logger->info(sprintf(
        'Считаем хэш: %s (%s)',
        basename($path),
        humanSize($size !== false ? (int)$size : 0)
    ), ['event' => 'hash_compute', 'path' => $path]);

    $start = microtime(true);
    $hash  = $hashCache->getOrCompute($path);
    $dur   = microtime(true) - $start;

    if ($hash === null) {
        $logger->warn("Не удалось посчитать хэш: {$path}", ['event' => 'hash_fail', 'path' => $path]);
        continue;
    }

    $logger->info(sprintf('  готово за %.2f сек: %s', $dur, $hash), [
        'event'    => 'hash_done',
        'path'     => $path,
        'hash'     => $hash,
        'duration' => round($dur, 3),
    ]);
    $computed++;
}

$removed = $hashCache->pruneOrphans($paths);

$logger->info(sprintf(
    'Готово: новых хэшей %d, из кэша %d, осиротевших удалено %d',
    $computed, $cached, $removed
), ['event' => 'hashes_done', 'computed' => $computed, 'from_cache' => $cached, 'pruned' => $removed]);

/**
 * Рекурсивный обход files/ — возвращает все обычные файлы (любая глубина).
 *
 * @return list<string>
 */
function collectFiles(string $root): array
{
    $out = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile()) {
            // .gitkeep и подобные служебные файлы не хэшируем
            if ($f->getFilename() === '.gitkeep') continue;
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

function humanSize(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, $n < 10 ? 1 : 0) . ' ' . $u[$i];
}
