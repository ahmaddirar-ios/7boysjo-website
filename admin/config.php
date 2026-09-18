<?php
// 7 Boys Admin Panel - config
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'Admin@2026');
define('ADMIN_PASS_HASH', '$2y$10$peJE7wEtg9Y3FOPLOJHiNeyfO7Iq17hxzp3CJ/Or08r5U4iUkdEzi'); // hashed for verify
define('BRANDS_FILE', __DIR__ . '/data/brands.json');
define('PRODUCTS_FILE', __DIR__ . '/data/products.json');
define('CATS_FILE', __DIR__ . '/data/categories.json');
define('SETTINGS_FILE', __DIR__ . '/data/settings.json');
define('IMG_DIR', __DIR__ . '/../assets/img');
define('SITE_DIR', __DIR__ . '/..');
define('DB_FILE', __DIR__ . '/data/app.db');
// MySQL primary - loads from .env (never commit real password)
$__env = @parse_ini_file(dirname(__DIR__).'/../.env'); if(!$__env) $__env = @parse_ini_file(dirname(__DIR__,2).'/.env'); if(!$__env) $__env = @parse_ini_file(SITE_DIR.'/../.env');
define('MYSQL_DSN', 'mysql:host='.($__env['DB_HOST']??'127.0.0.1').';dbname='.($__env['DB_NAME']??'u144908550_7boys').';charset=utf8mb4');
define('MYSQL_USER', $__env['DB_USER']??'u144908550_ZiT2n');
define('MYSQL_PASS', $__env['DB_PASS']??'');
define('MYSQL_PREFIX', $__env['DB_PREFIX']??'7b_');
function mysql_db(){
  static $pdo=null;
  if($pdo){
    try{ $pdo->query('SELECT 1'); return $pdo; } catch(Exception $e){ $pdo=null; }
  }
  try{
    $pdo=new PDO(MYSQL_DSN, MYSQL_USER, MYSQL_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
  }catch(Exception $e){ return null; }
}
// auto daily backup fallback (if system cron not set - runs once per 24h on admin visit)
if(!defined('BACKUP_CHECKED')){ define('BACKUP_CHECKED',1); $bf='/home/u144908550/backups/.last_backup'; if(!file_exists($bf) || time()-filemtime($bf) > 86400){ @touch($bf); @exec('/bin/bash /home/u144908550/domains/7boysjo.com/backup_daily.sh >> /home/u144908550/backups/backup.log 2>&1 &'); }}
function db(){
  static $pdo=null;
  if($pdo) return $pdo;
  try{
    if(!file_exists(DB_FILE)) return null;
    $pdo=new PDO('sqlite:'.DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
  }catch(Exception $e){ return null; }
}
require_once __DIR__ . '/lang.php';
// Determine language: GET param > cookie > default en
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','ar'])) { $GLOBALS['lang'] = $_GET['lang']; }
elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['en','ar'])) { $GLOBALS['lang'] = $_COOKIE['lang']; }
else { $GLOBALS['lang'] = 'en'; }

define('SITE_URL', 'https://7boysjo.com');
define('VER_FILE', SITE_DIR . '/admin/data/version.json');
define('CSS_FILE', SITE_DIR . '/assets/css/hdr3.css'); // single canonical CSS file (no versioned rename)

// Secure session cookie: HttpOnly + SameSite=Lax + Secure
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
} else {
  @ini_set('session.cookie_httponly', '1');
  @ini_set('session.cookie_samesite', 'Lax');
}
@session_start();
// CSRF & Rate limit helpers
function csrf_token(){
  if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_check($t){
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t??'');
}
function rate_limit_check($key, $max=5, $window=300){
  $k='rl_'.$key;
  $now=time();
  $data=$_SESSION[$k]??['c'=>0,'t'=>$now];
  if($now-$data['t'] > $window) $data=['c'=>0,'t'=>$now];
  $data['c']++;
  $_SESSION[$k]=$data;
  return $data['c'] <= $max;
}
function rate_limit_reset($key){ unset($_SESSION['rl_'.$key]); }

// ---------- TOTP two-factor auth (RFC 6238, zero dependencies) ----------
define('TWOFA_FILE', __DIR__ . '/data/twofa.json');
function twofa_all() { $d = file_exists(TWOFA_FILE) ? json_decode(file_get_contents(TWOFA_FILE), true) : []; return is_array($d) ? $d : []; }
function twofa_get($u) { $a = twofa_all(); return $a[$u] ?? null; }
function totp_b32dec($b32) {
  $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $b32 = strtoupper(trim($b32));
  $bits = '';
  for ($i = 0; $i < strlen($b32); $i++) { $v = strpos($map, $b32[$i]); if ($v === false) continue; $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT); }
  $out = '';
  for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
  return $out;
}
function totp_code($secret, $t = null) {
  if ($t === null) $t = time();
  $ctr = pack('N*', 0, (int)floor($t / 30));
  $hash = hash_hmac('sha1', $ctr, totp_b32dec($secret), true);
  $o = ord($hash[19]) & 15;
  $c = ((ord($hash[$o]) & 127) << 24) | (ord($hash[$o+1]) << 16) | (ord($hash[$o+2]) << 8) | ord($hash[$o+3]);
  return str_pad((string)($c % 1000000), 6, '0', STR_PAD_LEFT);
}
function totp_verify($secret, $code) {
  $code = trim((string)$code);
  if ($code === '') return false;
  for ($w = -1; $w <= 1; $w++) { if (hash_equals(totp_code($secret, time() + $w * 30), $code)) return true; }
  return false;
}
function totp_new_secret() {
  $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = '';
  for ($i = 0; $i < 32; $i++) $s .= $a[random_int(0, 31)];
  return $s;
}

// Returns a fixed stylesheet path. We deliberately do NOT version/rename the
// CSS file: Hostinger hcdn ignores ?v=N and a rename desyncs version.json from
// the on-disk file (a missing hdrN.css makes the whole site unstyled, HTTP 200
// but returning index.html). The only reliable purge is to overwrite hdr3.css
// and force a republish, plus Purge All in hPanel Cache Manager.
function css_ver(){ return '3'; }
function asset_css(){ return '/assets/css/hdr3.css?v=' . filemtime(__DIR__ . '/../assets/css/hdr3.css'); }
function asset_js($f){ $p = __DIR__ . '/../assets/js/' . basename($f); return '/assets/js/' . basename($f) . '?v=' . (file_exists($p) ? filemtime($p) : '1'); }

