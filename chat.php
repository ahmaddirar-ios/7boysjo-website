<?php
// 7 Boys® Smart Chat — reads live catalog (admin/data/products.json) and returns matching products.
// Matches Arabic + English (name, brand, ar_name, ar_desc), word by word.
header('Content-Type: application/json; charset=utf-8');
$q = trim($_GET['q'] ?? '');
if ($q === '') {
  echo json_encode(['reply' => 'مرحباً! اسألني عن أي منتج أو براند عندنا 🍫', 'cards' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$dataFile = __DIR__ . '/admin/data/products.json';
$products = [];
if (file_exists($dataFile)) {
  $raw = json_decode(file_get_contents($dataFile), true);
  if (is_array($raw)) {
    $products = isset($raw['products']) && is_array($raw['products']) ? $raw['products'] : $raw;
  }
}

function slugify_chat($s) {
  $s = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($s, 'UTF-8'));
  return trim($s, '-');
}

$qLower = mb_strtolower($q, 'UTF-8');
$syn = ['مشروم'=>'mushroom فطر','ميونيز'=>'مايونيز','كاتشاب'=>'كاتشب','همبرجر'=>'همبرغر','بيتزا'=>'البيتزا','شيبس'=>'chips','جبنه'=>'جبنة','بندوره'=>'بندورة','زتون'=>'زيتون'];
$words = array_values(array_filter(preg_split('/\s+/u', $qLower), fn($w) => mb_strlen($w, 'UTF-8') >= 2));
foreach ($words as $w) if (isset($syn[$w])) foreach (preg_split('/\s+/u', $syn[$w]) as $sw) $words[] = $sw;
$words = array_values(array_unique($words));
if (empty($words)) $words = [$qLower];
$matches = [];
foreach ($products as $p) {
  $name = $p['name'] ?? '';
  $brand = $p['brand'] ?? '';
  $hay = mb_strtolower(
    $name . ' ' . $brand . ' ' . ($p['ar_name'] ?? '') . ' ' . ($p['ar_desc'] ?? '') . ' ' . ($p['cat'] ?? ''),
    'UTF-8'
  );
  if ($hay === '') continue;
  $score = 0;
  foreach ($words as $w) {
    if (strpos($hay, $w) !== false) $score++;
  }
  if ($score > 0) $matches[] = ['p' => $p, 's' => $score];
  if (count($matches) >= 30) break;
}
usort($matches, fn($a, $b) => $b['s'] <=> $a['s']);
$matches = array_slice(array_column($matches, 'p'), 0, 6);

if (empty($matches)) {
  echo json_encode([
    'reply' => 'ما لقيت شي يطابق "' . $q . '" — جرّب: فطر، زيتون، مايونيز، نكتار، عصير، معكرونة.',
    'cards' => []
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$cards = [];
foreach ($matches as $p) {
  $id = $p['id'] ?? 0;
  $name = $p['name'] ?? '';
  $slug = slugify_chat($name) . '-' . $id;
  $cards[] = [
    'name' => $name,
    'type' => 'product',
    'meta' => $p['brand'] ?? '',
    'img'  => isset($p['image']) ? '/assets/img/' . $p['image'] : '',
    'url'  => '/product.php?slug=' . $slug
  ];
}
echo json_encode([
  'reply' => 'لقيت ' . count($cards) . ' نتيجة لـ "' . $q . '":',
  'cards' => $cards
], JSON_UNESCAPED_UNICODE);
