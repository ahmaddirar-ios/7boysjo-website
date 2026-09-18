<?php
// Dynamic product detail page
require_once __DIR__ . '/admin/config.php';
$slug = $_GET['slug'] ?? '';
$html = render_product($slug);
if ($html === null) {
    header("HTTP/1.0 404 Not Found");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not Found — 7 Boys®</title>'
       . '<link rel="stylesheet" href="' . asset_css() . '"></head><body>'
       . site_header(sb_nav())
       . '<main class="container"><h1 class="page-title">Product not found</h1>'
       . '<p>The product you are looking for does not exist. <a href="/">Return home</a>.</p></main>'
       . site_footer() . '</body></html>';
    exit;
}
echo $html;
