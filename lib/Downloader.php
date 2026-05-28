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
     * @param ?array{mtime:int,size:int} $localFileInfo
     * @return array{success:bool, skipped:bool, expected_size:?int, actual_size:?int, error:?string}
     */
    public function download(
        string $url,
        string $destination,
        bool $insecure = false,
        ?array $localFileInfo = null,
        bool $checkUnchanged = false,
        string $ipVersion = 'v4'
    ): array {
        $head = $this->http->head($url, $insecure, $ipVersion);
        $expectedSize = $head['content_length'] ?? null;
        $remoteMTime  = $head['last_modified']  ?? null;

        // skip_if_unchanged: HEAD-проверяем что и размер И Last-Modified совпадают.
        // Достаточно надёжно для force_download_without_checksum записей вроде virtio-win,
        // где у upstream'а нет SHA256 для самого ISO. Размер ловит случай когда сервер
        // touch-нул файл (mtime=сегодня), но содержимое не менялось.
        if ($checkUnchanged && $localFileInfo !== null) {
            $sizeMatch  = $expectedSize !== null && $expectedSize === $localFileInfo['size'];
            $mtimeMatch = $remoteMTime !== null  && $remoteMTime  <= $localFileInfo['mtime'];
            if ($sizeMatch && $mtimeMatch) {
                $this->logger->info('Файл не изменился (size+mtime совпали), пропуск', [
                    'event'        => 'skip_unchanged',
                    'url'          => $url,
                    'size'         => $expectedSize,
                    'remote_mtime' => date('c', $remoteMTime),
                    'local_mtime'  => date('c', $localFileInfo['mtime']),
                ]);
                return ['success' => true, 'skipped' => true, 'expected_size' => $expectedSize, 'actual_size' => null, 'error' => null];
            }
            // Размер расходится → 100% надо качать
            if ($expectedSize !== null && !$sizeMatch) {
                $this->logger->info(sprintf(
                    'Размер remote (%d) != local (%d), качаем заново',
                    $expectedSize, $localFileInfo['size']
                ), ['event' => 'size_changed', 'url' => $url]);
            }
        }

        $attempt = 0;
        $lastError = null;
        while ($attempt < $this->maxRetries) {
            $attempt++;

            // Resume: если .tmp от предыдущей попытки уцелел — продолжаем с того места.
            // Если сервер не поддерживает Range (вернёт 200 вместо 206), обработаем ниже.
            $resumeFrom = is_file($destination) ? (int)filesize($destination) : 0;
            $this->logger->info(
                $resumeFrom > 0
                    ? sprintf('Возобновляем (попытка %d/%d) с %s: %s',
                        $attempt, $this->maxRetries, self::humanSize($resumeFrom), $url)
                    : "Загрузка (попытка {$attempt}/{$this->maxRetries}): {$url}",
                ['event' => 'download_attempt', 'url' => $url, 'attempt' => $attempt, 'resume_from' => $resumeFrom]
            );

            // 'ab' если резюмим (дописываем), 'wb' если с нуля
            $fp = @fopen($destination, $resumeFrom > 0 ? 'ab' : 'wb');
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

            $opts = $this->http->commonOptions($insecure, $ipVersion) + [
                CURLOPT_FILE        => $fp,
                CURLOPT_TIMEOUT     => 0,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_NOPROGRESS  => false,
                CURLOPT_FAILONERROR => true,
            ];
            if ($resumeFrom > 0) {
                $opts[CURLOPT_RANGE] = "{$resumeFrom}-";
            }
            curl_setopt_array($ch, $opts);

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
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            fclose($fp);
            $this->logger->endProgress();

            // Если просили Range, но сервер вернул 200 (а не 206) — он проигнорил Range
            // и шлёт ПОЛНОЕ тело. Мы при этом аппендили в существующий .tmp → файл удвоен.
            // Сносим и идём на следующую итерацию с чистого листа.
            if ($resumeFrom > 0 && $ok && $errNo === 0 && $httpCode === 200) {
                $this->logger->warn(
                    "Сервер не поддерживает Range (HTTP 200 вместо 206) — начнём заново",
                    ['event' => 'range_not_supported', 'url' => $url, 'http_code' => $httpCode]
                );
                @unlink($destination);
                $lastError = 'server returned 200 for Range request';
                if ($attempt < $this->maxRetries) {
                    $sleep = 5 * $attempt;
                    $this->logger->info("Повтор через {$sleep} сек...", ['event' => 'retry_sleep', 'seconds' => $sleep]);
                    sleep($sleep);
                }
                continue;
            }

            if ($ok && $errNo === 0) {
                // Проверка Content-Length (полный размер; при 206 expectedSize — это полный файл,
                // и наш .tmp после аппенда тоже должен быть полного размера).
                $actualSize = @filesize($destination);
                if ($expectedSize !== null && $actualSize !== false && $actualSize !== $expectedSize) {
                    $lastError = "Размер не совпал: ожидали {$expectedSize}, получили {$actualSize}";
                    $this->logger->error($lastError, [
                        'event'         => 'size_mismatch',
                        'url'           => $url,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize,
                    ]);
                    // size mismatch — что-то сильно сломалось, начнём с нуля
                    @unlink($destination);
                } else {
                    $this->logger->info('Загрузка завершена успешно', [
                        'event'         => 'download_ok',
                        'url'           => $url,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize !== false ? (int)$actualSize : null,
                        'resumed_from'  => $resumeFrom,
                    ]);
                    return [
                        'success'       => true,
                        'skipped'       => false,
                        'expected_size' => $expectedSize,
                        'actual_size'   => $actualSize !== false ? (int)$actualSize : null,
                        'error'         => null,
                        'remote_mtime'  => $remoteMTime,
                    ];
                }
            } else {
                $lastError = "cURL #{$errNo}: {$errStr}";
                $this->logger->warn("Ошибка загрузки: {$lastError}", [
                    'event'    => 'download_error',
                    'url'      => $url,
                    'errno'    => $errNo,
                    'errstr'   => $errStr,
                    'http_code' => $httpCode,
                    'bytes_so_far' => @filesize($destination) ?: 0,
                ]);
                // ВАЖНО: .tmp НЕ удаляем — на следующей попытке возобновим с этой точки
                // через CURLOPT_RANGE. Это и есть основной выигрыш resume'а.
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
