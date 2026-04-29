<?php

// Массив соответствия локальных файлов
$filesToUpdate = [
    'Debian_11.iso' => [
        'local_subdir' => 'Debian',
        'url_dir'     => 'https://cdimage.debian.org/cdimage/archive/11.11.0/amd64/iso-dvd/',
        'remote_name' => 'debian-11.11.0-amd64-DVD-1.iso',
    ],
    'Debian_12.iso' => [
        'local_subdir' => 'Debian',
        'url_dir'     => 'https://cdimage.debian.org/cdimage/archive/12.12.0/amd64/iso-dvd/',
        'remote_name' => 'debian-12.12.0-amd64-DVD-1.iso',
    ],
    'Debian_13.iso' => [
        'local_subdir' => 'Debian',
        'url_dir'     => 'https://cdimage.debian.org/cdimage/archive/13.1.0/amd64/iso-dvd/',
        'remote_name' => 'debian-13.1.0-amd64-DVD-1.iso',
    ],
    'Ubuntu_22.04.iso' => [
        'local_subdir' => 'Ubuntu',
        'url_dir'     => 'https://releases.ubuntu.com/22.04/',
        'remote_name' => 'ubuntu-22.04.5-live-server-amd64.iso',
    ],
    'Ubuntu_24.04.iso' => [
        'local_subdir' => 'Ubuntu',
        'url_dir'     => 'https://releases.ubuntu.com/24.04/',
        'remote_name' => 'ubuntu-24.04.3-live-server-amd64.iso',
    ],
    'Ubuntu_25.04.iso' => [
        'local_subdir' => 'Ubuntu',
        'url_dir'     => 'https://releases.ubuntu.com/25.04/',
        'remote_name' => 'ubuntu-25.04-live-server-amd64.iso',
    ],
    'CentOS_7.iso' => [
        'local_subdir' => 'CentOS',
        'url_dir'     => 'https://mirror.yandex.ru/centos/centos/7/isos/x86_64/',
        'remote_name' => 'CentOS-7-x86_64-DVD-2207-02.iso',
    ],
    'CentOS_9.iso' => [
        'local_subdir' => 'CentOS',
        'url_dir'     => 'https://ftp.byfly.by/pub/centos-stream/9-stream/BaseOS/x86_64/iso/',
        'remote_name' => 'latest',
    ],
    'CentOS_10.iso' => [
        'local_subdir' => 'CentOS',
        'url_dir'     => 'https://ftp.byfly.by/pub/centos-stream/10-stream/BaseOS/x86_64/iso/',
        'remote_name' => 'latest',
    ],
    'AlmaLinux_8.iso' => [
        'local_subdir' => 'AlmaLinux',
        'url_dir'     => 'https://raw.repo.almalinux.org/almalinux/8/isos/x86_64/',
        'remote_name' => 'AlmaLinux-8-latest-x86_64-dvd.iso',
    ],
    'AlmaLinux_9.iso' => [
        'local_subdir' => 'AlmaLinux',
        'url_dir'     => 'https://raw.repo.almalinux.org/almalinux/9/isos/x86_64/',
        'remote_name' => 'AlmaLinux-9-latest-x86_64-dvd.iso',
    ],
    'AlmaLinux_10.0.iso' => [
        'local_subdir' => 'AlmaLinux',
        'url_dir'     => 'https://raw.repo.almalinux.org/almalinux/10/isos/x86_64/',
        'remote_name' => 'AlmaLinux-10-latest-x86_64-dvd.iso',
    ],
    'ProxmoxVE_7.4.iso' => [
        'local_subdir' => 'Proxmox',
        'url_dir'     => 'https://enterprise.proxmox.com/iso/',
        'remote_name' => 'proxmox-ve_7.4-1.iso',
    ],
    'ProxmoxVE_8.4.iso' => [
        'local_subdir' => 'Proxmox',
        'url_dir'     => 'https://enterprise.proxmox.com/iso/',
        'remote_name' => 'proxmox-ve_8.4-1.iso',
    ],
    'ProxmoxVE_9.1.iso' => [
        'local_subdir' => 'Proxmox',
        'url_dir'     => 'https://enterprise.proxmox.com/iso/',
        'remote_name' => 'proxmox-ve_9.1-1.iso',
    ],
    'Proxmox_BackUP_4.0.iso' => [
        'local_subdir' => 'Proxmox',
        'url_dir'     => 'https://enterprise.proxmox.com/iso/',
        'remote_name' => 'proxmox-backup-server_4.0-1.iso',
    ],
    'Proxmox_MailGateway_7.3.iso' => [
        'local_subdir' => 'Proxmox',
        'url_dir'     => 'https://enterprise.proxmox.com/iso/',
        'remote_name' => 'proxmox-mailgateway_7.3-1.iso',
    ],
    'QEMU_virtio-win-latest.iso' => [
        'local_subdir' => 'Windows',
        'url_dir'     => 'https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/',
        'remote_name' => 'virtio-win.iso',
        'force_download_without_checksum' => true,
    ],
    'ArchLinux.iso' => [
        'local_subdir' => 'ArchLinux',
        'url_dir'     => 'https://mirror.yandex.ru/archlinux/iso/latest/',
        'remote_name' => 'archlinux-x86_64.iso',
    ],
];

