<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('dashboard.view');

/* ---------------------------------------------------------------- */
/* Every figure below is read from the database at page load.        */
/* ---------------------------------------------------------------- */
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$totalClients   = (int)scalar('SELECT COUNT(*) FROM clients WHERE status = "active"');
$totalProjects  = (int)scalar('SELECT COUNT(*) FROM cctv_projects');
$byStatus       = [];
foreach (all('SELECT status, COUNT(*) c FROM cctv_projects GROUP BY status') as $r) {
    $byStatus[$r['status']] = (int)$r['c'];
}
$pendingProjects   = ($byStatus['Pending'] ?? 0) + ($byStatus['Approved'] ?? 0);
$ongoingProjects   = $byStatus['Ongoing'] ?? 0;
$scheduledProjects = $byStatus['Scheduled'] ?? 0;
$completedProjects = $byStatus['Completed'] ?? 0;

$totalRequests  = (int)scalar('SELECT COUNT(*) FROM service_requests');
$openRequests   = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE status IN ('New','Assigned','In Progress','On Hold')");

$totalSales     = (float)scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status <> 'Cancelled'");
$salesCount     = (int)scalar("SELECT COUNT(*) FROM sales WHERE status <> 'Cancelled'");
$collected      = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments');
$outstanding    = (float)scalar("SELECT COALESCE(SUM(balance),0) FROM sales WHERE status IN ('Unpaid','Partial')");

$monthIncome    = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN ? AND ?', [$monthStart, $monthEnd])
                + (float)scalar('SELECT COALESCE(SUM(amount),0) FROM incomes WHERE income_date BETWEEN ? AND ?', [$monthStart, $monthEnd]);
$monthExpense   = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$monthStart, $monthEnd]);
$monthProfit    = $monthIncome - $monthExpense;

