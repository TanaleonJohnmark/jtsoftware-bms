<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('users.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $roleId = (int)post_int('role_id');
    if ($roleId === 1) {
        flash('The owner role always keeps every permission.', 'warn');
        redirect('roles.php');
    }
    $granted = array_map('intval', $_POST['permissions'] ?? []);
    q('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    foreach ($granted as $permId) {
        q('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$roleId, $permId]);
    }
    unset($_SESSION['permissions']);
    log_activity('Users', 'permissions', $roleId, 'Updated permissions for role #' . $roleId);
    flash('Permissions saved for this role.');
    redirect('roles.php?role=' . $roleId);
}

$roles  = all('SELECT * FROM roles ORDER BY id');
$roleId = (int)input('role', 2);
$role   = one('SELECT * FROM roles WHERE id = ?', [$roleId]) ?: $roles[1];
$roleId = (int)$role['id'];

$permissions = all('SELECT * FROM permissions ORDER BY module, id');
$granted     = array_column(all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id');

$grouped = [];
foreach ($permissions as $p) { $grouped[$p['module']][] = $p; }

$pageTitle = 'Roles and permissions';
$pageSub   = 'What each role is allowed to open and change';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('users.php')) . '">Back to accounts</a>';
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <?php foreach ($roles as $r): ?>
    <a class="tab <?= (int)$r['id'] === $roleId ? 'active' : '' ?>" href="roles.php?role=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head"><h2><?= e($role['name']) ?></h2>
    <span class="spacer small muted"><?= e($role['description']) ?></span></div>
  <div class="card-body">
    <?php if ($roleId === 1): ?>
      <div class="alert alert-info">The owner role has full access to every module and cannot be restricted.</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="role_id" value="<?= $roleId ?>">
      <div class="grid grid-3">
        <?php foreach ($grouped as $module => $perms): ?>
          <div>
            <h3><?= e($module) ?></h3>
            <?php foreach ($perms as $p): ?>
              <label class="check" style="margin-bottom:6px">
                <input type="checkbox" name="permissions[]" value="<?= (int)$p['id'] ?>"
                  <?= in_array((int)$p['id'], array_map('intval', $granted), true) ? 'checked' : '' ?>
                  <?= $roleId === 1 ? 'disabled checked' : '' ?>>
                <span><?= e($p['label']) ?><br><span class="small muted"><?= e($p['code']) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($roleId !== 1): ?>
        <div style="margin-top:18px"><button class="btn" type="submit">Save permissions for <?= e($role['name']) ?></button></div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