// ---- 7 Boys notification center ----
function notify_cfg(){ $f = SITE_DIR . '/admin/data/notify.json'; $d = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; if (empty($d['emails'])) $d['emails'] = ['wael@7boys.com.jo']; if (!isset($d['wa']) || !is_array($d['wa'])) $d['wa'] = []; if (!isset($d['events']) || !is_array($d['events'])) $d['events'] = ['register'=>1,'quote'=>1,'contact'=>1,'monitor'=>1]; if (!isset($d['um'])) $d['um'] = ['instance'=>'','token'=>'']; return $d; }
function save_notify_cfg($d){ @file_put_contents(SITE_DIR . '/admin/data/notify.json', json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
function notify_admin($event, $subject, $body){
  $subject = trim(preg_replace('/[\r\n]+/', ' ', (string)$subject)); // header-injection guard: names flow into subjects
  $cfg = notify_cfg();
  if (empty($cfg['events'][$event])) return ['mail'=>0,'wa'=>0];
  $headers = "From: notify@7boysjo.com\r\nContent-Type: text/plain; charset=UTF-8";
  $m = 0;
  foreach ($cfg['emails'] as $to) { $to = trim($to); if ($to === '' || strpos($to, '@') === false) continue; if (@mail($to, $subject, $body, $headers)) $m++; }
  $w = 0;
  $inst = trim($cfg['um']['instance'] ?? ''); $tok = trim($cfg['um']['token'] ?? '');
  if ($inst !== '' && $tok !== '' && !empty($cfg['wa'])) {
    foreach ($cfg['wa'] as $num) {
      $num = preg_replace('/[^0-9]/', '', $num); if ($num === '') continue;
      $ch = curl_init('https://api.ultramsg.com/' . urlencode($inst) . '/messages/chat');
      curl_setopt_array($ch, ['POST'=>true,'RETURNTRANSFER'=>true,'TIMEOUT'=>12,'POSTFIELDS'=>http_build_query(['token'=>$tok,'to'=>$num,'body'=>'*'.$subject.'*'."\n".$body])]);
      $r = @curl_exec($ch); @curl_close($ch);
      if ($r && strpos($r, '"sent"') !== false) $w++;
    }
  }
  return ['mail'=>$m,'wa'=>$w];
}

function sw_ver_file(){ return SITE_DIR . '/admin/data/sw_ver.json'; }
function sw_ver(){ $f = sw_ver_file(); if (file_exists($f)) { $v = json_decode(file_get_contents($f), true); if (is_array($v) && !empty($v['v'])) return $v['v']; } return 1; }
function bump_sw(){ $v = time(); @file_put_contents(sw_ver_file(), json_encode(['v' => $v])); sw_write($v); return $v; }
function sw_write($v){ $sw = SITE_DIR . '/sw.js'; if (!file_exists($sw)) return; $t = file_get_contents($sw); $t = preg_replace("/const CACHE='7boys-[^']*'/", "const CACHE='7boys-" . $v . "'", $t); file_put_contents($sw, $t); }

function bump_css_ver(){ return '3'; }

define('VISITORS_FILE', __DIR__ . '/data/visitors.json');

function is_visitor_logged_in(){ return !empty($_SESSION['visitor_id']); }
function current_visitor(){ return $_SESSION['visitor'] ?? null; }

// === ENV ===
if(file_exists(SITE_DIR.'/.env')){ $lines=file(SITE_DIR.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); foreach($lines as $ln){ if(strpos(trim($ln),'#')===0) continue; if(strpos($ln,'=')===false) continue; list($k,$v)=explode('=', $ln, 2); $k=trim($k); $v=trim($v); if(!getenv($k)) putenv("$k=$v"); } }


// 2FA placeholder - enable per user: users.json -> "2fa_secret": "BASE32"
function verify_2fa($secret,$code){ if(empty($secret)) return true; // disabled
  // simple time-based 30s window - requires real TOTP lib for production
  return strlen($code)===6 && ctype_digit($code); }

function is_admin(){ return !empty($_SESSION['logged_in']); }
function admin_verify($p){ if(defined('ADMIN_PASS_HASH') && !empty(ADMIN_PASS_HASH)) return password_verify($p, ADMIN_PASS_HASH); return $p === ADMIN_PASS; }
function is_staff(){ return !empty($_SESSION['staff_id']); }
function current_staff(){ return $_SESSION['staff'] ?? null; }
function has_perm($perm){
  if(is_admin()) return true;
  if(!is_staff()) return false;
  $s=current_staff(); $perms=$s['perms']??[];
  // inactive staff has no perms
  if(($s['status']??'active')!=='active') return false;
  return in_array($perm,$perms);
}
function load_employees(){ return load_json(SITE_DIR.'/admin/data/employees.json'); }
function find_employee_by_username($u){
  foreach(load_employees() as $e) if(strcasecmp($e['username']??'',$u)===0) return $e;
  return null;
}
function load_visitors(){
  try{ $pdo=mysql_db(); if($pdo){ $st=$pdo->query('SELECT * FROM '.MYSQL_PREFIX.'visitors ORDER BY id'); if($st){ $rows=$st->fetchAll(); if($rows!==false && count($rows)>0) return $rows; } } }catch(Exception $e){}
  try{ $pdo=db(); if($pdo){ $st=$pdo->query('SELECT * FROM visitors ORDER BY id'); if($st){ $rows=$st->fetchAll(); if($rows!==false && count($rows)>0) return $rows; } } }catch(Exception $e){}
  return load_json(VISITORS_FILE);
}
function save_visitors($a){
  save_json(VISITORS_FILE, $a);
  try{ $pdo=mysql_db(); if($pdo){ $pdo->exec('DELETE FROM '.MYSQL_PREFIX.'visitors'); $st=$pdo->prepare('INSERT INTO '.MYSQL_PREFIX.'visitors (id,name,email,phone,company,customer_type,pass,created,created_at) VALUES (?,?,?,?,?,?,?,?,?)'); foreach($a as $v){ $st->execute([$v['id']??uniqid(),$v['name']??'',$v['email']??'',$v['phone']??'',$v['company']??'',$v['customer_type']??'',$v['pass']??'',$v['created']??'',$v['created_at']??$v['created']??'']); } } }catch(Exception $e){}
  try{ $pdo=db(); if($pdo){
    $pdo->exec('DELETE FROM visitors');
    $st=$pdo->prepare('INSERT INTO visitors (id,name,email,phone,company,customer_type,pass,created_at,created) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach($a as $i=>$v){ $st->execute([$v['id']??$i+1, $v['name']??'', $v['email']??'', $v['phone']??'', $v['company']??'', $v['customer_type']??'individual', $v['pass']??'', $v['created_at']??$v['created']??date('Y-m-d H:i:s'), $v['created']??$v['created_at']??date('Y-m-d H:i:s')]); }
  }}catch(Exception $e){}
  return true;
}
function find_visitor_by_email($email){
  foreach(load_visitors() as $v) if(strcasecmp($v['email']??'',$email)===0) return $v;
  return null;
}

function is_logged_in() { return !empty($_SESSION['logged_in']); }
function current_admin() { return $_SESSION['admin_user'] ?? 'admin'; }

function load_json($file) {
  if (!file_exists($file)) return [];
  $d = json_decode(file_get_contents($file), true);
  return is_array($d) ? $d : [];
}
function save_json($file, $data) {
  if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  // sync public copy for header search (live)
  $pub = dirname(__DIR__) . '/assets/data/' . basename($file);
  if (strpos($file, '/admin/data/') !== false && in_array(basename($file), ['products.json','brands.json'])) {
    if (!is_dir(dirname($pub))) mkdir(dirname($pub), 0755, true);
    @copy($file, $pub);
  }
}
function load_brands() { return load_json(BRANDS_FILE); }
function save_brands($b) { save_json(BRANDS_FILE, $b); }

// === FILE CACHE 60s (Redis alternative for shared hosting) ===
function cache_get($key){$f=SITE_DIR.'/admin/data/cache_'.$key.'.json'; if(!file_exists($f)) return null; if(time()-filemtime($f)>60) return null; $d=json_decode(file_get_contents($f),true); return $d;}
function cache_set($key,$data){$f=SITE_DIR.'/admin/data/cache_'.$key.'.json'; file_put_contents($f,json_encode($data));}
function cache_clear($key){$f=SITE_DIR.'/admin/data/cache_'.$key.'.json'; if(file_exists($f)) unlink($f);}

function load_products() {
  if(($c=cache_get('products'))!==null) return $c;
  try{ $pdo=mysql_db(); if($pdo){ $st=$pdo->query('SELECT * FROM '.MYSQL_PREFIX.'products ORDER BY id'); if($st){ $rows=$st->fetchAll(); if($rows){ foreach($rows as &$r){ $r['featured']=!empty($r['featured']); $r['desc']=$r['description']??$r['desc']??''; $r['order']=$r['order_idx']??0; $r['expiry_date']=$r['expiry_date']??null; $r['offer_price']=$r['offer_price']??null; } cache_set('products',$rows); return $rows; } } } }catch(Exception $e){}
  try{ $pdo=db(); if($pdo){ $st=$pdo->query("SELECT * FROM products ORDER BY id"); if($st){ $rows=$st->fetchAll(); if($rows){ foreach($rows as &$r){ $r['featured']=!empty($r['featured']); $r['desc']=$r['description']??$r['desc']??''; } cache_set('products',$rows); return $rows; } } } }catch(Exception $e){}
  $j=load_json(PRODUCTS_FILE); cache_set('products',$j); return $j;
}
function save_products($p) {
  if (function_exists('bump_sw')) bump_sw();
  audit_log('save_products', count($p).' products');
  cache_clear('products');
  save_json(PRODUCTS_FILE, $p);
  try{ $pdo=mysql_db(); if($pdo){ $pdo->exec('DELETE FROM '.MYSQL_PREFIX.'products'); $st=$pdo->prepare('INSERT INTO '.MYSQL_PREFIX.'products (id,slug,name,brand,cat,origin,description,price,stock,featured,image,seo_title,seo_desc,channel,created_at,order_idx,expiry_date,offer_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'); foreach($p as $v){ $ex=$v['expiry_date']??null; if($ex==='') $ex=null; $op=$v['offer_price']??null; if($op==='') $op=null; $st->execute([$v['id'],$v['slug']??'',$v['name']??'',$v['brand']??'',$v['cat']??'',$v['origin']??'',$v['description']??$v['desc']??'',$v['price']??'',$v['stock']??'',!empty($v['featured'])?1:0,$v['image']??'',$v['seo_title']??'',$v['seo_desc']??'',$v['channel']??'',$v['created_at']??null,$v['order']??0,$ex,$op]); } } }catch(Exception $e){ file_put_contents('/tmp/mysql_save_err.log', date('Y-m-d H:i:s')." save_products MySQL error: ".$e->getMessage()."\n", FILE_APPEND); }
  try{ $pdo=db(); if($pdo){
    $pdo->exec('DELETE FROM products');
    $st=$pdo->prepare('INSERT INTO products (id,slug,name,brand,cat,origin,description,price,stock,featured,image,seo_title,seo_desc,channel,expiry_date,offer_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach($p as $r){ $ex=$r['expiry_date']??null; if($ex==='') $ex=null; $op=$r['offer_price']??null; if($op==='') $op=null; $st->execute([$r['id']??null, $r['slug']??'', $r['name']??'', $r['brand']??'', $r['cat']??'', $r['origin']??'', $r['desc']??$r['description']??'', $r['price']??'', $r['stock']??'', !empty($r['featured'])?1:0, $r['image']??'', $r['seo_title']??'', $r['seo_desc']??'', $r['channel']??'', $ex, $op]); }
  }}catch(Exception $e){}
}
function load_cats() { $c=load_json(CATS_FILE); return $c ? $c : [['slug'=>'beverages','name'=>'Beverages'],['slug'=>'sweets','name'=>'Sweets & Confectionery'],['slug'=>'gourmet','name'=>'Gourmet & Deli'],['slug'=>'staples','name'=>'Staples & Basics']]; }
function save_cats($c) { save_json(CATS_FILE, $c); }
function load_settings() { $s=load_json(SETTINGS_FILE); return $s ? $s : ['phone'=>'+962 79 5816444','email'=>'wael@7boys.com.jo','address'=>'Al-Abdali, Amman, Jordan','facebook'=>'','instagram'=>'','whatsapp'=>'']; }
function save_settings($s) {
  if (function_exists('bump_sw')) bump_sw(); save_json(SETTINGS_FILE, $s); }
function load_users() {
  $f = __DIR__ . '/data/users.json';
  if (!file_exists($f)) return [];
  $u = json_decode(file_get_contents($f), true);
  return is_array($u) ? $u : [];
}
function save_users($u) { save_json(__DIR__ . '/data/users.json', $u); }


// === LOGGING ===

function audit_log($action,$details=""){ $f=SITE_DIR.'/admin/data/audit.log'; $u=$_SESSION['admin_user']??$_SESSION['staff']['username']??$_SESSION['staff_user']??'guest'; $line=date('Y-m-d H:i:s')." | $u | $action | $details\n"; file_put_contents($f,$line,FILE_APPEND); if(file_exists($f) && filesize($f)>800000) file_put_contents($f,substr(file_get_contents($f),-600000)); }

function app_log($msg,$level='info'){ $f=SITE_DIR.'/admin/data/app.log'; $line=date('Y-m-d H:i:s')." [$level] $msg\n"; file_put_contents($f,$line,FILE_APPEND); if(filesize($f)>500000) file_put_contents($f,substr(file_get_contents($f),-400000)); }

// ensure each product has an 'order' key for manual sorting
function normalize_products($products) {
  $changed = false;
  foreach ($products as $k => &$p) {
    if (!isset($p['order'])) { $p['order'] = $k; $changed = true; }
    if (!isset($p['featured'])) { $p['featured'] = 0; $changed = true; }
    if (!isset($p['price'])) { $p['price'] = ''; $changed = true; }
    if (!isset($p['stock'])) { $p['stock'] = ''; $changed = true; }
  }
  unset($p);
  if ($changed) save_products($products);
  return $products;
}

function next_num($arr, $prefix='brand-') {
  $max=0; foreach($arr as $x) if(preg_match('/'.preg_quote($prefix,'/').'(\d+)/',$x['image']??'',$m)) $max=max($max,(int)$m[1]);
  return $max+1;
}
function next_prod_num($products) { $max=0; foreach($products as $p) if(preg_match('/prod-(\d+)/',$p['image']??'',$m)) $max=max($max,(int)$m[1]); foreach(glob(IMG_DIR.'/prod-*.{jpg,jpeg,png,webp,svg}',GLOB_BRACE) as $f) if(preg_match('/prod-(\d+)/',basename($f),$m)) $max=max($max,(int)$m[1]); return $max+1; }
function slugify($s){ $s=strtolower(trim($s)); $s=preg_replace('/[^a-z0-9]+/','-',$s); return trim($s,'-'); }
function is_offer($p){
  if(empty($p['expiry_date'])) return false;
  $ts=strtotime($p['expiry_date']);
  if(!$ts) return false;
  $diff=($ts - time())/86400;
  return $diff >=0 && $diff <= 30;
}
function is_expired($p){
  if(empty($p['expiry_date'])) return false;
  $ts=strtotime($p['expiry_date']);
  return $ts && $ts < time();
}
function expiry_badge($p){
  if(empty($p['expiry_date'])) return '';
  if(is_expired($p)) return '<span class="badge-expired">Expired</span>';
  if(is_offer($p)){
    $d=(int)ceil((strtotime($p['expiry_date'])-time())/86400);
    return '<span class="badge-offer" style="background:#fff;color:#92400e;border:1px solid #fbbf24;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em">'.$d.' days left</span>';
  }
  return '';
}
function product_slug($p){ return slugify($p['name'] ?? 'product') . '-' . ($p['id'] ?? substr(md5($p['name'] ?? rand()),0,5)); }
function brand_name($brands, $slug){ foreach($brands as $b) if(($b['slug'] ?? '') === $slug) return $b['name']; return $slug; }
function esc($s){ return htmlspecialchars($s??'',ENT_QUOTES); }

// extended settings (certs, faq, whatsapp, channels)
function load_ext(){ $f = __DIR__.'/data/ext.json'; $d = load_json($f); return $d ? $d : ['certs'=>[],'faq'=>[],'whatsapp'=>'','channels'=>['horeca'=>'HORECA','retail'=>'Retail','wholesale'=>'Wholesale'],'map_embed'=>'','cookie_text'=>t('cookie')]; }
function save_ext($d){ save_json(__DIR__.'/data/ext.json', $d); }

// normalize product extra fields
function normalize_products_full($products) {
  $changed = false;
  foreach ($products as $k => &$p) {
    if (!isset($p['order'])) { $p['order'] = $k; $changed = true; }
    if (!isset($p['featured'])) { $p['featured'] = 0; $changed = true; }
    if (!isset($p['price'])) { $p['price'] = ''; $changed = true; }
    if (!isset($p['stock'])) { $p['stock'] = ''; $changed = true; }
    if (!isset($p['channel'])) { $p['channel'] = ''; $changed = true; }
    if (!isset($p['id'])) { $p['id'] = $k+1; $changed = true; }
  }
  unset($p);
  if ($changed) save_products($products);
  return $products;
}

// shared site chrome for admin-rendered public pages
function site_header_admin($nav){
  return '<header class="site-header"><div class="nav-inner"><a class="brand" href="/"><img src="/assets/img/logo.png" alt="Rubu Al Quds | 7 Boys" class="logo"></a><nav>' . $nav . '</nav></div></header>';
}
function site_footer_admin(){
  return '<footer class="site-footer"><div class="foot-inner"><div><img src="/assets/img/logo.png" alt="Rubu Al Quds | 7 Boys" class="foot-logo"><h4>'.t('footer_tag').'</h4><p>'.t('footer_desc').'</p></div>'
  . '<div><h4>'.t('footer_explore').'</h4><ul><li><a href="/categories/beverages.html">'.t('beverages').'</a></li><li><a href="/categories/sweets.html">'.t('sweets').'</a></li><li><a href="/categories/gourmet.html">'.t('gourmet').'</a></li><li><a href="/brands.html">'.t('brands').'</a></li></ul></div>'
  . '<div><h4>'.t('footer_contact').'</h4><ul><li><a href="tel:+962 79 5816444">+962 79 5816444</a></li><li><a href="mailto:wael@7boys.com.jo">wael@7boys.com.jo</a></li></ul></div></div>'
  . '<div class="foot-bottom"> &copy; 2026 Rubu Al Quds for Trading & Food Industries (7 Boys). All rights reserved.</div></footer>'
  
  . '<script src="'.asset_js('quote_cart.js').'"></script>' . '<script src="'.asset_js('chat.js').'"></script>' . '<script src="'.asset_js('lang.js').'"></script><script src="'.asset_js('header_search.js').'"></script><script src="'.asset_js('main.js').'"></script>';
}

function save_upload($field, $imgdir, $prefix, $num) {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) return false;
  $ext = strtolower(pathinfo($_FILES[$field]['name'],PATHINFO_EXTENSION));

  // --- SVG: stored as-is after stripping script vectors (not rasterizable via GD) ---
  if ($ext === 'svg') {
    $src = $_FILES[$field]['tmp_name'];
    $svg = file_get_contents($src);
    if ($svg === false || strpos($svg, '<svg') === false) return false;
    // strip dangerous elements/attrs (XSS guard)
    $svg = preg_replace('/<script[\s\S]*?<\/script>/i', '', $svg);
    $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);
    $svg = preg_replace('/javascript\s*:/i', '', $svg);
    $svg = preg_replace('/<foreignObject[\s\S]*?<\/foreignObject>/i', '', $svg);
    $svg = preg_replace('/data\s*:/i', '', $svg);
    // force a sane mime via XML header removal quirks; keep as .svg file
    $dest = $imgdir.'/'.$prefix.$num.'.svg';
    file_put_contents($dest, $svg);
    @chmod($dest, 0644);
    return $prefix.$num.'.svg';
  }

  // --- Raster images (jpg/png/webp): resize via GD ---
  $ext = in_array($ext,['jpg','jpeg'])?'jpg':($ext==='png'?'png':(in_array($ext,['webp'])?'webp':'jpg'));
  $base = $prefix.$num;
  if (file_exists($imgdir.'/'.$base.'.'.$ext)) $base .= '-'.substr(uniqid(),-6);
  if (preg_match('/^prod-\d+$/',$base)) $base .= '-'.substr(bin2hex(random_bytes(3)),0,6);
  $dest = $imgdir.'/'.$base.'.'.$ext;
  $src=$_FILES[$field]['tmp_name']; list($w,$h,$type)=getimagesize($src);
  if (!$w || !$h) return false;
  $im = $type===IMAGETYPE_PNG?imagecreatefrompng($src):($type===IMAGETYPE_WEBP?imagecreatefromwebp($src):imagecreatefromjpeg($src));
  $maxw=800; $rw=min($w,$maxw); $rh=(int)($rw/($w/$h));
  $o=imagecreatetruecolor($rw,$rh);
  // preserve transparency for PNG/WebP (black-background fix)
  if ($ext==='png' || $ext==='webp') {
    imagealphablending($o, false);
    imagesavealpha($o, true);
    $transparent = imagecolorallocatealpha($o, 0, 0, 0, 127);
    imagefilledrectangle($o, 0, 0, $rw, $rh, $transparent);
    imagealphablending($o, true);
  }
  imagecopyresampled($o,$im,0,0,0,0,$rw,$rh,$w,$h);
  if($ext==='png')imagepng($o,$dest,9); elseif($ext==='webp')imagewebp($o,$dest,85); else imagejpeg($o,$dest,85);
  @chmod($dest,0644);
  // also generate WebP for CDN (if not already webp)
  if($ext!=='webp'){
    $webpDest = $imgdir.'/'.$base.'.webp';
    @imagewebp($o,$webpDest,82);
    @chmod($webpDest,0644);
  }
  imagedestroy($im); imagedestroy($o);
  return basename($dest);
}

// ---- Brute-force lockout ----
define('MAX_ATTEMPTS', 5);
define('LOCK_MINUTES', 15);
define('ATTEMPTS_FILE', __DIR__ . '/data/login_attempts.json');
function get_attempts() { if(!file_exists(ATTEMPTS_FILE))return['count'=>0,'first'=>0,'lock_until'=>0]; $d=json_decode(file_get_contents(ATTEMPTS_FILE),true); return is_array($d)?$d:['count'=>0,'first'=>0,'lock_until'=>0]; }
function save_attempts($a){ if(!is_dir(dirname(ATTEMPTS_FILE)))mkdir(dirname(ATTEMPTS_FILE),0755,true); file_put_contents(ATTEMPTS_FILE,json_encode($a)); }
function is_locked_out(){ $a=get_attempts(); return (time()<($a['lock_until']??0)); }
function lock_remaining(){ $a=get_attempts(); return max(0,($a['lock_until']??0)-time()); }
function register_failed_attempt(){ $a=get_attempts(); $now=time(); if($now-($a['first']??0)>LOCK_MINUTES*60){$a['count']=0;$a['first']=$now;} $a['count']=($a['count']??0)+1; if($a['count']>=MAX_ATTEMPTS)$a['lock_until']=$now+LOCK_MINUTES*60; save_attempts($a); }
function clear_attempts(){ if(file_exists(ATTEMPTS_FILE))unlink(ATTEMPTS_FILE); }


function site_header($nav){
  $html = '<header class="site-header">';
  $html .= '<div class="nav-inner">';
  $html .= '<button class="nav-toggle" aria-label="Menu" onclick="document.body.classList.toggle(\'drawer-open\')"><span></span><span></span><span></span></button>';
  $html .= '<a class="brand" href="/"><img src="/assets/img/logo.png" alt="Rubu Al Quds | 7 Boys" class="logo"></a>';
  $html .= '<div class="header-actions">';
  $html .= '<div class="hdr-search" id="hdrSearch"><button class="hdr-search-btn" id="hdrSearchBtn" aria-label="Search" title="Search"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/></svg></button><div class="hdr-search-box" id="hdrSearchBox"><input id="hdrSearchInput" type="search" placeholder="Search products, brands..." autocomplete="off"><div id="hdrSearchResults" class="hdr-results"></div></div></div>';
  $html .= '<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" ><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><span>DARK</span></button>';
  if(is_visitor_logged_in()){ $v=current_visitor(); $html .= '<a class="hdr-auth" href="/account.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:999px;background:var(--green);color:#fff;font-weight:700;font-size:13px;text-decoration:none;white-space:nowrap">👤 '.esc($v['name']??'My Account').'</a>'; } elseif(!is_admin()){ $html .= '<a class="hdr-auth hdr-login" href="/login.php" style="display:inline-flex;align-items:center;padding:8px 18px;border-radius:999px;border:1.5px solid var(--line);color:var(--ink);font-weight:700;font-size:13px;text-decoration:none;background:#fff">Login</a><a class="hdr-auth hdr-register" href="/register.php" style="display:inline-flex;align-items:center;padding:8px 18px;border-radius:999px;background:var(--green);color:#fff;font-weight:700;font-size:13px;text-decoration:none;margin-left:6px">Sign up</a>'; } 
  $offersCount = count(array_filter(normalize_products_full(load_products()), fn($pp)=> is_offer($pp)));
  if($offersCount>0) $html .= '<a href="/offers.php" style="display:inline-flex;align-items:center;padding:7px 14px;border-radius:999px;border:1px solid #e8e2d6;background:#fff;color:#92400e;font-weight:700;font-size:12px;text-decoration:none;margin-right:4px">Offers <span style="background:#fef3c7;padding:1px 6px;border-radius:999px;margin-left:4px;font-size:11px">'.$offersCount.'</span></a>';
  $html .= '<a class="nav-cta" href="/quote.php" data-i18n="quote">'.t('quote').'</a>';
  $html .= '</div>';
  $html .= '</div></header>';

  // ---- Drawer (side menu) ----
  $html .= '<div class="drawer-overlay" onclick="document.body.classList.remove(\'drawer-open\')"></div>';
  $html .= '<aside class="drawer" aria-label="Main menu">';
  $html .= '<div class="drawer-head"><span class="drawer-title" data-i18n="explore_f">'.t('explore_f').'</span><button class="drawer-close" aria-label="Close" onclick="document.body.classList.remove(\'drawer-open\')">&times;</button></div>';
  $html .= '<div class="drawer-search" style="padding:12px 16px;border-bottom:1px solid var(--line)"><form action="/search.php" method="get" style="display:flex;gap:8px"><input name="q" placeholder="Search products..." style="flex:1;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px" /><button type="submit" style="padding:10px 14px;background:var(--green);color:#fff;border:none;border-radius:10px;font-weight:700">Search</button></form></div>';
  $html .= '<nav class="drawer-nav">';
  if (is_array($nav) && isset($nav['cats'])) {
    foreach ($nav['cats'] as $c) {
      $slug = $c['slug'];
      $label = $c['name'] ?? ucfirst($slug);
      $subs = $c['sub'] ?? [];
      if(!empty($subs)){
        $total=0; foreach($subs as $s) $total+=count($nav['cat_products'][$s['slug']] ?? []); if($total==0) continue;
        $html .= '<a class="dr-item dr-parent" href="/category.php?cat=' . esc($slug) . '" ><span class="dr-ico">&#128722;</span><span class="dr-label" data-i18n="'.esc($slug).'">' . esc($label) . '</span><span class="dr-count">' . $total . '</span><span class="dr-arrow">&#8250;</span></a>';
        $html .= '<div class="dr-sub" style="padding-left:16px">';
        foreach($subs as $s){
          $scount=count($nav['cat_products'][$s['slug']] ?? []);
          if($scount==0) continue;
          $html .= '<a class="dr-item dr-sub-item" href="/category.php?cat=' . esc($s['slug']) . '" style="padding-left:28px;background:#f9fafb"><span class="dr-label">' . esc($s['name']) . '</span><span class="dr-count">' . $scount . '</span></a>';
        }
        $html .= '</div>';
      } else {
        $count = count($nav['cat_products'][$slug] ?? []);
        if($slug==='best-offers'){ $count=count(array_filter(normalize_products_full(load_products()), fn($pp)=> is_offer($pp))); }
        if($count==0) continue;
        $html .= '<a class="dr-item" href="/category.php?cat=' . esc($slug) . '">';
        $html .= '<span class="dr-ico">&#128722;</span><span class="dr-label" data-i18n="'.esc($slug).'">' . esc($label) . '</span>';
        $html .= '<span class="dr-count">' . $count . '</span><span class="dr-arrow">&rsaquo;</span>';
        $html .= '</a>';
      }
    }
  }
  $html .= '</nav>';
  $html .= '<div class="drawer-auth" style="padding:12px 16px;border-top:1px solid var(--line);display:flex;gap:8px">'; 
  if(is_visitor_logged_in()){ $v2=current_visitor(); $html .= '<a href="/account.php" style="flex:1;text-align:center;padding:10px;border-radius:10px;background:var(--green);color:#fff;font-weight:700;text-decoration:none">👤 '.esc($v2['name']??'My Account').'</a><a href="/logout.php" style="padding:10px 14px;border-radius:10px;border:1px solid var(--line);color:var(--ink);text-decoration:none">Logout</a>'; } elseif(!is_admin()){ $html .= '<a href="/login.php" style="flex:1;text-align:center;padding:10px;border-radius:10px;border:1.5px solid var(--line);color:var(--ink);font-weight:700;text-decoration:none">Login</a><a href="/register.php" style="flex:1;text-align:center;padding:10px;border-radius:10px;background:var(--green);color:#fff;font-weight:700;text-decoration:none">Sign up</a>'; }
  $html .= '</div>'; 
  $html .= '<div class="drawer-foot"><a href="/story.php" data-i18n="our_story">'.t('our_story').'</a><a href="/brands.php" data-i18n="brands">'.t('brands').'</a><a href="/contact.php" data-i18n="contact">'.t('contact').'</a></div>';
  $html .= '</aside>';

  $html .= '<script>(function(){
    var t=document.documentElement;
    function applyDir(l){ t.setAttribute("dir", l==="ar"?"rtl":"ltr"); t.setAttribute("lang", l); }
    function applyTheme(th){
      t.setAttribute("data-theme",th);
      var b=document.getElementById("themeToggle");
      if(b){var s=b.querySelector("span");if(s)s.textContent=th==="dark"?"LIGHT":"DARK";}
    }
    var sv=localStorage.getItem("theme");
    if(sv){ applyTheme(sv); } else { applyTheme("light"); }
    applyDir("en");
    window.__toggleTheme=function(){var c=t.getAttribute("data-theme")==="dark"?"light":"dark";localStorage.setItem("theme",c);applyTheme(c);};
    
    var hsb=document.getElementById("hdrSearchBtn");if(hsb)hsb.addEventListener("click",function(e){e.stopPropagation();var w=document.getElementById("hdrSearch");if(!w)return;w.classList.toggle("open");if(w.classList.contains("open")){var ii=document.getElementById("hdrSearchInput");if(ii)ii.focus();}});var th=document.getElementById("themeToggle");if(th)th.addEventListener("click",function(e){e.preventDefault();if(window.__toggleTheme)window.__toggleTheme();});
  })();</script>';
  return $html;
}

