<?php
declare(strict_types=1);

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
$configFiles = [];
$missing = [];
if (is_file($configPath)) {
    try {
        $cfg = Config::loadFromFile($configPath);
        foreach ($cfg->files as $entry) {
            $expectedPath = $filesDir
                . ($entry->localSubdir !== '' ? DIRECTORY_SEPARATOR . $entry->localSubdir : '')
                . DIRECTORY_SEPARATOR . $entry->localName;
            $configFiles[$entry->localName] = $expectedPath;
            if (!is_file($expectedPath)) {
                $missing[] = [
                    'name'      => $entry->localName,
                    'subdir'    => $entry->localSubdir,
                    'remote'    => $entry->urlDir . ($entry->isLatest() ? '(latest)' : $entry->remoteName),
                ];
            }
        }
    } catch (Throwable) {
        // Конфиг битый — UI всё равно отрисуем без блока missing
    }
}

$items = [];
if (is_dir($filesDir)) {
    foreach (scandir($filesDir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep') continue;
        $path = $filesDir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            $children = [];
            foreach (scandir($path) ?: [] as $c) {
                if ($c === '.' || $c === '..' || $c === '.gitkeep') continue;
                $cp = $path . DIRECTORY_SEPARATOR . $c;
                if (is_file($cp)) {
                    $children[] = [
                        'name'  => $c,
                        'size'  => filesize($cp),
                        'mtime' => filemtime($cp),
                        'type'  => $hashCache->get($cp) ?? 'sha256:not_computed_yet',
                    ];
                }
            }
            $items[] = [
                'name'     => $name,
                'type'     => 'dir',
                'children' => $children,
            ];
        } elseif (is_file($path)) {
            $items[] = [
                'name'  => $name,
                'size'  => filesize($path),
                'mtime' => filemtime($path),
                'type'  => $hashCache->get($path) ?? 'sha256:not_computed_yet',
            ];
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
    --bg-1:#06101a;
    --bg-2:#091425;
    --muted:#9fb0c1;
    --accent:#56c1ff;
    --accent-dark:#2ea3dc;
    --ok:#4ade80;
    --warn:#fbbf24;
    --err:#f87171;
    --radius:12px;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,system-ui}
body{
    background:radial-gradient(ellipse at 20% 10%, rgba(86,193,255,0.02) 0%, transparent 10%),
               linear-gradient(180deg,var(--bg-1),var(--bg-2));
    color:#e9f3fb;
}
.container{max-width:1120px;margin:36px auto;padding:20px;display:flex;flex-direction:column;height:calc(100vh - 72px)}
header{display:flex;align-items:center;gap:16px;justify-content:space-between;margin-bottom:14px}
.brand{display:flex;align-items:center;gap:14px}
.logo-img{width:48px;height:48px;border-radius:10px}
h1{margin:0;font-size:22px;background:linear-gradient(90deg,var(--accent),#9ae2ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.actions{display:flex;gap:10px;align-items:center}
.search{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);padding:10px 12px;border-radius:10px;color:inherit;min-width:300px}
.sort{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);padding:8px 10px;border-radius:10px;color:var(--muted)}
.view-toggle{background:transparent;border:1px solid rgba(255,255,255,0.06);padding:8px 10px;border-radius:10px;color:var(--muted);cursor:pointer}

/* Полоса статуса */
.status-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);font-size:13px;color:var(--muted)}
.status-bar .pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,0.04);font-weight:600}
.status-bar .pill.ok{color:var(--ok)}
.status-bar .pill.warn{color:var(--warn)}
.status-bar .pill.err{color:var(--err)}
.status-bar .pill.muted{color:var(--muted)}
.status-bar .sep{opacity:.4}

