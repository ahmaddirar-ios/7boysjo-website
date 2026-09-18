<?php
require_once __DIR__.'/admin/config.php';
$products = normalize_products_full(load_products());
$offers = array_values(array_filter($products, fn($p)=> is_offer($p)));
$expired = array_values(array_filter($products, fn($p)=> is_expired($p)));
usort($offers, fn($a,$b)=> strtotime($a['expiry_date']) <=> strtotime($b['expiry_date']));
$brands = load_brands();
$nav = sb_nav();
$html='<!DOCTYPE html><html lang="'.($GLOBALS['lang']??'en').'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offers — 7 Boys®</title><link rel="stylesheet" href="'.asset_css().'"><style>
.badge-offer{background:#fff;color:#92400e;border:1px solid #fbbf24;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-expired{background:#dc2626;color:#fff;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800}
.offer-price{color:#b45309;font-weight:800}
.orig-price{text-decoration:line-through;color:#999;font-size:13px;margin-left:6px}
.expiry{font-size:12px;color:#92400e;margin-top:4px}
</style></head><body>'.site_header($nav).'<main class="container">';
$html.='<h1 class="page-title" style="justify-content:center;text-align:center">Special Offers <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700">'.count($offers).' items</span></h1>';
$html.='<p style="color:var(--muted);margin-top:-8px;text-align:center">Products expiring within 30 days — automatically updated. Offer price shown where available.</p>';
if(empty($offers)){
  $html.='<div style="text-align:center;padding:48px 20px;background:#fff;border-radius:16px;margin-top:20px"><p style="font-size:48px">—</p><p style="font-size:18px;font-weight:700">No offers right now</p><p style="color:var(--muted)">Products with expiry within 30 days will appear here automatically.</p><a href="/category.php?cat=beverages" class="btn" style="margin-top:12px;display:inline-block;background:var(--green);color:#fff;padding:10px 20px;border-radius:999px;text-decoration:none">Browse products</a></div>';
}else{
  $html.='<section class="cat-products" style="margin-top:20px">';
  foreach($offers as $p){
    $slug=product_slug($p);
    $days=(int)ceil((strtotime($p['expiry_date'])-time())/86400);
    $badge='<span class="badge-offer">'.$days.' days left</span>';
    $priceHtml='';
    if(!empty($p['offer_price'])) $priceHtml='<div><span class="offer-price">'.esc($p['offer_price']).'</span>'.(!empty($p['price'])?'<span class="orig-price">'.esc($p['price']).'</span>':'').'</div>';
    elseif(!empty($p['price'])) $priceHtml='<div style="color:var(--muted);font-size:13px">'.esc($p['price']).'</div>';
    $html.='<a class="prod-card" href="/product.php?slug='.esc($slug).'" style="position:relative"><div class="img-wrap"><img src="/assets/img/'.esc($p['image']).'" alt="'.esc($p['name']).'" loading="lazy"><div style="position:absolute;top:8px;left:8px">'.$badge.'</div></div><div class="body"><span class="tag">'.esc(brand_name($brands,$p['brand']??'')).'</span><h3>'.esc($p['name']).'</h3>'.$priceHtml.'<div class="expiry">Expiry: '.esc($p['expiry_date']).'</div></div></a>';
  }
  $html.='</section>';
}
$html.='</main>'.site_footer().whatsapp_float(load_ext()).'<script src="/assets/js/carousel.js"></script></body></html>';
echo $html;
