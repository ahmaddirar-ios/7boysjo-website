<?php
require_once 'config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }
$products = normalize_products(load_products());
$header = ['name','brand','cat','origin','desc','price','stock','featured','image','seo_title','seo_desc'];
$fp = fopen('php://temp', 'r+');
fputcsv($fp, $header);
foreach ($products as $p) {
  $row = [];
  foreach ($header as $h) $row[] = $p[$h] ?? '';
  fputcsv($fp, $row);
}
rewind($fp);
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="7boys-products-' . date('Ymd-His') . '.csv"');
fpassthru($fp);
exit;
