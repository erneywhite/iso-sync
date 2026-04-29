<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Главный оркестратор обновления ISO-образов.
 *
 * Алгоритм для каждой записи:
 *   1) скачать SHA256SUMS (если есть)
 *   2) опционально проверить GPG-подпись
 *   3) разрешить «какое имя качать» — fixed / latest / family
 *      (см. IsoEntry для описания режимов)
 *   4) сравнить локальный SHA256 с удалённым (с использованием кэша)
 *   5) при необходимости скачать в .tmp, проверить Content-Length и хэш, переименовать
 *   6) если family + cleanup_old — удалить старые версии в той же подпапке
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
        private readonly DownloaderInterface $downloader,
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

    /** @return array<string,mixed> */
    private function processOne(IsoEntry $entry): array
    {
        if ($entry->isDiscovery()) {
            return $this->processDiscoveryEntry($entry);
        }

        // 1. Чексуммы
        [$shaContent, $shaUrl] = $this->fetchChecksums($entry);

        if ($shaContent === null) {
            // Без чексумм можно качать только в legacy/fixed режиме (не family)
            // и только если разрешён force_download_without_checksum
            if ($entry->forceDownloadWithoutChecksum && !$entry->isFamily()) {
                $localPath = $this->localPathFor($entry);
                return $this->downloadWithoutChecksum($entry, $localPath, 'чексуммы недоступны');
            }
            $msg = "не удалось скачать SHA256SUMS из {$entry->urlDir}";
            $this->logger->warn($msg, ['event' => 'no_checksums', 'file' => $entry->localName]);
            return ['status' => 'failed', 'message' => $msg];
        }

        // 2. Опциональная проверка GPG-подписи
        if ($entry->gpgSignatureUrl !== null) {
            $verdict = $this->gpg->verify($entry->gpgSignatureUrl, $shaContent, $entry->gpgKeyFingerprint, $entry->insecureSsl);
            if (!$verdict['ok']) {
                $msg = "GPG verify failed: {$verdict['reason']}";
                $this->logger->error($msg, ['event' => 'gpg_failed', 'file' => $entry->localName]);
                return ['status' => 'failed', 'message' => $msg];
            }
        }

        $remoteHashes = ChecksumParser::parse($shaContent);

        // 3. Резолвим имя удалённого файла (fixed/latest/family)
        $picked = $this->resolveRemote($entry, $remoteHashes);

        if ($picked === null) {
            // Family-режим без матчей — фейл (нет смысла в force_download без шаблона)
            if ($entry->isFamily()) {
                $msg = "ни одного файла в SHA256SUMS не совпало с {$entry->remotePattern}";
                $this->logger->warn($msg, ['event' => 'family_no_match', 'file' => $entry->localName]);
                return ['status' => 'failed', 'message' => $msg];
            }
            // legacy/fixed: нет записи о конкретном файле
            if ($entry->forceDownloadWithoutChecksum) {
                $localPath = $this->localPathFor($entry);
                return $this->downloadWithoutChecksum($entry, $localPath, "нет записи для {$entry->remoteName} в чексуммах");
            }
            $what = $entry->isLatest()
                ? "не найден файл по шаблону {$entry->latestPattern} в {$shaUrl}"
                : "в SHA256SUMS нет записи для {$entry->remoteName}";
            $this->logger->warn($what, ['event' => 'resolve_failed', 'file' => $entry->localName]);
            return ['status' => 'failed', 'message' => $what];
        }

        $remoteName = $picked['name'];
        $remoteHash = strtolower($picked['hash']);

        // 4. Локальное имя — для family строим по шаблону, иначе берём ключ записи
        $localName = $entry->isFamily()
            ? FamilyResolver::applyTemplate($entry->localNameTemplate ?? '', $picked['matches'])
            : $entry->localName;
        $localPath = $this->localPathForName($entry, $localName);

        if ($entry->isFamily() || $entry->isLatest()) {
            $this->logger->info("Разрешено: {$remoteName} → локально {$localName}", [
                'event'  => 'resolved',
                'file'   => $entry->localName,
                'remote' => $remoteName,
                'local'  => $localName,
            ]);
        }

        if (file_exists($localPath) && !is_readable($localPath)) {
            return ['status' => 'failed', 'message' => "локальный файл нечитаем: {$localPath}"];
        }

        // 5. Сравнение хэшей (с кэшем)
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
            return ['status' => 'up_to_date', 'message' => 'хэши совпадают', 'local_name' => $localName];
        }

        // 6. Загрузка
        $result = $this->doDownload($entry, $entry->urlDir . $remoteName, $localPath, $remoteHash);
        $result['local_name'] = $localName;

        // 7. Cleanup старых версий — только для family и только если успешно обновили
        if ($result['status'] === 'updated' && $entry->isFamily() && $entry->cleanupOld) {
            $removed = $this->cleanupOldSiblings($entry, $localName);
            if ($removed !== []) {
                $result['removed_siblings'] = $removed;
            }
        }

        return $result;
    }

    /**
     * Обходит DEFAULT_CHECKSUM_FILES (или entry->checksumFiles) и возвращает первое
     * успешно скачанное содержимое плюс URL.
     *
     * @return array{0:?string,1:?string}
     */
    private function fetchChecksums(IsoEntry $entry): array
    {
        $checksumNames = $entry->checksumFiles ?? self::DEFAULT_CHECKSUM_FILES;
        foreach ($checksumNames as $name) {
            $tryUrl = $entry->urlDir . $name;
            $this->logger->info("Пробуем чексуммы: {$tryUrl}", ['event' => 'checksum_try', 'url' => $tryUrl]);
            $body = $this->http->getText($tryUrl, $entry->insecureSsl);
            if ($body !== null) {
                return [$body, $tryUrl];
            }
        }
        return [null, null];
    }

    /**
     * Резолвит имя удалённого файла с учётом режима (family / latest / fixed).
     *
     * @param array<string,string> $remoteHashes
     * @return array{name:string,hash:string,matches:array<int,string>}|null
     */
    private function resolveRemote(IsoEntry $entry, array $remoteHashes): ?array
    {
        if ($entry->isFamily()) {
            return FamilyResolver::pickHighest($remoteHashes, $entry->remotePattern ?? '');
        }

        if ($entry->isLatest()) {
            return FamilyResolver::pickHighest($remoteHashes, $entry->latestPattern);
        }

        // fixed name
        if (!isset($remoteHashes[$entry->remoteName])) {
            return null;
        }
        return [
            'name'    => $entry->remoteName,
            'hash'    => $remoteHashes[$entry->remoteName],
            'matches' => [$entry->remoteName],
        ];
    }

    /** @return array<string,mixed> */
    private function downloadWithoutChecksum(IsoEntry $entry, string $localPath, string $reasonMsg): array
    {
        $this->logger->warn("Скачиваем без проверки хэша ({$reasonMsg}): {$entry->localName}", [
            'event'  => 'force_download',
            'file'   => $entry->localName,
            'reason' => $reasonMsg,
        ]);
        $result = $this->doDownload($entry, $entry->urlDir . $entry->remoteName, $localPath, null);
        $result['local_name'] = $entry->localName;
        return $result;
    }

    /** @return array<string,mixed> */
    private function doDownload(IsoEntry $entry, string $url, string $localPath, ?string $expectedHash): array
    {
        $tmp = $localPath . '.tmp';
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Соберём info о существующем локальном файле для skip_if_unchanged.
        // Downloader сравнит и Content-Length, и Last-Modified — оба должны совпасть
        // чтобы пропустить загрузку.
        $localFileInfo = null;
        if (file_exists($localPath)) {
            $localFileInfo = [
                'mtime' => (int)filemtime($localPath),
                'size'  => (int)filesize($localPath),
            ];
        }

        $result = $this->downloader->download(
            $url,
            $tmp,
            insecure: $entry->insecureSsl,
            localFileInfo: $localFileInfo,
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

        // Перепроверка SHA256 после загрузки
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

        // Обновим кэш сразу
        $this->hashCache->forget($localPath);
        $this->hashCache->getOrCompute($localPath);

        $this->logger->info("Файл обновлён: " . basename($localPath), [
            'event'        => 'file_updated',
            'file'         => $entry->localName,
            'local_path'   => $localPath,
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
     * Удаляет файлы в той же подпапке, чьё имя матчится с template-как-regex,
     * но НЕ равно $currentLocalName и не оканчивается на `.tmp`.
     *
     * @return list<string> имена удалённых файлов
     */
    private function cleanupOldSiblings(IsoEntry $entry, string $currentLocalName): array
    {
        if ($entry->localNameTemplate === null) return [];

        $dir = $this->localDir
            . ($entry->localSubdir !== '' ? DIRECTORY_SEPARATOR . $entry->localSubdir : '');
        if (!is_dir($dir)) return [];

        $siblingPattern = FamilyResolver::templateToRegex($entry->localNameTemplate);
        $removed = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === $currentLocalName) continue;
            if (str_ends_with($name, '.tmp')) continue;

            if (preg_match($siblingPattern, $name) === 1) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && @unlink($path)) {
                    $this->hashCache->forget($path);
                    $removed[] = $name;
                    $this->logger->info("Удалена предыдущая версия: {$name}", [
                        'event'   => 'cleanup_old',
                        'family'  => $entry->localName,
                        'removed' => $name,
                    ]);
                }
            }
        }

        return $removed;
    }

    /**
     * Discovery-режим: генерируем список папок по folder_enum, для каждой пробуем
     * получить SHA256SUMS, существующие — обрабатываем как family с подставленным {folder}.
     *
     * @return array<string,mixed>
     */
    private function processDiscoveryEntry(IsoEntry $entry): array
    {
        $folders = self::generateFolders($entry->folderEnum ?? []);
        $this->logger->info(sprintf(
            'Discovery: проверим %d папок по шаблону %s',
            count($folders), $entry->urlTemplate
        ), ['event' => 'discovery_start', 'folders' => $folders]);

        $subResults = [];
        $skipped404 = [];

        foreach ($folders as $folder) {
            $folderUrl = rtrim(str_replace('{folder}', $folder, $entry->urlTemplate ?? ''), '/') . '/';

            // Быстрая проверка существования: HEAD на первый файл из checksum_files
            if (!$this->folderHasChecksums($entry, $folderUrl)) {
                $skipped404[] = $folder;
                $this->logger->info("Discovery: {$folder} — нет SHA256SUMS, пропуск", [
                    'event'  => 'discovery_skip_404',
                    'folder' => $folder,
                    'url'    => $folderUrl,
                ]);
                continue;
            }

            $sub = $this->materializeFolderEntry($entry, $folder, $folderUrl);
            $this->logger->info("Discovery: обрабатываем {$folder}", [
                'event'  => 'discovery_process',
                'folder' => $folder,
            ]);
            $subResults[$folder] = $this->processOne($sub);
        }

        return self::aggregateDiscoveryResults($folders, $skipped404, $subResults);
    }

    /**
     * Проверяет наличие хотя бы одного SHA256SUMS-файла в папке через HEAD.
     */
    private function folderHasChecksums(IsoEntry $entry, string $folderUrl): bool
    {
        $names = $entry->checksumFiles ?? self::DEFAULT_CHECKSUM_FILES;
        foreach ($names as $name) {
            $head = $this->http->head($folderUrl . $name, $entry->insecureSsl);
            if ($head !== null && $head['status'] >= 200 && $head['status'] < 300) {
                return true;
            }
        }
        return false;
    }

    /**
     * Создаёт синтетический IsoEntry для конкретной папки в discovery — с подставленным
     * {folder} в url_dir, remote_pattern и local_name_template. Этот entry отрабатывает
     * через обычный family-flow в processOne (т.к. urlTemplate=null, recursion невозможна).
     */
    private function materializeFolderEntry(IsoEntry $entry, string $folder, string $folderUrl): IsoEntry
    {
        return new IsoEntry(
            localName:                    $entry->localName . '/' . $folder,
            localSubdir:                  $entry->localSubdir,
            urlDir:                       $folderUrl,
            remoteName:                   '',
            forceDownloadWithoutChecksum: $entry->forceDownloadWithoutChecksum,
            skipIfUnchanged:              $entry->skipIfUnchanged,
            insecureSsl:                  $entry->insecureSsl,
            latestPattern:                $entry->latestPattern,
            checksumFiles:                $entry->checksumFiles,
            gpgSignatureUrl:              $entry->gpgSignatureUrl,
            gpgKeyFingerprint:            $entry->gpgKeyFingerprint,
            remotePattern:                str_replace('{folder}', preg_quote($folder, '/'), $entry->remotePattern ?? ''),
            localNameTemplate:            str_replace('{folder}', $folder, $entry->localNameTemplate ?? ''),
            cleanupOld:                   $entry->cleanupOld,
            urlTemplate:                  null,
            folderEnum:                   null,
        );
    }

    /**
     * Генерирует список имён папок из folder_enum.
     * Пример: from=22, to=30, step=1, format="{0}.04" → ["22.04","23.04",...,"30.04"]
     *
     * @param array{from?:int,to?:int,step?:int,format?:string} $enum
     * @return list<string>
     */
    public static function generateFolders(array $enum): array
    {
        // Без явных from/to считаем enum невалидным (Config-валидация это и так
        // отвергнет, но для прямых вызовов хотим предсказуемый результат).
        if (!isset($enum['from'], $enum['to'])) return [];

        $from   = (int)$enum['from'];
        $to     = (int)$enum['to'];
        $step   = (int)($enum['step']   ?? 1);
        $format = (string)($enum['format'] ?? '{0}');

        if ($step <= 0 || $from > $to) return [];

        $folders = [];
        for ($i = $from; $i <= $to; $i += $step) {
            $folders[] = str_replace('{0}', (string)$i, $format);
        }
        return $folders;
    }

    /**
     * Агрегирует под-результаты discovery в один статус.
     * Приоритет: failed > updated > up_to_date > skipped.
     *
     * @param list<string> $folders
     * @param list<string> $skipped404
     * @param array<string,array<string,mixed>> $subResults
     * @return array<string,mixed>
     */
    private static function aggregateDiscoveryResults(array $folders, array $skipped404, array $subResults): array
    {
        $statuses = array_map(fn($r) => (string)($r['status'] ?? ''), $subResults);

        $aggStatus = 'skipped';
        if (in_array('failed', $statuses, true)) {
            $aggStatus = 'failed';
        } elseif (in_array('updated', $statuses, true)) {
            $aggStatus = 'updated';
        } elseif (in_array('up_to_date', $statuses, true)) {
            $aggStatus = 'up_to_date';
        }

        return [
            'status'      => $aggStatus,
            'message'     => sprintf(
                'discovery: проверено %d, обработано %d, 404 %d',
                count($folders), count($subResults), count($skipped404)
            ),
            'discovered'  => array_keys($subResults),
            'skipped_404' => $skipped404,
            'sub_results' => $subResults,
        ];
    }

    public function localPathFor(IsoEntry $entry): string
    {
        return $this->localPathForName($entry, $entry->localName);
    }

    private function localPathForName(IsoEntry $entry, string $name): string
    {
        return $this->localDir
            . ($entry->localSubdir !== '' ? DIRECTORY_SEPARATOR . $entry->localSubdir : '')
            . DIRECTORY_SEPARATOR . $name;
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
