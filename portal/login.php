<?php
require_once __DIR__ . '/../includes/auth.php';

if (isset($_SESSION['client'])) { redirect(base_url('portal/index.php')); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (attempt_client_login((string)input('username', ''), (string)input('password', ''))) {
        redirect(base_url('portal/index.php'));
    }
    $error = 'That username and password do not match an active portal account.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Client portal · JTSOFTWARE I.T. SERVICES</title>
<link rel="icon" href="<?= e(base_url('assets/img/logo.jpg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
  <section class="auth-brand">
    <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="JT Software and Hardware Solution">
    <h1>Your CCTV system,<br>start to finish,<br>in one place.</h1>
    <p>Check how your installation is going, read your quotation, see what you have paid
       and send us a new service request without having to call.</p>
    <ul class="auth-points">
      <li>Installation progress updated by the crew on site</li>
      <li>Quotations and invoices you can open any time</li>
      <li>Service requests with the technician assigned to you</li>
      <li>Your visit schedule</li>
    </ul>
  </section>
  <section class="auth-form">
    <div class="auth-card">
      <h2>Client sign in</h2>
      <p class="sub">Use the username we gave you.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label for="username">Username</label>
          <input class="input" id="username" name="username" required autofocus></div>
        <div class="field"><label for="password">Password</label>
          <input class="input" id="password" name="password" type="password" required></div>
        <button class="btn btn-block" type="submit">Sign in</button>
      </form>
      <div class="cred-note">
        <strong>Demo account</strong><br>
        <code>maria.santos</code> / <code>client123</code> — any seeded client works with the same password.<br>
        No account yet? Call <?= e(setting('company_phone')) ?> and we will set one up.
      </div>
      <p class="auth-foot">Staff member? <a href="<?= e(base_url('login.php')) ?>">Sign in to the management system</a>.</p>
    </div>
  </section>
</div>
</body>
</html>
