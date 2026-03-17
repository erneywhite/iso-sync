# ISO Sync

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![cURL](https://img.shields.io/badge/Requires-cURL-073551?logo=curl&logoColor=white)](https://curl.se)

Набор PHP-скриптов для автоматической синхронизации ISO-образов с официальных серверов по SHA256-контрольным суммам с веб-интерфейсом просмотра файлов. Задуман для поддержания локального зеркала ISO-образов в актуальном состоянии в автоматическом режиме.

---

## Содержание

- [Функциональность](#функциональность)
- [Структура проекта](#структура-проекта)
- [Поддерживаемые дистрибутивы](#поддерживаемые-дистрибутивы)
- [Требования](#требования)
- [Установка](#установка)
- [Использование](#использование)
- [Логика обновления](#логика-обновления)
- [Конфигурация](#конфигурация)
- [Автоматизация через cron](#автоматизация-через-cron)
- [Нюансы](#нюансы)

---

## Функциональность

- ✅ Проверка локальных ISO по официальным `SHA256SUMS` / `SHA256SUM` / `CHECKSUM` с удалённого сервера
- ✅ Скачивание актуальной версии при несовпадении хэшей
- ✅ Поддержка `latest` — автоопределение актуального имени DVD-образа (CentOS Stream)
- ✅ Флаг `force_download_without_checksum` для файлов без официальных контрольных сумм (Windows, VirtIO)
- ✅ Прогресс-бар загрузки в консоли (50 символов)
- ✅ Защита от зависания: прерывает загрузку если за 60 секунд нет прогресса
- ✅ До 5 попыток с готевой задержкой (экспоненциальная: 5, 10, 15, 20, 25 сек)
- ✅ SHA256-кэширование по `mtime` + `size` — повторный хэш не считается, если файл не менялся
- ✅ Веб-интерфейс: просмотр файлов, размеров, дат и SHA256 с копированием в буфер
- ✅ Автозапуск `generate_all_hashes.php` после обновления

---

## Структура проекта

```
iso-sync/
├── index.php                # Веб-интерфейс: список файлов, размеры, даты, SHA256
├── update_iso.php           # Проверка актуальности и загрузка ISO-образов
├── generate_all_hashes.php  # CLI: вычисление и кэширование SHA256
├── crontab                  # Примеры cron-задач
└── files/                   # Хранилище ISO-образов
    ├── Debian/
    ├── Ubuntu/
    ├── CentOS/
    ├── AlmaLinux/
    ├── Proxmox/
    ├── ArchLinux/
    ├── Windows/
    └── .hash_cache/             # Кэш SHA256-хэшей (автоматически)
```

---

## Поддерживаемые дистрибутивы

| Дистрибутив     | Версии                    | Проверка SHA256 | Источник                          |
|-----------------|---------------------------|--------------|-----------------------------------|
| Debian          | 11, 12, 13                | ✅            | cdimage.debian.org                |
| Ubuntu          | 22.04, 24.04, 25.04       | ✅            | releases.ubuntu.com               |
| CentOS          | 7                         | ✅            | mirror.yandex.ru                  |
| CentOS Stream   | 9, 10                     | ✅ (`latest`) | ftp.byfly.by                      |
| AlmaLinux       | 8, 9, 10                  | ✅            | raw.repo.almalinux.org            |
| Proxmox VE      | 7.4, 8.4, 9.1             | ✅            | enterprise.proxmox.com            |
| Proxmox Backup  | 4.0                       | ✅            | enterprise.proxmox.com            |
| Proxmox Mail    | 7.3                       | ✅            | enterprise.proxmox.com            |
| ArchLinux       | latest                    | ✅            | mirror.yandex.ru                  |
| VirtIO-win      | latest                    | ❌ (без чексум) | fedorapeople.org                  |
| Windows Server  | 2022 ru/en, 2025 ru/en    | ❌ (без чексум) | microsoft.com (evaluation)        |

---

## Требования

- PHP 7.4+ с расширением `cURL`
- Доступ в интернет для скачивания контрольных сумм и образов
- Права на запись в `files/` и `.hash_cache/`
- Достаточно свободного места на диске (каждый DVD-образ — 4–8 ГБ)

Проверить наличие cURL:

```bash
php -r "echo extension_loaded('curl') ? 'OK' : 'NOT FOUND';"
```

---

## Установка

```bash
git clone https://github.com/erneywhite/iso-sync.git
cd iso-sync
mkdir -p files/.hash_cache
chmod -R 755 files/
```

Для веб-интерфейса поместите папку в `DocumentRoot` вашего веб-сервера (Apache/nginx + PHP-FPM).

---

## Использование

### Веб-интерфейс

Откройте `index.php` через браузер. Интерфейс отображает все файлы в `files/` со следующей информацией:
- Название и размер файла
- Дата последнего изменения
- SHA256 с префиксом `sha256:` — клик копирует в буфер обмена

### Вычисление хэшей

```bash
php generate_all_hashes.php
```

Рекурсивно обходит `files/`, вычисляет SHA256 для каждого файла и сохраняет кэш в `.hash_cache/`. Устаревшие записи (для удалённых файлов) автоматически чистятся.

### Проверка и обновление образов

```bash
php update_iso.php
```

Проверяет каждый образ из массива `$filesToUpdate` и при необходимости скачивает актуальную версию. По завершении автоматически запускается `generate_all_hashes.php`.

---

## Логика обновления

Для каждого файла в `$filesToUpdate`:

```
1. Скачать SHA256SUMS / SHA256SUM / CHECKSUM с url_dir
   ├─ Не найдены и force_download_without_checksum=true
   │   └─ Скачать файл без проверки
   └─ Найдены
       ├─ remote_name = 'latest' → автопоиск DVD-файла в списке
       └─ Сравнение хэшей
           ├─ Хэши совпадают → файл актуален, пропуск
           └─ Не совпадают → загрузка в .tmp, после — rename()

2. Загрузка: до 5 попыток, таймаут 60 сек без прогресса
   ├─ Успех → rename(.tmp -> финальный файл)
   └─ Ошибка → удаление .tmp, задержка, повтор

3. Запуск generate_all_hashes.php
```

---

## Конфигурация

### Добавление нового образа

Отредактируйте массив `$filesToUpdate` в `update_iso.php`:

```php
$filesToUpdate = [
    // Обычный файл с проверкой хэша
    'Fedora_41.iso' => [
        'local_subdir' => 'Fedora',
        'url_dir'      => 'https://download.fedoraproject.org/pub/fedora/linux/releases/41/Server/x86_64/iso/',
        'remote_name'  => 'Fedora-Server-dvd-x86_64-41-1.4.iso',
    ],

    // Файл без официальных чексум
    'SomeISO.iso' => [
        'local_subdir'                 => 'Other',
        'url_dir'                      => 'https://example.com/isos/',
        'remote_name'                  => 'some.iso',
        'force_download_without_checksum' => true,
    ],

    // Автоопределение актуального имени (подходит для роллинг-релизов)
    'CentOS_Stream_9.iso' => [
        'local_subdir' => 'CentOS',
        'url_dir'      => 'https://ftp.byfly.by/pub/centos-stream/9-stream/BaseOS/x86_64/iso/',
        'remote_name'  => 'latest',  // автопоиск DVD-файла
    ],
];
```

### Настройка параметров загрузки

В функции `downloadFile()` отредактируйте переменные:

```php
$maxStallSeconds = 60; // секунд без прогресса — зависание
$maxRetries      = 5;  // максимум попыток
```

---

## Автоматизация через cron

Добавьте содержимое файла `crontab` в расписание (`crontab -e`), заменив `/path/to/` на реальный путь:

```cron
# Пересчёт хэшей каждый час
0 * * * *  php /path/to/generate_all_hashes.php > /dev/null 2>&1

# Обновление ISO ежедневно в полночь
# (внутри автозапускается generate_all_hashes)
0 0 * * *  php /path/to/update_iso.php > /dev/null 2>&1

# Вариант: обновление раз в неделю (суббота)
0 0 * * 6  php /path/to/update_iso.php > /dev/null 2>&1
```

> ⚠️ Если `update_iso.php` уже запускает `generate_all_hashes.php` в конце — отдельный cron для `generate_all_hashes.php` избыточен. Используйте один из двух вариантов.

---

## Нюансы

- **SSL-проверка отключена** в `downloadFile()` (`CURLOPT_SSL_VERIFYPEER=false`) — необходимо для некоторых зеркал. Если важна безопасность — включите её обратно.
- **Кэш SHA256** хранится по `md5($localPath)` в `.hash_cache/`. Если переместить файл — кэш не сработает и SHA256 будет пересчитан.
- **Атомарность загрузки**: файл скачивается в `*.tmp` и переименовывается после успеха — обрыв не повреждает существующий файл.
- **`latest` ищет `dvd`** в именах файлов из SHA256SUMS — подходит для CentOS Stream, где имя образа меняется каждый релиз. Для других дистрибутивов указывайте точное имя.
- **Windows Server**: ссылки на evaluation-образы Microsoft могут устаревать — при необходимости обновляйте `url_dir` вручную.

---

## Лицензия

[MIT License](LICENSE)
