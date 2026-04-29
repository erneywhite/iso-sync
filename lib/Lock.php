<?php
declare(strict_types=1);

namespace IsoSync;

use RuntimeException;

/**
 * Эксклюзивная блокировка через flock. Не блокируется — если кто-то уже взял лок,
 * acquire() вернёт false (для скриптов под cron хочется именно так: тихо выйти, а не висеть).
 *
 * Использование:
 *   $lock = new Lock(__DIR__ . '/.update.lock');
 *   if (!$lock->acquire()) { exit(0); }
 *   register_shutdown_function([$lock, 'release']);
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $path
    ) {}

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($this->path, 'c');
        if ($fp === false) {
            throw new RuntimeException("Не удалось открыть лок-файл: {$this->path}");
        }

        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid() . "\n");
        fflush($fp);

        $this->handle = $fp;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) return;
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        @unlink($this->path);
        $this->handle = null;
    }
}
