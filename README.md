# ISO Sync

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![cURL](https://img.shields.io/badge/Requires-cURL-073551?logo=curl&logoColor=white)](https://curl.se)

Набор PHP-скриптов для автоматической синхронизации ISO-образов с официальных серверов по SHA256-контрольным суммам с веб-интерфейсом просмотра файлов. Задуман для поддержания локального зеркала ISO-образов в актуальном состоянии в автоматическом режиме.

---

## Содержание

- [Возможности](#возможности)
- [Структура проекта](#структура-проекта)
- [Поддерживаемые дистрибутивы](#поддерживаемые-дистрибутивы)
- [Требования](#требования)
- [Установка](#установка)
- [Использование](#использование)
- [Конфигурация](#конфигурация)
- [Логика обновления](#логика-обновления)
- [Логирование и статус](#логирование-и-статус)
- [Безопасность](#безопасность)
- [Автоматизация через cron](#автоматизация-через-cron)
- [Тесты](#тесты)
- [Нюансы](#нюансы)

---

## Возможности

- Проверка локальных ISO по официальным `SHA256SUMS` / `SHA256SUM` / `CHECKSUM` с удалённого сервера
- Опциональная **проверка GPG-подписи** на `SHA256SUMS` (`SHA256SUMS.gpg` / `.sign` / `.asc`)
- **SSL-проверка по умолчанию включена**, отключение точечно через `insecure_ssl: true` в конфиге
- Скачивание актуальной версии при несовпадении хэшей
- **Проверка `Content-Length`** — после загрузки сравнивает фактический размер с заголовком HEAD
- **Пере-проверка хэша после загрузки** — даже если зеркало вернуло «успех», файл проверяется ещё раз
- Поддержка `latest` — автоопределение актуального имени с **версионной сортировкой** (strnatcasecmp)
- Гибкий шаблон поиска `latest_pattern` для каждой записи
- Флаг `force_download_without_checksum` для файлов без официальных контрольных сумм (VirtIO и др.)
- Флаг `skip_if_unchanged` — для force-загрузок: пропускать, если remote не изменился по `Last-Modified`
- Прогресс-бар: TTY с `\r`, в cron-логе — компактный вывод по 10% или раз в 30 сек
- Защита от зависания: прерывает загрузку если за 60 секунд нет прогресса
- До 5 попыток с задержкой (5, 10, 15, 20, 25 сек)
- SHA256-кэширование по `mtime + size` — повторный хэш не считается, если файл не менялся
- **`flock`** на оба скрипта — повторный запуск из cron не наслаивается на текущий
- **Структурированные JSON-логи** в `logs/update.log` и `logs/hashes.log`
- **Сводка последнего прогона** в `logs/last_run.json` — отображается в веб-интерфейсе
- Веб-интерфейс: список файлов, размеры, даты, кликабельные SHA256, **отсутствующие файлы**, **полоса статуса**

---

## Структура проекта

```
iso-sync/
├── config/
│   ├── iso-list.json         # Список ISO для синхронизации
│   └── iso-list.schema.json  # JSON Schema для валидации/автодополнения
├── lib/                      # Общая библиотека (PHP 8.1+, namespace IsoSync)
│   ├── bootstrap.php
│   ├── Logger.php
│   ├── Config.php
│   ├── ChecksumParser.php
│   ├── HashCache.php
│   ├── Lock.php
│   ├── Http.php
│   ├── Downloader.php
│   ├── GpgVerifier.php
│   └── Updater.php
├── tests/                    # Минимальный test-runner без composer/PHPUnit
│   ├── run.php
│   ├── TestRunner.php
│   ├── ChecksumParserTest.php
│   └── HashCacheTest.php
├── logs/                     # Структурированные логи + last_run.json (gitignored)
├── files/                    # Хранилище ISO-образов (gitignored)
│   ├── Debian/
│   ├── Ubuntu/
│   └── ...
├── .hash_cache/              # Кэш SHA256 (gitignored, создаётся автоматически)
├── index.php                 # Веб-интерфейс
├── update_iso.php            # CLI: проверка и загрузка
├── generate_all_hashes.php   # CLI: пересчёт SHA256 + чистка кэша
├── crontab                   # Примеры cron-задач
├── favicon.ico
├── LICENSE
└── README.md
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

---

## Требования

- **PHP 8.1+** с расширением `cURL` (тестировалось на 8.4)
- Доступ в интернет для скачивания контрольных сумм и образов
- Права на запись в `files/`, `.hash_cache/`, `logs/`
- Достаточно свободного места на диске (каждый DVD-образ — 4–8 ГБ)
- *(Опционально)* установленный `gpg` в PATH — только если включена проверка подписи

Проверить наличие cURL:

```bash
php -r "echo extension_loaded('curl') ? 'OK' : 'NOT FOUND';"
```

---

## Установка

```bash
git clone https://github.com/erneywhite/iso-sync.git
cd iso-sync
mkdir -p files logs .hash_cache
chmod -R 755 files logs
```

Для веб-интерфейса поместите папку в `DocumentRoot` вашего веб-сервера (Apache/nginx + PHP-FPM).

---

## Использование

### Веб-интерфейс

Откройте `index.php` через браузер. Интерфейс отображает:
- **Полосу статуса** сверху — таймстемп последнего прогона `update_iso.php`, счётчики `обновлено / актуально / пропущено / ошибки`
- Список файлов в `files/` с именем, размером, датой и SHA256
- **Блок «Отсутствующие файлы»** — записи из конфига, которых нет на диске (с указанием ожидаемого URL)

Клик по хэшу копирует его в буфер. Через меню «Копировать» можно получить прямую ссылку или готовую `wget`-команду.

### Вычисление хэшей

```bash
php generate_all_hashes.php
```

Рекурсивно обходит `files/`, вычисляет SHA256 для каждого файла и сохраняет кэш в `.hash_cache/`. Устаревшие записи (для удалённых файлов) автоматически чистятся. Защищён `flock`.

### Проверка и обновление образов

```bash
php update_iso.php
```

Проверяет каждую запись из `config/iso-list.json` и при необходимости скачивает актуальную версию. По завершении автоматически запускается `generate_all_hashes.php`. Защищён `flock` — повторный запуск (из cron) тихо завершится с кодом 0, если предыдущий ещё работает.

Код возврата:
- `0` — все файлы актуальны или успешно обновлены
- `1` — были ошибки (детали в `logs/update.log` и `logs/last_run.json`)
- `2` — фатальная ошибка (битый конфиг, нет доступа к каталогам и т.п.)

---

## Конфигурация

Список образов хранится в [`config/iso-list.json`](config/iso-list.json). Структура:

```jsonc
{
    "files": {
        "Debian_12.iso": {
            "local_subdir": "Debian",
            "url_dir":      "https://cdimage.debian.org/cdimage/archive/12.12.0/amd64/iso-dvd/",
            "remote_name":  "debian-12.12.0-amd64-DVD-1.iso"
        },

        "CentOS_9.iso": {
            "local_subdir":   "CentOS",
            "url_dir":        "https://ftp.byfly.by/pub/centos-stream/9-stream/BaseOS/x86_64/iso/",
            "remote_name":    "latest",
            "latest_pattern": "/dvd/i"
        },

        "QEMU_virtio-win-latest.iso": {
            "local_subdir":                    "Windows",
            "url_dir":                         "https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/",
            "remote_name":                     "virtio-win.iso",
            "force_download_without_checksum": true,
            "skip_if_unchanged":               true
        },

        "SomeISO.iso": {
            "local_subdir": "Debian",
            "url_dir":      "https://example.com/iso/",
            "remote_name":  "some.iso",
            "insecure_ssl": true,
            "gpg": {
                "signature_url":   "https://example.com/iso/SHA256SUMS.gpg",
                "key_fingerprint": "AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555"
            }
        }
    }
}
```

### Поля

| Поле | Тип | По умолчанию | Описание |
|------|-----|-------------|----------|
| `local_subdir` | string | `""` | Подпапка внутри `files/` |
| `url_dir` | string | — | Базовый URL директории (со слешем в конце) |
| `remote_name` | string | — | Имя файла на удалённом сервере, либо `latest` |
| `latest_pattern` | string (regex) | `"/dvd/i"` | PCRE-шаблон поиска при `remote_name: "latest"`; среди совпадений выбирается версионно-старший |
| `checksum_files` | string[] | `["SHA256SUMS","SHA256SUM","sha256sum.txt","sha256sums.txt","CHECKSUM"]` | Имена чексумм-файлов для пробы |
| `force_download_without_checksum` | bool | `false` | Качать, даже если SHA256SUMS не найден или нет записи об этом файле |
| `skip_if_unchanged` | bool | `false` | Для force-загрузок: пропустить, если HEAD говорит, что remote не менялся (по `Last-Modified`) |
| `insecure_ssl` | bool | `false` | Отключить проверку SSL для конкретного зеркала (точечный опт-аут) |
| `gpg.signature_url` | string | — | URL подписи для `SHA256SUMS` |
| `gpg.key_fingerprint` | string | — | Ожидаемый fingerprint ключа (40 hex). Сам ключ должен быть импортирован в `gpg` |

См. также [`config/iso-list.schema.json`](config/iso-list.schema.json) — JSON Schema для валидации и автодополнения в IDE.

---

## Логика обновления

Для каждой записи из конфига:

```
1. Скачать SHA256SUMS / SHA256SUM / CHECKSUM (по списку checksum_files)
   ├─ Не найдены и force_download_without_checksum=true
   │   └─ Загрузка без проверки хэша
   └─ Найдены
       ├─ (опционально) проверить GPG-подпись SHA256SUMS
       ├─ remote_name = 'latest' → найти кандидатов по latest_pattern,
       │                            отсортировать через strnatcasecmp, взять старший
       └─ Сравнение хэшей
           ├─ Совпадают → файл актуален, пропуск
           └─ Не совпадают → загрузка в .tmp

2. Загрузка:
   - HEAD-запрос → ожидаемый Content-Length и Last-Modified
   - При skip_if_unchanged=true и неизменном remote — пропуск
   - До 5 попыток, прерывание при 60 сек без прогресса
   - После загрузки: проверка фактического размера == Content-Length
   - Если был ожидаемый хэш — пере-проверка SHA256 файла .tmp
   - rename(.tmp, финальный файл) — атомарно
   - Кэш SHA256 обновляется сразу

3. Запуск generate_all_hashes.php — пересчёт по всем файлам и чистка осиротевшего кэша

4. Запись logs/last_run.json со сводкой
```

---

## Логирование и статус

### Структурированные логи (JSON Lines)

`logs/update.log` и `logs/hashes.log` пишутся как JSON-объекты по одной записи на строку:

```json
{"ts":"2026-04-29T18:30:11+00:00","level":"info","message":"Файл обновлён: Debian_12.iso","event":"file_updated","file":"Debian_12.iso","actual_size":4290772992}
```

Удобно парсить из `jq`, отдавать в Loki/Elastic или просто грепать.

### Сводка последнего прогона

`logs/last_run.json` обновляется в конце каждого запуска `update_iso.php`:

```json
{
    "started_at": "2026-04-29T03:00:00+00:00",
    "finished_at": "2026-04-29T03:42:18+00:00",
    "duration_s": 2538,
    "total": 19,
    "updated": 2,
    "up_to_date": 16,
    "skipped": 1,
    "failed": 0,
    "results": { "...": "..." }
}
```

Веб-интерфейс читает этот файл и показывает информацию в полосе статуса.

---

## Безопасность

### SSL

Сертификаты проверяются по умолчанию (`CURLOPT_SSL_VERIFYPEER = true`). Если конкретное зеркало отдаёт битый/самоподписанный сертификат, добавьте `"insecure_ssl": true` для этой записи в конфиге — это локальный опт-аут, остальные записи продолжают проверять SSL строго.

### GPG (опционально)

Подпись на `SHA256SUMS` нивелирует риск подмены контрольных сумм MITM-атакой. Чтобы включить:

1. Установите `gpg` (обычно уже есть на серверах: `apt install gnupg`)
2. Импортируйте ключ подписанта:
   ```bash
   # пример для Debian
   gpg --keyserver keyserver.ubuntu.com --recv-keys DF9B9C49EAA9298432589D76DA87E80D6294BE9B
   ```
3. Добавьте в запись конфига блок `gpg`:
   ```json
   "gpg": {
       "signature_url":   "https://cdimage.debian.org/.../SHA256SUMS.sign",
       "key_fingerprint": "DF9B9C49EAA9298432589D76DA87E80D6294BE9B"
   }
   ```

При несовпадении подписи запись помечается `failed`, файл не скачивается.

---

## Автоматизация через cron

Добавьте содержимое файла `crontab` в расписание (`crontab -e`), заменив `/path/to/` на реальный путь:

```cron
# Пересчёт хэшей каждый час
0 * * * *  php /path/to/generate_all_hashes.php > /dev/null 2>&1

# Обновление ISO ежедневно в полночь
# (внутри автозапускается generate_all_hashes)
0 0 * * *  php /path/to/update_iso.php > /dev/null 2>&1
```

> Скрипты защищены `flock`, поэтому пересечение запусков безопасно — второй экземпляр тихо выйдет с кодом 0.
> 
> При запуске не из tty прогресс-бар автоматически переключается в компактный режим (логи не засоряются `\r`).

---

## Тесты

Минимальный test-runner без зависимостей:

```bash
php tests/run.php
```

Покрытие: парсер `SHA256SUMS` (формат GNU/BSD/CRLF/комментарии/пробелы) и кэш SHA256 (запись/чтение/инвалидация/чистка). Расширить можно, добавив `tests/SomethingTest.php` — runner подхватит автоматически.

---

## Нюансы

- **Кэш SHA256** хранится по `md5($localPath)` в `.hash_cache/`. Если переместить файл — кэш не сработает и SHA256 будет пересчитан (а старая запись удалится при очистке осиротевших).
- **Атомарность загрузки**: файл скачивается в `*.tmp` и переименовывается после успеха — обрыв не повреждает существующий файл.
- **Дефолтный `latest_pattern`** — `/dvd/i`. Подходит для CentOS Stream и образов, в имени которых есть «dvd». Для других схем переопределите в записи конфига.
- **Кэш формата**: единый `{ "hash": "sha256:<hex>", "mtime": ..., "size": ... }`. Старые записи без префикса `sha256:` корректно нормализуются при чтении.
- **`generate_all_hashes.php` сейчас идёт в один поток.** Хэширование 8 ГБ-файла занимает ~15–30 секунд CPU — для регулярного крона это нормально.

---

## Лицензия

[MIT License](LICENSE)
