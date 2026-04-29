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

use IsoSync\Config;
use IsoSync\Downloader;
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
    $config     = Config::loadFromFile($configPath);
    $hashCache  = new HashCache($cacheDir);
    $http       = new Http();
    $downloader = new Downloader($http, $logger);
    $gpg        = new GpgVerifier($http, $logger);

    $updater = new Updater(
        config:     $config,
        localDir:   $localDir,
        hashCache:  $hashCache,
        downloader: $downloader,
        http:       $http,
        gpg:        $gpg,
        logger:     $logger,
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