function site_footer(){
  return '<footer class="site-footer"><div class="foot-inner"><div><img src="/assets/img/logo.png" alt="Rubu Al Quds | 7 Boys" class="foot-logo"><h4 data-i18n="footer_tag">'.t('footer_tag').'</h4><p data-i18n="footer_desc">'.t('footer_desc').'</p></div>'
  . '<div><h4 data-i18n="footer_explore">'.t('footer_explore').'</h4><ul><li><a href="/category.php?cat=beverages" data-i18n="beverages">'.t('beverages').'</a></li><li><a href="/category.php?cat=sweets" data-i18n="sweets">'.t('sweets').'</a></li><li><a href="/category.php?cat=food" data-i18n="food">'.t('food').'</a></li><li><a href="/category.php?cat=pet-food" data-i18n="pet-food">'.t('pet-food').'</a></li><li><a href="/brands.php" data-i18n="brands">'.t('brands').'</a></li></ul></div>'
  . '<div><h4 data-i18n="footer_contact">'.t('footer_contact').'</h4><ul><li><a href="tel:+962 79 5816444">+962 79 5816444</a></li><li><a href="mailto:wael@7boys.com.jo">wael@7boys.com.jo</a></li><li>Al-Abdali, Amman, Jordan</li></ul></div></div>'
  . '<div class="foot-bottom" data-i18n="rights"> &copy; 2026 Rubu Al Quds for Trading & Food Industries (7 Boys). All rights reserved.</div></footer>'
  
  . '<script src="'.asset_js('quote_cart.js').'"></script>' . '<script src="'.asset_js('chat.js').'"></script>' . '<script src="'.asset_js('lang.js').'"></script><script src="'.asset_js('header_search.js').'"></script><script src="'.asset_js('main.js').'"></script>'
  . (function_exists('whatsapp_float') ? whatsapp_float($ext) : '')
  . (function_exists('mobile_bottom_bar') ? mobile_bottom_bar($ext) : '')
  . (function_exists('cookie_bar') ? cookie_bar($ext) : '');
}
function mobile_bottom_bar($ext){
  $wa = preg_replace('/[^0-9]/','',$ext['whatsapp'] ?? '962795816444');
  $cur = basename($_SERVER["SCRIPT_NAME"] ?? "index.php");
  $a_home = ($cur === "index.php" || $cur === "") ? " active" : "";
  $a_brands = ($cur === "brands.php") ? " active" : "";
  $a_cart = ($cur === "quote.php") ? " active" : "";
  $a_acc = ($cur === "account.php") ? " active" : "";
  return '<nav class="mob-bottom-bar" aria-label="Mobile navigation"><span class="mbb-indicator" aria-hidden="true"></span>'
    .'<a href="/" class="mbb-item'.$a_home.'"><span class="mbb-ico">🏠</span><span>Home</span></a>'
    .'<a href="/brands.php" class="mbb-item'.$a_brands.'"><span class="mbb-ico">🏷️</span><span>Brands</span></a>'
    .'<a href="/quote.php" class="mbb-item'.$a_cart.'"><span class="mbb-ico">🛒</span><span>Cart</span></a>'
    .'<a href="https://wa.me/'.$wa.'" target="_blank" rel="noopener" class="mbb-item mbb-wa"><span class="mbb-ico">💬</span><span>WhatsApp</span></a>'
    .'<a href="/account.php" class="mbb-item'.$a_acc.'"><span class="mbb-ico">👤</span><span>Account</span></a>'
    .'</nav>'
    .'<script>(function(){function mbbMove(el){var bar=document.querySelector(".mob-bottom-bar");if(!bar||!el)return;var ind=bar.querySelector(".mbb-indicator");if(!ind)return;ind.style.left=el.offsetLeft+"px";ind.style.width=el.offsetWidth+"px";ind.style.opacity="1";}function mbbInit(){var bar=document.querySelector(".mob-bottom-bar");if(!bar)return;var act=bar.querySelector(".mbb-item.active")||bar.querySelector(".mbb-item");mbbMove(act);}document.addEventListener("DOMContentLoaded",mbbInit);window.addEventListener("pageshow",mbbInit);window.addEventListener("load",mbbInit);window.addEventListener("resize",mbbInit);document.addEventListener("click",function(e){var t=e.target;var a=t&&t.closest?t.closest(".mbb-item"):null;if(!a||!a.closest(".mob-bottom-bar"))return;if(a.target==="_blank")return;var bar=a.closest(".mob-bottom-bar");bar.querySelectorAll(".mbb-item").forEach(function(x){x.classList.remove("active")});a.classList.add("active");mbbMove(a);},true);})();</script>';
}
function whatsapp_float($ext){
  $wa = $ext['whatsapp'] ?? '';
  if (!$wa) return '';
  $wa = preg_replace('/[^0-9]/','',$wa);
  $mainBtn = '<button class="fab-main" aria-label="Quick actions" onclick="document.body.classList.toggle(\'fab-open\')"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>';
  $waLink  = '<a class="wa-float" href="https://wa.me/'.$wa.'" target="_blank" rel="noopener" title="Chat on WhatsApp"><svg viewBox="0 0 32 32" width="28" height="28" fill="#fff"><path d="M16 .4C7.4.4.4 7.4.4 16c0 2.8.7 5.5 2.1 7.9L.4 31.6l7.9-2c2.3 1.3 4.9 2 7.7 2 8.6 0 15.6-7 15.6-15.6S24.6.4 16 .4zM16 28c-2.4 0-4.7-.7-6.7-1.9l-.5-.3-4.7 1.2 1.3-4.6-.3-.5C3.7 20 3 18 3 16 3 8.8 8.8 3 16 3s13 5.8 13 13-5.8 12-13 12zm7.2-9.8c-.4-.2-2.3-1.1-2.6-1.3-.3-.1-.6-.2-.8.2-.2.4-.8 1-1 1.2-.2.2-.4.2-.8.1-2.3-1.2-3.9-2.1-5.4-4.8-.4-.7.4-.7.8-1.9.1-.2 0-.4 0-.5 0-.1-.8-2-1-2.7-.3-.7-.6-.6-.8-.6h-.7c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.6 0 1.6 1.1 3.1 1.3 3.3.2.2 2.2 3.4 5.4 4.7.8.3 1.4.5 1.9.7.8.2 1.5.2 2.1.1.6-.1 2.3-.9 2.6-1.8.3-.9.3-1.6.2-1.8 0-.1-.3-.2-.7-.3z"/><\/svg><\/a>';
  $fabHide = '<style>body.fab-hidden .fab-main{opacity:0!important;pointer-events:none!important}</style><script>(function(){function fabCheck(){var f=document.querySelector(".fab-main");if(!f)return;var fr=f.getBoundingClientRect();var hide=false;document.querySelectorAll("footer.site-footer,#qForm button").forEach(function(el){if(!el||el.offsetParent===null)return;var r=el.getBoundingClientRect();if(!(fr.right<r.left||fr.left>r.right||fr.bottom<r.top||fr.top>r.bottom))hide=true;});document.body.classList.toggle("fab-hidden",hide);}var t;window.addEventListener("scroll",function(){cancelAnimationFrame(t);t=requestAnimationFrame(fabCheck);},{passive:true});window.addEventListener("resize",fabCheck);fabCheck();})();</script>';
  return '<div class="fab-group">'.$mainBtn.$waLink.'</div>'.$fabHide;
}


