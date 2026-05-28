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

$baseDir   = __DIR__;
$filesDir  = $baseDir . '/files';
$cacheDir  = $baseDir . '/.hash_cache';
$webDir    = 'files';
$logsDir   = $baseDir . '/logs';
$configPath = $baseDir . '/config/iso-list.json';

$hashCache = new HashCache($cacheDir);

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
        if ($name === '.' || $name === '..' || $name === '.gitkeep') continue;
        $path = $filesDir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            $children = [];
            $dirSize = 0;
            foreach (scandir($path) ?: [] as $c) {
                if ($c === '.' || $c === '..' || $c === '.gitkeep') continue;
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
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Хранилище iso-файлов</title>
<link rel="icon" href="favicon.ico" type="image/x-icon">
<style>
:root {
    /* Подсказка браузеру: страница тёмная — нативные контролы (option, скроллбары,
       автокомплит, дата-пикеры) должны рисоваться в тёмной палитре. */
    color-scheme: dark;
    /* Палитра: неоново-фиолетовая (под лого), с уклоном в тёмно-сливовый фон.
       --accent (168,85,247 = #a855f7) и --accent-pink (232,121,249) — два главных
       акцента. RGB-тройки удобны для использования внутри rgba(). */
    --bg-1:#0a0613;
    --bg-2:#1a0a2e;
    --surface-1:rgba(255,255,255,0.025);
    --surface-2:rgba(255,255,255,0.04);
    --border-1:rgba(168,85,247,0.08);
    --border-2:rgba(168,85,247,0.18);
    --text:#f0e9fb;
    --muted:#a89ec1;
    --muted-2:#6b6385;
    --accent:#a855f7;
    --accent-2:#d8b4fe;
    --accent-pink:#f0abfc;
    --accent-soft:rgba(168,85,247,0.13);
    --accent-rgb:168,85,247;
    --pink-rgb:232,121,249;
    --ok:#4ade80;
    --warn:#fbbf24;
    --err:#f87171;
    --radius:14px;
    --radius-sm:10px;
    --shadow:0 6px 30px rgba(10,5,25,0.7);
    --mono:ui-monospace,'JetBrains Mono','Cascadia Code','Fira Code','Courier New',monospace;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto;font-feature-settings:'cv11','ss01'}
/* Прокрутка идёт внутри .card, body не скроллится — шапка и статус-бар фиксированы,
   фон без шва на любом размере страницы.
   Mesh-gradient: 5 цветных blob'ов перекрывают друг друга, смешиваясь в "сетку"
   как в Stripe/Spotify-дизайнах. Сам body — статичный mesh; ::before добавляет
   2 движущихся blob'а сверху для эффекта "живости". */
body{
    background:
        radial-gradient(circle 700px at 10% 5%, rgba(168,85,247,0.26) 0%, transparent 55%),
        radial-gradient(circle 550px at 95% 12%, rgba(232,121,249,0.20) 0%, transparent 55%),
        radial-gradient(circle 800px at 100% 55%, rgba(240,171,252,0.13) 0%, transparent 55%),
        radial-gradient(circle 900px at 5% 100%, rgba(124,58,237,0.22) 0%, transparent 55%),
        radial-gradient(circle 700px at 60% 110%, rgba(232,121,249,0.12) 0%, transparent 55%),
        linear-gradient(180deg,var(--bg-1),var(--bg-2));
    color:var(--text);
    height:100vh;
    height:100dvh;
    overflow:hidden;
    position:relative;
}
::selection{background:rgba(168,85,247,0.35);color:#fff}

/* Movement layer: 2 blob'а медленно дрейфуют поверх статичного mesh.
   Только transform — opacity намеренно убрана: с alternate и тремя ключами
   (0/50/100) блоб «проходил» пик opacity дважды за цикл и это выглядело как
   мерцание лампочки. Теперь 2 ключа (from/to) и плавный ping-pong через
   alternate, opacity статичная. translate3d принудительно создаёт GPU-слой. */
body::before{
    content:'';
    position:fixed;
    inset:-10%;
    z-index:-2;
    pointer-events:none;
    /* Серия хинтов для Safari/Mac: принудительная GPU-композиция, чтобы
       слой не дёргался от перерисовок выше (mix-blend-mode, backdrop-filter,
       SVG-фильтры). will-change + явный transform на старте промоутят слой
       до начала анимации, а не на первом её кадре. */
    will-change:transform;
    transform:translate3d(0,0,0);
    backface-visibility:hidden;
    -webkit-backface-visibility:hidden;
    background:
        radial-gradient(ellipse 500px 400px at 35% 35%, rgba(168,85,247,0.20), transparent 65%),
        radial-gradient(ellipse 450px 380px at 70% 65%, rgba(232,121,249,0.14), transparent 65%);
    animation:nebula-drift 60s ease-in-out infinite alternate;
}
@keyframes nebula-drift{
    from{transform:translate3d(-25px,-15px,0) scale(0.98)}
    to  {transform:translate3d(35px,25px,0)   scale(1.05)}
}

/* Subtle grain/noise — SVG-шум через data-URI, накладывается mix-blend-mode'ом
   поверх mesh. Inline-размер ~1 KB. Без анимации, без CPU — статичная текстура. */
body::after{
    content:'';
    position:fixed;
    inset:0;
    z-index:-1;
    pointer-events:none;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' seed='7' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.85  0 0 0 0 0.7  0 0 0 0 1  0 0 0 0.55 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    opacity:0.07;
    mix-blend-mode:overlay;
}

@media (prefers-reduced-motion: reduce){
    body::before{animation:none}
}

.container{
    max-width:min(1600px,94vw);
    margin:0 auto;
    /* padding-top побольше — даём место под glow логотипа, чтобы он не обрезался
       при overflow:hidden на body. По бокам/снизу сжатие осталось. */
    padding:38px 20px 24px;
    height:100vh;
    height:100dvh;
    display:flex;
    flex-direction:column;
    gap:16px;
    overflow:hidden;
}

/* ========== Header ========== */
header{
    display:flex;
    align-items:center;
    gap:16px;
    justify-content:space-between;
    flex-wrap:wrap;
    padding:8px 4px;
}
.brand{display:flex;align-items:center;gap:14px;min-width:0}
.logo-img{
    width:48px;height:48px;border-radius:12px;
    /* Плотный неоновый halo: тонкая rim-обводка + ближний glow + средний halo.
       Радиус ~28px — помещается в padding-top контейнера (38px), без обрезок. */
    box-shadow:
        0 0 0 1px rgba(168,85,247,0.5),
        0 4px 14px rgba(168,85,247,0.55),
        0 0 24px rgba(232,121,249,0.40);
    animation:logoPulse 4s ease-in-out infinite;
}
@keyframes logoPulse{
    0%,100%{
        box-shadow:
            0 0 0 1px rgba(168,85,247,0.45),
            0 4px 14px rgba(168,85,247,0.50),
            0 0 22px rgba(232,121,249,0.35);
    }
    50%{
        box-shadow:
            0 0 0 1px rgba(168,85,247,0.65),
            0 4px 18px rgba(168,85,247,0.70),
            0 0 30px rgba(232,121,249,0.50);
    }
}
@media (prefers-reduced-motion: reduce){
    .logo-img{animation:none}
}
h1{
    margin:0;
    font-size:30px;
    font-weight:700;
    letter-spacing:-0.015em;
    /* Расширенный градиент с белым → лавандовый → magenta → purple → белый,
       размер фона 200% — больше текста, чтобы при движении position-а
       было видно "перелив". Cycles 7s ease-in-out alternate. */
    background:linear-gradient(90deg,
        #fff 0%,
        var(--accent-2) 22%,
        var(--accent-pink) 45%,
        var(--accent) 68%,
        #fff 100%);
    background-size:220% 100%;
    background-position:0% 50%;
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
    color:transparent;
    animation:headerFlow 7s ease-in-out infinite alternate;
}
@keyframes headerFlow{
    0%   {background-position:0% 50%}
    100% {background-position:100% 50%}
}
@media (prefers-reduced-motion: reduce){
    h1{animation:none}
}
.tag{
    display:inline-block;
    margin-left:12px;
    padding:3px 10px;
    border-radius:999px;
    background:var(--accent-soft);
    color:var(--accent);
    font-size:13px;
    font-weight:600;
    letter-spacing:0.02em;
    vertical-align:middle;
    border:1px solid rgba(168,85,247,0.18);
}

/* actions растянуты на всё свободное место в шапке — внутри обёртка поиска */
.actions{display:flex;gap:10px;align-items:center;flex:1 1 auto;min-width:0;max-width:640px}
.search-wrap{position:relative;flex:1 1 auto;min-width:0}
.search{
    width:100%;
    background:var(--surface-1);
    border:1px solid var(--border-1);
    /* padding-right больше, чтобы крестик не наезжал на текст */
    padding:11px 38px 11px 38px;
    border-radius:var(--radius-sm);
    color:inherit;
    font-size:14px;
    transition:border-color .15s, background .15s, box-shadow .15s;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a89ec1' stroke-width='2' stroke-linecap='round'><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>");
    background-repeat:no-repeat;
    background-position:12px center;
}
.search:focus{
    outline:none;
    border-color:rgba(168,85,247,0.5);
    background-color:rgba(168,85,247,0.06);
    box-shadow:0 0 28px -6px rgba(168,85,247,0.55);
}
/* Крестик очистки поиска. По умолчанию скрыт; показывается, когда .search-wrap
   имеет класс .has-value (выставляется JS-ом на input). */
.search-clear{
    position:absolute;
    right:8px;top:50%;transform:translateY(-50%);
    width:22px;height:22px;
    border:none;
    background:rgba(255,255,255,0.06);
    color:var(--muted);
    border-radius:50%;
    cursor:pointer;
    display:none;
    align-items:center;justify-content:center;
    padding:0;
    font-size:14px;line-height:1;
    transition:background .15s, color .15s, transform .15s;
}
.search-clear:hover{background:rgba(168,85,247,0.25);color:var(--text);transform:translateY(-50%) scale(1.08)}
.search-wrap.has-value .search-clear{display:flex}

/* ========== Status bar — bento из 3 карточек ==========
   Grid с auto-fit: на широком 3 колонки, на среднем 2, на узком стек.
   Сам контейнер без border/bg — это просто компоновка, "стенки" у карточек. */
.status-bar{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));
    gap:12px;
    padding:0;
    background:none;
    border:none;
}
.bento-card{
    background:linear-gradient(135deg, rgba(168,85,247,0.06), rgba(232,121,249,0.02));
    border:1px solid var(--border-1);
    border-radius:var(--radius);
    padding:14px 16px;
    display:flex;flex-direction:column;gap:6px;
    backdrop-filter:blur(14px) saturate(140%);
    -webkit-backdrop-filter:blur(14px) saturate(140%);
    box-shadow:
        0 4px 24px -8px rgba(168,85,247,0.12),
        inset 0 1px 0 rgba(255,255,255,0.03);
    /* 3D-наклон: переменные tilt-x/y задаются JS на mousemove (rotateX/rotateY
       в небольших градусах). Восстановление транзишеном 0.4s после mouseleave. */
    transform-style:preserve-3d;
    transform:perspective(900px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg));
    transition:border-color .2s, background .25s, transform .35s cubic-bezier(.2,.9,.3,1), box-shadow .25s;
    will-change:transform;
}
.bento-card:hover{
    border-color:rgba(168,85,247,0.28);
    background:linear-gradient(135deg, rgba(168,85,247,0.09), rgba(232,121,249,0.035));
    box-shadow:
        0 6px 32px -8px rgba(168,85,247,0.25),
        inset 0 1px 0 rgba(255,255,255,0.05);
}
.bento-card .card-head{
    display:flex;align-items:center;gap:6px;
    font-size:11px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:0.06em;
    font-weight:700;
}
.bento-card .card-head .ico{width:13px;height:13px;opacity:.7;flex-shrink:0}
.bento-card .card-value{
    font-size:22px;
    font-weight:700;
    color:var(--text);
    letter-spacing:-0.01em;
    line-height:1.15;
    font-variant-numeric:tabular-nums;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.bento-card .card-value.ok{color:var(--ok)}
.bento-card .card-value.warn{color:var(--warn)}
.bento-card .card-value.err{color:var(--err)}
.bento-card .card-value.muted{color:var(--muted)}
.bento-card .card-meta{
    font-size:12px;
    color:var(--muted);
    display:flex;
    flex-wrap:wrap;
    gap:3px 8px;
    align-items:center;
}
.bento-card .card-meta .accent{color:var(--text);font-weight:600;font-variant-numeric:tabular-nums}
.bento-card .card-meta .accent.ok{color:var(--ok)}
.bento-card .card-meta .accent.warn{color:var(--warn)}
.bento-card .card-meta .accent.err{color:var(--err)}
.bento-card .card-meta .sep{opacity:.4;color:var(--muted-2)}
.bento-card .card-meta code{
    background:rgba(255,255,255,0.05);
    padding:1px 6px;border-radius:4px;
    font-family:var(--mono);font-size:11px;
}

/* ========== Sparkline на «Хранилище» ==========
   Мини-график кумулятивного роста объёма по релизным датам файлов (mtime
   = upstream Last-Modified). Встаёт справа от значения «131 GB» в свободное
   пространство — карточка остаётся той же высоты, что и соседние bento.
   SVG-площадь с градиентной заливкой + линия + точка-конец с glow. */
.bento-card.storage-card .storage-row{
    display:flex;
    align-items:center;
    gap:14px;
    min-height:30px;
}
.bento-card.storage-card .storage-row .card-value{
    flex:0 0 auto;
}
.bento-card.storage-card .spark-slot{
    flex:1 1 auto;
    min-width:0;
    height:34px;
    display:block;
    opacity:0.92;
}
.sparkline{
    width:100%;height:100%;
    display:block;
    overflow:visible;  /* чтобы конечная точка с glow не подрезалась */
}
.sparkline .spark-area{
    fill:url(#spark-grad);
    opacity:.8;
}
/* Glow для линии — широкий полупрозрачный stroke под основной линией.
   Раньше был filter:drop-shadow(), но на маке это запускает софтверный
   фильтр и каскадно мерцает нижний body::before. SVG-нативный glow
   рисуется через GPU без артефактов. */
.sparkline .spark-line-glow{
    fill:none;
    stroke:#a855f7;
    stroke-width:4;
    stroke-opacity:0.28;
    stroke-linecap:round;
    stroke-linejoin:round;
}
.sparkline .spark-line{
    fill:none;
    stroke:var(--accent);
    stroke-width:1.5;
    stroke-linejoin:round;
    stroke-linecap:round;
}
.sparkline .spark-dot-glow{
    fill:#f0abfc;
    opacity:0.32;
}
/* Pulse через transform:scale (не через r) — анимация SVG-атрибутов
   на маке/сафари не всегда идёт по GPU, transform всегда. */
.sparkline .spark-dot{
    fill:var(--accent-2);
    transform-box:fill-box;
    transform-origin:center;
    animation:sparkPulse 2.4s ease-in-out infinite;
}
.sparkline.flat .spark-dot{animation:none}
@keyframes sparkPulse{
    0%,100%{transform:scale(1)}
    50%    {transform:scale(1.35)}
}

/* Draw-on эффект: линия рисуется слева направо через stroke-dashoffset,
   заливка проявляется с задержкой, точка появляется с лёгким overshoot.
   Класс .draw-in вешает JS после рендера (если не reduce-motion). */
.sparkline.draw-in .spark-area{
    animation:sparkAreaIn 700ms 280ms ease-out backwards;
}
.sparkline.draw-in .spark-dot{
    animation:sparkDotIn 380ms 820ms cubic-bezier(.2,1.7,.3,1) backwards,
              sparkPulse 2.4s 1200ms ease-in-out infinite;
}
.sparkline.draw-in .spark-dot-glow{
    animation:sparkGlowIn 420ms 880ms ease-out backwards;
}
@keyframes sparkAreaIn{
    from{opacity:0}
    to  {opacity:0.8}
}
@keyframes sparkGlowIn{
    from{opacity:0}
    to  {opacity:0.32}
}
@keyframes sparkDotIn{
    from{opacity:0;transform:scale(0)}
    to  {opacity:1;transform:scale(1)}
}

.bento-card .card-meta .delta-val{
    color:var(--accent-2);
    font-weight:700;
    font-variant-numeric:tabular-nums;
}
@media (prefers-reduced-motion: reduce){
    .sparkline .spark-dot{animation:none}
    .sparkline.draw-in .spark-area,
    .sparkline.draw-in .spark-dot,
    .sparkline.draw-in .spark-dot-glow{animation:none}
}

/* ========== Card ========== */
.card{
    background:var(--surface-1);
    border:1px solid var(--border-1);
    border-radius:var(--radius);
    padding:16px;
    box-shadow:var(--shadow);
    flex:1 1 auto;
    min-height:0; /* критично: без него flex-child с overflow не уменьшается, scroll не работает */
    overflow-y:auto;
}
.card::-webkit-scrollbar{width:10px}
.card::-webkit-scrollbar-track{background:transparent}
.card::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(168,85,247,0.35),rgba(168,85,247,0.1));border-radius:10px}
.card::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,rgba(168,85,247,0.5),rgba(168,85,247,0.2))}

