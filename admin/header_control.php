<?php
/**
 * 7 Boys® — Header Control Panel
 * Lets you add/edit/delete Categories + toggle Brands in nav + edit CTA
 * All changes trigger auto_publish().
 */
function render_header_control($brands, $cats, $ext) {
  $cfg_file = SITE_DIR . '/admin/data/header_config.json';
  $cfg = load_json($cfg_file);
  // defaults
  $cfg = array_merge([
    'nav_categories' => array_column($cats, 'slug'),
    'nav_brands' => array_column($brands, 'slug'),
    'brands_in_dropdown' => 12,
    'products_per_dropdown' => 6,
    'cta_text' => 'Request a Quote',
    'cta_link' => '/quote.php',
    'cta_visible' => true,
    'dropdown_behavior' => 'hover', // hover|click
    'dropdown_animation' => 180, // ms
  ], $cfg);

  // Handle POST actions
  $msg = '';
  if ($_POST) {
    if (isset($_POST['save_header'])) {
      $cfg['nav_categories'] = $_POST['nav_categories'] ?? [];
      $cfg['nav_brands'] = $_POST['nav_brands'] ?? [];
      $cfg['brands_in_dropdown'] = (int)($_POST['brands_in_dropdown'] ?? 12);
      $cfg['products_per_dropdown'] = (int)($_POST['products_per_dropdown'] ?? 6);
      $cfg['cta_text'] = $_POST['cta_text'] ?? 'Request a Quote';
      $cfg['cta_link'] = $_POST['cta_link'] ?? '/quote.php';
      $cfg['cta_visible'] = isset($_POST['cta_visible']);
      $cfg['dropdown_behavior'] = $_POST['dropdown_behavior'] ?? 'hover';
      $cfg['dropdown_animation'] = (int)($_POST['dropdown_animation'] ?? 180);
      save_json($cfg_file, $cfg);
      auto_publish();
      $msg = 'Header configuration saved and site republished.';
    }
    if (isset($_POST['add_category'])) {
      $name = trim($_POST['cat_name'] ?? '');
      if ($name) {
        $slug = slugify($name);
        // check if exists
        $exists = false;
        foreach ($cats as $c) if (($c['slug'] ?? '') === $slug) $exists = true;
        if (!$exists) {
          $cats[] = ['slug' => $slug, 'name' => $name];
          save_cats($cats);
          $cfg['nav_categories'][] = $slug;
          save_json($cfg_file, $cfg);
          auto_publish();
          $msg = "Category '$name' added and site republished.";
        } else {
          $msg = "Category '$name' (slug: $slug) already exists.";
        }
      }
    }
    if (isset($_POST['delete_category'])) {
      $slug = $_POST['del_cat_slug'] ?? '';
      $cats = array_values(array_filter($cats, function($c) use ($slug){ return ($c['slug'] ?? '') !== $slug; }));
      save_cats($cats);
      $cfg['nav_categories'] = array_diff((array)$cfg['nav_categories'], [$slug]);
      save_json($cfg_file, $cfg);
      auto_publish();
      $msg = "Category deleted and site republished.";
    }
    // reload cats in case of change
    $cats = load_cats();
  }

  $nav_cats = $cats;
  ?>
  <h1 style="display:flex;align-items:center;gap:10px;">🎨 Header Control <span style="color:#7a7267;font-size:14px;font-weight:400;">(manage the main navigation + dropdowns)</span></h1>
  <?php if ($msg): ?>
    <div class="msg" style="margin:16px 0;"><?= esc($msg) ?></div>
  <?php endif; ?>

  <style>
  .hc-section { background:#fff; border:1px solid #e3dccb; border-radius:12px; padding:20px; margin:20px 0; box-shadow:0 1px 4px rgba(0,0,0,.05); }
  .hc-section h2 { color:#2e6b3e; font-family:"Sora",sans-serif; font-size:20px; margin-bottom:16px; border-bottom:2px solid #e3dccb; padding-bottom:10px; }
  .hc-row { display:flex; align-items:center; gap:12px; margin:8px 0; padding:10px 12px; border-radius:8px; background:#f5f5f7; }
  .hc-row label { font-weight:600; min-width:180px; color:#2b2417; }
  .hc-row input[type="text"], .hc-row input[type="number"] { padding:8px 12px; border:1px solid #d9d2c5; border-radius:6px; flex:1; font-size:14px; }
  .hc-row input[type="range"] { flex:1; }
  .hc-toggle { display:flex; align-items:center; gap:8px; }
  .hc-toggle input[type="checkbox"] { width:16px; height:16px; cursor:pointer; }
  .hc-list { list-style:none; padding:0; margin:0; }
  .hc-list li { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px dashed #e3dccb; }
  .hc-list li:last-child { border-bottom:none; }
  .hc-list .drag-handle { cursor:grab; color:#7a7267; font-size:16px; }
  .hc-list .actions a, .hc-list .actions button { margin-left:8px; font-size:12px; padding:4px 10px; border-radius:4px; text-decoration:none; border:1px solid #d9d2c5; background:#fff; cursor:pointer; }
  .hc-list .actions a:hover, .hc-list .actions button:hover { background:#e7e0d3; }
  .hc-list .actions .del { color:#c0392b; border-color:#c0392b; }
  .hc-list .actions .del:hover { background:#fdecea; }
  .hc-badge { display:inline-block; background:#e8f5e9; color:#2e7d32; font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600; }
  .hc-actions { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; }
  .btn-hc { background:#2e6b3e; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
  .btn-hc:hover { background:#255e2e; }
  .btn-hc.secondary { background:#fff; color:#2e6b3e; border:1px solid #2e6b3e; }
  .btn-hc.secondary:hover { background:rgba(46,107,62,.06); }
  .hc-addform { display:grid; grid-template-columns:1fr auto; gap:12px; margin-top:12px; align-items:end; }
  .hc-addform input[type="text"] { padding:10px 14px; border:1px solid #d9d2c5; border-radius:6px; font-size:15px; }
  .hc-addform button { padding:10px 18px; border:none; background:#c0a040; color:#2b2417; border-radius:6px; font-weight:700; cursor:pointer; }
  .hc-addform button:hover { background:#a88b2f; }
  .msg { background:rgba(46,107,62,.08); border:1px solid #c8e6c9; color:#1b5e20; padding:10px 14px; border-radius:8px; }
  @media (max-width: 600px) { .hc-addform { grid-template-columns:1fr; } }
  </style>

  <form method="POST" action="index.php?action=header_control">
    <!-- Categories -->
    <div class="hc-section">
      <h2>🗂️ Categories in Navigation</h2>
      <p style="color:#7a7267;font-size:13px;">Reorder by drag-handle. Toggle visibility. Add new below.</p>
      <ul class="hc-list" id="catList">
        <?php foreach ($nav_cats as $slug):
          $cat_info = null;
          foreach ($cats as $c) if (($c['slug'] ?? '') === $slug) { $cat_info = $c; break; }
          if (!$cat_info) continue;
        ?>
        <li data-slug="<?= esc($slug) ?>">
          <span class="drag-handle" title="Drag to reorder">≡</span>
          <input type="hidden" name="nav_categories[]" value="<?= esc($slug) ?>">
          <span class="badge"><?= esc($cat_info['name']) ?> <span class="badge-small">(<?= esc($slug) ?>)</span></span>
          <div class="actions">
            <a href="#delCat" onclick="if(confirm('Delete category <?= esc($cat_info['name']) ?>? This removes it from nav + categories file.'))document.getElementById('del_cat_slug').value='<?= esc($slug) ?>'; document.querySelector('[name=delete_category]').click();">🗑️</a>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>

      <!-- Add new category -->
      <div class="hc-addform" style="margin-top:16px;">
        <input type="text" name="cat_name" placeholder="New category name (e.g. Gourmet & Deli)" required>
        <button type="submit" name="add_category" class="btn-hc secondary">+ Add Category</button>
      </div>
    </div>

    <!-- Brands -->
    <div class="hc-section">
      <h2>🏷️ Brands Dropdown</h2>
      <p style="color:#7a7267;font-size:13px;">All Brands dropdown — limit to <input type="number" name="brands_in_dropdown" value="<?= $cfg['brands_in_dropdown'] ?>" min="3" max="30" style="width:60px;" onchange="this.form.dispatchEvent(new Event('input'))"> brands shown (rest in "View All").</p>
      <div class="hc-row">
        <label>Dropdown style</label>
        <select name="dropdown_behavior" style="padding:8px 12px;border:1px solid #d9d2c5;border-radius:6px;font-size:14px;">
          <option value="hover" <?= ($cfg['dropdown_behavior']==='hover'?'selected':'') ?>>Hover (desktop)</option>
          <option value="click" <?= ($cfg['dropdown_behavior']==='click'?'selected':'') ?>>Click (better for mobile)</option>
        </select>
      </div>
      <div class="hc-row">
        <label>Animation speed</label>
        <input type="range" name="dropdown_animation" min="0" max="500" step="10" value="<?= $cfg['dropdown_animation'] ?>">
        <span style="min-width:50px;"><span id="animVal"><?= $cfg['dropdown_animation'] ?>ms</span></span>
      </div>

      <h3 style="margin:16px 0 8px;color:#2b2417;font-size:14px;">Which brands appear in the dropdown?</h3>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">
        <?php foreach ($brands as $b): $in = in_array($b['slug'], (array)$cfg['nav_brands']); ?>
        <label class="hc-toggle" style="margin:0;">
          <input type="checkbox" name="nav_brands[]" value="<?= esc($b['slug']) ?>" <?= $in ? 'checked' : '' ?>>
          <span><?= esc($b['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="hc-row">
        <label>Products per dropdown</label>
        <input type="number" name="products_per_dropdown" value="<?= $cfg['products_per_dropdown'] ?>" min="1" max="12" style="width:60px;">
      </div>
    </div>

    <!-- CTA Button -->
    <div class="hc-section">
      <h2>📞 Header CTA Button</h2>
      <div class="hc-row">
        <label>Button text</label>
        <input type="text" name="cta_text" value="<?= esc($cfg['cta_text']) ?>">
      </div>
      <div class="hc-row">
        <label>Redirect link</label>
        <select name="cta_link" style="padding:8px 12px;border:1px solid #d9d2c5;border-radius:6px;font-size:14px;">
          <option value="/quote.php" <?= ($cfg['cta_link']==='/quote.php'?'selected':'') ?>>Request a Quote (quote.php)</option>
          <option value="/contact.php" <?= ($cfg['cta_link']==='/contact.php'?'selected':'') ?>>Contact Page (contact.php)</option>
          <option value="/brands.html" <?= ($cfg['cta_link']==='/brands.html'?'selected':'') ?>>Brands Page (brands.html)</option>
          <option value="/categories/" <?= ($cfg['cta_link']==='/categories/'?'selected':'') ?>>Categories Landing</option>
        </select>
      </div>
      <div class="hc-row">
        <label>Show on mobile?</label>
        <input type="checkbox" name="cta_visible" value="1" <?= !empty($cfg['cta_visible'])?'checked':'' ?>>
      </div>
    </div>

    <!-- Publish -->
    <input type="hidden" name="delete_category" value="1" style="display:none;">
    <input type="hidden" id="del_cat_slug">
    <div class="hc-actions">
      <button type="submit" name="save_header" class="btn-hc">💾 Save Header Config + Publish</button>
      <a href="index.php?action=dashboard" class="btn-hc secondary">← Back to Dashboard</a>
    </div>
  </form>

  <script>
  // Update animation label live
  document.querySelector('input[name="dropdown_animation"]').addEventListener('input', function(e){
    document.getElementById('animVal').textContent = e.target.value + 'ms';
  });
  </script>
  <?php
}
?>