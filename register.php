<?php
require_once __DIR__ . '/admin/config.php';
if (is_visitor_logged_in()) { header('Location: /account.php'); exit; }
if (is_admin()) { header('Location: /admin/'); exit; }

$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('rate_limit_check') && !rate_limit_check('register', 5, 3600)) { $err = 'Too many attempts. Please try again in an hour.'; }
    else {
    $__order_lock = @fopen(__DIR__.'/admin/data/.lock_register','c'); if ($__order_lock) flock($__order_lock, LOCK_EX); // held till request end: no lost registrations
    $__is_bot = trim($_POST['website'] ?? '') !== '';
    $__rip = $_SERVER['REMOTE_ADDR'] ?? '0'; $__rrf = sys_get_temp_dir().'/rg_'.md5($__rip).'.json'; $__rn = time(); $__rd = ['c'=>0,'t'=>$__rn]; if (file_exists($__rrf)) { $__rd = json_decode(file_get_contents($__rrf), true) ?: $__rd; if ($__rn - ($__rd['t'] ?? 0) > 3600) $__rd = ['c'=>0,'t'=>$__rn]; } $__rd['c']++; file_put_contents($__rrf, json_encode($__rd), LOCK_EX); if ($__rd['c'] > 5) $err = 'Too many attempts. Please try again in an hour.';
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $ctype = trim($_POST['customer_type'] ?? 'individual');
    $allowed = ['individual','restaurant','hotel','cafe','supermarket','retail','wholesale','catering','other'];
    if(!in_array($ctype,$allowed)) $ctype='individual';
    $pass = $_POST['pass'] ?? '';
    $pass2 = $_POST['pass2'] ?? '';
    if ($err !== '') {} // rate limited: show error, save nothing
    elseif ($__is_bot) {} // honeypot: silently ignore bots
    elseif (strlen($name) < 2) $err = 'Please enter your full name.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Invalid email address.';
    elseif (strlen($pass) < 6) $err = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2) $err = 'Passwords do not match.';
    elseif (find_visitor_by_email($email)) $err = 'This email is already registered. Please login.';
    else {
        $visitors = load_visitors();
        $id = count($visitors) ? max(array_column($visitors,'id'))+1 : 1;
        $visitors[] = [
            'id'=> $id,
            'name'=> $name,
            'email'=> $email,
            'phone'=> $phone,
            'company'=> $company,
            'customer_type'=> $ctype,
            'pass'=> password_hash($pass, PASSWORD_DEFAULT),
            'created'=> date('Y-m-d H:i:s'),
            'role'=> 'visitor'
        ];
        save_visitors($visitors);
        notify_admin('register', '[7boysjo] New account: '.$name,
            "New visitor registered at ".date('Y-m-d H:i:s')."\nName: $name\nEmail: $email\nPhone: $phone\nCompany: $company\nType: $ctype");
        $_SESSION['visitor_id'] = $id;
        $_SESSION['visitor'] = ['id'=>$id,'name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>$company,'customer_type'=>$ctype];
        header('Location: /account.php'); exit;
    }
    }
}
$nav = sb_nav();
?>
<!DOCTYPE html><html lang="<?= esc($GLOBALS['lang']??'en') ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account — 7 Boys®</title><link rel="stylesheet" href="<?= asset_css() ?>"></head><body>
<?= site_header($nav) ?>
<section class="cat-hero"><div class="inner"><h1 data-i18n="create_account">Create Account</h1><p>Join 7 Boys® — track your quotes and orders with ease.</p></div></section>
<main class="container" style="max-width:520px;margin:32px auto 60px">
<form method="post" class="auth-card">
<input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">
<?php if($err): ?><div style="background:#fee;border:1px solid #fcc;color:#900;padding:12px 16px;border-radius:10px;margin-bottom:16px"><?= esc($err) ?></div><?php endif; ?>
<label>Full Name *<input name="name" required value="<?= esc($_POST['name']??'') ?>" placeholder="Ahmad Qasim" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Email *<input name="email" type="email" required value="<?= esc($_POST['email']??'') ?>" placeholder="you@example.com" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Phone<input name="phone" value="<?= esc($_POST['phone']??'') ?>" placeholder="+962 79..." style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Account Type *<select name="customer_type" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px;background:var(--card)">
<option value="individual" <?= (($_POST['customer_type']??'')==='individual'?'selected':'') ?>>👤 Individual / Home Customer</option>
<option value="restaurant" <?= (($_POST['customer_type']??'')==='restaurant'?'selected':'') ?>>🍽️ Restaurant</option>
<option value="hotel" <?= (($_POST['customer_type']??'')==='hotel'?'selected':'') ?>>🏨 Hotel</option>
<option value="cafe" <?= (($_POST['customer_type']??'')==='cafe'?'selected':'') ?>>☕ Cafe / Coffee Shop</option>
<option value="supermarket" <?= (($_POST['customer_type']??'')==='supermarket'?'selected':'') ?>>🛒 Supermarket / Minimarket</option>
<option value="retail" <?= (($_POST['customer_type']??'')==='retail'?'selected':'') ?>>🏪 Retail Shop</option>
<option value="wholesale" <?= (($_POST['customer_type']??'')==='wholesale'?'selected':'') ?>>📦 Wholesale / Distributor</option>
<option value="catering" <?= (($_POST['customer_type']??'')==='catering'?'selected':'') ?>>🎂 Catering / Kitchen</option>
<option value="other" <?= (($_POST['customer_type']??'')==='other'?'selected':'') ?>>🏢 Other Business</option>
</select></label>
<label>Company / Business Name<input name="company" value="<?= esc($_POST['company']??'') ?>" placeholder="e.g. Matar Restaurant (if business)" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Password *<input name="pass" type="password" required placeholder="••••••••" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Confirm Password *<input name="pass2" type="password" required placeholder="••••••••" style="width:100%;padding:12px;margin:6px 0 18px;border:1px solid var(--line);border-radius:10px"></label>
<button class="btn" style="width:100%;justify-content:center">Create Account</button>
<p style="text-align:center;margin-top:16px;color:var(--muted)">Already have an account? <a href="/login.php" style="color:var(--green);font-weight:600">Login</a></p>
</form>
</main>
<?= site_footer() ?><?= whatsapp_float(load_ext()) ?>
</body></html>
