<?php
/**
 * 7 Boys® — SEO Manager
 * Edit meta tags, sitemap, robots.txt, and schema markup
 */
require_once __DIR__ . '/config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

define('SEO_FILE', __DIR__ . '/data/seo.json');
define('SITEMAP_FILE', dirname(__DIR__) . '/sitemap.xml');
define('ROBOTS_FILE', dirname(__DIR__) . '/robots.txt');

$default_seo = [
    'pages' => [
        'home' => [
            'title' => '7 Boys® | Rubu Al Quds — Premium Food Trading & Distribution Since 1966',
            'description' => 'Rubu Al Quds — Premium Food Trading & Distribution Since 1966. 500+ global brands, 100+ countries, 3 generations. Al-Abdali, Amman.',
            'keywords' => 'food trading, beverage distribution, premium food, wholesale, Jordan, Amman, 7 Boys, Rubu Al Quds',
            'og_title' => '7 Boys® | Rubu Al Quds — Since 1966',
            'og_description' => 'Premium Food Trading & Distribution Since 1966 — 500+ brands, 100+ countries.',
            'og_image' => '/assets/img/logo.png',
            'canonical' => 'https://7boysjo.com/',
            'index' => true,
            'follow' => true,
        ],
        'brands' => [
            'title' => 'Brands — 7 Boys® | Rubu Al Quds',
            'description' => 'Explore 200+ global brands distributed by Rubu Al Quds — 7 Boys since 1966. Al-Abdali, Amman.',
            'keywords' => 'brands, food brands, beverage brands, premium brands',
            'og_title' => 'Brands — 7 Boys | Rubu Al Quds',
            'og_description' => '200+ global brands — 7 Boys since 1966',
            'og_image' => '/assets/img/logo.png',
            'canonical' => 'https://7boysjo.com/brands.php',
            'index' => true,
            'follow' => true,
        ],
        'categories' => [
            'title' => 'Categories — 7 Boys® | Rubu Al Quds',
            'description' => 'Browse premium food and beverage categories. Beverages, Sweets, Gourmet & more from 7 Boys.',
            'keywords' => 'food categories, beverages, sweets, gourmet, staples',
            'og_title' => 'Categories — 7 Boys | Rubu Al Quds',
            'og_description' => 'Premium food and beverage categories — 7 Boys',
            'og_image' => '/assets/img/logo.png',
            'canonical' => 'https://7boysjo.com/category.php',
            'index' => true,
            'follow' => true,
        ],
        'products' => [
            'title' => 'Products — 7 Boys® | Rubu Al Quds',
            'description' => 'Premium imported food and beverage products available for wholesale and retail distribution.',
            'keywords' => 'products, imported food, wholesale beverages, retail products',
            'og_title' => 'Products — 7 Boys | Rubu Al Quds',
            'og_description' => 'Premium imported products — 7 Boys since 1966',
            'og_image' => '/assets/img/logo.png',
            'canonical' => 'https://7boysjo.com/product.php',
            'index' => true,
            'follow' => true,
        ],
        'contact' => [
            'title' => 'Contact Us — 7 Boys® | Rubu Al Quds',
            'description' => 'Get in touch with 7 Boys | Rubu Al Quds. Premium food trading and distribution. Al-Abdali, Amman, Jordan.',
            'keywords' => 'contact, food trading contact, Amman, Jordan, wholesale inquiry',
            'og_title' => 'Contact Us — 7 Boys | Rubu Al Quds',
            'og_description' => 'Contact 7 Boys for premium food trading and distribution',
            'og_image' => '/assets/img/logo.png',
            'canonical' => 'https://7boysjo.com/contact.php',
            'index' => true,
            'follow' => true,
        ],
    ],
    'schema' => [
        'organization' => [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => '7 Boys | Rubu Al Quds',
            'url' => 'https://7boysjo.com',
            'logo' => 'https://7boysjo.com/assets/img/logo.png',
            'foundingDate' => '1966',
            'description' => 'Premium Food Trading & Distribution Since 1966',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Al-Abdali',
                'addressLocality' => 'Amman',
                'addressCountry' => 'JO',
            ],
            'telephone' => '+962795816444',
            'email' => 'wael@7boys.com.jo',
        ],
        'product' => [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => '',
            'brand' => '',
            'description' => '',
            'offers' => [
                '@type' => 'Offer',
                'availability' => 'https://schema.org/InStock',
                'priceCurrency' => 'JOD',
            ],
        ],
        'breadcrumb' => [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://7boysjo.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Products', 'item' => 'https://7boysjo.com/product.php'],
            ],
        ],
    ],
    'robots' => "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: https://7boysjo.com/sitemap.xml\n",
    'sitemap_enabled' => true,
    'sitemap_last_generated' => '',
    'open_graph' => [
        'og_site_name' => '7 Boys | Rubu Al Quds',
        'og_locale' => 'en_US',
        'og_type' => 'website',
        'twitter_card' => 'summary_large_image',
    ],
];

