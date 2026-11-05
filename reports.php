<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('reports.view');

$reports = [
    'installations' => 'CCTV installation report',
    'completed'     => 'Completed projects',
    'pending'       => 'Pending and ongoing projects',
    'clients'       => 'Client report',
    'technicians'   => 'Technician performance',
    'sales'         => 'Sales report',
    'payments'      => 'Payment report',
    'receivables'   => 'Outstanding receivables',
    'inventory'     => 'Inventory report',
    'expenses'      => 'Expense report',
    'pnl'           => 'Profit and loss',
];

$report = (string)input('report', 'installations');
if (!isset($reports[$report])) { $report = 'installations'; }
$from = (string)input('from', date('Y-m-01', strtotime('-5 months')));
$to   = (string)input('to', date('Y-m-d'));

$headers = [];
$rows    = [];
$summary = [];
$note    = '';

switch ($report) {
    case 'installations':
        $data = all('SELECT p.*, c.full_name AS client_name,
                       (SELECT GROUP_CONCAT(e.full_name SEPARATOR ", ") FROM project_assignments pa
                         JOIN employees e ON e.id = pa.employee_id WHERE pa.project_id = p.id) AS crew
                     FROM cctv_projects p JOIN clients c ON c.id = p.client_id
                     WHERE DATE(p.created_at) BETWEEN ? AND ? ORDER BY p.created_at DESC', [$from, $to]);
        $headers = ['Project', 'Client', 'Location', 'Cameras', 'Recorder', 'Crew', 'Install date', 'Total cost', 'Status'];
        foreach ($data as $p) {
            $rows[] = [$p['project_code'], $p['client_name'], $p['location'], $p['camera_count'], $p['recorder_type'],
                       $p['crew'], fdate($p['installation_date']), money($p['total_cost']), $p['status']];
        }
        $summary = ['Projects' => count($data),
                    'Cameras installed' => array_sum(array_map(fn($p) => (int)$p['camera_count'], $data)),
                    'Contract value' => money(array_sum(array_map(fn($p) => (float)$p['total_cost'], $data)))];
        $note = 'Projects created in this period.';
        break;

    case 'completed':
        $data = all('SELECT p.*, c.full_name AS client_name FROM cctv_projects p JOIN clients c ON c.id = p.client_id
                     WHERE p.status = "Completed" AND (p.completed_at BETWEEN ? AND ? OR p.completed_at IS NULL)
                     ORDER BY p.completed_at DESC', [$from, $to]);
        $headers = ['Project', 'Client', 'Cameras', 'Completed', 'Deadline', 'On time', 'Total cost'];
        foreach ($data as $p) {
            $onTime = ($p['completed_at'] && $p['deadline']) ? ($p['completed_at'] <= $p['deadline'] ? 'Yes' : 'No') : '—';
            $rows[] = [$p['project_code'], $p['client_name'], $p['camera_count'], fdate($p['completed_at']),
                       fdate($p['deadline']), $onTime, money($p['total_cost'])];
        }
        $onTimeCount = count(array_filter($data, fn($p) => $p['completed_at'] && $p['deadline'] && $p['completed_at'] <= $p['deadline']));
        $summary = ['Completed' => count($data), 'Finished on time' => $onTimeCount,
                    'Value delivered' => money(array_sum(array_map(fn($p) => (float)$p['total_cost'], $data)))];
        break;

    case 'pending':
        $data = all('SELECT p.*, c.full_name AS client_name FROM cctv_projects p JOIN clients c ON c.id = p.client_id
                     WHERE p.status IN ("Pending","Approved","Scheduled","Ongoing") ORDER BY p.deadline IS NULL, p.deadline');
        $headers = ['Project', 'Client', 'Status', 'Progress', 'Install date', 'Deadline', 'Days left', 'Total cost'];
        foreach ($data as $p) {
            $days = $p['deadline'] ? floor((strtotime($p['deadline']) - time()) / 86400) : null;
            $rows[] = [$p['project_code'], $p['client_name'], $p['status'], $p['progress'] . '%',
                       fdate($p['installation_date']), fdate($p['deadline']),
                       $days === null ? '—' : ($days < 0 ? abs($days) . ' overdue' : $days), money($p['total_cost'])];
        }
        $summary = ['Open projects' => count($data),
                    'Overdue' => count(array_filter($data, fn($p) => $p['deadline'] && $p['deadline'] < date('Y-m-d'))),
                    'Pipeline value' => money(array_sum(array_map(fn($p) => (float)$p['total_cost'], $data)))];
        $note = 'Current open work, regardless of the date range.';
        break;

    case 'clients':
        $data = all('SELECT c.*,
               (SELECT COUNT(*) FROM cctv_projects p WHERE p.client_id = c.id) AS projects,
               (SELECT COUNT(*) FROM service_requests s WHERE s.client_id = c.id) AS services,
               (SELECT COALESCE(SUM(total_amount),0) FROM sales s WHERE s.client_id = c.id AND s.status <> "Cancelled") AS billed,
               (SELECT COALESCE(SUM(amount),0) FROM payments pm WHERE pm.client_id = c.id) AS paid,
               (SELECT COALESCE(SUM(balance),0) FROM sales s WHERE s.client_id = c.id AND s.status IN ("Unpaid","Partial")) AS balance
             FROM clients c ORDER BY billed DESC');
        $headers = ['Code', 'Client', 'Type', 'Contact', 'Projects', 'Service jobs', 'Billed', 'Paid', 'Balance'];
        foreach ($data as $c) {
            $rows[] = [$c['client_code'], $c['full_name'], $c['client_type'], $c['contact_number'],
                       $c['projects'], $c['services'], money($c['billed']), money($c['paid']), money($c['balance'])];
        }
        $summary = ['Clients' => count($data),
                    'Total billed' => money(array_sum(array_map(fn($c) => (float)$c['billed'], $data))),
                    'Total owed' => money(array_sum(array_map(fn($c) => (float)$c['balance'], $data)))];
        break;

    case 'technicians':
        $data = all('SELECT e.*,
               (SELECT COUNT(*) FROM project_assignments pa WHERE pa.employee_id = e.id) AS assigned,
               (SELECT COUNT(*) FROM project_assignments pa JOIN cctv_projects p ON p.id = pa.project_id
                 WHERE pa.employee_id = e.id AND p.status = "Completed") AS completed_projects,
               (SELECT COUNT(*) FROM service_requests s WHERE s.assigned_to = e.id) AS service_jobs,
               (SELECT COUNT(*) FROM service_requests s WHERE s.assigned_to = e.id AND s.status = "Completed") AS service_done,
               (SELECT COALESCE(SUM(s.service_fee),0) FROM service_requests s WHERE s.assigned_to = e.id AND s.status = "Completed") AS fees
             FROM employees e WHERE e.is_technician = 1 ORDER BY completed_projects DESC, service_done DESC');
        $headers = ['Code', 'Technician', 'Position', 'Projects assigned', 'Projects completed', 'Service jobs', 'Service completed', 'Fees generated'];
        foreach ($data as $t) {
            $rows[] = [$t['employee_code'], $t['full_name'], $t['position'], $t['assigned'], $t['completed_projects'],
                       $t['service_jobs'], $t['service_done'], money($t['fees'])];
        }
        $summary = ['Technicians' => count($data),
                    'Projects completed' => array_sum(array_map(fn($t) => (int)$t['completed_projects'], $data)),
                    'Service fees' => money(array_sum(array_map(fn($t) => (float)$t['fees'], $data)))];
        break;

    case 'sales':
        $data = all('SELECT s.*, c.full_name AS client_name FROM sales s JOIN clients c ON c.id = s.client_id
                     WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date DESC', [$from, $to]);
        $headers = ['Invoice', 'Date', 'Client', 'Source', 'Total', 'Paid', 'Balance', 'Status'];
        foreach ($data as $s) {
            $rows[] = [$s['invoice_no'], fdate($s['sale_date']), $s['client_name'], $s['reference_type'],
                       money($s['total_amount']), money($s['amount_paid']), money($s['balance']), $s['status']];
        }
        $active = array_filter($data, fn($s) => $s['status'] !== 'Cancelled');
        $summary = ['Invoices' => count($data),
                    'Billed' => money(array_sum(array_map(fn($s) => (float)$s['total_amount'], $active))),
                    'Collected' => money(array_sum(array_map(fn($s) => (float)$s['amount_paid'], $active))),
                    'Outstanding' => money(array_sum(array_map(fn($s) => (float)$s['balance'], $active)))];
        break;

    case 'payments':
        $data = all('SELECT p.*, s.invoice_no, c.full_name AS client_name FROM payments p
                     JOIN sales s ON s.id = p.sale_id JOIN clients c ON c.id = p.client_id
                     WHERE p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date DESC', [$from, $to]);
        $headers = ['Receipt', 'Date', 'Client', 'Invoice', 'Method', 'Reference', 'Amount'];
        foreach ($data as $p) {
            $rows[] = [$p['payment_no'], fdate($p['payment_date']), $p['client_name'], $p['invoice_no'],
                       $p['method'], $p['reference_no'], money($p['amount'])];
        }
        $summary = ['Payments' => count($data),
                    'Total collected' => money(array_sum(array_map(fn($p) => (float)$p['amount'], $data)))];
        break;

    case 'receivables':
        $data = all('SELECT s.*, c.full_name AS client_name, c.contact_number FROM sales s JOIN clients c ON c.id = s.client_id
                     WHERE s.status IN ("Unpaid","Partial") ORDER BY s.due_date IS NULL, s.due_date');
        $headers = ['Invoice', 'Client', 'Contact', 'Invoice date', 'Due date', 'Days overdue', 'Total', 'Paid', 'Balance'];
        foreach ($data as $s) {
            $late = $s['due_date'] && $s['due_date'] < date('Y-m-d') ? floor((time() - strtotime($s['due_date'])) / 86400) : 0;
            $rows[] = [$s['invoice_no'], $s['client_name'], $s['contact_number'], fdate($s['sale_date']),
                       fdate($s['due_date']), $late ?: '—', money($s['total_amount']), money($s['amount_paid']), money($s['balance'])];
        }
        $summary = ['Open invoices' => count($data),
                    'Total receivable' => money(array_sum(array_map(fn($s) => (float)$s['balance'], $data))),
                    'Past due' => count(array_filter($data, fn($s) => $s['due_date'] && $s['due_date'] < date('Y-m-d')))];
        $note = 'All open invoices, regardless of the date range.';
        break;

    case 'inventory':
        $data = all('SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers s ON s.id = p.supplier_id
                     ORDER BY p.stock_qty <= p.reorder_level DESC, p.name');
        $headers = ['SKU', 'Product', 'Category', 'Supplier', 'On hand', 'Reorder level', 'Cost', 'Price', 'Stock value'];
        foreach ($data as $p) {
            $rows[] = [$p['sku'], $p['name'], $p['category_name'], $p['supplier_name'], $p['stock_qty'] . ' ' . $p['unit'],
                       $p['reorder_level'], money($p['purchase_price']), money($p['selling_price']),
                       money((float)$p['stock_qty'] * (float)$p['purchase_price'])];
        }
        $summary = ['Products' => count($data),
                    'Stock value at cost' => money(array_sum(array_map(fn($p) => (float)$p['stock_qty'] * (float)$p['purchase_price'], $data))),
                    'Need reordering' => count(array_filter($data, fn($p) => $p['stock_qty'] <= $p['reorder_level']))];
        $note = 'Current stock position as of today.';
        break;

    case 'expenses':
        $data = all('SELECT x.*, p.project_code FROM expenses x LEFT JOIN cctv_projects p ON p.id = x.project_id
                     WHERE x.expense_date BETWEEN ? AND ? ORDER BY x.expense_date DESC', [$from, $to]);
        $headers = ['No', 'Date', 'Category', 'Description', 'Vendor', 'Project', 'Method', 'Amount'];
        foreach ($data as $x) {
            $rows[] = [$x['expense_no'], fdate($x['expense_date']), $x['category'], $x['description'],
                       $x['vendor'], $x['project_code'], $x['payment_method'], money($x['amount'])];
        }
        $summary = ['Entries' => count($data),
                    'Total spent' => money(array_sum(array_map(fn($x) => (float)$x['amount'], $data)))];
        break;

    case 'pnl':
        $headers = ['Month', 'Collections', 'Other income', 'Total income', 'Expenses', 'Net profit'];
        $cursor = date('Y-m-01', strtotime($from));
        $endCap = date('Y-m-01', strtotime($to));
        $tIn = $tOther = $tOut = 0;
        while ($cursor <= $endCap) {
            $mStart = $cursor;
            $mEnd   = date('Y-m-t', strtotime($cursor));
            $coll   = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN ? AND ?', [$mStart, $mEnd]);
            $other  = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM incomes WHERE income_date BETWEEN ? AND ?', [$mStart, $mEnd]);
            $out    = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$mStart, $mEnd]);
            $rows[] = [date('F Y', strtotime($mStart)), money($coll), money($other), money($coll + $other),
                       money($out), money($coll + $other - $out)];
            $tIn += $coll; $tOther += $other; $tOut += $out;
            $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
        }
        $summary = ['Total income' => money($tIn + $tOther), 'Total expenses' => money($tOut),
                    'Net profit' => money($tIn + $tOther - $tOut)];
        $note = 'Income is counted when the payment is received.';
        break;
}

if (input('export') === 'csv') {
    export_csv($report . '-report-' . date('Y-m-d') . '.csv', $headers, $rows);
}

$pageTitle   = 'Reports';
$pageSub     = $reports[$report] . ' · ' . fdate($from) . ' to ' . fdate($to);
$pageActions = '<a class="btn btn-outline" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>'
             . ' <button class="btn" onclick="window.print()">Print or save as PDF</button>';
include __DIR__ . '/includes/header.php';
?>

<div class="card no-print">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><label>Report</label>
        <select class="input" name="report" style="min-width:250px">
          <?php foreach ($reports as $key => $label): ?>
            <option value="<?= $key ?>" <?= $report === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Run report</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="flex" style="align-items:flex-start;gap:18px">
      <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="" style="width:180px">
      <div>
        <h2 style="margin:0"><?= e($reports[$report]) ?></h2>
        <div class="small muted"><?= e(setting('company_name')) ?> · <?= e(setting('company_division')) ?></div>
        <div class="small muted">Period: <?= fdate($from) ?> to <?= fdate($to) ?> · generated <?= fdatetime(date('Y-m-d H:i:s')) ?>
          by <?= e(current_user()['full_name']) ?></div>
        <?php if ($note): ?><div class="small muted"><?= e($note) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($summary): ?>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="grid grid-4">
      <?php foreach ($summary as $label => $value): ?>
        <div class="stat is-blue"><div class="label"><?= e($label) ?></div><div class="value sm"><?= e($value) ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>Nothing to report for this period</strong>Widen the date range and run it again.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><?php foreach ($r as $i => $cell): ?>
          <td class="<?= $i > 0 && is_numeric(str_replace([',', '.', 'PHP', ' '], '', (string)$cell)) ? 'num' : '' ?>"><?= e($cell === '' || $cell === null ? '—' : $cell) ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
