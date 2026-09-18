<?php
require_once __DIR__ . '/admin/config.php';
if (!is_visitor_logged_in()) { header('Location: /login.php?next='.urlencode($_SERVER['REQUEST_URI'])); exit; }
$vis = current_visitor();
$visFull = find_visitor_by_email($vis['email'] ?? '');
if (!$visFull) { session_destroy(); header('Location: /login.php'); exit; }

// Handle profile update
$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_profile'])) {
    $name=trim($_POST['name']??''); $phone=trim($_POST['phone']??''); $company=trim($_POST['company']??''); $ctype=trim($_POST['customer_type']??'individual');
    if(strlen($name)>=2){
        $all=load_visitors();
        foreach($all as &$v) if($v['id']===$visFull['id']){ $v['name']=$name; $v['phone']=$phone; $v['company']=$company; $v['customer_type']=$ctype; break; }
        save_visitors($all);
        $_SESSION['visitor']['name']=$name; $_SESSION['visitor']['phone']=$phone; $_SESSION['visitor']['company']=$company; $_SESSION['visitor']['customer_type']=$ctype;
        $visFull['name']=$name; $visFull['phone']=$phone; $visFull['company']=$company; $visFull['customer_type']=$ctype;
        $msg='Profile updated.';
    }
}
$quotes=array_filter(load_json(SITE_DIR.'/admin/data/quotes.json'), function($q) use ($visFull){ $byId = isset($q['visitor_id']) && $q['visitor_id'] && $q['visitor_id']==($visFull['id']??0); $byEmail = strcasecmp($q['email']??$q['visitor_email']??'', $visFull['email'])===0; return $byId || $byEmail; });
$nav=sb_nav();
?>
<!DOCTYPE html><html lang="<?= esc($GLOBALS['lang']??'en') ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Account — 7 Boys®</title><link rel="stylesheet" href="<?= asset_css() ?>"></head><body>
<?= site_header($nav) ?>
<section class="cat-hero"><div class="inner"><h1>My Account</h1><p>Welcome, <?= esc($visFull['name']) ?> — <?= esc($visFull['email']) ?> <span style="background:var(--green);color:#fff;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;margin-left:6px"><?= esc(ucfirst($visFull['customer_type']??'individual')) ?></span></p></div></section>
<main class="container" style="max-width:900px;margin:32px auto 60px">
<?php if($msg): ?><div style="background:#e8f3ec;border:1px solid #b5d9c2;color:#1f5a38;padding:12px 16px;border-radius:10px;margin-bottom:16px"><?= esc($msg) ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:24px;align-items:start">
<div style="background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:22px">
<h3 style="margin:0 0 12px">Profile</h3>
<form method="post">
<label style="font-size:13px;color:var(--muted)">Full Name<input name="name" required value="<?= esc($visFull['name']) ?>" style="width:100%;padding:10px;margin:6px 0 12px;border:1px solid var(--line);border-radius:8px"></label>
<label style="font-size:13px;color:var(--muted)">Email (cannot change)<input disabled value="<?= esc($visFull['email']) ?>" style="width:100%;padding:10px;margin:6px 0 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg-soft)"></label>
<label style="font-size:13px;color:var(--muted)">Phone<input name="phone" value="<?= esc($visFull['phone']??'') ?>" style="width:100%;padding:10px;margin:6px 0 12px;border:1px solid var(--line);border-radius:8px"></label>
<label style="font-size:13px;color:var(--muted)">Account Type<select name="customer_type" style="width:100%;padding:10px;margin:6px 0 12px;border:1px solid var(--line);border-radius:8px;background:var(--card)"><option value="individual" <?= ($visFull['customer_type']??'individual')==='individual'?'selected':'' ?>>Individual</option><option value="restaurant" <?= ($visFull['customer_type']??'')==='restaurant'?'selected':'' ?>>Restaurant</option><option value="hotel" <?= ($visFull['customer_type']??'')==='hotel'?'selected':'' ?>>Hotel</option><option value="cafe" <?= ($visFull['customer_type']??'')==='cafe'?'selected':'' ?>>Cafe</option><option value="supermarket" <?= ($visFull['customer_type']??'')==='supermarket'?'selected':'' ?>>Supermarket</option><option value="retail" <?= ($visFull['customer_type']??'')==='retail'?'selected':'' ?>>Retail</option><option value="wholesale" <?= ($visFull['customer_type']??'')==='wholesale'?'selected':'' ?>>Wholesale</option><option value="catering" <?= ($visFull['customer_type']??'')==='catering'?'selected':'' ?>>Catering</option><option value="other" <?= ($visFull['customer_type']??'')==='other'?'selected':'' ?>>Other Business</option></select></label>
<label style="font-size:13px;color:var(--muted)">Company<input name="company" value="<?= esc($visFull['company']??'') ?>" style="width:100%;padding:10px;margin:6px 0 16px;border:1px solid var(--line);border-radius:8px"></label>
<button name="update_profile" value="1" class="btn" style="width:100%;justify-content:center">Save Changes</button>
</form>
<p style="margin-top:16px;text-align:center"><a href="/logout.php" style="color:#900;font-weight:600">Logout</a></p>
<p style="margin-top:8px;font-size:12px;color:var(--muted);text-align:center">Member since <?= esc(substr($visFull['created']??'',0,10)) ?></p>
</div>
<div>
<h3 style="margin:0 0 12px">My Quotes <span style="font-weight:400;color:var(--muted);font-size:14px">(<?= count($quotes) ?>)</span></h3>
<?php if(empty($quotes)): ?>
<div style="background:var(--bg-soft);border:1px dashed var(--line);border-radius:12px;padding:32px;text-align:center;color:var(--muted)">No quotes yet. <a href="/category.php" style="color:var(--green)">Browse products</a> and add to quote.</div>
<?php else: foreach(array_reverse($quotes) as $q): 
  $qJson = htmlspecialchars(json_encode($q['items']??[]), ENT_QUOTES);
