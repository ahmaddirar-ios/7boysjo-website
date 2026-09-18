<?php
require_once __DIR__ . '/config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

$all = array_values(load_products());
$byId = [];
foreach($all as $p){
  $id = $p['id'] ?? null;
  if($id!==null) $byId[(string)$id] = $p;
}
foreach($all as $idx=>$p){
  if(!isset($byId[(string)$idx])) $byId[(string)$idx] = $p;
}

$ids = $_POST['ids'] ?? $_GET['ids'] ?? [];
if(is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
if(!is_array($ids)) $ids = [];
$ids = array_values(array_filter(array_map('strval', $ids)));
if(!count($ids)){
  $demo = array_filter($all, fn($p)=>!empty($p['featured']));
  if(count($demo)<8) $demo = array_slice($all,0,12);
  $selected = array_values($demo);
  $title = '7 Boys — Featured Catalog';
} else {
  $selected = [];
  foreach($ids as $id){
    if(isset($byId[$id])) $selected[] = $byId[$id];
  }
  $title = trim($_POST['title'] ?? $_GET['title'] ?? '7 Boys — Product Catalog');
  if($title==='') $title='7 Boys — Product Catalog';
}

$showDesc = isset($_POST['showDesc']) ? $_POST['showDesc']=='1' : (($_GET['showDesc']??'1')=='1');
$showOrigin = isset($_POST['showOrigin']) ? $_POST['showOrigin']=='1' : (($_GET['showOrigin']??'1')=='1');
$showPrice = isset($_POST['showPrice']) ? $_POST['showPrice']=='1' : (($_GET['showPrice']??'0')=='1');
$showCode = isset($_POST['showCode']) ? $_POST['showCode']=='1' : (($_GET['showCode']??'0')=='1');
$coverTheme = $_POST['coverTheme'] ?? $_GET['coverTheme'] ?? 'green';
if(!in_array($coverTheme, ['green','gold','light'])) $coverTheme='green';
$sortBy = $_POST['sortBy'] ?? $_GET['sortBy'] ?? 'category';
$clientName = trim($_POST['clientName'] ?? $_GET['clientName'] ?? '');

// client logo handling (upload or GET param)
$clientLogoData = '';
if(isset($_FILES['clientLogo']) && is_uploaded_file($_FILES['clientLogo']['tmp_name'])){
  $tmp = $_FILES['clientLogo']['tmp_name'];
  $type = mime_content_type($tmp);
  if(in_array($type, ['image/jpeg','image/png','image/webp'])){
    $data = file_get_contents($tmp);
    $clientLogoData = 'data:'.$type.';base64,'.base64_encode($data);
  }
} elseif(!empty($_GET['clientLogoData'])){
  // SECURITY: only accept data:image/(jpeg|png|webp);base64 URIs — blocks data:text/html injection
  if(preg_match('#\Adata:image/(?:jpeg|png|webp);base64,[a-zA-Z0-9+/=\r\n]+\z#', $_GET['clientLogoData'])){
    $clientLogoData = $_GET['clientLogoData'];
  }
}

$settings = load_settings();
$cats = load_cats();
$catMap = [];
foreach($cats as $c) $catMap[$c['slug']] = $c['name'];

// sort
if($sortBy==='brand'){
  usort($selected, fn($a,$b)=> strcmp($a['brand']??'', $b['brand']??'') ?: strcmp($a['name']??'', $b['name']??''));
  // group by brand
  $groups = [];
  foreach($selected as $p){
    $g = $p['brand'] ?? 'other';
    if(!isset($groups[$g])) $groups[$g]=[];
    $groups[$g][]=$p;
  }
  ksort($groups);
} elseif($sortBy==='name'){
  usort($selected, fn($a,$b)=> strcmp($a['name']??'', $b['name']??''));
  $groups = ['All Products' => $selected];
} else {
  // by category
  $groups = [];
  foreach($selected as $p){
    $g = $p['cat'] ?? 'other';
    if(!isset($groups[$g])) $groups[$g]=[];
    $groups[$g][]=$p;
  }
  ksort($groups);
}

$today = date('F Y');
$coverStyles = [
  'green' => 'background:linear-gradient(135deg, #1f5a38 0%, #2e7d4f 55%, #1a3d2a 100%);color:#fff',
  'gold'  => 'background:linear-gradient(135deg, #8a6d2b 0%, #b8923f 45%, #d4b36a 100%);color:#fff',
  'light' => 'background:linear-gradient(135deg, #fbfaf6 0%, #f3f1e9 100%);color:var(--ink);border-bottom:1px solid var(--line)',
];
$coverStyle = $coverStyles[$coverTheme];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=esc($title)?> — 7 Boys®</title>
<link rel="stylesheet" href="/assets/css/hdr3.css?v=<?=filemtime(__DIR__.'/../assets/css/hdr3.css')?>">
<style>
@page { size: A4; margin: 14mm 12mm 14mm 12mm; @bottom-center{ content: "Page " counter(page) " of " counter(pages); font-size:9px; color:#6b756c; } }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.catalog{max-width:800px;margin:0 auto;background:#fff}
@media screen{ body{background:#f1efe8;padding:24px} .catalog{box-shadow:0 20px 60px rgba(0,0,0,.12);border-radius:18px;overflow:hidden} .no-print{display:block} }
@media print{ body{background:#fff;padding:0} .no-print{display:none !important} .catalog{box-shadow:none;border-radius:0} a{color:inherit;text-decoration:none} }

/* cover */
.cover{padding:56px 40px 48px;position:relative;overflow:hidden;<?=$coverStyle?>}
.cover::after{content:"";position:absolute;right:-40px;top:-40px;width:260px;height:260px;background:radial-gradient(circle, rgba(255,255,255,.14) 0%, transparent 70%);border-radius:50%;pointer-events:none}
.cover .eyebrow{display:inline-block;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);padding:6px 14px;border-radius:999px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:700}
.cover.light .eyebrow{background:var(--green-soft);color:var(--green);border-color:rgba(46,125,79,.14)}
.cover h1{font-family:var(--display);font-size:42px;line-height:1;letter-spacing:-.03em;margin:18px 0 12px}
.cover p.sub{opacity:.82;font-size:15px;max-width:520px}
.cover.light p.sub{color:var(--muted)}
.cover .prepared{margin-top:16px;font-size:14px;font-weight:700;background:rgba(255,255,255,.14);display:inline-block;padding:8px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.14)}
.cover.light .prepared{background:var(--green);color:#fff}
.cover .meta{margin-top:22px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px;opacity:.9}
.cover .meta span{background:rgba(255,255,255,.12);padding:6px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.14)}
.cover.light .meta span{background:var(--bg-soft);border-color:var(--line);color:var(--ink)}
.cover .logos{position:absolute;right:28px;bottom:20px;display:flex;gap:12px;align-items:center}
.cover .logo{width:86px;height:86px;object-fit:contain;background:#fff;border-radius:16px;padding:10px;box-shadow:0 8px 24px rgba(0,0,0,.18)}

/* toc */
.toc{padding:22px 36px;border-bottom:1px solid var(--line);background:var(--bg-soft);display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.toc strong{color:var(--ink)}

/* sections */
.section{padding:28px 32px 10px}
.cat-head{font-family:var(--display);font-size:22px;font-weight:800;letter-spacing:-.02em;margin:8px 0 16px;padding-left:14px;border-left:4px solid var(--gold);page-break-after:avoid}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
@media print{ .grid{gap:12px} }
.card{border:1px solid var(--card-line);border-radius:14px;overflow:hidden;background:#fff;display:flex;gap:14px;padding:12px;page-break-inside:avoid}
.card .img{width:110px;height:110px;flex:0 0 110px;background:var(--bg-soft);border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;border:1px solid var(--line)}
.card .img img{width:100%;height:100%;object-fit:cover}
.card .info{flex:1;min-width:0}
.card .tag{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--green)}
.card h3{font-size:14.5px;font-weight:700;line-height:1.25;margin:3px 0 4px;letter-spacing:-.01em}
.card p{font-size:12px;color:var(--muted);line-height:1.45;margin:0}
.card .meta-row{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.card .orig{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--green-dark);background:var(--green-soft);padding:4px 9px;border-radius:999px;border:1px solid rgba(46,125,79,.14)}
.card .code{font-size:10px;color:var(--muted);background:var(--bg-soft);padding:3px 8px;border-radius:999px;border:1px solid var(--line)}
.card .price{font-size:13px;font-weight:800;color:var(--ink);background:var(--gold-soft);padding:4px 10px;border-radius:999px;border:1px solid rgba(184,146,63,.18)}
.footer{padding:22px 32px;background:#f3f1e9;border-top:1px solid var(--line);display:flex;gap:24px;flex-wrap:wrap;font-size:12px;color:var(--muted)}
.footer strong{color:var(--ink)}
.actions{position:sticky;bottom:16px;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border:1px solid var(--line);border-radius:999px;padding:10px 14px;display:flex;gap:10px;align-items:center;justify-content:center;box-shadow:0 8px 30px rgba(0,0,0,.12);margin:18px auto;max-width:560px}
.btn{height:40px;padding:0 18px;border-radius:999px;border:1px solid var(--green);background:var(--green);color:#fff;font-weight:700;cursor:pointer}
.btn.outline{background:#fff;color:var(--green)}
</style>
</head>
<body>
<div class="no-print" style="max-width:800px;margin:0 auto 16px;display:flex;gap:10px;align-items:center">
  <a href="catalog.php" style="font-weight:700;color:var(--green)">← Back to Builder</a>
  <span style="margin-left:auto;color:var(--muted);font-size:13px"><?=count($selected)?> products • <?=esc($sortBy)?> • <?=esc($coverTheme)?></span>
</div>

<div class="catalog" id="catalog">
  <div class="cover <?=$coverTheme?>">
    <div class="eyebrow">Rubu Al Quds • Since 1966</div>
    <h1><?=esc($title)?></h1>
    <p class="sub">Premium food & beverage — curated selection from the world's finest brands, distributed with trust across Jordan & the Levant.</p>
    <?php if($clientName): ?><div class="prepared">Prepared for: <?=esc($clientName)?></div><?php endif; ?>
    <div class="meta">
      <span><?=esc($today)?></span>
      <span><?=count($selected)?> products • <?=count($groups)?> <?= $sortBy==='brand'?'brands':($sortBy==='name'?'items':'categories')?></span>
      <span><?=esc($settings['phone'] ?? '+962 79 5816444')?></span>
    </div>
    <div class="logos">
      <?php if($clientLogoData): ?><img class="logo" src="<?=esc($clientLogoData)?>" alt="Client"><?php endif; ?>
      <img class="logo" src="/assets/img/logo.png" alt="7 Boys" onerror="this.style.display='none'">
    </div>
  </div>

  <div class="toc">
    <span><strong>Contact:</strong> <?=esc($settings['phone'])?> • <?=esc($settings['email'])?></span>
    <span><strong>Address:</strong> <?=esc($settings['address'])?></span>
  </div>

  <?php foreach($groups as $groupName=>$items): ?>
    <div class="section">
      <div class="cat-head"><?=esc($sortBy==='brand' ? ($groupName?:'Other') : ($catMap[$groupName] ?? ucfirst($groupName)))?> <span style="font-weight:500;color:var(--muted);font-size:14px">— <?=count($items)?> items</span></div>
      <div class="grid">
        <?php foreach($items as $p): ?>
          <div class="card">
            <div class="img"><img src="/assets/img/<?=esc($p['image'] ?? 'prod-1.jpg')?>" alt="<?=esc($p['name'])?>" loading="lazy" onerror="this.src='/assets/img/logo.png'"></div>
            <div class="info">
              <div class="tag"><?=esc($p['brand'] ?? '')?></div>
              <h3><?=esc($p['name'])?></h3>
              <?php if($showDesc && !empty($p['desc'])): ?><p><?=esc($p['desc'])?></p><?php endif; ?>
              <div class="meta-row">
                <?php if($showOrigin && !empty($p['origin'])): ?><span class="orig"><?=esc($p['origin'])?></span><?php endif; ?>
                <?php if($showCode): ?><span class="code">#<?=esc($p['id'] ?? substr($p['image']??'',5,3))?></span><?php endif; ?>
                <?php if($showPrice && !empty($p['price'])): ?><span class="price"><?=esc($p['price'])?></span><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="footer">
    <div><strong>7 Boys® · Rubu Al Quds</strong><br>Premium food import, distribution & brand management since 1966.<br>Al-Abdali, Amman, Jordan</div>
    <div style="margin-left:auto;text-align:right"><strong>Contact</strong><br><?=esc($settings['phone'])?><br><?=esc($settings['email'])?></div>
  </div>
</div>

<div class="actions no-print">
  <button class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
  <button class="btn outline" onclick="shareLink()">🔗 Copy Link</button>
  <span id="shareMsg" style="font-size:12px;color:var(--muted)"></span>
</div>

<script>
function shareLink(){
  const ids = <?=json_encode(array_map('strval',$ids))?>;
  const url = location.origin + '/admin/catalog_view.php?ids=' + ids.join(',') + '&title=' + encodeURIComponent(document.title.replace(' — 7 Boys®','')) + '&coverTheme=<?=esc($coverTheme)?>&sortBy=<?=esc($sortBy)?>';
  navigator.clipboard.writeText(url).then(()=>{
    document.getElementById('shareMsg').textContent='Link copied!';
    setTimeout(()=>document.getElementById('shareMsg').textContent='',2000);
  });
}
</script>
</body>
</html>
