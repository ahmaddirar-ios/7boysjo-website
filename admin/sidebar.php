<div class="mobilebar"><button class="menu-btn" onclick="document.querySelector(.sidebar).classList.toggle(open)">☰</button><img src="/assets/img/logo-header.png" alt="" style="width:26px;height:26px;object-fit:contain;background:#fff;border-radius:7px;padding:2px;vertical-align:-8px;margin-right:6px;"><b>7 Boys® Admin</b></div>
<div class="sidebar">
<div class="brand-head"><img src="/assets/img/logo-header.png" alt="7 Boys"><span>7 Boys®</span></div>
<a href="dashboard.php" class="<?= $action==="dashboard"?"active":"" ?>">🏠 Dashboard</a>
<a href="orders.php" class="<?= $action==="orders"?"active":"" ?>">🛒 Orders<?php $qc=count($quotes_data ?? []); if($qc): ?><span class="badge"><?= $qc ?></span><?php endif; ?></a>
<a href="index.php?action=products" class="<?= $action==="products"?"active":"" ?>">📦 Products</a>
<a href="index.php?action=brands" class="<?= $action==="brands"?"active":"" ?>">🏷️ Brands</a>
<a href="index.php?action=cats" class="<?= $action==="cats"?"active":"" ?>">🗂️ Categories</a>
<a href="inventory.php" class="<?= $action==="inventory"?"active":"" ?>">📊 Inventory</a>
<a href="customers.php" class="<?= $action==="customers"?"active":"" ?>">👥 Customers<?php $vc=count($visitors ?? []); if($vc): ?><span class="badge"><?= $vc ?></span><?php endif; ?></a>
<a href="catalog.php" class="">📚 Catalog</a>
<a href="index.php?action=home" class="<?= $action==="home"?"active":"" ?>">🖼️ Homepage</a>
<a href="theme.php" class="<?= $action==="theme"?"active":"" ?>">🎨 Theme</a>
<a href="seo.php" class="<?= $action==="seo"?"active":"" ?>">📈 SEO</a>
<a href="index.php?action=media" class="<?= $action==="media"?"active":"" ?>">🎞️ Media</a>
<a href="notifications.php" class="<?= $action==="notifications"?"active":"" ?>">🔔 Notifications</a>
<a href="index.php?action=messages" class="<?= $action==="messages"?"active":"" ?>">✉️ Messages<?php $unread=count(array_filter($msgs,fn($m)=>(($m[read]??false)!==true))); if($unread): ?><span class="badge"><?= $unread ?></span><?php endif; ?></a>
<a href="index.php?action=options" class="<?= $action==="options"?"active":"" ?>">🧩 Site Options</a>
<a href="index.php?action=visitors" class="<?= $action==="visitors"?"active":"" ?>">👥 Clients</a>
<a href="index.php?action=employees" class="<?= $action==="employees"?"active":"" ?>">🧑‍💼 Employees<?php $ec=count(load_json(SITE_DIR./admin/data/employees.json)); if($ec): ?><span class="badge"><?= $ec ?></span><?php endif; ?></a>
<a href="index.php?action=users" class="<?= $action==="users"?"active":"" ?>">👤 Users</a>
<a href="index.php?action=settings" class="<?= $action==="settings"?"active":"" ?>">⚙️ Settings</a>
<a href="index.php?action=backup" class="<?= $action==="backup"?"active":"" ?>">💾 Backup</a>
<a href="index.php?action=publish" class="<?= $action==="publish"?"active":"" ?>">🚀 Publish</a>
<a href="index.php?action=health" class="<?= $action==="health"?"active":"" ?>">🩺 Health</a>
<a href="index.php?action=audit" class="<?= $action==="audit"?"active":"" ?>">📜 Audit</a>
<a href="login.php?logout=1" class="logout">🚪 Logout</a>
</div>
<div class="main">
<form method="get" action="index.php" class="topsearch" style="margin:0 0 12px;display:flex;gap:8px;"><input type="hidden" name="action" value="search"><input type="text" name="q" value="<?= esc($_GET[q] ?? ) ?>" placeholder="🔍 Search products, brands, categories…" style="flex:1;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;"><button style="background:#053d20;color:#fff;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;">Search</button></form>
