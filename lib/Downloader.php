<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Скачивание больших файлов с прогресс-баром, защитой от зависаний,
 * ретраями и обязательной проверкой Content-Length после загрузки.
 *
 * Атомарность достигается на уровне вызывающего: качаем в *.tmp и rename().
 */
final class Downloader implements DownloaderInterface
{
    public function __construct(
        private readonly Http $http,
        private readonly Logger $logger,
        private readonly int $maxStallSeconds = 60,
        private readonly int $maxRetries = 5,
    ) {}

    /**
     * Скачивает $url в $destination. Перед загрузкой делает HEAD, чтобы:
     *  1) узнать ожидаемый Content-Length и сверить после загрузки;
     *  2) дать возможность пропустить, если remote не изменился (skip_if_unchanged).
     *
     * @param string $url
     * @param string $destination абсолютный путь к .tmp-файлу
     * @param bool   $insecure отключение SSL для конкретного хоста
     * @param ?int   $expectedLastModified для skip_if_unchanged: mtime текущего локального файла
     * @return array{success:bool, skipped:bool, expected_size:?int, actual_size:?int, error:?string}
     */
    public function download(
        string $url,
        string $destination,
        bool $insecure = false,
        ?int $expectedLastModified = null,
        bool $checkUnchanged = false
    ): array {
        $head = $this->http->head($url, $insecure);
        $expectedSize = $head['content_length'] ?? null;
        $remoteMTime  = $head['last_modified']  ?? null;

        if ($checkUnchanged && $expectedLastModified !== null && $remoteMTime !== null
            && $remoteMTime <= $expectedLastModified) {
            $this->logger->info('Файл не изменился по Last-Modified, пропуск', [
                'event' => 'skip_unchanged',
                'url'   => $url,
                'remote_mtime' => date('c', $remoteMTime),
                'local_mtime'  => date('c', $expectedLastModified),
            ]);
            return ['success' => true, 'skipped' => true, 'expected_size' => $expectedSize, 'actual_size' => null, 'error' => null];
        }

        $attempt = 0;
        $lastError = null;
        while ($attempt < $this->maxRetries) {
            $attempt++;
            $this->logger->info("Загрузка (попытка {$attempt}/{$this->maxRetries}): {$url}", [
                'event'   => 'download_attempt',
                'url'     => $url,
                'attempt' => $attempt,
            ]);

            $fp = @fopen($destination, 'w+');
            if ($fp === false) {
                $lastError = "Не удалось открыть файл для записи: {$destination}";
                $this->logger->error($lastError, ['event' => 'open_failed']);
                return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize, 'actual_size' => null, 'error' => $lastError];
            }

            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fp);
                $lastError = 'curl_init() failed';
                $this->logger->error($lastError, ['event' => 'curl_init_failed']);
                return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize, 'actual_size' => null, 'error' => $lastError];
            }

            curl_setopt_array($ch, $this->http->commonOptions($insecure) + [
                CURLOPT_FILE        => $fp,
                CURLOPT_TIMEOUT     => 0,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_NOPROGRESS  => false,
                CURLOPT_FAILONERROR => true,
            ]);

            $progressState = [
                'lastDownloaded' => 0,
                'lastChange'     => time(),
                'tickerState'    => null,
                'lastRenderMs'   => 0,
            ];
            $logger = $this->logger;
            $maxStall = $this->maxStallSeconds;

            curl_setopt(
                $ch,
                CURLOPT_PROGRESSFUNCTION,
                function ($_res, $downloadSize, $downloaded) use (&$progressState, $logger, $maxStall) {
                    // Рендер не чаще 4 раз в секунду — иначе fwrite+fflush на каждый
                    // тик cURL съедает ощутимую часть CPU и тормозит I/O.
                    $nowMs = (int)(microtime(true) * 1000);
                    $shouldRender = ($nowMs - $progressState['lastRenderMs']) >= 250
                        || ($downloadSize > 0 && $downloaded >= $downloadSize);

                    if ($shouldRender && $downloadSize > 0) {
                        $percent = ($downloaded / $downloadSize) * 100.0;
                        $filled  = (int)round($percent / 2);
                        $bar     = str_repeat('=', $filled) . str_repeat(' ', 50 - $filled);
                        $line    = sprintf('Скачивание: %3d%% [%s] %s/%s',
                            (int)round($percent), $bar,
                            self::humanSize((int)$downloaded), self::humanSize((int)$downloadSize)
                        );
                        $logger->writeProgress($line, $percent, $progressState['tickerState']);
                        $progressState['lastRenderMs'] = $nowMs;
                    }

                    // Stall-detection делаем всегда, без throttle — это дешёво.
                    if ($downloaded > $progressState['lastDownloaded']) {
                        $progressState['lastDownloaded'] = (int)$downloaded;
                        $progressState['lastChange']     = time();
                        return 0;
                    }
                    if ((time() - $progressState['lastChange']) >= $maxStall) {
                        return 1; // прерываем загрузку
                    }
                    return 0;
                }
            );

            $ok       = curl_exec($ch);
            $errNo    = curl_errno($ch);
            $errStr   = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            $this->logger->endProgress();

            if ($ok && $errNo === 0) {
                // Проверка Content-Length
                $actualSize = @filesize($destination);
                if ($expectedSize !== null && $actualSize !== false && $actualSize !== $expectedSize) {
                    $lastError = "Размер не совпал: ожидали {$expectedSize}, получили {$actualSize}";
                    $this->logger->error($lastError, [
                        'event'         => 'size_mismatch',
                        'url'           => $url,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize,
                    ]);
                    @unlink($destination);
                } else {
                    $this->logger->info('Загрузка завершена успешно', [
                        'event'         => 'download_ok',
                        'url'           => $url,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize !== false ? (int)$actualSize : null,
                    ]);
                    return [
                        'success'       => true,
                        'skipped'       => false,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize !== false ? (int)$actualSize : null,
                        'error'         => null,
                    ];
                }
            } else {
                $lastError = "cURL #{$errNo}: {$errStr}";
                $this->logger->warn("Ошибка загрузки: {$lastError}", [
                    'event'  => 'download_error',
                    'url'    => $url,
                    'errno'  => $errNo,
                    'errstr' => $errStr,
                ]);
                @unlink($destination);
            }

            if ($attempt < $this->maxRetries) {
                $sleep = 5 * $attempt;
                $this->logger->info("Повтор через {$sleep} сек...", ['event' => 'retry_sleep', 'seconds' => $sleep]);
                sleep($sleep);
            }
        }

        $this->logger->error("Все {$this->maxRetries} попыток исчерпаны: {$url}", [
            'event' => 'download_giveup',
            'url'   => $url,
            'last_error' => $lastError,
        ]);

        return ['success' => false, 'skipped' => false, 'expected_size' => $expectedSize, 'actual_size' => null, 'error' => $lastError];
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return number_format($n, $n < 10 ? 1 : 0) . ' ' . $units[$i];
    }
}
