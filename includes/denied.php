<?php
$pageTitle = 'Access denied';
include __DIR__ . '/header.php';
?>
<div class="card"><div class="card-body empty">
  <strong>This area is restricted</strong>
  <p>Your role (<?= e(current_user()['role_name']) ?>) does not include access to this module.
     Ask the owner or a manager to adjust your role if you need it.</p>
  <a class="btn btn-outline" href="<?= e(base_url('dashboard.php')) ?>">Back to dashboard</a>
</div></div>
<?php include __DIR__ . '/footer.php'; ?>
