<?php
// Dynamic home page — renders on every request (no static HTML build needed)
require_once __DIR__ . '/admin/config.php';
echo render_home();
