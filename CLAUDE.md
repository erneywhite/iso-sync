# CLAUDE.md — iso-sync

Контекст проекта для Claude Code (читается автоматически при старте сессии).
Полная история работы и принятые решения — в [docs/SESSION-HANDOFF.md](docs/SESSION-HANDOFF.md).

## Что это

PHP-проект для автосинхронизации ISO-образов дистрибутивов с официальных
серверов по SHA256 (+ GPG-подписи где доступны) и веб-интерфейс просмотра.
Развёрнут как публичное зеркало **iso.erney.monster** (обновляется по cron).

Репозиторий: https://github.com/erneywhite/iso-sync · ветка `main` · публичный.

## Расположение в дереве

Git-репозиторий — это **подпапка `repo/`** внутри каталога проекта. Сессия
Claude Code обычно открывается на уровень выше (в каталоге проекта), а код,
`.git`, тесты и т.д. лежат в `repo/`. Все пути в коде строятся через `__DIR__`,
хардкода абсолютных локаций нет — проект перемещаемый.

## Стек и архитектура

- **PHP 8.1+** (на сервере php85), **без Composer**. Автозагрузка ручная — `lib/bootstrap.php`.
- `namespace IsoSync`, вся логика в `lib/`:
  - `Config` — парсинг `config/iso-list.json`
  - `Updater` — главный оркестратор прогона
  - `Downloader` (cURL) и `Aria2Downloader` (aria2c, multi-stream через HTTP Range) за общим `DownloaderInterface`
  - `Http`, `ChecksumParser`, `HashCache`, `GpgVerifier`, `FamilyResolver`, `Lock`, `Logger`
- `config/iso-list.json` — список образов. **4 режима записей**: `fixed` / `latest` / `family` / `discovery` (схема — `config/iso-list.schema.json`).
- `index.php` — веб-интерфейс (PHP + CSS + JS в одном файле, ~2200 строк).
- `update_iso.php` — CLI проверки/загрузки; `generate_all_hashes.php` — пересчёт SHA256.

## Запуск и тесты

```bash
php update_iso.php            # проверка + загрузка (в конце сам зовёт generate_all_hashes)
php generate_all_hashes.php   # пересчёт SHA256 + чистка кэша
php tests/run.php             # самописный раннер (без PHPUnit)
```

CI гоняет тесты на PHP 8.1–8.4 (`.github/workflows/tests.yml`).
Локально `php` может отсутствовать в PATH — тогда тесты идут на сервере / в CI.
Сборки как таковой нет (интерпретируемый PHP).

## Деплой

Через git. На сервере (aaPanel/baota) `git pull` спотыкается на
«local changes would be overwritten» (CRLF / правки панели) — деплой-скрипт
должен быть:

```bash
git fetch origin main && git reset --hard origin/main
```

`.gitattributes` форсит `* text=auto eol=lf` — иначе Linux-сервер ловит CRLF/LF mismatch.

## Грабли (важное)

- **Proxmox**: официальный `download.proxmox.com` по IPv4 блокируется из ряда
  хостинговых сетей → конфиг ходит на `de.cdn.proxmox.com` + `ip_version: v6`
  (узел в SAN серта, валиден по IPv6). Детали — в комментарии `config/iso-list.json` и README.
- **GPG**: блоки для Debian/Ubuntu включены, но требуют импорта ключей в keyring
  под тем пользователем, от которого идёт cron. Без ключей — `GPG verify FAILED`.
- **virtio-win**: у `.iso` нет официальной чек-суммы → `checksum_files: []` + `force_download_without_checksum`.
- **mtime файлов** = upstream `Last-Modified` (`touch` после загрузки) — это
  «дата релиза», на ней строятся sparkline и freshness-индикаторы в UI.
- **Фон UI на Firefox/Mac**: движущиеся `transform`-слои под интерфейсом дают
  trail-артефакты («моргание»). Анимация фоновой нейбулы переведена с `translate`
  на `filter: hue-rotate` (геометрия слоя не меняется → артефактов нет).
  Не возвращать transform/translate-анимации на фоновые слои под карточками.

## Рабочие конвенции

- **Смена источника данных / инфраструктуры** (зеркала, URL, способ доставки):
  СНАЧАЛА показать варианты с trade-offs и получить апрув — не коммитить сразу.
  (Прецедент: поспешный переход на зеркало, оказавшееся протухшим на полгода.)
- Правки кода / UI / багфиксы — действовать и пушить как обычно.
- Внешние источники проверять на **свежесть И достижимость с конкретного сервера**,
  не только «отвечает ли вообще».
- **Privacy**: не выводить server-paths в веб-интерфейсе (блок «История» парсит
  логи, но показывает только basename файла, не локальный путь).

## Отложенные идеи (не срочные)

- Drag-drop проверка хэша через Web Crypto API (перетащил ISO → сверка SHA256 в браузере).
- Сплит `index.php` на `index.php` + `assets/style.css` + `assets/app.js` (с `?v=<hash>` для кеша).
- Флаг `--only=<entry>` у `update_iso.php` для обновления одной записи.
