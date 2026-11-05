<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$cid = (int)current_client()['id'];

$upcoming = all('SELECT a.*, e.full_name AS tech, p.project_code FROM appointments a
                 LEFT JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN cctv_projects p ON p.id = a.project_id
                 WHERE a.client_id = ? AND a.start_datetime >= NOW() ORDER BY a.start_datetime', [$cid]);
$past     = all('SELECT a.*, e.full_name AS tech FROM appointments a LEFT JOIN employees e ON e.id = a.employee_id
                 WHERE a.client_id = ? AND a.start_datetime < NOW() ORDER BY a.start_datetime DESC LIMIT 15', [$cid]);

$pageTitle = 'Visit schedule';
$pageSub   = 'When we are coming, and who is coming';
include __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-head"><h2>Upcoming visits</h2></div>
  <div class="card-body tight">
    <?php if (!$upcoming): ?>
      <div class="empty"><strong>Nothing scheduled</strong>Send a service request and we will book a visit.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>When</th><th>Visit</th><th>Technician</th><th>Where</th><th>Type</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $a): ?>
        <tr><td class="nowrap"><span class="row-main"><?= fdate($a['start_datetime'], 'D, M j') ?></span>
            <div class="row-sub"><?= date('g:i A', strtotime($a['start_datetime'])) ?> – <?= date('g:i A', strtotime($a['end_datetime'])) ?></div></td>
          <td><?= e($a['title']) ?><?php if ($a['project_code']): ?><div class="row-sub"><?= e($a['project_code']) ?></div><?php endif; ?></td>
          <td><?= e($a['tech'] ?: 'to be assigned') ?></td>
          <td class="small muted"><?= e($a['location'] ?: '—') ?></td>
          <td><?= badge($a['appointment_type']) ?></td><td><?= badge($a['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Past visits</h2></div>
  <div class="card-body tight">
    <?php if (!$past): ?><div class="empty">No past visits on record.</div><?php else: ?>
    <table class="data"><tbody>
    <?php foreach ($past as $a): ?>
      <tr><td><span class="row-main"><?= e($a['title']) ?></span>
          <div class="row-sub"><?= fdatetime($a['start_datetime']) ?><?= $a['tech'] ? ' · ' . e($a['tech']) : '' ?></div></td>
        <td class="text-right"><?= badge($a['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
