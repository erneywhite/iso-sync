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

// Список из конфига для подсчёта отсутствующих
$missing = [];
if (is_file($configPath)) {
    try {
        $cfg = Config::loadFromFile($configPath);
        foreach ($cfg->files as $entry) {
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
    --bg-1:#06101a;
    --bg-2:#0a1830;
    --surface-1:rgba(255,255,255,0.025);
    --surface-2:rgba(255,255,255,0.04);
    --border-1:rgba(255,255,255,0.06);
    --border-2:rgba(255,255,255,0.1);
    --text:#e9f3fb;
    --muted:#9fb0c1;
    --muted-2:#6b7d8f;
    --accent:#56c1ff;
    --accent-2:#7ad9ff;
    --accent-soft:rgba(86,193,255,0.12);
    --ok:#4ade80;
    --warn:#fbbf24;
    --err:#f87171;
    --radius:14px;
    --radius-sm:10px;
    --shadow:0 6px 30px rgba(2,8,23,0.6);
    --mono:ui-monospace,'JetBrains Mono','Cascadia Code','Fira Code','Courier New',monospace;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto;font-feature-settings:'cv11','ss01'}
/* Прокрутка идёт внутри .card, body не скроллится — шапка и статус-бар фиксированы,
   фон без шва на любом размере страницы. */
body{
    background:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(86,193,255,0.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 100% 100%, rgba(122,217,255,0.04) 0%, transparent 50%),
        linear-gradient(180deg,var(--bg-1),var(--bg-2));
    color:var(--text);
    height:100vh;
    height:100dvh;
    overflow:hidden;
}
::selection{background:rgba(86,193,255,0.3);color:#fff}

.container{
    max-width:min(1600px,94vw);
    margin:0 auto;
    padding:28px 20px;
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
.logo-img{width:48px;height:48px;border-radius:12px;box-shadow:0 4px 16px rgba(86,193,255,0.15)}
h1{
    margin:0;
    font-size:24px;
    font-weight:700;
    letter-spacing:-0.01em;
    background:linear-gradient(90deg,#fff 0%, var(--accent-2) 60%, var(--accent) 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;
}
.tag{
    display:inline-block;
    margin-left:10px;
    padding:2px 8px;
    border-radius:999px;
    background:var(--accent-soft);
    color:var(--accent);
    font-size:11px;
    font-weight:600;
    letter-spacing:0.02em;
    vertical-align:middle;
}

.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.search{
    background:var(--surface-1);
    border:1px solid var(--border-1);
    padding:11px 14px 11px 38px;
    border-radius:var(--radius-sm);
    color:inherit;
    min-width:320px;
    font-size:14px;
    transition:border-color .15s, background .15s;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239fb0c1' stroke-width='2' stroke-linecap='round'><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>");
    background-repeat:no-repeat;
    background-position:12px center;
}
.search:focus{outline:none;border-color:rgba(86,193,255,0.4);background-color:rgba(86,193,255,0.04)}
.sort{
    background:var(--surface-1);
    border:1px solid var(--border-1);
    padding:10px 12px;
    border-radius:var(--radius-sm);
    color:var(--text);
    font-size:13px;
    cursor:pointer;
}
.sort:focus{outline:none;border-color:rgba(86,193,255,0.4)}
.sort option{background-color:#0a1628;color:var(--text)}
.sort option:checked{background-color:rgba(86,193,255,0.2)}

/* ========== Status bar ========== */
.status-bar{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    padding:12px 16px;
    border-radius:var(--radius);
    background:linear-gradient(135deg, rgba(86,193,255,0.04), rgba(122,217,255,0.02));
    border:1px solid var(--border-1);
    font-size:13px;
    color:var(--muted);
    backdrop-filter:blur(8px);
}
.pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    background:var(--surface-2);
    border:1px solid var(--border-1);
    font-weight:600;
    font-size:12px;
    letter-spacing:0.01em;
}
.pill .num{font-variant-numeric:tabular-nums;font-weight:700}
.pill.ok{color:var(--ok);background:rgba(74,222,128,0.08);border-color:rgba(74,222,128,0.15)}
.pill.warn{color:var(--warn);background:rgba(251,191,36,0.08);border-color:rgba(251,191,36,0.15)}
.pill.err{color:var(--err);background:rgba(248,113,113,0.08);border-color:rgba(248,113,113,0.18)}
.pill.muted{color:var(--muted)}
.pill.accent{color:var(--accent);background:var(--accent-soft);border-color:rgba(86,193,255,0.2)}
.sep{opacity:.35;color:var(--muted-2)}
.status-time{color:var(--muted);font-size:12px}
.status-time strong{color:var(--text);font-weight:600}

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
.card::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(86,193,255,0.35),rgba(86,193,255,0.1));border-radius:10px}
.card::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,rgba(86,193,255,0.5),rgba(86,193,255,0.2))}

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
    border-color:var(--border-2);
    box-shadow:0 8px 24px rgba(2,8,23,0.4);
}
.row:focus{outline:none;border-color:rgba(86,193,255,0.35);box-shadow:0 0 0 3px rgba(86,193,255,0.12)}
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
    background:linear-gradient(135deg,var(--accent),#7ad9ff);
    color:#012;
    box-shadow:0 4px 12px rgba(86,193,255,0.2);
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
.primary:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(86,193,255,0.25);filter:brightness(1.05)}
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
    border:1px solid rgba(86,193,255,0.15);
    cursor:pointer;
    transition:all .15s ease;
    user-select:all;
    word-break:break-all;
    max-width:100%;
    line-height:1.4;
}
.hash-clickable:hover{
    color:#9ae2ff;
    background:rgba(86,193,255,0.18);
    border-color:rgba(86,193,255,0.3);
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

/* ========== Animations ========== */
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer{to{background-position:-200% 0}}

/* ========== Highlight (search) ========== */
mark{background:rgba(86,193,255,0.25);color:var(--accent-2);padding:0 2px;border-radius:3px}

/* ========== Responsive ========== */
@media (max-width:1100px){
    .search{min-width:240px}
}
@media (max-width:880px){
    .container{margin:16px auto;padding:0 14px 20px}
    .search{min-width:200px}
    h1{font-size:20px}
    .row{padding:12px 14px;gap:12px}
    .thumb{width:40px;height:40px}
    .filename{font-size:14px}
    .children{margin-left:0}
}
@media (max-width:640px){
    header{flex-direction:column;align-items:stretch}
    .brand{justify-content:flex-start}
    .actions{width:100%}
    .search{flex:1;width:100%}
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
                <input id="search" class="search" placeholder="Поиск по имени..." aria-label="Поиск по имени" />
                <select id="sort" class="sort" aria-label="Сортировка">
                    <option value="mtime_desc">По дате (новые сверху)</option>
                    <option value="mtime_asc">По дате (старые сверху)</option>
                    <option value="size_desc">По размеру (больше)</option>
                    <option value="size_asc">По размеру (меньше)</option>
                    <option value="name_asc">По имени (A→Z)</option>
                    <option value="name_desc">По имени (Z→A)</option>
                </select>
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
        </div>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <script>
        const FILES = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> || [];
        const MISSING = <?php echo json_encode($missing, JSON_UNESCAPED_UNICODE); ?> || [];
        const LAST_RUN = <?php echo json_encode($lastRun, JSON_UNESCAPED_UNICODE); ?>;
        const TOTAL_FILES = <?php echo (int)$totalFiles; ?>;
        const TOTAL_SIZE = <?php echo (int)$totalSize; ?>;
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

        function renderStatusBar(){
            const el = document.getElementById('status-bar');
            const parts = [];

            // Сводка по хранилищу — всегда показываем
            parts.push(`<span class="pill accent">${svgIcon('ic-hdd')}<span class="num">${humanSize(TOTAL_SIZE)}</span></span>`);
            parts.push(`<span class="pill muted">${TOTAL_FILES} файл(ов)</span>`);

            if(MISSING.length > 0){
                parts.push(`<span class="pill warn">${svgIcon('ic-warn')}отсутствует ${MISSING.length}</span>`);
            }

            parts.push('<span class="sep">•</span>');

            if(!LAST_RUN){
                parts.push('<span class="status-time">Проверки не было — запустите <code style="background:rgba(255,255,255,0.05);padding:1px 6px;border-radius:4px;font-family:var(--mono);font-size:11px">php update_iso.php</code></span>');
            } else {
                const ts = LAST_RUN.finished_at || LAST_RUN.started_at;
                const rel = relativeTime(ts);
                parts.push(`<span class="status-time">${svgIcon('ic-clock')} Проверка: <strong>${fmtIso(ts)}</strong>${rel?' <span style="opacity:.6">('+rel+')</span>':''}</span>`);

                if(typeof LAST_RUN.total === 'number'){
                    parts.push('<span class="sep">•</span>');
                    if(LAST_RUN.updated > 0) parts.push(`<span class="pill ok">${svgIcon('ic-check')}обновлено ${LAST_RUN.updated}</span>`);
                    if(LAST_RUN.up_to_date > 0) parts.push(`<span class="pill muted">актуально ${LAST_RUN.up_to_date}</span>`);
                    if(LAST_RUN.skipped > 0) parts.push(`<span class="pill muted">пропущено ${LAST_RUN.skipped}</span>`);
                    if(LAST_RUN.failed > 0) parts.push(`<span class="pill err">ошибки ${LAST_RUN.failed}</span>`);
                    if(typeof LAST_RUN.duration_s === 'number') parts.push(`<span class="pill muted">${LAST_RUN.duration_s} сек</span>`);
                }
            }
            if(LAST_RUN && LAST_RUN.fatal){
                parts.push(`<span class="pill err">FATAL: ${escapeHtml(LAST_RUN.fatal)}</span>`);
            }
            el.innerHTML = parts.join(' ');
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

        function copyToClipboard(text){
            if(navigator.clipboard){
                navigator.clipboard.writeText(text).then(()=>showToast('Скопировано в буфер'));
            } else {
                const t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();
                try{document.execCommand('copy');showToast('Скопировано в буфер');}catch(e){showToast('Не удалось скопировать');}
                document.body.removeChild(t);
            }
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
            span.title = 'Кликните, чтобы скопировать хэш';
            span.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#ic-copy"></use></svg>';
            const txt = document.createElement('span');
            txt.textContent = hashValue;
            span.appendChild(txt);

            span.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                copyToClipboard(hashValue.replace('sha256:', ''));
                span.classList.add('copied');
                setTimeout(()=>span.classList.remove('copied'),350);
            });
            return span;
        }

        function buildSubLine(f){
            const sub = document.createElement('div'); sub.className='sub';

            const sizeF = document.createElement('span'); sizeF.className='field';
            sizeF.innerHTML = svgIcon('ic-hdd') + humanSize(f.size);
            sub.appendChild(sizeF);

            const dot1 = document.createElement('span'); dot1.className='dot'; dot1.textContent='•'; sub.appendChild(dot1);

            const dateF = document.createElement('span'); dateF.className='field';
            dateF.innerHTML = svgIcon('ic-clock') + fmtDate(f.mtime);
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
              sortSelect=document.getElementById('sort'),
              toast=document.getElementById('toast');
        let lastQuery = '';

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
            if(total===0){countEl.innerHTML='<span>Файлов не найдено</span>';return;}
            countEl.innerHTML = `Найдено <span class="num">${total}</span> элемент(ов)` + (lastQuery ? ` по запросу <span class="num">«${escapeHtml(lastQuery)}»</span>` : '');

            dirs.forEach(dir=>{
                const row=document.createElement('div');row.className='row dir-row';row.setAttribute('role','listitem');row.tabIndex=0;
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
                (dir.children||[]).forEach(f=>{
                    const crow=document.createElement('div');crow.className='row';crow.tabIndex=0;
                    const cthumb=document.createElement('div');cthumb.className='thumb';cthumb.innerHTML=fileIcon(f.name,false);
                    const cmeta=document.createElement('div');cmeta.className='meta';
                    const cname=document.createElement('div');cname.className='filename';
                    const highlighted = highlightSnippet(f.name,lastQuery);
                    cname.innerHTML = highlighted.html;
                    if(highlighted.count>0){const mb=document.createElement('span');mb.className='match-badge';mb.textContent=highlighted.count + ' совп.';cname.appendChild(mb);}
                    cmeta.appendChild(cname);
                    cmeta.appendChild(buildSubLine(f));

                    const cbtns=document.createElement('div');cbtns.className='btns';
                    const cdl=document.createElement('a');cdl.className='primary tooltip';
                    cdl.innerHTML = svgIcon('ic-download') + 'Скачать';
                    cdl.href=webDir+'/'+encodeURIComponent(dir.name)+'/'+encodeURIComponent(f.name);
                    cdl.setAttribute('download','');cdl.target='_blank';cdl.setAttribute('data-title','Скачать файл');

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
        }

        document.addEventListener('click',function(e){
            if(!e.target.closest('.copy-group')){
                document.querySelectorAll('.dd-menu.show').forEach(m=>setMenuOpen(m,false));
            }
        });

        function apply(){
            const q=(searchInput.value||'').trim().toLowerCase();
            lastQuery = q;
            let items=Array.isArray(FILES)?FILES.slice():[];
            if(q){
                items = items.map(it=>{
                    if(it.type==='dir'){
                        const dirMatch = it.name.toLowerCase().includes(q);
                        const filteredChildren = (it.children||[]).filter(c=>c.name.toLowerCase().includes(q));
                        if(dirMatch || filteredChildren.length>0){
                            return Object.assign({}, it, { children: dirMatch ? (it.children||[]) : filteredChildren, _dirMatch: dirMatch });
                        }
                        return null;
                    }
                    return it.name.toLowerCase().includes(q)?it:null;
                }).filter(Boolean);
            }
            const s=sortSelect.value;
            items.forEach(it=>{
                if(it.type==='dir' && Array.isArray(it.children)){
                    it.children.sort((a,b)=>{
                        switch(s){
                            case 'mtime_desc': return b.mtime - a.mtime;
                            case 'mtime_asc': return a.mtime - b.mtime;
                            case 'size_desc': return b.size - a.size;
                            case 'size_asc': return a.size - b.size;
                            case 'name_desc': return b.name.localeCompare(a.name);
                            default: return a.name.localeCompare(b.name);
                        }
                    });
                }
            });
            items.sort((a,b)=>{
                if(a.type==='dir' && b.type!=='dir') return -1;
                if(b.type==='dir' && a.type!=='dir') return 1;
                if(s==='name_asc' || s==='name_desc'){
                    return (s==='name_desc' ? b.name.localeCompare(a.name) : a.name.localeCompare(b.name));
                }
                if(s==='mtime_desc' || s==='mtime_asc' || s==='size_desc' || s==='size_asc'){
                    if(a.type==='dir' && b.type==='dir'){
                        if(s.startsWith('size')) return s==='size_desc' ? (b.size||0)-(a.size||0) : (a.size||0)-(b.size||0);
                        return a.name.localeCompare(b.name);
                    }
                    if(a.type!=='dir' && b.type!=='dir'){
                        switch(s){
                            case 'mtime_desc': return b.mtime - a.mtime;
                            case 'mtime_asc': return a.mtime - b.mtime;
                            case 'size_desc': return b.size - a.size;
                            case 'size_asc': return a.size - b.size;
                        }
                    }
                }
                return 0;
            });

            render(items);
        }

        renderStatusBar();
        renderMissing();
        createSkeleton(6);
        setTimeout(()=>apply(),120);

        searchInput.addEventListener('input', ()=>{
            createSkeleton(4);
            setTimeout(()=>apply(),80);
        });
        sortSelect.addEventListener('change',apply);

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
