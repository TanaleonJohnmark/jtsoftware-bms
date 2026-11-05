<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('schedule.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('schedule.manage');
    $action = input('action');
    $back   = url_with([]);

    if ($action === 'save') {
        $id    = post_int('id');
        $start = (string)input('start_datetime');
        $end   = (string)input('end_datetime');
        $empId = post_int('employee_id');

        if ($start === '' || $end === '') {
            flash('Set both a start and an end time.', 'danger');
            redirect($back);
        }
        if (strtotime($end) <= strtotime($start)) {
            flash('The end time has to be after the start time.', 'danger');
            redirect($back);
        }

        // Double-booking check for the assigned technician.
        if ($empId) {
            $clash = one(
                'SELECT a.*, c.full_name AS client_name FROM appointments a
                 LEFT JOIN clients c ON c.id = a.client_id
                 WHERE a.employee_id = ? AND a.status = "Scheduled" AND a.id <> ?
                   AND a.start_datetime < ? AND a.end_datetime > ? LIMIT 1',
                [$empId, (int)$id, $end, $start]
            );
            if ($clash && !input('ignore_clash')) {
                $name = scalar('SELECT full_name FROM employees WHERE id = ?', [$empId], 'That technician');
                flash($name . ' is already booked for "' . $clash['title'] . '" at that time ('
                    . fdatetime($clash['start_datetime']) . '). Tick "book anyway" if two jobs really overlap.', 'danger');
                redirect($back);
            }
        }

        $data = [
            (string)input('title'),
            (string)input('appointment_type', 'Installation'),
            post_int('client_id'),
            post_int('project_id'),
            post_int('service_request_id'),
            $empId,
            $start, $end,
            nn(input('location')),
            (string)input('status', 'Scheduled'),
            nn(input('notes')),
        ];
        if ($data[0] === '') {
            flash('Give the booking a title.', 'danger');
            redirect($back);
        }

        if ($id) {
            q('UPDATE appointments SET title=?, appointment_type=?, client_id=?, project_id=?, service_request_id=?,
                 employee_id=?, start_datetime=?, end_datetime=?, location=?, status=?, notes=? WHERE id=?',
              array_merge($data, [$id]));
            log_activity('Schedule', 'update', (int)$id, 'Updated booking #' . $id);
            flash('Booking updated.');
        } else {
            q('INSERT INTO appointments (title, appointment_type, client_id, project_id, service_request_id,
                 employee_id, start_datetime, end_datetime, location, status, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($data, [current_user()['id']]));
            $newId = (int)db()->lastInsertId();
            log_activity('Schedule', 'create', $newId, 'Booked ' . $data[0] . ' on ' . fdatetime($start));
            flash('Booking added to the calendar.');
        }

        // Keep the linked project in step with its installation booking.
        if ($data[3] && $data[1] === 'Installation') {
            q('UPDATE cctv_projects SET installation_date = ?, status = IF(status IN ("Pending","Approved"), "Scheduled", status)
               WHERE id = ?', [date('Y-m-d', strtotime($start)), $data[3]]);
        }
        redirect($back);
    }

    if ($action === 'status') {
        q('UPDATE appointments SET status = ? WHERE id = ?', [(string)input('status'), (int)post_int('id')]);
        flash('Booking marked ' . input('status') . '.');
        redirect($back);
    }

    if ($action === 'delete') {
        q('DELETE FROM appointments WHERE id = ?', [(int)post_int('id')]);
        log_activity('Schedule', 'delete', (int)post_int('id'), 'Deleted a booking');
        flash('Booking removed.');
        redirect($back);
    }
}

/* --------------------------- Calendar grid -------------------------- */
$month = (string)input('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
$firstDay   = $month . '-01';
$daysInMonth = (int)date('t', strtotime($firstDay));
$startWeekday = (int)date('w', strtotime($firstDay)); // 0 = Sunday
$prevMonth  = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth  = date('Y-m', strtotime($firstDay . ' +1 month'));
$techFilter = (int)input('tech', 0);

$args  = [$firstDay, date('Y-m-t', strtotime($firstDay))];
$extra = '';
if ($techFilter) { $extra = ' AND a.employee_id = ?'; $args[] = $techFilter; }

$events = all(
    "SELECT a.*, c.full_name AS client_name, e.full_name AS tech_name, p.project_code
     FROM appointments a
     LEFT JOIN clients c ON c.id = a.client_id
     LEFT JOIN employees e ON e.id = a.employee_id
     LEFT JOIN cctv_projects p ON p.id = a.project_id
     WHERE DATE(a.start_datetime) BETWEEN ? AND ? $extra
     ORDER BY a.start_datetime", $args
);

$byDay = [];
foreach ($events as $ev) {
    $byDay[date('j', strtotime($ev['start_datetime']))][] = $ev;
}

$upcoming = all(
    'SELECT a.*, c.full_name AS client_name, e.full_name AS tech_name FROM appointments a
     LEFT JOIN clients c ON c.id = a.client_id LEFT JOIN employees e ON e.id = a.employee_id
     WHERE a.start_datetime >= NOW() AND a.status = "Scheduled" ORDER BY a.start_datetime LIMIT 12'
);

$clients  = all('SELECT id, full_name FROM clients WHERE status = "active" ORDER BY full_name');
$techs    = all('SELECT id, full_name, position FROM employees WHERE status = "active" ORDER BY is_technician DESC, full_name');
$projects = all('SELECT id, project_code, title FROM cctv_projects WHERE status NOT IN ("Completed","Cancelled") ORDER BY project_code');
$srs      = all('SELECT id, request_no, service_type FROM service_requests WHERE status NOT IN ("Completed","Cancelled") ORDER BY request_no');

$pageTitle = 'Schedule';
$pageSub   = date('F Y', strtotime($firstDay)) . ' · ' . count($events) . ' booking' . (count($events) === 1 ? '' : 's');
if (can('schedule.manage')) {
    $pageActions = '<button class="btn" data-modal="apptModal">New booking</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <div class="btn-group">
      <a class="btn btn-sm btn-outline" href="<?= e(url_with(['month' => $prevMonth])) ?>">&larr; <?= date('M', strtotime($prevMonth . '-01')) ?></a>
      <a class="btn btn-sm btn-outline" href="<?= e(url_with(['month' => date('Y-m')])) ?>">This month</a>
      <a class="btn btn-sm btn-outline" href="<?= e(url_with(['month' => $nextMonth])) ?>"><?= date('M', strtotime($nextMonth . '-01')) ?> &rarr;</a>
    </div>
    <h2 class="spacer" style="margin:0"><?= date('F Y', strtotime($firstDay)) ?></h2>
    <form class="toolbar spacer" method="get" data-autosubmit>
      <input type="hidden" name="month" value="<?= e($month) ?>">
      <select class="input" name="tech">
        <option value="">All technicians</option>
        <?php foreach ($techs as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $techFilter === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body">
    <table class="calendar">
      <thead><tr>
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><th><?= $d ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php
      $cell = 0;
      $day  = 1;
      echo '<tr>';
      for ($i = 0; $i < $startWeekday; $i++) { echo '<td class="out"></td>'; $cell++; }
      while ($day <= $daysInMonth) {
          if ($cell % 7 === 0 && $cell > 0) { echo '</tr><tr>'; }
          $isToday = ($month . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)) === date('Y-m-d');
          echo '<td class="' . ($isToday ? 'today' : '') . '"><div class="daynum">' . $day . '</div>';
          foreach ($byDay[$day] ?? [] as $ev) {
              $cls = 'ev-' . $ev['appointment_type'];
              if ($ev['status'] === 'Completed') { $cls = 'ev-done'; }
              echo '<a class="cal-event ' . e($cls) . '" href="#" data-modal="apptModal" data-row=\''
                 . e(json_encode([
                     'id' => $ev['id'], 'title' => $ev['title'], 'appointment_type' => $ev['appointment_type'],
                     'client_id' => $ev['client_id'], 'project_id' => $ev['project_id'],
                     'service_request_id' => $ev['service_request_id'], 'employee_id' => $ev['employee_id'],
                     'start_datetime' => date('Y-m-d\TH:i', strtotime($ev['start_datetime'])),
                     'end_datetime' => date('Y-m-d\TH:i', strtotime($ev['end_datetime'])),
                     'location' => $ev['location'], 'status' => $ev['status'], 'notes' => $ev['notes'],
                 ], JSON_HEX_APOS | JSON_HEX_QUOT)) . '\'>'
                 . date('g:ia', strtotime($ev['start_datetime'])) . ' ' . e($ev['title']) . '</a>';
          }
          echo '</td>';
          $cell++; $day++;
      }
      while ($cell % 7 !== 0) { echo '<td class="out"></td>'; $cell++; }
      echo '</tr>';
      ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Next bookings</h2><span class="spacer small muted">click a row to edit</span></div>
  <div class="card-body tight">
    <?php if (!$upcoming): ?>
      <div class="empty"><strong>Nothing booked ahead</strong>Add an installation or a service visit to fill the crew's week.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>When</th><th>Booking</th><th>Client</th><th>Technician</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $a): ?>
        <tr>
          <td class="nowrap"><span class="row-main"><?= fdate($a['start_datetime'], 'D, M j') ?></span>
              <div class="row-sub"><?= date('g:i A', strtotime($a['start_datetime'])) ?> – <?= date('g:i A', strtotime($a['end_datetime'])) ?></div></td>
          <td><?= e($a['title']) ?><div class="row-sub"><?= e($a['location'] ?: '') ?></div></td>
          <td><?= e($a['client_name'] ?: '—') ?></td>
          <td><?= e($a['tech_name'] ?: '—') ?></td>
          <td><?= badge($a['appointment_type']) ?></td>
          <td><?= badge($a['status']) ?></td>
          <td class="actions-cell">
            <?php if (can('schedule.manage')): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="status" value="Completed">
                <button class="btn btn-sm btn-ok" type="submit">Done</button></form>
              <form method="post" style="display:inline" data-confirm="Remove this booking?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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

<?php if (can('schedule.manage')): ?>
<div class="modal-backdrop" id="apptModal" data-title-new="New booking" data-title-edit="Edit booking">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>New booking</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="field"><label class="required">Title</label>
            <input class="input" name="title" required placeholder="Installation - Lim Pharmacy (4 cameras)"></div>
          <div class="field"><label>Type</label>
            <select class="input" name="appointment_type">
              <?php foreach (['Installation', 'Service', 'Site Survey', 'Meeting', 'Maintenance'] as $t): ?><option><?= $t ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Start</label><input class="input" name="start_datetime" type="datetime-local" required></div>
          <div class="field"><label>End</label><input class="input" name="end_datetime" type="datetime-local" required></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Client</label>
            <select class="input" name="client_id"><option value="">None</option>
              <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Technician</label>
            <select class="input" name="employee_id"><option value="">Unassigned</option>
              <?php foreach ($techs as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Linked project</label>
            <select class="input" name="project_id"><option value="">None</option>
              <?php foreach ($projects as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>"><?= e($pr['project_code']) ?> — <?= e($pr['title']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Linked service request</label>
            <select class="input" name="service_request_id"><option value="">None</option>
              <?php foreach ($srs as $sr): ?>
                <option value="<?= (int)$sr['id'] ?>"><?= e($sr['request_no']) ?> — <?= e($sr['service_type']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Location</label><input class="input" name="location"></div>
          <div class="field"><label>Status</label>
            <select class="input" name="status">
              <?php foreach (['Scheduled', 'Completed', 'Cancelled', 'Rescheduled'] as $s): ?><option><?= $s ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Notes</label><textarea class="input" name="notes"></textarea></div>
        <label class="check"><input type="checkbox" name="ignore_clash" value="1">
          Book anyway even if the technician already has something at that time</label>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save booking</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
