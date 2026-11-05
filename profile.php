<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (input('action') === 'details') {
        q('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?',
          [(string)input('full_name'), nn(input('email')), nn(input('phone')), $me['id']]);
        $_SESSION['user']['full_name'] = (string)input('full_name');
        flash('Your details were updated.');
        redirect('profile.php');
    }
    if (input('action') === 'password') {
        $current = (string)input('current_password');
        $new     = (string)input('new_password');
        $confirm = (string)input('confirm_password');
        $hash    = scalar('SELECT password_hash FROM users WHERE id = ?', [$me['id']], '');
        if (!password_verify($current, (string)$hash)) {
            flash('Your current password is not correct.', 'danger');
        } elseif (strlen($new) < 8) {
            flash('The new password must be at least 8 characters.', 'danger');
        } elseif ($new !== $confirm) {
            flash('The two new passwords do not match.', 'danger');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            log_activity('Auth', 'password', (int)$me['id'], 'Changed own password');
            flash('Password changed.');
        }
        redirect('profile.php');
    }
}

$recent = all('SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 12', [$me['id']]);
$perms  = user_permissions();

$pageTitle = 'My account';
$pageSub   = $me['role_name'];
include __DIR__ . '/includes/header.php';
?>
<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>My details</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="details">
          <div class="form-row">
            <div class="field"><label>Full name</label><input class="input" name="full_name" value="<?= e($me['full_name']) ?>" required></div>
            <div class="field"><label>Username</label><input class="input" value="<?= e($me['username']) ?>" disabled></div>
          </div>
          <div class="form-row">
            <div class="field"><label>Email</label><input class="input" name="email" type="email" value="<?= e($me['email']) ?>"></div>
            <div class="field"><label>Phone</label><input class="input" name="phone" value="<?= e($me['phone']) ?>"></div>
          </div>
          <button class="btn" type="submit">Save details</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Change password</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="field"><label>Current password</label><input class="input" name="current_password" type="password" required></div>
          <div class="form-row">
            <div class="field"><label>New password</label><input class="input" name="new_password" type="password" required></div>
            <div class="field"><label>Repeat new password</label><input class="input" name="confirm_password" type="password" required></div>
          </div>
          <button class="btn" type="submit">Change password</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>My recent activity</h2></div>
      <div class="card-body">
        <ul class="timeline">
          <?php foreach ($recent as $r): ?>
            <li><div><?= e($r['description']) ?></div>
                <div class="t-meta"><?= e($r['module']) ?> · <?= fdatetime($r['created_at']) ?></div></li>
          <?php endforeach; ?>
          <?php if (!$recent): ?><li class="muted">Nothing logged yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Access</h2></div>
      <div class="card-body">
        <dl class="dl">
          <dt>Role</dt><dd><?= e($me['role_name']) ?></dd>
          <dt>Last sign-in</dt><dd><?= $me['last_login'] ? fdatetime($me['last_login']) : 'first session' ?></dd>
          <dt>Account created</dt><dd><?= fdate($me['created_at']) ?></dd>
        </dl>
        <h3 style="margin-top:14px">What you can do</h3>
        <?php if ((int)$me['role_id'] === 1): ?>
          <p class="small">Full access to every module.</p>
        <?php else: ?>
          <ul class="small" style="padding-left:18px;margin:0">
            <?php foreach ($perms as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
