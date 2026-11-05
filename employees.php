<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('employees.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('employees.manage');
    $action = input('action');

    if ($action === 'save') {
        $id = post_int('id');
        $data = [
            (string)input('full_name'), nn(input('position')), nn(input('department')),
            nn(input('contact_number')), nn(input('email')), nn(input('address')),
            nn(input('hire_date')), post_num('daily_rate'),
            isset($_POST['is_technician']) ? 1 : 0, post_int('user_id'),
            input('status') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($data[0] === '') {
            flash('An employee name is required.', 'danger');
            redirect('employees.php');
        }
        if ($id) {
            q('UPDATE employees SET full_name=?, position=?, department=?, contact_number=?, email=?, address=?,
                 hire_date=?, daily_rate=?, is_technician=?, user_id=?, status=? WHERE id=?', array_merge($data, [$id]));
            log_activity('Employees', 'update', (int)$id, 'Updated employee ' . $data[0]);
            flash('Employee updated.');
        } else {
            $code = next_code('employees', 'employee_code', 'EMP');
            q('INSERT INTO employees (employee_code, full_name, position, department, contact_number, email, address,
                 hire_date, daily_rate, is_technician, user_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
              array_merge([$code], $data));
            log_activity('Employees', 'create', (int)db()->lastInsertId(), 'Added employee ' . $code);
            flash('Employee ' . $code . ' added.');
        }
        redirect('employees.php');
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        $linked = (int)scalar('SELECT (SELECT COUNT(*) FROM project_assignments WHERE employee_id = ?)
                                    + (SELECT COUNT(*) FROM service_requests WHERE assigned_to = ?)
                                    + (SELECT COUNT(*) FROM appointments WHERE employee_id = ?)', [$id, $id, $id]);
        if ($linked) {
            q('UPDATE employees SET status = "inactive" WHERE id = ?', [$id]);
            flash('This employee is on ' . $linked . ' job record(s), so the profile was set to inactive instead.', 'warn');
        } else {
            q('DELETE FROM employees WHERE id = ?', [$id]);
            flash('Employee removed.');
        }
        redirect('employees.php');
    }
}

$search = (string)input('q', '');
$only   = (string)input('only', '');
$where  = ['1=1'];
$args   = [];
if ($search !== '') {
    $where[] = '(e.full_name LIKE ? OR e.employee_code LIKE ? OR e.position LIKE ? OR e.department LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($only === 'tech')     { $where[] = 'e.is_technician = 1'; }
if ($only === 'active')   { $where[] = 'e.status = "active"'; }
$whereSql = implode(' AND ', $where);

$rows = all("SELECT e.*, u.username, r.name AS role_name,
        (SELECT COUNT(*) FROM project_assignments pa JOIN cctv_projects p ON p.id = pa.project_id
          WHERE pa.employee_id = e.id AND p.status IN ('Scheduled','Ongoing')) AS active_jobs,
        (SELECT COUNT(*) FROM service_requests s WHERE s.assigned_to = e.id AND s.status NOT IN ('Completed','Cancelled')) AS open_services
     FROM employees e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN roles r ON r.id = u.role_id
     WHERE $whereSql ORDER BY e.status, e.is_technician DESC, e.full_name", $args);

if (input('export') === 'csv') {
    export_csv('employees-' . date('Y-m-d') . '.csv',
        ['Code', 'Name', 'Position', 'Department', 'Contact', 'Email', 'Hired', 'Daily rate', 'Technician', 'Status'],
        array_map(fn($r) => [$r['employee_code'], $r['full_name'], $r['position'], $r['department'], $r['contact_number'],
                             $r['email'], $r['hire_date'], $r['daily_rate'], $r['is_technician'] ? 'Yes' : 'No', $r['status']], $rows));
}

$users = all('SELECT u.id, u.username, u.full_name FROM users u ORDER BY u.full_name');
$techCount = count(array_filter($rows, fn($r) => $r['is_technician'] && $r['status'] === 'active'));

$pageTitle = 'Employees and technicians';
$pageSub   = count($rows) . ' on record · ' . $techCount . ' active technicians';
if (can('employees.manage')) { $pageActions = '<button class="btn" data-modal="empModal">Add employee</button>'; }
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <a class="tab active" href="employees.php">Employees</a>
  <a class="tab" href="attendance.php">Attendance</a>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search name, position, department…"></div>
      <div class="field"><select class="input" name="only">
        <option value="">Everyone</option>
        <option value="tech" <?= $only === 'tech' ? 'selected' : '' ?>>Technicians only</option>
        <option value="active" <?= $only === 'active' ? 'selected' : '' ?>>Active only</option>
      </select></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>No employees match</strong>Add your technicians and office staff here.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Employee</th><th>Position</th><th>Contact</th><th>System account</th>
        <th class="num">Workload</th><th class="num">Daily rate</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a class="row-main" href="employee_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['full_name']) ?></a>
              <div class="row-sub"><?= e($r['employee_code']) ?><?= $r['is_technician'] ? ' · <span class="pill">technician</span>' : '' ?></div></td>
          <td><?= e($r['position'] ?: '—') ?><div class="row-sub"><?= e($r['department'] ?: '') ?></div></td>
          <td class="small"><?= e($r['contact_number'] ?: '—') ?><?php if ($r['email']): ?><div class="row-sub"><?= e($r['email']) ?></div><?php endif; ?></td>
          <td class="small"><?= $r['username'] ? e($r['username']) . '<div class="row-sub">' . e($r['role_name']) . '</div>' : '<span class="muted">no login</span>' ?></td>
          <td class="num"><?= (int)$r['active_jobs'] ?> project<?= (int)$r['active_jobs'] === 1 ? '' : 's' ?>
              <div class="row-sub"><?= (int)$r['open_services'] ?> service jobs</div></td>
          <td class="num"><?= money($r['daily_rate']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-outline" href="employee_view.php?id=<?= (int)$r['id'] ?>">Open</a>
            <?php if (can('employees.manage')): ?>
              <button class="btn btn-sm btn-outline" data-modal="empModal"
                data-row='<?= e(json_encode(['id' => $r['id'], 'full_name' => $r['full_name'], 'position' => $r['position'],
                  'department' => $r['department'], 'contact_number' => $r['contact_number'], 'email' => $r['email'],
                  'address' => $r['address'], 'hire_date' => $r['hire_date'], 'daily_rate' => $r['daily_rate'],
                  'is_technician' => $r['is_technician'], 'user_id' => $r['user_id'], 'status' => $r['status']],
                  JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
              <form method="post" style="display:inline" data-confirm="Remove <?= e($r['full_name']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button></form>
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
<div class="modal-backdrop" id="empModal" data-title-new="Add employee" data-title-edit="Edit employee">
  <div class="modal"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Add employee</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="field"><label class="required">Full name</label><input class="input" name="full_name" required></div>
        <div class="field"><label>Position</label><input class="input" name="position" placeholder="CCTV Technician"></div>
      </div>
      <div class="form-row three">
        <div class="field"><label>Department</label><input class="input" name="department" placeholder="Field Operations"></div>
        <div class="field"><label>Contact number</label><input class="input" name="contact_number"></div>
        <div class="field"><label>Email</label><input class="input" name="email" type="email"></div>
      </div>
      <div class="field"><label>Address</label><input class="input" name="address"></div>
      <div class="form-row three">
        <div class="field"><label>Hire date</label><input class="input" name="hire_date" type="date"></div>
        <div class="field"><label>Daily rate</label><input class="input" name="daily_rate" type="number" step="0.01" min="0" value="0"></div>
        <div class="field"><label>Status</label>
          <select class="input" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
      <div class="field"><label>Linked system account</label>
        <select class="input" name="user_id"><option value="">No login account</option>
          <?php foreach ($users as $u2): ?><option value="<?= (int)$u2['id'] ?>"><?= e($u2['full_name']) ?> (<?= e($u2['username']) ?>)</option><?php endforeach; ?>
        </select></div>
      <label class="check"><input type="checkbox" name="is_technician" value="1"> This employee goes on installation and service jobs</label>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save employee</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