$msg = '';
$errors = [];
$active_page = $_GET['page'] ?? 'home';

function load_seo() {
    if (!file_exists(SEO_FILE)) return $GLOBALS['default_seo'];
    $d = json_decode(file_get_contents(SEO_FILE), true);
    return is_array($d) ? array_replace_recursive($GLOBALS['default_seo'], $d) : $GLOBALS['default_seo'];
}

function save_seo($data) {
    if (!is_dir(dirname(SEO_FILE))) mkdir(dirname(SEO_FILE), 0755, true);
    file_put_contents(SEO_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function generate_sitemap() {
    $products = normalize_products_full(load_products());
    $brands = load_brands();
    $cats = load_cats();
    $today = date('Y-m-d');
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    
    // Static pages
    static_pages('/', '1.0', $today, $xml);
    static_pages('/brands.php', '0.8', $today, $xml);
    static_pages('/category.php', '0.8', $today, $xml);
    static_pages('/product.php', '0.7', $today, $xml);
    static_pages('/contact.php', '0.6', $today, $xml);
    static_pages('/offers.php', '0.7', $today, $xml);
    static_pages('/story.php', '0.6', $today, $xml);
    
    // Product pages
    foreach ($products as $p) {
        $slug = product_slug($p);
        $xml .= "  <url>\n";
        $xml .= "    <loc>https://7boysjo.com/product.php?slug=" . rawurlencode($slug) . "</loc>\n";
        $xml .= "    <lastmod>" . ($today) . "</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.7</priority>\n";
        if (!empty($p['image'])) {
            $xml .= "    <image:image>\n";
            $xml .= "      <image:loc>https://7boysjo.com/assets/img/" . esc($p['image']) . "</image:loc>\n";
            $xml .= "      <image:title>" . esc($p['name']) . "</image:title>\n";
            $xml .= "    </image:image>\n";
        }
        $xml .= "  </url>\n";
    }
    
    // Brand pages
    foreach ($brands as $b) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>https://7boysjo.com/brands.php?brand=" . urlencode($b['slug']) . "</loc>\n";
        $xml .= "    <lastmod>" . $today . "</lastmod>\n";
        $xml .= "    <changefreq>monthly</changefreq>\n";
        $xml .= "    <priority>0.6</priority>\n";
        $xml .= "  </url>\n";
    }
    
    // Category pages
    foreach ($cats as $c) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>https://7boysjo.com/category.php?cat=" . urlencode($c['slug']) . "</loc>\n";
        $xml .= "    <lastmod>" . $today . "</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.7</priority>\n";
        $xml .= "  </url>\n";
    }
    
    $xml .= '</urlset>';
    
    file_put_contents(SITEMAP_FILE, $xml, LOCK_EX);
    return SITEMAP_FILE;
}

function static_pages($path, $priority, $date, &$xml) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>https://7boysjo.com" . $path . "</loc>\n";
    $xml .= "    <lastmod>" . $date . "</lastmod>\n";
    $xml .= "    <changefreq>weekly</changefreq>\n";
    $xml .= "    <priority>" . $priority . "</priority>\n";
    $xml .= "  </url>\n";
}

