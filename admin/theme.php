<?php
/**
 * 7 Boys® — Theme Builder
 * Visual theme customization with live preview
 */
require_once __DIR__ . '/config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

define('THEME_FILE', __DIR__ . '/data/theme.json');
define('THEME_CSS', dirname(__DIR__) . '/assets/css/theme-generated.css');

$default_theme = [
    'primary_color'   => '#2e7d4f',
    'secondary_color' => '#b8923f',
    'background'      => '#fbfaf6',
    'header_bg'       => 'rgba(255,255,255,.86)',
    'footer_bg'       => '#121712',
    'text_color'      => '#1d271f',
    'link_color'      => '#2e7d4f',
    'font_family'     => 'Inter',
    'layout'          => 'full-width',
    'sidebar_position'=> 'right',
];

$msg = '';
$errors = [];

function load_theme() {
    if (!file_exists(THEME_FILE)) return $GLOBALS['default_theme'];
    $d = json_decode(file_get_contents(THEME_FILE), true);
    return is_array($d) ? array_merge($GLOBALS['default_theme'], $d) : $GLOBALS['default_theme'];
}

function save_theme($data) {
    if (!is_dir(dirname(THEME_FILE))) mkdir(dirname(THEME_FILE), 0755, true);
    file_put_contents(THEME_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function generate_theme_css($theme) {
    $primary = $theme['primary_color'];
    $secondary = $theme['secondary_color'];
    $bg = $theme['background'];
    $header_bg = $theme['header_bg'];
    $footer_bg = $theme['footer_bg'];
    $text = $theme['text_color'];
    $link = $theme['link_color'];
    $font = $theme['font_family'];
    $layout = $theme['layout'];
    $sidebar = $theme['sidebar_position'];

    // Compute helper colors
    $primary_dark = adjust_brightness($primary, -20);
    $green_soft = adjust_brightness($primary, 80);
    $muted = adjust_brightness($text, 40);
    $line = adjust_brightness($text, 80);

    $maxw = $layout === 'boxed' ? '1200px' : '100%';

    $font_imports = [
        'Inter'    => "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');",
        'Cairo'    => "@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');",
        'Tajawal'  => "@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');',
        'Open Sans'=> "@import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap');",
    ];

    $import = $font_imports[$font] ?? $font_imports['Inter'];

    $sidebar_css = '';
    if ($sidebar === 'left') {
        $sidebar_css = "main.container { display: grid; grid-template-columns: 280px 1fr; gap: 30px; }
@media(max-width:860px) { main.container { grid-template-columns: 1fr; } }";
    } elseif ($sidebar === 'right') {
        $sidebar_css = "main.container { display: grid; grid-template-columns: 1fr 280px; gap: 30px; }
@media(max-width:860px) { main.container { grid-template-columns: 1fr; } }";
    } else {
        $sidebar_css = "main.container { display: block; }";
    }

    $css = "/* ============================================================
   7 Boys® — Auto-generated Theme CSS
   Generated: " . date('Y-m-d H:i:s') . "
   ============================================================ */
{$import}

:root {
  --theme-primary: {$primary};
  --theme-primary-dark: {$primary_dark};
  --theme-secondary: {$secondary};
  --theme-bg: {$bg};
  --theme-header-bg: {$header_bg};
  --theme-footer-bg: {$footer_bg};
  --theme-text: {$text};
  --theme-link: {$link};
  --theme-muted: {$muted};
  --theme-line: {$line};
  --theme-green-soft: {$green_soft};
  --theme-font: \"{$font}\", -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;
}

body {
  font-family: var(--theme-font);
  background: var(--theme-bg);
  color: var(--theme-text);
}

a { color: var(--theme-link); }

.site-header { background: var(--theme-header-bg); }

.site-footer { background: var(--theme-footer-bg); }

.btn, .nav-cta, .hero .btn {
  background: var(--theme-primary);
  border-color: var(--theme-primary);
}

.btn:hover, .nav-cta:hover { background: var(--theme-primary-dark); }

.nav-inner, .foot-inner, main.container { max-width: {$maxw}; margin: 0 auto; }

.accent, .section-title { color: var(--theme-primary); }

.brand-tile span, .prod-card h3 { color: var(--theme-text); }

{$sidebar_css}

/* End auto-generated theme CSS */";

    if (!is_dir(dirname(THEME_CSS))) mkdir(dirname(THEME_CSS), 0755, true);
    file_put_contents(THEME_CSS, $css, LOCK_EX);
    return THEME_CSS;
}

function adjust_brightness($hex, $percent) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = max(0, min(255, hexdec(substr($hex,0,2)) + $percent));
    $g = max(0, min(255, hexdec(substr($hex,2,2)) + $percent));
    $b = max(0, min(255, hexdec(substr($hex,4,2)) + $percent));
    return '#' . dechex($r) . dechex($g) . dechex($b);
}

$theme = load_theme();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['csrf']) && !csrf_check($_POST['csrf'])) {
        $errors[] = 'Invalid security token. Please try again.';
    }

    if (isset($_POST['save_theme']) && empty($errors)) {
        $new_theme = [];
        $color_fields = ['primary_color','secondary_color','background','header_bg','footer_bg','text_color','link_color'];
        foreach ($color_fields as $cf) {
            $val = trim($_POST[$cf] ?? $default_theme[$cf]);
            if (!preg_match('/^#([A-Fa-f0-9]{3,8}|rgba?\([^)]+\))$/', $val)) {
                $val = $default_theme[$cf];
            }
            $new_theme[$cf] = $val;
        }
        $fonts = ['Inter','Cairo','Tajawal','Open Sans'];
        $new_theme['font_family'] = in_array($_POST['font_family'] ?? '', $fonts) ? $_POST['font_family'] : 'Inter';
        $new_theme['layout'] = in_array($_POST['layout'] ?? '', ['boxed','full-width']) ? $_POST['layout'] : 'full-width';
        $new_theme['sidebar_position'] = in_array($_POST['sidebar_position'] ?? '', ['left','right','none']) ? $_POST['sidebar_position'] : 'right';

        save_theme($new_theme);
        generate_theme_css($new_theme);
        $theme = $new_theme;
        $msg = 'Theme saved and CSS generated successfully!';
    }

    if (isset($_POST['reset_theme']) && empty($errors)) {
        $theme = $default_theme;
        save_theme($theme);
        generate_theme_css($theme);
        $msg = 'Theme reset to defaults.';
    }

    if (isset($_POST['generate_css']) && empty($errors)) {
        generate_theme_css($theme);
        $msg = 'CSS file regenerated.';
    }
}

