<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('employees.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('employees.manage');
    if (input('action') === 'save') {
        $empId = (int)post_int('employee_id');
        $date  = (string)input('work_date', date('Y-m-d'));
        if (!$empId) {
            flash('Choose an employee.', 'danger');
            redirect('attendance.php');
        }
        q('INSERT INTO attendance (employee_id, work_date, time_in, time_out, status, remarks)
           VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), time_out = VALUES(time_out),
             status = VALUES(status), remarks = VALUES(remarks)',
          [$empId, $date, nn(input('time_in')), nn(input('time_out')), (string)input('status', 'Present'), nn(input('remarks'))]);
        log_activity('Employees', 'attendance', $empId, 'Recorded attendance for ' . $date);
        flash('Attendance saved.');
        redirect(url_with([]));
    }
    if (input('action') === 'delete') {
        q('DELETE FROM attendance WHERE id = ?', [(int)post_int('id')]);
        flash('Attendance record removed.');
        redirect(url_with([]));
    }
}

$employee = (int)input('employee', 0);
$from = (string)input('from', date('Y-m-01'));
$to   = (string)input('to', date('Y-m-d'));

$where = ['a.work_date BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($employee) { $where[] = 'a.employee_id = ?'; $args[] = $employee; }
$whereSql = implode(' AND ', $where);

$rows = all("SELECT a.*, e.full_name, e.employee_code, e.daily_rate FROM attendance a
             JOIN employees e ON e.id = a.employee_id WHERE $whereSql
             ORDER BY a.work_date DESC, e.full_name", $args);

if (input('export') === 'csv') {
    export_csv('attendance-' . date('Y-m-d') . '.csv',
        ['Date', 'Employee', 'Code', 'Time in', 'Time out', 'Status', 'Remarks'],
        array_map(fn($r) => [$r['work_date'], $r['full_name'], $r['employee_code'], $r['time_in'], $r['time_out'],
                             $r['status'], $r['remarks']], $rows));
}

$employees = all('SELECT id, full_name, employee_code FROM employees WHERE status = "active" ORDER BY full_name');
$present   = count(array_filter($rows, fn($r) => in_array($r['status'], ['Present', 'Late', 'Field Work'], true)));
$absent    = count(array_filter($rows, fn($r) => $r['status'] === 'Absent'));

$pageTitle = 'Attendance';
$pageSub   = count($rows) . ' records between ' . fdate($from) . ' and ' . fdate($to);
if (can('employees.manage')) { $pageActions = '<button class="btn" data-modal="attModal">Record attendance</button>'; }
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <a class="tab" href="employees.php">Employees</a>
  <a class="tab active" href="attendance.php">Attendance</a>
</div>

<div class="grid grid-3" style="margin-bottom:16px">
  <div class="stat is-ok"><div class="label">Days worked</div><div class="value"><?= $present ?></div>
    <div class="foot">present, late or on field work</div></div>
  <div class="stat is-danger"><div class="label">Absences</div><div class="value"><?= $absent ?></div>
    <div class="foot">in this period</div></div>
  <div class="stat is-blue"><div class="label">Records</div><div class="value"><?= count($rows) ?></div>
    <div class="foot"><?= count($employees) ?> active employees</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><label>Employee</label>
        <select class="input" name="employee"><option value="">Everyone</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>" <?= $employee === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>No attendance in this period</strong>Record the day's attendance with the button at the top right.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Date</th><th>Employee</th><th>Time in</th><th>Time out</th><th>Status</th><th>Remarks</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= fdate($r['work_date'], 'D, M j, Y') ?></td>
          <td><a class="row-main" href="employee_view.php?id=<?= (int)$r['employee_id'] ?>"><?= e($r['full_name']) ?></a>
              <div class="row-sub"><?= e($r['employee_code']) ?></div></td>
          <td><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—' ?></td>
          <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="small muted"><?= e($r['remarks'] ?: '—') ?></td>
          <td class="actions-cell">
            <?php if (can('employees.manage')): ?>
              <form method="post" data-confirm="Delete this record?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="linkbtn" type="submit">delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('employees.manage')): ?>
<div class="modal-backdrop" id="attModal">
  <div class="modal narrow"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="modal-head"><h3>Record attendance</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="field"><label class="required">Employee</label>
        <select class="input" name="employee_id" required><option value="">Choose…</option>
          <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Date</label><input class="input" type="date" name="work_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-row">
        <div class="field"><label>Time in</label><input class="input" type="time" name="time_in" value="08:00"></div>
        <div class="field"><label>Time out</label><input class="input" type="time" name="time_out" value="17:00"></div>
      </div>
      <div class="field"><label>Status</label>
        <select class="input" name="status">
          <?php foreach (['Present', 'Late', 'Half Day', 'Field Work', 'Leave', 'Absent'] as $s): ?><option><?= $s ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Remarks</label><input class="input" name="remarks" placeholder="Fernandez Apartments installation"></div>
      <p class="hint">Saving again for the same employee and date updates that record.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save attendance</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
