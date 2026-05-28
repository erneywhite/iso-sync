<?php
declare(strict_types=1);

/**
 * Точка входа: проверка актуальности и загрузка ISO-образов.
 *
 * Запуск:    php update_iso.php
 * Конфиг:    config/iso-list.json
 * Кэш:       .hash_cache/
 * Логи:      logs/update.log  +  logs/last_run.json
 *
 * Скрипт защищён flock — одновременный второй запуск тихо завершится с кодом 0.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\Aria2Downloader;
use IsoSync\Config;
use IsoSync\Downloader;
use IsoSync\DownloaderInterface;
use IsoSync\GpgVerifier;
use IsoSync\HashCache;
use IsoSync\Http;
use IsoSync\Lock;
use IsoSync\Logger;
use IsoSync\Updater;

$baseDir   = __DIR__;
$configPath = $baseDir . '/config/iso-list.json';
$localDir   = $baseDir . '/files';
$cacheDir   = $baseDir . '/.hash_cache';
$logDir     = $baseDir . '/logs';
$lockPath   = $baseDir . '/.update.lock';

$logger = new Logger($logDir, channel: 'update');

// Эксклюзивная блокировка
$lock = new Lock($lockPath);
if (!$lock->acquire()) {
    $logger->info('Другой экземпляр уже запущен, выходим', ['event' => 'lock_busy']);
    exit(0);
}
register_shutdown_function([$lock, 'release']);

try {
    // Очистка осиротевших *.tmp от прошлых упавших прогонов (kill -9, OOM, перезагрузка).
    // Lock уже взят — мы тут единственные, любой *.tmp / *.tmp.aria2 безопасно стирать.
    // Resume-логика cURL-Downloader'а опирается на *.tmp от ТЕКУЩЕГО прогона между попытками,
    // а не от предыдущего прогона (там данные могут быть от другого URL/версии).
    $orphans = sweepOrphanTmp($localDir);
    if ($orphans > 0) {
        $logger->info("Удалено осиротевших *.tmp от прошлых прогонов: {$orphans}", [
            'event' => 'orphan_tmp_swept',
            'count' => $orphans,
        ]);
    }

    $config     = Config::loadFromFile($configPath);
    $hashCache  = new HashCache($cacheDir);
    $http       = new Http();
    $gpg        = new GpgVerifier($http, $logger);

    // cURL-Downloader нужен всегда: как fallback и как backend для ip_version=v6
    // (чистый IPv6-only через CURL_IPRESOLVE_V6; aria2c так надёжно не умеет).
    $curl = new Downloader($http, $logger);

    // Основной downloader: aria2c если есть (multi-stream через HTTP Range,
    // обходит per-connection rate-limit), иначе cURL.
    $aria2 = new Aria2Downloader($http, $logger);
    /** @var DownloaderInterface $primary */
    if ($aria2->isAvailable()) {
        $primary = $aria2;
        $logger->info('Использую aria2c для загрузки: ' . $aria2->binaryPath(), [
            'event'   => 'downloader_chosen',
            'backend' => 'aria2c',
            'binary'  => $aria2->binaryPath(),
        ]);
    } else {
        $primary = $curl;
        $logger->info('aria2c не найден, использую cURL-Downloader (apt install aria2 для ускорения)', [
            'event'   => 'downloader_chosen',
            'backend' => 'curl',
        ]);
    }

    $updater = new Updater(
        config:         $config,
        localDir:       $localDir,
        hashCache:      $hashCache,
        downloader:     $primary,
        ipv6Downloader: $curl,
        http:           $http,
        gpg:            $gpg,
        logger:         $logger,
    );

    $summary = $updater->run();
    $logger->saveLastRun($summary);

    // Полный пересчёт кэша + чистка осиротевших — как в исходном поведении
    require __DIR__ . '/generate_all_hashes.php';

    exit($summary['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    $logger->error('Фатальная ошибка: ' . $e->getMessage(), [
        'event' => 'fatal',
        'class' => $e::class,
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    $logger->saveLastRun([
        'started_at' => date('c'),
        'fatal'      => $e->getMessage(),
    ]);
    exit(2);
}

/**
 * Удаляет все *.tmp и *.tmp.aria2 в files/ (рекурсивно).
 * Вызывается под flock — мы единственный update_iso, любой такой файл = мусор.
 *
 * @return int количество удалённых
 */
function sweepOrphanTmp(string $root): int
{
    if (!is_dir($root)) return 0;
    $count = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) continue;
        $name = $f->getFilename();
        if (str_ends_with($name, '.tmp') || str_ends_with($name, '.tmp.aria2')) {
            if (@unlink($f->getPathname())) {
                $count++;
            }
        }
    }
    return $count;
}
