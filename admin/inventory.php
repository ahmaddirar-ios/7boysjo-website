<?php require_once 'config.php'; ?>
<?php
if (!is_logged_in()) { header('Location: login.php'); exit; }

$products = normalize_products_full(load_products());
$brands = load_brands();
$cats = load_cats();

// Handle AJAX stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_stock'])) {
  header('Content-Type: application/json');
  $i = (int)$_POST['idx'];
  $val = trim($_POST['val']);
  $products[$i]['stock'] = $val;
  save_products($products);
  // Log stock change
  $log_file = SITE_DIR . '/admin/data/stock_log.json';
  $log = load_json($log_file);
  $log[] = ['at' => date('Y-m-d H:i:s'), 'user' => current_admin(), 'product' => $products[$i]['name'], 'idx' => $i, 'old' => $_POST['old'] ?? '', 'new' => $val];
  save_json($log_file, $log);
  echo json_encode(['ok' => true]);
  exit;
}

// Handle bulk stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_stock'])) {
  $ids = array_map('intval', explode(',', $_POST['bulk_ids']));
  $val = trim($_POST['bulk_val']);
  $log_file = SITE_DIR . '/admin/data/stock_log.json';
  $log = load_json($log_file);
  foreach ($ids as $i) {
    if (isset($products[$i])) {
      $old = $products[$i]['stock'];
      $products[$i]['stock'] = $val;
      $log[] = ['at' => date('Y-m-d H:i:s'), 'user' => current_admin(), 'product' => $products[$i]['name'], 'idx' => $i, 'old' => $old, 'new' => $val, 'bulk' => true];
    }
  }
  save_products($products);
  save_json($log_file, $log);
  header('Location: inventory.php?msg=' . urlencode('Bulk stock updated'));
  exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $header = ['name', 'brand', 'cat', 'origin', 'price', 'stock', 'expiry_date', 'offer_price', 'featured', 'image'];
  $fp = fopen('php://temp', 'r+');
  fputcsv($fp, $header);
  foreach ($products as $p) {
    $row = [];
    foreach ($header as $h) $row[] = $p[$h] ?? '';
    fputcsv($fp, $row);
  }
  rewind($fp);
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="7boys-inventory-' . date('Ymd-His') . '.csv"');
  fpassthru($fp);
  exit;
}

