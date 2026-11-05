<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$cid = (int)current_client()['id'];

$projects = all('SELECT * FROM cctv_projects WHERE client_id = ? ORDER BY created_at DESC', [$cid]);
$openId   = (int)input('id', $projects[0]['id'] ?? 0);
$project  = $openId ? one('SELECT * FROM cctv_projects WHERE id = ? AND client_id = ?', [$openId, $cid]) : null;

$updates = $photos = $crew = [];
if ($project) {
    $updates = all('SELECT * FROM project_updates WHERE project_id = ? ORDER BY created_at DESC', [$project['id']]);
    $photos  = all('SELECT * FROM project_photos WHERE project_id = ? ORDER BY photo_type, id', [$project['id']]);
    $crew    = all('SELECT e.full_name, pa.role_in_project FROM project_assignments pa
                    JOIN employees e ON e.id = pa.employee_id WHERE pa.project_id = ?', [$project['id']]);
}

$pageTitle = 'My installations';
$pageSub   = count($projects) . ' project' . (count($projects) === 1 ? '' : 's') . ' with us';
include __DIR__ . '/header.php';
?>
<?php if (!$projects): ?>
  <div class="card"><div class="card-body empty"><strong>No installations yet</strong>
    Once we start a job for you, the progress shows here.</div></div>
<?php else: ?>
<div class="tabs">
  <?php foreach ($projects as $p): ?>
    <a class="tab <?= $project && (int)$p['id'] === (int)$project['id'] ? 'active' : '' ?>" href="projects.php?id=<?= (int)$p['id'] ?>">
      <?= e($p['project_code']) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($project): ?>
<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Status</div><div class="value sm"><?= e($project['status']) ?></div>
    <div class="foot"><?= (int)$project['progress'] ?>% complete</div></div>
  <div class="stat is-copper"><div class="label">System</div><div class="value sm"><?= (int)$project['camera_count'] ?> cameras</div>
    <div class="foot"><?= e($project['recorder_type']) ?> · <?= e($project['storage_capacity'] ?: 'storage to be set') ?></div></div>
  <div class="stat"><div class="label">Agreed price</div><div class="value sm"><?= money($project['total_cost']) ?></div>
    <div class="foot">installation and equipment</div></div>
  <div class="stat is-ok"><div class="label">Installation date</div><div class="value sm"><?= fdate($project['installation_date']) ?></div>
    <div class="foot">target finish <?= fdate($project['deadline']) ?></div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2><?= e($project['title']) ?></h2></div>
      <div class="card-body">
        <div class="progress <?= $project['progress'] >= 100 ? 'done' : '' ?>" style="height:10px">
          <span style="width:<?= (int)$project['progress'] ?>%"></span></div>
        <p class="small muted" style="margin-top:6px"><?= (int)$project['progress'] ?>% complete</p>
        <h3 style="margin-top:16px">Progress updates</h3>
        <ul class="timeline">
          <?php foreach ($updates as $u): ?>
            <li><div><strong><?= (int)$u['progress'] ?>%</strong> · <?= e($u['status']) ?><?= $u['note'] ? ' — ' . e($u['note']) : '' ?></div>
              <div class="t-meta"><?= fdatetime($u['created_at']) ?></div></li>
          <?php endforeach; ?>
          <?php if (!$updates): ?><li class="muted">No updates posted yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <?php if ($photos): ?>
    <div class="card">
      <div class="card-head"><h2>Site photos</h2></div>
      <div class="card-body">
        <?php foreach (['Before', 'After', 'Documentation'] as $group):
            $set = array_filter($photos, fn($ph) => $ph['photo_type'] === $group);
            if (!$set) { continue; } ?>
          <h3><?= $group ?></h3>
          <div class="thumbs" style="margin-bottom:16px">
            <?php foreach ($set as $ph): ?>
              <figure><a href="<?= e(base_url($ph['file_path'])) ?>" target="_blank">
                <img src="<?= e(base_url($ph['file_path'])) ?>" alt="<?= e($ph['caption'] ?: $group) ?>"></a>
                <figcaption><?= e($ph['caption'] ?: fdate($ph['uploaded_at'])) ?></figcaption></figure>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>System details</h2></div>
      <div class="card-body"><dl class="dl">
        <dt>Location</dt><dd><?= e($project['location'] ?: '—') ?></dd>
        <dt>Package</dt><dd><?= e($project['package_name'] ?: '—') ?></dd>
        <dt>Cameras</dt><dd><?= (int)$project['camera_count'] ?> — <?= e($project['camera_specs'] ?: '') ?></dd>
        <dt>Recorder</dt><dd><?= e($project['recorder_type']) ?><?= $project['recorder_model'] ? ' — ' . e($project['recorder_model']) : '' ?></dd>
        <dt>Storage</dt><dd><?= e($project['storage_capacity'] ?: '—') ?></dd>
        <dt>Cabling</dt><dd><?= e($project['cable_details'] ?: '—') ?></dd>
        <dt>Completed</dt><dd><?= fdate($project['completed_at']) ?></dd>
      </dl></div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Your crew</h2></div>
      <div class="card-body tight">
        <table class="data"><tbody>
        <?php foreach ($crew as $c): ?>
          <tr><td><span class="row-main"><?= e($c['full_name']) ?></span>
              <div class="row-sub"><?= e($c['role_in_project']) ?></div></td></tr>
        <?php endforeach; ?>
        <?php if (!$crew): ?><tr><td class="muted">Crew not assigned yet.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
