<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    redirect(base_url('dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = (string)input('username', '');
    $password = (string)input('password', '');
    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (attempt_login($username, $password)) {
        $to = $_SESSION['redirect_after_login'] ?? base_url('dashboard.php');
        unset($_SESSION['redirect_after_login']);
        redirect($to);
    } else {
        $error = 'That username and password do not match an active account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · JTSOFTWARE I.T. SERVICES</title>
<link rel="icon" href="assets/img/logo.jpg">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
  <section class="auth-brand">
    <img src="assets/img/logo.jpg" alt="JT Software and Hardware Solution">
    <h1>Run every installation,<br>job order and invoice<br>from one place.</h1>
    <p>The business management system of JT Software and Hardware Solution — built around how
       CCTV and I.T. service work actually moves through the shop.</p>
    <ul class="auth-points">
      <li>CCTV installation projects from quotation to turnover</li>
      <li>Service requests assigned to the right technician</li>
      <li>Stock deducted automatically as equipment leaves</li>
      <li>Payments, balances and profit tracked per month</li>
    </ul>
  </section>

  <section class="auth-form">
    <div class="auth-card">
      <h2>Sign in</h2>
      <p class="sub">Use the account issued to you by the owner.</p>

      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">Username</label>
          <input class="input" id="username" name="username" required autofocus
                 value="<?= e(input('username', '')) ?>">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input" id="password" name="password" type="password" required>
        </div>
        <button class="btn btn-block" type="submit">Sign in</button>
      </form>

      <div class="cred-note">
        <strong>Test accounts for this installation</strong><br>
        Owner <code>admin</code> / <code>admin123</code> · Manager <code>manager</code> / <code>manager123</code><br>
        Staff <code>staff</code> / <code>staff123</code> · Technician <code>technician</code> / <code>tech123</code><br>
        Accountant <code>accountant</code> / <code>accounting123</code><br>
        Change these in User Accounts before the system is used for real work.
      </div>

      <p class="auth-foot">
        Are you a client? <a href="portal/login.php">Open the client portal</a>.
      </p>
    </div>
  </section>
</div>
</body>
</html>