$localDir = __DIR__ . DIRECTORY_SEPARATOR . 'files';
$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '.hash_cache';

/**
 * Функция скачивания файла с визуальным прогресс-баром
 * + защита от зависаний (нет прогресса N секунд)
 * + несколько попыток скачивания
 */
function downloadFile(string $url, string $destination): bool
{
    $maxStallSeconds = 60; // сколько секунд допускается без прогресса
    $maxRetries      = 5;  // сколько раз пробуем скачать файл заново

    $attempt = 0;

    while ($attempt < $maxRetries) {
        $attempt++;
        echo "Попытка {$attempt} загрузки: {$url}\n";

        $fp = fopen($destination, 'w+');
        if ($fp === false) {
            echo "Не удалось открыть файл для записи: {$destination}\n";
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            echo "Не удалось инициализировать cURL\n";
            return false;
        }

        // >>> игнорировать ошибки SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // НЕ проверять сертификат
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);     // НЕ проверять имя хоста
        // <<<
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);

        $lastDownloaded = 0;
        $lastChangeTime = time();

        curl_setopt(
            $ch,
            CURLOPT_PROGRESSFUNCTION,
            function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded)
            use (&$lastDownloaded, &$lastChangeTime, $maxStallSeconds)
            {
                if ($downloadSize > 0) {
                    $percent = ($downloaded / $downloadSize) * 100;
                    $filledBars = round($percent / 2); // 50 символов в полосе
                    $emptyBars = 50 - $filledBars;
                    $bar = str_repeat('=', $filledBars) . str_repeat(' ', $emptyBars);
                    printf("\rСкачивание: %3d%% [%s]", round($percent), $bar);
                }

                // отслеживаем прогресс
                if ($downloaded > $lastDownloaded) {
                    $lastDownloaded = $downloaded;
                    $lastChangeTime = time();
                } else {
                    // если за maxStallSeconds не прибавилось ни байта — считаем, что зависло
                    if ((time() - $lastChangeTime) >= $maxStallSeconds) {
                        echo "\nОбнаружено зависание скачивания (нет прогресса {$maxStallSeconds} секунд), прерываем.\n";
                        // Возвращаем ненулевое значение -> cURL прерывает запрос с ошибкой
                        return 1;
                    }
                }

                return 0; // 0 — продолжать
            }
        );

        $result    = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr   = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        echo "\n";

        if ($result && $curlErrNo === 0) {
            echo "Скачивание завершено успешно.\n";
            return true;
        }

        echo "Ошибка скачивания (cURL #{$curlErrNo}): {$curlErr}\n";

        // удаляем повреждённый файл, чтобы при следующей попытке начать заново
        if (file_exists($destination)) {
            unlink($destination);
        }

        if ($attempt < $maxRetries) {
            $sleep = 5 * $attempt; // простая экспоненциальная задержка
            echo "Повторная попытка через {$sleep} сек...\n";
            sleep($sleep);
        }
    }

    echo "Все {$maxRetries} попыток скачивания исчерпаны.\n";
    return false;
}

/**
 * Универсальный парсер SHA256SUMS/SHA256SUM
 */
function parseChecksumContent(string $content): array
{
    $hashes = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^([a-f0-9]{64})\s+\*?(\S+)$/i', $line, $matches)) {
            $hashes[$matches[2]] = $matches[1];
        } elseif (preg_match('/^SHA256\s+\((.+?)\)\s+=\s+([a-f0-9]{64})$/i', $line, $matches)) {
            $hashes[$matches[1]] = $matches[2];
        }
    }

    return $hashes;
}

/**
 * Получение локального хэша с кэшированием
 */
function getLocalFileHashCached(string $localPath, string $cacheDir)
{
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . md5($localPath) . '.cache';

    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        $fileMTime = filemtime($localPath);
        $fileSize  = filesize($localPath);

        if ($cacheData && $cacheData['mtime'] === $fileMTime && $cacheData['size'] === $fileSize) {
            return $cacheData['hash'];
        }
    }

    if (!file_exists($localPath) || !is_readable($localPath)) {
        return false;
    }

    $hash = hash_file('sha256', $localPath);
    $cacheDataToSave = [
        'hash'  => $hash,
        'mtime' => filemtime($localPath),
        'size'  => filesize($localPath),
    ];

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    file_put_contents($cacheFile, json_encode($cacheDataToSave));
    return $hash;
}

