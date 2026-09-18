<?php
// do_publish.php — lightweight publisher
// The site is now DYNAMIC (index.php, product.php, etc. render on request),
// so this script only regenerates the SEO helper files (sitemap.xml, robots.txt).
// It no longer writes static HTML pages (those are served by PHP directly).
require_once __DIR__ . '/config.php';
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log', __DIR__.'/php_err.log');
error_reporting(E_ALL);

$products = normalize_products_full(load_products());
$brands   = load_brands();
$cats     = load_cats();

// ===== SITEMAP.XML =====
$sitemap = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
$sitemap .= "  <url><loc>" . SITE_URL . "/</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod><xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . SITE_URL . "\"/><xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . SITE_URL . "\"/><changefreq>daily</changefreq></url>\n";
$sitemap .= "  <url><loc>" . SITE_URL . "/brands.php</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
$sitemap .= "  <url><loc>" . SITE_URL . "/story.php</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
$sitemap .= "  <url><loc>" . SITE_URL . "/contact.php</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
$sitemap .= "  <url><loc>" . SITE_URL . "/quote.php</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
foreach ($cats as $c) $sitemap .= "  <url><loc>" . SITE_URL . "/category.php?cat=" . urlencode($c['slug']) . "</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
foreach ($brands as $b) $sitemap .= "  <url><loc>" . SITE_URL . "/brands.php?brand=" . urlencode($b['slug']) . "</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod></url>\n";
foreach ($products as $p) { $slug=urlencode(product_slug($p)); $sitemap .= "  <url><loc>" . SITE_URL . "/product.php?slug=" . $slug . "</loc><lastmod>" . gmdate("Y-m-d") . "</lastmod><xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . SITE_URL . "/product.php?slug=" . $slug . "\"/><xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . SITE_URL . "/product.php?slug=" . $slug . "\"/></url>\n"; }
$sitemap .= "</urlset>\n";
file_put_contents(SITE_DIR . '/sitemap.xml', $sitemap);

// ===== ROBOTS.TXT =====
$robots = "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . SITE_URL . "/sitemap.xml\n";
file_put_contents(SITE_DIR . '/robots.txt', $robots);

// Optionally purge the old static HTML files so they don't shadow dynamic routes.
// (Kept safe: only removes known-generated filenames.)
foreach ($products as $p) { $f = SITE_DIR . '/product-' . product_slug($p) . '.html'; if (file_exists($f)) @unlink($f); }
foreach ($brands as $b) { $f = SITE_DIR . '/brand-' . $b['slug'] . '.html'; if (file_exists($f)) @unlink($f); }
foreach ($cats as $c) { $f = SITE_DIR . '/categories/' . $c['slug'] . '.html'; if (file_exists($f)) @unlink($f); }
$old = ['/brands.html','/about.html','/categories/index.html','/index.html'];
foreach ($old as $rel) { $f = SITE_DIR . $rel; if (file_exists($f)) @unlink($f); }

echo "Published: sitemap.xml + robots.txt regenerated, old static HTML purged.\n";

// if called with ?r= (from admin save), redirect back to admin (relative only: open-redirect guard)
if (isset($_GET['r'])) {
  $r = (string)$_GET['r'];
  if (preg_match('#^(index\.php|/admin/)#', $r)) header('Location: ' . $r);
  else header('Location: index.php?action=publish');
  exit;
}
