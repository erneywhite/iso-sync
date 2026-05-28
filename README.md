<div align="center">

<img src="favicon.ico" alt="ISO Sync" width="72" height="72" />

# ISO Sync

**Локальное зеркало ISO-образов с автообновлением и веб-интерфейсом**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![cURL](https://img.shields.io/badge/Requires-cURL-073551?logo=curl&logoColor=white)](https://curl.se)
[![aria2c](https://img.shields.io/badge/Optional-aria2c-orange)](https://aria2.github.io/)

[Возможности](#возможности) · [Быстрый старт](#быстрый-старт) · [Конфигурация](#конфигурация) · [Веб-интерфейс](#веб-интерфейс) · [Демо ↗](https://iso.erney.monster)

</div>

---

PHP-скрипты для поддержания локального зеркала ISO-образов в актуальном состоянии. Качают свежие релизы Debian, Ubuntu, CentOS, AlmaLinux, Proxmox, ArchLinux, VirtIO-win с официальных серверов; сверяют SHA256 (с GPG-подписями где доступны), при наличии `aria2c` разгоняются через multi-stream-загрузку, держат структурированные логи и сводку прогона — всё это с современным веб-интерфейсом просмотра.

> [!TIP]
> **Живое демо:** [iso.erney.monster](https://iso.erney.monster) — публичное зеркало автора, обновляется по cron.

---

## Содержание

- [Возможности](#возможности)
- [Быстрый старт](#быстрый-старт)
- [Поддерживаемые дистрибутивы](#поддерживаемые-дистрибутивы)
- [Структура проекта](#структура-проекта)
- [Требования](#требования)
- [Установка](#установка)
- [Использование](#использование)
- [Веб-интерфейс](#веб-интерфейс)
- [Конфигурация](#конфигурация)
- [Логика обновления](#логика-обновления)
- [Скорость загрузки](#скорость-загрузки)
- [Безопасность](#безопасность)
- [Логирование и статус](#логирование-и-статус)
- [Автоматизация через cron](#автоматизация-через-cron)
- [Тесты](#тесты)
- [Нюансы](#нюансы)

---

## Возможности

### Синхронизация
- Сверка SHA256 с официальными `SHA256SUMS` / `SHA256SUM` / `CHECKSUM`
- Опциональная **GPG-верификация** подписей (`SHA256SUMS.gpg` / `.sign` / `.asc`)
- Пере-проверка хэша **после** загрузки и сверка `Content-Length`
- Четыре режима выбора версии: `fixed` / `latest` / `family` / `discovery` — от точного имени файла до автоподхвата будущих релизов Ubuntu по диапазону папок
- Атомарная подмена через `*.tmp` + `rename()`, resume по HTTP Range

### Скорость и надёжность
- Автоматическое использование **`aria2c`** (multi-stream, **x2-x4** ускорение) при наличии в `PATH`, иначе тюнингованный cURL-fallback
- До 5 попыток с прогрессивной задержкой, прерывание при 60 сек без прогресса
- **`flock`** на оба скрипта — повторный запуск из cron не наслаивается
- Per-entry `ip_version: v4|v6|any` для обхода IPv4-блоков (см. кейс Proxmox в [Конфигурации](#режим-3-family--динамическое-имя-по-шаблону--cleanup-старых))

### Безопасность по умолчанию
- SSL-проверка включена; отключение точечно через `insecure_ssl: true` для конкретного зеркала
- GPG-блоки готовы для Debian/Ubuntu — нужен только импорт ключей в системный keyring

### Веб-интерфейс
- **Bento-статус** из 3 карточек со sparkline-графиком роста хранилища и дельтами 7д/30д
- Поиск по имени файла **или SHA256-хэшу** (Ctrl+K)
- **Latest-бейджи по семействам** (Proxmox VE 9, Backup 4, Mail 7 — каждый со своим бейджем), индикатор свежести у даты, бренд-цвета дистрибутивов
- Таймлайн «История обновлений», иллюстрированный empty-state
- Все анимации уважают `prefers-reduced-motion`

### Эксплуатация
- Структурированные **JSON-логи** в `logs/update.log` и `logs/hashes.log` — удобно парсить из `jq`, отдавать в Loki/Elastic
- **Сводка последнего прогона** в `logs/last_run.json` — читается веб-интерфейсом
- SHA256-кэширование по `mtime + size` — повторный хэш не считается, если файл не менялся

---

## Быстрый старт

```bash
git clone https://github.com/erneywhite/iso-sync.git
cd iso-sync
mkdir -p files logs .hash_cache && chmod -R 755 files logs

# (рекомендуется — multi-stream загрузка, x2-x4 быстрее)
sudo apt install aria2

# Первый прогон
php update_iso.php
```

Для веб-интерфейса — поместите папку в `DocumentRoot` веб-сервера (Apache / nginx + PHP-FPM), откройте `index.php`. Cron-задания — см. [Автоматизация через cron](#автоматизация-через-cron).

> [!IMPORTANT]
> Для Debian и Ubuntu в дефолтной конфигурации включена GPG-проверка. Без импорта ключей в системный keyring загрузка этих образов будет падать с `GPG verify FAILED`. См. [Безопасность → GPG-верификация](#gpg-верификация-sha256sums) или временно удалите блоки `gpg` из `config/iso-list.json`.

---

## Поддерживаемые дистрибутивы

| Дистрибутив     | Версии                  | Проверка SHA256       | Источник                  |
|-----------------|-------------------------|-----------------------|---------------------------|
| Debian          | 11, 12, 13              | ✅ + GPG              | cdimage.debian.org        |
| Ubuntu          | все `.04` (discovery)   | ✅ + GPG              | releases.ubuntu.com       |
| CentOS          | 7                       | ✅                    | mirror.yandex.ru          |
| CentOS Stream   | 9, 10                   | ✅ (`latest`)         | ftp.byfly.by              |
| AlmaLinux       | 8, 9, 10                | ✅                    | raw.repo.almalinux.org    |
| Proxmox VE      | 7.x, 8.x, 9.x (family)  | ✅                    | de.cdn.proxmox.com (IPv6) |
| Proxmox Backup  | 4.x (family)            | ✅                    | de.cdn.proxmox.com (IPv6) |
| Proxmox Mail    | 7.x (family)            | ✅                    | de.cdn.proxmox.com (IPv6) |
| ArchLinux       | latest                  | ✅                    | mirror.yandex.ru          |
| VirtIO-win      | latest                  | ⚠ только размер+mtime | fedorapeople.org          |

Список открыт к расширению — см. [Конфигурацию](#конфигурация).

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
│   ├── DownloaderInterface.php
│   ├── Downloader.php        # cURL-backend
│   ├── Aria2Downloader.php   # aria2c-backend (multi-stream через HTTP Range)
│   ├── GpgVerifier.php
│   ├── FamilyResolver.php    # шаблоны имён + версионная сортировка для family-режима
│   └── Updater.php
├── tests/                    # Минимальный test-runner без composer/PHPUnit
│   ├── run.php
│   ├── TestRunner.php
│   ├── ChecksumParserTest.php
│   ├── ConfigTest.php
│   ├── DiscoveryTest.php
│   ├── FamilyResolverTest.php
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

## Требования

- **PHP 8.1+** с расширением `cURL` (тестировалось на 8.4)
- Доступ в интернет для скачивания контрольных сумм и образов
- Права на запись в `files/`, `.hash_cache/`, `logs/`
- Достаточно свободного места на диске (каждый DVD-образ — 4–8 ГБ)
- *(Опционально, но рекомендуется)* `aria2c` в PATH — multi-stream загрузка, x2-x4 быстрее cURL: `apt install aria2`
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

Для веб-интерфейса поместите папку в `DocumentRoot` вашего веб-сервера (Apache / nginx + PHP-FPM).

> [!NOTE]
> В репозитории включён `.gitattributes` с `* text=auto eol=lf`. Это нужно для серверов с панелями типа aaPanel/baota: без него на Linux появлялись бы CRLF/LF mismatch и `git pull` падал с `local changes would be overwritten`. Если деплой через панель — лучше использовать `git fetch origin main && git reset --hard origin/main` вместо `git pull`.

---

## Использование

### `update_iso.php` — проверка и загрузка

```bash
php update_iso.php
```

Проверяет каждую запись из `config/iso-list.json` и при необходимости скачивает актуальную версию. По завершении автоматически запускается `generate_all_hashes.php`. Защищён `flock` — повторный запуск (из cron) тихо завершится с кодом 0, если предыдущий ещё работает.

Код возврата:

- `0` — все файлы актуальны или успешно обновлены
- `1` — были ошибки (детали в `logs/update.log` и `logs/last_run.json`)
- `2` — фатальная ошибка (битый конфиг, нет доступа к каталогам и т.п.)

### `generate_all_hashes.php` — пересчёт хэшей

```bash
php generate_all_hashes.php
```

Рекурсивно обходит `files/`, вычисляет SHA256 для каждого файла и сохраняет кэш в `.hash_cache/`. Устаревшие записи (для удалённых файлов) автоматически чистятся. Защищён `flock`.

---

## Веб-интерфейс

Откройте `index.php` через браузер. Что отображается:

### Bento-статус сверху — три карточки

- **Хранилище** — суммарный размер, число файлов, sparkline-график кумулятивного роста по релизным датам за последние 90 дней (`mtime` = upstream `Last-Modified`), дельты прироста за 7д/30д. При первой загрузке цифры набираются counter-up'ом, линия графика рисуется слева направо.
- **Состояние** — последний прогон `update_iso.php`: `актуально / N обновлено / N ошибок`, при FATAL — сообщение об ошибке.
- **Последняя проверка** — относительное время + длительность последнего запуска.

### Список файлов в `files/`

- Имя, размер, дата релиза (mtime упстрима, не время скачивания), SHA256.
- **Latest-бейдж** на самой свежей версии в каждом семействе (например, отдельно для Proxmox VE 9, Proxmox Backup 4, Proxmox Mail 7). Бейдж переливается лёгким shimmer'ом.
- **Точка-индикатор свежести** рядом с датой: зелёная (≤30 дней) / жёлтая (≤180) / серая (старше); свежие пульсируют.
- **Бренд-цвета** дистрибутивов — лёгкий акцент в thumb и на hover (Debian — красный, Ubuntu — оранжевый, Proxmox — пурпурный и т.п.).
- Клик по хэшу копирует в буфер. Меню «Копировать» → прямая ссылка или готовая `wget`-команда.

### Поиск (Ctrl+K)

По имени файла. Если в поле hex-строка длиной ≥ 6 символов — ищет и по SHA256-хэшу. Крестик справа очищает; `Esc` тоже.

### Дополнительные блоки

- **«Отсутствующие файлы»** — раскрывающийся список записей из конфига, которых нет на диске (с ожидаемым URL). Family/discovery записи в этот блок не попадают — для них фактическое имя становится известно только в момент `update_iso.php`.
- **«История обновлений»** — компактный таймлайн последних значимых событий из `logs/update.log`: что обновилось, что зачистилось (cleanup_old), что не удалось скачать. Server-paths намеренно не показываются — только имена файлов.
- **Пустое состояние** — если хранилище пустое или поиск ничего не нашёл, рисуется SVG-иллюстрация с поясняющим текстом и подсказкой.

> [!NOTE]
> Все интерактивные анимации (counter-up, stagger строк, sparkline draw-on, hover-glow, magnetic-pull у кнопок, 3D-tilt у bento-карточек, пульсирующие freshness-точки) выключаются при системной настройке `prefers-reduced-motion: reduce`.

---

## Конфигурация

Список образов хранится в [`config/iso-list.json`](config/iso-list.json). Поддерживаются четыре режима выбора версии:

### Режим 1: `fixed` — точное имя

Когда нужен конкретный файл по имени:

```jsonc
"Debian_12.iso": {
    "local_subdir": "Debian",
    "url_dir":      "https://cdimage.debian.org/cdimage/archive/12.12.0/amd64/iso-dvd/",
    "remote_name":  "debian-12.12.0-amd64-DVD-1.iso"
}
```

### Режим 2: `latest` — фиксированное локальное имя, авто-выбор внутри папки

Когда внутри одного URL зеркала точечная версия меняется (Ubuntu 22.04.5 → 22.04.6, CentOS Stream snapshots), но локальный файл всегда хочется называть одинаково:

```jsonc
"Ubuntu_22.04.iso": {
    "local_subdir":   "Ubuntu",
    "url_dir":        "https://releases.ubuntu.com/22.04/",
    "remote_name":    "latest",
    "latest_pattern": "/^ubuntu-22\\.04(?:\\.\\d+)?-live-server-amd64\\.iso$/"
}
```

Среди файлов в `SHA256SUMS`, совпадающих с `latest_pattern`, выбирается версионно-старший по `strnatcasecmp`. Локальный файл всегда сохраняется как `Ubuntu_22.04.iso`.

### Режим 3: `family` — динамическое имя по шаблону + cleanup старых

Когда упстрим публикует целую серию версий в одной папке (`proxmox-backup-server_4.0-1.iso`, `4.1-1.iso`, `4.2-1.iso`...) и хочется автоматически держать локально только самую свежую с версией в имени:

```jsonc
"proxmox-backup-4": {
    "local_subdir":        "Proxmox",
    "url_dir":             "https://de.cdn.proxmox.com/iso/",
    "ip_version":          "v6",
    "remote_pattern":      "/^proxmox-backup-server_4\\.(\\d+)-\\d+\\.iso$/",
    "local_name_template": "Proxmox_BackUP_4.{1}.iso",
    "cleanup_old":         true
}
```

Что произойдёт:

1. В `SHA256SUMS` найдены `proxmox-backup-server_4.0-1.iso`, `4.1-1.iso`, `4.2-1.iso`
2. Среди них старший — `4.2-1.iso` → captures: `[1] = "2"`
3. Локальное имя: `Proxmox_BackUP_4.{1}.iso` → `Proxmox_BackUP_4.2.iso`
4. После успешной загрузки старые `Proxmox_BackUP_4.0.iso`, `Proxmox_BackUP_4.1.iso` удаляются (т.к. `cleanup_old: true`)

JSON-ключ записи (`proxmox-backup-4`) — произвольный идентификатор семейства, не используется как имя файла.

> [!WARNING]
> **Почему `de.cdn.proxmox.com` + `ip_version: v6`, а не `download.proxmox.com`?** Официальный CDN Proxmox по **IPv4** дропает TCP-трафик из ряда хостинговых сетей (например netcup AS197540) — соединение таймаутит, хотя DNS резолвит корректно. По **IPv6** трафик проходит, но сертификат CDN-узла не содержит имя `download.proxmox.com` (в SAN только `enterprise.proxmox.com` + `*.cdn.proxmox.com`). Поэтому обращаемся к узлу `de.cdn.proxmox.com` напрямую (он в SAN → серт валиден), по IPv6 (`ip_version: v6`) → IPv4-блок обойдён, контент свежий (это официальный CDN, не зеркало).
>
> Если у вас всё доступно по IPv4 — верните `url_dir` на `https://download.proxmox.com/iso/` и уберите `ip_version`. Зеркала (mirrors.xtom.de, mirrors.tuna...) — крайний случай: они сильно отстают от CDN по свежести `/iso/`.

### Режим 4: `discovery` — перебор папок по диапазону

Когда упстрим разносит мажорные версии по разным папкам (Ubuntu LTS: `/22.04/`, `/24.04/`, `/26.04/` ...) и хочется автоматически подхватывать будущие релизы. Скрипт сам генерирует список папок по диапазону, для каждой пробует SHA256SUMS, существующие — обрабатывает как family с подставленным `{folder}`:

```jsonc
"ubuntu-lts": {
    "local_subdir":        "Ubuntu",
    "url_template":        "https://releases.ubuntu.com/{folder}/",
    "folder_enum":         { "from": 22, "to": 30, "step": 1, "format": "{0}.04" },
    "remote_pattern":      "/^ubuntu-{folder}(?:\\.\\d+)?-live-server-amd64\\.iso$/",
    "local_name_template": "Ubuntu_{folder}.iso"
}
```

Что произойдёт:

1. `folder_enum` сгенерирует `["22.04","23.04","24.04",...,"30.04"]` (9 папок)
2. Для каждой будет пробный HEAD на `<url_template>/SHA256SUMS`. Если 404 — папка пропускается тихо.
3. Для существующих (на момент запуска: 22.04, 24.04, 25.04) — `{folder}` подставляется в `url_dir`, `remote_pattern`, `local_name_template` (с `preg_quote` для regex), и запускается обычная family-обработка
4. Внутри `/22.04/` regex `^ubuntu-22\.04(?:\.\d+)?-live-server-amd64\.iso$` ловит файл — берётся версионно-старший (когда выйдет 22.04.6 вместо .5, подхватится автоматом)
5. Локальное имя: `Ubuntu_{folder}.iso` → `Ubuntu_22.04.iso`

**Чтобы тянуть только LTS:** поставь `step: 2` (выберет 22, 24, 26, 28, 30 — все чётные). Иначе `step: 1` включает интерим-релизы вроде 23.04 и 25.04.

Когда выйдет Ubuntu 26.04 / 28.04 — попадут в диапазон без правок конфига. Сразу после релиза папки `/26.04/` на upstream'е скрипт начнёт качать.

<details>
<summary><b>Все поля записи конфига</b> — полная справка</summary>

| Поле | Режим | По умолчанию | Описание |
|------|-------|-------------|----------|
| `local_subdir` | все | `""` | Подпапка внутри `files/` |
| `url_dir` | fixed/latest/family | — | Базовый URL директории (со слешем в конце) |
| `url_template` | discovery | — | URL-шаблон с плейсхолдером `{folder}` |
| `folder_enum.from` / `.to` / `.step` / `.format` | discovery | — / — / `1` / `"{0}"` | Параметры диапазона папок |
| `remote_name` | fixed/latest | — | Имя файла на удалённом сервере, либо `latest` |
| `latest_pattern` | latest | `"/dvd/i"` | PCRE-regex поиска при `remote_name: "latest"`; среди совпадений выбирается версионно-старший |
| `remote_pattern` | family/discovery | — | PCRE-regex по именам в SHA256SUMS. В discovery поддерживает `{folder}` (подставляется с `preg_quote`) |
| `local_name_template` | family/discovery | — | Шаблон локального имени, `{1}`/`{2}`/... — capture groups, `{folder}` (в discovery) — текущая папка |
| `cleanup_old` | family/discovery | `false` | Удалять старые версии в той же подпапке после успешной загрузки. **Необратимо.** |
| `checksum_files` | все | `["SHA256SUMS","SHA256SUM","sha256sum.txt","sha256sums.txt","CHECKSUM"]` | Имена чексумм-файлов для пробы |
| `force_download_without_checksum` | fixed/latest | `false` | Качать, даже если SHA256SUMS не найден или нет записи об этом файле |
| `skip_if_unchanged` | все | `false` | Для force-загрузок: пропустить, если HEAD говорит, что remote не менялся. Сравниваются И `Content-Length` (с локальным размером), И `Last-Modified` (с локальным mtime) — оба должны совпасть. |
| `insecure_ssl` | все | `false` | Отключить проверку SSL для конкретного зеркала (точечный опт-аут) |
| `ip_version` | все | `"v4"` | `v4` форсит IPv4, `v6` — IPv6 (обход IPv4-блоков), `any` — выбор curl. Для `v6` загрузка идёт через cURL, не aria2c |
| `gpg.signature_url` | все | — | URL подписи для `SHA256SUMS` |
| `gpg.key_fingerprint` | все | — | Ожидаемый fingerprint ключа (40 hex). Сам ключ должен быть импортирован в `gpg` |

**Особенности парсера:**
- Ключи, начинающиеся с `_` (например `"_comment_proxmox": "..."`) парсер игнорирует — удобно для inline-комментариев в JSON.
- `remote_name`, `remote_pattern` и `url_template` **взаимоисключающие**: ровно одно должно быть указано.
- Discovery-записи **не отображаются в блоке «отсутствующие файлы»** в веб-интерфейсе — для них фактический список локальных имён становится известен только в момент запуска `update_iso.php`.

</details>

См. также [`config/iso-list.schema.json`](config/iso-list.schema.json) — JSON Schema для валидации и автодополнения в IDE.

---

## Логика обновления

Для каждой записи из конфига:

```
1. Скачать SHA256SUMS / SHA256SUM / CHECKSUM (по списку checksum_files)
   ├─ Не найдены
   │   ├─ force_download_without_checksum=true и режим fixed/latest
   │   │   └─ Загрузка без проверки хэша
   │   └─ Иначе → failed
   └─ Найдены
       ├─ (опционально) проверить GPG-подпись SHA256SUMS
       └─ Резолв имени удалённого файла:
           ├─ family    → среди совпавших с remote_pattern взять версионно-старший
           ├─           ├─ имя локального файла = local_name_template с {1},{2},...
           ├─ latest    → среди совпавших с latest_pattern взять версионно-старший
           ├─           ├─ имя локального файла = ключ JSON-записи
           └─ fixed     → точное имя из remote_name
                         ├─ имя локального файла = ключ JSON-записи

2. Сравнение хэшей:
   - SHA256 локального файла (из кэша по mtime+size, иначе hash_file())
   - Совпали → up_to_date, выходим
   - Иначе → переходим к загрузке

3. Загрузка:
   - HEAD-запрос → ожидаемый Content-Length и Last-Modified
   - При skip_if_unchanged=true и неизменном remote — пропуск
   - До 5 попыток, прерывание при 60 сек без прогресса
   - После загрузки: проверка фактического размера == Content-Length
   - Если был ожидаемый хэш — пере-проверка SHA256 файла .tmp
   - rename(.tmp, финальный файл) — атомарно
   - Кэш SHA256 обновляется сразу

4. Family + cleanup_old + успешный update → удаление старых версий в той же подпапке,
   чьи имена матчатся с template-как-regex (но не равно текущему).

5. Запуск generate_all_hashes.php — пересчёт по всем файлам и чистка осиротевшего кэша.

6. Запись logs/last_run.json со сводкой.
```

---

## Скорость загрузки

### aria2c (рекомендуется)

При наличии `aria2c` в `PATH` `update_iso.php` автоматически использует его вместо cURL для скачивания ISO. Файл делится на 16 сегментов и качается параллельно через HTTP Range — это ломает per-connection rate-limit многих серверов (Proxmox, Microsoft, AWS S3 mirrors) и даёт реалистичный буст **x2-x4**. Установка:

```bash
apt install aria2          # Debian / Ubuntu
dnf install aria2          # Fedora / RHEL
```

При первом запуске `update_iso.php` залогирует выбранный backend:

```
Использую aria2c для загрузки: /usr/bin/aria2c
```

или (если не установлен):

```
aria2c не найден, использую cURL-Downloader (apt install aria2 для ускорения)
```

`aria2c` сам показывает прогресс-бар и счётчик скорости. SHA256 и Content-Length проверяются **после** загрузки нашим кодом — даже при загрузке через aria2c целостность гарантирована.

### Тюнинг cURL

Когда aria2c недоступен, штатный cURL-downloader использует:

- буфер чтения 256 KB (вместо дефолтных 16 KB)
- `TCP_NODELAY` (выключен Nagle)
- `TCP_KEEPALIVE`
- HTTP/1.1 (быстрее HTTP/2 для single-stream загрузок ISO)
- IPv4 (стабильнее на большинстве хостингов; переопределяется per-entry через `ip_version`)
- прогресс-бар throttle до 4 Hz (меньше fflush-overhead)

Это даёт +20-50% к скорости по сравнению с дефолтным cURL.

---

## Безопасность

### SSL

Сертификаты проверяются по умолчанию (`CURLOPT_SSL_VERIFYPEER = true`). Если конкретное зеркало отдаёт битый или самоподписанный сертификат, добавьте `"insecure_ssl": true` для этой записи в конфиге — это локальный опт-аут, остальные записи продолжают проверять SSL строго.

### GPG-верификация SHA256SUMS

Подпись на `SHA256SUMS` нивелирует риск MITM-подмены контрольных сумм (тот, кто подменил бы хэш, должен ещё и пересоздать подпись валидным ключом — что без приватки невозможно). В конфиге уже включены `gpg`-блоки для **Debian** и **Ubuntu** — но они работают только если на сервере импортированы соответствующие ключи.

> [!WARNING]
> Без ключей загрузка Debian/Ubuntu будет падать с `GPG verify FAILED` — либо импортируйте ключи (см. ниже), либо удалите блоки `gpg` из `config/iso-list.json`.

<details>
<summary><b>Импорт GPG-ключей</b> (один раз)</summary>

```bash
# 1) Проверьте, что gpg установлен
apt install gnupg

# 2) Debian: импорт текущих Release/Archive ключей с keyring.debian.org
#    Актуальный список fingerprint'ов сверяйте на https://www.debian.org/CD/verify
#    На момент написания (Debian 11/12/13):
gpg --keyserver keyserver.ubuntu.com --recv-keys \
    DF9B9C49EAA9298432589D76DA87E80D6294BE9B \
    DC30D7C23CBBABEE \
    B7619EB16E91369B68B0E312EF0F382A1A7B6500

# 3) Ubuntu: импорт Ubuntu CD Image Automatic Signing Key
gpg --keyserver keyserver.ubuntu.com --recv-keys \
    843938DF228D22F7B3742BC0D94AA3F0EFE21092 \
    D94AA3F0EFE21092

# 4) Проверка: ключи импортированы?
gpg --list-keys
```

> Fingerprint'ы могут устаревать (Debian обновляет ключи каждый релиз). Если `gpg --verify` падает с "Unknown signing key" — найдите актуальный отпечаток на странице Verify соответствующего проекта и импортируйте свежий.

</details>

#### Запуск под `www` (не root)

GPG читает ключи из `$HOME/.gnupg`. Если cron запускает `update_iso.php` от пользователя `www` (типично для aaPanel), ключи надо импортировать **под этим пользователем**:

```bash
su -s /bin/bash www -c 'gpg --keyserver keyserver.ubuntu.com --recv-keys 843938DF228D22F7B3742BC0D94AA3F0EFE21092'
```

#### Проверка работы

После импорта запустите `php update_iso.php`. В логе для Debian/Ubuntu записей должна появиться строка `GPG signature OK (fingerprint XXX...)`. Если видите `GPG verify FAILED` — ключ не импортирован под нужным пользователем или fingerprint устарел.

#### Опциональный жёсткий чек fingerprint'а

По умолчанию GPG-блок принимает подпись любым импортированным ключом. Чтобы дополнительно гарантировать, что подписало именно **то** имя — добавьте `key_fingerprint`:

```jsonc
"gpg": {
    "signature_url":   "https://cdimage.debian.org/.../SHA256SUMS.sign",
    "key_fingerprint": "DF9B9C49EAA9298432589D76DA87E80D6294BE9B"
}
```

При несовпадении запись помечается `failed`, файл не скачивается.

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

Веб-интерфейс читает этот файл и показывает информацию в bento-статусе.

---

## Автоматизация через cron

Добавьте содержимое файла [`crontab`](crontab) в расписание (`crontab -e`), заменив `/path/to/` на реальный путь:

```cron
# Пересчёт хэшей каждый час
0 * * * *  php /path/to/generate_all_hashes.php > /dev/null 2>&1

# Обновление ISO ежедневно в полночь
# (внутри автозапускается generate_all_hashes)
0 0 * * *  php /path/to/update_iso.php > /dev/null 2>&1
```

> [!NOTE]
> Скрипты защищены `flock`, поэтому пересечение запусков безопасно — второй экземпляр тихо выйдет с кодом 0.
>
> При запуске не из TTY прогресс-бар автоматически переключается в компактный режим (логи не засоряются `\r`).

---

## Тесты

Минимальный test-runner без зависимостей:

```bash
php tests/run.php
```

Покрытие:

- `ChecksumParserTest` — парсер `SHA256SUMS` (формат GNU/BSD/CRLF/комментарии/пробелы)
- `HashCacheTest` — кэш SHA256 (запись/чтение/инвалидация/чистка)
- `ConfigTest` — валидация JSON-конфига
- `FamilyResolverTest` — резолв шаблонов имён, версионная сортировка
- `DiscoveryTest` — генератор папок по диапазону

Расширить можно, добавив `tests/SomethingTest.php` — runner подхватит автоматически.

---

## Нюансы

- **Кэш SHA256** хранится по `md5($localPath)` в `.hash_cache/`. Если переместить файл — кэш не сработает и SHA256 будет пересчитан (а старая запись удалится при очистке осиротевших).
- **Атомарность загрузки**: файл скачивается в `*.tmp` и переименовывается после успеха — обрыв не повреждает существующий файл.
- **Дефолтный `latest_pattern`** — `/dvd/i`. Подходит для CentOS Stream и образов, в имени которых есть «dvd». Для других схем переопределите в записи конфига.
- **Кэш формата**: единый `{ "hash": "sha256:<hex>", "mtime": ..., "size": ... }`. Старые записи без префикса `sha256:` корректно нормализуются при чтении.
- **`generate_all_hashes.php` сейчас идёт в один поток.** Хэширование 8 ГБ-файла занимает ~15–30 секунд CPU — для регулярного крона это нормально.
- **mtime файлов** = upstream `Last-Modified` (после загрузки делается `touch`). Это значит «дата релиза», а не «когда скачали» — sparkline в интерфейсе показывает реальную релизную историю зеркала.

---

<div align="center">

**Лицензия:** [MIT](LICENSE)

[⬆ Наверх](#iso-sync)

</div>