// ============================================================
//  DYNAMIC RENDER FUNCTIONS (replaces fragile do_publish HTML build)
//  Each returns a full HTML page string. Used by index.php, product.php, etc.
// ============================================================

function spec_table($p){ $pk=pack_info($p); $rows=''; $vol=''; if(preg_match('/(\d+(?:\.\d+)?\s*(?:ml|l|g|kg))/i',$p['name'].' '.($p['desc'] ?? ''),$m))$vol=trim($m[1]); if($vol!=='')$rows.='<tr><th>Size / Weight</th><td>'.esc($vol).'</td></tr>'; if($pk[0]>0)$rows.='<tr><th>Units per Case</th><td>'.esc($pk[0]).' pcs/ct</td></tr>'; if($pk[1]>0)$rows.='<tr><th>Cases per Pallet</th><td>'.esc($pk[1]).' ct/pal</td></tr>'; if(!empty($p['ean']))$rows.='<tr><th>EAN Barcode</th><td>'.esc($p['ean']).'</td></tr>'; if($rows==='')return ''; return '<table class="spec-table"><tbody>'.$rows.'</tbody></table>'; }
function pack_info($p){ $t=(($p["desc"] ?? "")." ".($p["description"] ?? "")); $pcs=0;$ctpal=0; if(preg_match("/pcs\/ct\s*([0-9]+)/i",$t,$m))$pcs=(int)$m[1]; if(preg_match("/ct\/pal\s*([0-9]+)/i",$t,$m))$ctpal=(int)$m[1]; return [$pcs,$ctpal]; }
function img_tag($file,$alt='',$extra=''){
static $wl=null;
if($wl===null){ $wl=array(); foreach(@glob(SITE_DIR.'/assets/img/*.webp')?:array() as $f) $wl[basename($f)]=1; }
$e=$extra?' '.ltrim($extra):''; $f=esc($file); $a=esc($alt);
if(isset($wl[$file.'.webp'])) return '<picture><source srcset="/assets/img/'.$f.'.webp" type="image/webp"><img src="/assets/img/'.$f.'" alt="'.$a.'" loading="lazy"'.$e.'></picture>';
return '<img src="/assets/img/'.$f.'" alt="'.$a.'" loading="lazy"'.$e.'>'; }
function sb_prod_card($p, $brands, $sub='all'){
  $bn = brand_name($brands, $p['brand'] ?? '');
  $slug = product_slug($p);
  $chan = '';
  if (!empty($p['channel']) && isset($GLOBALS['ext']['channels'][$p['channel']])) {
    $chan = '<span class="chan-chip">'.esc($GLOBALS['ext']['channels'][$p['channel']]).'</span>';
  }
  $badge = expiry_badge($p);
  $searchData = strtolower(esc($p['name']).' '.esc($bn).' '.esc($p['desc']).' '.esc($p['origin']).' '.esc($p['brand']));
  return '<a class="prod-card" href="/product.php?slug='.rawurlencode($slug).'" data-sub="'.esc($sub).'" data-brand="'.esc($p['brand']).'" data-origin="'.esc($p['origin']).'" data-name="'.esc($searchData).'" data-pcs="'.pack_info($p)[0].'" data-ctpal="'.pack_info($p)[1].'">'
    . '<div class="img-wrap">'.img_tag($p['image'],$p['name']).($badge ? '<div style="position:absolute;top:8px;left:8px">'.$badge.'</div>' : '').'</div>'
    . '<div class="body"><span class="tag">' . esc($bn) . '</span>'.$chan.'<h3>' . esc($p['name']) . '</h3>'
    . '<p>' . esc($p['desc']) . '</p><span class="origin-chip">' . esc($p['origin']) . '</span></div></a>';
}

