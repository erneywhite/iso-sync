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
     * @param ?array{mtime:int,size:int} $localFileInfo инфо о существующем локальном файле,
     *                                  используется для skip_if_unchanged: HEAD-сравниваем
     *                                  Content-Length с size и Last-Modified с mtime.
     *                                  Передавай null если локального файла ещё нет.
     * @param bool   $checkUnchanged true → если HEAD говорит что remote не изменился
     *                              (size совпал И Last-Modified ≤ local mtime), пропустить
     *
     * @return array{success:bool, skipped:bool, expected_size:?int, actual_size:?int, error:?string}
     */
    public function download(
        string $url,
        string $destination,
        bool $insecure = false,
        ?array $localFileInfo = null,
        bool $checkUnchanged = false
    ): array;
}