$lowStock = all('SELECT id, sku, name, stock_qty, reorder_level FROM products
                 WHERE status = "active" AND stock_qty <= reorder_level ORDER BY stock_qty ASC LIMIT 6');

$upcoming = all(
    'SELECT a.*, c.full_name AS client_name, e.full_name AS tech_name
     FROM appointments a
     LEFT JOIN clients c ON c.id = a.client_id
     LEFT JOIN employees e ON e.id = a.employee_id
     WHERE a.status = "Scheduled" AND a.start_datetime >= NOW()
     ORDER BY a.start_datetime ASC LIMIT 6'
);

$recentLogs = all('SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 8');

$activeProjects = all(
    'SELECT p.*, c.full_name AS client_name FROM cctv_projects p
     JOIN clients c ON c.id = p.client_id
     WHERE p.status IN ("Scheduled","Ongoing","Approved") ORDER BY p.deadline ASC LIMIT 6'
);

/* Charts: last six months of money in and out ---------------------- */
$labels = $incomeSeries = $expenseSeries = [];
for ($i = 5; $i >= 0; $i--) {
    $start = date('Y-m-01', strtotime("-$i months"));
    $end   = date('Y-m-t', strtotime($start));
    $labels[] = date('M Y', strtotime($start));
    $incomeSeries[] = round(
        (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN ? AND ?', [$start, $end])
        + (float)scalar('SELECT COALESCE(SUM(amount),0) FROM incomes WHERE income_date BETWEEN ? AND ?', [$start, $end]), 2);
    $expenseSeries[] = round((float)scalar('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$start, $end]), 2);
}

$statusOrder  = ['Pending', 'Approved', 'Scheduled', 'Ongoing', 'Completed', 'Cancelled'];
$statusCounts = [];
foreach ($statusOrder as $s) {
    $statusCounts[] = $byStatus[$s] ?? 0;
}

$pageTitle = 'Dashboard';
$pageSub   = 'Live figures for ' . date('F Y');
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue">
    <div class="label">Active clients</div>
    <div class="value"><?= $totalClients ?></div>
    <div class="foot"><?= $totalProjects ?> installation projects on record</div>
  </div>
  <div class="stat is-copper">
    <div class="label">Projects in progress</div>
    <div class="value"><?= $ongoingProjects + $scheduledProjects ?></div>
    <div class="foot"><?= $pendingProjects ?> awaiting approval or start</div>
  </div>
  <div class="stat is-ok">
    <div class="label">Completed installations</div>
    <div class="value"><?= $completedProjects ?></div>
    <div class="foot"><?= $openRequests ?> open service requests of <?= $totalRequests ?></div>
  </div>
  <div class="stat is-danger">
    <div class="label">Outstanding balance</div>
    <div class="value"><?= money($outstanding) ?></div>
    <div class="foot">across unpaid and partial invoices</div>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat">
    <div class="label">Total sales recorded</div>
    <div class="value sm"><?= money($totalSales) ?></div>
    <div class="foot"><?= $salesCount ?> invoices</div>
  </div>
  <div class="stat is-ok">
    <div class="label">Collected to date</div>
    <div class="value sm"><?= money($collected) ?></div>
    <div class="foot">all recorded payments</div>
  </div>
  <div class="stat is-warn">
    <div class="label">Expenses this month</div>
    <div class="value sm"><?= money($monthExpense) ?></div>
    <div class="foot">income <?= money($monthIncome) ?></div>
  </div>
  <div class="stat <?= $monthProfit >= 0 ? 'is-ok' : 'is-danger' ?>">
    <div class="label">Net profit this month</div>
    <div class="value sm"><?= money($monthProfit) ?></div>
    <div class="foot"><?= date('M 1') ?> – <?= date('M j') ?></div>
  </div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head">
        <h2>Money in and out</h2>
        <span class="spacer small muted">last six months</span>
      </div>
      <div class="card-body"><div class="chart-box"><canvas id="cashChart"></canvas></div></div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Installation projects to watch</h2>
        <a class="spacer btn btn-sm btn-outline" href="<?= e(base_url('projects.php')) ?>">All projects</a>
      </div>
      <div class="card-body tight">
        <?php if (!$activeProjects): ?>
          <div class="empty"><strong>No active projects</strong>New installations will appear here.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Project</th><th>Client</th><th>Deadline</th><th>Progress</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($activeProjects as $p): ?>
            <tr>
              <td>
                <a class="row-main" href="<?= e(base_url('project_view.php?id=' . $p['id'])) ?>"><?= e($p['project_code']) ?></a>
                <div class="row-sub"><?= e($p['title']) ?></div>
              </td>
              <td><?= e($p['client_name']) ?></td>
              <td class="nowrap"><?= fdate($p['deadline']) ?></td>
              <td>
                <div class="progress <?= $p['progress'] >= 100 ? 'done' : '' ?>"><span style="width:<?= (int)$p['progress'] ?>%"></span></div>
                <span class="row-sub"><?= (int)$p['progress'] ?>%</span>
              </td>
              <td><?= badge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Projects by status</h2></div>
      <div class="card-body"><div class="chart-box short"><canvas id="statusChart"></canvas></div></div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head">
        <h2>Upcoming schedule</h2>
        <a class="spacer btn btn-sm btn-outline" href="<?= e(base_url('schedule.php')) ?>">Calendar</a>
      </div>
      <div class="card-body tight">
        <?php if (!$upcoming): ?>
          <div class="empty"><strong>Nothing scheduled</strong>Book an installation or service visit from the calendar.</div>
        <?php else: ?>
          <table class="data"><tbody>
          <?php foreach ($upcoming as $a): ?>
            <tr>
              <td>
                <div class="row-main"><?= e($a['title']) ?></div>
                <div class="row-sub">
                  <?= fdatetime($a['start_datetime']) ?>
                  <?= $a['tech_name'] ? ' · ' . e($a['tech_name']) : '' ?>
                </div>
              </td>
              <td class="text-right"><?= badge($a['appointment_type']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Low stock</h2>
        <a class="spacer btn btn-sm btn-outline" href="<?= e(base_url('products.php?filter=low')) ?>">Inventory</a>
      </div>
      <div class="card-body tight">
        <?php if (!$lowStock): ?>
          <div class="empty"><strong>Stock levels are healthy</strong>Nothing is at or below its reorder level.</div>
        <?php else: ?>
          <table class="data"><tbody>
          <?php foreach ($lowStock as $p): ?>
            <tr>
              <td>
                <div class="row-main"><?= e($p['name']) ?></div>
                <div class="row-sub"><?= e($p['sku']) ?></div>
              </td>
              <td class="num">
                <span class="badge badge-<?= $p['stock_qty'] <= 0 ? 'danger' : 'warn' ?>"><?= (int)$p['stock_qty'] ?> left</span>
                <div class="row-sub">reorder at <?= (int)$p['reorder_level'] ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Recent activity</h2></div>
      <div class="card-body">
        <ul class="timeline">
          <?php foreach ($recentLogs as $log): ?>
            <li>
              <div><?= e($log['description']) ?></div>
              <div class="t-meta"><?= e($log['actor_name'] ?: 'System') ?> · <?= fdatetime($log['created_at']) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$recentLogs): ?><li class="muted">No activity recorded yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(base_url('assets/vendor/chart.umd.js')) ?>"></script>
<script>
Chart.defaults.font.family = '"Segoe UI", Roboto, system-ui, sans-serif';
Chart.defaults.color = '#67748C';

new Chart(document.getElementById('cashChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { label: 'Money in', data: <?= json_encode($incomeSeries) ?>, borderColor: '#2E6BE6',
        backgroundColor: 'rgba(46,107,230,.10)', fill: true, tension: .32, borderWidth: 2, pointRadius: 3 },
      { label: 'Money out', data: <?= json_encode($expenseSeries) ?>, borderColor: '#B5714A',
        backgroundColor: 'rgba(181,113,74,.10)', fill: true, tension: .32, borderWidth: 2, pointRadius: 3 }
    ]
  },
  options: {
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#EDF1F7' },
           ticks: { callback: function (v) { return v.toLocaleString(); } } },
      x: { grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('statusChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($statusOrder) ?>,
    datasets: [{
      label: 'Projects',
      data: <?= json_encode($statusCounts) ?>,
      backgroundColor: ['#B26A00', '#2E6BE6', '#33508A', '#B5714A', '#11875A', '#94A3B8'],
      borderRadius: 5, maxBarThickness: 42
    }]
  },
  options: {
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#EDF1F7' } }, x: { grid: { display: false } } }
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
