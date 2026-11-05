<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('employees.view');

$id = (int)input('id', 0);
$emp = one('SELECT e.*, u.username, r.name AS role_name FROM employees e
            LEFT JOIN users u ON u.id = e.user_id LEFT JOIN roles r ON r.id = u.role_id WHERE e.id = ?', [$id]);
if (!$emp) {
    flash('That employee no longer exists.', 'danger');
    redirect(base_url('employees.php'));
}

$projects = all('SELECT p.*, pa.role_in_project, c.full_name AS client_name
                 FROM project_assignments pa JOIN cctv_projects p ON p.id = pa.project_id
                 JOIN clients c ON c.id = p.client_id WHERE pa.employee_id = ?
                 ORDER BY p.installation_date DESC', [$id]);
$services = all('SELECT s.*, c.full_name AS client_name FROM service_requests s JOIN clients c ON c.id = s.client_id
                 WHERE s.assigned_to = ? ORDER BY s.request_date DESC LIMIT 25', [$id]);
$bookings = all('SELECT a.*, c.full_name AS client_name FROM appointments a LEFT JOIN clients c ON c.id = a.client_id
                 WHERE a.employee_id = ? AND a.start_datetime >= NOW() - INTERVAL 7 DAY
                 ORDER BY a.start_datetime LIMIT 15', [$id]);
$attendance = all('SELECT * FROM attendance WHERE employee_id = ? ORDER BY work_date DESC LIMIT 20', [$id]);

$doneProjects = count(array_filter($projects, fn($p) => $p['status'] === 'Completed'));
$doneServices = count(array_filter($services, fn($s) => $s['status'] === 'Completed'));
$feesEarned   = array_sum(array_map(fn($s) => $s['status'] === 'Completed' ? (float)$s['service_fee'] : 0, $services));
$daysPresent  = count(array_filter($attendance, fn($a) => in_array($a['status'], ['Present', 'Late', 'Field Work'], true)));

$pageTitle = $emp['full_name'];
$pageSub   = $emp['employee_code'] . ' · ' . ($emp['position'] ?: 'no position set');
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('employees.php')) . '">All employees</a>';
include __DIR__ . '/includes/header.php';
?>
<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Projects assigned</div><div class="value"><?= count($projects) ?></div>
    <div class="foot"><?= $doneProjects ?> completed</div></div>
  <div class="stat is-copper"><div class="label">Service jobs</div><div class="value"><?= count($services) ?></div>
    <div class="foot"><?= $doneServices ?> completed</div></div>
  <div class="stat is-ok"><div class="label">Service fees generated</div><div class="value sm"><?= money($feesEarned) ?></div>
    <div class="foot">from completed jobs</div></div>
  <div class="stat"><div class="label">Recent attendance</div><div class="value"><?= $daysPresent ?> / <?= count($attendance) ?></div>
    <div class="foot">days present in the last entries</div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Project assignments</h2></div>
      <div class="card-body tight">
        <?php if (!$projects): ?><div class="empty">Not assigned to any installation yet.</div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Project</th><th>Client</th><th>Role</th><th>Install date</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($projects as $p): ?>
            <tr><td><a class="row-main" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?></a>
                    <div class="row-sub"><?= e($p['title']) ?></div></td>
                <td><?= e($p['client_name']) ?></td><td class="small"><?= e($p['role_in_project']) ?></td>
                <td class="nowrap"><?= fdate($p['installation_date']) ?></td><td><?= badge($p['status']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Service jobs</h2></div>
      <div class="card-body tight">
        <?php if (!$services): ?><div class="empty">No service jobs assigned.</div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Request</th><th>Client</th><th>Type</th><th>Date</th><th class="num">Fee</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr><td class="row-main"><?= e($s['request_no']) ?></td><td><?= e($s['client_name']) ?></td>
                <td class="small"><?= e($s['service_type']) ?></td><td class="nowrap"><?= fdate($s['request_date']) ?></td>
                <td class="num"><?= money($s['service_fee']) ?></td><td><?= badge($s['status']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Profile</h2></div>
      <div class="card-body"><dl class="dl">
        <dt>Position</dt><dd><?= e($emp['position'] ?: '—') ?></dd>
        <dt>Department</dt><dd><?= e($emp['department'] ?: '—') ?></dd>
        <dt>Contact</dt><dd><?= e($emp['contact_number'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= e($emp['email'] ?: '—') ?></dd>
        <dt>Address</dt><dd><?= e($emp['address'] ?: '—') ?></dd>
        <dt>Hired</dt><dd><?= fdate($emp['hire_date']) ?></dd>
        <dt>Daily rate</dt><dd><?= money($emp['daily_rate']) ?></dd>
        <dt>Field work</dt><dd><?= $emp['is_technician'] ? 'Yes — goes on jobs' : 'Office based' ?></dd>
        <dt>System login</dt><dd><?= $emp['username'] ? e($emp['username']) . ' (' . e($emp['role_name']) . ')' : 'none' ?></dd>
        <dt>Status</dt><dd><?= badge($emp['status']) ?></dd>
      </dl></div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Upcoming bookings</h2></div>
      <div class="card-body tight">
        <?php if (!$bookings): ?><div class="empty">Nothing on the calendar.</div><?php else: ?>
        <table class="data"><tbody>
        <?php foreach ($bookings as $b): ?>
          <tr><td><span class="row-main"><?= e($b['title']) ?></span>
              <div class="row-sub"><?= fdatetime($b['start_datetime']) ?><?= $b['client_name'] ? ' · ' . e($b['client_name']) : '' ?></div></td>
              <td class="text-right"><?= badge($b['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Recent attendance</h2>
        <a class="spacer btn btn-sm btn-outline" href="attendance.php?employee=<?= (int)$emp['id'] ?>">All records</a></div>
      <div class="card-body tight">
        <?php if (!$attendance): ?><div class="empty">No attendance recorded.</div><?php else: ?>
        <table class="data"><tbody>
        <?php foreach ($attendance as $a): ?>
          <tr><td><span class="row-main"><?= fdate($a['work_date'], 'D, M j') ?></span>
              <div class="row-sub"><?= $a['time_in'] ? date('g:i A', strtotime($a['time_in'])) : '—' ?>
                 – <?= $a['time_out'] ? date('g:i A', strtotime($a['time_out'])) : '—' ?></div></td>
              <td class="text-right"><?= badge($a['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
