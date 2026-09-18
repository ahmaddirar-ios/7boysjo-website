<?php
// Public API - serves live data for header search (WITHOUT price for visitors)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
$type = $_GET['type'] ?? 'products';
$allowed = ['products'=>'products.json','brands'=>'brands.json'];
$file = __DIR__ . '/../admin/data/' . ($allowed[$type] ?? 'products.json');
if (!file_exists($file)) { echo '[]'; exit; }
$data = json_decode(file_get_contents($file), true);
if ($type === 'products' && is_array($data)) {
  // strip price/stock before sending to frontend
  foreach ($data as &$p) { unset($p['price'], $p['stock']); }
}
echo json_encode($data, JSON_UNESCAPED_UNICODE);
