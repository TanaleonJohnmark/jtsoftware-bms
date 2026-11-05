<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('projects.view');

/* ----------------------------- Actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('projects.manage');
    $action = input('action');

    if ($action === 'save') {
        $id   = post_int('id');
        $fee  = post_num('installation_fee');
        $equip = post_num('equipment_cost');
        $disc = post_num('discount');
        $total = $fee + $equip - $disc;

        $data = [
            (int)post_int('client_id'),
            (string)input('title'),
            nn(input('location')),
            (int)post_int('camera_count', 0),
            nn(input('camera_specs')),
            (string)input('recorder_type', 'DVR'),
            nn(input('recorder_model')),
            nn(input('storage_capacity')),
            nn(input('cable_details')),
            nn(input('package_name')),
            $fee, $equip, $disc, $total,
            nn(input('installation_date')),
            nn(input('deadline')),
            (string)input('status', 'Pending'),
            nn(input('notes')),
        ];

        if (!$data[0] || $data[1] === '') {
            flash('Choose a client and give the project a title.', 'danger');
            redirect(url_with([]));
        }

        if ($id) {
            q('UPDATE cctv_projects SET client_id=?, title=?, location=?, camera_count=?, camera_specs=?,
                 recorder_type=?, recorder_model=?, storage_capacity=?, cable_details=?, package_name=?,
                 installation_fee=?, equipment_cost=?, discount=?, total_cost=?, installation_date=?, deadline=?,
                 status=?, notes=? WHERE id=?', array_merge($data, [$id]));
            if ((string)input('status') === 'Completed') {
                q('UPDATE cctv_projects SET progress = 100, completed_at = COALESCE(completed_at, CURDATE()) WHERE id = ?', [$id]);
            }
            log_activity('Projects', 'update', $id, 'Updated project #' . $id);
            flash('Project updated.');
        } else {
            $code = next_code('cctv_projects', 'project_code', 'PRJ');
            q('INSERT INTO cctv_projects (project_code, client_id, title, location, camera_count, camera_specs,
                 recorder_type, recorder_model, storage_capacity, cable_details, package_name, installation_fee,
                 equipment_cost, discount, total_cost, installation_date, deadline, status, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
               array_merge([$code], $data, [current_user()['id']]));
            $id = (int)db()->lastInsertId();
            log_activity('Projects', 'create', $id, 'Created project ' . $code);
            flash('Project ' . $code . ' created. Add equipment and technicians next.');
            redirect(base_url('project_view.php?id=' . $id));
        }
        redirect(url_with([]));
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        q('DELETE FROM cctv_projects WHERE id = ?', [$id]);
        log_activity('Projects', 'delete', $id, 'Deleted project #' . $id);
        flash('Project deleted along with its equipment lines and progress notes.');
        redirect(url_with([]));
    }
}

/* ------------------------------ Query ------------------------------ */
$search = (string)input('q', '');
$status = (string)input('status', '');
$client = (int)input('client', 0);