function get_seo_score($seo) {
    $score = 0;
    $total = 0;
    $checks = [];
    
    foreach ($seo['pages'] as $page_key => $page) {
        // Title length check (50-60 optimal)
        $total++;
        $title_len = strlen($page['title'] ?? '');
        if ($title_len >= 30 && $title_len <= 60) {
            $score++;
            $checks[] = ['page' => $page_key, 'check' => 'title_length', 'status' => 'pass', 'msg' => 'Title length OK (' . $title_len . ' chars)'];
        } else {
            $checks[] = ['page' => $page_key, 'check' => 'title_length', 'status' => 'warn', 'msg' => 'Title length: ' . $title_len . ' (ideal: 30-60)'];
        }
        
        // Description length (150-160 optimal)
        $total++;
        $desc_len = strlen($page['description'] ?? '');
        if ($desc_len >= 120 && $desc_len <= 160) {
            $score++;
            $checks[] = ['page' => $page_key, 'check' => 'desc_length', 'status' => 'pass', 'msg' => 'Description length OK (' . $desc_len . ' chars)'];
        } else {
            $checks[] = ['page' => $page_key, 'check' => 'desc_length', 'status' => 'warn', 'msg' => 'Description length: ' . $desc_len . ' (ideal: 120-160)'];
        }
        
        // OG tags present
        $total++;
        if (!empty($page['og_title']) && !empty($page['og_description'])) {
            $score++;
            $checks[] = ['page' => $page_key, 'check' => 'og_tags', 'status' => 'pass', 'msg' => 'Open Graph tags present'];
        } else {
            $checks[] = ['page' => $page_key, 'check' => 'og_tags', 'status' => 'warn', 'msg' => 'Open Graph tags missing'];
        }
        
        // Canonical URL
        $total++;
        if (!empty($page['canonical'])) {
            $score++;
            $checks[] = ['page' => $page_key, 'check' => 'canonical', 'status' => 'pass', 'msg' => 'Canonical URL set'];
        } else {
            $checks[] = ['page' => $page_key, 'check' => 'canonical', 'status' => 'fail', 'msg' => 'Canonical URL missing'];
        }
        
        // Keywords
        $total++;
        if (!empty($page['keywords'])) {
            $score++;
            $checks[] = ['page' => $page_key, 'check' => 'keywords', 'status' => 'pass', 'msg' => 'Meta keywords present'];
        } else {
            $checks[] = ['page' => $page_key, 'check' => 'keywords', 'status' => 'info', 'msg' => 'No meta keywords (optional)'];
        }
    }
    
    // Schema markup check
    $total += 2;
    if (!empty($seo['schema']['organization']['name'])) {
        $score++;
        $checks[] = ['page' => 'global', 'check' => 'org_schema', 'status' => 'pass', 'msg' => 'Organization schema present'];
    } else {
        $checks[] = ['page' => 'global', 'check' => 'org_schema', 'status' => 'fail', 'msg' => 'Organization schema missing'];
    }
    if (!empty($seo['schema']['breadcrumb']['itemListElement'])) {
        $score++;
        $checks[] = ['page' => 'global', 'check' => 'breadcrumb_schema', 'status' => 'pass', 'msg' => 'Breadcrumb schema present'];
    } else {
        $checks[] = ['page' => 'global', 'check' => 'breadcrumb_schema', 'status' => 'warn', 'msg' => 'Breadcrumb schema missing'];
    }
    
    // Sitemap check
    $total++;
    if (file_exists(SITEMAP_FILE)) {
        $score++;
        $checks[] = ['page' => 'global', 'check' => 'sitemap', 'status' => 'pass', 'msg' => 'Sitemap.xml exists'];
    } else {
        $checks[] = ['page' => 'global', 'check' => 'sitemap', 'status' => 'fail', 'msg' => 'Sitemap.xml missing'];
    }
    
    // Robots.txt check
    $total++;
    if (file_exists(ROBOTS_FILE)) {
        $score++;
        $checks[] = ['page' => 'global', 'check' => 'robots', 'status' => 'pass', 'msg' => 'Robots.txt exists'];
    } else {
        $checks[] = ['page' => 'global', 'check' => 'robots', 'status' => 'fail', 'msg' => 'Robots.txt missing'];
    }
    
    return ['score' => $score, 'total' => $total, 'percentage' => $total > 0 ? round(($score / $total) * 100) : 0, 'checks' => $checks];
}

