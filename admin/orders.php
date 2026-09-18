<?php
/**
 * Orders Management Page — 7 Boys Admin
 * Standalone order management with table, filters, modals, bulk actions
 */

if (!defined('SITE_DIR')) { http_response_code(403); exit('Forbidden'); }

if (!function_exists('is_logged_in') || !is_logged_in()) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/lang.php';

$QUOTES_FILE = SITE_DIR . '/admin/data/quotes.json';
$PER_PAGE = 20;
$CSRF_NAME = 'orders_csrf';

if (session_status() === PHP_SESSION_NONE) @session_start();

if (empty($_SESSION[$CSRF_NAME])) {
    $_SESSION[$CSRF_NAME] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION[$CSRF_NAME];

function esc_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function st_label($s) {
    $m = [
        'new' => ['l' => 'New', 'bg' => '#dbeafe', 'fg' => '#1e40af', 'bd' => '#93c5fd'],
        'pending' => ['l' => 'Pending', 'bg' => '#fef9c3', 'fg' => '#854d0e', 'bd' => '#fde047'],
        'processing' => ['l' => 'Processing', 'bg' => '#ffedd5', 'fg' => '#9a3412', 'bd' => '#fdba74'],
        'completed' => ['l' => 'Completed', 'bg' => '#dcfce7', 'fg' => '#166534', 'bd' => '#86efac'],
        'cancelled' => ['l' => 'Cancelled', 'bg' => '#fee2e2', 'fg' => '#991b1b', 'bd' => '#fca5a5'],
        'viewed' => ['l' => 'Viewed', 'bg' => '#e0f2fe', 'fg' => '#075985', 'bd' => '#7dd3fc'],
        'confirmed' => ['l' => 'Confirmed', 'bg' => '#dcfce7', 'fg' => '#166534', 'bd' => '#86efac'],
    ];
    return $m[$s] ?? ['l' => ucfirst($s), 'bg' => '#f3f4f6', 'fg' => '#374151', 'bd' => '#d1d5db'];
}

function ld_q() {
    global $QUOTES_FILE;
    $d = @file_exists($QUOTES_FILE) ? json_decode(@file_get_contents($QUOTES_FILE), true) : [];
    return is_array($d) ? $d : [];
}

function sv_q($data) {
    global $QUOTES_FILE;
    return @file_put_contents($QUOTES_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_f = trim($_GET['date_from'] ?? '');
$date_t = trim($_GET['date_to'] ?? '');
$pg = max(1, (int)($_GET['pg'] ?? 1));
$sort = trim($_GET['sort'] ?? 'date_desc');

$quotes_all = ld_q();
$items = [];

foreach ($quotes_all as $idx => $row) {
    if (!is_array($row)) continue;
    $st = strtolower(trim($row['status'] ?? 'new'));
    if ($status !== '' && $st !== $status) continue;
    $dt = $row['date'] ?? $row['at'] ?? '';
    if ($date_f !== '' && $dt < $date_f) continue;
    if ($date_t !== '' && $dt > $date_t . ' 23:59:59') continue;
    if ($q !== '') {
        $hay = strtolower(($row['name'] ?? '') . ' ' . ($row['email'] ?? '') . ' ' . ($row['phone'] ?? '') . ' ' . ($row['id'] ?? ''));
        if (stripos($hay, strtolower($q)) === false) continue;
    }
    $items[] = array_merge($row, ['_idx' => $idx]);
}

usort($items, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'date_asc': return ($a['date'] ?? $a['at'] ?? '') <=> ($b['date'] ?? $b['at'] ?? '');
        case 'date_desc': return ($b['date'] ?? $b['at'] ?? '') <=> ($a['date'] ?? $a['at'] ?? '');
        case 'total_asc': return floatval($a['total'] ?? 0) <=> floatval($b['total'] ?? 0);
        case 'total_desc': return floatval($b['total'] ?? 0) <=> floatval($a['total'] ?? 0);
        case 'id_asc': return strnatcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        case 'id_desc': return strnatcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
        case 'name_asc': return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        case 'name_desc': return strcasecmp((string)($b['name'] ?? ''), (string)($a['name'] ?? ''));
        default: return 0;
    }
});

$total_items = count($items);
$total_pages = max(1, (int)ceil($total_items / $PER_PAGE));
if ($pg > $total_pages) $pg = $total_pages;
$paged = array_slice($items, ($pg - 1) * $PER_PAGE, $PER_PAGE);

function orders_qs($overrides = []) {
    $base = ['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '', 'date_from' => $_GET['date_from'] ?? '', 'date_to' => $_GET['date_to'] ?? '', 'sort' => $_GET['sort'] ?? 'date_desc'];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
}

$sc = ['new' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($quotes_all as $q) {
    if (!is_array($q)) continue;
    $s = strtolower(trim($q['status'] ?? 'new'));
    if (isset($sc[$s])) $sc[$s]++;
    elseif ($s === 'viewed') $sc['pending']++;
    elseif ($s === 'confirmed') $sc['completed']++;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Order Management — 7 Boys Admin</title>
    <style>
        :root{--green:#053d20;--gold:#c9a23f;--bg:#eef1ee;--card:#fff;--border:#e5e7eb;--text:#222;--muted:#6b7280}
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:var(--bg);color:var(--text)}
        .wrap{max-width:1400px;margin:0 auto;padding:24px 28px 60px}
        .head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
        .head h1{font-family:Georgia,serif;color:var(--green);margin:0;font-size:28px}
        .head .sub{font-size:13px;color:var(--muted)}
        .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
        .tab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:999px;background:var(--card);border:1.5px solid var(--border);font-size:13px;font-weight:600;color:var(--text);text-decoration:none;cursor:pointer;transition:.15s}
        .tab:hover{border-color:var(--green);color:var(--green)}
        .tab.active{background:var(--green);color:#fff;border-color:var(--green)}
        .tab .n{background:rgba(0,0,0,.08);border-radius:999px;padding:1px 7px;font-size:11px}
        .tab.active .n{background:rgba(255,255,255,.2)}
        .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .toolbar input[type=text],.toolbar input[type=date],.toolbar select{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;outline:none;transition:border-color .15s}
        .toolbar input[type=text]:focus,.toolbar input[type=date]:focus,.toolbar select:focus{border-color:var(--green)}
        .toolbar input[type=text]{flex:1;min-width:200px}
        .toolbar .btn{padding:9px 16px;border-radius:8px;border:0;font-size:13px;font-weight:600;cursor:pointer;transition:.15s}
        .toolbar .btn-primary{background:var(--green);color:#fff}
        .toolbar .btn-primary:hover{background:#0a5230}
        .toolbar .btn-ghost{background:transparent;border:1.5px solid var(--border);color:var(--text)}
        .toolbar .btn-ghost:hover{border-color:var(--green);color:var(--green)}
        .bulkbar{display:none;gap:8px;align-items:center;background:#f9fafb;border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:16px;flex-wrap:wrap}
        .bulkbar.show{display:flex}
        .bulkbar .sel-count{font-weight:700;font-size:14px}
        .bulkbar .btn-danger{background:#dc2626;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
        .bulkbar .btn-danger:hover{background:#b91c1c}
        .table-wrap{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
        table.orders-tbl{width:100%;border-collapse:collapse;font-size:13px}
        table.orders-tbl thead th{background:#f9fafb;padding:12px 14px;text-align:left;font-weight:600;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.03em;border-bottom:2px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap}
        table.orders-tbl thead th input[type=checkbox]{accent-color:var(--green)}
        table.orders-tbl tbody td{padding:11px 14px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
        table.orders-tbl tbody tr:hover{background:#fafafa}
        table.orders-tbl tbody tr.selected{background:#eef7f0}
        table.orders-tbl .cell-id{font-weight:700;color:var(--green);white-space:nowrap}
        table.orders-tbl .cell-customer{font-weight:600}
        table.orders-tbl .cell-meta{font-size:11px;color:var(--muted);margin-top:2px}
        table.orders-tbl .cell-items{font-weight:600;text-align:center}
        table.orders-tbl .cell-total{font-weight:700;white-space:nowrap}
        table.orders-tbl .cell-date{font-size:12px;color:var(--muted);white-space:nowrap}
        table.orders-tbl .cell-actions{white-space:nowrap;text-align:right}
        table.orders-tbl .cell-actions a,table.orders-tbl .cell-actions button{display:inline-block;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;border:0;cursor:pointer;margin-left:4px}
        table.orders-tbl .cell-actions .view-btn{background:var(--green);color:#fff}
        table.orders-tbl .cell-actions .print-btn{background:#fff;border:1px solid var(--border);color:var(--green)}
        table.orders-tbl .cell-actions .wa-btn{background:#25d366;color:#fff}
        table.orders-tbl .cell-actions .del-btn{background:#fff;border:1px solid #fecaca;color:#dc2626}
        table.orders-tbl .cell-actions .status-select{padding:4px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:12px;font-weight:600;background:#fff;cursor:pointer}
        .pagination{display:flex;gap:4px;flex-wrap:wrap;justify-content:center;margin-top:16px}
        .pagination a,.pagination span{padding:7px 13px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;min-width:36px;text-align:center}
        .pagination a{border:1px solid var(--border);background:var(--card);color:var(--green)}
        .pagination a:hover{background:var(--green);color:#fff;border-color:var(--green)}
        .pagination span.current{background:var(--green);color:#fff;border:1px solid var(--green)}
        .pagination .gap{border:0;background:transparent;color:var(--muted);cursor:default}
        .page-info{font-size:13px;color:var(--muted);text-align:center;margin-top:8px}
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px}
        .modal-overlay.show{display:flex}
        .modal{background:var(--card);border-radius:16px;width:100%;max-width:720px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--border)}
        .modal-head h2{margin:0;font-family:Georgia,serif;color:var(--green);font-size:20px}
        .modal-close{width:36px;height:36px;border-radius:50%;border:1.5px solid var(--border);background:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text)}
        .modal-close:hover{background:#fee2e2;border-color:#fecaca;color:#dc2626}
        .modal-body{padding:20px 24px}
        .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
        .detail-grid .field{background:#f9fafb;border-radius:10px;padding:10px 14px}
        .detail-grid .field label{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;font-weight:600}
        .detail-grid .field span{font-size:14px;font-weight:600}
        .detail-section{margin-bottom:18px}
        .detail-section h3{font-size:15px;font-weight:700;color:var(--green);margin:0 0 10px}
        .items-table{width:100%;border-collapse:collapse;font-size:13px}
        .items-table th,.items-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #f3f4f6}
        .items-table th{background:#f9fafb;font-weight:600;font-size:12px;color:var(--muted)}
        .items-table .item-total{text-align:right;font-weight:700}
        .modal-foot{padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}
        .modal-foot .btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:0}
        .modal-foot .btn-primary{background:var(--green);color:#fff}
        .modal-foot .btn-ghost{background:#fff;border:1.5px solid var(--border);color:var(--text)}
        .modal-foot .btn-danger{background:#dc2626;color:#fff}
        .modal-foot .btn-success{background:#16a34a;color:#fff}
        .toast{position:fixed;top:20px;right:20px;background:var(--green);color:#fff;padding:14px 20px;border-radius:10px;font-size:14px;font-weight:600;z-index:2000;opacity:0;transform:translateY(-12px);transition:.25s;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.15)}
        .toast.show{opacity:1;transform:translateY(0)}
        .toast.err{background:#dc2626}
        .empty{text-align:center;padding:60px 20px;color:var(--muted)}
        .empty .icon{font-size:48px;margin-bottom:12px}
        .empty h3{font-size:18px;color:var(--text);margin:0 0 6px}
        .empty p{font-size:14px;margin:0}
        @media(max-width:700px){.wrap{padding:16px}.detail-grid{grid-template-columns:1fr}.toolbar input[type=text]{width:100%}table.orders-tbl{font-size:12px}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1>Order Management</h1>
            <div class="sub">Manage and track all customer orders</div>
        </div>
        <div style="display:flex;gap:8px">
            <a href="index.php?action=quotes" style="padding:9px 16px;border-radius:8px;border:1.5px solid var(--border);text-decoration:none;font-size:13px;font-weight:600;color:var(--text)">Back to Quotes</a>
        </div>
    </div>

    <div class="tabs">
        <a class="tab <?= $status === '' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => '', 'pg' => 1]) ?>">All <span class="n"><?= $total_items ?></span></a>
        <a class="tab <?= $status === 'new' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => 'new', 'pg' => 1]) ?>">New <span class="n"><?= $sc['new'] ?></span></a>
        <a class="tab <?= $status === 'pending' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => 'pending', 'pg' => 1]) ?>">Pending <span class="n"><?= $sc['pending'] ?></span></a>
        <a class="tab <?= $status === 'processing' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => 'processing', 'pg' => 1]) ?>">Processing <span class="n"><?= $sc['processing'] ?></span></a>
        <a class="tab <?= $status === 'completed' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => 'completed', 'pg' => 1]) ?>">Completed <span class="n"><?= $sc['completed'] ?></span></a>
        <a class="tab <?= $status === 'cancelled' ? 'active' : '' ?>" href="orders.php?<?= orders_qs(['status' => 'cancelled', 'pg' => 1]) ?>">Cancelled <span class="n"><?= $sc['cancelled'] ?></span></a>
    </div>

    <div class="toolbar">
        <input type="text" id="searchInput" placeholder="Search by name, email, phone, ID..." value="<?= esc_h($q) ?>">
        <input type="date" id="dateFrom" value="<?= esc_h($date_f) ?>" title="From date">
        <input type="date" id="dateTo" value="<?= esc_h($date_t) ?>" title="To date">
        <select id="sortSelect">
            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Newest first</option>
            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Oldest first</option>
            <option value="total_desc" <?= $sort === 'total_desc' ? 'selected' : '' ?>>Highest total</option>
            <option value="total_asc" <?= $sort === 'total_asc' ? 'selected' : '' ?>>Lowest total</option>
            <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>ID high-low</option>
            <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>ID low-high</option>
            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
        </select>
        <button class="btn btn-primary" onclick="applyFilters()">Apply</button>
        <a href="orders.php" class="btn btn-ghost">Reset</a>
    </div>

    <div class="bulkbar" id="bulkbar">
        <span class="sel-count"><span id="selCount">0</span> selected</span>
        <button class="btn-danger" onclick="bulkDelete()">Delete Selected</button>
        <button class="btn btn-ghost" onclick="bulkStatusChange()">Change Status</button>
    </div>

    <div class="table-wrap">
        <table class="orders-tbl">
            <thead>
                <tr>
                    <th style="width:36px"><input type="checkbox" id="chkAll" onchange="toggleAll(this.checked)"></th>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th style="text-align:center">Items</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right;min-width:200px">Actions</th>
                </tr>
            </thead>
            <tbody id="ordersBody">
            <?php if (empty($paged)): ?>
                <tr><td colspan="10"><div class="empty"><div class="icon">📦</div><h3>No orders found</h3><p>No orders match your current filters.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($paged as $row):
                $idx = $row['_idx'];
                $oid = esc_h($row['id'] ?? '-');
                $name = esc_h($row['name'] ?? '-');
                $email = esc_h($row['email'] ?? '-');
                $company = esc_h($row['company'] ?? '');
                $phone = esc_h($row['phone'] ?? '-');
                $total = !empty($row['total']) ? number_format(floatval($row['total']), 2) : '—';
                $st = strtolower(trim($row['status'] ?? 'new'));
                $st_info = st_label($st);
                $items_arr = is_array($row['items'] ?? null) ? $row['items'] : [];
                $item_count = count($items_arr);
                $date_str = esc_h(substr($row['date'] ?? $row['at'] ?? '-', 0, 16));
                $wa_num = preg_replace('/[^0-9]/', '', $row['phone'] ?? '');
            ?>
                <tr data-idx="<?= $idx ?>" data-id="<?= $oid ?>">
                    <td style="padding:8px 14px"><input type="checkbox" class="row-chk" value="<?= $idx ?>" data-id="<?= $oid ?>" onchange="updateBulkBar()"></td>
                    <td class="cell-id">#<?= $oid ?></td>
                    <td class="cell-customer"><?= $name ?><div class="cell-meta"><?= $email ?></div></td>
                    <td><?= $company ? esc_h($company) : '<span style="color:#d1d5bd">—</span>' ?></td>
                    <td><?= $phone ?></td>
                    <td class="cell-items" style="text-align:center"><?= $item_count ?></td>
                    <td class="cell-total"><?= $total ?><?= $total !== '—' ? ' JOD' : '' ?></td>
                    <td>
                        <select class="status-select" data-idx="<?= $idx ?>" onchange="quickStatusChange(this)" style="background:<?= $st_info['bg'] ?>;color:<?= $st_info['fg'] ?>;border-color:<?= $st_info['bd'] ?>">
                            <option value="new" <?= $st === 'new' ? 'selected' : '' ?>>New</option>
                            <option value="pending" <?= $st === 'pending' || $st === 'viewed' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= $st === 'processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="completed" <?= $st === 'completed' || $st === 'confirmed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $st === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </td>
                    <td class="cell-date"><?= $date_str ?></td>
                    <td class="cell-actions">
                        <button class="view-btn" onclick="viewOrder(<?= $idx ?>)">View</button>
                        <button class="print-btn" onclick="printOrder(<?= $idx ?>, '<?= $oid ?>')">Print</button>
                        <?php if ($wa_num): ?>
                        <button class="wa-btn" onclick="sendWhatsApp(<?= $idx ?>, '<?= esc_h($name) ?>', '<?= $wa_num ?>', '<?= $oid ?>')">WhatsApp</button>
                        <?php endif; ?>
                        <button class="del-btn" onclick="deleteOrder(<?= $idx ?>, '<?= $oid ?>')">Del</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($pg > 1): ?><a href="orders.php?<?= orders_qs(['pg' => $pg - 1]) ?>">Prev</a><?php endif; ?>
        <?php
        $start = max(1, $pg - 2);
        $end = min($total_pages, $pg + 2);
        if ($start > 1) { echo '<a href="orders.php?' . orders_qs(['pg' => 1]) . '">1</a>'; if ($start > 2) echo '<span class="gap">...</span>'; }
        for ($p = $start; $p <= $end; $p++) {
            if ($p === $pg) echo '<span class="current">' . $p . '</span>';
            else echo '<a href="orders.php?' . orders_qs(['pg' => $p]) . '">' . $p . '</a>';
        }
        if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="gap">...</span>'; echo '<a href="orders.php?' . orders_qs(['pg' => $total_pages]) . '">' . $total_pages . '</a>'; }
        ?>
        <?php if ($pg < $total_pages): ?><a href="orders.php?<?= orders_qs(['pg' => $pg + 1]) ?>">Next</a><?php endif; ?>
    </div>
    <div class="page-info">Page <?= $pg ?> of <?= $total_pages ?> · <?= $total_items ?> total orders</div>
    <?php endif; ?>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-head">
            <h2>Order #<span id="modalOrderId">—</span></h2>
            <button class="modal-close" onclick="closeModal('detailModal')">×</button>
        </div>
        <div class="modal-body">
            <div id="modalStatusWrap" style="margin-bottom:16px"></div>
            <div class="detail-grid">
                <div class="field"><label>Customer</label><span id="mName">—</span></div>
                <div class="field"><label>Email</label><span id="mEmail">—</span></div>
                <div class="field"><label>Phone</label><span id="mPhone">—</span></div>
                <div class="field"><label>Company</label><span id="mCompany">—</span></div>
                <div class="field"><label>Date</label><span id="mDate">—</span></div>
                <div class="field"><label>Items</label><span id="mItemCount">—</span></div>
                <div class="field"><label>Total</label><span id="mTotal" style="font-size:18px;color:var(--green)">—</span></div>
                <div class="field"><label>Status</label><span id="mStatusSelect">—</span></div>
            </div>
            <div class="detail-section" style="margin-top:8px">
                <h3>Note</h3>
                <div id="mNote" style="background:#f9fafb;border-radius:10px;padding:12px;font-size:13px;color:#555;min-height:40px">—</div>
            </div>
            <div class="detail-section">
                <h3>Order Items</h3>
                <table class="items-table">
                    <thead><tr><th>#</th><th>Product</th><th>Slug</th><th style="text-align:center">Qty</th><th>Price</th><th class="item-total">Subtotal</th></tr></thead>
                    <tbody id="mItemsBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('detailModal')">Close</button>
            <button class="btn btn-success" onclick="printFromModal()">Print Invoice</button>
            <button class="btn btn-primary" id="mWaBtn">Send WhatsApp</button>
            <button class="btn btn-danger" id="mDelBtn">Delete Order</button>
        </div>
    </div>
</div>

<!-- WhatsApp Modal -->
<div class="modal-overlay" id="whatsappModal">
    <div class="modal" style="max-width:480px">
        <div class="modal-head">
            <h2>Send WhatsApp Message</h2>
            <button class="modal-close" onclick="closeModal('whatsappModal')">×</button>
        </div>
        <div class="modal-body">
            <div style="background:#f9fafb;border-radius:10px;padding:10px 14px;margin-bottom:12px">
                <label style="display:block;font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600;margin-bottom:3px">To</label>
                <span id="waTo" style="font-size:14px;font-weight:600">—</span>
            </div>
            <div style="margin-bottom:12px">
                <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">Template</label>
                <select id="waTemplate" onchange="fillTemplate()" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px">
                    <option value="status_update">Status Update</option>
                    <option value="order_confirmed">Order Confirmed</option>
                    <option value="order_shipped">Order Shipped</option>
                    <option value="order_completed">Order Completed</option>
                    <option value="custom">Custom Message</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">Message</label>
                <textarea id="waMessage" rows="6" style="width:100%;padding:12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;resize:vertical;line-height:1.5"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('whatsappModal')">Cancel</button>
            <button class="btn btn-success" id="waSendBtn">Send via WhatsApp</button>
        </div>
    </div>
</div>

<!-- Bulk Status Modal -->
<div class="modal-overlay" id="bulkStatusModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-head">
            <h2>Change Status (Bulk)</h2>
            <button class="modal-close" onclick="closeModal('bulkStatusModal')">×</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;margin-bottom:12px">Change status for <b id="bulkStatusCount">0</b> selected orders:</p>
            <select id="bulkStatusValue" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-size:14px">
                <option value="new">New</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('bulkStatusModal')">Cancel</button>
            <button class="btn btn-primary" onclick="confirmBulkStatus()">Apply</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrfToken" value="<?= esc_h($csrf_token) ?>">

<script>
const CSRF = document.getElementById('csrfToken').value;
const ST_C = {
    'new':{bg:'#dbeafe',fg:'#1e40af',bd:'#93c5fd'},
    'pending':{bg:'#fef9c3',fg:'#854d0e',bd:'#fde047'},
    'processing':{bg:'#ffedd5',fg:'#9a3412',bd:'#fdba74'},
    'completed':{bg:'#dcfce7',fg:'#166534',bd:'#86efac'},
    'cancelled':{bg:'#fee2e2',fg:'#991b1b',bd:'#fca5a5'},
};
let curModalOrd = null;

function toast(msg, isErr) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' err' : '');
    setTimeout(() => { t.className = 'toast'; }, 3000);
}

function applyFilters() {
    const q = document.getElementById('searchInput').value.trim();
    const df = document.getElementById('dateFrom').value;
    const dt = document.getElementById('dateTo').value;
    const s = document.getElementById('sortSelect').value;
    const p = new URLSearchParams();
    if (q) p.set('q', q);
    if (df) p.set('date_from', df);
    if (dt) p.set('date_to', dt);
    if (s) p.set('sort', s);
    window.location = 'orders.php?' + p.toString();
}
document.getElementById('searchInput').addEventListener('keydown', e => { if (e.key === 'Enter') applyFilters(); });

function toggleAll(checked) {
    document.querySelectorAll('.row-chk').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') {
            cb.checked = checked;
            cb.closest('tr').classList.toggle('selected', checked);
        }
    });
    updateBulkBar();
}

function updateBulkBar() {
    const n = document.querySelectorAll('.row-chk:checked').length;
    document.getElementById('selCount').textContent = n;
    document.getElementById('bulkbar').classList.toggle('show', n > 0);
    document.querySelectorAll('.row-chk').forEach(cb => {
        cb.closest('tr').classList.toggle('selected', cb.checked);
    });
}

async function ajax(action, data) {
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf', CSRF);
    for (const k in data) {
        if (Array.isArray(data[k])) data[k].forEach(v => body.append(k + '[]', v));
        else body.append(k, data[k]);
    }
    const res = await fetch('orders_ajax.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString() });
    return await res.json();
}

async function viewOrder(idx) {
    const res = await ajax('get_details', { order_id: idx });
    if (!res.ok) { toast('Error loading order', true); return; }
    const d = res.data;
    curModalOrd = { idx, id: d.id };
    document.getElementById('modalOrderId').textContent = d.id;
    const si = ST_C[d.status] || ST_C['new'];
    document.getElementById('modalStatusWrap').innerHTML = '<span style="display:inline-block;padding:4px 14px;border-radius:999px;font-size:13px;font-weight:700;background:' + si.bg + ';color:' + si.fg + ';border:1.5px solid ' + si.bd + '">' + d.status_label + '</span>';
    document.getElementById('mName').textContent = d.name || '—';
    document.getElementById('mEmail').textContent = d.email || '—';
    document.getElementById('mPhone').textContent = d.phone || '—';
    document.getElementById('mCompany').textContent = d.company || '—';
    document.getElementById('mDate').textContent = d.date || '—';
    document.getElementById('mItemCount').textContent = d.item_count || 0;
    document.getElementById('mTotal').textContent = d.total ? d.total + ' JOD' : '—';
    document.getElementById('mStatusSelect').innerHTML = '<select id="modalStatusSel" style="padding:6px 12px;border-radius:8px;border:1.5px solid ' + si.bd + ';font-size:13px;font-weight:700;background:' + si.bg + ';color:' + si.fg + '"><option value="new"' + (d.status==='new'?'selected="selected"':'' ) + '>New</option><option value="pending"' + (d.status==='pending'?'selected="selected"':'' ) + '>Pending</option><option value="processing"' + (d.status==='processing'?'selected="selected"':'' ) + '>Processing</option><option value="completed"' + (d.status==='completed'?'selected="selected"':'' ) + '>Completed</option><option value="cancelled"' + (d.status==='cancelled'?'selected="selected"':'' ) + '>Cancelled</select> <button onclick="saveModalStatus()" style="margin-left:8px;padding:6px 12px;border-radius:8px;border:0;background:var(--green);color:#fff;font-size:12px;font-weight:600;cursor:pointer">Save</button>';
    document.getElementById('mNote').textContent = d.note || '(no note)';
    const ib = document.getElementById('mItemsBody');
    ib.innerHTML = '';
    if (d.items && d.items.length) {
        d.items.forEach((it, i) => {
            const price = it.price || '—';
            const sub = (parseFloat(it.price || 0) * parseInt(it.qty || 1)).toFixed(2);
            ib.innerHTML += '<tr><td>' + (i+1) + '</td><td>' + (it.name || it.slug) + '</td><td style="color:var(--muted);font-size:12px">' + it.slug + '</td><td style="text-align:center">' + (it.qty || 1) + '</td><td>' + price + '</td><td class="item-total">' + sub + ' JOD</td></tr>';
        });
    } else {
        ib.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">No items</td></tr>';
    }
    document.getElementById('mWaBtn').onclick = () => { closeModal('detailModal'); openWhatsApp(d); };
    document.getElementById('mDelBtn').onclick = () => { closeModal('detailModal'); deleteOrder(idx, d.id); };
    document.getElementById('detailModal').classList.add('show');
}

function saveModalStatus() {
    const sel = document.getElementById('modalStatusSel');
    if (!sel || !curModalOrd) return;
    quickStatusChange({ value: sel.value, dataset: { idx: curModalOrd.idx } }, () => closeModal('detailModal'));
}

async function quickStatusChange(sel, cb) {
    const idx = sel.dataset.idx;
    const ns = sel.value;
    const res = await ajax('update_status', { order_id: idx, new_status: ns });
    if (res.ok) { toast('Status updated to ' + res.data.new_label); if (cb) cb(); }
    else toast(res.error || 'Update failed', true);
}

async function deleteOrder(idx, id) {
    if (!confirm('Delete order #' + id + '? This cannot be undone.')) return;
    const res = await ajax('delete', { order_id: idx });
    if (res.ok) {
        toast('Order #' + id + ' deleted');
        const row = document.querySelector('tr[data-idx="' + idx + '"]');
        if (row) row.remove();
        updateBulkBar();
    } else toast(res.error || 'Delete failed', true);
}

async function bulkDelete() {
    const ids = Array.from(document.querySelectorAll('.row-chk:checked')).map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' orders? This cannot be undone.')) return;
    const res = await ajax('bulk_delete', { order_ids: ids });
    if (res.ok) { toast('Deleted ' + res.data.deleted + ' orders'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Bulk delete failed', true);
}

function bulkStatusChange() {
    document.getElementById('bulkStatusCount').textContent = document.querySelectorAll('.row-chk:checked').length;
    document.getElementById('bulkStatusModal').classList.add('show');
}

async function confirmBulkStatus() {
    const ids = Array.from(document.querySelectorAll('.row-chk:checked')).map(cb => cb.value);
    const ns = document.getElementById('bulkStatusValue').value;
    const res = await ajax('bulk_status', { order_ids: ids, new_status: ns });
    if (res.ok) { toast('Updated ' + res.data.updated + ' orders'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Bulk update failed', true);
    closeModal('bulkStatusModal');
}

let curWA = null;

function sendWhatsApp(idx, name, phone, id) {
    openWhatsApp({ idx, name, phone, id });
}

function openWhatsApp(d) {
    curWA = d;
    document.getElementById('waTo').textContent = (d.name || 'Customer') + (d.phone ? ' (+' + d.phone + ')' : '');
    document.getElementById('waMessage').value = '';
    fillTemplate();
    document.getElementById('whatsappModal').classList.add('show');
}

function fillTemplate() {
    if (!curWA) return;
    const tpl = document.getElementById('waTemplate').value;
    const n = curWA.name || 'Customer';
    const id = curWA.id || '';
    const st = curWA.status || '';
    const t = {
        status_update: 'Dear ' + n + ',\n\nYour order #' + id + ' status has been updated to: ' + st.toUpperCase() + '.\n\nWe will keep you informed of any changes.\n\nThank you for choosing 7 Boys\nRubu Al Quds - Premium Food Trading Since 1966',
        order_confirmed: 'Dear ' + n + ',\n\nGreat news! Your order #' + id + ' has been confirmed.\n\nOur team will begin preparing your items shortly.\n\nThank you for choosing 7 Boys\nRubu Al Quds - Premium Food Trading Since 1966',
        order_shipped: 'Dear ' + n + ',\n\nYour order #' + id + ' has been shipped!\n\nYou should receive your delivery within 2-3 business days.\n\nThank you for choosing 7 Boys\nRubu Al Quds - Premium Food Trading Since 1966',
        order_completed: 'Dear ' + n + ',\n\nYour order #' + id + ' has been completed successfully.\n\nWe hope you enjoyed your purchase from 7 Boys. We look forward to serving you again!\n\nThank you for your trust.\nRubu Al Quds - Premium Food Trading Since 1966',
        custom: ''
    };
    document.getElementById('waMessage').value = t[tpl] || '';
}

document.getElementById('waSendBtn').addEventListener('click', async () => {
    if (!curWA) return;
    const msg = document.getElementById('waMessage').value.trim();
    if (!msg) { toast('Please enter a message', true); return; }
    const res = await ajax('send_whatsapp', { order_id: curWA.idx, phone: curWA.phone, message: msg });
    if (res.ok) {
        toast('WhatsApp link opened');
        if (res.data.wa_url) window.open(res.data.wa_url, '_blank');
    } else toast(res.error || 'Failed', true);
    closeModal('whatsappModal');
});

async function printOrder(idx, id) {
    const res = await ajax('get_details', { order_id: idx });
    if (!res.ok) { toast('Error loading order', true); return; }
    openPrintWindow(res.data);
}

function printFromModal() {
    if (curModalOrd) ajax('get_details', { order_id: curModalOrd.idx }).then(res => { if (res.ok) openPrintWindow(res.data); });
}

function openPrintWindow(d) {
    let ih = '';
    (d.items || []).forEach((it, i) => {
        ih += '<tr><td style="padding:8px;border-bottom:1px solid #eee">' + (i+1) + '</td><td style="padding:8px;border-bottom:1px solid #eee">' + (it.name || it.slug) + '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:center">' + (it.qty || 1) + '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right">' + (it.price || '—') + '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:700">' + (parseFloat(it.price || 0) * parseInt(it.qty || 1)).toFixed(2) + ' JOD</td></tr>';
    });
    const w = window.open('', '_blank');
    w.document.write('<!DOCTYPE html><html><head><title>Invoice #' + d.id + ' - 7 Boys</title><style>body{font-family:system-ui,Arial,sans-serif;padding:40px;color:#222}.h{display:flex;justify-content:space-between;align-items:start;margin-bottom:30px;border-bottom:3px solid #053d20;padding-bottom:20px}.c h1{font-family:Georgia,serif;color:#053d20;margin:0;font-size:28px}.c p{color:#666;margin:4px 0;font-size:13px}.m{text-align:right}.m .id{font-size:24px;font-weight:700;color:#053d20}.dg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px}.dg .f{background:#f9fafb;border-radius:8px;padding:10px 14px}.dg .f label{display:block;font-size:11px;color:#888;text-transform:uppercase;font-weight:600;margin-bottom:3px}.dg .f span{font-size:14px;font-weight:600}table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#053d20;color:#fff;padding:10px 8px;text-align:left;font-size:13px}td{padding:8px;border-bottom:1px solid #eee;font-size:13px}.t{margin-top:20px;text-align:right}.t .g{font-size:22px;font-weight:700;color:#053d20}.sb{display:inline-block;padding:4px 14px;border-radius:999px;font-size:13px;font-weight:700}.ft{margin-top:40px;padding-top:16px;border-top:2px solid #eee;text-align:center;color:#888;font-size:12px}@media print{body{padding:20px}}</style></head><body><div class="h"><div class="c"><h1>7 Boys</h1><p>Rubu Al Quds - Premium Food Trading</p><p>Al-Abdali, Amman, Jordan</p><p>+962 79 581 6444</p></div><div class="m"><div class="id">Order #' + d.id + '</div><div style="color:#666;font-size:13px;margin-top:4px">' + (d.date || '') + '</div><div style="margin-top:8px"><span class="sb" style="background:' + (ST_C[d.status]?.bg || '#f3f4f6') + ';color:' + (ST_C[d.status]?.fg || '#374151') + '">' + (d.status || '').toUpperCase() + '</span></div></div></div><div class="dg"><div class="f"><label>Customer</label><span>' + (d.name || '—') + '</span></div><div class="f"><label>Email</label><span>' + (d.email || '—') + '</span></div><div class="f"><label>Phone</label><span>' + (d.phone || '—') + '</span></div><div class="f"><label>Company</label><span>' + (d.company || '—') + '</span></div></div><h3 style="color:#053d20;margin:0 0 8px">Order Items</h3><table><thead><tr><th>#</th><th>Product</th><th style="text-align:center">Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Subtotal</th></tr></thead><tbody>' + ih + '</tbody></table><div class="t"><div style="font-size:14px;color:#666">Total Items: <b>' + (d.item_count || 0) + '</b></div><div class="g">Total: ' + (d.total ? parseFloat(d.total).toFixed(2) + ' JOD' : '—') + '</div></div>' + (d.note ? '<div style="margin-top:20px"><h4 style="margin:0 0 4px;color:#053d20">Note</h4><p style="margin:0;font-size:14px;color:#555">' + d.note + '</p></div>' : '') + '<div class="ft"><p>7 Boys - Rubu Al Quds for Trading and Food Industries - Established 1966</p><p>Thank you for your business!</p></div><script>setTimeout(function(){window.print()},300)<\/script></body></html>');
    w.document.close();
}

function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); }); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show')); });
</script>
</body>
</html>