if (!function_exists('sb_brand_logo_img')) {
function sb_brand_logo_img($b){
  if (!empty($b['image']) && file_exists(SITE_DIR . '/assets/img/' . $b['image'])) {
    return img_tag($b['image'],$b['name'],'class="brand-logo"');
  }
  return '<div class="brand-initial">' . esc(strtoupper(substr($b['name'],0,1))) . '</div>';
}
}

function sb_nav(){
  $products = normalize_products_full(load_products());
  $brands   = load_brands();
  $cats     = load_cats();
  $cat_products = [];
  foreach ($products as $p) { $cat_products[$p['category'] ?? $p['cat'] ?? 'all'][] = $p; }
  return ['cats' => $cats, 'brands' => $brands, 'cat_products' => $cat_products];
}

function render_home(){
  $products = normalize_products_full(load_products());
  $brands   = load_brands();
  $cats     = load_cats();
  $settings = load_settings();
  $home     = load_json(SITE_DIR . '/admin/data/home.json');
  $ext      = load_ext();
  $GLOBALS['ext'] = $ext;
  $nav = sb_nav();

  $home_c = '';
  $featured = array_filter($products, fn($p)=> !empty($p['featured']));
  if ($featured) { $home_list = array_slice(array_values($featured), 0, 10); }
  else { $home_list = []; $seenC = []; foreach ($products as $p) { $c = $p['cat'] ?? ''; if (isset($seenC[$c])) continue; $seenC[$c] = 1; $home_list[] = $p; } $seenB = []; foreach ($products as $p) { if (count($home_list) >= 10) break; $b = $p['brand'] ?? ''; if (isset($seenB[$b])) continue; $sk = array_search($p, $home_list); if ($sk !== false) continue; $seenB[$b] = 1; $home_list[] = $p; } if (count($home_list) < 6) $home_list = array_slice($products, 0, 10); }
  foreach ($home_list as $p) $home_c .= sb_prod_card($p, $brands);
  // loop: duplicate for seamless infinite scroll if enough items
  $is_loop = count($home_list) >= 3;
  $home_c_loop = $home_c; // no DOM duplication - JS loop handles it
  // beverages auto section - same design as featured (exclude featured to avoid duplication)
  $bev_list = array_values(array_filter($products, fn($p)=> ($p['cat']??'')==='beverages'));
  $bev_html = '';
  foreach (array_slice($bev_list, 0, 6) as $p) $bev_html .= sb_prod_card($p, $brands);
  $is_bev_loop = count($bev_list) >= 3;
  $bev_html_loop = $bev_html;
  // 7 Boys own-label section (exhibition)
  $own_list = array_values(array_filter($products, fn($p)=> ($p['brand']??'')==='7-boys'));
  $own_html = '';
  foreach (array_slice($own_list, 0, 10) as $p) $own_html .= sb_prod_card($p, $brands);
  // MAZZA requested-brand section
  $mazza_list = array_values(array_filter($products, fn($p)=> strtolower($p['brand']??'')==='mazza'));
  $mazza_html = '';
  foreach (array_slice($mazza_list, 0, 14) as $p) $mazza_html .= sb_prod_card($p, $brands);
  $is_mazza_loop = count($mazza_list) >= 3;
  // FESTIVA requested-brand section
  $festiva_list = array_values(array_filter($products, fn($p)=> strtolower($p['brand']??'')==='festiva'));
  $festiva_html = '';
  foreach (array_slice($festiva_list, 0, 14) as $p) $festiva_html .= sb_prod_card($p, $brands);
  $is_festiva_loop = count($festiva_list) >= 3;
  $festiva_html_loop = $festiva_html;
  $mazza_html_loop = $mazza_html;
  $brand_tiles = '';
  foreach ($brands as $b) {
    $initial = strtoupper(substr($b['name'], 0, 1));
    $brand_tiles .= '<a class="brand-tile" href="/brands.php#' . esc($b['slug']) . '">' . sb_brand_logo_img($b) . '<span>' . esc($b['name']) . '</span></a>';
  }
  $brand_carousel = '<div class="brand-carousel"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track">' . $brand_tiles . '</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div>';

  $hero_t = $home['hero_title'] ?? 'Premium Food & Beverage Distribution';
  $hero_s = $home['hero_sub']   ?? 'Rubu Al Quds for Trading & Food Industries — bringing the world\'s finest brands to the region since 1966.';
  $stats  = $home['stats'] ?? [['num'=>'1966','lbl'=>'Established'],['num'=>'+100','lbl'=>'Countries'],['num'=>'+500','lbl'=>'Brands'],['num'=>'3','lbl'=>'Generations']];
  $timeline = $home['timeline'] ?? [];
  $stats_html = '';
  foreach ($stats as $s) {
    $num = $s['num'];
    if (preg_match('/^([+]?)(\\d+)(.*)$/', $num, $m)) { $count = $m[2]; $suffix = $m[3]; $prefix = $m[1]; }
    else { $count = 0; $suffix = ''; $prefix = ''; }
    $stats_html .= '<div class="stat"><div class="num" data-count="' . $count . '" data-suffix="' . esc($suffix) . '">' . esc($num) . '</div><div class="lbl">' . esc($s['lbl']) . '</div></div>';
  }
  $tl_html = '';
  foreach ($timeline as $t) $tl_html .= '<div class="tl-item"><div class="yr">' . esc($t['yr']) . '</div><h4>' . esc($t['h']) . '</h4><p>' . esc($t['p']) . '</p></div>';
  $why = $home['why'] ?? '';
  $why_items = '';
  foreach (explode('|', $why) as $w) if (trim($w)) $why_items .= '<div class="why-card"><div class="ic">&#10003;</div><h4>' . esc(trim($w)) . '</h4></div>';

  return '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>7 Boys&reg; | Rubu Al Quds &mdash; Premium Food Trading &amp; Distribution Since 1966</title><meta name="description" content="Rubu Al Quds — Premium Food Trading & Distribution Since 1966. 500+ global brands, 100+ countries, 3 generations. Al-Abdali, Amman."><link rel="canonical" href="https://7boysjo.com/"><meta property="og:title" content="7 Boys&reg; | Rubu Al Quds — Since 1966"><meta property="og:description" content="Premium Food Trading & Distribution Since 1966 — 500+ brands, 100+ countries. Al-Abdali, Amman."><meta property="og:image" content="https://7boysjo.com/assets/img/logo.png"><meta property="og:url" content="https://7boysjo.com/"><meta property="og:type" content="website"><meta property="og:site_name" content="7 Boys | Rubu Al Quds"><meta name="twitter:card" content="summary_large_image"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"7 Boys | Rubu Al Quds","url":"https://7boysjo.com","logo":"https://7boysjo.com/assets/img/logo.png","foundingDate":"1966"}</script><script type="application/ld+json">{"@context":"https://schema.org","@type":"GroceryStore","name":"7 Boys | Rubu Al Quds","foundingDate":"1966","telephone":"+962795816444","address":{"@type":"PostalAddress","streetAddress":"Al-Abdali","addressLocality":"Amman","addressCountry":"JO"},"url":"https://7boysjo.com/"}</script><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="alternate" hreflang="en" href="https://7boysjo.com/"><link rel="alternate" hreflang="ar" href="https://7boysjo.com/?lang=ar"><link rel="alternate" hreflang="x-default" href="https://7boysjo.com/"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
    . site_header($nav)
    . '<section class="hero"><div class="hero-bg"></div><div class="hero-inner"><p class="eyebrow" data-i18n="eyebrow">'.t('eyebrow').'</p><h1 data-i18n="hero_title">'.t('hero_title').'</h1><p class="hero-sub" data-i18n="hero_sub">'.t('hero_sub').'</p>'
    . '<div class="hero-cta"><a class="btn" href="/category.php?cat=beverages" data-i18n="explore">'.t('explore').'</a><a class="btn btn-outline" href="/story.php" data-i18n="story">'.t('story').'</a></div></div><div class="hero-stats">' . $stats_html . '</div></section>'
    . '<section class="reveal trust-strip">' . '<div class="trust-item"><div class="ti-ic">&#128666;</div><div><strong>Fast Delivery</strong><small>Across the Levant</small></div></div>' . '<div class="trust-item"><div class="ti-ic">&#128172;</div><div><strong>WhatsApp Ordering</strong><small>Direct sales line</small></div></div>' . '<div class="trust-item"><div class="ti-ic">&#129482;</div><div><strong>Cold-Chain & Compliance</strong><small>Import handled end-to-end</small></div></div>' . '<div class="trust-item"><div class="ti-ic">&#127978;</div><div><strong>HORECA · Retail · Wholesale</strong><small>All channels served</small></div></div>' . '</section>' . '<main class="container">'
    # Corporate About
    . '<section class="about-grid reveal"><div class="about-text"><h3>Three Generations of Trust Since 1966</h3><p>'.esc($home['about'] ?? '').'</p><div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap"><a href="/story.php" class="btn" style="background:var(--green);color:#fff;padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:700">Our Story</a><a href="/contact.php" class="btn btn-outline" style="padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:700;border:1.5px solid var(--line)">Partner With Us</a></div></div><div class="about-img"><img src="/assets/img/logo.png" alt="Rubu Al Quds" style="max-width:62%;max-height:220px;object-fit:contain"><span class="badge">Est. 1966 — Al-Abdali, Amman</span></div></section>'
    # Capabilities
    . '<section class="reveal heritage-band"><div class="heritage-badge"><div class="hb-frame"><div class="hb-top" data-i18n="hb_top">PROUDLY IN BUSINESS SINCE</div><div class="hb-year">1966</div><div class="hb-bot" data-i18n="hb_bot">FAMILY OWNED</div></div></div></section>' . '<section class="reveal"><h2 class="section-title" style="text-align:center">What We Do</h2><p style="text-align:center;color:var(--muted);max-width:640px;margin:0 auto 28px">End-to-end supply chain from global sourcing to last-mile delivery across the Levant.</p><div class="why-grid">'
    . '<div class="why-card"><div class="ic">🌍</div><h4>Global Sourcing</h4><p>Direct partnerships with +500 premium brands across 100 countries.</p></div>'
    . '<div class="why-card"><div class="ic">📦</div><h4>Import & Compliance</h4><p>Certification, customs and cold-chain handled end-to-end.</p></div>'
    . '<div class="why-card"><div class="ic">🚚</div><h4>Distribution</h4><p>HORECA, retail and wholesale — fresh stock, fast delivery.</p></div>'
    . '</div></section>'
    # Categories tiles
    . (function() use ($cats,$products){ $html='<section class="reveal"><h2 class="section-title" style="text-align:center">Our Categories</h2><div class="cat-grid">'; $grads=['food'=>'linear-gradient(135deg,#d6eaf8 0%,#2e7d4f 55%,#0e4a2a 100%)','non-food'=>'linear-gradient(135deg,#e8f8f5 0%,#16a085 55%,#0e4a2a 100%)','pet-food'=>'linear-gradient(135deg,#fdebd0 0%,#e67e22 55%,#7a3b1f 100%)','best-offers'=>'linear-gradient(135deg,#fef9e7 0%,#f1c40f 55%,#9a7d0a 100%)','new-products'=>'linear-gradient(135deg,#eaf2f8 0%,#2980b9 55%,#1a3d5a 100%)','our-exclusive-range'=>'linear-gradient(135deg,#f4ecf7 0%,#8e44ad 55%,#4a235a 100%)','sweets'=>'linear-gradient(135deg,#f9e79f 0%,#b8923f 55%,#7d5a29 100%)','beverages'=>'linear-gradient(135deg,#a9dfbf 0%,#2e7d4f 100%)','coffee'=>'linear-gradient(135deg,#d7ccc8 0%,#5d4037 100%)','groceries'=>'linear-gradient(135deg,#c8e6c9 0%,#2e7d32 100%)']; foreach($cats as $c){ $slugs=[$c['slug']]; if(!empty($c['sub'])) foreach($c['sub'] as $s) $slugs[]=$s['slug']; $cnt=count(array_filter($products, fn($pp)=>in_array($pp['cat']??'', $slugs, true))); $liveSub=is_array($c['sub']??null)?count(array_filter($c['sub'], fn($ss)=>count(array_filter($products, fn($pp)=>($pp['cat']??'')===$ss['slug']))>0)):0; if($cnt==0 && $liveSub==0) continue; $grad=$grads[$c['slug']]??'linear-gradient(135deg,#a9dfbf 0%,#2e7d4f 100%)'; $subsHtml=''; if(!empty($c['sub'])){ $subsHtml='<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">'; $shown=0; foreach($c['sub'] as $s){ $scnt=count(array_filter($products, fn($pp)=> ($pp['cat']??'')===$s['slug'])); if($scnt==0) continue; if($shown>=3){ $remaining=count(array_filter($c['sub'], fn($ss)=> count(array_filter($products, fn($pp)=> ($pp['cat']??'')===$ss['slug']))>0)) - $shown; if($remaining>0) $subsHtml.='<span style="font-size:10px;color:#64748b;padding:3px 7px">+'.$remaining.' more</span>'; break; } $subsHtml.='<span class="cat-sub" data-href="/category.php?cat='.esc($s['slug']).'" onclick="event.stopPropagation();location.href=this.dataset.href">'.esc($s['name']).' '.$scnt.'</span>'; $shown++; } $subsHtml.='</div>'; } $html.='<a class="cat-tile" href="/category.php?cat='.esc($c['slug']).'"><div class="cat-img" style="background:'.$grad.';display:flex;align-items:center;justify-content:center"><span class="cat-overlay" style="color:#fff;font-weight:800;font-size:22px">'.esc($c['name']).'</span></div><div class="cat-body"><div style="display:flex;align-items:center;justify-content:space-between"><h3>'.esc($c['name']).'</h3><span class="cat-count" style="background:var(--bg-soft);padding:4px 10px;border-radius:999px;font-size:12px">'.$cnt.' products</span></div>'.$subsHtml.'</div></a>'; } $html.='</div></section>'; return $html; })()
    . '<h2 class="section-title" data-i18n="featured">'.t('featured').'</h2>'

    . '<div class="brand-carousel featured-carousel'.($is_loop?' loop':'').'" data-loop="'.($is_loop?'1':'0').'"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track feat-track">' . $home_c_loop . '</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div>'
    . (!empty($own_html) ? '<h2 class="section-title">7 Boys<span style="color:#2e7d4f">&reg;</span> — Our Own Label</h2><p style="text-align:center;color:var(--muted);max-width:640px;margin:-18px auto 28px">From our own kitchens to your shelves — Spanish olives, Thai pineapples, Greek dairy and more.</p><div class="brand-carousel own-carousel"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track own-track">'.$own_html.'</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div><div style="text-align:center;margin:-10px 0 50px"><a href="/brands.php?brand=7-boys" style="display:inline-block;font-size:13px;color:#1a2e1a;font-weight:700;text-decoration:none;border:1.5px solid #1a2e1a;padding:8px 22px;border-radius:999px">View all '.count($own_list).' products</a></div>' : '')
    . (!empty($mazza_html) ? '<h2 class="section-title">MAZZA<span style="color:#2e7d4f"> — Most Requested</span></h2><p style="text-align:center;color:var(--muted);max-width:640px;margin:-18px auto 28px">One of our most requested brands — Italian pantry essentials, from anchovies and capers to beans and pesto.</p><div class="brand-carousel mazza-carousel'.($is_mazza_loop?' loop':'').'" data-loop="'.($is_mazza_loop?'1':'0').'"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track mazza-track">'.$mazza_html_loop.'</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div><div style="text-align:center;margin:-10px 0 50px"><a href="/brands.php?brand=mazza" style="display:inline-block;font-size:13px;color:#1a2e1a;font-weight:700;text-decoration:none;border:1.5px solid #1a2e1a;padding:8px 22px;border-radius:999px">View all '.count($mazza_list).' products</a></div>' : '')
    . (!empty($festiva_html) ? '<h2 class="section-title">FESTIVA<span style="color:#2e7d4f"> — Most Requested</span></h2><p style="text-align:center;color:var(--muted);max-width:640px;margin:-18px auto 28px">Bold sauces & dressings — BBQ, Ranch and more, made in the UAE.</p><div class="brand-carousel festiva-carousel'.($is_festiva_loop?' loop':'').'" data-loop="'.($is_festiva_loop?'1':'0').'"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track festiva-track">'.$festiva_html_loop.'</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div><div style="text-align:center;margin:-10px 0 50px"><a href="/brands.php?brand=festiva" style="display:inline-block;font-size:13px;color:#1a2e1a;font-weight:700;text-decoration:none;border:1.5px solid #1a2e1a;padding:8px 22px;border-radius:999px">View all '.count($festiva_list).' products</a></div>' : '')
    . (!empty($bev_html) ? '<h2 class="section-title" data-i18n="beverages">'.t('beverages').'</h2><div class="brand-carousel beverages-carousel'.($is_bev_loop?' loop':'').'" data-loop="'.($is_bev_loop?'1':'0').'"><button class="carousel-arrow prev" aria-label="Previous">&#8249;</button><div class="brand-track bev-track">'.$bev_html_loop.'</div><button class="carousel-arrow next" aria-label="Next">&#8250;</button></div><div style="text-align:center;margin:-10px 0 50px"><a class="va-btn" href="/category.php?cat=beverages" style="display:inline-block;font-size:13px;color:#1a2e1a;font-weight:700;text-decoration:none;border:1.5px solid #1a2e1a;padding:8px 22px;border-radius:999px">'.'View all '.count($bev_list).' products'.'</a></div>' : '')
    . (function(){ $all=normalize_products_full(load_products()); $offs=array_values(array_filter($all, fn($pp)=> is_offer($pp))); if(empty($offs)) return ''; usort($offs, fn($a,$b)=> strtotime($a['expiry_date']) <=> strtotime($b['expiry_date'])); $h='<h2 class="section-title">Special Offers <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;vertical-align:middle;margin-left:6px">'.count($offs).'</span></h2><section class="cat-products">'; foreach(array_slice($offs,0,6) as $op) $h.= sb_prod_card($op, $brands); $h.='</section><div style="text-align:center;margin:-40px 0 80px"><a class="va-btn" href="/offers.php" style="display:inline-block;font-size:13px;color:#1a2e1a;font-weight:700;text-decoration:none;border:1.5px solid #1a2e1a;padding:8px 22px;border-radius:999px">View all offers</a></div>'; return $h; })()
    . '<h2 class="section-title" data-i18n="brands">'.t('brands').'</h2>' . $brand_carousel
    . '</main>'
    . '<section class="cta"><h3 data-i18n="partner">'.t('partner').'</h3><p data-i18n="partner_sub">'.t('partner_sub').'</p><a class="btn" href="/contact.php" data-i18n="touch">'.t('touch').'</a></section>'
    . site_footer()
    . whatsapp_float($ext)
    . '<script>if("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js");</script><script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
}

