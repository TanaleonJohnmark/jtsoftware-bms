<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$client = current_client();
$cid    = (int)$client['id'];

$projects = all('SELECT * FROM cctv_projects WHERE client_id = ? ORDER BY created_at DESC', [$cid]);
$openReq  = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE client_id = ? AND status NOT IN ('Completed','Cancelled')", [$cid]);
$balance  = (float)scalar("SELECT COALESCE(SUM(balance),0) FROM sales WHERE client_id = ? AND status IN ('Unpaid','Partial')", [$cid]);
$paid     = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE client_id = ?', [$cid]);
$next     = all('SELECT a.*, e.full_name AS tech FROM appointments a LEFT JOIN employees e ON e.id = a.employee_id
                 WHERE a.client_id = ? AND a.start_datetime >= NOW() AND a.status = "Scheduled"
                 ORDER BY a.start_datetime LIMIT 5', [$cid]);
$updates  = all('SELECT pu.*, p.project_code, p.title FROM project_updates pu
                 JOIN cctv_projects p ON p.id = pu.project_id WHERE p.client_id = ?
                 ORDER BY pu.created_at DESC LIMIT 6', [$cid]);

$pageTitle = 'Welcome, ' . $client['full_name'];
$pageSub   = 'Everything we are doing for you, in one view';
include __DIR__ . '/header.php';
?>
<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Installations</div><div class="value"><?= count($projects) ?></div>
    <div class="foot"><?= count(array_filter($projects, fn($p) => $p['status'] === 'Completed')) ?> completed</div></div>
  <div class="stat is-copper"><div class="label">Open service requests</div><div class="value"><?= $openReq ?></div>
    <div class="foot"><a href="requests.php">send a new one</a></div></div>
  <div class="stat is-ok"><div class="label">Total paid</div><div class="value sm"><?= money($paid) ?></div>
    <div class="foot">thank you</div></div>
  <div class="stat <?= $balance > 0 ? 'is-danger' : '' ?>"><div class="label">Outstanding balance</div>
    <div class="value sm"><?= money($balance) ?></div><div class="foot"><a href="invoices.php">view invoices</a></div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Your installations</h2><a class="spacer btn btn-sm btn-outline" href="projects.php">See all</a></div>
      <div class="card-body tight">
        <?php if (!$projects): ?>
          <div class="empty"><strong>No installations yet</strong>Once we start a job for you it shows up here.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Project</th><th>Cameras</th><th>Install date</th><th>Progress</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($projects, 0, 5) as $p): ?>
            <tr><td><span class="row-main"><?= e($p['project_code']) ?></span><div class="row-sub"><?= e($p['title']) ?></div></td>
              <td><?= (int)$p['camera_count'] ?></td>
              <td class="nowrap"><?= fdate($p['installation_date']) ?></td>
              <td><div class="progress <?= $p['progress'] >= 100 ? 'done' : '' ?>"><span style="width:<?= (int)$p['progress'] ?>%"></span></div>
                  <span class="row-sub"><?= (int)$p['progress'] ?>%</span></td>
              <td><?= badge($p['status']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Latest from the crew</h2></div>
      <div class="card-body">
        <ul class="timeline">
          <?php foreach ($updates as $u): ?>
            <li><div><strong><?= e($u['project_code']) ?></strong> — <?= (int)$u['progress'] ?>% <?= e($u['status']) ?>
                <?= $u['note'] ? '<br>' . e($u['note']) : '' ?></div>
              <div class="t-meta"><?= fdatetime($u['created_at']) ?></div></li>
          <?php endforeach; ?>
          <?php if (!$updates): ?><li class="muted">No progress notes yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Your next visits</h2></div>
      <div class="card-body tight">
        <?php if (!$next): ?><div class="empty">Nothing scheduled right now.</div><?php else: ?>
        <table class="data"><tbody>
        <?php foreach ($next as $a): ?>
          <tr><td><span class="row-main"><?= e($a['title']) ?></span>
              <div class="row-sub"><?= fdatetime($a['start_datetime']) ?><?= $a['tech'] ? ' · ' . e($a['tech']) : '' ?></div></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Need help?</h2></div>
      <div class="card-body">
        <p class="small">Camera offline, blurry footage, or you want remote viewing on a new phone?
           Send us the details and we will assign a technician.</p>
        <a class="btn btn-block" href="requests.php">Submit a service request</a>
        <p class="small muted" style="margin-top:12px">Or call <?= e(setting('company_phone')) ?>.</p>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
