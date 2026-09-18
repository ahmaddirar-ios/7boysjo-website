<?php require_once 'config.php'; ?>
<?php
if (!is_logged_in()) { header('Location: login.php'); exit; }

$visitors = load_visitors();
$quotes = load_json(SITE_DIR . '/admin/data/quotes.json');
$products = normalize_products_full(load_products());

// Handle note save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note'])) {
  header('Content-Type: application/json');
  $idx = (int)$_POST['idx'];
  $note = trim($_POST['note']);
  if (isset($visitors[$idx])) {
    $visitors[$idx]['admin_note'] = $note;
    save_visitors($visitors);
    echo json_encode(['ok' => true]);
  } else {
    echo json_encode(['ok' => false]);
  }
  exit;
}

// Handle customer add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
  $cid = trim($_POST['cid'] ?? '');
  $name = trim($_POST['c_name'] ?? '');
  $email = trim($_POST['c_email'] ?? '');
  $phone = trim($_POST['c_phone'] ?? '');
  $company = trim($_POST['c_company'] ?? '');
  $ctype = trim($_POST['c_type'] ?? 'individual');
  if ($name === '') {
    header('Location: customers.php?msg=error_name');
    exit;
  }
  if ($cid === '') {
    $nid = count($visitors) ? max(array_map(fn($x) => intval($x['id'] ?? 0), $visitors)) + 1 : 1;
    $visitors[] = [
      'id' => $nid, 'name' => $name, 'email' => $email, 'phone' => $phone,
      'company' => $company, 'customer_type' => $ctype,
      'pass' => '', 'created' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
      'admin_note' => ''
    ];
  } else {
    $idx = (int)$cid;
    if (isset($visitors[$idx])) {
      $visitors[$idx]['name'] = $name;
      $visitors[$idx]['email'] = $email;
      $visitors[$idx]['phone'] = $phone;
      $visitors[$idx]['company'] = $company;
      $visitors[$idx]['customer_type'] = $ctype;
    }
  }
  save_visitors($visitors);
  header('Location: customers.php?msg=saved');
  exit;
}

// Handle delete
if (isset($_GET['del'])) {
  $idx = (int)$_GET['del'];
  if (isset($visitors[$idx])) {
    array_splice($visitors, $idx, 1);
    save_visitors($visitors);
  }
  header('Location: customers.php?msg=deleted');
  exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $header = ['id', 'name', 'email', 'phone', 'company', 'customer_type', 'total_orders', 'total_spent', 'last_order', 'created_at', 'admin_note'];
  $fp = fopen('php://temp', 'r+');
  fputcsv($fp, $header);
  // Build customer stats
  $cust_stats = build_customer_stats($visitors, $quotes, $products);
  foreach ($cust_stats as $cs) {
    $row = [];
    foreach ($header as $h) $row[] = $cs[$h] ?? '';
    fputcsv($fp, $row);
  }
  rewind($fp);
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="7boys-customers-' . date('Ymd-His') . '.csv"');
  fpassthru($fp);
  exit;
}

function build_customer_stats($visitors, $quotes, $products) {
  $stats = [];
  $price_map = [];
  foreach ($products as $p) {
    $slug = product_slug($p);
    $price_map[$slug] = floatval(preg_replace('/[^0-9.]/', '', $p['price'] ?? ''));
    $price_map[$p['name'] ?? ''] = $price_map[$slug];
  }
  foreach ($visitors as $i => $v) {
    $email = strtolower(trim($v['email'] ?? ''));
    $name = $v['name'] ?? '';
    $order_count = 0;
    $total_spent = 0;
    $last_order = '';
    foreach ($quotes as $q) {
      $q_email = strtolower(trim($q['email'] ?? ''));
      $q_name = trim($q['name'] ?? '');
      if ($q_email === $email || $q_name === $name) {
        $order_count++;
        $total_spent += floatval($q['total'] ?? 0);
        $qa = $q['created_at'] ?? $q['created'] ?? $q['date'] ?? '';
        if ($qa && (!$last_order || $qa > $last_order)) $last_order = $qa;
      }
    }
    // Segment
    if ($order_count >= 10) $segment = 'VIP';
    elseif ($order_count >= 2) $segment = 'Returning';
    elseif ($order_count === 1) $segment = 'New';
    else $segment = 'Lead';
    
    $stats[] = array_merge($v, [
      'total_orders' => $order_count,
      'total_spent' => $total_spent > 0 ? number_format($total_spent, 2) : '0',
      'last_order' => $last_order ? substr($last_order, 0, 10) : '—',
      'segment' => $segment,
      '_idx' => $i
    ]);
  }
  return $stats;
}

