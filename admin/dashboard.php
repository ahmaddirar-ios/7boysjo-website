<?php
/**
 * dashboard.php — Modern Admin Dashboard for 7 Boys® Premium Food Trading
 */

require_once 'config.php';

if (!is_logged_in()) { header('Location: login.php'); exit; }

// ---------- Load all data ----------
$brands = load_brands();
$products = normalize_products(load_products());
$cats = load_cats();
$quotes_data = load_json(SITE_DIR . '/admin/data/quotes.json');
$visitors = load_visitors();
$msgs = load_json(SITE_DIR . '/admin/data/messages.json');
$settings = load_settings();
$home = load_json(SITE_DIR . '/admin/data/home.json');
$mon = @json_decode(@file_get_contents(SITE_DIR . '/admin/data/monitor.json'), true);

// ---------- Stats ----------
$d_n_products = count($products);
$d_n_brands = count($brands);
$d_n_cats = count($cats);
$d_n_quotes = count($quotes_data);
$d_n_clients = count($visitors);
$d_pending = 0;
foreach ($quotes_data as $dq) { if (($dq['status'] ?? 'new') === 'new') $d_pending++; }

$d_hour = (int) date('G');
$d_greet = $d_hour < 12 ? 'Good morning' : ($d_hour < 18 ? 'Good afternoon' : 'Good evening');
$d_admin = function_exists('current_admin') ? (string) current_admin() : 'Admin';

// ---------- New this month ----------
$d_ym = date('Y-m');
$d_new_month = 0;
foreach ($visitors as $dv) {
  $ca = (string) ($dv['created_at'] ?? $dv['created'] ?? '');
  if (strlen($ca) >= 7 && substr($ca, 0, 7) === $d_ym) $d_new_month++;
}

// ---------- Monthly activity ----------
$d_months = [];
for ($mi = 5; $mi >= 0; $mi--) {
  $k = date('Y-m', strtotime('-' . $mi . ' months'));
  $d_months[$k] = ['lbl' => date('M', strtotime('-' . $mi . ' months')), 'quotes' => 0, 'clients' => 0];
}
foreach ($quotes_data as $dq) {
  $ca = (string) ($dq['created_at'] ?? $dq['created'] ?? $dq['date'] ?? '');
  $k = strlen($ca) >= 7 ? substr($ca, 0, 7) : '';
  if (isset($d_months[$k])) $d_months[$k]['quotes']++;
}
foreach ($visitors as $dv) {
  $ca = (string) ($dv['created_at'] ?? $dv['created'] ?? '');
  $k = strlen($ca) >= 7 ? substr($ca, 0, 7) : '';
  if (isset($d_months[$k])) $d_months[$k]['clients']++;
}

$d_qmax = 1;
foreach ($d_months as $dm) { $d_qmax = max($d_qmax, (int) $dm['quotes'], (int) $dm['clients']); }
foreach ([1, 2, 3, 4, 5, 6, 8, 10, 12, 15, 20, 30, 40, 50, 75, 100, 150, 200, 300, 500] as $nn) {
  if ($d_qmax <= $nn) { $d_qmax = $nn; break; }
}

// ---------- Category distribution ----------
$d_sub2par = [];
foreach (($cats ?? []) as $cc) {
  foreach (($cc['sub'] ?? []) as $ss) $d_sub2par[$ss['slug'] ?? ''] = $cc['slug'] ?? '';
}
$d_names = [];
foreach (($cats ?? []) as $cc) $d_names[$cc['slug'] ?? ''] = $cc['name'] ?? ($cc['slug'] ?? '');
$d_counts = [];
foreach (($products ?? []) as $pp) {
  $csl = $pp['cat'] ?? '';
  $par = $d_sub2par[$csl] ?? $csl;
  if ($par === '') $par = '(none)';
  $d_counts[$par] = ($d_counts[$par] ?? 0) + 1;
}
arsort($d_counts);
$d_ch_total = array_sum($d_counts);

// ---------- Recent quotes ----------
$d_recent_q = array_slice(array_reverse($quotes_data), 0, 8);

// ---------- Low stock ----------
$d_low_stock = [];
foreach ($products as $pi => $pp) {
  $stock = trim($pp['stock'] ?? '');
  if ($stock !== '' && is_numeric($stock) && (int) $stock < 5) {
    $d_low_stock[] = ['idx' => $pi, 'name' => $pp['name'], 'stock' => (int) $stock, 'brand' => $pp['brand'] ?? ''];
  }
  if (count($d_low_stock) >= 6) break;
}

// ---------- Top products (by brand popularity / order) ----------
$d_brand_pop = [];
foreach ($products as $pp) { $br = $pp['brand'] ?? ''; if ($br !== '') $d_brand_pop[$br] = ($d_brand_pop[$br] ?? 0) + 1; }
arsort($d_brand_pop);
$d_top_brands = array_slice($d_brand_pop, 0, 5, true);

