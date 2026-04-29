<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Контракт для различных бэкендов скачивания (cURL, aria2c).
 * Updater работает через этот интерфейс — выбор реализации делается в update_iso.php.
 */
interface DownloaderInterface
{
    /**
     * Скачивает $url в $destination (атомарность достигается на уровне вызывающего:
     * качаем в *.tmp и rename()).
     *
     * @param string $url
     * @param string $destination абсолютный путь к (.tmp) файлу
     * @param bool   $insecure отключение проверки SSL для конкретного хоста
     * @param ?int   $expectedLastModified для skip_if_unchanged: mtime локального файла
     * @param bool   $checkUnchanged true → если HEAD говорит что remote не изменился, пропустить
     *
     * @return array{success:bool, skipped:bool, expected_size:?int, actual_size:?int, error:?string}
     */
    public function download(
        string $url,
        string $destination,
        bool $insecure = false,
        ?int $expectedLastModified = null,
        bool $checkUnchanged = false
    ): array;
}