// Handle stock log export
if (isset($_GET['export']) && $_GET['export'] === 'log') {
  $log = load_json(SITE_DIR . '/admin/data/stock_log.json');
  $header = ['at', 'user', 'product', 'idx', 'old', 'new', 'bulk'];
  $fp = fopen('php://temp', 'r+');
  fputcsv($fp, $header);
  foreach ($log as $row) {
    $r = [];
    foreach ($header as $h) $r[] = is_array($row[$h] ?? '') ? json_encode($row[$h]) : ($row[$h] ?? '');
    fputcsv($fp, $r);
  }
  rewind($fp);
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="7boys-stock-log-' . date('Ymd-His') . '.csv"');
  fpassthru($fp);
  exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$fb = trim($_GET['filter_brand'] ?? '');
$fc = trim($_GET['filter_cat'] ?? '');
$fs = trim($_GET['filter_stock'] ?? '');
$fe = trim($_GET['filter_expiry'] ?? '');
$sort = trim($_GET['sort'] ?? '');

$filtered = $products;
if ($q !== '') $filtered = array_filter($filtered, fn($p) => stripos($p['name'] ?? '', $q) !== false || stripos($p['brand'] ?? '', $q) !== false);
if ($fb !== '') $filtered = array_filter($filtered, fn($p) => ($p['brand'] ?? '') === $fb);
if ($fc !== '') $filtered = array_filter($filtered, fn($p) => ($p['cat'] ?? '') === $fc);
if ($fs === 'low') $filtered = array_filter($filtered, fn($p) => intval($p['stock'] ?? 0) < 5 && intval($p['stock'] ?? 0) > 0);
if ($fs === 'medium') $filtered = array_filter($filtered, fn($p) => intval($p['stock'] ?? 0) >= 5 && intval($p['stock'] ?? 0) < 20);
if ($fs === 'out') $filtered = array_filter($filtered, fn($p) => intval($p['stock'] ?? 0) === 0);
if ($fs === 'in') $filtered = array_filter($filtered, fn($p) => intval($p['stock'] ?? 0) >= 20);
if ($fe === 'expired') $filtered = array_filter($filtered, fn($p) => is_expired($p));
if ($fe === 'near') $filtered = array_filter($filtered, fn($p) => is_offer($p));
if ($fe === 'none') $filtered = array_filter($filtered, fn($p) => empty($p['expiry_date']));
$filtered = array_values($filtered);

if ($sort === 'name') usort($filtered, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
elseif ($sort === 'stock') usort($filtered, fn($a, $b) => intval($a['stock'] ?? 0) <=> intval($b['stock'] ?? 0));
elseif ($sort === 'expiry') usort($filtered, fn($a, $b) => strtotime($a['expiry_date'] ?? '2099-01-01') <=> strtotime($b['expiry_date'] ?? '2099-01-01'));
else usort($filtered, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

// Stats
$total_products = count($products);
$low_stock = count(array_filter($products, fn($p) => intval($p['stock'] ?? 0) < 5 && intval($p['stock'] ?? 0) > 0));
$out_of_stock = count(array_filter($products, fn($p) => intval($p['stock'] ?? 0) === 0));
$expired_count = count(array_filter($products, fn($p) => is_expired($p)));
$near_expiry = count(array_filter($products, fn($p) => is_offer($p)));

$msg = '';
if (isset($_GET['msg'])) $msg = $_GET['msg'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory — 7 Boys® Admin</title>
<style>
*{box-sizing:border-box;} body{font-family:system-ui;margin:0;background:#eef1ee;color:#222;}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:220px;background:#053d20;color:#fff;padding:20px 0;overflow-y:auto;}
.sidebar h2{font-family:Georgia,serif;text-align:center;margin:0 0 20px;font-size:20px;}
.sidebar a{display:block;color:#cfe;padding:11px 22px;text-decoration:none;font-size:14px;border-left:3px solid transparent;}
.sidebar a:hover,.sidebar a.active{background:#0a5230;border-left-color:#c9a23f;}
.sidebar .logout{margin-top:20px;color:#c9a23f;}
.menu-btn{display:none;position:fixed;top:12px;left:12px;z-index:200;background:#053d20;color:#fff;border:1px solid #c9a23f;border-radius:8px;padding:9px 13px;font-size:16px;cursor:pointer;}
.mobilebar{display:none;}
@media(max-width:860px){.mobilebar{display:flex;position:fixed;top:0;left:0;right:0;z-index:200;background:#053d20;color:#fff;align-items:center;gap:10px;padding:10px 12px;box-shadow:0 2px 12px rgba(0,0,0,.25);}.mobilebar .menu-btn{display:block;position:static;}.mobilebar b{font-family:Georgia,serif;font-size:16px;font-weight:400;}.sidebar{transform:translateX(-100%);transition:transform .25s;width:250px;z-index:150;padding-top:64px;}.sidebar.open{transform:none;box-shadow:4px 0 30px rgba(0,0,0,.35);}.menu-btn{display:block;}.main{margin-left:0 !important;padding:70px 12px 40px !important;}h1{font-size:22px;}.toolbar{flex-direction:column;align-items:stretch;}.toolbar input[type=text],.toolbar select,.toolbar button,.toolbar a{width:100% !important;max-width:none !important;flex:none !important;box-sizing:border-box;}table{display:block;overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}}
.main{margin-left:220px;padding:30px 40px;}
h1{font-family:Georgia,serif;color:#053d20;}
.msg{background:#e8f5e9;color:#1b5e20;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
.toolbar{display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.toolbar input[type=text]{max-width:240px;}
.toolbar select{max-width:190px;width:auto;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;margin-top:16px;}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:13px;}
th{background:#053d20;color:#fff;}
.brand-head{display:flex;align-items:center;gap:10px;padding:16px 16px 14px;}.brand-head img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:11px;padding:3px;flex:none;}.brand-head span{color:#fff;font-size:19px;font-weight:800;letter-spacing:.01em;}
.stock-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;cursor:pointer;}
.stock-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.stock-yellow{background:#fffbeb;color:#d97706;border:1px solid #fde68a;}
.stock-green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.stock-edit{display:none;min-width:60px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.expired-row{background:#fef2f2 !important;}
.expired-row td{color:#dc2626;}
.near-row{background:#fffbeb !important;}
.expired-badge{display:inline-block;background:#dc2626;color:#fff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.near-badge{display:inline-block;background:#f59e0b;color:#fff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.ok-badge{display:inline-block;background:#16a34a;color:#fff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.noexp-badge{display:inline-block;background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin:24px 0;}
.dash-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.dash-card .num{font-size:2em;font-weight:700;color:#053d20;}
.dash-card .lbl{color:#7a7267;font-size:.85em;margin-top:4px;}
.dash-card.danger .num{color:#dc2626;}
.dash-card.warning .num{color:#d97706;}
.dash-card.success .num{color:#16a34a;}
.bulk-bar{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.prod-img{width:40px;height:40px;object-fit:contain;border:1px solid #eee;border-radius:6px;background:#fff;}
</style>
</head>
<body>
<div class="mobilebar"><button class="menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button><b>7 Boys® Admin</b></div>
<div class="sidebar">
<div class="brand-head"><span>7 Boys®</span></div>
<a href="index.php?action=dashboard">🏠 Dashboard</a>
<a href="index.php?action=brands">Brands</a>
<a href="index.php?action=products">📦 Products</a>
<a href="index.php?action=cats">🗂️ Categories</a>
<a href="inventory.php" class="active">📊 Inventory</a>
<a href="customers.php">👥 Customers</a>
<a href="index.php?action=quotes">🧾 Quotes</a>
<a href="index.php?action=visitors">👥 Clients</a>
<a href="index.php?action=settings">⚙️ Settings</a>
<a href="login.php?logout=1" class="logout">🚪 Logout</a>
</div>
<div class="main">
<h1>📊 Inventory Management</h1>
<?php if($msg): ?><div class="msg"><?= esc($msg) ?></div><?php endif; ?>

<div class="dash-grid">
  <div class="dash-card"><div class="num"><?= $total_products ?></div><div class="lbl">Total Products</div></div>
  <div class="dash-card danger"><div class="num"><?= $out_of_stock ?></div><div class="lbl">Out of Stock</div></div>
  <div class="dash-card warning"><div class="num"><?= $low_stock ?></div><div class="lbl">Low Stock (&lt;5)</div></div>
  <div class="dash-card danger"><div class="num"><?= $expired_count ?></div><div class="lbl">Expired</div></div>
  <div class="dash-card warning"><div class="num"><?= $near_expiry ?></div><div class="lbl">Near Expiry (&lt;30d)</div></div>
</div>

<div class="toolbar">
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
<input type="text" name="q" placeholder="Search products..." value="<?= esc($q) ?>" style="min-width:160px;flex:1">
<select name="filter_brand"><option value="">All brands</option><?php foreach($brands as $b): ?><option <?= $fb===$b['slug']?'selected':'' ?> value="<?= esc($b['slug']) ?>"><?= esc($b['name']) ?></option><?php endforeach; ?></select>
<select name="filter_cat"><option value="">All categories</option><?php foreach($cats as $c): ?><option <?= $fc===$c['slug']?'selected':'' ?> value="<?= esc($c['slug']) ?>"><?= esc($c['name']) ?></option><?php endforeach; ?></select>
<select name="filter_stock"><option value="">Any stock</option><option <?= $fs==='in'?'selected':'' ?> value="in">In Stock (≥20)</option><option <?= $fs==='medium'?'selected':'' ?> value="medium">Low (5-19)</option><option <?= $fs==='low'?'selected':'' ?> value="low">Critical (&lt;5)</option><option <?= $fs==='out'?'selected':'' ?> value="out">Out of Stock</option></select>
<select name="filter_expiry"><option value="">Any expiry</option><option <?= $fe==='expired'?'selected':'' ?> value="expired">Expired</option><option <?= $fe==='near'?'selected':'' ?> value="near">Near Expiry</option><option <?= $fe==='none'?'selected':'' ?> value="none">No Expiry</option></select>
<select name="sort"><option value="">Sort: Default</option><option <?= $sort==='name'?'selected':'' ?> value="name">Name A-Z</option><option <?= $sort==='stock'?'selected':'' ?> value="stock">Stock</option><option <?= $sort==='expiry'?'selected':'' ?> value="expiry">Expiry Date</option></select>
<button type="submit" style="margin-top:0;background:#053d20;color:#fff;border:none;padding:9px 16px;border-radius:8px;cursor:pointer;">Filter</button>
<a href="inventory.php" style="color:#053d20;line-height:38px;">Reset</a>
</form>
<a href="inventory.php?export=csv<?= $q ? '&q='.urlencode($q) : '' ?><?= $fb ? '&filter_brand='.urlencode($fb) : '' ?><?= $fc ? '&filter_cat='.urlencode($fc) : '' ?>" style="background:#053d20;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;">📥 Export CSV</a>
<a href="inventory.php?export=log" style="background:#fff;color:#053d20;border:1px solid #053d20;padding:9px 16px;border-radius:8px;text-decoration:none;">📋 Stock Log</a>
</div>

<div class="bulk-bar">
<span style="font-size:12px;color:#6b7280">Bulk update selected:</span>
<input type="number" id="bulkStockVal" placeholder="New stock value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;min-width:140px">
<button onclick="bulkStockUpdate()" style="background:#053d20;color:#fff;margin:0;padding:7px 14px;border-radius:6px;border:0;cursor:pointer">Update Selected (<span id="invSelCount">0</span>)</button>
<button onclick="clearSel()" style="background:#fff;color:#6b7280;border:1px solid #d1d5db;margin:0;padding:7px 10px;border-radius:6px;cursor:pointer">Clear</button>
</div>

<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="max-height:65vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="invTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1">
<tr>
<th style="padding:8px 12px;width:32px"><input type="checkbox" id="invSelectAll" onchange="selectAllInv(this.checked)"></th>
<th style="padding:8px 12px;width:48px">Image</th>
<th style="padding:8px 12px">Name</th>
<th style="padding:8px 12px">Brand</th>
<th style="padding:8px 12px">Category</th>
<th style="padding:8px 12px">Stock</th>
<th style="padding:8px 12px">Price</th>
<th style="padding:8px 12px">Expiry Date</th>
<th style="padding:8px 12px">Status</th>
</tr>
</thead>
<tbody>
<?php foreach($filtered as $pIdx => $p): 
  $stock = intval($p['stock'] ?? 0);
  $stock_class = $stock === 0 ? 'stock-red' : ($stock < 5 ? 'stock-red' : ($stock < 20 ? 'stock-yellow' : 'stock-green'));
  $row_class = '';
  $status_html = '';
  if (is_expired($p)) { $row_class = 'expired-row'; $status_html = '<span class="expired-badge">Expired</span>'; }
  elseif (is_offer($p)) { $row_class = 'near-row'; $d = (int)ceil((strtotime($p['expiry_date']) - time()) / 86400); $status_html = '<span class="near-badge">'.$d.'d left</span>'; }
  elseif (!empty($p['expiry_date'])) { $status_html = '<span class="ok-badge">Valid</span>'; }
  else { $status_html = '<span class="noexp-badge">N/A</span>'; }
?>
<tr class="<?= $row_class ?>" style="border-bottom:1px solid #f3f4f6">
<td style="padding:6px 12px"><input type="checkbox" class="invCheck" value="<?= $pIdx ?>" onchange="updateInvBulk()"></td>
<td style="padding:6px 12px"><?php if(!empty($p['image']) && file_exists(SITE_DIR.'/assets/img/'.$p['image'])): ?><img src="../assets/img/<?= esc($p['image']) ?>" class="prod-img" onerror="this.style.display='none'"><?php else: ?><div class="prod-img" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af">no img</div><?php endif; ?></td>
<td style="padding:6px 12px"><b style="color:#053d20"><?= esc($p['name']) ?></b></td>
<td style="padding:6px 12px"><span style="background:#f3f4f6;padding:2px 7px;border-radius:999px;font-size:11px"><?= esc($p['brand']?:'—') ?></span></td>
<td style="padding:6px 12px"><span style="background:#eef7f0;color:#053d20;padding:2px 7px;border-radius:999px;font-size:11px"><?= esc($p['cat']??'—') ?></span></td>
<td style="padding:6px 12px">
<span class="stock-badge <?= $stock_class ?>" onclick="editStock(this)" data-idx="<?= $pIdx ?>" data-val="<?= esc($p['stock'] ?? '0') ?>"><?= esc($p['stock'] ?? '0') ?></span>
<input type="number" class="stock-edit" data-idx="<?= $pIdx ?>" onblur="saveStock(this)" onkeydown="if(event.key==='Enter')saveStock(this)">
</td>
<td style="padding:6px 12px;font-weight:600"><?= esc($p['price']?:'—') ?></td>
<td style="padding:6px 12px;font-size:12px"><?= esc($p['expiry_date']?:'—') ?></td>
<td style="padding:6px 12px"><?= $status_html ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<p style="color:#6b7280;font-size:12px;margin-top:8px"><?= count($filtered) ?> products shown</p>
</div>

<script>
function editStock(el) {
  el.style.display = 'none';
  var inp = el.nextElementSibling;
  inp.style.display = 'inline-block';
  inp.value = el.getAttribute('data-val');
  inp.focus();
  inp.select();
}
function saveStock(el) {
  var idx = el.getAttribute('data-idx');
  var val = el.value;
  var old = el.previousElementSibling.getAttribute('data-val');
  el.style.display = 'none';
  var badge = el.previousElementSibling;
  badge.style.display = 'inline-block';
  badge.textContent = val;
  badge.setAttribute('data-val', val);
  // Update class
  var n = parseInt(val) || 0;
  badge.className = 'stock-badge ' + (n === 0 ? 'stock-red' : (n < 5 ? 'stock-red' : (n < 20 ? 'stock-yellow' : 'stock-green')));
  // AJAX save
  var fd = new FormData();
  fd.append('ajax_stock', '1');
  fd.append('idx', idx);
  fd.append('val', val);
  fd.append('old', old);
  fetch('inventory.php', { method: 'POST', body: fd });
}
function selectAllInv(checked) {
  document.querySelectorAll('.invCheck').forEach(function(cb) {
    if (cb.closest('tr').style.display !== 'none') cb.checked = checked;
  });
  document.getElementById('invSelectAll').checked = checked;
  updateInvBulk();
}
function updateInvBulk() {
  var sel = document.querySelectorAll('.invCheck:checked').length;
  document.getElementById('invSelCount').textContent = sel;
}
function clearSel() {
  document.querySelectorAll('.invCheck').forEach(function(cb) { cb.checked = false; });
  document.getElementById('invSelectAll').checked = false;
  updateInvBulk();
}
function bulkStockUpdate() {
  var val = document.getElementById('bulkStockVal').value;
  if (val === '') { alert('Enter a stock value'); return; }
  var ids = Array.from(document.querySelectorAll('.invCheck:checked')).map(function(cb) { return cb.value; });
  if (!ids.length) { alert('Select products first'); return; }
  var fd = new FormData();
  fd.append('bulk_stock', '1');
  fd.append('bulk_ids', ids.join(','));
  fd.append('bulk_val', val);
  fetch('inventory.php', { method: 'POST', body: fd }).then(function() { location.reload(); });
}
</script>
</body>
</html>
