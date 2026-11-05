<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('services.view');

$serviceTypes = ['CCTV Troubleshooting', 'CCTV Repair', 'Camera Replacement', 'DVR/NVR Configuration',
                 'Mobile Viewing Setup', 'Network Troubleshooting', 'Computer Repair', 'Software Installation',
                 'Hardware Maintenance', 'Security Monitoring Check', 'Other IT Service'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('services.manage');
    $action = input('action');

    if ($action === 'save') {
        $id = post_int('id');
        $data = [
            (int)post_int('client_id'),
            (string)input('service_type'),
            (string)input('description'),
            nn(input('location')),
            (string)input('request_date', date('Y-m-d')),
            nn(input('preferred_date')),
            (string)input('priority', 'Normal'),
            post_int('assigned_to'),
            post_num('service_fee'),
            (string)input('status', 'New'),
            nn(input('completion_notes')),
        ];
        if (!$data[0] || $data[2] === '') {
            flash('Choose a client and describe the problem.', 'danger');
            redirect(url_with([]));
        }
        if ($data[9] === 'Completed') {
            $completed = date('Y-m-d H:i:s');
        } else {
            $completed = null;
        }

        if ($id) {
            $prev = one('SELECT completed_at FROM service_requests WHERE id = ?', [$id]);
            if ($data[9] === 'Completed' && $prev && $prev['completed_at']) {
                $completed = $prev['completed_at'];
            }
            q('UPDATE service_requests SET client_id=?, service_type=?, description=?, location=?, request_date=?,
                 preferred_date=?, priority=?, assigned_to=?, service_fee=?, status=?, completion_notes=?, completed_at=?
               WHERE id=?', array_merge($data, [$completed, $id]));
            log_activity('Services', 'update', $id, 'Updated service request #' . $id);
            flash('Service request updated.');
        } else {
            $no = next_code('service_requests', 'request_no', 'SR');
            q('INSERT INTO service_requests (request_no, client_id, service_type, description, location, request_date,
                 preferred_date, priority, assigned_to, service_fee, status, completion_notes, completed_at, source)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"Staff")', array_merge([$no], $data, [$completed]));
            $id = (int)db()->lastInsertId();
            log_activity('Services', 'create', $id, 'Logged service request ' . $no);
            flash('Service request ' . $no . ' logged.');
        }
        redirect(url_with([]));
    }

    if ($action === 'quick_status') {
        $id     = (int)post_int('id');
        $status = (string)input('status');
        $completed = $status === 'Completed' ? date('Y-m-d H:i:s') : null;
        q('UPDATE service_requests SET status = ?, completed_at = ? WHERE id = ?', [$status, $completed, $id]);
        log_activity('Services', 'status', $id, 'Service request #' . $id . ' set to ' . $status);
        flash('Status changed to ' . $status . '.');
        redirect(url_with([]));
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        q('DELETE FROM service_requests WHERE id = ?', [$id]);
        log_activity('Services', 'delete', $id, 'Deleted service request #' . $id);
        flash('Service request deleted.');
        redirect(url_with([]));
    }
}

/* ------------------------------ Query ------------------------------ */
$search   = (string)input('q', '');
$status   = (string)input('status', '');
$priority = (string)input('priority', '');
$tech     = (int)input('tech', 0);