$customer_stats = build_customer_stats($visitors, $quotes, $products);

// Filters
$q = trim($_GET['q'] ?? '');
$fs = trim($_GET['filter_segment'] ?? '');
$ft = trim($_GET['filter_type'] ?? '');
$sort = trim($_GET['sort'] ?? '');

$filtered = $customer_stats;
if ($q !== '') $filtered = array_filter($filtered, fn($c) => 
  stripos($c['name'] ?? '', $q) !== false || 
  stripos($c['email'] ?? '', $q) !== false || 
  stripos($c['company'] ?? '', $q) !== false ||
  stripos($c['phone'] ?? '', $q) !== false
);
if ($fs !== '') $filtered = array_filter($filtered, fn($c) => $c['segment'] === $fs);
if ($ft !== '') $filtered = array_filter($filtered, fn($c) => ($c['customer_type'] ?? '') === $ft);
$filtered = array_values($filtered);

if ($sort === 'name') usort($filtered, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
elseif ($sort === 'orders') usort($filtered, fn($a, $b) => $b['total_orders'] <=> $a['total_orders']);
elseif ($sort === 'spent') usort($filtered, fn($a, $b) => floatval($b['total_spent']) <=> floatval($a['total_spent']));
elseif ($sort === 'recent') usort($filtered, fn($a, $b) => strcmp($b['last_order'] ?? '', $a['last_order'] ?? ''));

// Stats
$vip_count = count(array_filter($customer_stats, fn($c) => $c['segment'] === 'VIP'));
$returning_count = count(array_filter($customer_stats, fn($c) => $c['segment'] === 'Returning'));
$new_count = count(array_filter($customer_stats, fn($c) => $c['segment'] === 'New'));
$lead_count = count(array_filter($customer_stats, fn($c) => $c['segment'] === 'Lead'));
$total_customers = count($visitors);
$total_revenue = array_sum(array_map(fn($c) => floatval($c['total_spent']), $customer_stats));

$msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'saved') $msg = 'Customer saved.';
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') $msg = 'Customer deleted.';
if (isset($_GET['msg']) && $_GET['msg'] === 'error_name') $msg = 'Name required.';