// ---------- No image / duplicates / empty brands ----------
$d_noimg = 0;
foreach (($products ?? []) as $p) { $im = trim($p['image'] ?? ''); if ($im === '' || $im === 'placeholder.png') $d_noimg++; }

$d_seen = [];
$d_dupes = 0;
foreach (($products ?? []) as $p) {
  $k = strtolower(trim($p['name'] ?? ''));
  if ($k === '') continue;
  if (isset($d_seen[$k])) $d_dupes++; else $d_seen[$k] = 1;
}

$d_used = [];
foreach (($products ?? []) as $p) $d_used[$p['brand'] ?? ''] = true;
$d_empty_brands = 0;
foreach (($brands ?? []) as $b) { if (empty($d_used[$b['slug'] ?? ''])) $d_empty_brands++; }

$d_unread = 0;
foreach (($msgs ?? []) as $m) { if (($m['read'] ?? false) !== true) $d_unread++; }

$d_mon_ok = !empty($mon) && !empty($mon['ok']);
$d_mon_at = !empty($mon['at']) ? substr((string) $mon['at'], 0, 16) : '';

// ---------- Layout ----------
define('ADMIN_AREA', true);
$GLOBALS['G_active_action'] = 'dashboard';
$GLOBALS['G_page_title'] = 'Dashboard';
$GLOBALS['quotes_data'] = $quotes_data;
$GLOBALS['msgs'] = $msgs;

layout_start('Dashboard', $d_admin, $G_role ?? 'admin');
?>

<!-- Welcome Banner -->
<div class="welcome-banner anim-in">
  <div>
    <h2><?= esc($d_greet) ?>, <?= esc($d_admin) ?> 👋</h2>
    <p>Here's your business overview — track your catalog, quotes and growth.</p>
  </div>
  <div class="welcome-meta">
    <span class="welcome-pill"><i class="fas fa-chart-line"></i> Last 6 months</span>
    <?php if (!empty($mon)): ?>
    <span class="welcome-pill" style="background: <?= $d_mon_ok ? 'rgba(22,163,74,0.2)' : 'rgba(239,68,68,0.2)' ?>; border-color: <?= $d_mon_ok ? 'rgba(22,163,74,0.4)' : 'rgba(239,68,68,0.4)' ?>; color: <?= $d_mon_ok ? '#86efac' : '#fca5a5' ?>;">
      <?= $d_mon_ok ? '✔ Site OK' : '✘ Check needed' ?><?= $d_mon_at ? ' &middot; ' . esc($d_mon_at) : '' ?>
    </span>
    <?php endif; ?>
  </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid">
  <a class="stat-card anim-in" href="index.php?action=products">
    <div class="stat-icon green"><i class="fas fa-box-open"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $d_n_products ?></div>
      <div class="stat-label">Total Products</div>
      <div class="stat-trend up"><i class="fas fa-arrow-up"></i> across <?= $d_n_brands ?> brands</div>
    </div>
  </a>
  <a class="stat-card anim-in" href="index.php?action=brands">
    <div class="stat-icon gold"><i class="fas fa-tags"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $d_n_brands ?></div>
      <div class="stat-label">Total Brands</div>
      <div class="stat-trend up"><i class="fas fa-arrow-up"></i> in <?= $d_n_cats ?> categories</div>
    </div>
  </a>
  <a class="stat-card anim-in" href="index.php?action=quotes">
    <div class="stat-icon blue"><i class="fas fa-file-invoice"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $d_n_quotes ?></div>
      <div class="stat-label">Total Quotes</div>
      <div class="stat-trend <?= $d_pending > 0 ? 'down' : 'up' ?>"><i class="fas fa-<?= $d_pending > 0 ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= $d_pending ?> pending</div>
    </div>
  </a>
  <a class="stat-card anim-in" href="index.php?action=visitors">
    <div class="stat-icon purple"><i class="fas fa-users"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $d_n_clients ?></div>
      <div class="stat-label">Total Customers</div>
      <div class="stat-trend up"><i class="fas fa-arrow-up"></i> <?= $d_new_month ?> new this month</div>
    </div>
  </a>
</div>

<!-- Charts Row -->
<div class="grid-2">
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">📈 Activity Overview</div>
        <div class="card-subtitle">Quotes & new customers per month</div>
      </div>
      <div style="display:flex;gap:12px;font-size:12px;color:var(--text-muted);">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#c9a23f;margin-right:4px;vertical-align:middle;"></span>Quotes</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#3b82f6;margin-right:4px;vertical-align:middle;"></span>Clients</span>
      </div>
    </div>
    <div class="chart-wrap">
      <canvas id="activityChart"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">🥧 Catalog Distribution</div>
        <div class="card-subtitle"><?= $d_ch_total ?> products by category</div>
      </div>
    </div>
    <div class="chart-wrap">
      <canvas id="categoryChart"></canvas>
    </div>
  </div>
