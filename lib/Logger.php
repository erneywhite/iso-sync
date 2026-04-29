<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Двухуровневый логгер:
 *  - человекочитаемый вывод в STDOUT (с поддержкой TTY-прогресс-бара через writeProgress)
 *  - JSON Lines в logs/<channel>.log
 * Дополнительно умеет сохранять итог последнего прогона в logs/last_run.json.
 */
final class Logger
{
    private const LEVELS = ['debug', 'info', 'warn', 'error'];

    private bool $isTty;
    private string $logFile;
    private bool $silent;

    public function __construct(
        private readonly string $logDir,
        string $channel = 'update',
        ?bool $forceTty = null,
        bool $silent = false
    ) {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . DIRECTORY_SEPARATOR . $channel . '.log';
        $this->isTty   = $forceTty ?? self::detectTty();
        $this->silent  = $silent;
    }

    public function isTty(): bool
    {
        return $this->isTty;
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log('warn', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Пишет строку прогресс-бара. В TTY — с \r и без перевода строки.
     * В не-TTY (cron) — выводит каждые ~10% или каждые 5 секунд, чтобы не засорять логи.
     */
    public function writeProgress(string $line, float $percent, ?array &$state = null): void
    {
        if ($this->silent) {
            return;
        }

        if ($this->isTty) {
            fwrite(STDOUT, "\r" . $line);
            return;
        }

        // Не-TTY: показываем только при значительном изменении
        $state ??= ['lastPercent' => -10.0, 'lastTime' => 0];
        $now = time();
        if (($percent - $state['lastPercent']) >= 10.0 || ($now - $state['lastTime']) >= 30) {
            fwrite(STDOUT, $line . "\n");
            $state['lastPercent'] = $percent;
            $state['lastTime']    = $now;
        }
    }

    /** Завершает прогресс-бар (перенос строки в TTY). */
    public function endProgress(): void
    {
        if ($this->isTty && !$this->silent) {
            fwrite(STDOUT, "\n");
        }
    }

    public function saveLastRun(array $summary): void
    {
        $summary['finished_at'] = date('c');
        $path = $this->logDir . DIRECTORY_SEPARATOR . 'last_run.json';
        file_put_contents($path, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    }

    private function log(string $level, string $message, array $context): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $record = array_merge([
            'ts'      => date('c'),
            'level'   => $level,
            'message' => $message,
        ], $context);

        // JSON Lines в файл
        @file_put_contents(
            $this->logFile,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        // Человекочитаемо в STDOUT
        if ($this->silent) {
            return;
        }

        $stream = $level === 'error' ? STDERR : STDOUT;
        $prefix = match ($level) {
            'error' => '[ERROR] ',
            'warn'  => '[WARN]  ',
            'debug' => '[DEBUG] ',
            default => '',
        };
        fwrite($stream, $prefix . $message . "\n");
    }

    private static function detectTty(): bool
    {
        if (!defined('STDOUT')) {
            return false;
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        return false;
    }
}
