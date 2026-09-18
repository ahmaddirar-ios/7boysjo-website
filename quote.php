<?php
require_once __DIR__ . '/admin/config.php';
$nav = sb_nav();
$ext = load_ext();
$wa_num = preg_replace('/[^0-9]/','', $ext['whatsapp'] ?? '962795109022');

$quote_html = null;
$quote_id = null;
$quote_data = null;
$prefill = null;
if(is_visitor_logged_in()){ $v=current_visitor(); $vf=find_visitor_by_email($v['email']??''); if($vf){ $prefill=$vf; } else { $prefill=$v; } }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_data'])) {
  if (function_exists('rate_limit_check') && !rate_limit_check('quote', 20, 3600)) { http_response_code(429); exit('Too many requests. Please try again in an hour.'); }
  $__order_lock = @fopen(__DIR__.'/admin/data/.lock_quote','c'); if ($__order_lock) flock($__order_lock, LOCK_EX); // held till request end: no lost orders
  if (trim($_POST['website'] ?? '') !== '') { http_response_code(200); exit('OK'); } // honeypot: bots only
  $__qip = $_SERVER['REMOTE_ADDR'] ?? '0'; $__qrf = sys_get_temp_dir().'/qf_'.md5($__qip).'.json'; $__qn = time(); $__qd = ['c'=>0,'t'=>$__qn]; if (file_exists($__qrf)) { $__qd = json_decode(file_get_contents($__qrf), true) ?: $__qd; if ($__qn - ($__qd['t'] ?? 0) > 3600) $__qd = ['c'=>0,'t'=>$__qn]; } $__qd['c']++; file_put_contents($__qrf, json_encode($__qd), LOCK_EX); if ($__qd['c'] > 20) { http_response_code(429); exit('Too many requests. Please try again in an hour.'); }
  $items = json_decode($_POST['quote_data'], true) ?: [];
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $note = trim($_POST['note'] ?? '');
  if ($name && $email && count($items)) {
    $products = normalize_products_full(load_products());
    $brands = load_brands();
    $map = [];
    foreach ($products as $p) $map[product_slug($p)] = $p;
    $rows = ''; $total = 0; $has_price = false; $n=1; $valid_items=[];
    foreach ($items as $it) {
      $slug = $it['slug'] ?? '';
      $qty = max(1, min(100000, intval($it['qty'] ?? 1)));
      $p = $map[$slug] ?? null;
      if (!$p) continue;
      $pkpcs=0; if(preg_match('/pcs\/ct\s*([0-9]+)/i',(($p['desc'] ?? '').' '.($p['description'] ?? '')),$mm))$pkpcs=(int)$mm[1]; $units=$pkpcs>0?$qty*$pkpcs:'&mdash;'; $valid_items[] = ['p'=>$p,'qty'=>$qty,'slug'=>$slug];
      $price_raw = trim($p['price'] ?? ''); // catalog price only: never trust client-submitted prices
      $price_num = floatval(preg_replace('/[^0-9.]/','',$price_raw));
      $line = $price_num ? $price_num * $qty : 0;
      if ($price_num) { $has_price = true; $total += $line; }
      $bn = brand_name($brands, $p['brand'] ?? '');
      $rows .= '<tr>'
        .'<td class="c">'.$n.'</td>'
        .'<td><div class="pn">'.esc($p['name']).'</div><div class="pm">'.esc($bn).' &middot; '.esc($p['origin']??'').'</div></td>'
        .'<td class="c">'.$qty.'</td>'.'<td class="c">'.$units.'</td>'
        .'<td class="r">'.($price_num ? number_format($price_num,2).' <span>JOD</span>' : '<span class="mut">Upon request</span>').'</td>'
        .'<td class="r b">'.($price_num ? number_format($line,2).' <span>JOD</span>' : '<span class="mut">&mdash;</span>').'</td>'
        .'</tr>';
      $n++;
    }
    if ($rows === '') $rows = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#888">No valid items</td></tr>';
    $quote_id = 'QB-'.date('Ymd').'-'.strtoupper(substr(md5($name.time()),0,6));
    $valid_until = date('d M Y', strtotime('+14 days'));
    // save
    // snapshot price into items for future edits/prints
    $snap_items=[];
    foreach($items as $it){
      $slug=$it['slug']??''; $p=$map[$slug]??null;
      $snap=$it; $snap['price']=$p['price']??''; $snap['name']=$p['name']??$it['name']??$slug;
      $snap_items[]=$snap;
    }
    $quotes = load_json(SITE_DIR . '/admin/data/quotes.json');
    $visitor_id = is_visitor_logged_in() ? (current_visitor()['id']??0) : 0;
    $visitor_email = is_visitor_logged_in() ? (current_visitor()['email']??$email) : $email;
    $quotes[] = ['id'=>$quote_id,'visitor_id'=>$visitor_id,'visitor_email'=>strtolower($visitor_email),'at'=>date('Y-m-d H:i:s'),'date'=>date('Y-m-d H:i:s'),'name'=>$name,'email'=>strtolower($email),'company'=>$company,'phone'=>$phone,'note'=>$note,'items'=>$snap_items,'total'=>$total,'has_price'=>$has_price,'ip'=>$_SERVER['REMOTE_ADDR']??'','token'=>bin2hex(random_bytes(16)),'status'=>'new','logs'=>[['at'=>date('Y-m-d H:i:s'),'user'=>'customer','action'=>'created quote '.$quote_id]]];
    save_json(SITE_DIR . '/admin/data/quotes.json', $quotes);
    $msgs = load_json(SITE_DIR . '/admin/data/messages.json');
    $msgs[] = ['type'=>'quote','quote_id'=>$quote_id,'name'=>$name,'email'=>$email,'company'=>$company,'phone'=>$phone,'msg'=>$note.' | Items: '.json_encode($snap_items),'at'=>date('Y-m-d H:i:s'),'ip'=>$_SERVER['REMOTE_ADDR']??'','read'=>false];
    save_json(SITE_DIR . '/admin/data/messages.json', $msgs);
    notify_admin('quote', '[7boysjo] New quote ' . $quote_id . ' — ' . $name,
      'Quote ' . $quote_id . ' at ' . date('Y-m-d H:i:s') . "\nName: $name\nEmail: $email\nCompany: $company\nPhone: $phone\nItems: " . ($n-1) . "\nNote: $note");
    $quote_data = ['id'=>$quote_id,'date'=>date('d M Y'),'valid'=>$valid_until,'name'=>$name,'company'=>$company,'email'=>$email,'phone'=>$phone,'note'=>$note,'rows'=>$rows,'total'=>$total,'has_price'=>$has_price,'count'=>$n-1];
  }
}

