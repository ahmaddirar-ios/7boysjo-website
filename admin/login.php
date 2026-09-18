<?php require_once 'config.php'; ?>
<?php
if (isset($_GET['logout'])) { session_destroy(); header('Location: login.php'); exit; }
if (is_logged_in() || is_staff()) { header('Location: index.php'); exit; }

$error = '';
$locked = is_locked_out();
if ($locked) {
  $mins = ceil(lock_remaining()/60);
  $error = "Too many failed attempts. Locked for $mins minutes.";
}

if (isset($_POST['login']) && !$locked) {
  $u = trim($_POST['user'] ?? ''); $p = $_POST['pass'] ?? '';
  $ok = false; $staff=null;
  if ($u === ADMIN_USER && admin_verify($p)) $ok = true;
  else {
    $all_users = load_users();
    foreach ($all_users as $ui => $usr) {
      if (($usr['username'] ?? '') !== $u) continue;
      $stored = (string)($usr['pass'] ?? '');
      $info = function_exists('password_get_info') ? password_get_info($stored) : ['algo' => null];
      $match = !empty($info['algo']) ? password_verify($p, $stored) : hash_equals($stored, (string)$p);
      if ($match) {
        $ok = true;
        // auto-migrate plaintext → hash + record last login
        if (empty($info['algo'])) $all_users[$ui]['pass'] = password_hash($p, PASSWORD_DEFAULT);
        $all_users[$ui]['last_login'] = date('Y-m-d H:i:s');
        save_users($all_users);
      }
      break;
    }
  }
  // check employees
  if(!$ok){
    foreach(load_employees() as $e){
      if(($e['username']??'')===$u && !empty($e['pass']) && password_verify($p,$e['pass'])){
        if(($e['status']??'active')!=='active'){ $error='Account inactive. Contact admin.'; break; }
        $ok=true; $staff=$e; break;
      }
    }
  }
  if ($ok && $staff) {
    clear_attempts();
    if (function_exists('session_regenerate_id')) session_regenerate_id(true); // kill fixation
    $_SESSION['staff_id']=$staff['id'];
    $_SESSION['staff']=$staff;
    $_SESSION['staff_user']=$staff['username'];
    // also set generic logged_in for compatibility but mark as staff
    $_SESSION['is_staff']=true;
    header('Location: index.php'); exit;
  }
  if ($ok) {
    $rec = function_exists('twofa_get') ? twofa_get($u) : null;
    if ($rec && !empty($rec['secret'])) {
      $_SESSION['2fa_pending'] = $u;
      header('Location: login.php?step=code'); exit;
    }
    clear_attempts();
    if (function_exists('session_regenerate_id')) session_regenerate_id(true); // kill fixation
    $_SESSION['logged_in'] = true;
    $_SESSION['admin_user'] = $u;
    header('Location: index.php');
    exit;
  }
  register_failed_attempt();
  if (is_locked_out()) {
    $mins = ceil(lock_remaining()/60);
    $error = "Too many failed attempts. Locked for $mins minutes.";
  } else {
    $left = MAX_ATTEMPTS - get_attempts()['count'];
    $error = "Invalid credentials. $left attempt(s) remaining.";
  }
}

if (isset($_POST['verify2fa']) && isset($_SESSION['2fa_pending']) && !$locked) {
  $pu = $_SESSION['2fa_pending'];
  $rec = function_exists('twofa_get') ? twofa_get($pu) : null;
  $code = preg_replace('/[^0-9a-zA-Z]/', '', (string)($_POST['code'] ?? ''));
  $pass = false;
  if ($rec && !empty($rec['secret']) && function_exists('totp_verify') && totp_verify($rec['secret'], $code)) $pass = true;
  if (!$pass && $rec && !empty($rec['backup']) && is_array($rec['backup'])) {
    foreach ($rec['backup'] as $bi => $bh) {
      if (password_verify(strtoupper($code), $bh)) {
        $pass = true;
        $all = twofa_all(); $bk = $all[$pu]['backup']; unset($bk[$bi]); $all[$pu]['backup'] = array_values($bk); save_json(TWOFA_FILE, $all);
        break;
      }
    }
  }
  if ($pass) {
    clear_attempts(); unset($_SESSION['2fa_pending']);
    if (function_exists('session_regenerate_id')) session_regenerate_id(true); // kill fixation
    $_SESSION['logged_in'] = true;
    $_SESSION['admin_user'] = $pu;
    $uu = load_users();
    foreach ($uu as $ui => $usr) { if (($usr['username'] ?? '') === $pu) { $uu[$ui]['last_login'] = date('Y-m-d H:i:s'); save_users($uu); break; } }
    header('Location: index.php'); exit;
  }
  register_failed_attempt();
  $error = 'Invalid code. Try again.';
}
// moved
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>7 Boys Admin — Login</title>
<style>body{font-family:system-ui;background:#053d20;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}
.box{background:#fff;padding:36px 40px;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.3);width:320px;}
h1{font-family:Georgia,serif;color:#053d20;margin:0 0 18px;font-size:24px;}
input{width:100%;padding:11px;margin:8px 0;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;}
button{width:100%;padding:12px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:15px;cursor:pointer;margin-top:10px;}
.err{color:#b00;font-size:13px;margin-top:8px;}
.lock{color:#b00;font-weight:bold;font-size:14px;margin-top:8px;}</style></head>
<body><div class="box">
<h1>7 Boys® Admin</h1>
<?php $need_code = isset($_SESSION['2fa_pending']); ?>
<?php if ($need_code): ?>
<form method="post">
<input name="code" placeholder="6-digit code" required autocomplete="one-time-code" inputmode="numeric" maxlength="12">
<button name="verify2fa">Verify</button>
</form>
<p style="color:#666;font-size:12px;margin-top:10px;">Open your authenticator app and enter the 6-digit code. Lost your phone? Use one of your backup codes instead.</p>
<?php else: ?>
<form method="post" <?php echo $locked?'onsubmit="return false"':''; ?>>
<input name="user" placeholder="Username" required <?php echo $locked?'disabled':''; ?>>
<input name="pass" type="password" placeholder="Password" required <?php echo $locked?'disabled':''; ?>>
<button name="login" <?php echo $locked?'disabled style="opacity:.5;cursor:not-allowed"':''; ?>>Login</button>
</form>
<?php endif; ?>
<?php if($locked): ?><div class="lock"><?php echo $error; ?></div>
<?php elseif(!empty($error)): ?><div class="err"><?php echo $error; ?></div><?php endif; ?>
</div></body></html>