$edit_customer = null;
$edit_idx = null;
if (isset($_GET['edit'])) {
  $ei = (int)$_GET['edit'];
  foreach ($customer_stats as $cs) {
    if (($cs['_idx'] ?? -1) === $ei) { $edit_customer = $cs; $edit_idx = $ei; break; }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CRM — 7 Boys® Admin</title>
<style>
*{box-sizing:border-box;} body{font-family:system-ui;margin:0;background:#eef1ee;color:#222;}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:220px;background:#053d20;color:#fff;padding:20px 0;overflow-y:auto;}
.sidebar h2{font-family:Georgia,serif;text-align:center;margin:0 0 20px;font-size:20px;}
.sidebar a{display:block;color:#cfe;padding:11px 22px;text-decoration:none;font-size:14px;border-left:3px solid transparent;}
.sidebar a:hover,.sidebar a.active{background:#0a5230;border-left-color:#c9a23f;}
.sidebar .logout{margin-top:20px;color:#c9a23f;}
.menu-btn{display:none;position:fixed;top:12px;left:12px;z-index:200;background:#053d20;color:#fff;border:1px solid #c9a23f;border-radius:8px;padding:9px 13px;font-size:16px;cursor:pointer;}
.mobilebar{display:none;}
@media(max-width:860px){.mobilebar{display:flex;position:fixed;top:0;left:0;right:0;z-index:200;background:#053d20;color:#fff;align-items:center;gap:10px;padding:10px 12px;box-shadow:0 2px 12px rgba(0,0,0,.25);}.mobilebar .menu-btn{display:block;position:static;}.mobilebar b{font-family:Georgia,serif;font-size:16px;font-weight:400;}.sidebar{transform:translateX(-100%);transition:transform .25s;width:250px;z-index:150;padding-top:64px;}.sidebar.open{transform:none;box-shadow:4px 0 30px rgba(0,0,0,.35);}.menu-btn{display:block;}.main{margin-left:0 !important;padding:70px 12px 40px !important;}h1{font-size:22px;}.toolbar{flex-direction:column;align-items:stretch;}table{display:block;overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}}
.main{margin-left:220px;padding:30px 40px;}
h1{font-family:Georgia,serif;color:#053d20;}
.msg{background:#e8f5e9;color:#1b5e20;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
.toolbar{display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.toolbar input[type=text]{max-width:240px;}
.toolbar select{max-width:190px;width:auto;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;margin-top:16px;}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:13px;}
th{background:#053d20;color:#fff;}
.brand-head{display:flex;align-items:center;gap:10px;padding:16px 16px 14px;}.brand-head span{color:#fff;font-size:19px;font-weight:800;letter-spacing:.01em;}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin:24px 0;}
.dash-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.dash-card .num{font-size:2em;font-weight:700;color:#053d20;}
.dash-card .lbl{color:#7a7267;font-size:.85em;margin-top:4px;}
.dash-card.vip .num{color:#7c3aed;}
.dash-card.returning .num{color:#053d20;}
.dash-card.new .num{color:#2563eb;}
.dash-card.revenue .num{color:#059669;}
.seg-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;}
.seg-vip{background:#ede9fe;color:#7c3aed;border:1px solid #c4b5fd;}
.seg-returning{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.seg-new{background:#dbeafe;color:#2563eb;border:1px solid #93c5fd;}
.seg-lead{background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;}
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px;max-width:640px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.modal h2{font-family:Georgia,serif;color:#053d20;margin:0 0 16px;}
.modal .close{float:right;font-size:24px;cursor:pointer;color:#6b7280;background:none;border:0;}
.modal .field{margin-bottom:12px;}
.modal label{display:block;font-size:12px;color:#6b7280;margin-bottom:4px;font-weight:600;}
.modal input,.modal select,.modal textarea{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;}
.modal textarea{height:80px;}
.modal .actions{display:flex;gap:10px;margin-top:16px;}
.modal .actions button{flex:1;padding:10px;border-radius:8px;font-weight:600;cursor:pointer;}
.btn-primary{background:#053d20;color:#fff;border:0;}
.btn-outline{background:#fff;color:#053d20;border:1px solid #053d20;}
.note-display{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:12px;color:#92400e;min-height:40px;cursor:pointer;}
.note-display.empty{color:#9ca3af;border-style:dashed;}
.customer-avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#053d20,#0a5230);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;}
.order-item{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:12px;}
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
<a href="inventory.php">📊 Inventory</a>
<a href="customers.php" class="active">👥 Customers</a>
<a href="index.php?action=quotes">🧾 Quotes</a>
<a href="index.php?action=visitors">👥 Clients</a>
<a href="index.php?action=settings">⚙️ Settings</a>
<a href="login.php?logout=1" class="logout">🚪 Logout</a>
</div>
<div class="main">
<h1>👥 Customer CRM</h1>
<?php if($msg): ?><div class="msg"><?= esc($msg) ?></div><?php endif; ?>

<div class="dash-grid">
  <div class="dash-card"><div class="num"><?= $total_customers ?></div><div class="lbl">Total Customers</div></div>
  <div class="dash-card vip"><div class="num"><?= $vip_count ?></div><div class="lbl">VIP</div></div>
  <div class="dash-card returning"><div class="num"><?= $returning_count ?></div><div class="lbl">Returning</div></div>
  <div class="dash-card new"><div class="num"><?= $new_count ?></div><div class="lbl">New</div></div>
  <div class="dash-card revenue"><div class="num"><?= number_format($total_revenue, 0) ?> JD</div><div class="lbl">Total Revenue</div></div>
</div>

<div class="toolbar">
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
<input type="text" name="q" placeholder="Search name, email, company..." value="<?= esc($q) ?>" style="min-width:160px;flex:1">
<select name="filter_segment"><option value="">All segments</option><option <?= $fs==='VIP'?'selected':'' ?> value="VIP">VIP</option><option <?= $fs==='Returning'?'selected':'' ?> value="Returning">Returning</option><option <?= $fs==='New'?'selected':'' ?> value="New">New</option><option <?= $fs==='Lead'?'selected':'' ?> value="Lead">Lead</option></select>
<select name="filter_type"><option value="">All types</option><option <?= $ft==='individual'?'selected':'' ?> value="individual">Individual</option><option <?= $ft==='restaurant'?'selected':'' ?> value="restaurant">Restaurant</option><option <?= $ft==='hotel'?'selected':'' ?> value="hotel">Hotel</option><option <?= $ft==='company'?'selected':'' ?> value="company">Company</option><option <?= $ft==='other'?'selected':'' ?> value="other">Other</option></select>
<select name="sort"><option value="">Sort: Default</option><option <?= $sort==='name'?'selected':'' ?> value="name">Name A-Z</option><option <?= $sort==='orders'?'selected':'' ?> value="orders">Most Orders</option><option <?= $sort==='spent'?'selected':'' ?> value="spent">Most Spent</option><option <?= $sort==='recent'?'selected':'' ?> value="recent">Recent Activity</option></select>
<button type="submit" style="margin-top:0;background:#053d20;color:#fff;border:none;padding:9px 16px;border-radius:8px;cursor:pointer;">Filter</button>
<a href="customers.php" style="color:#053d20;line-height:38px;">Reset</a>
</form>
<a href="customers.php?export=csv" style="background:#053d20;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;">📥 Export CSV</a>
<button onclick="openAddModal()" style="background:#053d20;color:#fff;padding:9px 16px;border-radius:8px;border:0;cursor:pointer;">+ Add Customer</button>
</div>

<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="max-height:65vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="custTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1">
<tr>
<th style="padding:8px 12px;width:44px"></th>
<th style="padding:8px 12px">Name</th>
<th style="padding:8px 12px">Email</th>
<th style="padding:8px 12px">Phone</th>
<th style="padding:8px 12px">Company</th>
<th style="padding:8px 12px">Orders</th>
<th style="padding:8px 12px">Spent</th>
<th style="padding:8px 12px">Last Order</th>
<th style="padding:8px 12px">Segment</th>
<th style="padding:8px 12px;min-width:140px">Actions</th>
</tr>
</thead>
<tbody>
<?php foreach($filtered as $c): ?>
<tr style="border-bottom:1px solid #f3f4f6">
<td style="padding:6px 12px"><div class="customer-avatar"><?= esc(strtoupper(substr($c['name'] ?? 'U', 0, 1))) ?></div></td>
<td style="padding:6px 12px"><b style="color:#053d20"><?= esc($c['name'] ?? '—') ?></b><div style="font-size:11px;color:#9ca3af"><?= esc($c['customer_type'] ?? '') ?></div></td>
<td style="padding:6px 12px;font-size:12px"><?= esc($c['email'] ?? '—') ?></td>
<td style="padding:6px 12px;font-size:12px"><?= esc($c['phone'] ?? '—') ?></td>
<td style="padding:6px 12px;font-size:12px"><?= esc($c['company'] ?? '—') ?></td>
<td style="padding:6px 12px;text-align:center;font-weight:700"><?= $c['total_orders'] ?? 0 ?></td>
<td style="padding:6px 12px;font-weight:600"><?= esc($c['total_spent'] ?? '0') ?> JD</td>
<td style="padding:6px 12px;font-size:12px"><?= esc($c['last_order'] ?? '—') ?></td>
<td style="padding:6px 12px"><span class="seg-badge seg-<?= strtolower($c['segment'] ?? 'lead') ?>"><?= esc($c['segment'] ?? 'Lead') ?></span></td>
<td style="padding:6px 12px;white-space:nowrap">
<button onclick='openProfile(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;border:0;cursor:pointer;font-size:11px">Profile</button>
<a href="customers.php?edit=<?= $c['_idx'] ?>" style="padding:4px 8px;border:1px solid #d1d5db;color:#053d20;border-radius:6px;text-decoration:none;font-size:11px">Edit</a>
<a href="customers.php?del=<?= $c['_idx'] ?>" onclick="return confirm('Delete this customer?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<p style="color:#6b7280;font-size:12px;margin-top:8px"><?= count($filtered) ?> customers shown</p>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="addModal">
<div class="modal">
<button class="close" onclick="closeAddModal()">&times;</button>
<h2 id="modalTitle">Add Customer</h2>
<form method="post" id="customerForm">
<input type="hidden" name="cid" id="c_id" value="">
<div class="field"><label>Name *</label><input type="text" name="c_name" id="c_name" required></div>
<div class="field"><label>Email</label><input type="email" name="c_email" id="c_email"></div>
<div class="field"><label>Phone</label><input type="text" name="c_phone" id="c_phone"></div>
<div class="field"><label>Company</label><input type="text" name="c_company" id="c_company"></div>
<div class="field"><label>Type</label>
<select name="c_type" id="c_type">
<option value="individual">Individual</option>
<option value="restaurant">Restaurant</option>
<option value="hotel">Hotel</option>
<option value="company">Company</option>
<option value="other">Other</option>
</select>
</div>
<div class="actions">
<button type="submit" name="save_customer" class="btn-primary">Save Customer</button>
<button type="button" class="btn-outline" onclick="closeAddModal()">Cancel</button>
</div>
</form>
</div>
</div>

<!-- Profile Modal -->
<div class="modal-overlay" id="profileModal">
<div class="modal">
<button class="close" onclick="closeProfile()">&times;</button>
<div style="display:flex;gap:16px;align-items:center;margin-bottom:16px">
<div class="customer-avatar" id="profAvatar" style="width:56px;height:56px;font-size:22px"></div>
<div>
<h2 id="profName" style="margin:0"></h2>
<p id="profType" style="margin:2px 0 0;color:#6b7280;font-size:12px"></p>
</div>
<div style="margin-left:auto"><span class="seg-badge" id="profSegment"></span></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
<div><label style="font-size:11px;color:#6b7280">Email</label><div id="profEmail" style="font-size:13px"></div></div>
<div><label style="font-size:11px;color:#6b7280">Phone</label><div id="profPhone" style="font-size:13px"></div></div>
<div><label style="font-size:11px;color:#6b7280">Company</label><div id="profCompany" style="font-size:13px"></div></div>
<div><label style="font-size:11px;color:#6b7280">Customer Since</label><div id="profCreated" style="font-size:13px"></div></div>
</div>
<div style="background:#f9fafb;border-radius:8px;padding:12px;margin-bottom:14px">
<div style="display:flex;justify-content:space-between"><span style="color:#6b7280;font-size:12px">Total Orders</span><b id="profOrders">0</b></div>
<div style="display:flex;justify-content:space-between;margin-top:6px"><span style="color:#6b7280;font-size:12px">Total Spent</span><b id="profSpent">0 JD</b></div>
</div>
<div style="margin-bottom:14px">
<label style="font-size:11px;color:#6b7280">Admin Notes</label>
<div class="note-display empty" id="profNote" onclick="editNote(this)" data-idx="">Click to add notes...</div>
</div>
<div>
<label style="font-size:11px;color:#6b7280;margin-bottom:6px;display:block">Order History</label>
<div id="profOrdersList" style="max-height:150px;overflow:auto;background:#f9fafb;border-radius:8px;padding:10px;font-size:12px;color:#6b7280">No orders found.</div>
</div>
</div>
</div>

<script>
// Embed quotes data for order history lookup
const quotesData = <?= json_encode($quotes, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function openAddModal() {
  document.getElementById('modalTitle').textContent = 'Add Customer';
  document.getElementById('c_id').value = '';
  document.getElementById('customerForm').reset();
  document.getElementById('addModal').classList.add('open');
}
function closeAddModal() { document.getElementById('addModal').classList.remove('open'); }

function openProfile(c) {
  document.getElementById('profAvatar').textContent = (c.name || 'U').charAt(0).toUpperCase();
  document.getElementById('profName').textContent = c.name || '';
  document.getElementById('profType').textContent = (c.customer_type || 'N/A') + ' · ID: ' + (c.id || '');
  document.getElementById('profSegment').textContent = c.segment || 'Lead';
  document.getElementById('profSegment').className = 'seg-badge seg-' + (c.segment || 'Lead').toLowerCase();
  document.getElementById('profEmail').textContent = c.email || '—';
  document.getElementById('profPhone').textContent = c.phone || '—';
  document.getElementById('profCompany').textContent = c.company || '—';
  document.getElementById('profCreated').textContent = (c.created_at || c.created || '—').substr(0, 10);
  document.getElementById('profOrders').textContent = c.total_orders || 0;
  document.getElementById('profSpent').textContent = (c.total_spent || '0') + ' JD';
  
  // Note
  var noteEl = document.getElementById('profNote');
  noteEl.setAttribute('data-idx', c._idx);
  if (c.admin_note) {
    noteEl.textContent = c.admin_note;
    noteEl.className = 'note-display';
  } else {
    noteEl.textContent = 'Click to add notes...';
    noteEl.className = 'note-display empty';
  }
  
  // Order history
  var ordersList = document.getElementById('profOrdersList');
  var custOrders = quotesData.filter(function(q) {
    return (q.email && q.email.toLowerCase() === (c.email || '').toLowerCase()) ||
           (q.name && q.name === c.name);
  });
  if (custOrders.length === 0) {
    ordersList.innerHTML = '<div style="padding:10px;color:#9ca3af">No orders yet.</div>';
  } else {
    ordersList.innerHTML = custOrders.map(function(q) {
      var date = (q.created_at || q.created || q.date || '').substr(0, 10);
      var items = Array.isArray(q.items) ? q.items.length : 0;
      var status = q.status || 'new';
      var statusColor = status === 'new' ? '#c9a23f' : (status === 'viewed' ? '#2563eb' : '#16a34a');
      return '<div class="order-item"><span>#' + (q.id || '?') + ' · ' + date + ' · ' + items + ' items</span><span style="color:' + statusColor + ';font-weight:700">' + status + ' · ' + (q.total || 0) + ' JD</span></div>';
    }).join('');
  }
  
  document.getElementById('profileModal').classList.add('open');
}
function closeProfile() { document.getElementById('profileModal').classList.remove('open'); }

function editNote(el) {
  var idx = el.getAttribute('data-idx');
  var current = el.textContent === 'Click to add notes...' ? '' : el.textContent;
  var input = document.createElement('textarea');
  input.style.width = '100%';
  input.style.height = '80px';
  input.style.border = '1px solid #fde68a';
  input.style.borderRadius = '8px';
  input.style.padding = '10px';
  input.style.fontSize = '13px';
  input.value = current;
  el.replaceWith(input);
  input.focus();
  input.onblur = function() {
    var val = input.value.trim();
    var div = document.createElement('div');
    div.className = val ? 'note-display' : 'note-display empty';
    div.setAttribute('data-idx', idx);
    div.onclick = function() { editNote(this); };
    div.textContent = val || 'Click to add notes...';
    input.replaceWith(div);
    if (val !== current) {
      var fd = new FormData();
      fd.append('save_note', '1');
      fd.append('idx', idx);
      fd.append('note', val);
      fetch('customers.php', { method: 'POST', body: fd });
    }
  };
}

<?php if($edit_customer): ?>
window.addEventListener('DOMContentLoaded', function() {
  openAddModal();
  document.getElementById('modalTitle').textContent = 'Edit Customer';
  document.getElementById('c_id').value = '<?= $edit_idx ?>';
  document.getElementById('c_name').value = '<?= esc($edit_customer['name'] ?? '') ?>';
  document.getElementById('c_email').value = '<?= esc($edit_customer['email'] ?? '') ?>';
  document.getElementById('c_phone').value = '<?= esc($edit_customer['phone'] ?? '') ?>';
  document.getElementById('c_company').value = '<?= esc($edit_customer['company'] ?? '') ?>';
  document.getElementById('c_type').value = '<?= esc($edit_customer['customer_type'] ?? 'individual') ?>';
});
<?php endif; ?>

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
});
</script>
</body>
</html>
