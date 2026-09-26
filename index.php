<?php
declare(strict_types=1);

// Запрещаем браузеру и прокси кешировать саму страницу — иначе после правок
// пользователь может видеть устаревший HTML/CSS до Ctrl+F5.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/lib/bootstrap.php';

use IsoSync\Config;
use IsoSync\HashCache;
use IsoSync\PrivateDirs;

$baseDir   = __DIR__;
$filesDir  = $baseDir . '/files';
$cacheDir  = $baseDir . '/.hash_cache';
$webDir    = 'files';
$logsDir   = $baseDir . '/logs';
$configPath = $baseDir . '/config/iso-list.json';

$hashCache = new HashCache($cacheDir);

/* ===== Приватные каталоги =====
   Каталог с файлом-маркером `.private` не попадает ни в листинг, ни в тоталы,
   ни в спарклайн, ни в «Историю» — кроме случая, когда IP клиента попал в
   allowlist внутри самого маркера. Логика и разбор — в lib/PrivateDirs.php.

   Это только витрина: саму раздачу закрывает веб-сервер (allow/deny с тем же
   списком IP) — см. docs/PRIVATE-DIRS.md.

   Почему маркер на диске, а не поле в config/iso-list.json: конфиг может не
   распарситься (ниже есть catch, который просто рисует UI без missing-блока), и
   тогда фильтрация молча отключилась бы — fail-open ровно на том, что прячем.
   Маркер лежит рядом с данными и от валидности конфига не зависит. */
$clientIp     = PrivateDirs::clientIp($_SERVER, PrivateDirs::trustedProxies($baseDir . '/config'));
$hidden       = PrivateDirs::scan($filesDir, $clientIp);
$privateDirs  = $hidden['dirs'];    // имя каталога => true (скрыть от этого клиента)
$privateNames = $hidden['files'];   // basename файла внутри скрытого каталога => true

// Сводка последнего прогона update_iso (если есть)
$lastRun = null;
$lastRunPath = $logsDir . '/last_run.json';
if (is_file($lastRunPath)) {
    $raw = @file_get_contents($lastRunPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $lastRun = $decoded;
        }
    }
}

/**
 * История значимых событий из logs/update.log: что обновилось, что зачистилось,
 * что не удалось скачать. Читает последние 256 KB лога (хвост) — этого хватает
 * на сотни событий, парсит JSON-lines, фильтрует и возвращает последние N.
 *
 * Намеренно НЕ показываем server-path (privacy) — только имена файлов.
 *
 * @return list<array{ts:string,kind:string,file:string,extra?:string}>
 */
function loadHistory(string $logsDir, int $maxEntries = 20): array
{
    $log = $logsDir . '/update.log';
    if (!is_file($log)) return [];
    $size = @filesize($log);
    if ($size === false || $size === 0) return [];

    $read = (int)min($size, 262144);  // последние 256 KB
    $fp = @fopen($log, 'rb');
    if ($fp === false) return [];
    @fseek($fp, -$read, SEEK_END);
    $data = @fread($fp, $read);
    @fclose($fp);
    if (!is_string($data)) return [];

    $lines = explode("\n", $data);
    // Если читали из середины файла — первая строка может быть обрезана, отбросим
    if ($size > $read) array_shift($lines);

    $events = [];
    foreach (array_reverse($lines) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rec = @json_decode($line, true);
        if (!is_array($rec)) continue;
        $event = $rec['event'] ?? null;

        if ($event === 'file_updated') {
            // Имя файла: предпочтительно local_name (добавлено в Updater), иначе извлечь из сообщения
            $file = (string)($rec['local_name'] ?? '');
            if ($file === '' && preg_match('/^Файл обновлён:\s*(.+)$/u', (string)($rec['message'] ?? ''), $m)) {
                $file = trim($m[1]);
            }
            if ($file === '') continue;
            $events[] = ['ts' => (string)($rec['ts'] ?? ''), 'kind' => 'updated', 'file' => $file];
        } elseif ($event === 'cleanup_old') {
            $events[] = [
                'ts'    => (string)($rec['ts'] ?? ''),
                'kind'  => 'cleanup',
                'file'  => (string)($rec['removed'] ?? '?'),
                'extra' => (string)($rec['family'] ?? ''),
            ];
        } elseif ($event === 'download_giveup') {
            // URL содержит upstream-хост (публично — не страшно), показываем basename
            $u = (string)($rec['url'] ?? '');
            $name = basename((string)parse_url($u, PHP_URL_PATH));
            if ($name === '' || $name === false) $name = '?';
            $events[] = ['ts' => (string)($rec['ts'] ?? ''), 'kind' => 'failed', 'file' => $name];
        }

        if (count($events) >= $maxEntries) break;
    }
    return $events;
}

$history = loadHistory($logsDir);
// Имена файлов из приватных каталогов вычищаем — иначе они утекали бы в
// публичный UI через парсинг логов, мимо фильтра самого листинга.
if ($privateNames !== []) {
    $history = array_values(array_filter(
        $history,
        static fn(array $e): bool => !isset($privateNames[$e['file']])
    ));
}

