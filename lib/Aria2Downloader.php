<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Альтернативный downloader на базе внешнего бинарника aria2c.
 *
 * Зачем: aria2c делит файл на N сегментов и качает их параллельно через HTTP Range.
 * Это обходит per-connection rate-limit многих серверов (Proxmox, Microsoft, Ubuntu CDN)
 * и часто даёт x2-x4 ускорение по сравнению с обычным cURL.
 *
 * Установка: `apt install aria2` (Debian/Ubuntu) или `dnf install aria2` (RHEL).
 *
 * При отсутствии aria2c в PATH класс просто помечается как недоступный (isAvailable=false),
 * и update_iso.php автоматически упадёт обратно на cURL-Downloader.
 */
final class Aria2Downloader implements DownloaderInterface
{
    private const DEFAULT_CONNECTIONS = 16;
    private const MIN_SPLIT_SIZE      = '1M';
    private const USER_AGENT          = 'iso-sync/2.0 (+https://github.com/erneywhite/iso-sync)';

    private readonly ?string $aria2Bin;

    public function __construct(
        private readonly Http $http,
        private readonly Logger $logger,
        ?string $aria2Bin = null,
        private readonly int $connections = self::DEFAULT_CONNECTIONS,
    ) {
        $this->aria2Bin = $aria2Bin ?? self::findAria2();
    }

    public function isAvailable(): bool
    {
        return $this->aria2Bin !== null;
    }

    public function binaryPath(): ?string
    {
        return $this->aria2Bin;
    }

    /**
     * @param ?array{mtime:int,size:int} $localFileInfo
     * @return array{success:bool, skipped:bool, expected_size:?int, actual_size:?int, error:?string}
     */
    public function download(
        string $url,
        string $destination,
        bool $insecure = false,
        ?array $localFileInfo = null,
        bool $checkUnchanged = false
    ): array {
        if ($this->aria2Bin === null) {
            return ['success' => false, 'skipped' => false, 'expected_size' => null, 'actual_size' => null,
                    'error' => 'aria2c не найден в PATH'];
        }

        // HEAD для skip_if_unchanged и валидации Content-Length
        $head = $this->http->head($url, $insecure);
        $expectedSize = $head['content_length'] ?? null;
        $remoteMTime  = $head['last_modified']  ?? null;

        // skip_if_unchanged: совпадение И размера И mtime → файл точно не менялся
        if ($checkUnchanged && $localFileInfo !== null) {
            $sizeMatch  = $expectedSize !== null && $expectedSize === $localFileInfo['size'];
            $mtimeMatch = $remoteMTime !== null  && $remoteMTime  <= $localFileInfo['mtime'];
            if ($sizeMatch && $mtimeMatch) {
                $this->logger->info('Файл не изменился (size+mtime совпали), пропуск', [
                    'event'        => 'skip_unchanged',
                    'url'          => $url,
                    'size'         => $expectedSize,
                    'remote_mtime' => $remoteMTime ? date('c', $remoteMTime) : null,
                    'local_mtime'  => date('c', $localFileInfo['mtime']),
                ]);
                return ['success' => true, 'skipped' => true, 'expected_size' => $expectedSize,
                        'actual_size' => null, 'error' => null];
            }
        }

        $tmpDir  = dirname($destination);
        $tmpName = basename($destination);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        // aria2c сам управляет .aria2 control-файлом для resume.
        // Если предыдущая попытка оставила .tmp без .aria2 — стираем, чтобы начать заново.
        $controlFile = $destination . '.aria2';
        if (file_exists($destination) && !file_exists($controlFile)) {
            @unlink($destination);
        }

        // В TTY показываем summary каждые 2 сек, в cron — реже, чтобы лог не разрастался
        $summaryInterval = $this->logger->isTty() ? 2 : 60;

        $args = [
            escapeshellcmd($this->aria2Bin),
            '-x' . $this->connections,
            '-s' . $this->connections,
            '-k' . self::MIN_SPLIT_SIZE,
            '--continue=true',
            '--allow-overwrite=true',
            '--auto-file-renaming=false',
            '--console-log-level=warn',
            '--summary-interval=' . $summaryInterval,
            '--max-tries=5',
            '--retry-wait=10',
            '--connect-timeout=30',
            '--timeout=60',
            '--check-certificate=' . ($insecure ? 'false' : 'true'),
            '--user-agent=' . escapeshellarg(self::USER_AGENT),
            '-d', escapeshellarg($tmpDir),
            '-o', escapeshellarg($tmpName),
            escapeshellarg($url),
        ];
        $cmd = implode(' ', $args) . ' 2>&1';

        $this->logger->info(sprintf(
            'Загрузка через aria2c (%d потоков): %s',
            $this->connections, $url
        ), ['event' => 'aria2_start', 'url' => $url, 'connections' => $this->connections]);

        $exitCode = -1;
        // passthru — чтобы пользователь сразу видел live-прогресс aria2c
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            $msg = "aria2c завершился с кодом {$exitCode}";
            $this->logger->error($msg, ['event' => 'aria2_failed', 'exit' => $exitCode, 'url' => $url]);
            // Чистим возможные артефакты
            @unlink($destination);
            @unlink($controlFile);
            return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize,
                    'actual_size' => null, 'error' => $msg];
        }

        // Удаляем .aria2 control-файл (после успеха он не нужен)
        @unlink($controlFile);

        $actualSize = @filesize($destination);
        if ($actualSize === false) {
            $msg = "файл после aria2c не найден: {$destination}";
            $this->logger->error($msg, ['event' => 'aria2_no_file', 'destination' => $destination]);
            return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize,
                    'actual_size' => null, 'error' => $msg];
        }

        // Сравнение размеров если знаем ожидаемый
        if ($expectedSize !== null && (int)$actualSize !== $expectedSize) {
            $msg = "размер не совпал: ожидали {$expectedSize}, получили {$actualSize}";
            $this->logger->error($msg, [
                'event'         => 'aria2_size_mismatch',
                'url'           => $url,
                'expected_size' => $expectedSize,
                'actual_size'   => (int)$actualSize,
            ]);
            @unlink($destination);
            return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize,
                    'actual_size' => (int)$actualSize, 'error' => $msg];
        }

        $this->logger->info('Загрузка через aria2c завершена', [
            'event'         => 'aria2_done',
            'url'           => $url,
            'expected_size' => $expectedSize,
            'actual_size'   => (int)$actualSize,
        ]);

        return [
            'success'       => true,
            'skipped'       => false,
            'expected_size' => $expectedSize,
            'actual_size'   => (int)$actualSize,
            'error'         => null,
        ];
    }

    /**
     * Ищет aria2c в PATH. Возвращает абсолютный путь или null.
     */
    private static function findAria2(): ?string
    {
        $cmd = (PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . 'aria2c';
        $out = @shell_exec($cmd . ' 2>/dev/null');
        if (!is_string($out)) return null;

        $path = trim(strtok($out, "\n") ?: '');
        if ($path === '') return null;
        if (!@is_executable($path)) return null;

        return $path;
    }
}
