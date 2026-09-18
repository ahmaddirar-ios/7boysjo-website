<?php
require_once __DIR__ . '/admin/config.php';
$products = normalize_products_full(load_products());
$brands = load_brands();
$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
  $qlow = strtolower($q);
  foreach ($products as $p) {
    $hay = strtolower($p['name'] . ' ' . ($p['brand'] ?? '') . ' ' . ($p['origin'] ?? '') . ' ' . ($p['desc'] ?? ''));
    if (strpos($hay, $qlow) !== false) $results[] = $p;
  }
}
$nav = '<a href="/categories/beverages.html">Beverages</a> | <a href="/categories/sweets.html">Sweets</a> | <a href="/categories/gourmet.html">Gourmet</a> | <a href="/categories/staples.html">Staples</a> | <a href="/brands.html">Brands</a> | <a href="/search.php">Search</a> | <a href="/contact.php">Contact</a>';
function card_in_search($p, $brands) {
  $slug = product_slug($p);
  $bn = brand_name($brands, $p['brand'] ?? '');
  return '<a class="prod-card" href="/product-' . $slug . '.html"><div class="img-wrap"><img src="/assets/img/' . $p['image'] . '" alt="' . esc($p['name']) . '" loading="lazy"></div><div class="body"><span class="tag">' . esc($bn) . '</span><h3>' . esc($p['name']) . '</h3><p>' . esc($p['desc']) . '</p><span class="origin-chip">' . esc($p['origin']) . '</span></div></a>';
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Search — 7 Boys®</title><link rel="stylesheet" href="/assets/css/hdr3.css"></head><body>
<?= site_header($nav) ?>
<section class="cat-hero"><div class="inner"><h1>Search</h1><p>Find products and brands across our catalogue.</p></div></section>
<main class="container">
  <form method="get" style="margin:20px 0;display:flex;gap:10px;max-width:520px;">
    <input name="q" value="<?= esc($q) ?>" placeholder="Search products, brands…" style="flex:1;padding:11px;border:1px solid #ccc;border-radius:8px;font-size:15px;">
    <button style="background:#053d20;color:#fff;border:0;padding:11px 22px;border-radius:8px;font-size:15px;cursor:pointer;">Search</button>
  </form>
  <?php if ($q !== ''): ?>
    <p style="color:#666;margin-bottom:14px;"><?= count($results) ?> result(s) for <strong><?= esc($q) ?></strong></p>
    <?php if ($results): ?>
      <section class="cat-products"><?= implode('', array_map(fn($p)=>card_in_search($p,$brands), $results)) ?></section>
    <?php else: ?>
      <p style="color:#999;">No matches found.</p>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?= site_footer() ?>
<?= whatsapp_float(load_ext()) ?>
<?= cookie_bar(load_ext()) ?>
<script src="/assets/js/carousel.js"></script></body></html>
