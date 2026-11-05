<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('logs.view');

$module = (string)input('module', '');
$search = (string)input('q', '');
$from   = (string)input('from', date('Y-m-d', strtotime('-30 days')));
$to     = (string)input('to', date('Y-m-d'));

$where = ['DATE(l.created_at) BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($module !== '') { $where[] = 'l.module = ?'; $args[] = $module; }
if ($search !== '') {
    $where[] = '(l.description LIKE ? OR l.actor_name LIKE ? OR l.action LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$rows    = all("SELECT l.* FROM activity_logs l WHERE $whereSql ORDER BY l.created_at DESC, l.id DESC LIMIT 400", $args);
$modules = array_column(all('SELECT DISTINCT module FROM activity_logs ORDER BY module'), 'module');

if (input('export') === 'csv') {
    export_csv('activity-log-' . date('Y-m-d') . '.csv',
        ['When', 'Who', 'Type', 'Module', 'Action', 'Reference', 'Description', 'IP address'],
        array_map(fn($r) => [$r['created_at'], $r['actor_name'], $r['actor_type'], $r['module'], $r['action'],
                             $r['reference_id'], $r['description'], $r['ip_address']], $rows));
}

$pageTitle = 'Activity logs';
$pageSub   = count($rows) . ' entries between ' . fdate($from) . ' and ' . fdate($to);
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search description or user…"></div>
      <div class="field"><label>Module</label>
        <select class="input" name="module"><option value="">All modules</option>
          <?php foreach ($modules as $m): ?><option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>No activity in this period</strong>Every create, edit and delete is written here as it happens.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>When</th><th>Who</th><th>Module</th><th>Action</th><th>Description</th><th>IP address</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap small"><?= fdatetime($r['created_at']) ?></td>
          <td><span class="row-main"><?= e($r['actor_name'] ?: 'System') ?></span>
              <div class="row-sub"><?= e($r['actor_type']) ?></div></td>
          <td><span class="pill"><?= e($r['module']) ?></span></td>
          <td class="small"><?= e($r['action']) ?></td>
          <td class="small"><?= e($r['description']) ?></td>
          <td class="small muted"><?= e($r['ip_address'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