.count{color:var(--muted);font-size:13px;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.count .num{color:var(--text);font-weight:700;font-variant-numeric:tabular-nums}

.list{display:grid;gap:10px}

/* ========== Row ========== */
.row{
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:14px;
    padding:14px 16px;
    border-radius:var(--radius);
    background:var(--surface-1);
    border:1px solid var(--border-1);
    transition:transform .18s cubic-bezier(.2,.9,.2,1), background .18s, border-color .18s, box-shadow .18s;
}
.row:hover{
    transform:translateY(-2px);
    background:var(--surface-2);
    border-color:rgba(168,85,247,0.22);
    box-shadow:
        0 8px 24px rgba(2,8,23,0.4),
        0 0 0 1px rgba(168,85,247,0.06),
        0 0 22px -10px rgba(168,85,247,0.45);
}

/* ========== Бренд-цвета дистрибутивов ==========
   RGB задаётся как тройка чисел через запятую, чтобы можно было использовать
   в rgba() с нужной альфой. Класс .has-distro на .row активирует все эффекты;
   .distro-XXX задаёт конкретный цвет. CSS-переменная каскадирует на .thumb. */
.distro-debian    { --distro-rgb: 194, 24, 91 }     /* Debian red */
.distro-ubuntu    { --distro-rgb: 233, 84, 32 }     /* Ubuntu orange */
.distro-almalinux { --distro-rgb: 10, 153, 112 }    /* AlmaLinux green */
.distro-centos    { --distro-rgb: 147, 34, 121 }    /* CentOS purple */
.distro-proxmox   { --distro-rgb: 229, 112, 0 }     /* Proxmox orange */
.distro-arch      { --distro-rgb: 23, 147, 209 }    /* Arch blue */
.distro-windows   { --distro-rgb: 0, 120, 212 }     /* Windows blue */
.distro-fedora    { --distro-rgb: 60, 110, 180 }    /* Fedora blue (virtio) */

/* Тонкая полоска слева у thumb — мгновенная идентификация даже без чтения имени */
.row.has-distro .thumb{
    box-shadow:
        inset 3px 0 0 rgba(var(--distro-rgb), 0.85),
        inset 0 0 0 1px rgba(255,255,255,0.04);
}
/* Папочный thumb имеет drop shadow — сохраняем его + добавляем полоску */
.row.has-distro .thumb.folder{
    box-shadow:
        inset 3px 0 0 rgba(var(--distro-rgb), 0.85),
        0 4px 12px rgba(168,85,247,0.2);
}

/* На hover вместо общего cyan-glow строка светится в цвет своего дистрибутива */
.row.has-distro:hover{
    border-color:rgba(var(--distro-rgb), 0.22);
    box-shadow:
        0 8px 24px rgba(2,8,23,0.4),
        0 0 0 1px rgba(var(--distro-rgb), 0.08),
        0 0 22px -10px rgba(var(--distro-rgb), 0.45);
}
.row:focus{outline:none;border-color:rgba(168,85,247,0.35);box-shadow:0 0 0 3px rgba(168,85,247,0.12)}
.row.dir-row{cursor:pointer}
.row.menu-open,.row.menu-open:hover{transform:none!important}

.thumb{
    width:44px;height:44px;
    border-radius:11px;
    display:flex;align-items:center;justify-content:center;
    background:var(--surface-2);
    color:var(--accent);
    box-shadow:0 0 0 1px rgba(255,255,255,0.04) inset;
    flex-shrink:0;
}
.thumb.folder{
    background:linear-gradient(135deg,var(--accent),var(--accent-pink));
    color:#012;
    box-shadow:0 4px 12px rgba(168,85,247,0.2);
}
.thumb.missing{background:rgba(248,113,113,0.12);color:var(--err);box-shadow:0 0 0 1px rgba(248,113,113,0.2) inset}
.icon{width:24px;height:24px;display:block;flex-shrink:0}
.thumb.folder .icon{width:22px;height:22px}
/* Базовый размер для всех инлайновых иконок (.ico) — без него SVG рендерится в дефолтные 300×150 */
.ico{width:14px;height:14px;flex-shrink:0;display:inline-block;vertical-align:middle}

.meta{min-width:0;display:flex;flex-direction:column;gap:6px}
.filename{
    font-size:15px;
    font-weight:600;
    letter-spacing:-0.005em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    display:flex;
    align-items:center;
    gap:8px;
}

/* sub: flex-wrap, чтобы хэш мог переноситься на новую строку при нехватке места */
.sub{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:4px 10px;
    font-size:12.5px;
    color:var(--muted);
    min-width:0;
}
.sub .field{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.sub .field .ico{width:13px;height:13px;opacity:.7}
.sub .dot{color:var(--muted-2);opacity:.4;font-weight:700}

.match-badge{
    display:inline-block;margin-left:8px;
    background:var(--accent-soft);color:var(--accent);
    padding:2px 8px;border-radius:999px;
    font-size:11px;font-weight:700;letter-spacing:0.02em;
}
.latest-badge{
    display:inline-block;margin-left:8px;
    background:rgba(74,222,128,0.12);color:var(--ok);
    border:1px solid rgba(74,222,128,0.25);
    padding:1px 8px;border-radius:999px;
    font-size:10.5px;font-weight:700;letter-spacing:0.04em;
    text-transform:uppercase;
    position:relative;
    overflow:hidden;
}
/* Периодический shimmer-проход по бейджу. Большую часть цикла полоска
   "за кадром" (translateX -150%), быстрый сладкий проход — на 30% цикла.
   animation-delay задаётся inline через JS (Math.random() * -5s) — все
   бейджи светятся в разных фазах, выглядит "живым", а не "лампа моргает". */
.latest-badge::after{
    content:'';
    position:absolute;inset:0;
    pointer-events:none;
    background:linear-gradient(110deg,
        transparent 0%, transparent 35%,
        rgba(255,255,255,0.35) 50%,
        transparent 65%, transparent 100%);
    transform:translateX(-150%);
    animation:latestShimmer 5s ease-in-out infinite;
    animation-delay:var(--shimmer-delay, 0s);
}
@keyframes latestShimmer{
    0%, 70% { transform:translateX(-150%) }
    100%    { transform:translateX(150%) }
}
@media (prefers-reduced-motion: reduce){
    .latest-badge::after{animation:none}
}

.btns{display:flex;align-items:center;gap:8px;flex-shrink:0}

/* ========== Buttons ========== */
.primary{
    background:linear-gradient(90deg,var(--accent),var(--accent-2));
    padding:9px 14px;
    border-radius:var(--radius-sm);
    border:none;
    color:#012;
    font-weight:700;
    font-size:13px;
    text-decoration:none;
    transition:transform .12s, box-shadow .12s, filter .12s;
    display:inline-flex;align-items:center;gap:6px;
    cursor:pointer;
    white-space:nowrap;
}
.primary{
    /* Магнит: переменные --mag-x/y устанавливаются из JS на mousemove,
       по умолчанию 0. Hover добавляет базовый translateY -1px поверх. */
    transform:translate(var(--mag-x, 0px), var(--mag-y, 0px));
}
.primary:hover{
    transform:translate(var(--mag-x, 0px), calc(-1px + var(--mag-y, 0px)));
    box-shadow:0 8px 24px rgba(168,85,247,0.4),0 0 24px rgba(232,121,249,0.2);
    filter:brightness(1.05)
}
.ghost{
    transform:translate(var(--mag-x, 0px), var(--mag-y, 0px));
}
.primary:active{transform:translateY(0)}
.ghost{
    background:transparent;
    padding:9px 12px;
    border-radius:var(--radius-sm);
    border:1px solid var(--border-1);
    color:var(--muted);
    cursor:pointer;
    font-size:13px;
    transition:background .15s, color .15s, border-color .15s;
    white-space:nowrap;
}
.ghost:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-2)}

