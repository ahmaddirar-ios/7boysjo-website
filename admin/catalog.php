<?php
require_once __DIR__ . '/config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

$products = array_values(load_products());
$cats = load_cats();
$brands = load_brands();
usort($products, fn($a,$b)=> ($a['order']??0) <=> ($b['order']??0));
$catMap = [];
foreach($cats as $c) $catMap[$c['slug']] = $c['name'];
$prodJson = json_encode($products, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Catalog Builder — 7 Boys®</title>
<link rel="stylesheet" href="/assets/css/hdr3.css?v=<?=filemtime(__DIR__.'/../assets/css/hdr3.css')?>">
<style>
.catalog-admin{max-width:1140px;margin:0 auto;padding:28px 20px 60px}
.ca-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.ca-head h1{font-family:var(--display);font-size:28px;font-weight:800;letter-spacing:-.02em}
.ca-head p{color:var(--muted);font-size:14px}
.ca-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:14px 16px;box-shadow:var(--shadow-sm);position:sticky;top:14px;z-index:10;margin-bottom:18px}
.ca-toolbar input[type=search]{flex:1;min-width:200px;height:40px;padding:0 14px;border:1.5px solid var(--line);border-radius:10px;font-size:14px}
.ca-toolbar select{height:40px;padding:0 12px;border:1.5px solid var(--line);border-radius:10px;font-weight:600;font-size:13px;background:var(--card)}
.ca-count{background:var(--green);color:#fff;padding:6px 14px;border-radius:999px;font-weight:700;font-size:13px}
.btn-sm{height:40px;padding:0 16px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:700;cursor:pointer}
.btn-sm.primary{background:var(--green);color:#fff;border-color:var(--green)}
.btn-sm.primary:disabled{opacity:.5;cursor:not-allowed}
.btn-sm:hover{transform:translateY(-1px)}
.options-card{background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:18px 20px;box-shadow:var(--shadow-sm);margin-bottom:18px}
.options-card h3{font-family:var(--display);font-size:16px;font-weight:800;margin-bottom:14px;display:flex;gap:8px;align-items:center}
.opt-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:700px){.opt-grid{grid-template-columns:1fr}}
.opt-grid label{font-size:13px;font-weight:600;color:var(--ink);display:flex;flex-direction:column;gap:6px}
.opt-grid input[type=text], .opt-grid select, .opt-grid input[type=file]{height:40px;padding:0 12px;border:1.5px solid var(--line);border-radius:10px;font-size:13px;background:#fff}
.opt-row{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px}
.opt-row label{flex-direction:row;align-items:center;gap:6px;font-size:13px}
.cat-group{margin:22px 0 8px;padding:8px 4px;border-left:4px solid var(--gold);font-family:var(--display);font-weight:800;font-size:20px}
.prod-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
@media(max-width:1000px){.prod-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:680px){.prod-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:440px){.prod-grid{grid-template-columns:1fr}}
.p-card{border:1.5px solid var(--card-line);border-radius:16px;overflow:hidden;background:var(--card);transition:.2s;position:relative}
.p-card.selected{border-color:var(--green);box-shadow:0 8px 24px rgba(46,125,79,.18);transform:translateY(-2px)}
.p-card .chk{position:absolute;top:10px;right:10px;width:22px;height:22px;accent-color:var(--green);z-index:2}
.p-card .img{height:150px;background:var(--bg-soft);display:flex;align-items:center;justify-content:center;overflow:hidden}
.p-card .img img{width:100%;height:100%;object-fit:cover}
.p-card .bd{padding:12px 14px}
.p-card .tag{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--green)}
.p-card h3{font-size:14px;font-weight:700;margin:4px 0;line-height:1.3;min-height:36px}
.p-card p{font-size:12.5px;color:var(--muted);line-height:1.4;min-height:32px}
.p-card .orig{margin-top:8px;font-size:11px;font-weight:600;background:var(--green-soft);color:var(--green-dark);padding:3px 8px;border-radius:999px;display:inline-block}
.empty{padding:40px;text-align:center;color:var(--muted);background:var(--card);border:1px dashed var(--line);border-radius:16px}
.cover-preview{height:36px;border-radius:999px;padding:0 14px;font-weight:700;border:1.5px solid var(--line);cursor:pointer}
.cover-preview.green{background:#1f5a38;color:#fff;border-color:#1f5a38}
.cover-preview.gold{background:linear-gradient(135deg,#b8923f,#d4b36a);color:#fff;border-color:#b8923f}
.cover-preview.light{background:#fff;color:var(--ink)}
</style>
</head>
<body style="background:var(--bg)">
<div class="catalog-admin">
  <a href="/admin/" style="display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--green);margin-bottom:14px">← Back to Admin</a>
  <div class="ca-head">
    <div>
      <h1>📚 Catalog Builder</h1>
      <p>Select products → Generate a premium PDF catalog with images</p>
    </div>
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
      <span class="ca-count" id="countBadge">0 selected</span>
      <button class="btn-sm primary" id="genBtn" disabled onclick="generate()">Generate Catalog →</button>
    </div>
  </div>

  <div class="ca-toolbar">
    <input type="search" id="q" placeholder="Search products, brands, origin..." oninput="filter()">
    <select id="catFilter" onchange="filter()"><option value="">All Categories</option><?php foreach($cats as $c) echo '<option value="'.esc($c['slug']).'">'.esc($c['name']).'</option>'; ?></select>
    <select id="brandFilter" onchange="filter()"><option value="">All Brands</option><?php foreach($brands as $b) echo '<option value="'.esc($b['slug']).'">'.esc($b['name']).'</option>'; ?></select>
    <button class="btn-sm" onclick="selectAll(true)">Select all (filtered)</button>
    <button class="btn-sm" onclick="selectAll(false)">Clear</button>
  </div>

  <form id="catalogForm" method="POST" action="catalog_view.php" target="_blank" enctype="multipart/form-data" onsubmit="return prepareSubmit()">
    <div class="options-card">
      <h3>⚙️ Catalog Options — 1+2+3</h3>
      <div class="opt-grid">
        <label>Catalog title
          <input type="text" id="catalogTitle" value="7 Boys — Product Catalog">
        </label>
        <label>Prepared for (client name)
          <input type="text" id="clientName" placeholder="e.g. Marriott Hotels, Amman">
        </label>
        <label>Cover theme
          <select id="coverTheme">
            <option value="green">Green Premium (default)</option>
            <option value="gold">Gold Elegant</option>
            <option value="light">Light Minimal</option>
          </select>
        </label>
        <label>Sort by
          <select id="sortBy">
            <option value="category">By Category (default)</option>
            <option value="brand">By Brand</option>
            <option value="name">By Name (A-Z)</option>
          </select>
        </label>
        <label>Client logo (optional)
          <input type="file" id="clientLogo" accept="image/*">
        </label>
        <label>Logo preview
          <div id="logoPreview" style="height:40px;border:1.5px dashed var(--line);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;background:var(--bg-soft)">No logo selected</div>
        </label>
      </div>
      <div class="opt-row">
        <label><input type="checkbox" id="showDesc" checked> Show description</label>
        <label><input type="checkbox" id="showOrigin" checked> Show origin</label>
        <label><input type="checkbox" id="showPrice"> Show price</label>
        <label><input type="checkbox" id="showCode"> Show product code</label>
      </div>
    </div>

    <div id="gridWrap"></div>
    <input type="hidden" name="title" id="catalogTitleInput" value="">
    <input type="hidden" name="clientName" id="clientNameInput" value="">
    <input type="hidden" name="coverTheme" id="coverThemeInput" value="green">
    <input type="hidden" name="sortBy" id="sortByInput" value="category">
  </form>
</div>

<script>
const products = <?=$prodJson?>;
const catMap = <?=json_encode($catMap, JSON_UNESCAPED_UNICODE)?>;
let selected = new Set();
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;')}
function render(){
  const q = (document.getElementById('q').value||'').toLowerCase();
  const cf = document.getElementById('catFilter').value;
  const bf = document.getElementById('brandFilter').value;
  const wrap = document.getElementById('gridWrap');
  wrap.innerHTML='';
  const groups = {};
  products.forEach((p,i)=>{
    const hay = (p.name+' '+(p.brand||'')+' '+(p.origin||'')+' '+(p.desc||'')).toLowerCase();
    if(q && !hay.includes(q)) return;
    if(cf && p.cat!==cf) return;
    if(bf && p.brand!==bf) return;
    const g = p.cat||'other';
    if(!groups[g]) groups[g]=[];
    groups[g].push({p,idx:i});
  });
  const cats = Object.keys(groups);
  if(!cats.length){ wrap.innerHTML='<div class="empty">No products match your filters</div>'; return; }
  cats.forEach(cat=>{
    const title = catMap[cat]||cat;
    const h = document.createElement('div');
    h.className='cat-group';
    h.textContent = title + ' ('+groups[cat].length+')';
    wrap.appendChild(h);
    const grid = document.createElement('div');
    grid.className='prod-grid';
    groups[cat].forEach(({p,idx})=>{
      const id = p.id ?? idx;
      const isSel = selected.has(String(id));
      const card = document.createElement('label');
      card.className='p-card'+(isSel?' selected':'');
      card.innerHTML = `
        <input type="checkbox" class="chk" ${isSel?'checked':''} onchange="toggle('${id}',this.checked)">
        <div class="img"><img src="/assets/img/${esc(p.image||'prod-1.jpg')}" alt="" loading="lazy" onerror="this.src='/assets/img/logo.png'"></div>
        <div class="bd">
          <div class="tag">${esc(p.brand||'')}</div>
          <h3>${esc(p.name||'')}</h3>
          <p>${esc((p.desc||'').slice(0,80))}</p>
          <span class="orig">${esc(p.origin||'')}</span>
        </div>
      `;
      card.addEventListener('click', (e)=>{
        if(e.target.classList.contains('chk')) return;
        const cb = card.querySelector('.chk');
        cb.checked = !cb.checked;
        toggle(String(id), cb.checked);
      });
      grid.appendChild(card);
    });
    wrap.appendChild(grid);
  });
  updateCount();
}
function toggle(id, on){ if(on) selected.add(String(id)); else selected.delete(String(id)); render(); }
function selectAll(on){
  const q = (document.getElementById('q').value||'').toLowerCase();
  const cf = document.getElementById('catFilter').value;
  const bf = document.getElementById('brandFilter').value;
  products.forEach(p=>{
    const hay = (p.name+' '+(p.brand||'')+' '+(p.origin||'')+' '+(p.desc||'')).toLowerCase();
    if(q && !hay.includes(q)) return;
    if(cf && p.cat!==cf) return;
    if(bf && p.brand!==bf) return;
    const id = String(p.id ?? products.indexOf(p));
    if(on) selected.add(id); else selected.delete(id);
  });
  render();
}
function filter(){ render(); }
function updateCount(){
  const n = selected.size;
  document.getElementById('countBadge').textContent = n+' selected';
  document.getElementById('genBtn').disabled = n===0;
}
document.getElementById('clientLogo').addEventListener('change', function(){
  const f = this.files[0];
  const prev = document.getElementById('logoPreview');
  if(!f){ prev.textContent='No logo selected'; prev.innerHTML='No logo selected'; return; }
  const r = new FileReader();
  r.onload = ()=>{ prev.innerHTML='<img src="'+r.result+'" style="height:32px;object-fit:contain">'; };
  r.readAsDataURL(f);
});
function prepareSubmit(){
  if(!selected.size){ alert('Select at least one product'); return false; }
  const form = document.getElementById('catalogForm');
  form.querySelectorAll('input[name="ids[]"]').forEach(e=>e.remove());
  form.querySelectorAll('input[name="showDesc"],input[name="showOrigin"],input[name="showPrice"],input[name="showCode"]').forEach(e=>e.remove());
  selected.forEach(id=>{
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  document.getElementById('catalogTitleInput').value = document.getElementById('catalogTitle').value;
  document.getElementById('clientNameInput').value = document.getElementById('clientName').value;
  document.getElementById('coverThemeInput').value = document.getElementById('coverTheme').value;
  document.getElementById('sortByInput').value = document.getElementById('sortBy').value;
  // we need to move file input into form (it already is) — just add hidden flags
  const addFlag = (id,name)=>{
    const el=document.getElementById(id);
    const inp=document.createElement('input');
    inp.type='hidden'; inp.name=name; inp.value=el.checked?'1':'0';
    form.appendChild(inp);
  };
  addFlag('showDesc','showDesc');
  addFlag('showOrigin','showOrigin');
  addFlag('showPrice','showPrice');
  addFlag('showCode','showCode');
  // file input already in form with name clientLogo — ensure it has name
  const fileInp=document.getElementById('clientLogo');
  fileInp.name='clientLogo';
  return true;
}
function generate(){ if(prepareSubmit()) document.getElementById('catalogForm').submit(); }
render();
</script>
</body>
</html>
