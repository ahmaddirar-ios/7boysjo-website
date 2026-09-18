<?php
require_once 'config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }
if (isset($_GET['download'])) {
  $data = [
    'products' => load_products(),
    'brands'   => load_brands(),
    'cats'     => load_cats(),
    'settings' => load_settings(),
    'home'     => load_json(SITE_DIR . '/admin/data/home.json'),
    'messages' => load_json(SITE_DIR . '/admin/data/messages.json'),
    'exported_at' => date('Y-m-d H:i:s')
  ];
  header('Content-Type: application/json');
  header('Content-Disposition: attachment; filename="7boys-backup-' . date('Ymd-His') . '.json"');
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  exit;
}
header('Location: index.php?action=backup');
exit;