/* ========== Dropdown ========== */
.copy-group{position:relative}
.dd-menu{
    display:none;
    position:absolute;
    right:0;
    top:calc(100% + 8px);
    min-width:240px;
    background:#0a1628;
    border:1px solid var(--border-2);
    border-radius:var(--radius-sm);
    padding:6px;
    box-shadow:0 12px 36px rgba(2,8,23,0.7);
    z-index:9999;
}
.dd-menu.show{display:block;animation:fadeIn .15s ease}
.dd-menu.flip-up{top:auto;bottom:calc(100% + 8px)}
.dd-item{
    display:flex;align-items:center;gap:10px;
    padding:10px 12px;
    border-radius:8px;
    color:var(--muted);
    text-decoration:none;
    font-size:13px;
    cursor:pointer;
    transition:background .12s,color .12s;
}
.dd-item:hover{background:var(--surface-2);color:var(--text)}
.dd-item .ico{width:14px;height:14px;flex-shrink:0;opacity:.7}

/* ========== Children expand ========== */
.children{
    margin-top:6px;
    margin-left:58px;
    display:grid;
    gap:8px;
    overflow:hidden;
    max-height:0;
    opacity:0;
    transition:max-height 320ms cubic-bezier(.2,.9,.2,1), opacity 220ms ease;
}
.children.open{opacity:1;overflow:visible}
.children .row{padding:12px 14px;background:rgba(255,255,255,0.015)}
.toggle-arrow{transition:transform 220ms ease;display:inline-block;font-size:14px}
.toggle-arrow.expanded{transform:rotate(180deg)}

/* ========== Hash chip ========== */
.hash-clickable{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:3px 10px;
    border-radius:7px;
    font-family:var(--mono);
    font-size:11.5px;
    font-weight:500;
    color:var(--accent);
    background:var(--accent-soft);
    border:1px solid rgba(168,85,247,0.15);
    cursor:pointer;
    transition:all .15s ease;
    user-select:all;
    word-break:break-all;
    max-width:100%;
    line-height:1.4;
}
.hash-clickable:hover{
    color:var(--accent-2);
    background:rgba(168,85,247,0.18);
    border-color:rgba(168,85,247,0.3);
}
.hash-clickable.copied{color:var(--ok);background:rgba(74,222,128,0.12);border-color:rgba(74,222,128,0.3)}
.hash-clickable .ico{width:11px;height:11px;opacity:.6;flex-shrink:0}
.hash-not-ready{
    color:var(--muted-2);
    font-style:italic;
    font-size:12px;
    font-family:var(--mono);
}

/* ========== Toast ========== */
.toast{
    position:fixed;right:20px;bottom:20px;
    background:#0a1628;
    border:1px solid var(--border-2);
    padding:11px 16px;
    border-radius:var(--radius-sm);
    color:var(--text);
    box-shadow:0 12px 36px rgba(2,8,23,0.7);
    display:none;z-index:200;
    font-size:13px;
}
.toast.show{display:flex;align-items:center;gap:8px;animation:fadeIn .2s ease}
.toast .ico{width:16px;height:16px;color:var(--ok)}