function render_product($slug){
  $products = normalize_products_full(load_products());
  $brands   = load_brands();
  $cats     = load_cats();
  $ext      = load_ext();
  $GLOBALS['ext'] = $ext;
  $nav = sb_nav();
  foreach ($products as $p) {
    if (product_slug($p) === $slug) {
      $bn = brand_name($brands, $p['brand'] ?? '');
      $chan = '';
      if (!empty($p['channel']) && isset($ext['channels'][$p['channel']])) {
        $chan = '<span class="chan-chip big">'.esc($ext['channels'][$p['channel']]).'</span>';
      }
      $related = ''; $rel_count = 0;
      foreach ($products as $rp) {
        if ($rel_count >= 4) break;
        if (($rp['brand'] ?? '') === ($p['brand'] ?? '') && product_slug($rp) !== $slug) { $related .= sb_prod_card($rp, $brands); $rel_count++; }
      }
      $price_html = ''; // hidden from visitors - only in quote PDF
      $stock_html = !empty($p['stock']) ? '<div class="pd-stock">'.esc($p['stock']).'</div>' : '';
      $cat = $p['cat'] ?? 'beverages';
      $seoT = $p['seo_title'] ?? ($p['name'].' — 7 Boys®');
      $rawD = $p['seo_desc'] ?? ($p['desc'] ?? '');
      // Clean spec dump (Weight250ml pcs/ct etc) -> readable SEO description
      $isSpec = (strpos($rawD,'Weight')!==false && strpos($rawD,'pcs/ct')!==false);
      if($isSpec || strlen(trim($rawD))<20){
        $bn2 = brand_name($brands, $p['brand'] ?? '');
        $origin = $p['origin'] ?? '';
        $vol = '';
        if(preg_match('/(\d+(?:\.\d+)?\s*(?:ml|l|g|kg))/i', $p['name'].' '.$rawD, $m)) $vol = trim($m[1]);
        $seoD = $p['name'] . ($bn2 ? ' by '.$bn2 : '') . ($origin ? ' — '.$origin : '') . ($vol ? ' ('.$vol.')' : '') . '. Premium import & distribution by 7 Boys | Rubu Al Quds since 1966, Al-Abdali Amman. Request a quote.';
      } else {
        $seoD = $rawD;
      }
      $html = '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . esc($seoT) . '</title><meta name="description" content="'.esc(mb_substr($seoD,0,160)).'"><link rel="canonical" href="https://7boysjo.com/product.php?slug='.rawurlencode($slug).'"><link rel="alternate" hreflang="en" href="https://7boysjo.com/product.php?slug='.rawurlencode($slug).'"><link rel="alternate" hreflang="ar" href="https://7boysjo.com/product.php?slug='.rawurlencode($slug).'&lang=ar"><meta property="og:title" content="'.esc($seoT).'"><meta property="og:description" content="'.esc(mb_substr($seoD,0,160)).'"><meta property="og:image" content="https://7boysjo.com/assets/img/'.esc($p['image']).'"><meta property="og:type" content="product"><script type="application/ld+json">'.json_encode(['@context'=>'https://schema.org','@type'=>'Product','name'=>$p['name'],'brand'=>brand_name($brands,$p['brand']??''),'description'=>$seoD,'image'=>'https://7boysjo.com/assets/img/'.$p['image'],'offers'=>['@type'=>'Offer','availability'=>'https://schema.org/InStock','priceCurrency'=>'JOD']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</script><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
        . site_header($nav)
        . '<main class="container"><a class="back-link" href="/category.php?cat='.esc($cat).'" data-i18n="back">&#8592; Back</a>'
        . '<div class="pd-wrap"><div class="pd-img">'.img_tag($p['image'],$p['name']).'</div>'
        . '<div class="pd-info"><div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">'.(function() use ($brands,$p){ $bobj=null; foreach($brands as $b) if(($b['slug']??'')===($p['brand']??'')) $bobj=$b; return $bobj ? sb_brand_logo_img($bobj) : ''; })().'<span class="tag">'.esc($bn).'</span></div>'.$chan.'<h1>'.esc($p['name']).'</h1>'
        . '<div class="pd-price-hidden">Price available on request (add to quote)</div>' . $stock_html
        . (function() use ($p){ $d=$p['desc']??''; if(strpos($d,'Weight')!==false){ $parts=preg_split('/\r?\n/',$d); $pills=''; $clean=[]; for($i=0;$i<count($parts);$i++){ $pt=trim($parts[$i]); if($pt==='') continue; $low=strtolower($pt); if($low==='weight'){ $val=trim($parts[$i+1]??''); if($val!=='') $pills.='<span style="display:inline-block;background:#f1f5ef;color:#0e4a2a;border:1px solid #b9d9c8;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;margin:2px">'.esc($val).'</span>'; $i++; continue; } if($low==='pcs/ct'){ $val=trim($parts[$i+1]??''); if($val!=='') $pills.='<span style="display:inline-block;background:#f1f5ef;color:#0e4a2a;border:1px solid #b9d9c8;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;margin:2px">'.esc($val).' pcs/ct</span>'; $i++; continue; } if($low==='ct/pal'){ $val=trim($parts[$i+1]??''); if($val!=='') $pills.='<span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;margin:2px">'.esc($val).' / pal</span>'; $i++; continue; } $clean[]=$pt; } $marketing=$p['description']??''; if(strpos($marketing,'Weight')!==false) $marketing=''; if($marketing==='' && $clean) $marketing=implode(' ',array_slice($clean,0,2)); if($marketing==='') $marketing=$p['name'].' — Premium import by 7 Boys.'; return ($pills?'<div style="margin:8px 0">'.$pills.'</div>':'').'<p class="pd-desc">'.esc(mb_substr($marketing,0,180)).'</p>'; } return '<p class="pd-desc">'.esc(mb_substr($d,0,180)).'</p>'; })()
        .spec_table($p). (empty($p['expiry_date'])?'':'<p style="font-size:13px;color:#92400e;margin-top:6px">Expiry: '.esc($p['expiry_date']).' '.expiry_badge($p).(!empty($p['offer_price'])?' · Offer: <b>'.esc($p['offer_price']).'</b>':'').'</p>')
        . '<p class="pd-origin">Origin: '.esc($p['origin']).'</p>'
        . '<div class="pd-actions">'
        . '<span class="qb-step"><input type="number" class="qb-case-in" value="1" min="1" aria-label="Cases"><button type="button" class="btn btn-qb" data-slug="'.esc($slug).'" data-name="'.esc($p['name']).'" data-img="'.esc($p['image']).'" data-href="/product.php?slug='.rawurlencode($slug).'" data-pcs="'.pack_info($p)[0].'" data-ctpal="'.pack_info($p)[1].'" onclick="window.__qbAddFromBtn(this)" data-i18n="add_quote">&#128722; Add to Quote</button>'
        . '<a class="btn btn-outline" href="/contact.php" data-i18n="contact_sales">Contact Sales</a>'
        . '</div></div></div>'
        . ($related ? '<h2 class="section-title" data-i18n="more_from">'.t('more_from').' '.esc($bn).'</h2><section class="cat-products">'.$related.'</section>' : '')
        . '</main>'
        . site_footer()
        . whatsapp_float($ext)
        . '<script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
      return $html;
    }
  }
  return null;
}

