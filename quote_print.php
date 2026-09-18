<?php
require_once __DIR__ . '/admin/config.php';
$id = $_GET['id'] ?? '';
$quotes = load_json(SITE_DIR . '/admin/data/quotes.json');
$found = null;
foreach ($quotes as $q) if (($q['id'] ?? '') === $id) { $found = $q; break; }
if (!$found) { http_response_code(404); echo 'Quote not found'; exit; }
$tok = $_GET['t'] ?? '';
if (!function_exists('is_logged_in') || !is_logged_in()) {
  if ($tok === '' || !isset($found['token']) || !hash_equals((string)$found['token'], (string)$tok)) { http_response_code(403); echo 'Forbidden'; exit; }
}
$__ip = $_SERVER['REMOTE_ADDR'] ?? '0'; $__f = sys_get_temp_dir().'/qp_rl_'.md5($__ip).'.json'; $__n = time();
$__d = ['c'=>0,'t'=>$__n];
if (file_exists($__f)) { $__j = json_decode(file_get_contents($__f), true); if (is_array($__j)) $__d = $__j; if ($__n - ($__d['t'] ?? 0) > 60) $__d = ['c'=>0,'t'=>$__n]; }
$__d['c']++; file_put_contents($__f, json_encode($__d));
if ($__d['c'] > 60) { http_response_code(429); echo 'Too many requests'; exit; }