/* ========== Skeleton ========== */
.skel-row{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:14px;padding:14px 16px;border-radius:var(--radius);background:var(--surface-1);border:1px solid var(--border-1)}
.skel-thumb{width:44px;height:44px;border-radius:11px;background:linear-gradient(90deg,rgba(255,255,255,0.02),rgba(255,255,255,0.06));}
.skel-meta{display:flex;flex-direction:column;gap:8px}
.skel-line{height:13px;border-radius:6px;background:linear-gradient(90deg,#0b1014 0%, #1b2a36 50%, #0b1014 100%);background-size:200% 100%;animation:shimmer 1.1s linear infinite}
.skel-sub{height:11px;width:60%;border-radius:6px;background:linear-gradient(90deg,#071016 0%, #12202a 50%, #071016 100%);background-size:200% 100%;animation:shimmer 1.1s linear infinite}

/* ========== Tooltip ========== */
.tooltip{position:relative}
.tooltip[data-title]:hover::after{
    content:attr(data-title);
    position:absolute;bottom:calc(100% + 8px);left:50%;
    transform:translateX(-50%);
    background:rgba(2,8,23,0.95);color:var(--text);
    padding:6px 10px;border-radius:6px;
    font-size:12px;white-space:nowrap;
    pointer-events:none;
    border:1px solid var(--border-2);
    z-index:100;
}

/* ========== Missing block ========== */
.missing-block{
    margin-top:18px;
    padding:14px 16px;
    border-radius:var(--radius);
    background:linear-gradient(135deg, rgba(248,113,113,0.04), rgba(248,113,113,0.02));
    border:1px dashed rgba(248,113,113,0.25);
}
.missing-block h2{
    margin:0 0 12px 0;
    font-size:14px;
    font-weight:600;
    color:var(--err);
    display:flex;align-items:center;gap:8px;
}
.missing-block h2 .hint{margin-left:auto;font-size:12px;font-weight:400;color:var(--muted)}
.missing-block .row{background:rgba(255,255,255,0.015)}
.missing-block .row:hover{transform:none;background:rgba(255,255,255,0.02)}

/* ========== History block (admin) ========== */
.history-block{
    margin-top:14px;
    padding:12px 16px;
    border-radius:var(--radius);
    background:var(--surface-1);
    border:1px solid var(--border-1);
}
.history-block .hist-header{
    display:flex;align-items:center;gap:10px;
    cursor:pointer;user-select:none;
    color:var(--muted);
    font-size:13px;font-weight:600;
}
.history-block .hist-header:hover{color:var(--text)}
.history-block .hist-header .toggle-arrow{transition:transform 220ms ease}
.history-block.open .hist-header .toggle-arrow{transform:rotate(180deg)}
.history-block .hist-list{
    margin-top:0;
    max-height:0;
    overflow:hidden;
    transition:max-height 260ms cubic-bezier(.2,.9,.2,1);
    display:flex;flex-direction:column;gap:6px;
}
.history-block.open .hist-list{max-height:600px;margin-top:10px;overflow-y:auto}
.hist-row{display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:8px;font-size:12.5px;background:rgba(255,255,255,0.01)}
.hist-row .hist-ts{color:var(--muted-2);font-family:var(--mono);font-size:11.5px;white-space:nowrap}
.hist-row .hist-kind{font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px;text-transform:uppercase;letter-spacing:0.04em;flex-shrink:0}
.hist-row .hist-kind.updated{color:var(--ok);background:rgba(74,222,128,0.1);border:1px solid rgba(74,222,128,0.2)}
.hist-row .hist-kind.cleanup{color:var(--muted);background:rgba(255,255,255,0.04);border:1px solid var(--border-1)}
.hist-row .hist-kind.failed {color:var(--err);background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.25)}
.hist-row .hist-file{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}
.hist-row .hist-extra{color:var(--muted-2);font-size:11px;margin-left:auto;white-space:nowrap}

/* ========== Animations ========== */
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer{to{background-position:-200% 0}}

/* Stagger-вход строк при первичной загрузке: каждая строка появляется со сдвигом
   ~25 мс через JS-инлайн animation-delay. После первого рендера флаг сбрасывается
   и при поиске/фильтрации стаггера нет (чтобы не лагало). */
.row-stagger{animation:rowIn 0.42s cubic-bezier(.2,.9,.2,1) backwards}
@keyframes rowIn{
    from{opacity:0;transform:translateY(10px)}
    to{opacity:1;transform:translateY(0)}
}

/* Inline-фидбэк копирования: маленький "✓ скопировано" появляется прямо рядом
   с хэшем на ~1 сек после клика. Toast снизу всё равно остаётся (для accessibility). */
.inline-check{
    display:inline-flex;align-items:center;gap:4px;
    margin-left:6px;
    padding:2px 8px;
    border-radius:999px;
    background:rgba(74,222,128,0.15);
    border:1px solid rgba(74,222,128,0.3);
    color:var(--ok);
    font-size:11px;font-weight:600;
    vertical-align:middle;
    animation:inlineCheckIn .22s cubic-bezier(.2,.9,.2,1);
    transition:opacity .25s, transform .25s;
}
.inline-check.fading{opacity:0;transform:translateX(6px) scale(.9)}
.inline-check svg{width:11px;height:11px;flex-shrink:0}
@keyframes inlineCheckIn{
    from{opacity:0;transform:scale(.85)}
    to{opacity:1;transform:scale(1)}
}

/* ========== Highlight (search) ========== */
mark{background:rgba(168,85,247,0.25);color:var(--accent-2);padding:0 2px;border-radius:3px}

/* ========== Empty state ==========
   Иллюстрированное "ничего не найдено": SVG-лупа с пунктирным внутренним кругом
   медленно пульсирует, plus заголовок и подсказка. Шрифт-стек как в основном. */
.empty-state{
    text-align:center;
    padding:50px 24px 60px;
    color:var(--muted);
    display:flex;flex-direction:column;align-items:center;gap:6px;
}
.empty-state .empty-icon{
    width:104px;height:104px;
    color:var(--accent);
    opacity:0.35;
    margin-bottom:14px;
    animation:emptyPulse 3.6s ease-in-out infinite;
}
.empty-state .empty-icon .dashed{
    animation:emptyRotate 18s linear infinite;
    transform-origin:80px 80px;
}
@keyframes emptyPulse{
    0%,100%{opacity:0.32;transform:scale(1)}
    50%    {opacity:0.50;transform:scale(1.04)}
}
@keyframes emptyRotate{
    from{transform:rotate(0)}
    to  {transform:rotate(360deg)}
}
.empty-state .empty-title{
    font-size:18px;font-weight:600;
    color:var(--text);
    margin-top:4px;
}
.empty-state .empty-meta{
    font-size:13px;color:var(--muted);
    max-width:460px;line-height:1.55;
}
.empty-state .empty-meta code{
    background:rgba(255,255,255,0.05);
    padding:1px 6px;border-radius:4px;
    font-family:var(--mono);font-size:11.5px;color:var(--accent-2);
}
.empty-state .empty-hint{
    margin-top:14px;
    font-size:12px;color:var(--muted-2);
}
.empty-state .empty-hint kbd{
    display:inline-block;
    padding:1px 7px;
    border:1px solid var(--border-2);
    border-radius:5px;
    background:rgba(255,255,255,0.04);
    font-family:var(--mono);font-size:11px;
    color:var(--text);
}
@media (prefers-reduced-motion: reduce){
    .empty-state .empty-icon,
    .empty-state .empty-icon .dashed{animation:none}
}

/* ========== Freshness-индикатор у даты файла ==========
   Подкрашенная точка показывает возраст релиза (mtime теперь = upstream Last-Modified,
   так что это реально "сколько прошло с публикации"). Свежие пульсируют. */
.fresh-dot{
    display:inline-block;
    width:8px;height:8px;
    border-radius:50%;
    flex-shrink:0;
    vertical-align:middle;
    margin:0 2px;
}
.fresh-dot.fresh{
    background:var(--ok);
    box-shadow:0 0 0 0 rgba(74,222,128,0.45);
    animation:pulseFresh 2.4s ease-in-out infinite;
}
.fresh-dot.aging{background:var(--warn);box-shadow:0 0 4px rgba(251,191,36,0.4)}
.fresh-dot.old  {background:var(--muted-2)}
@keyframes pulseFresh{
    0%,100%{box-shadow:0 0 0 0 rgba(74,222,128,0.45)}
    50%    {box-shadow:0 0 8px 1px rgba(74,222,128,0.7)}
}
/* Pulse не вечен — приостанавливаем когда вкладка не активна, экономим CPU */
@media (prefers-reduced-motion: reduce){
    .fresh-dot.fresh{animation:none}
}

/* ========== Responsive ========== */
@media (max-width:880px){
    .container{margin:16px auto;padding:0 14px 20px}
    h1{font-size:20px}
    .row{padding:12px 14px;gap:12px}
    .thumb{width:40px;height:40px}
    .filename{font-size:14px}
    .children{margin-left:0}
}
@media (max-width:640px){
    header{flex-direction:column;align-items:stretch}
    .brand{justify-content:flex-start}
    .actions{width:100%;max-width:none}
    .row{grid-template-columns:auto 1fr;grid-template-areas:"thumb meta" "btns btns"}
    .thumb{grid-area:thumb}
    .meta{grid-area:meta}
    .btns{grid-area:btns;justify-content:flex-end;flex-wrap:wrap}
    .dd-menu{right:0}
}
</style>
</head>
<body>
<svg style="display:none" aria-hidden="true">
  <symbol id="ic-folder" viewBox="0 0 24 24"><path d="M10 4H4a2 2 0 0 0-2 2v2h20V6a2 2 0 0 0-2-2h-8l-2-2zM2 10v8a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-8H2z"/></symbol>
  <symbol id="ic-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM14 3.5L20.5 10H14V3.5z"/></symbol>
  <symbol id="ic-iso" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="currentColor"/></symbol>
  <symbol id="ic-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
  <symbol id="ic-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
  <symbol id="ic-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></symbol>
  <symbol id="ic-warn" viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></symbol>
  <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
  <symbol id="ic-hdd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol>
  <symbol id="ic-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></symbol>
  <symbol id="ic-term" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></symbol>

  <!-- Иконки по типам файлов -->
  <symbol id="ic-exe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polygon points="9.5 13 9.5 19 15 16" fill="currentColor" stroke="none"/></symbol>
  <symbol id="ic-archive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="13" x2="14" y2="13"/></symbol>
  <symbol id="ic-package" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></symbol>
  <symbol id="ic-image" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></symbol>
  <symbol id="ic-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></symbol>
  <symbol id="ic-code" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></symbol>
  <symbol id="ic-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"/></symbol>
  <symbol id="ic-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></symbol>
  <symbol id="ic-vm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></symbol>
  <symbol id="ic-audio" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
  <symbol id="ic-video" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></symbol>
</svg>

    <div class="container">
        <header>
            <div class="brand">
                <img src="favicon.ico" alt="Logo" class="logo-img">
                <h1>iso-файлы<span class="tag">mirror</span></h1>
            </div>
            <div class="actions">
                <div class="search-wrap" id="search-wrap">
                    <input id="search" class="search" placeholder="Поиск по имени или хэшу (Ctrl+K)" aria-label="Поиск по имени или хэшу" />
                    <button class="search-clear" id="search-clear" type="button" aria-label="Очистить">×</button>
                </div>
            </div>
        </header>

        <div id="status-bar" class="status-bar"></div>

        <div class="card">
            <div id="count" class="count">Загружается...</div>
            <div id="list" class="list" role="list"></div>

            <div id="missing-block" class="missing-block" style="display:none">
                <h2>
                    <svg class="icon" style="width:18px;height:18px" aria-hidden="true"><use href="#ic-warn"></use></svg>
                    <span id="missing-title">Отсутствующие файлы</span>
                    <span class="hint">— ожидаются по конфигу, но не найдены на диске</span>
                </h2>
                <div id="missing-list" class="list"></div>
            </div>

            <div id="history-block" class="history-block" style="display:none">
                <div class="hist-header" id="hist-toggle">
                    <svg class="ico" aria-hidden="true"><use href="#ic-clock"></use></svg>
                    <span id="hist-title">История обновлений</span>
                    <span class="toggle-arrow" style="margin-left:auto">▾</span>
                </div>
                <div id="hist-list" class="hist-list"></div>
            </div>
        </div>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <script>
        const FILES = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> || [];
        const MISSING = <?php echo json_encode($missing, JSON_UNESCAPED_UNICODE); ?> || [];
        const LAST_RUN = <?php echo json_encode($lastRun, JSON_UNESCAPED_UNICODE); ?>;
        const HISTORY = <?php echo json_encode($history, JSON_UNESCAPED_UNICODE); ?> || [];
        const TOTAL_FILES = <?php echo (int)$totalFiles; ?>;
        const TOTAL_SIZE = <?php echo (int)$totalSize; ?>;
        const STORAGE_SERIES = <?php echo json_encode($storageSeries, JSON_UNESCAPED_UNICODE); ?> || [];
        const STORAGE_DELTA_7D  = <?php echo (int)$delta7d;  ?>;
        const STORAGE_DELTA_30D = <?php echo (int)$delta30d; ?>;
        const webDir = '<?php echo addslashes($webDir); ?>';

        function escapeHtml(unsafe){return String(unsafe).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]||m));}
        function humanSize(bytes){if(bytes===0)return'0 B';const u=['B','KB','MB','GB','TB'];let i=0,n=bytes;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (n<10?n.toFixed(1):Math.round(n))+' '+u[i];}
        function fmtDate(ts){const d=new Date(ts*1000);return d.toLocaleString('ru-RU',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});}
        function fmtIso(iso){if(!iso)return'—';try{return new Date(iso).toLocaleString('ru-RU');}catch(e){return iso;}}
        function relativeTime(iso){
            if(!iso) return '';
            try{
                const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
                if(diff < 60) return 'только что';
                if(diff < 3600) return Math.floor(diff/60) + ' мин назад';
                if(diff < 86400) return Math.floor(diff/3600) + ' ч назад';
                if(diff < 604800) return Math.floor(diff/86400) + ' дн назад';
                return Math.floor(diff/604800) + ' нед назад';
            } catch(e) { return ''; }
        }
        // width/height в атрибутах — защита от случая, когда CSS .ico ещё не подгружен
        // (без них браузер по спецификации SVG рисует svg в 300×150 px).
        function svgIcon(id, cls=''){return `<svg class="ico ${cls}" width="14" height="14" aria-hidden="true"><use href="#${id}"></use></svg>`;}

        const _reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Магнитное "притяжение" к курсору: элемент слегка смещается на mousemove.
        // На mouseleave сбрасывается, CSS-транзишен плавно возвращает.
        // strength=5 → максимальное смещение ~5px по каждой оси. Не уехать за бордюр.
        function attachMagnetic(el, strength = 5){
            if (_reduceMotion) return;
            el.addEventListener('mousemove', e => {
                const r = el.getBoundingClientRect();
                const x = ((e.clientX - r.left) / r.width  - 0.5) * strength * 2;
                const y = ((e.clientY - r.top)  / r.height - 0.5) * strength * 2;
                el.style.setProperty('--mag-x', x.toFixed(1) + 'px');
                el.style.setProperty('--mag-y', y.toFixed(1) + 'px');
            });
            el.addEventListener('mouseleave', () => {
                el.style.removeProperty('--mag-x');
                el.style.removeProperty('--mag-y');
            });
        }

        // 3D-наклон элемента к курсору: rotateX/rotateY в небольших градусах.
        // maxDeg=3 → максимум 3° по каждой оси. Лёгкий parallax-эффект.
        function attachTilt(el, maxDeg = 3){
            if (_reduceMotion) return;
            el.addEventListener('mousemove', e => {
                const r = el.getBoundingClientRect();
                const x = ((e.clientX - r.left) / r.width  - 0.5);  // -0.5..0.5
                const y = ((e.clientY - r.top)  / r.height - 0.5);
                el.style.setProperty('--tilt-x', (-y * maxDeg * 2).toFixed(2) + 'deg');
                el.style.setProperty('--tilt-y', ( x * maxDeg * 2).toFixed(2) + 'deg');
            });
            el.addEventListener('mouseleave', () => {
                el.style.removeProperty('--tilt-x');
                el.style.removeProperty('--tilt-y');
            });
        }

        // Анимация числа 0 → target с easeOutQuart за durationMs.
        // formatter(value) — как форматировать число (humanSize для байт, Math.round для счётчиков).
        function animateNumber(el, target, durationMs, formatter){
            if (_reduceMotion) { el.textContent = formatter(target); return; }
            const start = performance.now();
            el.textContent = formatter(0);
            function step(now){
                const t = Math.min(1, (now - start) / durationMs);
                const eased = 1 - Math.pow(1 - t, 4);  // easeOutQuart
                el.textContent = formatter(target * eased);
                if (t < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        /* Sparkline для карточки «Хранилище».
         * series: массив {ts: unix-sec, total: cumulative-bytes}.
         * Рисуем кумулятивный график на 90-дневном окне (или весь ряд если он короче).
         * Glow для линии и точки — SVG-нативный (дублирующие элементы с
         * большим радиусом / шире stroke), а не filter:drop-shadow — он на
         * маке/сафари каскадно мерцает нижние слои. */
        function renderSparkline(host, series){
            if (!host || !Array.isArray(series) || series.length < 2) return;
            const W = 280, H = 36, padT = 4, padB = 4;
            const now = Math.floor(Date.now() / 1000);
            const windowSec = 86400 * 90;
            const fromTs = Math.max(now - windowSec, series[0].ts);
            // Точки, нормализованные в координаты SVG (viewBox W×H).
            // Если все точки старше окна — показываем последние 8 (минимум контекста).
            let pts = series.filter(p => p.ts >= fromTs);
            if (pts.length < 2) pts = series.slice(-Math.min(8, series.length));
            const minTs = pts[0].ts, maxTs = pts[pts.length - 1].ts;
            const span = Math.max(1, maxTs - minTs);
            const maxTotal = pts[pts.length - 1].total;
            const minTotal = pts[0].total;
            const range = Math.max(1, maxTotal - minTotal);

            const coords = pts.map(p => {
                const x = ((p.ts - minTs) / span) * (W - 4) + 2;
                const y = H - padB - ((p.total - minTotal) / range) * (H - padT - padB);
                return [x, y];
            });
            // Если в окне 1 точка после фильтра — рисуем плоскую линию по центру
            const isFlat = (range <= 1);
            const line = coords.map((c,i) => (i===0?'M':'L') + c[0].toFixed(1) + ',' + c[1].toFixed(1)).join(' ');
            const area = line + ` L${coords[coords.length-1][0].toFixed(1)},${H} L${coords[0][0].toFixed(1)},${H} Z`;
            const lastX = coords[coords.length-1][0];
            const lastY = coords[coords.length-1][1];

            const drawClass = _reduceMotion ? '' : ' draw-in';

            host.innerHTML = `
                <svg class="sparkline${isFlat ? ' flat' : ''}${drawClass}" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="spark-grad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%"   stop-color="#a855f7" stop-opacity="0.45"/>
                            <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path class="spark-area" d="${area}"/>
                    <path class="spark-line-glow" d="${line}"/>
                    <path class="spark-line" d="${line}"/>
                    <circle class="spark-dot-glow" cx="${lastX.toFixed(1)}" cy="${lastY.toFixed(1)}" r="6"/>
                    <circle class="spark-dot"      cx="${lastX.toFixed(1)}" cy="${lastY.toFixed(1)}" r="2.6"/>
                </svg>`;

            // Draw-on: анимируем stroke-dashoffset у линии и её glow-двойника.
            // CSS-классу .draw-in уже отдали area и точки (delay + ease в CSS),
            // а для линии нужна JS-длина пути (getTotalLength), оттуда раздаём
            // dasharray=length, dashoffset=length → transition к 0.
            if (!_reduceMotion) {
                const lineEl = host.querySelector('.spark-line');
                const glowEl = host.querySelector('.spark-line-glow');
                if (lineEl && typeof lineEl.getTotalLength === 'function') {
                    const len = lineEl.getTotalLength();
                    [lineEl, glowEl].forEach(p => {
                        if (!p) return;
                        p.style.strokeDasharray  = len + ' ' + len;
                        p.style.strokeDashoffset = String(len);
                        p.style.transition = 'none';
                    });
                    // Принудительный reflow перед навешиванием transition,
                    // иначе браузер схлопнет старт и конец в один кадр.
                    void lineEl.getBoundingClientRect();
                    requestAnimationFrame(() => {
                        [lineEl, glowEl].forEach(p => {
                            if (!p) return;
                            p.style.transition = 'stroke-dashoffset 950ms cubic-bezier(.4,0,.2,1)';
                            p.style.strokeDashoffset = '0';
                        });
                    });
                }
            }
        }

        function renderStatusBar(){
            const el = document.getElementById('status-bar');
            const has = LAST_RUN && typeof LAST_RUN.total === 'number';

            // ===== Карточка 1: Хранилище =====
            // Layout: head | [value-row: 131 GB + spark-slot] | [meta-row: count + missing? + delta?]
            // Дельты слиты в meta inline — отдельной строки больше нет.
            const missingChip = MISSING.length
                ? `<span class="sep">•</span><span class="accent warn">${MISSING.length}</span><span>отсутствует</span>`
                : '';
            const deltaInline = (STORAGE_DELTA_30D > 0)
                ? `<span class="sep">•</span><span>прирост</span>` +
                  `<span class="delta-val">+${humanSize(STORAGE_DELTA_30D)}</span><span>за 30д</span>` +
                  (STORAGE_DELTA_7D > 0 && STORAGE_DELTA_7D !== STORAGE_DELTA_30D
                      ? `<span class="sep">•</span><span class="delta-val">+${humanSize(STORAGE_DELTA_7D)}</span><span>за 7д</span>`
                      : '')
                : '';
            const card1 = `
                <div class="bento-card storage-card">
                    <div class="card-head">${svgIcon('ic-hdd')}<span>Хранилище</span></div>
                    <div class="storage-row">
                        <div class="card-value" data-anim="size">${humanSize(TOTAL_SIZE)}</div>
                        <div class="spark-slot" data-spark-host></div>
                    </div>
                    <div class="card-meta">
                        <span class="accent" data-anim="files">${TOTAL_FILES}</span>
                        <span>файл(ов)</span>
                        ${missingChip}
                        ${deltaInline}
                    </div>
                </div>`;

            // ===== Карточка 2: Состояние =====
            let stateValue = 'не запускалось';
            let stateClass = 'muted';
            let stateMeta  = '<span>запустите <code>php update_iso.php</code></span>';
            if (LAST_RUN && LAST_RUN.fatal) {
                stateValue = 'FATAL';
                stateClass = 'err';
                stateMeta = `<span style="color:var(--err)">${escapeHtml(LAST_RUN.fatal)}</span>`;
            } else if (has) {
                if (LAST_RUN.failed > 0) {
                    stateValue = LAST_RUN.failed + ' ошибк(и)';
                    stateClass = 'err';
                } else if (LAST_RUN.updated > 0) {
                    stateValue = LAST_RUN.updated + ' обновлено';
                    stateClass = 'ok';
                } else {
                    stateValue = 'актуально';
                    stateClass = 'ok';
                }
                const parts = [];
                if (LAST_RUN.up_to_date > 0) parts.push(`<span class="accent">${LAST_RUN.up_to_date}</span><span>актуально</span>`);
                if (LAST_RUN.updated > 0)    parts.push(`<span class="accent ok">${LAST_RUN.updated}</span><span>обновлено</span>`);
                if (LAST_RUN.skipped > 0)    parts.push(`<span class="accent">${LAST_RUN.skipped}</span><span>пропущено</span>`);
                if (LAST_RUN.failed > 0)     parts.push(`<span class="accent err">${LAST_RUN.failed}</span><span>ошибки</span>`);
                stateMeta = parts.length
                    ? parts.join('<span class="sep">•</span>')
                    : '<span>—</span>';
            }
            const card2 = `
                <div class="bento-card">
                    <div class="card-head">${svgIcon('ic-check')}<span>Состояние</span></div>
                    <div class="card-value ${stateClass}">${stateValue}</div>
                    <div class="card-meta">${stateMeta}</div>
                </div>`;

            // ===== Карточка 3: Последняя проверка =====
            let checkValue = 'нет данных';
            let checkMeta  = '<span style="opacity:.7">—</span>';
            let checkCls   = 'muted';
            if (LAST_RUN) {
                const ts  = LAST_RUN.finished_at || LAST_RUN.started_at;
                const rel = relativeTime(ts);
                checkValue = rel || fmtIso(ts);
                checkCls = '';
                const metaParts = [`<span>${fmtIso(ts)}</span>`];
                if (typeof LAST_RUN.duration_s === 'number') {
                    metaParts.push(`<span class="sep">•</span><span>заняла <span class="accent">${LAST_RUN.duration_s}</span> сек</span>`);
                }
                checkMeta = metaParts.join('');
            }
            const card3 = `
                <div class="bento-card">
                    <div class="card-head">${svgIcon('ic-clock')}<span>Последняя проверка</span></div>
                    <div class="card-value ${checkCls}">${checkValue}</div>
                    <div class="card-meta">${checkMeta}</div>
                </div>`;

            el.innerHTML = card1 + card2 + card3;

            // Counter-up на тоталах (один раз при загрузке)
            const sizeEl  = el.querySelector('[data-anim="size"]');
            const filesEl = el.querySelector('[data-anim="files"]');
            if (sizeEl)  animateNumber(sizeEl,  TOTAL_SIZE,  650, humanSize);
            if (filesEl) animateNumber(filesEl, TOTAL_FILES, 650, n => Math.round(n).toString());

            // Sparkline на карточке «Хранилище»
            const sparkHost = el.querySelector('[data-spark-host]');
            if (sparkHost) renderSparkline(sparkHost, STORAGE_SERIES);

            // 3D-tilt на каждой bento-карточке
            el.querySelectorAll('.bento-card').forEach(c => attachTilt(c, 2.5));
        }

        function renderMissing(){
            const block = document.getElementById('missing-block');
            const list = document.getElementById('missing-list');
            const title = document.getElementById('missing-title');
            list.innerHTML = '';
            if(!Array.isArray(MISSING) || MISSING.length === 0){
                block.style.display = 'none';
                return;
            }
            block.style.display = '';
            title.textContent = `Отсутствующие файлы (${MISSING.length})`;
            MISSING.forEach(m => {
                const row = document.createElement('div'); row.className = 'row';
                const thumb = document.createElement('div'); thumb.className = 'thumb missing'; thumb.innerHTML = '<svg class="icon" width="24" height="24" aria-hidden="true"><use href="#ic-warn"></use></svg>';
                const meta = document.createElement('div'); meta.className = 'meta';
                const name = document.createElement('div'); name.className = 'filename'; name.textContent = m.name;
                const sub = document.createElement('div'); sub.className = 'sub';
                const where = document.createElement('span'); where.className='field';
                where.innerHTML = `<span style="opacity:.7">${m.subdir ? 'files/'+escapeHtml(m.subdir)+'/' : 'files/'}</span>`;
                const arrow = document.createElement('span'); arrow.className='dot'; arrow.textContent='←';
                const remote = document.createElement('span'); remote.className='field'; remote.textContent = m.remote; remote.style.color='var(--muted)';
                remote.title = m.remote;
                sub.appendChild(where); sub.appendChild(arrow); sub.appendChild(remote);
                meta.appendChild(name); meta.appendChild(sub);
                row.appendChild(thumb); row.appendChild(meta);
                list.appendChild(row);
            });
        }

        function highlightSnippet(name, q){
            const raw = String(name);
            if(!q) return {html: escapeHtml(raw), count: 0};
            const L = raw.toLowerCase();
            const qL = q.toLowerCase();
            const matches = L.match(new RegExp(qL.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'gi')) || [];
            const count = matches.length;
            const idx = L.indexOf(qL);
            if(idx === -1) return {html: escapeHtml(raw), count};
            const start = Math.max(0, idx - 12);
            const end = Math.min(raw.length, idx + q.length + 12);
            let snippet = raw.slice(start, end);
            if(start > 0) snippet = '…' + snippet;
            if(end < raw.length) snippet = snippet + '…';
            const esc = escapeHtml(snippet);
            const regex = new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')', 'ig');
            return {html: esc.replace(regex, '<mark>$1</mark>'), count};
        }

        // Карта расширений на ID иконки. Чтобы добавить новый тип — допиши сюда расширение.
        const FILE_ICON_MAP = {
            'ic-iso':     ['iso','img','raw','dmg'],
            'ic-vm':      ['vhd','vhdx','vmdk','qcow','qcow2','ova','ovf','vdi'],
            'ic-exe':     ['exe','msi','msu','appx','msix','com','bat','cmd'],
            'ic-archive': ['zip','7z','rar','tar','gz','tgz','bz2','tbz','xz','txz','zst','lz','lzma','arj','cab','wim'],
            'ic-package': ['deb','rpm','apk','snap','flatpak','pkg','crx','xpi'],
            'ic-image':   ['jpg','jpeg','png','gif','webp','svg','bmp','tiff','tif','ico','heic','avif'],
            'ic-doc':     ['pdf','doc','docx','odt','rtf','epub','xls','xlsx','ods','ppt','pptx','odp'],
            'ic-text':    ['txt','md','log','readme','license','changelog','nfo','csv','tsv'],
            'ic-code':    ['php','js','mjs','cjs','ts','jsx','tsx','py','rb','go','rs','c','cpp','cc','h','hpp','cs','java','kt','swift','sh','bash','zsh','ps1','vbs','json','yaml','yml','xml','html','htm','css','scss','toml','ini','conf','cfg','sql'],
            'ic-key':     ['sha256','sha1','sha512','md5','sig','asc','gpg','pem','crt','cer','key','pub','sign','sums'],
            'ic-audio':   ['mp3','wav','flac','ogg','m4a','aac','opus','wma'],
            'ic-video':   ['mp4','mkv','avi','mov','webm','wmv','flv','m4v'],
        };
        const _extToIcon = (() => {
            const m = new Map();
            for (const [icon, exts] of Object.entries(FILE_ICON_MAP)) {
                for (const e of exts) m.set(e, icon);
            }
            return m;
        })();
        function fileIcon(name, isDir){
            const id = isDir ? 'ic-folder' : (_extToIcon.get((name.split('.').pop()||'').toLowerCase()) || 'ic-file');
            return `<svg class="icon" width="24" height="24" aria-hidden="true"><use href="#${id}"></use></svg>`;
        }

        // Определяет дистрибутив по имени файла/папки для бренд-окраски строки.
        // Возвращает класс-суффикс (debian/ubuntu/...) или null.
        function detectDistro(name){
            const n = name.toLowerCase();
            if (n.startsWith('debian'))    return 'debian';
            if (n.startsWith('ubuntu'))    return 'ubuntu';
            if (n.startsWith('almalinux') || n.startsWith('alma')) return 'almalinux';
            if (n.startsWith('centos'))    return 'centos';
            if (n.startsWith('proxmox'))   return 'proxmox';
            if (n.startsWith('arch'))      return 'arch';
            if (n.startsWith('windows') || n.startsWith('winpe') || n.startsWith('langpaks_win')) return 'windows';
            if (n.startsWith('qemu') || n.includes('virtio')) return 'fedora';
            return null;
        }
        function applyDistroClass(elem, name){
            const d = detectDistro(name);
            if (d) elem.classList.add('has-distro', 'distro-' + d);
        }

        function copyToClipboard(text){
            if(navigator.clipboard){
                navigator.clipboard.writeText(text).then(()=>showToast('Скопировано в буфер'));
            } else {
                const t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();
                try{document.execCommand('copy');showToast('Скопировано в буфер');}catch(e){showToast('Не удалось скопировать');}
                document.body.removeChild(t);
            }
        }

        // Сжатый вид: "sha256:dec49008…f1ca5d9" — показываем по 8 символов с начала и с конца,
        // полный — в нативном title (hover) и копируется по клику.
        function shortenHash(full){
            if(!full || !full.startsWith('sha256:')) return full;
            const hex = full.slice(7);
            if (hex.length < 20) return full;
            return 'sha256:' + hex.slice(0, 8) + '…' + hex.slice(-7);
        }

        function createClickableHash(hashValue) {
            if (!hashValue || hashValue === 'sha256:not_computed_yet') {
                const span = document.createElement('span');
                span.className = 'hash-not-ready';
                span.textContent = 'sha256: вычисляется...';
                return span;
            }
            const span = document.createElement('span');
            span.className = 'hash-clickable';
            // Нативный title с переводом строки: полный хэш видно при наведении
            span.title = hashValue + '\n(клик — копировать)';
            span.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#ic-copy"></use></svg>';
            const txt = document.createElement('span');
            txt.textContent = shortenHash(hashValue);
            txt.style.fontFamily = 'var(--mono)';
            span.appendChild(txt);

            span.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                copyToClipboard(hashValue.replace('sha256:', ''));
                span.classList.add('copied');
                setTimeout(()=>span.classList.remove('copied'),350);
                showInlineCheck(span);   // мини-фидбэк рядом с хэшем
            });
            return span;
        }

        // Маленькая "✓ скопировано" пилюля рядом с элементом на ~1 сек.
        // Появляется в той же строке (parent), потом плавно уезжает.
        function showInlineCheck(target){
            const parent = target.parentElement;
            if (!parent) return;
            // Снести предыдущую, если ещё висит
            const old = parent.querySelector('.inline-check');
            if (old) old.remove();

            const c = document.createElement('span');
            c.className = 'inline-check';
            c.innerHTML = svgIcon('ic-check') + 'скопировано';
            target.insertAdjacentElement('afterend', c);

            setTimeout(() => {
                c.classList.add('fading');
                setTimeout(() => c.remove(), 300);
            }, 900);
        }

        // Возраст файла (mtime теперь = upstream Last-Modified, см. Updater.doDownload).
        // < 30 дней   → fresh (зелёный с пульсом)
        // 30-180 дней → aging (жёлтый)
        // > 180 дней  → old   (серый)
        function freshness(mtime){
            const ageDays = (Date.now()/1000 - mtime) / 86400;
            if (ageDays < 30)  return 'fresh';
            if (ageDays < 180) return 'aging';
            return 'old';
        }
        function freshnessTitle(fr, mtime){
            const ageDays = Math.max(0, Math.floor((Date.now()/1000 - mtime) / 86400));
            const label = fr === 'fresh' ? 'свежий релиз'
                       : fr === 'aging' ? 'средний возраст'
                       : 'старый релиз';
            return `${label} (≈${ageDays} дн. с публикации upstream)`;
        }

        function buildSubLine(f){
            const sub = document.createElement('div'); sub.className='sub';

            const sizeF = document.createElement('span'); sizeF.className='field';
            sizeF.innerHTML = svgIcon('ic-hdd') + humanSize(f.size);
            sub.appendChild(sizeF);

            const dot1 = document.createElement('span'); dot1.className='dot'; dot1.textContent='•'; sub.appendChild(dot1);

            // Дата + точка-freshness прямо за иконкой часов
            const dateF = document.createElement('span'); dateF.className='field';
            const fr = freshness(f.mtime);
            dateF.innerHTML = svgIcon('ic-clock')
                + `<span class="fresh-dot ${fr}" title="${freshnessTitle(fr, f.mtime)}"></span>`
                + fmtDate(f.mtime);
            sub.appendChild(dateF);

            const dot2 = document.createElement('span'); dot2.className='dot'; dot2.textContent='•'; sub.appendChild(dot2);

            sub.appendChild(createClickableHash(f.type));
            return sub;
        }

        function buildCopyMenu(fileUrl, fileName){
            const group=document.createElement('div');group.className='copy-group';
            const btn=document.createElement('button');btn.className='ghost copy-toggle';
            btn.innerHTML = svgIcon('ic-copy') + ' Копировать <span style="opacity:.6">▾</span>';
            btn.setAttribute('aria-haspopup','true');
            btn.setAttribute('aria-expanded','false');
            attachMagnetic(btn, 4);

            const menu=document.createElement('div');menu.className='dd-menu';
            const a1=document.createElement('a');a1.href='#';a1.className='dd-item';
            a1.innerHTML = svgIcon('ic-link') + 'Прямую ссылку';
            const a2=document.createElement('a');a2.href='#';a2.className='dd-item';
            a2.innerHTML = svgIcon('ic-term') + 'Команду <code style="font-family:var(--mono);font-size:11px;color:var(--accent)">wget</code>';
            menu.appendChild(a1);menu.appendChild(a2);

            btn.addEventListener('click',e=>{
                e.stopPropagation();
                const willOpen = !menu.classList.contains('show');
                setMenuOpen(menu, willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if(willOpen) positionMenu(menu, btn);
            });
            a1.addEventListener('click',e=>{e.preventDefault();copyToClipboard(fileUrl); setMenuOpen(menu,false);});
            a2.addEventListener('click',e=>{e.preventDefault();const wget='wget -O "' + fileName.replace(/"/g, '\\"') + '" "' + fileUrl + '"';copyToClipboard(wget); setMenuOpen(menu,false);});

            group.appendChild(btn);group.appendChild(menu);
            return group;
        }

        function positionMenu(menu, btn){
            // Если в нижней четверти viewport — открываем вверх
            menu.classList.remove('flip-up');
            const r = btn.getBoundingClientRect();
            if (window.innerHeight - r.bottom < 240) {
                menu.classList.add('flip-up');
            }
        }

        const listEl=document.getElementById('list'),
              countEl=document.getElementById('count'),
              searchInput=document.getElementById('search'),
              toast=document.getElementById('toast');
        let lastQuery = '';
        // Только при первой отрисовке делаем stagger; при поиске/фильтрации — мгновенно
        let isInitialRender = true;

        // Натуральное сравнение (учитывает числа: 9 < 10, 22.04 < 26.04)
        function natCmp(a, b){
            return String(a).localeCompare(String(b), undefined, {numeric:true, sensitivity:'base'});
        }

        // "Ключ семейства" по имени файла: версионные токены маскируются в '*'.
        // Файлы с одним ключом = одно семейство.
        //
        // Что считается версией: токен из цифр со .- разделителями (7.3, 4.2-1, 9.2, 22.04).
        // Что НЕ считается: диапазон годов вида YYYY-YYYY (например 2012-2022 у langpaks_win) —
        // это не "версия продукта", а исторический артефакт.
        //
        // Примеры:
        //   ProxmoxVE_9.2.iso             → ProxmoxVE_*.iso
        //   Proxmox_BackUP_4.2.iso        → Proxmox_BackUP_*.iso
        //   Windows_Server_2025_ru.iso    → Windows_Server_*_ru.iso  (≠ _en — разные семейства)
        //   Windows_11_ru.iso             → Windows_*_ru.iso         (группируется с Windows_10_ru)
        //   WinPE.iso                     → WinPE.iso                (нет версий вообще)
        //   langpaks_win_2012-2022.iso    → langpaks_win_2012-2022.iso (range годов — не версия)
        //   QEMU_virtio-win-latest.iso    → QEMU_virtio-win-latest.iso (нет версий)
        function familyKey(name){
            const dot = name.lastIndexOf('.');
            const base = dot > 0 ? name.slice(0, dot) : name;
            const ext  = dot > 0 ? name.slice(dot)    : '';
            return base.split('_').map(tok => {
                if (/^\d{4}-\d{4}$/.test(tok)) return tok;            // range годов — не версия
                return /^\d+(?:[.\-]\d+)*$/.test(tok) ? '*' : tok;
            }).join('_') + ext;
        }

        // Есть ли в имени файла версионный токен? Используется для решения,
        // даём ли latest-бейдж singleton'ам: с версией ("Proxmox_BackUP_4.2") — да,
        // без версии ("WinPE", "QEMU_virtio-win-latest") — нет.
        function hasVersionToken(name){
            const dot = name.lastIndexOf('.');
            const base = dot > 0 ? name.slice(0, dot) : name;
            return base.split('_').some(tok =>
                !/^\d{4}-\d{4}$/.test(tok) && /^\d+(?:[.\-]\d+)*$/.test(tok)
            );
        }

        function showToast(t){
            toast.innerHTML = svgIcon('ic-check') + escapeHtml(t);
            toast.classList.add('show');
            clearTimeout(showToast._t);
            showToast._t=setTimeout(()=>toast.classList.remove('show'),1600);
        }

        function animateOpen(el){
            el.style.display = 'grid';
            requestAnimationFrame(()=>{
                el.style.maxHeight = el.scrollHeight + 'px';
                el.classList.add('open');
            });
        }
        function animateClose(el){
            el.style.maxHeight = el.scrollHeight + 'px';
            requestAnimationFrame(()=>{
                el.style.maxHeight = '0px';
                el.classList.remove('open');
            });
            const onEnd = function(){
                if (el.style.maxHeight === '0px') el.style.display = 'none';
                el.removeEventListener('transitionend', onEnd);
            };
            el.addEventListener('transitionend', onEnd);
        }

        function setMenuOpen(menu, open){
            if(!menu) return;
            if(open){
                document.querySelectorAll('.dd-menu.show').forEach(m=>{
                    if(m!==menu){
                        m.classList.remove('show');
                        const rr = m.closest('.row'); if(rr) rr.classList.remove('menu-open');
                    }
                });
                menu.classList.add('show');
                const row = menu.closest('.row'); if(row) row.classList.add('menu-open');
            } else {
                menu.classList.remove('show');
                const row = menu.closest('.row'); if(row) row.classList.remove('menu-open');
            }
        }

        function createSkeleton(count=6){
            listEl.innerHTML = '';
            for(let i=0;i<count;i++){
                const s = document.createElement('div'); s.className='skel-row';
                s.innerHTML = '<div class="skel-thumb"></div><div class="skel-meta"><div class="skel-line" style="width:60%"></div><div class="skel-sub"></div></div>';
                listEl.appendChild(s);
            }
        }

        function render(items){
            listEl.innerHTML='';
            const dirs = items.filter(i=>i.type==='dir');
            const files = items.filter(i=>i.type!=='dir');
            const total = files.length + dirs.length;
            if(total===0){
                // Два состояния:
                // 1) lastQuery непустой → "ничего не найдено по запросу"
                // 2) lastQuery пустой   → "хранилище пусто" (скрипт ещё не запускался)
                const emptyIcon = `
                    <svg class="empty-icon" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <circle cx="80" cy="80" r="50" stroke-opacity="0.55"/>
                        <line x1="116" y1="116" x2="168" y2="168" stroke-opacity="0.55" stroke-linecap="round"/>
                        <circle class="dashed" cx="80" cy="80" r="30" stroke-dasharray="4 6" stroke-opacity="0.75"/>
                        <circle cx="80" cy="80" r="4" fill="currentColor" fill-opacity="0.7" stroke="none"/>
                    </svg>`;
                if(lastQuery){
                    countEl.innerHTML = `Найдено <span class="num">0</span> по запросу <span class="num">«${escapeHtml(lastQuery)}»</span>`;
                    listEl.innerHTML = `
                        <div class="empty-state" role="status">
                            ${emptyIcon}
                            <div class="empty-title">Ничего не найдено</div>
                            <div class="empty-meta">По запросу <code>${escapeHtml(lastQuery)}</code> ничего не подошло — ни по имени файла, ни по хэшу. Попробуйте короче или другое ключевое слово.</div>
                            <div class="empty-hint">Нажмите <kbd>Esc</kbd> или крестик чтобы очистить поиск</div>
                        </div>`;
                } else {
                    countEl.innerHTML = '<span>Хранилище пустое</span>';
                    listEl.innerHTML = `
                        <div class="empty-state" role="status">
                            ${emptyIcon}
                            <div class="empty-title">Здесь пока пусто</div>
                            <div class="empty-meta">Скрипт <code>update_iso.php</code> ещё не загрузил ни одного образа. Запустите его вручную или дождитесь cron-задания.</div>
                        </div>`;
                }
                return;
            }
            countEl.innerHTML = `Найдено <span class="num">${total}</span> элемент(ов)` + (lastQuery ? ` по запросу <span class="num">«${escapeHtml(lastQuery)}»</span>` : '');

            dirs.forEach(dir=>{
                const row=document.createElement('div');row.className='row dir-row';row.setAttribute('role','listitem');row.tabIndex=0;
                applyDistroClass(row, dir.name);
                const thumb=document.createElement('div');thumb.className='thumb folder';thumb.innerHTML=fileIcon(dir.name,true);
                const meta=document.createElement('div');meta.className='meta';
                const name=document.createElement('div');name.className='filename';

                const dirNameHighlight = highlightSnippet(dir.name,lastQuery);
                name.innerHTML = dirNameHighlight.html;
                if((dir._dirMatch) || (Array.isArray(dir.children) && dir.children.length>0 && lastQuery)){
                    const matchCount = (dir._dirMatch?1:0) + (Array.isArray(dir.children)?dir.children.reduce((s,c)=>s + ((c.name.toLowerCase().includes(lastQuery) && lastQuery)?1:0), 0):0);
                    if(matchCount>0){
                        const mb = document.createElement('span'); mb.className='match-badge'; mb.textContent = matchCount + ' совп.';
                        name.appendChild(mb);
                    }
                }

                const sub=document.createElement('div');sub.className='sub';
                const cf = document.createElement('span'); cf.className='field';
                cf.innerHTML = svgIcon('ic-folder') + (Array.isArray(dir.children)?dir.children.length:0) + ' файл(ов)';
                sub.appendChild(cf);
                if(dir.size > 0){
                    const dot = document.createElement('span'); dot.className='dot'; dot.textContent='•'; sub.appendChild(dot);
                    const sf = document.createElement('span'); sf.className='field';
                    sf.innerHTML = svgIcon('ic-hdd') + humanSize(dir.size);
                    sub.appendChild(sf);
                }
                meta.appendChild(name);meta.appendChild(sub);

                const btns=document.createElement('div');btns.className='btns';
                const toggle=document.createElement('button');toggle.className='ghost';
                toggle.innerHTML = '<span class="toggle-arrow">▾</span>';
                toggle.setAttribute('aria-expanded','false');toggle.title='Открыть папку';toggle.setAttribute('aria-label','Открыть папку');
                btns.appendChild(toggle);
                row.appendChild(thumb);row.appendChild(meta);row.appendChild(btns);
                listEl.appendChild(row);

                const childrenWrap=document.createElement('div');childrenWrap.className='children';childrenWrap.style.display='none';childrenWrap.style.maxHeight='0px';

                // Для каждого семейства (familyKey) находим самый свежий файл — он получит latest-бейдж.
                // Правила:
                //   - семейство из ≥2 файлов → новейший (первый в descending-сортировке) badge'ом
                //   - singleton с версионным токеном (Proxmox_BackUP_4.2 — это "единственная версия
                //     этого продукта") → тоже badge
                //   - singleton без версии (WinPE.iso, QEMU_virtio-win-latest.iso, langpaks_win_2012-2022.iso)
                //     → НЕ показываем latest: не с чем сравнивать, и это утилиты/архивы, не релизы
                const _latestIdxByFamily = (() => {
                    const firstIdx = new Map();
                    const size = new Map();
                    (dir.children||[]).forEach((f, i) => {
                        const k = familyKey(f.name);
                        if (!firstIdx.has(k)) firstIdx.set(k, i);
                        size.set(k, (size.get(k) || 0) + 1);
                    });
                    const out = new Set();
                    for (const [k, idx] of firstIdx) {
                        const cnt = size.get(k) || 0;
                        const file = (dir.children||[])[idx];
                        if (cnt >= 2 || (cnt === 1 && hasVersionToken(file.name))) {
                            out.add(idx);
                        }
                    }
                    return out;
                })();

                (dir.children||[]).forEach((f,idx)=>{
                    const crow=document.createElement('div');crow.className='row';crow.tabIndex=0;
                    applyDistroClass(crow, f.name);
                    const cthumb=document.createElement('div');cthumb.className='thumb';cthumb.innerHTML=fileIcon(f.name,false);
                    const cmeta=document.createElement('div');cmeta.className='meta';
                    const cname=document.createElement('div');cname.className='filename';
                    const highlighted = highlightSnippet(f.name,lastQuery);
                    cname.innerHTML = highlighted.html;
                    if(highlighted.count>0){const mb=document.createElement('span');mb.className='match-badge';mb.textContent=highlighted.count + ' совп.';cname.appendChild(mb);}
                    // Бейдж latest — самый свежий в СВОЁМ семействе (не один на всю папку).
                    // В папке с несколькими продуктами (Proxmox VE/Backup/MailGateway) каждое
                    // семейство получает свой бейдж.
                    if(_latestIdxByFamily.has(idx)){
                        const lb=document.createElement('span');lb.className='latest-badge';lb.textContent='latest';
                        lb.title='Самая свежая версия в этом семействе';
                        // Случайная отрицательная задержка через CSS-переменную (animation-delay
                        // на parent'е не пропагируется на ::after, поэтому через --shimmer-delay).
                        lb.style.setProperty('--shimmer-delay', (-Math.random() * 5) + 's');
                        cname.appendChild(lb);
                    }
                    cmeta.appendChild(cname);
                    cmeta.appendChild(buildSubLine(f));

                    const cbtns=document.createElement('div');cbtns.className='btns';
                    const cdl=document.createElement('a');cdl.className='primary tooltip';
                    cdl.innerHTML = svgIcon('ic-download') + 'Скачать';
                    cdl.href=webDir+'/'+encodeURIComponent(dir.name)+'/'+encodeURIComponent(f.name);
                    cdl.setAttribute('download','');cdl.target='_blank';cdl.setAttribute('data-title','Скачать файл');
                    attachMagnetic(cdl, 5);

                    const childUrl=window.location.origin+'/'+webDir+'/'+encodeURIComponent(dir.name)+'/'+encodeURIComponent(f.name);
                    cbtns.appendChild(cdl);
                    cbtns.appendChild(buildCopyMenu(childUrl, f.name));
                    crow.appendChild(cthumb);crow.appendChild(cmeta);crow.appendChild(cbtns);
                    childrenWrap.appendChild(crow);

                    crow.addEventListener('keydown', e=>{
                        if(e.key==='Enter'){const a = crow.querySelector('a.primary'); if(a) a.click();}
                        if(e.key===' '){e.preventDefault(); const cb = crow.querySelector('.copy-toggle'); if(cb) cb.click();}
                    });
                });

                listEl.appendChild(childrenWrap);

                if (lastQuery && Array.isArray(dir.children) && (dir._dirMatch || dir.children.some(c=>c.name.toLowerCase().includes(lastQuery)))) {
                    const arrow = toggle.querySelector('.toggle-arrow'); if(arrow) arrow.classList.add('expanded');
                    animateOpen(childrenWrap);
                    childrenWrap.classList.add('open');
                    toggle.setAttribute('aria-expanded','true');
                }

                row.addEventListener('click', function(e){
                    if (e.target.closest('.btns')) return;
                    const isOpen = childrenWrap.classList.contains('open');
                    const arrow = toggle.querySelector('.toggle-arrow');
                    if(isOpen){
                        if(arrow) arrow.classList.remove('expanded');
                        animateClose(childrenWrap);
                        childrenWrap.classList.remove('open');
                        toggle.setAttribute('aria-expanded','false');
                    } else {
                        if(arrow) arrow.classList.add('expanded');
                        animateOpen(childrenWrap);
                        childrenWrap.classList.add('open');
                        toggle.setAttribute('aria-expanded','true');
                    }
                });

                row.addEventListener('keydown', e=>{
                    if(e.key==='Enter'){row.click();}
                });
            });

            files.forEach(f=>{
                const row=document.createElement('div');row.className='row';row.setAttribute('role','listitem');row.tabIndex=0;
                applyDistroClass(row, f.name);
                const thumb=document.createElement('div');thumb.className='thumb';thumb.innerHTML=fileIcon(f.name,false);
                const meta=document.createElement('div');meta.className='meta';
                const name=document.createElement('div');name.className='filename';
                const highlighted = highlightSnippet(f.name,lastQuery);
                name.innerHTML = highlighted.html;
                if(highlighted.count>0){const mb=document.createElement('span');mb.className='match-badge';mb.textContent=highlighted.count + ' совп.';name.appendChild(mb);}
                meta.appendChild(name);
                meta.appendChild(buildSubLine(f));

                const btns=document.createElement('div');btns.className='btns';
                const dl=document.createElement('a');dl.className='primary tooltip';
                dl.innerHTML = svgIcon('ic-download') + 'Скачать';
                dl.href=webDir+'/'+encodeURIComponent(f.name);
                dl.setAttribute('download','');dl.target='_blank';dl.setAttribute('data-title','Скачать файл');
                attachMagnetic(dl, 5);

                const fileUrl=window.location.origin+'/'+webDir+'/'+encodeURIComponent(f.name);
                btns.appendChild(dl);
                btns.appendChild(buildCopyMenu(fileUrl, f.name));
                row.appendChild(thumb);row.appendChild(meta);row.appendChild(btns);
                listEl.appendChild(row);

                row.addEventListener('keydown', e=>{
                    if(e.key==='Enter'){const a = row.querySelector('a.primary'); if(a) a.click();}
                    if(e.key===' '){e.preventDefault(); const cb = row.querySelector('.copy-toggle'); if(cb) cb.click();}
                });
            });

            // Stagger-вход для первых N top-level строк только при первой отрисовке.
            // :scope > .row — берём только прямых детей listEl (dir-row и top-level file-row),
            // не вложенных в .children (там свои анимации раскрытия папки).
            if (isInitialRender) {
                let i = 0;
                listEl.querySelectorAll(':scope > .row').forEach(row => {
                    if (i < 15) {
                        row.style.animationDelay = (i * 28) + 'ms';
                        row.classList.add('row-stagger');
                        i++;
                    }
                });
                isInitialRender = false;
            }
        }

        document.addEventListener('click',function(e){
            if(!e.target.closest('.copy-group')){
                document.querySelectorAll('.dd-menu.show').forEach(m=>setMenuOpen(m,false));
            }
        });

        function apply(){
            const q=(searchInput.value||'').trim().toLowerCase();
            lastQuery = q;
            // Состояние крестика очистки: видим только когда есть текст в поиске
            const wrap = document.getElementById('search-wrap');
            if (wrap) wrap.classList.toggle('has-value', q.length > 0);

            // Если запрос — длинная hex-строка (≥6 hex символов, ничего кроме),
            // считаем что ищем по хэшу. Иначе только по имени.
            const isHashQuery = q.length >= 6 && /^[a-f0-9]+$/.test(q);
            const matchFile = (f) => {
                if (f.name.toLowerCase().includes(q)) return true;
                if (isHashQuery && f.type && f.type.toLowerCase().includes(q)) return true;
                return false;
            };

            let items=Array.isArray(FILES)?FILES.slice():[];
            if(q){
                items = items.map(it=>{
                    if(it.type==='dir'){
                        const dirMatch = it.name.toLowerCase().includes(q);
                        const filteredChildren = (it.children||[]).filter(matchFile);
                        if(dirMatch || filteredChildren.length>0){
                            return Object.assign({}, it, { children: dirMatch ? (it.children||[]) : filteredChildren, _dirMatch: dirMatch });
                        }
                        return null;
                    }
                    return matchFile(it) ? it : null;
                }).filter(Boolean);
            }
            // Фиксированная сортировка (ручной выбор убран):
            //  - внутри папок файлы по натуральному имени УБЫВАЮЩЕ (новейшие версии сверху)
            //  - на верхнем уровне папки идут первыми и по алфавиту (A→Z),
            //    свободные файлы — новейшие сверху
            items.forEach(it=>{
                if(it.type==='dir' && Array.isArray(it.children)){
                    it.children.sort((a,b)=> natCmp(b.name, a.name));
                }
            });
            items.sort((a,b)=>{
                if(a.type==='dir' && b.type!=='dir') return -1;
                if(b.type==='dir' && a.type!=='dir') return 1;
                if(a.type==='dir') return natCmp(a.name, b.name);   // папки A→Z
                return natCmp(b.name, a.name);                       // файлы — новее сверху
            });

            render(items);
        }

        function renderHistory(){
            const block = document.getElementById('history-block');
            const list  = document.getElementById('hist-list');
            const title = document.getElementById('hist-title');
            list.innerHTML = '';
            if(!Array.isArray(HISTORY) || HISTORY.length === 0){
                block.style.display = 'none';
                return;
            }
            block.style.display = '';
            title.textContent = `История обновлений (${HISTORY.length})`;

            HISTORY.forEach(e => {
                const row = document.createElement('div'); row.className = 'hist-row';
                const ts  = document.createElement('span'); ts.className = 'hist-ts';
                // компактная дата: ДД.ММ HH:MM
                try {
                    const d = new Date(e.ts);
                    ts.textContent = d.toLocaleString('ru-RU', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                } catch(_) { ts.textContent = e.ts; }
                const kind = document.createElement('span'); kind.className = 'hist-kind ' + e.kind;
                kind.textContent = e.kind === 'updated' ? 'обновлено'
                                : e.kind === 'cleanup' ? 'удалено'
                                : e.kind === 'failed'  ? 'не скачано'
                                : e.kind;
                const file = document.createElement('span'); file.className = 'hist-file';
                file.textContent = e.file || '?';
                row.appendChild(ts); row.appendChild(kind); row.appendChild(file);
                if(e.extra){
                    const ex = document.createElement('span'); ex.className = 'hist-extra';
                    ex.textContent = e.extra;
                    row.appendChild(ex);
                }
                list.appendChild(row);
            });

            // Сворачиваем/разворачиваем по клику на хедер
            document.getElementById('hist-toggle').addEventListener('click', ()=>{
                block.classList.toggle('open');
            });
        }

        renderStatusBar();
        renderMissing();
        renderHistory();
        createSkeleton(6);
        setTimeout(()=>apply(),120);

        searchInput.addEventListener('input', ()=>{
            createSkeleton(4);
            setTimeout(()=>apply(),80);
        });

        // Крестик очистки поиска
        const _searchClear = document.getElementById('search-clear');
        if (_searchClear) {
            _searchClear.addEventListener('click', () => {
                searchInput.value = '';
                searchInput.focus();
                apply();
            });
        }

        document.addEventListener('keydown', e=>{
            if(e.key==='Escape'){
                document.querySelectorAll('.dd-menu.show').forEach(m=>setMenuOpen(m,false));
                if(searchInput.value){searchInput.value=''; apply();}
            }
            if((e.ctrlKey||e.metaKey) && e.key==='k'){e.preventDefault(); searchInput.focus(); searchInput.select();}
        });
    </script>
</body>
</html>
