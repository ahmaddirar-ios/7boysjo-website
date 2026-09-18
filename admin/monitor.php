<?php
// 7 Boys uptime monitor - run via cron every 5 min. CLI only (or ?key=CRON_KEY).
define('MON_KEY', '7b-mon-1966');
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== MON_KEY && !defined('ADMIN_AREA')) { http_response_code(403); exit; }
$root = '/home/u144908550/domains/7boysjo.com';
if (!defined('SITE_DIR')) require $root . '/public_html/admin/config.php';
function mon_run() {
$state_f = $root . '/public_html/admin/data/monitor.json';
$state = file_exists($state_f) ? (json_decode(file_get_contents($state_f), true) ?: []) : [];
$GLOBALS['mon_checks'] = []; $checks =& $GLOBALS['mon_checks'];
function chk($name, $fn) {
  $checks =& $GLOBALS['mon_checks'];
  $t = microtime(true);
  try { $ok = $fn(); $checks[$name] = ['ok' => (bool)$ok, 'ms' => (int)((microtime(true) - $t) * 1000)]; }
  catch (Throwable $e) { $checks[$name] = ['ok' => false, 'ms' => 0, 'err' => substr($e->getMessage(), 0, 120)]; }
}
function httpok($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_NOBODY => false]);
  curl_exec($ch);
  $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $c >= 200 && $c < 400;
}
chk('home', function () { return httpok('https://7boysjo.com/'); });
chk('api', function () { return httpok('https://7boysjo.com/api/v1/products?limit=1'); });
chk('db', function () {
  $p = mysql_db();
  if (!$p) return false;
  return ((int)$p->query('SELECT COUNT(*) FROM ' . MYSQL_PREFIX . 'products')->fetchColumn()) > 1000;
});
chk('disk', function () {
  $free = disk_free_space('/home/u144908550/domains/7boysjo.com/public_html');
  return $free > 200 * 1024 * 1024;
});
chk('ssl', function () {
  $c = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
  $s = @stream_socket_client('ssl://7boysjo.com:443', $e, $es, 15, STREAM_CLIENT_CONNECT, $c);
  if (!$s) return false;
  $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'];
  fclose($s);
  $info = openssl_x509_parse($cert);
  return ($info['validTo_time_t'] - time()) > 7 * 86400;
});
$allok = true;
foreach ($checks as $c) if (!$c['ok']) $allok = false;
$prev = $state['ok'] ?? true;
$state = ['ok' => $allok, 'at' => date('c'), 'checks' => $checks, 'fails' => ($state['fails'] ?? 0)];
$to = null; $ncfg = notify_cfg();
$headers = "From: monitor@7boysjo.com\r\nContent-Type: text/plain; charset=UTF-8";
if ($prev && !$allok) {
  $bad = [];
  foreach ($checks as $k => $c) if (!$c['ok']) $bad[] = "$k(" . ($c['err'] ?? $c['ms'] . 'ms') . ')';
  notify_admin('monitor', '[7boysjo] SITE ISSUE: ' . implode(',', $bad), "Checks at " . date('c') . ":\n" . json_encode($checks, JSON_PRETTY_PRINT));
  $state['fails']++;
  $state['last_alert'] = date('c');
} elseif (!$prev && $allok) {
  notify_admin('monitor', '[7boysjo] Recovered', 'Site is back up at ' . date('c'));
}
file_put_contents($state_f, json_encode($state));
return ["ok" => $allok, "checks" => $checks]; }
if (php_sapi_name() === "cli" || ($_GET["key"] ?? "") === MON_KEY) { $r = mon_run(); echo json_encode($r) . "\n"; }