$where = ['1=1'];
$args  = [];
if ($search !== '') {
    $where[] = '(p.project_code LIKE ? OR p.title LIKE ? OR p.location LIKE ? OR c.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($status !== '') { $where[] = 'p.status = ?';   $args[] = $status; }
if ($client)        { $where[] = 'p.client_id = ?'; $args[] = $client; }
$whereSql = implode(' AND ', $where);

$projects = all(
    "SELECT p.*, c.full_name AS client_name, c.client_type,
            (SELECT GROUP_CONCAT(e.full_name SEPARATOR ', ') FROM project_assignments pa
              JOIN employees e ON e.id = pa.employee_id WHERE pa.project_id = p.id) AS technicians
     FROM cctv_projects p JOIN clients c ON c.id = p.client_id
     WHERE $whereSql
     ORDER BY FIELD(p.status,'Ongoing','Scheduled','Approved','Pending','Completed','Cancelled'), p.deadline IS NULL, p.deadline ASC",
    $args
);

if (input('export') === 'csv') {
    export_csv('cctv-projects-' . date('Y-m-d') . '.csv',
        ['Code', 'Client', 'Title', 'Location', 'Cameras', 'Install date', 'Deadline', 'Total', 'Progress', 'Status'],
        array_map(fn($p) => [$p['project_code'], $p['client_name'], $p['title'], $p['location'], $p['camera_count'],
                             $p['installation_date'], $p['deadline'], $p['total_cost'], $p['progress'] . '%', $p['status']], $projects));
}

$clients = all('SELECT id, full_name, client_code FROM clients WHERE status = "active" ORDER BY full_name');
$counts  = [];
foreach (all('SELECT status, COUNT(*) c FROM cctv_projects GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}

$pageTitle = 'CCTV installation projects';
$pageSub   = count($projects) . ' shown · ' . array_sum($counts) . ' total';
if (can('projects.manage')) {
    $pageActions = '<button class="btn" data-modal="projModal">New project</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-warn"><div class="label">Pending and approved</div>
    <div class="value"><?= ($counts['Pending'] ?? 0) + ($counts['Approved'] ?? 0) ?></div>
    <div class="foot">waiting to be scheduled</div></div>
  <div class="stat is-blue"><div class="label">Scheduled</div><div class="value"><?= $counts['Scheduled'] ?? 0 ?></div>
    <div class="foot">booked with a date</div></div>
  <div class="stat is-copper"><div class="label">Ongoing</div><div class="value"><?= $counts['Ongoing'] ?? 0 ?></div>
    <div class="foot">crews on site</div></div>
  <div class="stat is-ok"><div class="label">Completed</div><div class="value"><?= $counts['Completed'] ?? 0 ?></div>
    <div class="foot"><?= $counts['Cancelled'] ?? 0 ?> cancelled</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search code, title, location, client…"></div>
      <div class="field">
        <select class="input" name="status">
          <option value="">Any status</option>
          <?php foreach (['Pending', 'Approved', 'Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <select class="input" name="client">
          <option value="">All clients</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $client === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <?php if ($search || $status || $client): ?><a class="btn btn-outline" href="projects.php">Clear</a><?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$projects): ?>
      <div class="empty"><strong>No projects match these filters</strong>Create one with the button at the top right.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th>Project</th><th>Client</th><th>System</th><th>Schedule</th>
        <th>Crew</th><th class="num">Total cost</th><th>Progress</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($projects as $p):
          $late = $p['deadline'] && $p['deadline'] < date('Y-m-d') && !in_array($p['status'], ['Completed', 'Cancelled'], true); ?>
        <tr>
          <td><a class="row-main" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?></a>
              <div class="row-sub"><?= e($p['title']) ?></div></td>
          <td><?= e($p['client_name']) ?><div class="row-sub"><?= e($p['location'] ?: $p['client_type']) ?></div></td>
          <td class="small"><?= (int)$p['camera_count'] ?> cameras<div class="row-sub"><?= e($p['recorder_type']) ?> · <?= e($p['storage_capacity'] ?: 'no storage set') ?></div></td>
          <td class="small nowrap">
            <?= fdate($p['installation_date']) ?>
            <div class="row-sub <?= $late ? '' : '' ?>">due <?= fdate($p['deadline']) ?>
              <?= $late ? '<span class="badge badge-danger">overdue</span>' : '' ?></div>
          </td>
          <td class="small"><?= e($p['technicians'] ?: '—') ?></td>
          <td class="num"><?= money($p['total_cost']) ?></td>
          <td>
            <div class="progress <?= $p['progress'] >= 100 ? 'done' : '' ?>"><span style="width:<?= (int)$p['progress'] ?>%"></span></div>
            <span class="row-sub"><?= (int)$p['progress'] ?>%</span>
          </td>
          <td><?= badge($p['status']) ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-outline" href="project_view.php?id=<?= (int)$p['id'] ?>">Open</a>
            <?php if (can('projects.manage')): ?>
              <button class="btn btn-sm btn-outline" data-modal="projModal"
                data-row='<?= e(json_encode([
                  'id' => $p['id'], 'client_id' => $p['client_id'], 'title' => $p['title'], 'location' => $p['location'],
                  'camera_count' => $p['camera_count'], 'camera_specs' => $p['camera_specs'], 'recorder_type' => $p['recorder_type'],
                  'recorder_model' => $p['recorder_model'], 'storage_capacity' => $p['storage_capacity'],
                  'cable_details' => $p['cable_details'], 'package_name' => $p['package_name'],
                  'installation_fee' => $p['installation_fee'], 'equipment_cost' => $p['equipment_cost'],
                  'discount' => $p['discount'], 'installation_date' => $p['installation_date'],
                  'deadline' => $p['deadline'], 'status' => $p['status'], 'notes' => $p['notes'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('projects.manage')): ?>
<div class="modal-backdrop" id="projModal" data-title-new="New CCTV installation project" data-title-edit="Edit project">
  <div class="modal wide">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>New CCTV installation project</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="field"><label class="required">Client</label>
            <select class="input" name="client_id" required>
              <option value="">Select a client…</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?> (<?= e($c['client_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label class="required">Project title</label>
            <input class="input" name="title" required placeholder="8-Camera CCTV System - Store Name"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Installation location</label><input class="input" name="location"></div>
          <div class="field"><label>Installation package</label><input class="input" name="package_name" placeholder="Commercial 8-Cam Package"></div>
        </div>
        <div class="form-row four">
          <div class="field"><label>Number of cameras</label><input class="input" name="camera_count" type="number" min="0" value="0"></div>
          <div class="field"><label>Recorder type</label>
            <select class="input" name="recorder_type"><option>DVR</option><option>NVR</option><option>Hybrid</option><option>None</option></select></div>
          <div class="field"><label>Recorder model</label><input class="input" name="recorder_model"></div>
          <div class="field"><label>Storage capacity</label><input class="input" name="storage_capacity" placeholder="2TB"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Camera specifications</label><input class="input" name="camera_specs" placeholder="2MP bullet, 30m IR, weatherproof"></div>
          <div class="field"><label>Cable and accessories</label><input class="input" name="cable_details" placeholder="RG59 siamese, approx. 220m"></div>
        </div>
        <div class="form-row four">
          <div class="field"><label>Installation fee</label><input class="input" name="installation_fee" type="number" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Equipment cost</label><input class="input" name="equipment_cost" type="number" step="0.01" min="0" value="0">
            <p class="hint">Recalculated from the equipment list.</p></div>
          <div class="field"><label>Discount</label><input class="input" name="discount" type="number" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status">
              <?php foreach (['Pending', 'Approved', 'Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
                <option><?= $s ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Installation date</label><input class="input" name="installation_date" type="date"></div>
          <div class="field"><label>Project deadline</label><input class="input" name="deadline" type="date"></div>
        </div>
        <div class="field"><label>Installation notes</label><textarea class="input" name="notes"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save project</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
