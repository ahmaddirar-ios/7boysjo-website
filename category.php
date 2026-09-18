<?php
// Dynamic category / shop-by-category page
require_once __DIR__ . '/admin/config.php';
$cat = $_GET['cat'] ?? '';
if ($cat === '') {
    // Category grid landing
    $cats = load_cats();
    $products = normalize_products_full(load_products());
    $ext = load_ext(); $GLOBALS['ext'] = $ext;
    $nav = sb_nav();
    $tiles = '';
    // Offers tile (auto, shows only if offers exist)
    $offersCount = count(array_filter($products, fn($p)=> is_offer($p)));
    if($offersCount>0){
        $tiles .= '<a class="cat-tile" href="/offers.php" style="border:1.5px solid #fbbf24;background:linear-gradient(135deg,#fffbf0 0%,#fff 100%)">'
            . '<div class="cat-img" style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);display:flex;align-items:center;justify-content:center"><span style="font-size:15px;font-weight:800;color:#92400e;letter-spacing:.06em">OFFERS</span></div>'
            . '<div class="cat-body"><h3>Special Offers</h3><span class="cat-count" style="background:#fef3c7;color:#92400e">' . $offersCount . ' products</span></div></a>';
    }
    foreach ($cats as $c) {
        $slugs = [$c['slug']]; if(!empty($c['sub'])) foreach($c['sub'] as $s) $slugs[]=$s['slug']; $count = count(array_filter($products, function($p) use ($slugs){ $pc=$p['cat'] ?? $p['category'] ?? ''; return in_array($pc,$slugs,true); }));
        $liveSubs = is_array($c['sub'] ?? null) ? count(array_filter($c['sub'], fn($ss)=> count(array_filter($products, fn($pp)=> ($pp['cat'] ?? '') === $ss['slug'])) > 0)) : 0;
        if ($count == 0 && $liveSubs == 0) continue;
        // BB-G gradients per main cat
        $grads=['food'=>'linear-gradient(135deg,#d6eaf8 0%,#2e7d4f 55%,#0e4a2a 100%)','non-food'=>'linear-gradient(135deg,#e8f8f5 0%,#16a085 55%,#0e4a2a 100%)','pet-food'=>'linear-gradient(135deg,#fdebd0 0%,#e67e22 55%,#7a3b1f 100%)','best-offers'=>'linear-gradient(135deg,#fef9e7 0%,#f1c40f 55%,#9a7d0a 100%)','new-products'=>'linear-gradient(135deg,#eaf2f8 0%,#2980b9 55%,#1a3d5a 100%)','our-exclusive-range'=>'linear-gradient(135deg,#f4ecf7 0%,#8e44ad 55%,#4a235a 100%)'];
        $grad=$grads[$c['slug']]??'linear-gradient(135deg,#a9dfbf 0%,#2e7d4f 100%)';
        $subsHtml='';
        if(!empty($c['sub'])){ $subsHtml='<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">'; $shown=0; foreach($c['sub'] as $s){ $scnt=count(array_filter($products, fn($pp)=> ($pp['cat']??'')===$s['slug'])); if($scnt==0) continue; if($shown>=3){ $remaining=count(array_filter($c['sub'], fn($ss)=> count(array_filter($products, fn($pp)=> ($pp['cat']??'')===$ss['slug']))>0)) - $shown; if($remaining>0) $subsHtml.='<span style="font-size:10px;color:#64748b;padding:3px 7px">+'.$remaining.' more</span>'; break; } $subsHtml.='<span class="cat-sub" data-href="/category.php?cat='.esc($s['slug']).'" onclick="event.stopPropagation();location.href=this.dataset.href">'.esc($s['name']).' '.$scnt.'</span>'; $shown++; } $subsHtml.='</div>'; }
        $tiles .= '<a class="cat-tile" href="/category.php?cat=' . esc($c['slug']) . '">'
            . '<div class="cat-img" style="background:'.$grad.';display:flex;align-items:center;justify-content:center"><span class="cat-overlay" style="position:static;transform:none;background:none;color:#fff;font-weight:800;font-size:22px" data-i18n="'.esc($c['slug']).'">' . esc($c['name']) . '</span></div>'
            . '<div class="cat-body"><div style="display:flex;align-items:center;justify-content:space-between"><h3 data-i18n="'.esc($c['slug']).'">' . esc($c['name']) . '</h3><span class="cat-count">' . $count . ' products</span></div>'.$subsHtml.'</div></a>';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Categories — 7 Boys®</title><link rel="stylesheet" href="' . asset_css() . '"></head><body>'
        . site_header($nav)
        . '<section class="cat-hero"><div class="inner"><h1 data-i18n="shop_title">Shop by Category</h1><p data-i18n="shop_sub">From premium beverages to gourmet delights — every aisle curated with care.</p></div></section>'
        . '<main class="container"><section class="cat-grid">' . $tiles . '</section></main>'
        . site_footer() . whatsapp_float($ext) . '<script src="/assets/js/carousel.js"></script></body></html>';
} else {
    echo render_category($cat);
}