// Список из конфига для подсчёта отсутствующих
$missing = [];
if (is_file($configPath)) {
    try {
        $cfg = Config::loadFromFile($configPath);
        foreach ($cfg->files as $entry) {
            // Family и Discovery записи имеют динамическое локальное имя — в этих
            // режимах "ожидаемое имя" определяется только в момент запуска update_iso.
            // Пропускаем такие записи в проверке missing (иначе репортили бы false-positives).
            if ($entry->isFamily() || $entry->isDiscovery()) {
                continue;
            }
            // Приватные записи не светим даже фактом отсутствия
            if ($entry->localSubdir !== '' && isset($privateDirs[$entry->localSubdir])) {
                continue;
            }
            $expectedPath = $filesDir
                . ($entry->localSubdir !== '' ? DIRECTORY_SEPARATOR . $entry->localSubdir : '')
                . DIRECTORY_SEPARATOR . $entry->localName;
            if (!is_file($expectedPath)) {
                $missing[] = [
                    'name'   => $entry->localName,
                    'subdir' => $entry->localSubdir,
                    'remote' => $entry->urlDir . ($entry->isLatest() ? '(latest)' : $entry->remoteName),
                ];
            }
        }
    } catch (Throwable) {
        // конфиг битый — UI отрисуем без блока missing
    }
}

$items = [];
$totalSize = 0;
$totalFiles = 0;
if (is_dir($filesDir)) {
    foreach (scandir($filesDir) ?: [] as $name) {
        // str_starts_with('.') покрывает . .. .gitkeep и сам маркер .private
        if (str_starts_with($name, '.')) continue;
        $path = $filesDir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            if (isset($privateDirs[$name])) continue;
            $children = [];
            $dirSize = 0;
            foreach (scandir($path) ?: [] as $c) {
                if (str_starts_with($c, '.')) continue;
                $cp = $path . DIRECTORY_SEPARATOR . $c;
                if (is_file($cp)) {
                    $size = (int)filesize($cp);
                    $children[] = [
                        'name'  => $c,
                        'size'  => $size,
                        'mtime' => filemtime($cp),
                        'type'  => $hashCache->get($cp) ?? 'sha256:not_computed_yet',
                    ];
                    $dirSize += $size;
                    $totalFiles++;
                    $totalSize += $size;
                }
            }
            $items[] = [
                'name'     => $name,
                'type'     => 'dir',
                'size'     => $dirSize,
                'children' => $children,
                // Каталог приватный, но показан — значит клиент попал в allowlist.
                // Помечаем бейджем, чтобы не спутать его с публичной раздачей.
                'private'  => PrivateDirs::isPrivate($path),
            ];
        } elseif (is_file($path)) {
            $size = (int)filesize($path);
            $items[] = [
                'name'  => $name,
                'size'  => $size,
                'mtime' => filemtime($path),
                'type'  => $hashCache->get($path) ?? 'sha256:not_computed_yet',
            ];
            $totalFiles++;
            $totalSize += $size;
        }
    }
}

/* ===== Sparkline-данные для карточки «Хранилище» =====
   Собираем все файлы (size + mtime), сортируем хронологически и строим
   кумулятивный временной ряд. mtime у нас — upstream Last-Modified (см.
   Updater::syncMtime), так что это реально «релизная история» зеркала,
   а не время скачивания. Точек обычно ≤ десятков, ряд лёгкий — пушим в JS. */
$storageDataPoints = [];
$collectPoints = function(array $node) use (&$collectPoints, &$storageDataPoints) {
    if (($node['type'] ?? null) === 'dir' && !empty($node['children'])) {
        foreach ($node['children'] as $c) $collectPoints($c);
    } elseif (isset($node['mtime'], $node['size'])) {
        $storageDataPoints[] = ['ts' => (int)$node['mtime'], 'size' => (int)$node['size']];
    }
};
foreach ($items as $it) $collectPoints($it);
usort($storageDataPoints, fn($a, $b) => $a['ts'] <=> $b['ts']);

$cumulative = 0;
$storageSeries = [];
foreach ($storageDataPoints as $p) {
    $cumulative += $p['size'];
    $storageSeries[] = ['ts' => $p['ts'], 'total' => $cumulative];
}

// Дельты по окнам (для подсказки под спарклайном)
$nowTs    = time();
$delta7d  = 0;
$delta30d = 0;
foreach ($storageDataPoints as $p) {
    if ($p['ts'] >= $nowTs - 86400 * 7)  $delta7d  += $p['size'];
    if ($p['ts'] >= $nowTs - 86400 * 30) $delta30d += $p['size'];
}

/* ===== Подписи для каркаса Workbench (assets/app.js) =====
   Счётчики и подпись «Проверка» в HTML намеренно вычисляем по доступным
   пользователю данным — см. MIGRATION.md §3 (демо показывало статичные цифры).
   mbstring в боевом PHP отсутствует — только ASCII-регулярки. */