?>
<div style="background:var(--card);border:1px solid var(--card-line);border-radius:12px;padding:16px 18px;margin-bottom:12px">
<div style="display:flex;justify-content:space-between;align-items:center"><strong>#<?= esc($q['id']??'') ?> — <?= esc($q['status']??'pending') ?></strong><span style="font-size:12px;color:var(--muted)"><?= esc($q['date']??$q['at']??'') ?></span></div>
<div style="font-size:13px;color:var(--muted);margin-top:4px"><?= count($q['items']??[]) ?> items<?php if(!empty($q['total'])&&$q['has_price']): ?> · <strong style="color:var(--ink)"><?= number_format($q['total'],2) ?> JOD</strong><?php endif; ?></div>
<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px"><?php foreach($q['items']??[] as $it): ?>
  <span style="background:var(--bg-soft);padding:5px 8px;border-radius:8px;font-size:12px;display:inline-flex;align-items:center;gap:6px"><?= esc($it['name']??$it['slug']) ?> ×<?= esc($it['qty']??1) ?> <button onclick="reorderOne('<?= esc($it['slug']??'') ?>','<?= esc(str_replace("'","\\'",$it['name']??'')) ?>','<?= esc($it['img']??'prod-1.jpg') ?>','<?= esc($it['href']??'') ?>',<?= intval($it['qty']??1) ?>)" style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:2px 6px;font-size:11px;cursor:pointer;color:var(--green);font-weight:700">+ Add</button></span>
<?php endforeach; ?></div>
<div style="margin-top:12px;display:flex;gap:8px">
  <button onclick="reorderAll(this)" data-items='<?= $qJson ?>' style="flex:1;background:var(--green);color:#fff;border:none;padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer">🔄 Reorder All (<?= count($q['items']??[]) ?>)</button>
  <a href="/quote.php" onclick="reorderAll(this); return false;" data-items='<?= $qJson ?>' style="background:#fff;color:var(--ink);border:1.5px solid var(--line);padding:10px 14px;border-radius:10px;font-weight:700;text-decoration:none;text-align:center">Reorder & Edit →</a>
</div>
<div class="reorder-msg" style="display:none;margin-top:8px;background:#e8f3ec;color:#1f5a38;padding:8px 12px;border-radius:8px;font-size:13px;text-align:center"></div>
</div>
<?php endforeach; endif; ?>
</div>
</div>
</main>
<script>
function reorderOne(slug,name,img,href,qty){
  if(!slug) return;
  var KEY='qb_quote'; var c={}; try{ c=JSON.parse(localStorage.getItem(KEY))||{} }catch(e){ c={} }
  if(!c[slug]) c[slug]={slug:slug,name:name,img:img,href:href,qty:qty};
  else c[slug].qty += qty;
  localStorage.setItem(KEY, JSON.stringify(c));
  // update bubble
  var btn=document.getElementById('qb-cart-btn'); if(btn){ var n=Object.values(c).reduce((a,b)=>a+b.qty,0); var cc=btn.querySelector('.qb-count'); if(cc) cc.textContent=n; btn.style.display='flex'; }
  // toast
  var msg=document.createElement('div'); msg.textContent='✓ '+name+' added ('+qty+')'; msg.style.cssText='position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1f5a38;color:#fff;padding:10px 18px;border-radius:999px;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2)';
  document.body.appendChild(msg); setTimeout(()=>msg.remove(),1800);
}
function reorderAll(btn){
  var raw = btn.getAttribute('data-items');
  if(!raw) return;
  try{
    var items = JSON.parse(raw.replace(/&quot;/g,'"').replace(/&#039;/g,"'").replace(/&amp;/g,'&'));
    var KEY='qb_quote'; var c={}; try{ c=JSON.parse(localStorage.getItem(KEY))||{} }catch(e){ c={} }
    items.forEach(function(it){
      var slug=it.slug||''; if(!slug) return;
      var qty=parseInt(it.qty)||1;
      if(!c[slug]) c[slug]={slug:slug,name:it.name||slug,img:it.img||'prod-1.jpg',href:it.href||'',qty:qty};
      else c[slug].qty += qty;
    });
    localStorage.setItem(KEY, JSON.stringify(c));
    var box=btn.closest('div').parentElement.querySelector('.reorder-msg');
    if(box){ box.style.display='block'; box.textContent='✓ ' + items.length + ' products added to quote — opening basket...'; }
    setTimeout(function(){ 
      var qb=document.getElementById('qb-cart-btn'); if(qb) qb.style.display='flex';
      if(btn.tagName==='A'){ window.location='/quote.php'; } else { 
        var panel=document.getElementById('qb-panel'); if(panel) panel.classList.add('open');
        // also go to quote page after 800ms
        setTimeout(()=>window.location='/quote.php',700);
      }
    },400);
  }catch(e){ alert('Reorder failed'); console.error(e); }
}
</script>
<?= site_footer() ?><?= whatsapp_float(load_ext()) ?>
</body></html>