foreach ($filesToUpdate as $localName => $info) {

    echo "Обрабатываем файл: {$localName}\n";

    $localSubdir = $info['local_subdir'] ?? '';
    $urlDir      = rtrim($info['url_dir'], '/') . '/';
    $remoteName  = $info['remote_name'];

    $forceDownloadWithoutChecksum = !empty($info['force_download_without_checksum']);

    $localPath = $localDir
        . ($localSubdir !== '' ? DIRECTORY_SEPARATOR . $localSubdir : '')
        . DIRECTORY_SEPARATOR . $localName;

    echo "Локальный файл: {$localPath}\n";

    if (!file_exists($localPath)) {
        echo "Файл отсутствует локально — будет загружен\n";
    } elseif (!is_readable($localPath)) {
        echo "Ошибка: файл существует, но недоступен для чтения\n\n";
        continue;
    }

    // URL-ы с чексуммами
    $shaUrlsToTry = [
        $urlDir . 'SHA256SUMS',
        $urlDir . 'SHA256SUM',
        $urlDir . 'sha256sum.txt',
        $urlDir . 'sha256sums.txt',
        $urlDir . 'CHECKSUM',
    ];

    // подготовим контекст для HTTPS
    $sslContext = stream_context_create([
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $shaContent = false;
    foreach ($shaUrlsToTry as $tryUrl) {
        echo "Пытаемся скачать контрольные суммы: {$tryUrl}\n";

        if (stripos($tryUrl, 'https://') === 0) {
            $shaContent = @file_get_contents($tryUrl, false, $sslContext);
        } else {
            $shaContent = @file_get_contents($tryUrl);
        }

        if ($shaContent !== false) {
            break;
        }
    }

    // === НЕТ ФАЙЛА С ЧЕКСУММАМИ ===
    if ($shaContent === false) {
        if ($forceDownloadWithoutChecksum) {
            echo "Контрольные суммы недоступны, но для {$localName} разрешено скачивание без проверки.\n";

            $fileUrl = $urlDir . $remoteName;
            $tmpFile = $localPath . '.tmp';

            if (!is_dir(dirname($localPath))) {
                mkdir(dirname($localPath), 0755, true);
            }

            if (downloadFile($fileUrl, $tmpFile)) {
                rename($tmpFile, $localPath);
                echo "Файл загружен без проверки хэша: {$localName}\n\n";
            } else {
                echo "Ошибка скачивания: {$fileUrl}\n\n";
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }

            // переходим к следующему элементу
            continue;
        }

        echo "Не удалось скачать ни SHA256SUMS, ни SHA256SUM с {$urlDir}\n\n";
        continue;
    }

    $remoteHashes = parseChecksumContent($shaContent);

    // обработка remote_name = 'latest'
    if ($remoteName === 'latest' || $remoteName === '') {
        $matchedName = null;
        foreach ($remoteHashes as $fileName => $fileHash) {
            if (stripos($fileName, 'dvd') !== false) {
                $matchedName = $fileName;
                break;
            }
        }
        if ($matchedName === null) {
            echo "Не найден файл с 'dvd' в имени для загрузки\n";
            continue;
        }
        $remoteName = $matchedName;
    }

    // === ЕСТЬ ФАЙЛ С ЧЕКСУММАМИ, НО НЕТ ЗАПИСИ ДЛЯ КОНКРЕТНОГО ФАЙЛА ===
    if (!isset($remoteHashes[$remoteName])) {
        if ($forceDownloadWithoutChecksum) {
            echo "Нет контрольной суммы для {$remoteName}, но разрешено скачивание без проверки.\n";

            $fileUrl = $urlDir . $remoteName;
            $tmpFile = $localPath . '.tmp';

            if (!is_dir(dirname($localPath))) {
                mkdir(dirname($localPath), 0755, true);
            }

            if (downloadFile($fileUrl, $tmpFile)) {
                rename($tmpFile, $localPath);
                echo "Файл загружен без проверки хэша: {$localName}\n\n";
            } else {
                echo "Ошибка скачивания: {$fileUrl}\n\n";
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }

            continue;
        }

        echo "Нет контрольной суммы для файла {$remoteName} в контрольных суммах\n\n";
        continue;
    }

    // === Обычная логика сравнения хэшей ===
    $remoteHash = $remoteHashes[$remoteName];
    $localHash  = getLocalFileHashCached($localPath, $cacheDir);

    $localHashForCompare = $localHash !== false && strpos($localHash, 'sha256:') === 0
        ? substr($localHash, strlen('sha256:'))
        : $localHash;

    echo "Локальный хэш: " . ($localHash !== false ? $localHash : 'отсутствует') . "\n";
    echo "Удаленный хэш: {$remoteHash}\n";

    if ($localHashForCompare === $remoteHash) {
        echo "Файл актуален, обновление не требуется.\n\n";
        continue;
    }

    echo "Файл устарел или отсутствует, скачиваем обновленную версию...\n";

    $fileUrl = $urlDir . $remoteName;
    $tmpFile = $localPath . '.tmp';

    if (!is_dir(dirname($localPath))) {
        mkdir(dirname($localPath), 0755, true);
    }

    if (downloadFile($fileUrl, $tmpFile)) {
        rename($tmpFile, $localPath);
        echo "Файл обновлен: {$localName}\n\n";
    } else {
        echo "Ошибка скачивания: {$fileUrl}\n\n";
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
}

echo "Обновление файлов завершено.\n";

// Запускаем генерацию хэшей
require __DIR__ . '/generate_all_hashes.php';

?>
