<?php
declare(strict_types=1);

/**
 * Запускает все *Test.php в каталоге tests/ как отдельные процессы PHP,
 * чтобы каждая группа тестов имела свой инстанс TestRunner.
 *
 * Запуск:
 *     php tests/run.php
 */

$dir = __DIR__;
$testFiles = glob($dir . '/*Test.php') ?: [];

if ($testFiles === []) {
    echo "Тесты не найдены в {$dir}\n";
    exit(0);
}

$totalFailed = 0;
foreach ($testFiles as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($cmd, $code);
    if ($code !== 0) {
        $totalFailed++;
    }
}

if ($totalFailed > 0) {
    echo "\n{$totalFailed} test file(s) FAILED\n";
    exit(1);
}

echo "\nВсе тесты прошли успешно.\n";
exit(0);