$seo = load_seo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['csrf']) && !csrf_check($_POST['csrf'])) {
        $errors[] = 'Invalid security token.';
    }

    if (isset($_POST['save_seo']) && empty($errors)) {
        $page = $_POST['page_key'] ?? 'home';
        if (isset($seo['pages'][$page])) {
            $fields = ['title','description','keywords','og_title','og_description','og_image','canonical'];
            foreach ($fields as $f) {
                $seo['pages'][$page][$f] = trim($_POST[$f] ?? '');
            }
            $seo['pages'][$page]['index'] = isset($_POST['index']);
            $seo['pages'][$page]['follow'] = isset($_POST['follow']);
            save_seo($seo);
            $active_page = $page;
            $msg = "SEO settings for '$page' saved.";
        }
    }

    if (isset($_POST['save_robots']) && empty($errors)) {
        $seo['robots'] = trim($_POST['robots_content'] ?? $default_seo['robots']);
        file_put_contents(ROBOTS_FILE, $seo['robots'], LOCK_EX);
        save_seo($seo);
        $msg = 'Robots.txt updated.';
    }

    if (isset($_POST['regenerate_sitemap']) && empty($errors)) {
        generate_sitemap();
        $seo['sitemap_last_generated'] = date('Y-m-d H:i:s');
        save_seo($seo);
        $msg = 'Sitemap.xml regenerated.';
    }

    if (isset($_POST['save_schema']) && empty($errors)) {
        $schema_type = $_POST['schema_type'] ?? 'organization';
        $schema_json = trim($_POST['schema_json'] ?? '');
        $decoded = json_decode($schema_json, true);
        if (is_array($decoded)) {
            $seo['schema'][$schema_type] = $decoded;
            save_seo($seo);
            $msg = "Schema markup for '$schema_type' saved.";
        } else {
            $errors[] = 'Invalid JSON in schema markup.';
        }
    }

    if (isset($_POST['save_og']) && empty($errors)) {
        $seo['open_graph']['og_site_name'] = trim($_POST['og_site_name'] ?? '');
        $seo['open_graph']['og_locale'] = trim($_POST['og_locale'] ?? 'en_US');
        $seo['open_graph']['og_type'] = trim($_POST['og_type'] ?? 'website');
        $seo['open_graph']['twitter_card'] = trim($_POST['twitter_card'] ?? 'summary_large_image');
        save_seo($seo);
        $msg = 'Open Graph settings saved.';
    }

    if (isset($_POST['generate_auto_desc']) && empty($errors)) {
        $page = $_POST['auto_page'] ?? 'home';
        $content = $_POST['content_text'] ?? '';
        if (!empty($content) && isset($seo['pages'][$page])) {
            // Generate description from first 160 chars of content
            $generated = substr(trim(strip_tags($content)), 0, 160);
            if (strlen($generated) >= 120) {
                $seo['pages'][$page]['description'] = $generated . '...';
                save_seo($seo);
                $msg = 'Auto-generated description saved.';
            } else {
                $errors[] = 'Content too short. Need at least 120 characters.';
            }
        }
    }
}

