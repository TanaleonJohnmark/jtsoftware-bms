<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$client = current_client();
$cid    = (int)$client['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $type = (string)input('service_type');
    $desc = (string)input('description');
    if ($type === '' || $desc === '') {
        flash('Tell us the type of help you need and what is happening.', 'danger');
        redirect('requests.php');
    }
    $no = next_code('service_requests', 'request_no', 'SR');
    q('INSERT INTO service_requests (request_no, client_id, service_type, description, location, request_date,
         preferred_date, priority, status, source)
       VALUES (?,?,?,?,?,CURDATE(),?,?,"New","Portal")',
      [$no, $cid, $type, $desc, nn(input('location')) ?: $client['address'],
       nn(input('preferred_date')), (string)input('priority', 'Normal')]);
    log_activity('Services', 'create', (int)db()->lastInsertId(), $client['full_name'] . ' submitted ' . $no . ' from the portal');
    flash('Request ' . $no . ' received. We will assign a technician and get back to you.');
    redirect('requests.php');
}

$requests = all('SELECT s.*, e.full_name AS tech FROM service_requests s
                 LEFT JOIN employees e ON e.id = s.assigned_to
                 WHERE s.client_id = ? ORDER BY s.request_date DESC, s.id DESC', [$cid]);

$pageTitle = 'Service requests';
$pageSub   = 'Report a problem and follow what happens next';
include __DIR__ . '/header.php';
?>
<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Your requests</h2></div>
      <div class="card-body tight">
        <?php if (!$requests): ?>
          <div class="empty"><strong>No requests yet</strong>Use the form to tell us what needs fixing.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Request</th><th>Problem</th><th>Technician</th><th>Logged</th><th class="num">Fee</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($requests as $r): ?>
            <tr><td><span class="row-main"><?= e($r['request_no']) ?></span><div class="row-sub"><?= e($r['service_type']) ?></div></td>
              <td class="small"><?= e($r['description']) ?>
                <?php if ($r['completion_notes']): ?><div class="row-sub">Resolved: <?= e($r['completion_notes']) ?></div><?php endif; ?></td>
              <td class="small"><?= e($r['tech'] ?: 'to be assigned') ?></td>
              <td class="nowrap"><?= fdate($r['request_date']) ?></td>
              <td class="num"><?= $r['service_fee'] > 0 ? money($r['service_fee']) : '—' ?></td>
              <td><?= badge($r['status']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Submit a new request</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <div class="field"><label class="required">What do you need?</label>
            <select class="input" name="service_type" required>
              <?php foreach (['CCTV Troubleshooting', 'CCTV Repair', 'Camera Replacement', 'DVR/NVR Configuration',
                              'Mobile Viewing Setup', 'Network Troubleshooting', 'Computer Repair',
                              'Software Installation', 'Hardware Maintenance', 'Other IT Service'] as $t): ?>
                <option><?= $t ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label class="required">Tell us what is happening</label>
            <textarea class="input" name="description" required placeholder="Camera at the back gate has no image since Monday."></textarea></div>
          <div class="field"><label>Where</label>
            <input class="input" name="location" value="<?= e($client['address']) ?>"></div>
          <div class="field"><label>Preferred visit date</label><input class="input" type="date" name="preferred_date"></div>
          <div class="field"><label>How urgent is it?</label>
            <select class="input" name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></div>
          <button class="btn btn-block" type="submit">Send request</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