.card{background:rgba(255,255,255,0.015);border-radius:var(--radius);padding:14px;box-shadow:0 6px 30px rgba(2,8,23,0.6);flex:1 1 auto;overflow-y:auto}
.card::-webkit-scrollbar{width:10px}
.card::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(86,193,255,0.4),rgba(86,193,255,0.15));border-radius:10px}
.count{color:var(--muted);font-size:14px;margin-bottom:12px}
.list{display:grid;gap:12px}
.row{display:flex;align-items:center;gap:14px;padding:14px;border-radius:12px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);transition:all .18s ease}
.row:hover{transform:translateY(-3px);background:rgba(255,255,255,0.04)}
.row:focus{outline:2px solid rgba(86,193,255,0.18);outline-offset:4px;transform:translateY(-3px)}
.thumb{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.06);color:var(--accent);font-size:14px;font-weight:600;box-shadow:0 0 8px rgba(86,193,255,0.06) inset}
.thumb.folder{background:linear-gradient(135deg,var(--accent),#7ad9ff);color:#012;font-size:18px}
.thumb.missing{background:rgba(248,113,113,0.12);color:var(--err)}
.icon{width:28px;height:28px;display:block}
.meta{flex:1;min-width:0}
.filename{font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:8px}
.sub{font-size:13px;color:var(--muted);margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.match-badge{display:inline-block;margin-left:8px;background:rgba(86,193,255,0.15);color:var(--accent);padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
.btns{display:flex;align-items:center;gap:10px}
.primary{background:linear-gradient(90deg,var(--accent),#7ad9ff);padding:9px 14px;border-radius:10px;border:none;color:#012;font-weight:700;text-decoration:none;transition:transform .12s,box-shadow .12s}
.primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(46,163,220,0.15)}
.primary:active{transform:translateY(0)}
.ghost{background:transparent;padding:9px 12px;border-radius:10px;border:1px solid rgba(255,255,255,0.08);color:var(--muted);cursor:pointer;transition:background .12s}
.ghost:hover{background:rgba(255,255,255,0.06);color:#fff}
.copy-group{position:relative}
.copy-main{padding:8px 10px;border-radius:8px;background:rgba(255,255,255,0.08);border:none;color:#e6eef6;cursor:pointer}
.dd-menu{display:none;position:absolute;right:0;top:calc(100% + 8px);min-width:220px;background:#071225;border-radius:10px;padding:8px;box-shadow:0 8px 30px rgba(2,8,23,0.9);z-index:9999}
.dd-menu.show{display:block;animation:fadeIn .15s ease}
.dd-item{display:block;padding:9px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:13px;transition:background .12s}
.dd-item:hover{background:rgba(255,255,255,0.04);color:#fff}
.children{margin-top:8px;margin-left:62px;display:grid;gap:8px;overflow:hidden;max-height:0;opacity:0;transition:max-height 320ms cubic-bezier(.2,.9,.2,1),opacity 220ms ease}
.children.open{opacity:1;overflow:visible}
.indent{padding-left:4px}
.toggle-arrow{transition:transform 220ms ease}
.toggle-arrow.expanded{transform:rotate(180deg)}
.toast{position:fixed;right:20px;bottom:20px;background:#06202a;padding:12px 14px;border-radius:10px;color:#cfeefc;box-shadow:0 8px 30px rgba(2,8,23,0.6);display:none;z-index:200}
.toast.show{display:block;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.row.menu-open,.row.menu-open:hover{transform:none!important;background:rgba(255,255,255,0.02)!important}

/* Skeleton loader */
.skel-row{display:flex;align-items:center;gap:14px;padding:14px;border-radius:12px;background:linear-gradient(90deg,rgba(255,255,255,0.02),rgba(255,255,255,0.015));border:1px solid rgba(255,255,255,0.03);}
.skel-thumb{width:48px;height:48px;border-radius:10px;background:linear-gradient(90deg,rgba(255,255,255,0.02),rgba(255,255,255,0.06));}
.skel-meta{flex:1}
.skel-line{height:12px;border-radius:6px;background:linear-gradient(90deg,#0b1014 0%, #1b2a36 50%, #0b1014 100%);background-size:200% 100%;animation:shimmer 1.1s linear infinite}
.skel-sub{height:10px;width:60%;border-radius:6px;margin-top:8px;background:linear-gradient(90deg,#071016 0%, #12202a 50%, #071016 100%);background-size:200% 100%;animation:shimmer 1.1s linear infinite}
@keyframes shimmer{to{background-position:-200% 0}}

.tooltip{position:relative}
.tooltip[data-title]:hover::after{content:attr(data-title);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:rgba(2,8,23,0.9);color:#cfeefc;padding:6px 8px;border-radius:6px;font-size:12px;white-space:nowrap}

@media (max-width:880px){.search{min-width:180px}.brand h1{font-size:18px}}
@media (max-width:640px){.actions{flex-direction:column;align-items:stretch}.search{width:100%}}

/* Стили для кликабельных хэшей */
.hash-clickable {
    color: var(--accent);
    cursor: pointer;
    text-decoration: underline;
    text-decoration-style: dotted;
    transition: color 0.2s ease;
    font-family: 'Courier New', monospace;
    font-weight: 500;
}

.hash-clickable:hover {
    color: #9ae2ff;
    text-decoration-style: solid;
}

.hash-not-ready {
    color: var(--muted);
    font-style: italic;
    cursor: default;
}

/* Блок отсутствующих */
.missing-block{margin-top:18px;padding:12px 14px;border-radius:10px;background:rgba(248,113,113,0.06);border:1px dashed rgba(248,113,113,0.25)}
.missing-block h2{margin:0 0 10px 0;font-size:15px;color:var(--err);display:flex;align-items:center;gap:8px}
.missing-block .row{background:rgba(255,255,255,0.015)}
.missing-block .row:hover{transform:none}
.missing-block.collapsed .missing-list{display:none}
.missing-toggle{cursor:pointer;user-select:none}
</style>
</head>
<body>
<svg style="display:none" aria-hidden="true">
  <symbol id="ic-folder" viewBox="0 0 24 24">
    <path d="M10 4H4a2 2 0 0 0-2 2v2h20V6a2 2 0 0 0-2-2h-8l-2-2zM2 10v8a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-8H2z"/>
  </symbol>
  <symbol id="ic-file" viewBox="0 0 24 24">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM14 3.5L20.5 10H14V3.5z"/>
  </symbol>
  <symbol id="ic-iso" viewBox="0 0 24 24">
  <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
  <circle cx="12" cy="12" r="3" fill="currentColor"/>
  </symbol>
  <symbol id="ic-download" viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zm7-18L5.33 9h3.67v4h4V9h3.67L12 2z"/></symbol>
  <symbol id="ic-copy" viewBox="0 0 24 24"><path d="M16 1H4a2 2 0 0 0-2 2v12h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></symbol>
  <symbol id="ic-link" viewBox="0 0 24 24"><path d="M3.9 12a5 5 0 0 0 0 7.07l1.42 1.42a5 5 0 0 0 7.07 0l3.54-3.54-1.41-1.41L11 19.07a3 3 0 0 1-4.24 0L5.33 17A3 3 0 0 1 5.33 13L3.9 12zM20.1 12a5 5 0 0 0 0-7.07L18.68 3.51a5 5 0 0 0-7.07 0L8.07 6.05 9.48 7.46 13 3.93a3 3 0 0 1 4.24 0l1.42 1.42a3 3 0 0 1 0 4.24l-1.42 1.42L20.1 12z"/></symbol>
  <symbol id="ic-warn" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></symbol>
</svg>

    <div class="container">
        <header>
            <div class="brand">
                <img src="favicon.ico" alt="Logo" class="logo-img">
                <h1>iso-файлы</h1>
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
                <h2 class="missing-toggle">
                    <svg class="icon" style="width:18px;height:18px" aria-hidden="true"><use href="#ic-warn"></use></svg>
                    <span id="missing-title">Отсутствующие файлы</span>
                    <span style="margin-left:auto;font-size:12px;color:var(--muted)">— ожидаются по конфигу, но не найдены на диске</span>
                </h2>
                <div id="missing-list" class="list missing-list"></div>
            </div>
        </div>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <script>
        const FILES = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?> || [];
        const MISSING = <?php echo json_encode($missing, JSON_UNESCAPED_UNICODE); ?> || [];
        const LAST_RUN = <?php echo json_encode($lastRun, JSON_UNESCAPED_UNICODE); ?>;
        const webDir = '<?php echo addslashes($webDir); ?>';

        function escapeHtml(unsafe){return String(unsafe).replace(/[&<>\"']/g, function(m){return{'&':'&amp;','<':'&lt;','>':'&gt;','\\"':'&quot;','"':'&quot;',"'":"&#39;"}[m]||m;});}
        function humanSize(bytes){if(bytes===0)return'0 B';const thresh=1024;const units=['B','KB','MB','GB','TB'];let u=0;let n=bytes;while(n>=thresh&&u<units.length-1){n/=thresh;u++;}return Math.round(n*10)/10+' '+units[u];}
        function fmtDate(ts){const d=new Date(ts*1000);return d.toLocaleString();}
        function fmtIso(iso){if(!iso)return'—';try{return new Date(iso).toLocaleString();}catch(e){return iso;}}
        function relativeTime(iso){
            if(!iso) return '';
            try{
                const then = new Date(iso).getTime();
                const diff = Math.floor((Date.now() - then) / 1000);
                if(diff < 60) return 'только что';
                if(diff < 3600) return Math.floor(diff/60) + ' мин назад';
                if(diff < 86400) return Math.floor(diff/3600) + ' ч назад';
                return Math.floor(diff/86400) + ' дн назад';
            } catch(e) { return ''; }
        }

        function renderStatusBar(){
            const el = document.getElementById('status-bar');
            if(!LAST_RUN){
                el.innerHTML = '<span class="pill muted">Последняя проверка: данных нет</span><span class="sep">·</span><span>Запустите <code>php update_iso.php</code></span>';
                return;
            }
            const ts = LAST_RUN.finished_at || LAST_RUN.started_at;
            const rel = relativeTime(ts);
            const parts = [];
            parts.push(`<span class="pill muted">Последняя проверка: ${fmtIso(ts)}${rel ? ' (' + rel + ')' : ''}</span>`);
            if(typeof LAST_RUN.total === 'number'){
                parts.push('<span class="sep">·</span>');
                parts.push(`<span class="pill ok">обновлено ${LAST_RUN.updated || 0}</span>`);
                parts.push(`<span class="pill muted">актуально ${LAST_RUN.up_to_date || 0}</span>`);
                if(LAST_RUN.skipped) parts.push(`<span class="pill muted">пропущено ${LAST_RUN.skipped}</span>`);
                if(LAST_RUN.failed) parts.push(`<span class="pill err">ошибки ${LAST_RUN.failed}</span>`);
                if(typeof LAST_RUN.duration_s === 'number') parts.push(`<span class="pill muted">${LAST_RUN.duration_s} сек</span>`);
            }
            if(LAST_RUN.fatal){
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
                const thumb = document.createElement('div'); thumb.className = 'thumb missing'; thumb.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#ic-warn"></use></svg>';
                const meta = document.createElement('div'); meta.className = 'meta';
                const name = document.createElement('div'); name.className = 'filename'; name.textContent = m.name;
                const sub = document.createElement('div'); sub.className = 'sub';
                sub.textContent = (m.subdir ? `files/${m.subdir}/  ←  ` : 'files/  ←  ') + m.remote;
                sub.title = m.remote;
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
            const highlighted = esc.replace(regex, '<mark>$1</mark>');
            return {html: highlighted, count};
        }

        function fileIcon(name, isDir){
            if(isDir) return '<svg class="icon" aria-hidden="true"><use href="#ic-folder"></use></svg>';
            const ext = (name.split('.').pop()||'').toLowerCase();
            if(/(jpe?g|png|gif|webp|svg)/.test(ext)) return '<svg class="icon" aria-hidden="true"><use href="#ic-file"></use></svg>';
            if(/(pdf)/.test(ext)) return '<svg class="icon" aria-hidden="true"><use href="#ic-file"></use></svg>';
            if(/(iso)/.test(ext)) return '<svg class="icon" aria-hidden="true"><use href="#ic-iso"></use></svg>';
            return '<svg class="icon" aria-hidden="true"><use href="#ic-file"></use></svg>';
        }

        function copyToClipboard(text){if(navigator.clipboard){navigator.clipboard.writeText(text).then(()=>showToast('Скопировано в буфер'))}else{const t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();try{document.execCommand('copy');showToast('Скопировано в буфер');}catch(e){showToast('Не удалось скопировать');}document.body.removeChild(t);}}

        function createClickableHash(hashValue) {
            if (!hashValue || hashValue === 'sha256:not_computed_yet') {
                const span = document.createElement('span');
                span.className = 'hash-not-ready';
                span.textContent = 'sha256:вычисляется...';
                return span;
            }

            const span = document.createElement('span');
            span.className = 'hash-clickable';
            span.textContent = hashValue;
            span.title = 'Кликните, чтобы скопировать хэш';

            span.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const hashOnly = hashValue.replace('sha256:', '');
                copyToClipboard(hashOnly);

                const originalColor = span.style.color;
                span.style.color = '#4ade80';
                setTimeout(() => {
                    span.style.color = originalColor;
                }, 200);
            });

            return span;
        }

        const listEl=document.getElementById('list'),countEl=document.getElementById('count'),searchInput=document.getElementById('search'),sortSelect=document.getElementById('sort'),toast=document.getElementById('toast');
        let lastQuery = '';

        function showToast(t){toast.textContent=t;toast.classList.add('show');clearTimeout(showToast._t);showToast._t=setTimeout(()=>toast.classList.remove('show'),1600);}

        function animateOpen(el){
            el.style.display = 'grid';
            requestAnimationFrame(()=>{
                const h = el.scrollHeight;
                el.style.maxHeight = h + 'px';
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
            if(total===0){countEl.textContent='Файлов не найдено.';return;}countEl.textContent=`Найдено ${total} элемент(ов)`;

            dirs.forEach(dir=>{
                const row=document.createElement('div');row.className='row dir-row';row.setAttribute('role','listitem');row.tabIndex=0;
                const thumb=document.createElement('div');thumb.className='thumb folder';thumb.innerHTML=fileIcon(dir.name,true);
                const meta=document.createElement('div');meta.className='meta';
                const name=document.createElement('div');name.className='filename';

                const dirNameHighlight = highlightSnippet(dir.name,lastQuery);
                name.innerHTML = dirNameHighlight.html;
                if((dir._dirMatch) || (Array.isArray(dir.children) && dir.children.length>0 && lastQuery)){
                    const matchCount = (dir._dirMatch?1:0) + (Array.isArray(dir.children)?dir.children.reduce((s,c)=>{
                        return s + ((c.name.toLowerCase().includes(lastQuery) && lastQuery)?1:0);
                    },0):0);
                    if(matchCount>0){
                        const mb = document.createElement('span'); mb.className='match-badge'; mb.textContent = matchCount + ' совп.';
                        name.appendChild(mb);
                    }
                }

                const sub=document.createElement('div');sub.className='sub';sub.textContent=`Папка • ${Array.isArray(dir.children)?dir.children.length:0} файл(ов)`;
                meta.appendChild(name);meta.appendChild(sub);

                const btns=document.createElement('div');btns.className='btns';
                const toggle=document.createElement('button');toggle.className='ghost';toggle.innerHTML = '<span class="toggle-arrow">▾</span>';toggle.setAttribute('aria-expanded','false');toggle.title='Открыть папку';toggle.setAttribute('aria-label','Открыть папку');
                btns.appendChild(toggle);
                row.appendChild(thumb);row.appendChild(meta);row.appendChild(btns);
                listEl.appendChild(row);

                const childrenWrap=document.createElement('div');childrenWrap.className='children';childrenWrap.style.display='none';childrenWrap.style.maxHeight='0px';
                (dir.children||[]).forEach(f=>{
                    const crow=document.createElement('div');crow.className='row indent';crow.tabIndex=0;
                    const cthumb=document.createElement('div');cthumb.className='thumb';cthumb.innerHTML=fileIcon(f.name,false);
                    const cmeta=document.createElement('div');cmeta.className='meta';
                    const cname=document.createElement('div');cname.className='filename';
                    const highlighted = highlightSnippet(f.name,lastQuery);
                    cname.innerHTML = highlighted.html;
                    if(highlighted.count>0){const mb=document.createElement('span');mb.className='match-badge';mb.textContent=highlighted.count + ' совп.';cname.appendChild(mb);}
                    const csub=document.createElement('div');csub.className='sub';
                    csub.appendChild(document.createTextNode(`${humanSize(f.size)} • ${fmtDate(f.mtime)} • `));
                    csub.appendChild(createClickableHash(f.type));
                    cmeta.appendChild(cname);cmeta.appendChild(csub);

                    const cbtns=document.createElement('div');cbtns.className='btns';
                    const cdl=document.createElement('a');cdl.className='primary tooltip';cdl.textContent='Скачать';cdl.href=webDir+'/'+encodeURIComponent(dir.name)+'/'+encodeURIComponent(f.name);cdl.setAttribute('download','');cdl.target='_blank';cdl.setAttribute('data-title','Скачать файл');

                    const copyGroup=document.createElement('div');copyGroup.className='copy-group';
                    const ccopy=document.createElement('button');ccopy.className='ghost copy-toggle';ccopy.textContent='Копировать ▾';ccopy.setAttribute('aria-haspopup','true');ccopy.setAttribute('aria-expanded','false');
                    const cmenu=document.createElement('div');cmenu.className='dd-menu';
                    const citem1=document.createElement('a');citem1.href='#';citem1.className='dd-item';citem1.textContent='Скопировать прямую ссылку';
                    const citem2=document.createElement('a');citem2.href='#';citem2.className='dd-item';citem2.textContent='Скопировать команду для Linux (wget)';
                    cmenu.appendChild(citem1);cmenu.appendChild(citem2);

                    const childUrl=window.location.origin+'/'+webDir+'/'+encodeURIComponent(dir.name)+'/'+encodeURIComponent(f.name);
                    ccopy.addEventListener('click',e=>{e.stopPropagation(); setMenuOpen(cmenu, !cmenu.classList.contains('show')); ccopy.setAttribute('aria-expanded', cmenu.classList.contains('show') ? 'true' : 'false');});
                    citem1.addEventListener('click',e=>{e.preventDefault();copyToClipboard(childUrl); setMenuOpen(cmenu,false);});
                    citem2.addEventListener('click',e=>{e.preventDefault();var wget = 'wget -O "' + f.name.replace(/"/g, '\\"') + '" "' + childUrl + '"';copyToClipboard(wget); setMenuOpen(cmenu,false);});

                    copyGroup.appendChild(ccopy);
                    copyGroup.appendChild(cmenu);

                    cbtns.appendChild(cdl);
                    cbtns.appendChild(copyGroup);
                    crow.appendChild(cthumb);crow.appendChild(cmeta);crow.appendChild(cbtns);
                    childrenWrap.appendChild(crow);

                    crow.addEventListener('keydown', e=>{ if(e.key==='Enter'){ const a = crow.querySelector('a.primary'); if(a) a.click(); } if(e.key===' '){ e.preventDefault(); const cb = crow.querySelector('.copy-toggle'); if(cb) cb.click(); } });
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
                    if(isOpen){
                        const arrow = toggle.querySelector('.toggle-arrow'); if(arrow) arrow.classList.remove('expanded');
                        animateClose(childrenWrap);
                        childrenWrap.classList.remove('open');
                        toggle.setAttribute('aria-expanded','false');
                    } else {
                        const arrow = toggle.querySelector('.toggle-arrow'); if(arrow) arrow.classList.add('expanded');
                        animateOpen(childrenWrap);
                        childrenWrap.classList.add('open');
                        toggle.setAttribute('aria-expanded','true');
                    }
                });

                row.addEventListener('keydown', e=>{ if(e.key==='Enter'){ row.click(); } if(e.key===' '){ e.preventDefault(); const cb = row.querySelector('.copy-toggle'); if(cb) cb.click(); } });

            });

            files.forEach(f=>{
                const row=document.createElement('div');row.className='row';row.setAttribute('role','listitem');row.tabIndex=0;
                const thumb=document.createElement('div');thumb.className='thumb';thumb.innerHTML=fileIcon(f.name,false);
                const meta=document.createElement('div');meta.className='meta';
                const name=document.createElement('div');name.className='filename';
                const highlighted = highlightSnippet(f.name,lastQuery);
                name.innerHTML = highlighted.html;
                if(highlighted.count>0){const mb=document.createElement('span');mb.className='match-badge';mb.textContent=highlighted.count + ' совп.';name.appendChild(mb);}
                const sub=document.createElement('div');sub.className='sub';
                sub.appendChild(document.createTextNode(`${humanSize(f.size)} • ${fmtDate(f.mtime)} • `));
                sub.appendChild(createClickableHash(f.type));
                meta.appendChild(name);meta.appendChild(sub);

                const btns=document.createElement('div');btns.className='btns';
                const dl=document.createElement('a');dl.className='primary tooltip';dl.textContent='Скачать';dl.href=webDir+'/'+encodeURIComponent(f.name);dl.setAttribute('download','');dl.target='_blank';dl.setAttribute('data-title','Скачать файл');

                const group=document.createElement('div');group.className='copy-group';
                const copyBtn=document.createElement('button');copyBtn.className='ghost copy-toggle';copyBtn.textContent='Копировать ▾';copyBtn.setAttribute('aria-haspopup','true');copyBtn.setAttribute('aria-expanded','false');
                const menu=document.createElement('div');menu.className='dd-menu';
                const item1=document.createElement('a');item1.href='#';item1.className='dd-item';item1.textContent='Скопировать прямую ссылку';
                const item2=document.createElement('a');item2.href='#';item2.className='dd-item';item2.textContent='Скопировать команду для Linux (wget)';
                menu.appendChild(item1);menu.appendChild(item2);

                const fileUrl=window.location.origin+'/'+webDir+'/'+encodeURIComponent(f.name);
                copyBtn.addEventListener('click',e=>{e.stopPropagation(); setMenuOpen(menu, !menu.classList.contains('show')); copyBtn.setAttribute('aria-expanded', menu.classList.contains('show') ? 'true' : 'false');});
                item1.addEventListener('click',e=>{e.preventDefault();copyToClipboard(fileUrl); setMenuOpen(menu,false);});
                item2.addEventListener('click',e=>{e.preventDefault();var wget = 'wget -O "' + f.name.replace(/"/g, '\\"') + '" "' + fileUrl + '"';copyToClipboard(wget); setMenuOpen(menu,false);});

                group.appendChild(copyBtn);
                group.appendChild(menu);

                btns.appendChild(dl);btns.appendChild(group);row.appendChild(thumb);row.appendChild(meta);row.appendChild(btns);listEl.appendChild(row);

                row.addEventListener('keydown', e=>{ if(e.key==='Enter'){ const a = row.querySelector('a.primary'); if(a) a.click(); } if(e.key===' '){ e.preventDefault(); const cb = row.querySelector('.copy-toggle'); if(cb) cb.click(); } });
            });
        }

        document.addEventListener('click',function(e){ if(!e.target.closest('.copy-group') && !e.target.classList.contains('ghost')){ document.querySelectorAll('.dd-menu.show').forEach(m=>setMenuOpen(m,false));}});

        function apply(){
            const q=(searchInput.value||'').trim().toLowerCase();
            lastQuery = q;
            let items=Array.isArray(FILES)?FILES.slice():[];
            if(q){
                items = items.map(it=>{
                    if(it.type==='dir'){
                        const dirMatch = it.name.toLowerCase().includes(q);
                        const filteredChildren = (it.children||[]).filter(c=>c.name.toLowerCase().includes(q));
                        return Object.assign({}, it, { children: filteredChildren, _dirMatch: dirMatch });
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
                    if(a.type==='dir' && b.type==='dir') return a.name.localeCompare(b.name);
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

        document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ document.querySelectorAll('.dd-menu.show').forEach(m=>setMenuOpen(m,false)); } });
    </script>
</body>
</html>