$where = ['1=1'];
$args  = [];
if ($search !== '') {
    $where[] = '(s.request_no LIKE ? OR s.description LIKE ? OR s.service_type LIKE ? OR c.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($status !== '')   { $where[] = 's.status = ?';      $args[] = $status; }
if ($priority !== '') { $where[] = 's.priority = ?';    $args[] = $priority; }
if ($tech)            { $where[] = 's.assigned_to = ?'; $args[] = $tech; }
$whereSql = implode(' AND ', $where);

$requests = all(
    "SELECT s.*, c.full_name AS client_name, c.contact_number, e.full_name AS tech_name
     FROM service_requests s JOIN clients c ON c.id = s.client_id
     LEFT JOIN employees e ON e.id = s.assigned_to
     WHERE $whereSql
     ORDER BY FIELD(s.status,'New','Assigned','In Progress','On Hold','Completed','Cancelled'),
              FIELD(s.priority,'Urgent','High','Normal','Low'), s.request_date DESC",
    $args
);

if (input('export') === 'csv') {
    export_csv('service-requests-' . date('Y-m-d') . '.csv',
        ['Request no', 'Client', 'Type', 'Description', 'Requested', 'Priority', 'Technician', 'Fee', 'Status'],
        array_map(fn($r) => [$r['request_no'], $r['client_name'], $r['service_type'], $r['description'],
                             $r['request_date'], $r['priority'], $r['tech_name'], $r['service_fee'], $r['status']], $requests));
}

$clients = all('SELECT id, full_name, client_code, address FROM clients WHERE status = "active" ORDER BY full_name');
$techs   = all('SELECT id, full_name, position FROM employees WHERE status = "active" ORDER BY is_technician DESC, full_name');
$open    = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE status IN ('New','Assigned','In Progress','On Hold')");
$urgent  = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE priority IN ('Urgent','High') AND status NOT IN ('Completed','Cancelled')");
$doneMonth = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE status='Completed' AND completed_at >= ?", [date('Y-m-01')]);
$feesMonth = (float)scalar("SELECT COALESCE(SUM(service_fee),0) FROM service_requests WHERE status='Completed' AND completed_at >= ?", [date('Y-m-01')]);

$pageTitle = 'Service requests';
$pageSub   = $open . ' open · ' . count($requests) . ' shown';
if (can('services.manage')) {
    $pageActions = '<button class="btn" data-modal="srModal">Log request</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Open jobs</div><div class="value"><?= $open ?></div>
    <div class="foot">new, assigned or in progress</div></div>
  <div class="stat is-danger"><div class="label">Urgent and high</div><div class="value"><?= $urgent ?></div>
    <div class="foot">still unresolved</div></div>
  <div class="stat is-ok"><div class="label">Completed this month</div><div class="value"><?= $doneMonth ?></div>
    <div class="foot">since <?= date('M 1') ?></div></div>
  <div class="stat is-copper"><div class="label">Service fees this month</div><div class="value sm"><?= money($feesMonth) ?></div>
    <div class="foot">from completed jobs</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search request, client, problem…"></div>
      <div class="field"><select class="input" name="status">
        <option value="">Any status</option>
        <?php foreach (['New', 'Assigned', 'In Progress', 'On Hold', 'Completed', 'Cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select></div>
      <div class="field"><select class="input" name="priority">
        <option value="">Any priority</option>
        <?php foreach (['Urgent', 'High', 'Normal', 'Low'] as $pr): ?>
          <option value="<?= $pr ?>" <?= $priority === $pr ? 'selected' : '' ?>><?= $pr ?></option>
        <?php endforeach; ?>
      </select></div>
      <div class="field"><select class="input" name="tech">
        <option value="">Any technician</option>
        <?php foreach ($techs as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tech === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['full_name']) ?></option>
        <?php endforeach; ?>
      </select></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <?php if ($search || $status || $priority || $tech): ?><a class="btn btn-outline" href="services.php">Clear</a><?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$requests): ?>
      <div class="empty"><strong>No service requests match</strong>Log a new job with the button at the top right.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Request</th><th>Client</th><th>Problem</th><th>Technician</th><th>Priority</th>
        <th class="num">Fee</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $s): ?>
        <tr>
          <td><span class="row-main"><?= e($s['request_no']) ?></span>
              <div class="row-sub"><?= e($s['service_type']) ?><?= $s['source'] === 'Portal' ? ' · <span class="pill">portal</span>' : '' ?></div></td>
          <td><?= e($s['client_name']) ?><div class="row-sub"><?= e($s['contact_number'] ?: '') ?></div></td>
          <td class="small" style="max-width:280px"><?= e($s['description']) ?>
              <div class="row-sub">logged <?= fdate($s['request_date']) ?><?= $s['preferred_date'] ? ' · wants ' . fdate($s['preferred_date']) : '' ?></div></td>
          <td class="small"><?= e($s['tech_name'] ?: '—') ?></td>
          <td><?= badge($s['priority']) ?></td>
          <td class="num"><?= money($s['service_fee']) ?></td>
          <td><?= badge($s['status']) ?></td>
          <td class="actions-cell">
            <?php if (can('services.manage')): ?>
              <button class="btn btn-sm btn-outline" data-modal="srModal"
                data-row='<?= e(json_encode([
                  'id' => $s['id'], 'client_id' => $s['client_id'], 'service_type' => $s['service_type'],
                  'description' => $s['description'], 'location' => $s['location'], 'request_date' => $s['request_date'],
                  'preferred_date' => $s['preferred_date'], 'priority' => $s['priority'], 'assigned_to' => $s['assigned_to'],
                  'service_fee' => $s['service_fee'], 'status' => $s['status'], 'completion_notes' => $s['completion_notes'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
              <?php if (!in_array($s['status'], ['Completed', 'Cancelled'], true)): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="quick_status">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="status" value="Completed">
                  <button class="btn btn-sm btn-ok" type="submit">Complete</button>
                </form>
              <?php endif; ?>
              <?php if (can('sales.manage') && $s['status'] === 'Completed' && $s['service_fee'] > 0): ?>
                <a class="btn btn-sm btn-outline" href="sales.php?from_service=<?= (int)$s['id'] ?>">Invoice</a>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('services.manage')): ?>
<div class="modal-backdrop" id="srModal" data-title-new="Log a service request" data-title-edit="Edit service request">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>Log a service request</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="field"><label class="required">Client</label>
            <select class="input" name="client_id" required>
              <option value="">Select a client…</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?> (<?= e($c['client_code']) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label class="required">Service type</label>
            <select class="input" name="service_type" required>
              <?php foreach ($serviceTypes as $t): ?><option><?= $t ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label class="required">Problem description</label>
          <textarea class="input" name="description" required placeholder="Camera 4 at the stockroom shows no signal since yesterday."></textarea></div>
        <div class="field"><label>Service location</label><input class="input" name="location"></div>
        <div class="form-row three">
          <div class="field"><label>Request date</label><input class="input" name="request_date" type="date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>Preferred visit date</label><input class="input" name="preferred_date" type="date"></div>
          <div class="field"><label>Priority</label>
            <select class="input" name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></div>
        </div>
        <div class="form-row three">
          <div class="field"><label>Assign technician</label>
            <select class="input" name="assigned_to">
              <option value="">Not yet assigned</option>
              <?php foreach ($techs as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Service fee</label><input class="input" name="service_fee" type="number" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status">
              <?php foreach (['New', 'Assigned', 'In Progress', 'On Hold', 'Completed', 'Cancelled'] as $s): ?><option><?= $s ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Completion notes</label>
          <textarea class="input" name="completion_notes" placeholder="What was found and what was done."></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save request</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
