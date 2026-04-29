<?php
declare(strict_types=1);

/**
 * Минимальный test-runner без composer/PHPUnit.
 *
 * Использование:
 *     php tests/run.php
 *
 * В тестовых файлах:
 *     test('что проверяем', function () {
 *         assertEquals($expected, $actual, 'комментарий');
 *     });
 */

namespace IsoSync\Tests;

final class TestRunner
{
    /** @var list<array{name:string, fn:callable}> */
    private static array $tests = [];
    private static int $passed = 0;
    private static int $failed = 0;
    /** @var list<array{name:string,message:string}> */
    private static array $failures = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(): int
    {
        echo "Запуск " . count(self::$tests) . " тестов...\n\n";
        foreach (self::$tests as $t) {
            try {
                ($t['fn'])();
                self::$passed++;
                echo "  ✓ {$t['name']}\n";
            } catch (\Throwable $e) {
                self::$failed++;
                self::$failures[] = ['name' => $t['name'], 'message' => $e->getMessage()];
                echo "  ✗ {$t['name']}\n    " . $e->getMessage() . "\n";
            }
        }

        echo "\n";
        echo "passed: " . self::$passed . ", failed: " . self::$failed . "\n";

        if (self::$failed > 0) {
            echo "\nFAILURES:\n";
            foreach (self::$failures as $f) {
                echo "  - {$f['name']}\n    {$f['message']}\n";
            }
            return 1;
        }
        return 0;
    }
}

function test(string $name, callable $fn): void
{
    TestRunner::add($name, $fn);
}

function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}\n  " : '') .
            "ожидали:  {$expStr}\n  получили: {$actStr}"
        );
    }
}

function assertTrue(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new \AssertionError($msg !== '' ? $msg : 'ожидали true');
    }
}

function assertFalse(bool $cond, string $msg = ''): void
{
    if ($cond) {
        throw new \AssertionError($msg !== '' ? $msg : 'ожидали false');
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}\n  " : '') .
            "строка не содержит '{$needle}': '{$haystack}'"
        );
    }
}