if ($quote_data) {
 // --- PROFESSIONAL A4 QUOTATION ONLY (no site chrome) ---
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quotation <?= esc($quote_data['id']) ?> — 7 Boys</title>
<link rel="icon" href="/favicon.ico">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter, -apple-system, BlinkMacSystemFont, Segoe UI, Helvetica, Arial, sans-serif;background:#f3f1e9;color:#1a1a1a}
.toolbar{max-width:860px;margin:16px auto;padding:0 16px;display:flex;gap:10px;flex-wrap:wrap}
.toolbar a, .toolbar button{padding:10px 18px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-dark{background:#111;color:#fff;border:none}
.btn-green{background:#2e6b3e;color:#fff;border:none}
.btn-outline{background:#fff;color:#111;border:1.5px solid #ddd}
.paper{max-width:860px;margin:0 auto 32px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.11);border-radius:4px;overflow:hidden}
.paper-head{height:8px;background:linear-gradient(90deg,#0e4a2a 0%,#2e6b3e 35%,#c8a84a 100%)}
.paper-body{padding:36px 40px 28px}
.brand-row{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}
.brand-left{display:flex;gap:14px;align-items:center}
.brand-left img{height:72px;width:auto}
.brand-left h1{font-size:19px;letter-spacing:.04em;color:#0e4a2a;line-height:1.1}
.brand-left h1 span{font-weight:400;font-size:11px;letter-spacing:.18em;color:#8a7d5a;display:block;margin-top:2px}
.brand-left p{font-size:11px;color:#666;margin-top:6px;line-height:1.5}
.quote-badge{text-align:right}
.quote-badge .lbl{font-size:10px;letter-spacing:.22em;color:#8a7d5a;font-weight:700}
.quote-badge .num{font-size:22px;font-weight:800;color:#0e4a2a;margin-top:2px}
.quote-badge .dates{font-size:11px;color:#666;margin-top:6px;line-height:1.6}
.quote-badge .dates strong{color:#1a1a1a}
.divider{height:1px;background:#eee;margin:20px 0}
.cust-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.box{background:#faf8f2;border:1px solid #e8e2d0;border-radius:12px;padding:14px 16px}
.box h3{font-size:10px;letter-spacing:.14em;color:#8a7d5a;margin-bottom:8px}
.box .v{font-size:13px;line-height:1.6}
.box .v strong{font-size:14px}
.box .v small{color:#666}
.note-box{background:#fff;border:1px dashed #d9d2b8;border-radius:10px;padding:10px 14px;font-size:12px;color:#6b5f3a;margin-top:10px}
table.q{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}
table.q thead th{background:#0e4a2a;color:#fff;padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.06em}
table.q thead th.c, table.q td.c{text-align:center}
table.q thead th.r, table.q td.r{text-align:right}
table.q td{padding:11px 12px;border-bottom:1px solid #eee;vertical-align:top}
table.q td .pn{font-weight:700}
table.q td .pm{font-size:11px;color:#777;margin-top:2px}
table.q td .mut{color:#999;font-style:italic}
table.q td.b{font-weight:700}
table.q tfoot td{background:#faf8f2;padding:14px 12px;border-top:2px solid #0e4a2a;font-weight:800}
.terms{margin-top:18px;background:#faf8f2;border:1px solid #e8e2d0;border-radius:12px;padding:14px 16px;font-size:11px;color:#6b5f3a;line-height:1.7}
.terms strong{color:#0e4a2a}
.sign-row{display:flex;justify-content:space-between;gap:24px;margin-top:22px}
.sign{flex:1;text-align:center}
.sign .line{border-top:1px solid #333;margin-top:40px;padding-top:6px;font-size:11px;color:#333}
.sign small{color:#888;font-size:10px}
.paper-foot{text-align:center;padding:14px;background:#0e4a2a;color:#d9c9a0;font-size:10px;letter-spacing:.08em}
@media print{
  body{background:#fff}
  .toolbar{display:none !important}
  .paper{box-shadow:none;border-radius:0;margin:0;max-width:none}
  @page{margin:12mm 10mm}
}
@media(max-width:600px){ .paper-body{padding:22px 18px} .cust-grid{grid-template-columns:1fr} .brand-row{flex-direction:column} }
</style></head><body>
<div class="toolbar">
  <button class="btn-dark" onclick="window.print()">🖨️ Print / Save PDF</button>
  <a class="btn-green" href="https://wa.me/<?= $wa_num ?>?text=<?= rawurlencode("Hello 7 Boys! My quotation ".$quote_data['id']." — please confirm. Name: ".$quote_data['name']) ?>" target="_blank">💬 WhatsApp</a>
  <a class="btn-outline" href="/quote.php">← New Quote</a>
  <a class="btn-outline" href="/">Home</a>
</div>
<div class="paper">
  <div class="paper-head"></div>
  <div class="paper-body">
    <div class="brand-row">
      <div class="brand-left">
        <img src="/assets/img/logo.png" alt="7 Boys">
        <div>
          <h1>RUBU AL QUDS<span>FOR TRADING & FOOD INDUSTRIES</span></h1>
          <p>7 Boys® — Premium Food Trading & Distribution<br>Since 1966 · Al-Abdali, Amman, Jordan</p>
        </div>
      </div>
      <div class="quote-badge">
        <div class="lbl">QUOTATION</div>
        <div class="num"><?= esc($quote_data['id']) ?></div>
        <div class="dates">
          <strong>Date:</strong> <?= esc($quote_data['date']) ?><br>
          <strong>Valid until:</strong> <?= esc($quote_data['valid']) ?><br>
          <strong>Items:</strong> <?= $quote_data['count'] ?>
        </div>
      </div>
    </div>
    <div class="divider"></div>
    <div class="cust-grid">
      <div class="box">
        <h3>BILL TO</h3>
        <div class="v"><strong><?= esc($quote_data['name']) ?></strong><?= $quote_data['company']?' — '.esc($quote_data['company']):'' ?><br><small><?= esc($quote_data['email']) ?> · <?= esc($quote_data['phone']) ?></small></div>
      </div>
      <div class="box">
        <h3>SUPPLIER</h3>
        <div class="v"><strong>Rubu Al Quds — 7 Boys®</strong><br><small>Al-Abdali, Amman, Jordan<br>+962 79 5816444 · wael@7boys.com.jo<br>www.7boysjo.com</small></div>
      </div>
    </div>
    <?php if($quote_data['note']): ?><div class="note-box"><strong>Note:</strong> <?= esc($quote_data['note']) ?></div><?php endif; ?>
    <table class="q">
      <thead><tr><th class="c" style="width:36px">#</th><th>Product</th><th class="c" style="width:60px">Cases</th><th class="c" style="width:90px">Units</th><th class="r" style="width:120px">Unit Price</th><th class="r" style="width:120px">Line Total</th></tr></thead>
      <tbody><?= $quote_data['rows'] ?></tbody>
      <?php if($quote_data['has_price']): ?><tfoot><tr><td colspan="5" class="r">TOTAL</td><td class="r"><?= number_format($quote_data['total'],2) ?> <span>JOD</span></td></tr></tfoot><?php endif; ?>
    </table>
    <div class="terms">
      <strong>Terms:</strong> Prices valid 14 days. Delivery, MOQ and lead time to be confirmed by sales. Prices exclude VAT/delivery unless stated. Availability subject to stock. This document is a quotation, not a tax invoice.
    </div>
    <div class="sign-row">
      <div class="sign"><div class="line">Prepared by — 7 Boys® Sales</div><small>Rubu Al Quds</small></div>
      <div class="sign"><div class="line">Accepted by — Customer signature & stamp</div><small>Date: _____ / _____ / _____</small></div>
    </div>
  </div>
  <div class="paper-foot">RUBU AL QUDS FOR TRADING & FOOD INDUSTRIES (7 BOYS®) — SINCE 1966 · AMMAN, JORDAN · THANK YOU FOR YOUR TRUST</div>
</div>
</body></html>
<?php exit; } ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Request a Quote — 7 Boys</title><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="<?= asset_css() ?>"><style>
.quote-wrap{max-width:860px;margin:24px auto}
.q-cart-table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.q-cart-table th{background:#0e4a2a;color:#fff;padding:10px 12px;text-align:left;font-size:13px}
.q-cart-table td{padding:12px;border-bottom:1px solid var(--line);font-size:14px}
.q-cart-table img{width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--line)}
.qty-in{width:64px;padding:6px;border:1.5px solid var(--line);border-radius:8px;text-align:center}
.quote-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}
.quote-form label{font-size:13px;font-weight:600;color:var(--muted)}
.quote-form input,.quote-form textarea{width:100%;padding:10px;border:1.5px solid var(--line);border-radius:10px;font-size:14px;background:var(--card);color:var(--ink)}
.quote-form .full{grid-column:1/-1}
</style></head><body>
<?= site_header($nav) ?>
<section class="cat-hero" style="padding:28px 0 18px"><div class="inner" style="max-width:860px;margin:0 auto;padding:0 16px"><h1>Request a Quote</h1><?php if($prefill): ?><p style="background:#e8f3ec;border:1px solid #b5d9c2;color:#1f5a38;padding:10px 14px;border-radius:10px;margin-top:10px">Logged in as <strong><?= esc($prefill['name']) ?></strong> (<?= esc($prefill['email']) ?>) — your quotes will be saved to your account for reordering.</p><?php endif; ?><p>Add products to your basket ( <strong>+ Quote</strong> on cards or <strong>Add to Quote</strong> on product) — prices are hidden on site and shown only in your PDF quotation.</p></div></section>
<main class="container quote-wrap">
  <div id="qCartArea"></div>
  <form id="qForm" method="post" action="/quote.php" class="quote-form" style="margin-top:20px">
    <input type="hidden" name="quote_data" id="quoteData">
    <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div><label>Name *</label><input name="name" required placeholder="Full name" value="<?= esc($prefill['name']??'') ?>"></div>
    <div><label>Company</label><input name="company" placeholder="Company" value="<?= esc($prefill['company']??'') ?>"></div>
    <div><label>Email *</label><input name="email" type="email" required placeholder="you@company.com" value="<?= esc($prefill['email']??'') ?>" <?= $prefill?'readonly style="background:var(--bg-soft)"':'' ?>></div>
    <div><label>Phone *</label><input name="phone" required placeholder="+962 ..." value="<?= esc($prefill['phone']??'') ?>"></div>
    <div class="full"><label>Note</label><textarea name="note" rows="3" placeholder="Delivery address, required date, etc."></textarea></div>
    <div class="full"><button type="submit" class="btn" style="width:100%;padding:14px;font-size:16px">Generate Quotation PDF</button></div>
  </form>
</main>
<?= site_footer() ?>
<?= whatsapp_float(load_ext()) ?>
<script>
(function(){
  var KEY='qb_quote';
  function load(){ try{return JSON.parse(localStorage.getItem(KEY))||{}}catch(e){return{}} }
  var area=document.getElementById('qCartArea');
  if(area){
    var c=load(); var vals=Object.values(c);
    if(!vals.length){
      area.innerHTML='<div style="text-align:center;padding:32px;background:var(--card);border:1px solid var(--line);border-radius:12px;color:var(--muted)">Your quote basket is empty — browse products and click <strong>+ Quote</strong> or <strong>Add to Quote</strong></div>';
      document.getElementById('qForm').style.display='none';
    } else {
      var html='<table class="q-cart-table"><thead><tr><th>Product</th><th>Cases</th><th>Units</th><th></th></tr></thead><tbody>';
      vals.forEach(function(it){
        html+='<tr><td style="display:flex;gap:10px;align-items:center"><img src="/assets/img/'+(it.img||'prod-1.jpg')+'"><span>'+it.name+'</span></td><td><input class="qty-in" type="number" min="1" value="'+it.qty+'" data-slug="'+it.slug+'"></td><td style="text-align:center">'+(it.pcs>0?it.qty*it.pcs:'-')+'</td><td><button type="button" class="rm" data-slug="'+it.slug+'" style="background:none;border:none;color:#e63946;font-size:20px;cursor:pointer">×</button></td></tr>';
      });
      html+='</tbody></table><div style="text-align:right;margin-top:8px"><button type="button" id="qClear" style="background:none;border:none;color:#888;cursor:pointer">Clear basket</button></div>';
      area.innerHTML=html;
      area.querySelectorAll('.qty-in').forEach(function(inp){ inp.onchange=function(){ var c=load(); var s=inp.dataset.slug; if(c[s]){c[s].qty=parseInt(inp.value)||1; localStorage.setItem(KEY,JSON.stringify(c));} }; });
      area.querySelectorAll('.rm').forEach(function(b){ b.onclick=function(){ var c=load(); delete c[b.dataset.slug]; localStorage.setItem(KEY,JSON.stringify(c)); location.reload(); }; });
      document.getElementById('qClear').onclick=function(){ localStorage.removeItem(KEY); location.reload(); };
      document.getElementById('qForm').onsubmit=function(){
        var c=load(); document.getElementById('quoteData').value=JSON.stringify(Object.values(c));
        if(!Object.keys(c).length){ alert('Basket empty'); return false; }
      };
    }
  }
})();
</script>
<script src="/assets/js/carousel.js"></script></body></html>