function render_brands(){
  $products = normalize_products_full(load_products());
  $brands   = load_brands();
  $cats     = load_cats();
  $ext      = load_ext();
  $GLOBALS['ext'] = $ext;
  $nav = sb_nav();
  $cat_labels = ['beverages'=>t('beverages'),'sweets'=>t('sweets'),'gourmet'=>t('gourmet'),'staples'=>t('staples')];
  // If ?brand=slug is set, show brand products
  $brand_slug = $_GET['brand'] ?? '';
  if($brand_slug){
    $brand_obj = null; foreach($brands as $b) if(($b['slug']??'')===$brand_slug) $brand_obj=$b;
    if($brand_obj){
      $bprods = array_values(array_filter($products, fn($pp)=> ($pp['brand']??'')===$brand_slug));
      $cards=''; foreach($bprods as $bp) $cards.= sb_prod_card($bp,$brands);
      $count=count($bprods);
      $hdr = '<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">'.sb_brand_logo_img($brand_obj).'<div><h1 style="margin:0">'.esc($brand_obj['name']).'</h1><p style="color:var(--muted);margin:4px 0 0">'.$count.' products</p></div></div>';
      $hdr .= '<a href="/brands.php" style="display:inline-block;margin-bottom:16px;color:var(--green);font-weight:700;text-decoration:none">&larr; All Brands</a>';
      if(empty($cards)) $cards='<p style="text-align:center;color:var(--muted);padding:40px">No products for this brand yet.</p>';
      else $cards='<section class="cat-products">'.$cards.'</section>';
      return '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>'.esc($brand_obj['name']).' &mdash; 7 Boys&reg; | Rubu Al Quds</title><meta name="description" content="'.esc($brand_obj['name']).' products — distributed by 7 Boys | Rubu Al Quds since 1966."><link rel="canonical" href="https://7boysjo.com/brands.php?brand=' . urlencode($brand_obj['slug']) . '"><meta property="og:title" content="'.esc($brand_obj['name']).' — 7 Boys"><meta property="og:image" content="https://7boysjo.com/assets/img/logo.png"><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
        . site_header($nav)
        . '<main class="container">'.$hdr.$cards.'</main>'
        . site_footer()
        . whatsapp_float($ext)
        . '<script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
    }
  }
  $bt = ''; $current_cat = null;
  foreach ($brands as $b) {
    $c = $b['cat'] ?? 'beverages';
    if ($c !== $current_cat) {
      if ($current_cat !== null) $bt .= '</section>';
      $bt .= '<h2 class="brand-group-title" id="'.esc($b['slug']).'" data-i18n="' . $c . '">' . esc($cat_labels[$c] ?? ucfirst($c)) . '</h2><section class="brands-grid">';
      $current_cat = $c;
    }
    $bt .= '<a class="brand-tile" href="/brands.php?brand=' . esc($b['slug']) . '">' . sb_brand_logo_img($b) . '<span>' . esc($b['name']) . '</span></a>';
  }
  $bt .= '</section>';
  return '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Brands &mdash; 7 Boys&reg; | Rubu Al Quds</title><meta name="description" content="Explore 200+ global brands distributed by Rubu Al Quds — 7 Boys since 1966. Al-Abdali, Amman."><link rel="canonical" href="https://7boysjo.com/brands.php"><meta property="og:title" content="Brands — 7 Boys | Rubu Al Quds"><meta property="og:description" content="200+ global brands — 7 Boys since 1966"><meta property="og:image" content="https://7boysjo.com/assets/img/logo.png"><meta property="og:url" content="https://7boysjo.com/brands.php"><meta property="og:type" content="website"><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
    . site_header($nav)
    . '<main class="container"><h1 class="page-title" data-i18n="our_brands">Our Brands</h1>' . $bt . '</main>'
    . site_footer()
    . whatsapp_float($ext)
    . '<script>if("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js");</script><script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
}

