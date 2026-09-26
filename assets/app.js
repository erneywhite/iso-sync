// ISO Workbench — Erney White. Интерфейс iso-sync: данные — window.ISO_ARCHIVE_DATA (готовит index.php).
(()=>{
const root=document.getElementById('iso-designs');
const catalog=window.ISO_ARCHIVE_DATA.catalog;
const history=window.ISO_ARCHIVE_DATA.history;
const missingData=Array.isArray(window.ISO_ARCHIVE_DATA.missing)?window.ISO_ARCHIVE_DATA.missing:[];
const meta=window.ISO_ARCHIVE_DATA.meta||{};
const $=id=>root.querySelector('#'+id);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// Русские формы множественного числа (тот же алгоритм, что $uiPlural в index.php).
const plural=(n,o,f,m)=>{n=((n%100)+100)%100;if(n>=11&&n<=14)return m;return{1:o,2:f,3:f,4:f}[n%10]||m};
const symbols={all:'≡',AlmaLinux:'Al',ArchLinux:'Ar',CentOS:'Ce',Debian:'De',Proxmox:'Px',Ubuntu:'Ub',Windows:'Wi'};
const notes={all:'Дистрибутивы, драйверы и утилиты',AlmaLinux:'Образы AlmaLinux в вашем архиве',ArchLinux:'Установочный образ Arch Linux',CentOS:'Версии CentOS в вашем архиве',Debian:'Установочные образы Debian',Proxmox:'VE, Backup Server и Mail Gateway',Ubuntu:'Образы Ubuntu в вашем архиве',Windows:'WinPE, драйверы и утилиты'};
const family=n=>n.startsWith('ProxmoxVE_')?'Proxmox VE':n.startsWith('Proxmox_BackUP_')?'Proxmox Backup':n.startsWith('Proxmox_MailGateway_')?'Proxmox Mail Gateway':n.split('_')[0];
const compare=(a,b)=>b.name.localeCompare(a.name,'en',{numeric:true});
const files=catalog.flatMap(g=>(g.children||[]).map(f=>({...f,group:g.name,groupPrivate:!!g.private,id:g.name+'/'+f.name})).concat(g.type!=='dir'?[{...g,group:'',groupPrivate:false,id:g.name}]:[]));
const newest=new Set();catalog.forEach(g=>{const grouped={};(g.children||[]).forEach(f=>(grouped[family(f.name)]??=[]).push(f));Object.values(grouped).forEach(a=>{a.sort(compare);if(a.length>1||/_\d/.test(a[0].name)&&g.name!=='Windows')newest.add(g.name+'/'+a[0].name)})});
// Единицу выбираем по размеру (как в старом humanSize): B/KB/MB/GB/TB.
const size=n=>{if(!n)return'0 B';const u=['B','KB','MB','GB','TB'];let i=0,x=n;while(x>=1024&&i<u.length-1){x/=1024;i++}return(x<10?x.toLocaleString('ru-RU',{minimumFractionDigits:1,maximumFractionDigits:1}):Math.round(x).toLocaleString('ru-RU'))+' '+u[i];};
const fmtIso=iso=>{if(!iso)return'—';try{return new Date(iso).toLocaleString('ru-RU')}catch(e){return iso}};
const date=n=>new Date(n*1000).toLocaleDateString('ru-RU',{timeZone:'Europe/Riga'});
const fmtDur=s=>{s=Math.max(0,Math.round(s||0));const m=Math.floor(s/60),ss=s%60;return m?(m+' мин '+(ss?ss+' сек':'')):(ss+' сек')};
const url=f=>window.location.origin+'/files/'+(f.group?encodeURIComponent(f.group)+'/':'')+encodeURIComponent(f.name);
const clean=f=>f.name.replace(/\.iso$/i,'').replace(/_/g,' ');
const saved={}; // При открытии показываем утверждённый Workbench / Ubuntu.
let state={mode:'workbench',group:catalog.some(g=>g.name===saved.group)||saved.group==='all'?saved.group:catalog.some(g=>g.name==='Ubuntu')?'Ubuntu':(catalog[0]||{name:'all'}).name,query:'',sort:'version',selected:'',radius:14};
const initial=files.filter(f=>state.group==='all'||f.group===state.group).sort(compare)[0];state.selected=initial?initial.id:'';
// Общий размер и дата последней проверки: строки ISO из index.php форматируем
// в браузере (PHP живёт в UTC-зоне сервера, даты на странице — локальные).
try{$('total-size').textContent=size(meta.total_size||0)}catch(_){}
try{$('last-check').textContent=fmtIso(meta.last_check)}catch(_){}
// ===== Сводка последней проверки (meta.last_run, из logs/last_run.json) =====
// Состояния: fatal (ошибка прогона), ошибки (failed>0 — выделяем), частичная
// проверка ("only" — явно подписываем, чтобы не выглядела полным сканом),
// обычный прогон (длительность + обновлено/актуально/пропущено/ошибки),
// прогон без сводки (только время), ещё не запускалось (нет last_run.json).
function renderCheck(){const el=$('check');if(!el)return;const lr=meta.last_run;if(!lr){el.innerHTML='<span class="smallcaps">СТАТУС ПРОВЕРКИ</span><span class="check-line">Проверка ещё не запускалась</span><span class="check-sub">Запустите php update_iso.php — сводка появится здесь.</span>';return}
const at=lr.finished_at||lr.started_at;
let html='<span class="smallcaps">СТАТУС ПРОВЕРКИ</span>';
if(lr.fatal){html+=`<span class="check-line bad">Ошибочное завершение</span><span class="check-sub bad">${esc(lr.fatal)}</span>${at?`<span class="check-sub">${esc(fmtIso(at))}</span>`:''}`}
else{const failed=(typeof lr.failed==='number')?lr.failed:0;
if(failed>0)html+=`<span class="check-line bad">Ошибки: ${failed} ${plural(failed,'файл','файла','файлов')}</span>`;
html+=`<span class="check-sub">${at?esc(fmtIso(at)):'время неизвестно'}${typeof lr.duration_s==='number'?` · заняла ${esc(fmtDur(lr.duration_s))}`:''}</span>`;
if(Array.isArray(lr.only)&&lr.only.length){const names=lr.only.map(String).map(esc).join(', ');html+=`<span class="check-sub warn">Частичная проверка: только ${names}</span>`}
if(typeof lr.updated==='number'||typeof lr.up_to_date==='number'||typeof lr.skipped==='number'||typeof lr.failed==='number'){
const parts=[];
if(lr.updated>0)parts.push(`<span class="ok">${lr.updated} ${plural(lr.updated,'обновлено','обновлено','обновлено')}</span>`);
if(lr.up_to_date>0)parts.push(`${lr.up_to_date} ${plural(lr.up_to_date,'актуально','актуально','актуально')}</span>`);
if(lr.skipped>0)parts.push(`${lr.skipped} ${plural(lr.skipped,'пропущено','пропущено','пропущено')}</span>`);
if(lr.failed>0)parts.push(`<span class="bad">${lr.failed} ${plural(lr.failed,'ошибка','ошибки','ошибок')}</span>`);
if(parts.length)html+=`<span class="check-sub">${parts.join(' · ')}</span>`;}}
el.innerHTML=html}
renderCheck();
// ===== Отсутствующие файлы (missing): ожидаются по конфигу, а на диске нет =====
function renderMissing(){const el=$('missing');if(!el)return;const list=missingData.filter(m=>m&&m.name);if(!list.length){el.innerHTML='';return}
el.innerHTML=`<div class="panel"><div class="panel-top"><i data-lucide="alert-triangle" aria-hidden="true"></i><span>ОТСУТСТВУЮЩИЕ ФАЙЛЫ</span></div><p>${list.length} ${plural(list.length,'файл','файла','файлов')} — в конфигурации, но не найдены на диске</p>`+list.map(m=>`<div class="missing-row"><div><strong>${esc(m.name)}</strong><small>${esc(m.subdir?m.subdir+'/':'/')}</small></div><small class="remote" title="${esc(m.remote||'')}">${esc((m.remote||'').split('/').pop()||'')}</small></div>`).join('')+'</div>'}
renderMissing();
function persist(){} // В демонстрации состояние хранится только до перезагрузки.
function icons(){if(globalThis.lucide)lucide.createIcons({attrs:{width:18,height:18}})}
function visibleFiles(){return files.filter(f=>(state.query?true:(state.group==='all'||f.group===state.group))&&(!state.query||(f.name+' '+f.group+' '+f.type).toLowerCase().includes(state.query.toLowerCase()))).sort(state.sort==='date'?(a,b)=>b.mtime-a.mtime:state.sort==='size'?(a,b)=>b.size-a.size:compare)}
function render(){root.dataset.mode=state.mode;root.style.setProperty('--radius',state.mode==='console'?'2px':state.radius+'px');root.querySelectorAll('.comparebar button').forEach(b=>{b.classList.toggle('active',b.dataset.mode===state.mode);b.setAttribute('aria-pressed',b.dataset.mode===state.mode?'true':'false')});$('folders').innerHTML=[{name:'all',label:'Все файлы',count:files.length,priv:false},...catalog.map(g=>({name:g.name,label:g.name,count:(g.children||[]).length,priv:!!g.private}))].map(g=>`<button class="folder cursor-interaction ${state.group===g.name&&!state.query?'active':''}" data-folder="${esc(g.name)}" aria-pressed="${state.group===g.name&&!state.query}"><span class="folder-mark">${symbols[g.name]||'⌘'}</span>${g.label}${g.priv?'<span class="priv-badge priv-badge-sm"><i data-lucide="lock" aria-hidden="true"></i>PRIVATE</span>':''}<span class="folder-count">${g.count}</span></button>`).join('');
const list=visibleFiles();$('section-title').innerHTML=(state.query?'Поиск':state.group==='all'?'Все образы':esc(state.group))+'<span class="title-dot">.</span>';$('section-description').textContent=state.query?'Результаты по всему публичному архиву':state.group==='all'?'Дистрибутивы, драйверы и утилиты':(notes[state.group]||state.group);$('group-count').textContent=list.length;$('results-count').textContent=state.query?`Найдено: ${list.length} ${plural(list.length,'файл','файла','файлов')}`:`Файлов: ${list.length}`;$('clear-query').hidden=!state.query;$('empty').hidden=list.length>0||!state.query;if(!list.length&&state.query)$('empty').innerHTML='<i data-lucide="search-x" aria-hidden="true"></i><h2>Ничего не найдено</h2><p>Попробуйте название системы, часть имени или SHA-256.</p><button class="cursor-interaction quiet" id="reset-search">Сбросить поиск</button>';
const featured=!state.query&&state.group!=='all'?list.find(f=>newest.has(f.id)):null;
$('feature').innerHTML=featured?`<div class="featured"><div class="featured-symbol">${symbols[featured.group]||'⌘'}</div><div class="featured-info"><div class="featured-label">НОВЕЙШАЯ ВЕРСИЯ В АРХИВЕ</div><h2>${esc(clean(featured))}</h2><p>${size(featured.size)} <span class="sep">/</span> ${date(featured.mtime)} <span class="sep">/</span> SHA-256</p></div><a class="primary cursor-interaction" href="${url(featured)}" target="_blank" rel="noopener"><i data-lucide="download" aria-hidden="true"></i> Скачать ISO</a></div>`:'';
if(['console','workbench'].includes(state.mode)&&!list.some(f=>f.id===state.selected))state.selected=list[0]?.id||'';
$('file-list').innerHTML=list.length?'<div class="list-header"><span>Имя файла</span><span>Размер</span><span>Дата файла</span><span></span></div>'+list.map(f=>`<article class="file-row ${state.selected===f.id?'selected':''}"><button class="file-name cursor-interaction" data-file="${esc(f.id)}" aria-label="Сведения: ${esc(f.name)}"><strong>${esc(f.name)}${newest.has(f.id)?'<span class="latest">LATEST</span>':''}</strong><small>${state.query||state.group==='all'?esc(f.group)+' / ':''}${state.mode==='workbench'?date(f.mtime)+(hashOk(f)?' · SHA-256':' · хэш не готов'):(hashOk(f)?esc(f.type.slice(0,19))+'…':'хэш не готов')}</small></button><span class="file-size">${size(f.size)}</span><span class="file-date">${date(f.mtime)}</span><a class="row-download cursor-interaction" aria-label="Скачать ${esc(f.name)}" href="${url(f)}" target="_blank" rel="noopener"><i data-lucide="download" aria-hidden="true"></i> Скачать</a></article>`).join(''):'';if(!list.length&&!state.query)emptyList($('file-list'));
renderInspector();icons();}
let inspectorOpen=false;
root.addEventListener('click',async e=>{const mode=e.target.closest('button[data-mode]');if(mode){state.mode=mode.dataset.mode;render();persist();return}const folder=e.target.closest('[data-folder]');if(folder){state.group=folder.dataset.folder;state.query='';$('iso-query').value='';inspectorOpen=false;state.selected='';render();persist();return}const file=e.target.closest('[data-file]');if(file){state.selected=file.dataset.file;inspectorOpen=true;render();return}if(e.target.closest('[data-close]')){inspectorOpen=false;render();return}if(e.target.closest('#reset-search')){clear();return}const copy=e.target.closest('[data-copy]');if(copy){const f=files.find(f=>f.id===state.selected);const text=copy.dataset.copy==='hash'?(/^sha256:[0-9a-f]{64}$/i.test(f.type)?f.type.replace('sha256:',''):''):copy.dataset.copy==='url'?url(f):`wget -O "${f.name}" "${url(f)}"`;try{await navigator.clipboard.writeText(text);$('copy-message').textContent='Скопировано в буфер обмена.'}catch{$('copy-message').textContent='Буфер обмена недоступен в этом просмотре. Можно выделить и скопировать текст выше.'}}});
$('iso-query').addEventListener('input',e=>{state.query=e.target.value.trim();render()});$('sort').addEventListener('change',e=>{state.sort=e.target.value;render()});const clear=()=>{state.query='';$('iso-query').value='';render();$('iso-query').focus()};$('clear-query').onclick=clear;
// Пустые состояния: архив пуст / в разделе нет файлов / история пуста / файл
// без хэша — у каждого своё сообщение, без «undefined» и пустых коробок.
const hashOk=f=>/^sha256:[0-9a-f]{64}$/i.test(f.type);
const privBadge=on=>on?'<span class="priv-badge"><i data-lucide="lock" aria-hidden="true"></i>PRIVATE</span>':'';
const emptyList=el=>{if(!el)return;el.innerHTML='<div class="panel"><div class="panel-top"><i data-lucide="inbox" aria-hidden="true"></i><span>ФАЙЛЫ</span></div><p>В этом разделе пока нет файлов.</p></div>'};
if(!files.length){$('file-list').innerHTML='';$('inspector').innerHTML='';$('feature').innerHTML='';$('results-count').textContent='Файлов: 0';$('empty').hidden=false;$('empty').innerHTML='<i data-lucide="inbox" aria-hidden="true"></i><h2>Архив пуст</h2><p>Файлы появятся после запуска синхронизации.</p>';icons()}else{render()}
function renderInspector(){const f=files.find(f=>f.id===state.selected);if(!f||(!inspectorOpen&&!['console','workbench'].includes(state.mode))||!visibleFiles().some(x=>x.id===f.id)){$('inspector').innerHTML='';return}
const h=hashOk(f);
const wget=`wget -O "${f.name}" "${url(f)}"`;
$('inspector').innerHTML=`<div class="inspect-panel"><div class="inspect-top"><span>СВЕДЕНИЯ О ФАЙЛЕ</span>${privBadge(f.private||f.groupPrivate||false)}</div><h2>${esc(f.name)}</h2><dl><div><dt>Размер</dt><dd>${size(f.size)}</dd></div><div><dt>Дата файла</dt><dd>${date(f.mtime)}</dd></div></dl><div class="data-label"><span>SHA-256</span>${h?'<button class="copy-small cursor-interaction" data-copy="hash">Копировать</button>':''}</div>${h?`<code>${esc(f.type.replace('sha256:',''))}</code>`:`<div class="hash-note"><i data-lucide="file-question" aria-hidden="true"></i>Хэш пока недоступен — он появится после запуска проверки</div>`}<div class="data-label"><span>ПРЯМАЯ ССЫЛКА</span><button class="copy-small cursor-interaction" data-copy="url">Копировать</button></div><code>${esc(url(f))}</code><div class="data-label"><span>WGET</span><button class="copy-small cursor-interaction" data-copy="wget">Копировать</button></div><code>${esc(wget)}</code><a class="primary cursor-interaction" href="${url(f)}" target="_blank" rel="noopener"><i data-lucide="download" aria-hidden="true"></i> Скачать файл</a></div>`}
// История: события + пустое состояние, когда лога нет.
function renderHistory(){const el=$('history-items');if(!el)return;if(!history.length){el.innerHTML='<div class="panel"><p>Событий пока нет — история появится после первого обновления файлов.</p></div>';return}
el.innerHTML=history.map(h=>{let d='';try{d=new Date(h.ts).toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',timeZone:'Europe/Riga'})}catch(_){d=String(h.ts||'')}const k=h.kind==='updated'?'Обновлено':h.kind==='cleanup'?'Удалено':h.kind==='failed'?'Не скачано':String(h.kind||'');const x=h.extra?`<span class="history-extra" style="color:var(--muted);font-size:12px">${esc(h.extra)}</span>`:'';return`<div class="history-row"><time>${esc(d)}</time><span class="history-kind">${esc(k)}</span><strong>${esc(h.file||'?')}</strong>${x}</div>`}).join('')}
renderHistory();
$('history-open').onclick=()=>{$('history').hidden=!$('history').hidden;if(!$('history').hidden)$('history').scrollIntoView({block:'nearest',behavior:'instant'})};$('history-close').onclick=()=>{$('history').hidden=true;$('history-open').focus()};
root.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();$('iso-query').focus()}if(e.key==='Escape'){state.query='';$('iso-query').value='';inspectorOpen=false;$('history').hidden=true;render()}});

})();
