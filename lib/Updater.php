<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Главный оркестратор обновления ISO-образов.
 * Принимает Config, прогоняет каждую запись через единый алгоритм:
 *
 *   1) скачать SHA256SUMS (если есть)
 *   2) опционально проверить GPG-подпись
 *   3) разрешить 'latest' в реальное имя файла (по версионной сортировке)
 *   4) сравнить локальный SHA256 с удалённым
 *   5) при необходимости скачать в .tmp, проверить Content-Length, переименовать
 */
final class Updater
{
    /** @var array<int,string> */
    private const DEFAULT_CHECKSUM_FILES = [
        'SHA256SUMS', 'SHA256SUM', 'sha256sum.txt', 'sha256sums.txt', 'CHECKSUM',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly string $localDir,
        private readonly HashCache $hashCache,
        private readonly Downloader $downloader,
        private readonly Http $http,
        private readonly GpgVerifier $gpg,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array<string,mixed> сводка прогона для last_run.json
     */
    public function run(): array
    {
        $startedAt = time();
        $results   = [];

        foreach ($this->config->files as $entry) {
            $this->logger->info("Обрабатываем: {$entry->localName}", [
                'event' => 'process_start',
                'file'  => $entry->localName,
            ]);
            $results[$entry->localName] = $this->processOne($entry);
        }

        $summary = [
            'started_at'  => date('c', $startedAt),
            'duration_s'  => time() - $startedAt,
            'total'       => count($results),
            'updated'     => count(array_filter($results, fn($r) => $r['status'] === 'updated')),
            'up_to_date'  => count(array_filter($results, fn($r) => $r['status'] === 'up_to_date')),
            'skipped'     => count(array_filter($results, fn($r) => $r['status'] === 'skipped')),
            'failed'      => count(array_filter($results, fn($r) => $r['status'] === 'failed')),
            'results'     => $results,
        ];

        $this->logger->info(sprintf(
            'Готово: всего %d, обновлено %d, актуально %d, пропущено %d, ошибки %d',
            $summary['total'], $summary['updated'], $summary['up_to_date'], $summary['skipped'], $summary['failed']
        ), ['event' => 'run_summary']);

        return $summary;
    }

    /** @return array{status:string, message:string, expected_size?:?int, actual_size?:?int} */
    private function processOne(IsoEntry $entry): array
    {
        $localPath = $this->localPathFor($entry);

        if (file_exists($localPath) && !is_readable($localPath)) {
            return ['status' => 'failed', 'message' => 'локальный файл нечитаем'];
        }

        // Скачать файл с чексуммами
        $checksumNames = $entry->checksumFiles ?? self::DEFAULT_CHECKSUM_FILES;
        $shaContent = null;
        $shaUrl = null;
        foreach ($checksumNames as $name) {
            $tryUrl = $entry->urlDir . $name;
            $this->logger->info("Пробуем чексуммы: {$tryUrl}", ['event' => 'checksum_try', 'url' => $tryUrl]);
            $body = $this->http->getText($tryUrl, $entry->insecureSsl);
            if ($body !== null) {
                $shaContent = $body;
                $shaUrl     = $tryUrl;
                break;
            }
        }

        // Чексуммы недоступны
        if ($shaContent === null) {
            if ($entry->forceDownloadWithoutChecksum) {
                return $this->downloadWithoutChecksum($entry, $localPath, 'чексуммы недоступны');
            }
            $msg = "не удалось скачать SHA256SUMS из {$entry->urlDir}";
            $this->logger->warn($msg, ['event' => 'no_checksums', 'file' => $entry->localName]);
            return ['status' => 'failed', 'message' => $msg];
        }

        // Опциональная проверка GPG-подписи
        if ($entry->gpgSignatureUrl !== null) {
            $verdict = $this->gpg->verify($entry->gpgSignatureUrl, $shaContent, $entry->gpgKeyFingerprint, $entry->insecureSsl);
            if (!$verdict['ok']) {
                $msg = "GPG verify failed: {$verdict['reason']}";
                $this->logger->error($msg, ['event' => 'gpg_failed', 'file' => $entry->localName]);
                return ['status' => 'failed', 'message' => $msg];
            }
        }

        $remoteHashes = ChecksumParser::parse($shaContent);
        $remoteName   = $entry->remoteName;

        // Разрешить 'latest' с версионной сортировкой
        if ($entry->isLatest()) {
            $resolved = $this->resolveLatest($remoteHashes, $entry->latestPattern);
            if ($resolved === null) {
                $msg = "не найден файл по шаблону {$entry->latestPattern} в {$shaUrl}";
                $this->logger->warn($msg, ['event' => 'latest_not_found', 'file' => $entry->localName]);
                return ['status' => 'failed', 'message' => $msg];
            }
            $remoteName = $resolved;
            $this->logger->info("'latest' разрешено в: {$remoteName}", ['event' => 'latest_resolved', 'file' => $entry->localName]);
        }

        // Нет записи о конкретном файле в SHA256SUMS
        if (!isset($remoteHashes[$remoteName])) {
            if ($entry->forceDownloadWithoutChecksum) {
                return $this->downloadWithoutChecksum($entry, $localPath, "нет записи для {$remoteName} в чексуммах");
            }
            $msg = "в SHA256SUMS нет записи для {$remoteName}";
            $this->logger->warn($msg, ['event' => 'checksum_entry_missing', 'file' => $entry->localName]);
            return ['status' => 'failed', 'message' => $msg];
        }

        // Сравнение хэшей
        $remoteHash = strtolower($remoteHashes[$remoteName]);

        // Если локальный файл есть и кэш-промах — hash_file пробежится по всему файлу.
        // На 8 GB ISO это ~20-30 сек тишины. Предупредим пользователя, чтобы не казалось, что зависло.
        $localHash = null;
        if (file_exists($localPath)) {
            $cached = $this->hashCache->get($localPath);
            if ($cached !== null) {
                $localHash = $cached;
            } else {
                $size = (int)@filesize($localPath);
                $this->logger->info(sprintf(
                    'Считаем SHA256 локального файла %s (%s) — это может занять время...',
                    basename($localPath),
                    self::humanSize($size)
                ), ['event' => 'local_hash_compute', 'file' => $entry->localName, 'size' => $size]);
                $localHash = $this->hashCache->getOrCompute($localPath);
            }
        }
        $localHex = HashCache::stripPrefix($localHash);

        $this->logger->info(sprintf(
            'Хэш local=%s remote=%s',
            $localHex ?? 'отсутствует',
            $remoteHash
        ), ['event' => 'hash_compare', 'file' => $entry->localName]);

        if ($localHex !== null && hash_equals($remoteHash, $localHex)) {
            return ['status' => 'up_to_date', 'message' => 'хэши совпадают'];
        }

        // Загрузка
        return $this->doDownload(
            $entry,
            $entry->urlDir . $remoteName,
            $localPath,
            expectedHash: $remoteHash
        );
    }

    private function downloadWithoutChecksum(IsoEntry $entry, string $localPath, string $reasonMsg): array
    {
        $this->logger->warn("Скачиваем без проверки хэша ({$reasonMsg}): {$entry->localName}", [
            'event'  => 'force_download',
            'file'   => $entry->localName,
            'reason' => $reasonMsg,
        ]);
        return $this->doDownload(
            $entry,
            $entry->urlDir . $entry->remoteName,
            $localPath,
            expectedHash: null
        );
    }

    private function doDownload(IsoEntry $entry, string $url, string $localPath, ?string $expectedHash): array
    {
        $tmp = $localPath . '.tmp';
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $localMTime = file_exists($localPath) ? filemtime($localPath) : null;

        $result = $this->downloader->download(
            $url,
            $tmp,
            insecure: $entry->insecureSsl,
            expectedLastModified: $localMTime ?: null,
            checkUnchanged: $entry->skipIfUnchanged && $expectedHash === null
        );

        if (!$result['success']) {
            @unlink($tmp);
            return [
                'status'        => 'failed',
                'message'       => $result['error'] ?? 'download error',
                'expected_size' => $result['expected_size'],
                'actual_size'   => $result['actual_size'],
            ];
        }

        if ($result['skipped']) {
            return ['status' => 'skipped', 'message' => 'remote не изменился'];
        }

        // Если есть ожидаемый хэш — пере-проверяем после загрузки (доверяй, но проверяй)
        if ($expectedHash !== null) {
            $size = (int)@filesize($tmp);
            $this->logger->info(sprintf(
                'Проверяем SHA256 загруженного файла (%s)...',
                self::humanSize($size)
            ), ['event' => 'post_download_hash_start', 'file' => $entry->localName, 'size' => $size]);

            $actual = hash_file('sha256', $tmp);
            if ($actual === false || !hash_equals($expectedHash, strtolower($actual))) {
                @unlink($tmp);
                $msg = "после загрузки хэш не совпал: ожидали {$expectedHash}, получили " . ($actual ?: 'null');
                $this->logger->error($msg, ['event' => 'post_download_hash_mismatch', 'file' => $entry->localName]);
                return ['status' => 'failed', 'message' => $msg];
            }
        }

        // Атомарное переименование
        if (!@rename($tmp, $localPath)) {
            @unlink($tmp);
            $msg = "rename не удался: {$tmp} -> {$localPath}";
            $this->logger->error($msg, ['event' => 'rename_failed', 'file' => $entry->localName]);
            return ['status' => 'failed', 'message' => $msg];
        }

        // Обновим кэш сразу — пригодится index.php и следующему запуску
        $this->hashCache->forget($localPath);
        $this->hashCache->getOrCompute($localPath);

        $this->logger->info("Файл обновлён: {$entry->localName}", [
            'event'        => 'file_updated',
            'file'         => $entry->localName,
            'actual_size'  => $result['actual_size'],
        ]);

        return [
            'status'        => 'updated',
            'message'       => 'OK',
            'expected_size' => $result['expected_size'],
            'actual_size'   => $result['actual_size'],
        ];
    }

    /**
     * Среди ключей $remoteHashes отбирает совпадающие по PCRE-шаблону
     * и возвращает версионно-старший (через strnatcasecmp).
     *
     * @param array<string,string> $remoteHashes
     */
    private function resolveLatest(array $remoteHashes, string $pattern): ?string
    {
        $candidates = [];
        foreach (array_keys($remoteHashes) as $name) {
            if (@preg_match($pattern, $name) === 1) {
                $candidates[] = $name;
            }
        }
        if ($candidates === []) return null;

        // strnatcasecmp хорошо работает с именами вроде CentOS-Stream-9-20240219.0-...iso
        usort($candidates, 'strnatcasecmp');
        return end($candidates) ?: null;
    }

    public function localPathFor(IsoEntry $entry): string
    {
        return $this->localDir
            . ($entry->localSubdir !== '' ? DIRECTORY_SEPARATOR . $entry->localSubdir : '')
            . DIRECTORY_SEPARATOR . $entry->localName;
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
