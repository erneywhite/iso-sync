<?php
declare(strict_types=1);

/**
 * Подключает все классы библиотеки lib/ в правильном порядке.
 * Используется вместо composer autoload, чтобы не тянуть зависимостей.
 */

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ChecksumParser.php';
require_once __DIR__ . '/HashCache.php';
require_once __DIR__ . '/Lock.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/GpgVerifier.php';
require_once __DIR__ . '/Downloader.php';
require_once __DIR__ . '/FamilyResolver.php';
require_once __DIR__ . '/Updater.php';