$products = normalize_products_full(load_products());
$brands = load_brands();
$map = []; foreach ($products as $p) $map[product_slug($p)] = $p;
$rows=''; $total=0; $has_price=false; $n=1;
foreach (($found['items']??[]) as $it) {
  $slug=$it['slug']??''; $qty=intval($it['qty']??1); $p=$map[$slug]??null; if(!$p) continue;
  // prefer price saved in quote (admin override), fallback to current product price
  $price_raw=trim(($it['price']??'') !== '' ? $it['price'] : ($p['price']??'')); $price_num=floatval(preg_replace('/[^0-9.]/','',$price_raw)); $line=$price_num?$price_num*$qty:0;
  if($price_num){$has_price=true;$total+=$line;}
  $bn=brand_name($brands,$p['brand']??'');
  $rows.='<tr><td class="c">'.$n.'</td><td><div class="pn">'.esc($p['name']).'</div><div class="pm">'.esc($bn).' &middot; '.esc($p['origin']??'').'</div></td><td class="c">'.$qty.'</td><td class="r">'.($price_num?number_format($price_num,2).' <span>JOD</span>':'<span class="mut">Upon request</span>').'</td><td class="r b">'.($price_num?number_format($line,2).' <span>JOD</span>':'<span class="mut">&mdash;</span>').'</td></tr>';
  $n++;
}
$valid_until = date('d M Y', strtotime(($found['at']??date('Y-m-d')).' +14 days'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quotation <?= esc($found['id']) ?> — 7 Boys</title><link rel="icon" href="/favicon.ico">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,-apple-system,sans-serif;background:#f3f1e9;color:#1a1a1a}
.toolbar{max-width:860px;margin:16px auto;padding:0 16px;display:flex;gap:10px}
.toolbar a,.toolbar button{padding:10px 18px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px}
.btn-dark{background:#111;color:#fff;border:none}.btn-outline{background:#fff;color:#111;border:1.5px solid #ddd}
.paper{max-width:860px;margin:0 auto 32px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.11);border-radius:4px;overflow:hidden}
.paper-head{height:8px;background:linear-gradient(90deg,#0e4a2a 0%,#2e6b3e 35%,#c8a84a 100%)}
.paper-body{padding:36px 40px 28px}
.brand-row{display:flex;justify-content:space-between;gap:20px}
.brand-left{display:flex;gap:14px;align-items:center}
.brand-left img{height:72px}
.brand-left h1{font-size:19px;color:#0e4a2a;line-height:1.1}
.brand-left h1 span{font-weight:400;font-size:11px;letter-spacing:.18em;color:#8a7d5a;display:block;margin-top:2px}
.brand-left p{font-size:11px;color:#666;margin-top:6px;line-height:1.5}
.quote-badge{text-align:right}
.quote-badge .lbl{font-size:10px;letter-spacing:.22em;color:#8a7d5a;font-weight:700}
.quote-badge .num{font-size:22px;font-weight:800;color:#0e4a2a}
.quote-badge .dates{font-size:11px;color:#666;margin-top:6px;line-height:1.6}
.divider{height:1px;background:#eee;margin:20px 0}
.cust-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.box{background:#faf8f2;border:1px solid #e8e2d0;border-radius:12px;padding:14px 16px}
.box h3{font-size:10px;letter-spacing:.14em;color:#8a7d5a;margin-bottom:8px}
.box .v{font-size:13px;line-height:1.6}
.box .v strong{font-size:14px}
.note-box{background:#fff;border:1px dashed #d9d2b8;border-radius:10px;padding:10px 14px;font-size:12px;color:#6b5f3a;margin-top:10px}
table.q{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}
table.q thead th{background:#0e4a2a;color:#fff;padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.06em}
table.q td{padding:11px 12px;border-bottom:1px solid #eee}
table.q td .pn{font-weight:700}
table.q td .pm{font-size:11px;color:#777}
table.q td .mut{color:#999;font-style:italic}
table.q td.r{text-align:right}
table.q td.c{text-align:center}
table.q td.b{font-weight:700}
table.q tfoot td{background:#faf8f2;padding:14px 12px;border-top:2px solid #0e4a2a;font-weight:800}
.terms{margin-top:18px;background:#faf8f2;border:1px solid #e8e2d0;border-radius:12px;padding:14px 16px;font-size:11px;color:#6b5f3a;line-height:1.7}
.sign-row{display:flex;gap:24px;margin-top:22px}
.sign{flex:1;text-align:center}
.sign .line{border-top:1px solid #333;margin-top:40px;padding-top:6px;font-size:11px}
.paper-foot{text-align:center;padding:14px;background:#0e4a2a;color:#d9c9a0;font-size:10px;letter-spacing:.08em}
@media print{body{background:#fff}.toolbar{display:none}.paper{box-shadow:none;margin:0;max-width:none}@page{margin:12mm 10mm}}
</style></head><body>
<div class="toolbar"><button class="btn-dark" onclick="window.print()">🖨️ Print / Save PDF</button><a class="btn-outline" href="/admin/index.php?action=quotes">← Back to Quotes</a></div>
<div class="paper"><div class="paper-head"></div><div class="paper-body">
<div class="brand-row"><div class="brand-left"><img src="/assets/img/logo.png" alt="7 Boys"><div><h1>RUBU AL QUDS<span>FOR TRADING & FOOD INDUSTRIES</span></h1><p>7 Boys® — Premium Food Trading & Distribution<br>Since 1966 · Al-Abdali, Amman, Jordan</p></div></div><div class="quote-badge"><div class="lbl">QUOTATION</div><div class="num"><?= esc($found['id']) ?></div><div class="dates"><strong>Date:</strong> <?= esc(date('d M Y', strtotime($found['at']))) ?><br><strong>Valid until:</strong> <?= esc($valid_until) ?><br><strong>Items:</strong> <?= $n-1 ?></div></div></div>
<div class="divider"></div>
<div class="cust-grid"><div class="box"><h3>BILL TO</h3><div class="v"><?php if(!empty(trim($found['company']??''))): ?><strong style="font-size:15px"><?= esc(trim($found['company'])) ?></strong><br><small style="color:#6b5f3a">Attn: <?= esc($found['name']) ?> · <?= esc($found['email']) ?> · <?= esc($found['phone']) ?></small><?php else: ?><strong><?= esc($found['name']) ?></strong><br><small><?= esc($found['email']) ?> · <?= esc($found['phone']) ?></small><?php endif; ?></div></div><div class="box"><h3>SUPPLIER</h3><div class="v"><strong>Rubu Al Quds — 7 Boys®</strong><br><small>Al-Abdali, Amman, Jordan<br>+962 79 5816444 · wael@7boys.com.jo<br>www.7boysjo.com</small></div></div></div>
<?php if(!empty($found['note'])): ?><div class="note-box"><strong>Note:</strong> <?= esc($found['note']) ?></div><?php endif; ?>
<table class="q"><thead><tr><th class="c" style="width:36px">#</th><th>Product</th><th class="c" style="width:60px">Qty</th><th class="r" style="width:120px">Unit Price</th><th class="r" style="width:120px">Line Total</th></tr></thead><tbody><?= $rows ?></tbody><?php if($has_price): ?><tfoot><tr><td colspan="4" class="r">TOTAL</td><td class="r"><?= number_format($total,2) ?> <span>JOD</span></td></tr></tfoot><?php endif; ?></table>
<div class="terms"><strong>Terms:</strong> Prices valid 14 days. Delivery, MOQ and lead time to be confirmed by sales. Prices exclude VAT/delivery unless stated. Availability subject to stock. This document is a quotation, not a tax invoice.</div>
<div class="sign-row"><div class="sign"><div class="line">Prepared by — 7 Boys® Sales</div></div><div class="sign"><div class="line">Accepted by — Customer signature & stamp</div></div></div>
</div><div class="paper-foot">RUBU AL QUDS FOR TRADING & FOOD INDUSTRIES (7 BOYS®) — SINCE 1966 · AMMAN, JORDAN</div></div>
</body></html>
