<?php
declare(strict_types=1);

/**
 * Точка входа: проверка актуальности и загрузка ISO-образов.
 *
 * Запуск:    php update_iso.php
 *            php update_iso.php --only=Debian_12.iso,ubuntu-lts   # частичный прогон
 * Конфиг:    config/iso-list.json
 * Кэш:       .hash_cache/
 * Логи:      logs/update.log  +  logs/last_run.json
 *
 * Без --only прогоняется весь список. С --only обрабатываются только выбранные
 * записи (любого режима), а в сводке last_run.json появляется поле only. Блокировка,
 * чистка осиротевших *.tmp и пересчёт хэшей в конце — без изменений.
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
use IsoSync\OnlySelector;
use IsoSync\Updater;

/**
 * Краткая справка по аргументам. Выводим в STDOUT и выходим с кодом 0.
 *
 * Без mb_* и heredoc с отступами: на проде CLI-PHP собран без mbstring, а
 * отступающий heredoc легко ломает парсер — строки проще собрать массивом.
 */
function printUsage(): void
{
    $lines = [
        'update_iso.php — проверка актуальности и загрузка ISO-образов',
        '',
        '  php update_iso.php                       весь список из config/iso-list.json',
        '  php update_iso.php --only=Debian_12.iso  одна запись',
        '  php update_iso.php --only=A,B            несколько записей',
        '  php update_iso.php --help                этот текст',
        '',
        'Коды возврата: 0 — всё актуально или успешно обновлено, 1 — были ошибки,',
        '2 — фатальная ошибка (битый конфиг, нет доступа к каталогам) либо',
        'неразобранный аргумент; текст ошибки в STDERR.',
    ];
    fwrite(STDOUT, implode("\n", $lines) . "\n");
}

$args = $argv ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    printUsage();
    exit(0);
}

$baseDir   = __DIR__;
$configPath = $baseDir . '/config/iso-list.json';
$localDir   = $baseDir . '/files';
$cacheDir   = $baseDir . '/.hash_cache';
$logDir     = $baseDir . '/logs';
$lockPath   = $baseDir . '/.update.lock';

$logger = new Logger($logDir, channel: 'update');

// Конфиг грузим ДО блокировки: битый конфиг останавливает прогон до того, как
// кто-то возьмёт лок или начнёт чистить *.tmp.
try {
    $config = Config::loadFromFile($configPath);
} catch (Throwable $e) {
    $logger->error('Не удалось загрузить конфиг: ' . $e->getMessage(), [
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

// Разбираем --only ДО блокировки и любой сетевой работы: битый флаг и
// неизвестный ключ в нём должны останавливать прогон до того, как что-нибудь
// начнёт качаться, до блокировки и чистки *.tmp — сообщение в STDERR и код
// выхода 2, как для неразбранного аргумента.
$only = null;
try {
    $only = OnlySelector::parseArgs(array_slice($args, 1));
    // Частичный прогон: оставляем только выбранные записи, остальной конфиг не
    // трогаем. Делается до создания Updater — тот просто не увидит лишнего.
    if ($only !== null) {
        $config = (new Config(OnlySelector::select($config->files, $only)));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(2);
}

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

    // Конфиг и выбор --only загружены до блокировки (выше): битый конфиг и
    // неизвестный ключ в --only уже вышли с кодом 2 до того, как лок был
    // взят, и last_run.json не трогали.
    if ($only !== null) {
        $logger->info('Частичный прогон по --only: ' . implode(', ', array_keys($config->files)), [
            'event' => 'only_mode',
            'only'  => $only,
        ]);
    }

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
    if ($only !== null) {
        // Поле only отличает частичный прогон от полного — его читает веб-интерфейс.
        $summary = array_merge(['only' => array_values($only)], $summary);
    }
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