$uiPlural = static function (int $n, string $one, string $few, string $many): string {
    $n = $n % 100;
    if ($n >= 11 && $n <= 14) return $many;
    return match ($n % 10) {
        1      => $one,
        2..4   => $few,
        default => $many,
    };
};

$uiTotalSize = '';
if ($totalSize > 0) {
    $gb = $totalSize / 1073741824;
    $uiTotalSize = str_replace('.', ',', number_format($gb, 1)) . ' GiB';
}

$uiSectionCount = count(array_filter($items, static fn ($it) => ($it['type'] ?? '') === 'dir'));

$uiLastCheck = '—';
if (is_array($lastRun)) {
    $ts = (string)($lastRun['finished_at'] ?? $lastRun['started_at'] ?? '');
    if ($ts !== '') {
        $epoch = strtotime($ts);
        if ($epoch !== false) {
            $uiLastCheck = date('d F', $epoch);
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Erney White">
<meta name="description" content="Личное зеркало дистрибутивов Erney White.">
<title>Хранилище iso-файлов</title>
<link rel="icon" href="favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="assets/styles.css?v=workbench-20260926">
<script defer src="assets/vendor/lucide.js?v=workbench-20260926"></script>
</head>
<body>
<div id="iso-designs" data-mode="workbench" aria-label="ISO-архив">
  <div class="product">
    <header class="masthead"><a class="brand" href="https://iso.erney.monster/" target="_blank" rel="noopener"><span class="brand-icon">ew<span>↗</span></span><span>Erney<span class="brand-second"> / iso archive</span></span></a><div class="mast-right"><span>Личное зеркало дистрибутивов</span><button class="cursor-interaction quiet" id="history-open"><i data-lucide="history" aria-hidden="true"></i> Обновления</button></div></header>
    <div class="workarea"><aside class="navigation"><div class="navcaption">БИБЛИОТЕКА</div><nav id="folders" aria-label="Разделы архива"></nav><div class="sidebar-bottom"><span class="smallcaps">ПУБЛИЧНЫЙ АРХИВ</span><strong><?php echo (int)$totalFiles; ?> <small><?php echo $uiPlural($totalFiles, 'файл', 'файла', 'файлов'); ?></small></strong><span><?php echo $uiTotalSize; ?> · <?php echo (int)$uiSectionCount; ?> разделов</span><div class="storage-line"></div><span>Проверка: <?php echo $uiLastCheck; ?></span></div></aside>
    <main class="workspace"><div class="heading-line"><div><div class="eyebrow">ISO.ERNEY.MONSTER <span>/ БИБЛИОТЕКА</span></div><h1 id="section-title">Ubuntu<span class="title-dot">.</span></h1><p id="section-description">Образы Ubuntu в вашем архиве</p></div><div class="heading-meta"><b id="group-count">5</b><span>файлов в разделе</span></div></div>
      <div class="searchrow"><label class="searchbox"><i data-lucide="search" aria-hidden="true"></i><input id="iso-query" aria-label="Поиск по имени или SHA-256" placeholder="Найти образ или SHA-256…" autocomplete="off"><kbd>Ctrl K</kbd><button class="cursor-interaction clear" id="clear-query" aria-label="Очистить поиск" hidden>×</button></label><select id="sort" aria-label="Сортировка"><option value="version">По версии</option><option value="date">По дате файла</option><option value="size">По размеру</option></select></div>
      <div id="feature"></div><div class="results-head"><span id="results-count" aria-live="polite">5 файлов</span><span class="hashhint">SHA-256 доступен для каждого файла</span></div>
      <div class="files-and-detail"><section id="file-list" aria-label="Файлы"></section><aside id="inspector" aria-label="Сведения о выбранном файле"></aside></div>
      <div id="empty" hidden><i data-lucide="search-x" aria-hidden="true"></i><h2>Ничего не найдено</h2><p>Попробуйте название системы, часть имени или SHA-256.</p><button class="cursor-interaction quiet" id="reset-search">Сбросить поиск</button></div>
      <footer class="archive-footer"><span>Erney White <span class="sep">/</span> ISO archive</span><span>Личное зеркало дистрибутивов</span></footer>
    </main></div>
    <section id="history" hidden aria-label="История обновлений"><div class="history-heading"><h2>История обновлений</h2><button class="cursor-interaction quiet" id="history-close">Закрыть ×</button></div><div id="history-items"></div></section>
    <div id="copy-message" role="status" aria-live="polite"></div>
  </div>
</div>
    <script>
    // Передача серверных данных в интерфейс: структура — assets/demo-data.js /
    // MIGRATION.md §3. Приватные каталоги и файлы уже отфильтрованы вышестоящим
    // PHP-кодом; JSON кодируется с HEX-флагами, чтобы экранировать <, &, ' и " .
    window.ISO_ARCHIVE_DATA = <?php
    echo json_encode(
        ['catalog' => $items, 'history' => $history],
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    ?>;
    </script>
    <script defer src="assets/app.js?v=workbench-20260926"></script>
</body>
</html>