$fonts_available = ['Inter','Cairo','Tajawal','Open Sans'];
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Theme Builder — 7 Boys® Admin</title>
<style>
.theme-builder{max-width:1400px;margin:0 auto;padding:28px 20px 60px}
.theme-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.theme-head h1{font-family:var(--display);font-size:28px;font-weight:800;letter-spacing:-.02em}
.theme-head p{color:var(--muted);font-size:14px}
.theme-grid{display:grid;grid-template-columns:420px 1fr;gap:24px}
@media(max-width:1000px){.theme-grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:20px;box-shadow:var(--shadow-sm);margin-bottom:20px}
.panel h2{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.color-row{display:grid;grid-template-columns:36px 1fr auto;align-items:center;gap:10px;margin-bottom:12px}
.color-row label{font-size:13px;font-weight:600;color:var(--ink)}
.color-row input[type=color]{width:36px;height:36px;border:1px solid var(--line);border-radius:8px;cursor:pointer;padding:2px;background:#fff}
.color-row input[type=text]{padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;font-family:monospace;background:#fff;width:110px}
.form-row{margin-bottom:12px}
.form-row label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px}
.form-row select,.form-row input[type=text]{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff}
.btn-theme{background:var(--green);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px}
.btn-theme:hover{opacity:.9}
.btn-theme.secondary{background:var(--card);color:var(--green);border:1px solid var(--green)}
.btn-theme.danger{background:#b91c1c}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.preview-frame{width:100%;height:600px;border:1px solid var(--card-line);border-radius:12px;background:#fff;overflow:hidden}
.preview-bar{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg-soft);border:1px solid var(--card-line);border-radius:12px 12px 0 0;border-bottom:none}
.preview-bar .dot{width:10px;height:10px;border-radius:50%}
.preview-bar .dot.r{background:#ff5f57}.preview-bar .dot.y{background:#febc2e}.preview-bar .dot.g{background:#28c840}
.preview-bar .url{flex:1;background:#fff;border:1px solid var(--line);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--muted);font-family:monospace}
.section-title-sm{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:16px 0 8px}
.toggle-group{display:flex;gap:8px}
.toggle-group button{flex:1;padding:10px;border:1.5px solid var(--line);background:var(--card);border-radius:10px;cursor:pointer;font-weight:600;font-size:13px}
.toggle-group button.active{border-color:var(--green);background:var(--green-soft);color:var(--green)}
.toast{position:fixed;top:20px;right:20px;z-index:999;padding:12px 20px;border-radius:10px;font-weight:600;font-size:14px;box-shadow:0 10px 40px rgba(0,0,0,.15)}
.toast.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.toast.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
</style>
</head>
<body style="background:var(--bg)">
<div class="theme-builder">
    <a href="/admin/" style="display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--green);margin-bottom:14px">← Back to Admin</a>
    <div class="theme-head">
        <div>
            <h1>🎨 Theme Builder</h1>
            <p>Customize colors, fonts, and layout — live preview</p>
        </div>
    </div>

    <?php if ($msg): ?><div class="msg" style="margin:16px 0;padding:12px 16px;background:#dcfce7;color:#166534;border-radius:10px;font-weight:600;"><?= esc($msg) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="msg" style="margin:8px 0;padding:12px 16px;background:#fee2e2;color:#991b1b;border-radius:10px;font-weight:600;"><?= esc($e) ?></div><?php endif; ?>

    <div class="theme-grid">
        <div>
            <form method="POST" id="themeForm">
                <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">

                <div class="panel">
                    <h2>🎨 Colors</h2>
                    <?php
                    $color_labels = [
                        'primary_color' => 'Primary Color',
                        'secondary_color' => 'Secondary Color',
                        'background' => 'Background',
                        'header_bg' => 'Header Background',
                        'footer_bg' => 'Footer Background',
                        'text_color' => 'Text Color',
                        'link_color' => 'Link Color',
                    ];
                    foreach ($color_labels as $field => $label):
                        $val = $theme[$field];
                        $hex_val = strpos($val, '#') === 0 ? $val : '#000000';
                    ?>
                    <div class="color-row">
                        <label for="<?= $field ?>"><?= $label ?></label>
                        <input type="color" id="<?= $field ?>" value="<?= esc($hex_val) ?>" data-target="<?= $field ?>">
                        <input type="text" name="<?= $field ?>" id="txt_<?= $field ?>" value="<?= esc($val) ?>" maxlength="30" onchange="document.getElementById('<?= $field ?>').value=this.value">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <h2>🔤 Typography</h2>
                    <div class="form-row">
                        <label for="font_family">Font Family</label>
                        <select name="font_family" id="font_family">
                            <?php foreach ($fonts_available as $f): ?>
                            <option value="<?= $f ?>" <?= $theme['font_family']===$f?'selected':'' ?>><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="panel">
                    <h2>📐 Layout</h2>
                    <div class="form-row">
                        <label>Page Width</label>
                        <div class="toggle-group">
                            <button type="button" class="layout-btn <?= $theme['layout']==='full-width'?'active':'' ?>" data-target="layout" data-value="full-width">Full Width</button>
                            <button type="button" class="layout-btn <?= $theme['layout']==='boxed'?'active':'' ?>" data-target="layout" data-value="boxed">Boxed</button>
                        </div>
                        <input type="hidden" name="layout" id="layout_input" value="<?= esc($theme['layout']) ?>">
                    </div>
                    <div class="form-row" style="margin-top:14px">
                        <label>Sidebar Position</label>
                        <div class="toggle-group">
                            <button type="button" class="sidebar-btn <?= $theme['sidebar_position']==='none'?'active':'' ?>" data-target="sidebar" data-value="none">None</button>
                            <button type="button" class="sidebar-btn <?= $theme['sidebar_position']==='left'?'active':'' ?>" data-target="sidebar" data-value="left">Left</button>
                            <button type="button" class="sidebar-btn <?= $theme['sidebar_position']==='right'?'active':'' ?>" data-target="sidebar" data-value="right">Right</button>
                        </div>
                        <input type="hidden" name="sidebar_position" id="sidebar_input" value="<?= esc($theme['sidebar_position']) ?>">
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" name="save_theme" class="btn-theme">💾 Save Theme</button>
                    <button type="submit" name="generate_css" class="btn-theme secondary">🔄 Regenerate CSS</button>
                    <button type="submit" name="reset_theme" class="btn-theme danger" onclick="return confirm('Reset theme to defaults? This cannot be undone.')">↩️ Reset</button>
                </div>
            </form>
        </div>

        <div>
            <div class="panel" style="margin-bottom:0">
                <h2>👁️ Live Preview</h2>
                <div class="preview-bar">
                    <span class="dot r"></span><span class="dot y"></span><span class="dot g"></span>
                    <span class="url">https://7boysjo.com/</span>
                </div>
                <iframe class="preview-frame" id="previewFrame" src="/index.php?theme_preview=1" sandbox="allow-same-origin allow-scripts"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Sync color pickers with text inputs
document.querySelectorAll('input[type=color]').forEach(function(picker) {
    picker.addEventListener('input', function() {
        var target = this.dataset.target;
        document.getElementById('txt_' + target).value = this.value;
        updatePreview();
    });
});

// Sync text inputs with color pickers
document.querySelectorAll('input[id^=txt_]').forEach(function(txt) {
    txt.addEventListener('input', function() {
        var id = this.id.replace('txt_', '');
        var picker = document.getElementById(id);
        if (picker) picker.value = this.value;
        updatePreview();
    });
});

// Toggle groups (layout, sidebar)
document.querySelectorAll('.layout-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.layout-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('layout_input').value = btn.dataset.value;
        updatePreview();
    });
});

document.querySelectorAll('.sidebar-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.sidebar-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('sidebar_input').value = btn.dataset.value;
        updatePreview();
    });
});

// Font change triggers preview update
document.getElementById('font_family').addEventListener('change', updatePreview);

var previewTimeout;
function updatePreview() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(function() {
        var iframe = document.getElementById('previewFrame');
        if (iframe && iframe.contentWindow) {
            try {
                iframe.contentWindow.postMessage({type: 'theme-update', theme: collectTheme()}, '*');
            } catch(e) { /* cross-origin fallback: reload */ }
        }
    }, 300);
}

function collectTheme() {
    var t = {};
    document.querySelectorAll('input[type=color]').forEach(function(p) {
        t[p.dataset.target] = document.getElementById('txt_' + p.dataset.target).value;
    });
    t.font_family = document.getElementById('font_family').value;
    t.layout = document.getElementById('layout_input').value;
    t.sidebar_position = document.getElementById('sidebar_input').value;
    return t;
}
</script>
</body>
</html>