$seo_score = get_seo_score($seo);
$csrf = csrf_token();
$page_labels = [
    'home' => 'Home Page',
    'brands' => 'Brands Page',
    'categories' => 'Categories Page',
    'products' => 'Products Page',
    'contact' => 'Contact Page',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>SEO Manager — 7 Boys® Admin</title>
<style>
.seo-manager{max-width:1400px;margin:0 auto;padding:28px 20px 60px}
.seo-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.seo-head h1{font-family:var(--display);font-size:28px;font-weight:800;letter-spacing:-.02em}
.seo-head p{color:var(--muted);font-size:14px}
.seo-grid{display:grid;grid-template-columns:1fr 400px;gap:24px}
@media(max-width:1000px){.seo-grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:20px;box-shadow:var(--shadow-sm);margin-bottom:20px}
.panel h2{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.score-circle{width:120px;height:120px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-direction:column;margin:0 auto 16px}
.score-circle .num{font-size:32px;font-weight:800}
.score-circle .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.score-good{background:#dcfce7;color:#166534}.score-ok{background:#fef3c7;color:#92400e}.score-bad{background:#fee2e2;color:#991b1b}
.check-item{display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px}
.check-item:last-child{border-bottom:none}
.check-icon{font-weight:700;width:20px;flex-shrink:0}
.check-pass{color:#16a34a}.check-warn{color:#ca8a04}.check-fail{color:#dc2626}.check-info{color:#6b7280}
.tabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}
.tab{padding:8px 16px;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;background:var(--bg-soft);border:1px solid transparent}
.tab.active{background:var(--green);color:#fff}
.form-row{margin-bottom:12px}
.form-row label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px}
.form-row input[type=text],.form-row textarea,.form-row select{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box}
.form-row textarea{min-height:80px;resize:vertical;font-family:monospace;font-size:12px}
.char-count{font-size:11px;color:var(--muted);text-align:right;margin-top:2px}
.toggle-group{display:flex;gap:12px;align-items:center;margin-bottom:12px}
.toggle-group label{font-size:13px;font-weight:600}
.btn-seo{background:var(--green);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px}
.btn-seo:hover{opacity:.9}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.sitemap-status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600}
.sitemap-status.ok{background:#dcfce7;color:#166534}
.sitemap-status.warn{background:#fef3c7;color:#92400e}
pre{background:var(--bg-soft);border:1px solid var(--line);border-radius:8px;padding:12px;font-size:12px;overflow-x:auto;max-height:200px}
</style>
</head>
<body style="background:var(--bg)">
<div class="seo-manager">
    <a href="/admin/" style="display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--green);margin-bottom:14px">← Back to Admin</a>
    <div class="seo-head">
        <div>
            <h1>🔍 SEO Manager</h1>
            <p>Optimize meta tags, schema markup, and search visibility</p>
        </div>
    </div>

    <?php if ($msg): ?><div class="msg" style="margin:16px 0;padding:12px 16px;background:#dcfce7;color:#166534;border-radius:10px;font-weight:600;"><?= esc($msg) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="msg" style="margin:8px 0;padding:12px 16px;background:#fee2e2;color:#991b1b;border-radius:10px;font-weight:600;"><?= esc($e) ?></div><?php endif; ?>

    <div class="seo-grid">
        <div>
            <!-- Page SEO Tabs -->
            <div class="panel">
                <h2>📄 Page Meta Tags</h2>
                <div class="tabs">
                    <?php foreach ($page_labels as $pk => $pl): ?>
                    <a href="?page=<?= $pk ?>" class="tab <?= $active_page===$pk?'active':'' ?>"><?= $pl ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="page_key" value="<?= esc($active_page) ?>">
                    <?php $page_data = $seo['pages'][$active_page] ?? $default_seo['pages']['home']; ?>
                    <div class="form-row">
                        <label>Page Title (30-60 chars)</label>
                        <input type="text" name="title" id="seo_title" value="<?= esc($page_data['title']) ?>" maxlength="70" oninput="document.getElementById('title_count').textContent=this.value.length+'/70'">
                        <div class="char-count" id="title_count"><?= strlen($page_data['title']) ?>/70</div>
                    </div>
                    <div class="form-row">
                        <label>Meta Description (120-160 chars)</label>
                        <textarea name="description" id="seo_desc" maxlength="200" oninput="document.getElementById('desc_count').textContent=this.value.length+'/200'"><?= esc($page_data['description']) ?></textarea>
                        <div class="char-count" id="desc_count"><?= strlen($page_data['description']) ?>/200</div>
                    </div>
                    <div class="form-row">
                        <label>Meta Keywords (comma separated)</label>
                        <input type="text" name="keywords" value="<?= esc($page_data['keywords']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Canonical URL</label>
                        <input type="text" name="canonical" value="<?= esc($page_data['canonical']) ?>">
                    </div>
                    <div class="toggle-group">
                        <label><input type="checkbox" name="index" <?= !empty($page_data['index'])?'checked':'' ?>> Index</label>
                        <label><input type="checkbox" name="follow" <?= !empty($page_data['follow'])?'checked':'' ?>> Follow</label>
                    </div>
                    <div class="form-row" style="margin-top:16px">
                        <label>Open Graph Title</label>
                        <input type="text" name="og_title" value="<?= esc($page_data['og_title']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Open Graph Description</label>
                        <input type="text" name="og_description" value="<?= esc($page_data['og_description']) ?>">
                    </div>
                    <div class="form-row">
                        <label>OG Image URL</label>
                        <input type="text" name="og_image" value="<?= esc($page_data['og_image']) ?>">
                    </div>
                    <div class="actions">
                        <button type="submit" name="save_seo" class="btn-seo">💾 Save Meta Tags</button>
                    </div>
                </form>
            </div>

            <!-- Schema Markup -->
            <div class="panel">
                <h2>📊 Schema Markup</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Schema Type</label>
                        <select name="schema_type" id="schema_type" onchange="loadSchema()">
                            <option value="organization">Organization</option>
                            <option value="product">Product</option>
                            <option value="breadcrumb">BreadcrumbList</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Schema JSON-LD</label>
                        <textarea name="schema_json" id="schema_json" style="min-height:200px"><?= esc(json_encode($seo['schema']['organization'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" name="save_schema" class="btn-seo">💾 Save Schema</button>
                    </div>
                </form>
            </div>

            <!-- Robots.txt Editor -->
            <div class="panel">
                <h2>🤖 Robots.txt</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <textarea name="robots_content" style="min-height:120px;font-family:monospace"><?= esc($seo['robots']) ?></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" name="save_robots" class="btn-seo">💾 Save Robots.txt</button>
                    </div>
                </form>
            </div>

            <!-- Sitemap -->
            <div class="panel">
                <h2>🗺️ XML Sitemap</h2>
                <?php $sitemap_exists = file_exists(SITEMAP_FILE); ?>
                <div class="sitemap-status <?= $sitemap_exists?'ok':'warn' ?>">
                    <?= $sitemap_exists ? '✅ Sitemap.xml exists (' . date('M j, Y H:i', filemtime(SITEMAP_FILE)) . ')' : '⚠️ Sitemap.xml not generated yet' ?>
                </div>
                <?php if (!empty($seo['sitemap_last_generated'])): ?>
                <p style="font-size:12px;color:var(--muted);margin-top:8px">Last generated: <?= esc($seo['sitemap_last_generated']) ?></p>
                <?php endif; ?>
                <div class="actions">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                        <button type="submit" name="regenerate_sitemap" class="btn-seo">🔄 Regenerate Sitemap</button>
                    </form>
                </div>
            </div>

            <!-- Open Graph Settings -->
            <div class="panel">
                <h2>🌐 Open Graph Settings</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Site Name</label>
                        <input type="text" name="og_site_name" value="<?= esc($seo['open_graph']['og_site_name']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Locale</label>
                        <input type="text" name="og_locale" value="<?= esc($seo['open_graph']['og_locale']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Type</label>
                        <input type="text" name="og_type" value="<?= esc($seo['open_graph']['og_type']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Twitter Card Type</label>
                        <input type="text" name="twitter_card" value="<?= esc($seo['open_graph']['twitter_card']) ?>">
                    </div>
                    <div class="actions">
                        <button type="submit" name="save_og" class="btn-seo">💾 Save OG Settings</button>
                    </div>
                </form>
            </div>

            <!-- Auto Generate Description -->
            <div class="panel">
                <h2>🤖 Auto-Generate Meta Description</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Target Page</label>
                        <select name="auto_page">
                            <?php foreach ($page_labels as $pk => $pl): ?>
                            <option value="<?= $pk ?>"><?= $pl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Content Source (paste page text, first 160 chars will be used)</label>
                        <textarea name="content_text" style="min-height:100px" placeholder="Paste your page content here to auto-generate a meta description..."></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" name="generate_auto_desc" class="btn-seo">⚡ Generate Description</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sidebar: SEO Score -->
        <div>
            <div class="panel" style="text-align:center">
                <h2>🏆 SEO Score</h2>
                <div class="score-circle <?= $seo_score['percentage'] >= 80 ? 'score-good' : ($seo_score['percentage'] >= 50 ? 'score-ok' : 'score-bad') ?>">
                    <span class="num"><?= $seo_score['percentage'] ?>%</span>
                    <span class="lbl">Score</span>
                </div>
                <p style="font-size:13px;color:var(--muted)"><?= $seo_score['score'] ?> / <?= $seo_score['total'] ?> checks passed</p>
            </div>

            <div class="panel">
                <h2>✅ SEO Checklist</h2>
                <?php foreach ($seo_score['checks'] as $check): ?>
                <div class="check-item">
                    <span class="check-icon check-<?= $check['status'] ?>">
                        <?= $check['status'] === 'pass' ? '✓' : ($check['status'] === 'warn' ? '⚠' : '✗') ?>
                    </span>
                    <div>
                        <strong><?= esc($page_labels[$check['page']] ?? ucfirst($check['page'])) ?></strong>
                        <br><span style="color:var(--muted)"><?= esc($check['msg']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="panel">
                <h2>💡 SEO Tips</h2>
                <ul style="font-size:13px;color:var(--muted);padding-left:16px;line-height:1.8">
                    <li>Keep titles between 30-60 characters</li>
                    <li>Descriptions should be 120-160 characters</li>
                    <li>Use unique meta tags for each page</li>
                    <li>Add Open Graph tags for social sharing</li>
                    <li>Keep sitemap updated with new content</li>
                    <li>Use schema markup for rich snippets</li>
                    <li>Canonical URLs prevent duplicate content</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
var schemaData = <?= json_encode($seo['schema'], JSON_UNESCAPED_UNICODE) ?>;
function loadSchema() {
    var type = document.getElementById('schema_type').value;
    var data = schemaData[type] || {};
    document.getElementById('schema_json').value = JSON.stringify(data, null, 2);
}
</script>
</body>
</html>
