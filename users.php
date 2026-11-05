<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('users.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = input('action');

    if ($action === 'save') {
        $id       = post_int('id');
        $username = (string)input('username');
        $password = (string)input('password');
        $data = [(int)post_int('role_id'), (string)input('full_name'), $username,
                 nn(input('email')), nn(input('phone')),
                 input('status') === 'inactive' ? 'inactive' : 'active'];

        if ($data[1] === '' || $username === '' || !$data[0]) {
            flash('Name, username and role are all required.', 'danger');
            redirect('users.php');
        }
        if (scalar('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, (int)$id])) {
            flash('That username is already taken.', 'danger');
            redirect('users.php');
        }
        if ($password !== '' && strlen($password) < 8) {
            flash('Passwords must be at least 8 characters.', 'danger');
            redirect('users.php');
        }
        if (!$id && $password === '') {
            flash('Set a password for the new account.', 'danger');
            redirect('users.php');
        }

        if ($id) {
            if ((int)$id === (int)current_user()['id'] && ($data[5] === 'inactive' || (int)$data[0] !== 1)) {
                flash('You cannot remove your own access while signed in.', 'warn');
                redirect('users.php');
            }
            q('UPDATE users SET role_id=?, full_name=?, username=?, email=?, phone=?, status=? WHERE id=?',
              array_merge($data, [$id]));
            if ($password !== '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            log_activity('Users', 'update', (int)$id, 'Updated account ' . $username);
            flash('Account updated.');
        } else {
            q('INSERT INTO users (role_id, full_name, username, email, phone, status, password_hash) VALUES (?,?,?,?,?,?,?)',
              array_merge($data, [password_hash($password, PASSWORD_DEFAULT)]));
            log_activity('Users', 'create', (int)db()->lastInsertId(), 'Created account ' . $username);
            flash('Account created.');
        }
        redirect('users.php');
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        if ($id === (int)current_user()['id']) {
            flash('You cannot delete the account you are signed in with.', 'warn');
        } elseif ($id === 1) {
            flash('The owner account cannot be deleted.', 'warn');
        } else {
            q('UPDATE employees SET user_id = NULL WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            log_activity('Users', 'delete', $id, 'Deleted account #' . $id);
            flash('Account deleted.');
        }
        redirect('users.php');
    }
}

$users = all('SELECT u.*, r.name AS role_name,
        (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.id) AS actions
     FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.id, u.full_name');
$roles = all('SELECT * FROM roles ORDER BY id');

$pageTitle   = 'User accounts';
$pageSub     = count($users) . ' accounts across ' . count($roles) . ' roles';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('roles.php')) . '">Roles and permissions</a>'
             . ' <button class="btn" data-modal="userModal">Add account</button>';
include __DIR__ . '/includes/header.php';
?>
<div class="card"><div class="card-body tight">
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Last signed in</th>
      <th class="num">Actions logged</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u2): ?>
      <tr>
        <td><span class="row-main"><?= e($u2['full_name']) ?></span>
            <?php if ((int)$u2['id'] === (int)current_user()['id']): ?><div class="row-sub">this is you</div><?php endif; ?></td>
        <td><?= e($u2['username']) ?></td>
        <td><span class="badge badge-info"><?= e($u2['role_name']) ?></span></td>
        <td class="small"><?= e($u2['email'] ?: '—') ?><?php if ($u2['phone']): ?><div class="row-sub"><?= e($u2['phone']) ?></div><?php endif; ?></td>
        <td class="small"><?= $u2['last_login'] ? fdatetime($u2['last_login']) : 'never' ?></td>
        <td class="num"><?= (int)$u2['actions'] ?></td>
        <td><?= badge($u2['status']) ?></td>
        <td class="actions-cell">
          <button class="btn btn-sm btn-outline" data-modal="userModal"
            data-row='<?= e(json_encode(['id' => $u2['id'], 'full_name' => $u2['full_name'], 'username' => $u2['username'],
              'email' => $u2['email'], 'phone' => $u2['phone'], 'role_id' => $u2['role_id'], 'status' => $u2['status']],
              JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
          <?php if ((int)$u2['id'] !== (int)current_user()['id'] && (int)$u2['id'] !== 1): ?>
            <form method="post" style="display:inline" data-confirm="Delete the account for <?= e($u2['full_name']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u2['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>

<div class="modal-backdrop" id="userModal" data-title-new="Add user account" data-title-edit="Edit user account">
  <div class="modal"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Add user account</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="field"><label class="required">Full name</label><input class="input" name="full_name" required></div>
        <div class="field"><label class="required">Username</label><input class="input" name="username" required autocomplete="off"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>Email</label><input class="input" name="email" type="email"></div>
        <div class="field"><label>Phone</label><input class="input" name="phone"></div>
      </div>
      <div class="form-row">
        <div class="field"><label class="required">Role</label>
          <select class="input" name="role_id" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?> — <?= e($r['description']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Status</label>
          <select class="input" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
      <div class="field"><label>Password</label>
        <input class="input" name="password" type="text" autocomplete="new-password" placeholder="Leave blank to keep the current password">
        <p class="hint">At least 8 characters. Passwords are stored as bcrypt hashes, never as plain text.</p></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save account</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
