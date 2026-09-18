<?php
/**
 * _layout.php — Reusable Admin Layout for 7 Boys® Premium Food Trading
 * Includes: Modern dark sidebar, top header, RTL support, Chart.js
 */

// Security: only include-able
if (!defined('ADMIN_AREA')) { define('ADMIN_AREA', true); }

if (!function_exists('is_logged_in') || !is_logged_in()) {
  if (php_sapi_name() !== 'cli') {
    $login = (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false)
      ? str_repeat('/', substr_count(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') - 1) . '/admin/login.php'
      : 'login.php';
  }
}

$G_active_action = $G_active_action ?? 'dashboard';
$G_page_title = $G_page_title ?? 'Dashboard';
$G_admin_user = function_exists('current_admin') ? (string)current_admin() : 'Admin';
$G_role = $G_role ?? 'admin';
$G_sidebar_items = $G_sidebar_items ?? [];
$G_extra_head = $G_extra_head ?? '';
$G_extra_scripts = $G_extra_scripts ?? '';

if (empty($G_sidebar_items)) {
  $G_sidebar_items = [
    ['key' => 'dashboard',  'icon' => 'fas fa-th-large',        'label' => 'Dashboard',     'url' => 'dashboard.php'],
    ['key' => 'products',   'icon' => 'fas fa-box-open',        'label' => 'Products',      'url' => 'index.php?action=products'],
    ['key' => 'brands',     'icon' => 'fas fa-tags',            'label' => 'Brands',        'url' => 'index.php?action=brands'],
    ['key' => 'cats',       'icon' => 'fas fa-folder-tree',     'label' => 'Categories',    'url' => 'index.php?action=cats'],
    ['key' => 'quotes',     'icon' => 'fas fa-file-invoice',    'label' => 'Quotes',        'url' => 'index.php?action=quotes', 'badge_var' => 'quotes_data'],
    ['key' => 'messages',   'icon' => 'fas fa-envelope',       'label' => 'Messages',      'url' => 'index.php?action=messages', 'badge_var' => 'msgs'],
    ['key' => 'visitors',   'icon' => 'fas fa-users',           'label' => 'Customers',     'url' => 'index.php?action=visitors'],
    ['key' => 'employees',  'icon' => 'fas fa-user-tie',        'label' => 'Employees',     'url' => 'index.php?action=employees'],
    ['key' => 'catalog',    'icon' => 'fas fa-book-open',       'label' => 'Catalog',       'url' => 'catalog.php'],
    ['key' => 'home',       'icon' => 'fas fa-home',            'label' => 'Homepage',      'url' => 'index.php?action=home'],
    ['key' => 'media',      'icon' => 'fas fa-photo-video',     'label' => 'Media',         'url' => 'index.php?action=media'],
    ['key' => 'options',    'icon' => 'fas fa-sliders-h',       'label' => 'Site Options',  'url' => 'index.php?action=options'],
    ['key' => 'notify',     'icon' => 'fas fa-bell',            'label' => 'Notifications', 'url' => 'notifications.php'],
    ['key' => 'users',      'icon' => 'fas fa-user-shield',     'label' => 'Users',         'url' => 'index.php?action=users'],
    ['key' => 'backup',     'icon' => 'fas fa-database',        'label' => 'Backup',        'url' => 'index.php?action=backup'],
    ['key' => 'publish',    'icon' => 'fas fa-rocket',          'label' => 'Publish',       'url' => 'index.php?action=publish'],
    ['key' => 'settings',   'icon' => 'fas fa-cog',             'label' => 'Settings',      'url' => 'index.php?action=settings'],
    ['key' => 'audit',      'icon' => 'fas fa-scroll',          'label' => 'Audit Log',     'url' => 'index.php?action=audit'],
    ['key' => 'health',     'icon' => 'fas fa-heartbeat',       'label' => 'Health',        'url' => 'index.php?action=health'],
  ];
}

function layout_render_sidebar($items, $active, $collapsed = false) {
  $logo_src = '/assets/img/logo-header.png';
  $aside_style = $collapsed ? 'style="width:70px;"' : '';
  echo '<aside class="admin-sidebar" id="adminSidebar"' . $aside_style . '>';
  echo '<div class="sidebar-brand">';
    echo '<img src="' . esc($logo_src) . '" alt="7 Boys" class="sidebar-logo" onerror="this.style.display=\'none\'">';
    echo '<span class="sidebar-brand-text">7 Boys<span class="reg">&reg;</span></span>';
  echo '</div>';
  echo '<nav class="sidebar-nav">';
  foreach ($items as $it) {
    $key = $it['key'];
    $cls = ($active === $key) ? ' active' : '';
    $badge = '';
    if (!empty($it['badge_var']) && !empty($GLOBALS[$it['badge_var']])) {
      $var = $GLOBALS[$it['badge_var']];
      $cnt = 0;
      if ($key === 'messages') {
        $cnt = count(array_filter($var, fn($m) => (($m['read'] ?? false) !== true)));
      } else {
        $cnt = is_array($var) ? count($var) : 0;
      }
      if ($cnt > 0) $badge = '<span class="sidebar-badge">' . $cnt . '</span>';
    }
    echo '<a href="' . esc($it['url']) . '" class="sidebar-link' . $cls . '">';
      echo '<i class="' . esc($it['icon']) . ' sidebar-icon"></i>';
      echo '<span class="sidebar-label">' . esc($it['label']) . '</span>';
      echo $badge;
    echo '</a>';
  }
  echo '</nav>';
  echo '<div class="sidebar-footer">';
    echo '<a href="login.php?logout=1" class="sidebar-link logout"><i class="fas fa-sign-out-alt sidebar-icon"></i><span class="sidebar-label">Logout</span></a>';
  echo '</div>';
  echo '</aside>';
}

function layout_render_header($title, $admin, $role = 'admin') {
  echo '<header class="admin-header" id="adminHeader">';
  echo '<button class="header-toggle" id="sidebarToggle" title="Toggle sidebar"><i class="fas fa-bars"></i></button>';
  echo '<div class="header-search">';
    echo '<form method="get" action="index.php" class="search-form">';
      echo '<input type="hidden" name="action" value="search">';
      echo '<i class="fas fa-search search-icon"></i>';
      echo '<input type="text" name="q" placeholder="Search products, brands, categories…" value="' . esc($_GET['q'] ?? '') . '" class="search-input">';
    echo '</form>';
  echo '</div>';
  echo '<div class="header-right">';
    echo '<button class="header-icon-btn" id="themeToggle" title="Toggle theme"><i class="fas fa-moon"></i></button>';
    echo '<button class="header-icon-btn notification-btn" id="notifToggle" title="Notifications"><i class="fas fa-bell"></i><span class="notif-dot"></span></button>';
    echo '<div class="user-menu" id="userMenu">';
      echo '<button class="user-menu-trigger" id="userMenuBtn">';
        echo '<div class="user-avatar">' . esc(strtoupper(substr((string)$admin, 0, 1))) . '</div>';
        echo '<span class="user-name">' . esc($admin) . '</span>';
        echo '<span class="user-role">' . esc(ucfirst($role)) . '</span>';
        echo '<i class="fas fa-chevron-down"></i>';
      echo '</button>';
      echo '<div class="user-dropdown" id="userDropdown">';
        echo '<a href="index.php?action=settings"><i class="fas fa-cog"></i> Settings</a>';
        echo '<a href="index.php?action=users"><i class="fas fa-user-shield"></i> Users</a>';
        echo '<a href="index.php?action=backup"><i class="fas fa-database"></i> Backup</a>';
        echo '<div class="dropdown-divider"></div>';
        echo '<a href="login.php?logout=1" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>';
      echo '</div>';
    echo '</div>';
  echo '</div>';
  echo '</header>';
}

function layout_start($title = 'Dashboard', $admin = 'Admin', $role = 'admin') {
  $GLOBALS['_page_title'] = $title;
  $GLOBALS['_page_admin'] = $admin;
  $GLOBALS['_page_role'] = $role;
  ob_start();
}

function layout_end() {
  $body = ob_get_clean();
  $title = $GLOBALS['_page_title'] ?? 'Dashboard';
  $admin = $GLOBALS['_page_admin'] ?? 'Admin';
  $role = $GLOBALS['_page_role'] ?? 'admin';
  $extra_head = $GLOBALS['G_extra_head'] ?? '';
  $extra_scripts = $GLOBALS['G_extra_scripts'] ?? '';
  $active = $GLOBALS['G_active_action'] ?? 'dashboard';
  $sidebar_items = $GLOBALS['G_sidebar_items'] ?? [];

  echo '<!DOCTYPE html>';
  echo '<html lang="en" dir="ltr">';
  echo '<head>';
  echo '<meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>' . esc($title) . ' &mdash; 7 Boys&reg; Admin</title>';

  // Fonts
  echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
  echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
  echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">';

  // Font Awesome
  echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">';

  // Chart.js
  echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>';

  // Styles
  echo layout_css();

  echo $extra_head;
  echo '</head>';
  echo '<body>';

  // Sidebar
  layout_render_sidebar($sidebar_items, $active, false);

  // Main wrapper
  echo '<div class="admin-main" id="adminMain">';

  // Header
  layout_render_header($title, $admin, $role);

  // Content
  echo '<main class="admin-content" id="adminContent">';
  echo $body;
  echo '</main>';

  echo '</div>'; // .admin-main

  // Mobile overlay
  echo '<div class="sidebar-overlay" id="sidebarOverlay"></div>';

  // JS
  echo layout_js();
  echo $extra_scripts;

  echo '</body>';
  echo '</html>';
}

function layout_css() {
  return '<style>
:root {
  --brand: #2d6a4f;
  --brand-dark: #1b4332;
  --brand-light: #40916c;
  --gold: #c9a23f;
  --gold-light: #e8c96a;
  --gold-soft: rgba(201, 162, 63, 0.12);
  --bg: #f4f6f5;
  --surface: #ffffff;
  --text: #1a1a2e;
  --text-muted: #6b7280;
  --border: #e5e7eb;
  --shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.03);
  --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
  --shadow-lg: 0 10px 30px rgba(0,0,0,0.12);
  --radius: 12px;
  --radius-sm: 8px;
  --sidebar-w: 240px;
  --sidebar-collapsed: 70px;
  --header-h: 60px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body {
  font-family: "Inter", "Cairo", system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.6;
  overflow-x: hidden;
}
a { color: var(--brand); text-decoration: none; }
img { max-width: 100%; display: block; }
button { cursor: pointer; font-family: inherit; border: none; background: none; }
input, select, textarea { font-family: inherit; font-size: 14px; }

/* Sidebar */
.admin-sidebar {
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  width: var(--sidebar-w);
  background: linear-gradient(180deg, #0a2e1f 0%, #051f15 100%);
  color: #e0e0e0;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  transition: width 0.25s ease, transform 0.25s ease;
  overflow: hidden;
  border-right: 1px solid rgba(201, 162, 63, 0.15);
}
.admin-sidebar.collapsed { width: var(--sidebar-collapsed); }
.admin-sidebar.collapsed .sidebar-brand-text,
.admin-sidebar.collapsed .sidebar-label,
.admin-sidebar.collapsed .sidebar-badge { display: none; }
.admin-sidebar.collapsed .sidebar-link { justify-content: center; padding: 14px 0; }
.sidebar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 18px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
  min-height: var(--header-h);
  flex-shrink: 0;
}
.sidebar-logo { width: 34px; height: 34px; object-fit: contain; background: #fff; border-radius: 8px; padding: 3px; flex-shrink: 0; }
.sidebar-brand-text { font-family: Georgia, serif; font-size: 17px; font-weight: 600; color: #fff; white-space: nowrap; }
.sidebar-brand-text .reg { font-size: 10px; vertical-align: super; color: var(--gold); }
.sidebar-nav { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 10px 8px; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  border-radius: var(--radius-sm);
  color: #c8d6cd;
  font-size: 13.5px;
  font-weight: 500;
  transition: background 0.15s, color 0.15s;
  margin-bottom: 2px;
  white-space: nowrap;
  position: relative;
}
.sidebar-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
.sidebar-link.active {
  background: rgba(201, 162, 63, 0.14);
  color: var(--gold-light);
  border-left: 3px solid var(--gold);
  padding-left: 11px;
}
.sidebar-icon { font-size: 15px; width: 20px; text-align: center; flex-shrink: 0; }
.sidebar-label { flex: 1; }
.sidebar-badge {
  background: var(--gold);
  color: #0a2e1f;
  font-size: 10px;
  font-weight: 700;
  border-radius: 999px;
  padding: 2px 7px;
  min-width: 20px;
  text-align: center;
}
.sidebar-footer { padding: 10px 8px; border-top: 1px solid rgba(255,255,255,0.06); flex-shrink: 0; }
.sidebar-link.logout { color: #f3b8b8; }
.sidebar-link.logout:hover { background: rgba(200, 60, 60, 0.15); }

/* Main */
.admin-main {
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  transition: margin-left 0.25s ease;
}
.admin-sidebar.collapsed ~ .admin-main { margin-left: var(--sidebar-collapsed); }

/* Header */
.admin-header {
  height: var(--header-h);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 0 20px;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.header-toggle {
  width: 36px; height: 36px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  font-size: 16px;
  transition: background 0.15s;
}
.header-toggle:hover { background: var(--bg); color: var(--brand); }
.header-search { flex: 1; max-width: 420px; }
.search-form { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 12px; color: var(--text-muted); font-size: 13px; pointer-events: none; }
.search-input {
  width: 100%;
  padding: 8px 12px 8px 34px;
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg);
  font-size: 13px;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus { outline: none; border-color: var(--brand-light); box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1); }
.header-right { display: flex; align-items: center; gap: 10px; }
.header-icon-btn {
  width: 36px; height: 36px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  position: relative;
  transition: background 0.15s;
}
.header-icon-btn:hover { background: var(--bg); color: var(--brand); }
.notif-dot {
  position: absolute; top: 8px; right: 8px;
  width: 8px; height: 8px;
  background: #ef4444;
  border-radius: 50%;
  border: 2px solid var(--surface);
}
.user-menu { position: relative; }
.user-menu-trigger {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 12px 6px 6px;
  border-radius: var(--radius-sm);
  transition: background 0.15s;
}
.user-menu-trigger:hover { background: var(--bg); }
.user-avatar {
  width: 32px; height: 32px;
  background: var(--brand);
  color: #fff;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700;
  font-size: 13px;
  flex-shrink: 0;
}
.user-name { font-size: 13px; font-weight: 600; color: var(--text); }
.user-role { font-size: 11px; color: var(--text-muted); margin-left: 2px; }
.user-menu-trigger .fa-chevron-down { font-size: 10px; color: var(--text-muted); }
.user-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 180px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
  padding: 6px 0;
  display: none;
  z-index: 200;
}
.user-dropdown.show { display: block; }
.user-dropdown a {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 16px;
  font-size: 13px;
  color: var(--text);
  transition: background 0.1s;
}
.user-dropdown a:hover { background: var(--bg); }
.user-dropdown .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }
.user-dropdown .logout-link { color: #dc2626; }

/* Content */
.admin-content { padding: 24px; max-width: 1400px; margin: 0 auto; }

/* Stat Cards */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: var(--shadow);
  transition: transform 0.15s, box-shadow 0.15s;
  text-decoration: none;
  color: inherit;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon {
  width: 52px; height: 52px;
  border-radius: var(--radius-sm);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.stat-icon.green { background: rgba(45, 106, 79, 0.1); color: var(--brand); }
.stat-icon.gold { background: var(--gold-soft); color: var(--gold); }
.stat-icon.blue { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
.stat-icon.red { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.stat-icon.purple { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
.stat-info { min-width: 0; }
.stat-value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1.2; }
.stat-label { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.stat-trend {
  font-size: 12px;
  margin-top: 4px;
  display: flex; align-items: center; gap: 4px;
  font-weight: 600;
}
.stat-trend.up { color: #16a34a; }
.stat-trend.down { color: #dc2626; }

/* Cards */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px;
  box-shadow: var(--shadow);
  margin-bottom: 20px;
}
.card-header {
  display: flex; justify-content: space-between; align-items: baseline; gap: 12px;
  margin-bottom: 16px;
}
.card-title { font-size: 16px; font-weight: 700; color: var(--text); }
.card-subtitle { font-size: 12.5px; color: var(--text-muted); }
.card-link { font-size: 12.5px; color: var(--brand); font-weight: 600; white-space: nowrap; }
.card-link:hover { color: var(--brand-dark); }

/* Grid layouts */
.grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }

/* Chart container */
.chart-wrap { position: relative; width: 100%; height: 280px; }
.chart-wrap canvas { width: 100% !important; height: 100% !important; }

/* Tables */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 10px 14px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--border); }
.data-table th { font-weight: 600; color: var(--text-muted); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; background: #f9fafb; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #f9fafb; }
.badge-status {
  display: inline-block; padding: 3px 10px; border-radius: 999px;
  font-size: 11px; font-weight: 700;
}
.badge-status.new { background: var(--gold-soft); color: #8a6d2f; }
.badge-status.viewed { background: rgba(59,130,246,0.1); color: #2563eb; }
.badge-status.done { background: rgba(22,163,74,0.1); color: #16a34a; }
.badge-status.pending { background: rgba(201,162,63,0.15); color: #b8860b; }
.badge-status.low { background: rgba(239,68,68,0.1); color: #dc2626; }

/* Progress bar */
.progress-bar { height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--brand), var(--brand-light)); }

/* Quick actions */
.quick-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.quick-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 9px 16px; border-radius: var(--radius-sm);
  font-size: 13px; font-weight: 600;
  text-decoration: none;
  transition: transform 0.1s, box-shadow 0.1s;
}
.quick-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
.quick-btn.primary { background: var(--brand); color: #fff; }
.quick-btn.primary:hover { background: var(--brand-dark); }
.quick-btn.gold { background: var(--gold); color: #0a2e1f; }
.quick-btn.gold:hover { background: #dcb84f; }
.quick-btn.outline { background: var(--surface); color: var(--brand); border: 1.5px solid var(--brand); }
.quick-btn.outline:hover { background: rgba(45,106,79,0.05); }

/* Low stock / alerts list */
.alert-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border);
  font-size: 13px;
}
.alert-item:last-child { border-bottom: none; }
.alert-item .dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #ef4444; flex-shrink: 0;
}
.alert-item .dot.warn { background: var(--gold); }
.alert-item .dot.ok { background: #16a34a; }
.alert-item .lbl { flex: 1; }
.alert-item .val { font-weight: 700; color: var(--text); }

/* Top products */
.top-prod-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border);
  text-decoration: none; color: inherit;
}
.top-prod-item:last-child { border-bottom: none; }
.top-prod-item:hover .tp-name { color: var(--brand); }
.tp-rank { width: 26px; height: 26px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.tp-rank.gold { background: var(--gold); color: #0a2e1f; }
.tp-info { flex: 1; min-width: 0; }
.tp-name { font-weight: 600; font-size: 13.5px; transition: color 0.15s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tp-meta { font-size: 11.5px; color: var(--text-muted); }
.tp-stat { font-weight: 700; font-size: 14px; color: var(--brand); }

/* Welcome banner */
.welcome-banner {
  background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
  border-radius: var(--radius);
  padding: 22px 24px;
  color: #fff;
  margin-bottom: 24px;
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 12px;
}
.welcome-banner h2 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.welcome-banner p { font-size: 13.5px; opacity: 0.85; }
.welcome-meta { display: flex; gap: 12px; align-items: center; }
.welcome-pill {
  background: rgba(201,162,63,0.2); border: 1px solid rgba(201,162,63,0.4);
  border-radius: 999px; padding: 6px 14px;
  font-size: 12px; font-weight: 600; color: var(--gold-light);
}

/* Sidebar overlay for mobile */
.sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  z-index: 999;
}
.sidebar-overlay.show { display: block; }

/* Responsive */
@media (max-width: 1024px) {
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 860px) {
  .admin-sidebar {
    transform: translateX(-100%);
    width: var(--sidebar-w) !important;
  }
  .admin-sidebar.open { transform: translateX(0); box-shadow: var(--shadow-lg); }
  .admin-sidebar.collapsed { transform: translateX(-100%); }
  .admin-main { margin-left: 0 !important; }
  .admin-content { padding: 16px; }
  .stat-grid { grid-template-columns: repeat(2, 1fr); }
  .grid-3 { grid-template-columns: 1fr; }
  .welcome-banner { padding: 18px; }
  .welcome-banner h2 { font-size: 17px; }
  .user-name, .user-role { display: none; }
}
@media (max-width: 500px) {
  .stat-grid { grid-template-columns: 1fr; }
  .header-search { max-width: 200px; }
}

/* Dark mode override (optional toggle) */
body.dark {
  --bg: #111827;
  --surface: #1f2937;
  --text: #f3f4f6;
  --text-muted: #9ca3af;
  --border: #374151;
}
body.dark .admin-header { background: #1f2937; border-color: #374151; }
body.dark .search-input { background: #111827; border-color: #374151; color: #f3f4f6; }
body.dark .data-table th { background: #111827; }
body.dark .data-table tr:hover td { background: #111827; }

/* Animations */
@keyframes fadeInUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.anim-in { animation: fadeInUp 0.3s ease forwards; }
.stat-card:nth-child(1) { animation-delay: 0s; }
.stat-card:nth-child(2) { animation-delay: 0.05s; }
.stat-card:nth-child(3) { animation-delay: 0.1s; }
.stat-card:nth-child(4) { animation-delay: 0.15s; }
.stat-card:nth-child(5) { animation-delay: 0.2s; }
.stat-card:nth-child(6) { animation-delay: 0.25s; }
</style>';
}

function layout_js() {
  return '<script>
(function() {
  // Sidebar toggle
  var sidebar = document.getElementById("adminSidebar");
  var main = document.getElementById("adminMain");
  var toggle = document.getElementById("sidebarToggle");
  var overlay = document.getElementById("sidebarOverlay");
  var isMobile = window.innerWidth <= 860;

  function toggleSidebar() {
    if (isMobile) {
      sidebar.classList.toggle("open");
      overlay.classList.toggle("show");
    } else {
      sidebar.classList.toggle("collapsed");
      localStorage.setItem("sidebarCollapsed", sidebar.classList.contains("collapsed") ? "1" : "0");
    }
  }
  if (toggle) toggle.addEventListener("click", toggleSidebar);
  if (overlay) overlay.addEventListener("click", function() {
    sidebar.classList.remove("open");
    overlay.classList.remove("show");
  });

  // Restore sidebar state
  if (!isMobile && localStorage.getItem("sidebarCollapsed") === "1") {
    sidebar.classList.add("collapsed");
  }

  // User dropdown
  var umBtn = document.getElementById("userMenuBtn");
  var umDrop = document.getElementById("userDropdown");
  if (umBtn && umDrop) {
    umBtn.addEventListener("click", function(e) {
      e.stopPropagation();
      umDrop.classList.toggle("show");
    });
    document.addEventListener("click", function() {
      umDrop.classList.remove("show");
    });
    umDrop.addEventListener("click", function(e) { e.stopPropagation(); });
  }

  // Theme toggle
  var themeBtn = document.getElementById("themeToggle");
  if (themeBtn) {
    themeBtn.addEventListener("click", function() {
      document.body.classList.toggle("dark");
      var isDark = document.body.classList.contains("dark");
      localStorage.setItem("darkMode", isDark ? "1" : "0");
      themeBtn.querySelector("i").className = isDark ? "fas fa-sun" : "fas fa-moon";
    });
    if (localStorage.getItem("darkMode") === "1") {
      document.body.classList.add("dark");
      themeBtn.querySelector("i").className = "fas fa-sun";
    }
  }

  // Responsive check
  window.addEventListener("resize", function() {
    var wasMobile = isMobile;
    isMobile = window.innerWidth <= 860;
    if (wasMobile !== isMobile) {
      sidebar.classList.remove("open", "collapsed");
      overlay.classList.remove("show");
    }
  });
})();
</script>';
}