</div>

<!-- Data Row -->
<div class="grid-2">
  <!-- Recent Quotes -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">🧾 Recent Quotes</div>
        <div class="card-subtitle">Latest customer requests</div>
      </div>
      <a href="index.php?action=quotes" class="card-link">View all →</a>
    </div>
    <?php if (empty($d_recent_q)): ?>
      <p style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">No quotes yet — share your quote page to get started.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead><tr><th>Quote</th><th>Customer</th><th>Items</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($d_recent_q as $rq):
          $st = strtolower((string) ($rq['status'] ?? 'new'));
          $stc = $st === 'new' ? 'new' : (($st === 'viewed') ? 'viewed' : 'done');
          $its = $rq['items'] ?? [];
          $nic = is_array($its) ? count($its) : 0;
        ?>
        <tr>
          <td><b style="color:var(--brand);">#<?= esc((string) ($rq['id'] ?? '-')) ?></b></td>
          <td><?= esc((string) ($rq['name'] ?? $rq['customer'] ?? $rq['company'] ?? '-')) ?></td>
          <td><?= $nic > 0 ? $nic : '—' ?></td>
          <td><span class="badge-status <?= $stc ?>"><?= esc(ucfirst($st)) ?></span></td>
          <td style="color:var(--text-muted);"><?= esc(substr((string) ($rq['created_at'] ?? $rq['created'] ?? $rq['date'] ?? '-'), 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right column: Top + Low Stock + Quick Actions -->
  <div>
    <!-- Top Categories -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header">
        <div>
          <div class="card-title">🏆 Top Categories</div>
          <div class="card-subtitle">By product count</div>
        </div>
        <a href="index.php?action=cats" class="card-link">View all →</a>
      </div>
      <?php
      $d_top = array_slice($d_counts, 0, 5, true);
      $d_mx = max(1, max($d_counts ?: [1]));
      $d_pi = 0;
      foreach ($d_top as $csl => $cnt):
        $pc = round($cnt / max(1, $d_ch_total) * 100);
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;">
        <span style="width:110px;flex:none;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= esc($d_names[$csl] ?? $csl) ?></span>
        <div class="progress-bar" style="flex:1;"><div class="progress-fill" style="width:<?= round($cnt / $d_mx * 100) ?>%;"></div></div>
        <b style="width:36px;text-align:right;font-size:13px;color:var(--text);"><?= $cnt ?></b>
        <span style="width:38px;text-align:right;font-size:11.5px;color:var(--text-muted);"><?= $pc ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Low Stock Alerts -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header">
        <div>
          <div class="card-title">⚠️ Low Stock Alerts</div>
          <div class="card-subtitle">Products with stock &lt; 5</div>
        </div>
      </div>
      <?php if (empty($d_low_stock)): ?>
        <p style="text-align:center;padding:16px;color:#16a34a;font-weight:600;font-size:13px;">✓ All stock levels healthy</p>
      <?php else: ?>
        <?php foreach ($d_low_stock as $ls): ?>
        <div class="alert-item">
          <span class="dot"></span>
          <span class="lbl"><?= esc($ls['name']) ?></span>
          <span class="val" style="color:#dc2626;"><?= $ls['stock'] ?> left</span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Needs Attention -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header">
        <div>
          <div class="card-title">🔔 Needs Attention</div>
          <div class="card-subtitle">Items waiting for you</div>
        </div>
      </div>
      <?php
      $att_items = [
        ['lbl' => 'Products w/o image', 'n' => $d_noimg],
        ['lbl' => 'Duplicate names', 'n' => $d_dupes],
        ['lbl' => 'Brands w/o products', 'n' => $d_empty_brands],
        ['lbl' => 'Pending quotes', 'n' => $d_pending],
        ['lbl' => 'Unread messages', 'n' => $d_unread],
      ];
      $att_total = $d_noimg + $d_dupes + $d_empty_brands + $d_pending + $d_unread;
      if ($att_total <= 0):
      ?>
        <p style="text-align:center;padding:16px;color:#16a34a;font-weight:700;font-size:13px;">✔ All clear — nothing needs you.</p>
      <?php else: ?>
        <?php foreach ($att_items as $ai): ?>
        <div class="alert-item">
          <span class="dot <?= $ai['n'] > 0 ? '' : 'ok' ?>"></span>
          <span class="lbl"><?= esc($ai['lbl']) ?></span>
          <span class="val" style="font-size:12px;background:<?= $ai['n'] > 0 ? 'rgba(239,68,68,0.1);color:#dc2626;' : 'rgba(22,163,74,0.1);color:#16a34a;' ?>padding:2px 10px;border-radius:999px;"><?= $ai['n'] ?></span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">⚡ Quick Actions</div>
          <div class="card-subtitle">Common tasks</div>
        </div>
      </div>
      <div class="quick-actions">
        <a href="index.php?action=products" class="quick-btn primary"><i class="fas fa-plus"></i> Add Product</a>
        <a href="index.php?action=publish" class="quick-btn gold"><i class="fas fa-rocket"></i> Publish</a>
        <a href="index.php?action=backup" class="quick-btn outline"><i class="fas fa-database"></i> Backup</a>
        <a href="index.php?action=quotes" class="quick-btn outline"><i class="fas fa-file-invoice"></i> Quotes</a>
        <a href="index.php?action=csv_export" class="quick-btn outline"><i class="fas fa-download"></i> Export CSV</a>
      </div>
    </div>
  </div>
</div>

<?php
// ---------- Charts JS ----------
$chart_labels = json_encode(array_map(fn($m) => $m['lbl'], $d_months));
$chart_quotes = json_encode(array_map(fn($m) => (int) $m['quotes'], $d_months));
$chart_clients = json_encode(array_map(fn($m) => (int) $m['clients'], $d_months));

$cat_labels = json_encode(array_map(fn($k) => $d_names[$k] ?? $k, array_keys($d_counts)));
$cat_data = json_encode(array_values($d_counts));
$cat_colors = json_encode(['#c9a23f', '#2d6a4f', '#3b82f6', '#8b5cf6', '#ef4444', '#f59e0b', '#10b981', '#ec4899']);
?>

<?php
$GLOBALS['G_extra_scripts'] = '
<script>
(function() {
  // Activity Chart (Line)
  var ctx1 = document.getElementById("activityChart");
  if (ctx1) {
    new Chart(ctx1, {
      type: "line",
      data: {
        labels: ' . $chart_labels . ',
        datasets: [
          {
            label: "Quotes",
            data: ' . $chart_quotes . ',
            borderColor: "#c9a23f",
            backgroundColor: "rgba(201,162,63,0.12)",
            fill: true,
            tension: 0.4,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: "#c9a23f",
            pointBorderColor: "#fff",
            pointBorderWidth: 2,
            pointHoverRadius: 6
          },
          {
            label: "New Clients",
            data: ' . $chart_clients . ',
            borderColor: "#3b82f6",
            backgroundColor: "rgba(59,130,246,0.08)",
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 4,
            pointBackgroundColor: "#3b82f6",
            pointBorderColor: "#fff",
            pointBorderWidth: 2,
            pointHoverRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: "#1f2937",
            titleFont: { size: 13 },
            bodyFont: { size: 12 },
            padding: 10,
            cornerRadius: 8
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, color: "#6b7280" }
          },
          y: {
            grid: { color: "rgba(0,0,0,0.05)" },
            ticks: { font: { size: 11 }, color: "#6b7280", stepSize: Math.max(1, Math.round(' . $d_qmax . ' / 4)) },
            beginAtZero: true
          }
        }
      }
    });
  }

  // Category Chart (Doughnut)
  var ctx2 = document.getElementById("categoryChart");
  if (ctx2) {
    var catData = ' . $cat_data . ';
    var catLabels = ' . $cat_labels . ';
    var colors = ' . $cat_colors . ';
    // Limit to top 6 + rest
    var maxSlices = 6;
    if (catData.length > maxSlices) {
      var top = catData.slice(0, maxSlices);
      var rest = catData.slice(maxSlices).reduce(function(a, b) { return a + b; }, 0);
      catData = top.concat([rest]);
      catLabels = catLabels.slice(0, maxSlices).concat(["Other"]);
    }
    var bgColors = catData.map(function(_, i) { return colors[i % colors.length]; });

    new Chart(ctx2, {
      type: "doughnut",
      data: {
        labels: catLabels,
        datasets: [{
          data: catData,
          backgroundColor: bgColors,
          borderColor: "#fff",
          borderWidth: 3,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "65%",
        plugins: {
          legend: {
            position: "right",
            labels: {
              boxWidth: 12,
              padding: 12,
              font: { size: 12 },
              color: "#4b5563"
            }
          },
          tooltip: {
            backgroundColor: "#1f2937",
            padding: 10,
            cornerRadius: 8,
            callbacks: {
              label: function(ctx) {
                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                return ctx.label + ": " + ctx.parsed + " (" + pct + "%)";
              }
            }
          }
        }
      }
    });
  }
})();
</script>';
?>

<?php layout_end(); ?>