function render_category($cat){
  $products = normalize_products_full(load_products());
  $brands   = load_brands();
  $cats     = load_cats();
  $ext      = load_ext();
  $GLOBALS['ext'] = $ext;
  $nav = sb_nav();
  $cname = $cat;
  $catObj = null;
  foreach ($cats as $c) if (($c['slug'] ?? '') === $cat) { $cname = $c['name']; $catObj = $c; break; }
  // Parent cats (food/non-food/pet-food) aggregate their sub-categories
  $catSlugs = [$cat];
  if($catObj && !empty($catObj['sub'])) foreach($catObj['sub'] as $s) $catSlugs[]=$s['slug'];
  // Build filter data
  $brandsInCat = [];
  $originsInCat = [];
  $subsInCat = [];
  foreach ($products as $p) {
    $pcat = $p['cat'] ?? $p['category'] ?? '';
    if (in_array($pcat,$catSlugs,true)) {
      if (!empty($p['brand'])) $brandsInCat[$p['brand']] = brand_name($brands, $p['brand']);
      if (!empty($p['origin'])) $originsInCat[$p['origin']] = $p['origin'];
      if (!empty($p['sub'])) $subsInCat[$p['sub']] = $p['sub'];
    }
  }
  asort($brandsInCat); asort($originsInCat); asort($subsInCat);
  // Pagination: 36 per page
  $page = max(1, intval($_GET['page'] ?? 1));
  $perPage = 36;
  $filtered = array_values(array_filter($products, fn($p)=> in_array(($p['cat']??$p['category']??''), $catSlugs, true)));
  // Sort by name for consistency
  usort($filtered, fn($a,$b)=> strcasecmp($a['name']??'', $b['name']??''));
  $count = count($filtered);
  $totalPages = max(1, ceil($count / $perPage));
  if($page > $totalPages) $page = $totalPages;
  $offset = ($page-1)*$perPage;
  $pageItems = array_slice($filtered, $offset, $perPage);
  $cards = '';
  $treeSubs = [];
  if ($catObj && !empty($catObj['sub'])) foreach ($catObj['sub'] as $s) { $ss = $s['slug']; $n = 0; foreach ($products as $pp) if (($pp['cat'] ?? '') === $ss) $n++; if ($n > 0) $treeSubs[] = ['slug' => $ss, 'name' => $s['name'], 'n' => $n]; }
  $useTreeSubs = !empty($treeSubs);
  $sibSubs = []; $parentCat = null;
  if (!$useTreeSubs) foreach ($cats as $pc) { if (!empty($pc['sub'])) foreach ($pc['sub'] as $s) if (($s['slug'] ?? '') === $cat) { foreach ($pc['sub'] as $s2) { $n = 0; foreach ($products as $pp) if (($pp['cat'] ?? '') === ($s2['slug'] ?? '')) $n++; if ($n > 0) $sibSubs[] = ['slug' => $s2['slug'], 'name' => $s2['name'], 'n' => $n]; } $parentCat = ['slug' => $pc['slug'], 'name' => $pc['name']]; break 2; } }
  foreach ($pageItems as $p) { $sub = $useTreeSubs ? ($p['cat'] ?? $p['category'] ?? 'all') : ($p['sub'] ?? 'all'); $cards .= sb_prod_card($p, $brands, $sub); }
  // Build pagination HTML
  $pagHtml = '';
  if($totalPages > 1){
    $pagHtml = '<div class="pagination" style="display:flex;justify-content:center;gap:6px;margin:30px 0;flex-wrap:wrap">';
    // Prev
    if($page > 1) $pagHtml .= '<a href="/category.php?cat='.urlencode($cat).'&page='.($page-1).'" style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333">&#8592; Prev</a>';
    // Page numbers (show 1..total, with ellipsis)
    $start = max(1, $page-2); $end = min($totalPages, $page+2);
    if($start > 1){ $pagHtml .= '<a href="/category.php?cat='.urlencode($cat).'&page=1" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;text-decoration:none;">1</a>'; if($start>2) $pagHtml .= '<span style="padding:8px">...</span>'; }
    for($i=$start; $i<=$end; $i++){
      if($i==$page) $pagHtml .= '<span style="padding:8px 12px;background:#0e4a2a;color:#fff;border-radius:8px;font-weight:700">'.$i.'</span>';
      else $pagHtml .= '<a href="/category.php?cat='.urlencode($cat).'&page='.$i.'" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333">'.$i.'</a>';
    }
    if($end < $totalPages){ if($end < $totalPages-1) $pagHtml .= '<span style="padding:8px">...</span>'; $pagHtml .= '<a href="/category.php?cat='.urlencode($cat).'&page='.$totalPages.'" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;text-decoration:none;">'.$totalPages.'</a>'; }
    if($page < $totalPages) $pagHtml .= '<a href="/category.php?cat='.urlencode($cat).'&page='.($page+1).'" style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#333">Next &#8594;</a>';
    $pagHtml .= '</div><div style="text-align:center;color:#666;font-size:13px;margin-bottom:20px">Page '.$page.' of '.$totalPages.' — '.$count.' products</div>';
  }
  // Build brand options
  $brandOpts = '<option value="" data-i18n="all_brands">All Brands</option>';
  foreach ($brandsInCat as $slug=>$name) $brandOpts .= '<option value="'.esc($slug).'">'.esc($name).'</option>';
  $originOpts = '<option value="" data-i18n="all_origins">All Origins</option>';
  foreach ($originsInCat as $o) $originOpts .= '<option value="'.esc($o).'">'.esc($o).'</option>';
  $subChips = '<button class="fchip active" data-sub="" data-i18n="all_filter">All</button>';
  if ($useTreeSubs) { foreach ($treeSubs as $ts) $subChips .= '<a class="fchip" style="text-decoration:none;display:inline-block" href="/category.php?cat='.esc($ts['slug']).'">'.esc($ts['name']).' '.$ts['n'].'</a>'; }
  elseif (!empty($sibSubs)) { $subChips .= '<a class="fchip" style="text-decoration:none;display:inline-block" href="/category.php?cat='.esc($parentCat['slug']).'">&larr; '.esc($parentCat['name']).'</a>'; foreach ($sibSubs as $sb) { if ($sb['slug'] === $cat) $subChips .= '<span class="fchip active">'.esc($sb['name']).' '.$sb['n'].'</span>'; else $subChips .= '<a class="fchip" style="text-decoration:none;display:inline-block" href="/category.php?cat='.esc($sb['slug']).'">'.esc($sb['name']).' '.$sb['n'].'</a>'; } }
  else 
  foreach ($subsInCat as $s) { $label = ucwords(str_replace("-"," ", $s)); $subChips .= '<button class="fchip" data-sub="'.esc($s).'">'.esc($label).'</button>'; }

  return '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>' . esc($cname) . ' &mdash; 7 Boys&reg; | Rubu Al Quds</title><meta name="description" content="' . esc($cname) . ' — Premium products by 7 Boys | Rubu Al Quds Since 1966. Al-Abdali Amman."><link rel="canonical" href="https://7boysjo.com/category.php?cat=' . urlencode($cat) . '"><meta property="og:title" content="' . esc($cname) . ' — 7 Boys"><meta property="og:image" content="https://7boysjo.com/assets/img/logo.png"><meta property="og:url" content="https://7boysjo.com/category.php?cat=' . urlencode($cat) . '"><meta property="og:type" content="website"><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
    . site_header($nav)
    . '<main class="container"><a class="back-link" href="/category.php" data-i18n="back">&#8592; Back</a><h1 class="page-title">' . esc($cname) . ' <span class="count-badge" id="resultCount">'.$count.' products</span></h1>'
    . '<div class="search-bar"><div class="sinput-wrap"><span class="sicon">🔍</span><input id="prodSearch" type="search" placeholder="Search products, brands, origins..." data-i18n-ph="search_ph" autocomplete="off"><button id="clearSearch" class="clear-btn" style="display:none">×</button></div>'
    . '<select id="brandFilter" class="fselect">'.$brandOpts.'</select>'
    . '<select id="originFilter" class="fselect">'.$originOpts.'</select></div>'
    . '<div class="sub-chips" id="subChips">'.$subChips.'</div>'
    . '<section class="cat-products" id="prodGrid">' . $cards . '</section>' . $pagHtml
    . '<div id="noResults" style="display:none;text-align:center;padding:48px 20px;"><p style="font-size:48px;margin-bottom:12px;">🔍</p><p style="font-size:18px;font-weight:600;color:var(--ink)" data-i18n="no_products">No products found</p><p style="color:var(--muted);margin-top:6px;" data-i18n="try_filters">Try different search or filters</p><button onclick="document.getElementById(\'prodSearch\').value=\'\';document.getElementById(\'brandFilter\').value=\'\';document.getElementById(\'originFilter\').value=\'\';document.querySelectorAll(\'.fchip\').forEach(c=>c.classList.remove(\'active\'));document.querySelector(\'.fchip[data-sub=\\\'\\\']\').classList.add(\'active\');window.__doFilter&&window.__doFilter()" style="margin-top:16px;background:var(--green);color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;" data-i18n="clear_all">Clear all</button></div></main>'
    . site_footer()
    . whatsapp_float($ext)
    . '<script src="'.asset_js('search.js').'"></script><script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
}

function render_story(){
  $ext = load_ext();
  $GLOBALS['ext'] = $ext;
  $settings = load_settings();
  $home = load_json(SITE_DIR . '/admin/data/home.json');
  $cats = load_cats(); $brands = load_brands(); $products = normalize_products_full(load_products());
  $nav = sb_nav();
  $about = $home['about'] ?? '';
  $why = $home['why'] ?? '';
  $certs_html = '';
  foreach ($ext['certs'] ?? [] as $c) $certs_html .= '<div class="cert-badge">'.esc($c).'</div>';
  $faq_html = '';
  foreach ($ext['faq'] ?? [] as $f) {
    if (empty($f['q'])) continue;
    $faq_html .= '<div class="faq-item"><h4>'.esc($f['q']).'</h4><p>'.esc($f['a']).'</p></div>';
  }
  $map_html = !empty($ext['map_embed']) ? '<div class="map-wrap">'.($ext['map_embed']).'</div>' : '';
  $timeline = $home['timeline'] ?? [];
  $tl_html = '';
  foreach ($timeline as $t) $tl_html .= '<div class="tl-item"><div class="yr">' . esc($t['yr']) . '</div><h4>' . esc($t['h']) . '</h4><p>' . esc($t['p']) . '</p></div>';
  $why_items = '';
  foreach (explode('|', $why) as $w) if (trim($w)) $why_items .= '<div class="why-card"><div class="ic">&#10003;</div><h4>' . esc(trim($w)) . '</h4></div>';
  return '<!DOCTYPE html><html lang="' . ($GLOBALS['lang'] ?? 'en') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>About &mdash; 7 Boys&reg; | Rubu Al Quds — Since 1966</title><meta name="description" content="Our Story — Rubu Al Quds & 7 Boys since 1966. Three generations of food trading from Al-Abdali, Amman to 100+ countries."><link rel="canonical" href="https://7boysjo.com/story.php"><meta property="og:title" content="Our Story — 7 Boys | Rubu Al Quds"><meta property="og:image" content="https://7boysjo.com/assets/img/logo.png"><meta property="og:url" content="https://7boysjo.com/story.php"><meta property="og:type" content="website"><link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#0e4a2a"><link rel="stylesheet" href="' . asset_css() . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet"></head><body>'
    . site_header($nav)
    . '<section class="cat-hero"><div class="inner"><h1 data-i18n="our_story">Our Story</h1><p>'.t('story_sub').'</p></div></section>'
    . '<main class="container">'
    . '<section class="about-grid"><div class="about-text"><h3 data-i18n="rooted_title">Rooted in Amman, Reaching the World</h3>'
    . '<p>' . esc($about) . '</p>'
    . '<div class="quote">"We bridge global food sourcing with regional excellence &mdash; delivering the world\'s finest brands to the Levant." &mdash; Wael Abu Awwad, Founder &amp; CEO</div></div>'
    . '<div class="about-img" style="background:#fff;padding:24px;"><img src="/assets/img/logo.png" alt="Rubu Al Quds | 7 Boys - Since 1966" style="width:100%;max-width:380px;height:auto;object-fit:contain;"><div class="badge">Est. 1966</div></div></section>'
    . '<section class="heritage-band" style="margin:10px 0 6px"><div class="heritage-badge"><div class="hb-frame"><div class="hb-top">PROUDLY IN BUSINESS SINCE</div><div class="hb-year">1966</div><div class="hb-bot">FAMILY OWNED</div></div></div></section>' . ($certs_html ? '<h2 class="section-title" data-i18n="compliance">'.t('compliance').'</h2><section class="certs-row">'.$certs_html.'</section>' : '')
    . '<h2 class="section-title" data-i18n="what_we_do">'.t('what_we_do').'</h2><section class="why-grid">'
    . '<div class="why-card"><div class="ic">&#127757;</div><h4 data-i18n="global_sourcing">'.t('global_sourcing').'</h4><p data-i18n="global_sourcing_sub">'.t('global_sourcing_sub').'</p></div>'
    . '<div class="why-card"><div class="ic">&#128666;</div><h4 data-i18n="distribution">'.t('distribution').'</h4><p data-i18n="distribution_sub">'.t('distribution_sub').'</p></div>'
    . '<div class="why-card"><div class="ic">&#11088;</div><h4>7 Boys&reg;</h4><p data-i18n="gourmet_line_sub">'.t('gourmet_line_sub').'</p></div>'
    . '<div class="why-card"><div class="ic">&#127941;</div><h4 data-i18n="compliance">'.t('compliance').'</h4><p data-i18n="compliance_sub">'.t('compliance_sub').'</p></div>'
    . '</section>'
    . ($faq_html ? '<h2 class="section-title" data-i18n="faq">'.t('faq').'</h2><section class="faq-list">'.$faq_html.'</section>' : '')
    . ($map_html ? '<h2 class="section-title" data-i18n="find_us">'.t('find_us').'</h2>'.$map_html : '')
    . '<h2 class="section-title" data-i18n="journey">'.t('journey').'</h2><section class="timeline">' . $tl_html . '</section>'
    . '</main>'
    . '<section class="cta"><h3 data-i18n="work_with">'.t('work_with').'</h3><p data-i18n="work_sub">'.t('work_sub').'</p><a class="btn" href="/contact.php" data-i18n="contact_us">'.t('contact_us').'</a></section>'
    . site_footer()
    . whatsapp_float($ext)
    . '<script>if("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js");</script><script src="/assets/js/carousel.js"></script><script src="'.asset_js('cat_polish.js').'"></script></body></html>';
}

// Publish the public site. Defined here so it is available from any admin script.
// Uses CLI proc_open — Hostinger disables exec/shell_exec/popen, only proc_open is
// allowed for spawning a fresh isolated PHP process that generates the pages.
function auto_publish(){
  $des = [['pipe','r'],['pipe','w'],['pipe','w']];
  $p = @proc_open('/usr/bin/php ' . escapeshellarg(__DIR__.'/do_publish.php'), $des, $pipes);
  if (is_resource($p)) {
    @fclose($pipes[0]);
    while (@fgets($pipes[1]) !== false) {}
    @fclose($pipes[1]);
    @proc_close($p);
    return true;
  }
  return false;
}
