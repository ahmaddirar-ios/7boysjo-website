<?php
require_once __DIR__ . '/admin/config.php';
if (is_visitor_logged_in()) { header('Location: /account.php'); exit; }
if (is_admin()) { header('Location: /admin/'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = $_POST['pass'] ?? '';
    $vis = find_visitor_by_email($email);
    if (!$vis || !password_verify($pass, $vis['pass'] ?? '')) {
        $err = 'Invalid email or password.';
    } else {
        $_SESSION['visitor_id'] = $vis['id'];
        $_SESSION['visitor'] = ['id'=>$vis['id'],'name'=>$vis['name'],'email'=>$vis['email'],'phone'=>$vis['phone']??'','company'=>$vis['company']??''];
        $next = $_GET['next'] ?? '/account.php';
        if (!str_starts_with($next,'/')) $next='/account.php';
        header('Location: '.$next); exit;
    }
}
$nav = sb_nav();
?>
<!DOCTYPE html><html lang="<?= esc($GLOBALS['lang']??'en') ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — 7 Boys®</title><link rel="stylesheet" href="<?= asset_css() ?>"></head><body>
<?= site_header($nav) ?>
<section class="cat-hero"><div class="inner"><h1>Welcome Back</h1><p>Login to track your quotes and orders.</p></div></section>
<main class="container" style="max-width:460px;margin:32px auto 60px">
<form method="post" class="auth-card">
<?php if($err): ?><div style="background:#fee;border:1px solid #fcc;color:#900;padding:12px 16px;border-radius:10px;margin-bottom:16px"><?= esc($err) ?></div><?php endif; ?>
<?php if(isset($_GET['registered'])): ?><div style="background:#e8f3ec;border:1px solid #b5d9c2;color:#1f5a38;padding:12px 16px;border-radius:10px;margin-bottom:16px">Account created! Please login.</div><?php endif; ?>
<label>Email<input name="email" type="email" required value="<?= esc($_POST['email']??'') ?>" placeholder="you@example.com" style="width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px"></label>
<label>Password<input name="pass" type="password" required placeholder="••••••••" style="width:100%;padding:12px;margin:6px 0 18px;border:1px solid var(--line);border-radius:10px"></label>
<button class="btn" style="width:100%;justify-content:center">Login</button>
<p style="text-align:center;margin-top:16px;color:var(--muted)">No account? <a href="/register.php" style="color:var(--green);font-weight:600">Create one</a> &nbsp;·&nbsp; <a href="/admin/login.php" style="color:var(--muted)">Admin login</a></p>
</form>
</main>
<?= site_footer() ?><?= whatsapp_float(load_ext()) ?>
</body></html>
