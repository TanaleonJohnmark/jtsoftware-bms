<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('accounting.view');

$expenseCategories = ['Equipment Purchase', 'Transportation', 'Labor Cost', 'Installation Expense',
                      'Operating Expense', 'Rent and Utilities', 'Marketing', 'Taxes and Permits', 'Other'];
$incomeSources     = ['Consultation', 'Training', 'Rebate', 'Equipment Resale', 'Interest', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('accounting.manage');
    $action = input('action');

    if ($action === 'save_expense') {
        $id = post_int('id');
        $data = [(string)input('category'), (string)input('description'), post_num('amount'),
                 (string)input('expense_date', date('Y-m-d')), post_int('project_id'), nn(input('vendor')),
                 (string)input('payment_method', 'Cash')];
        if ($data[1] === '' || $data[2] <= 0) {
            flash('Describe the expense and enter an amount greater than zero.', 'danger');
            redirect(url_with([]));
        }
        if ($id) {
            q('UPDATE expenses SET category=?, description=?, amount=?, expense_date=?, project_id=?, vendor=?, payment_method=? WHERE id=?',
              array_merge($data, [$id]));
            flash('Expense updated.');
        } else {
            $no = next_code('expenses', 'expense_no', 'EXP');
            q('INSERT INTO expenses (expense_no, category, description, amount, expense_date, project_id, vendor, payment_method, recorded_by)
               VALUES (?,?,?,?,?,?,?,?,?)', array_merge([$no], $data, [current_user()['id']]));
            log_activity('Accounting', 'expense', (int)db()->lastInsertId(), $no . ' — ' . money($data[2]) . ' ' . $data[0]);
            flash('Expense ' . $no . ' recorded.');
        }
        redirect(url_with([]));
    }

    if ($action === 'save_income') {
        $id = post_int('id');
        $data = [(string)input('source'), (string)input('description'), post_num('amount'),
                 (string)input('income_date', date('Y-m-d')), post_int('project_id')];
        if ($data[1] === '' || $data[2] <= 0) {
            flash('Describe the income and enter an amount greater than zero.', 'danger');
            redirect(url_with([]));
        }
        if ($id) {
            q('UPDATE incomes SET source=?, description=?, amount=?, income_date=?, project_id=? WHERE id=?',
              array_merge($data, [$id]));
            flash('Income updated.');
        } else {
            $no = next_code('incomes', 'income_no', 'INC');
            q('INSERT INTO incomes (income_no, source, description, amount, income_date, project_id, recorded_by)
               VALUES (?,?,?,?,?,?,?)', array_merge([$no], $data, [current_user()['id']]));
            log_activity('Accounting', 'income', (int)db()->lastInsertId(), $no . ' — ' . money($data[2]));
            flash('Income ' . $no . ' recorded.');
        }
        redirect(url_with([]));
    }

    if ($action === 'delete_expense') {
        q('DELETE FROM expenses WHERE id = ?', [(int)post_int('id')]);
        flash('Expense deleted.');
        redirect(url_with([]));
    }
    if ($action === 'delete_income') {
        q('DELETE FROM incomes WHERE id = ?', [(int)post_int('id')]);
        flash('Income entry deleted.');
        redirect(url_with([]));
    }
}

$from = (string)input('from', date('Y-m-01'));
$to   = (string)input('to', date('Y-m-t'));
$tab  = input('tab') === 'income' ? 'income' : 'expenses';

$expenses = all('SELECT x.*, p.project_code, u.full_name AS recorder FROM expenses x
                 LEFT JOIN cctv_projects p ON p.id = x.project_id LEFT JOIN users u ON u.id = x.recorded_by
                 WHERE x.expense_date BETWEEN ? AND ? ORDER BY x.expense_date DESC, x.id DESC', [$from, $to]);
$incomes  = all('SELECT i.*, p.project_code, u.full_name AS recorder FROM incomes i
                 LEFT JOIN cctv_projects p ON p.id = i.project_id LEFT JOIN users u ON u.id = i.recorded_by
                 WHERE i.income_date BETWEEN ? AND ? ORDER BY i.income_date DESC, i.id DESC', [$from, $to]);

$collections   = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN ? AND ?', [$from, $to]);
$otherIncome   = array_sum(array_map(fn($r) => (float)$r['amount'], $incomes));
$expenseTotal  = array_sum(array_map(fn($r) => (float)$r['amount'], $expenses));
$netProfit     = $collections + $otherIncome - $expenseTotal;

$byCategory = [];
foreach ($expenses as $x) {
    $byCategory[$x['category']] = ($byCategory[$x['category']] ?? 0) + (float)$x['amount'];
}
arsort($byCategory);

if (input('export') === 'csv') {
    if ($tab === 'income') {
        export_csv('income-' . date('Y-m-d') . '.csv', ['No', 'Date', 'Source', 'Description', 'Project', 'Amount'],
            array_map(fn($r) => [$r['income_no'], $r['income_date'], $r['source'], $r['description'], $r['project_code'], $r['amount']], $incomes));
    }
    export_csv('expenses-' . date('Y-m-d') . '.csv', ['No', 'Date', 'Category', 'Description', 'Vendor', 'Project', 'Method', 'Amount'],
        array_map(fn($r) => [$r['expense_no'], $r['expense_date'], $r['category'], $r['description'], $r['vendor'],
                             $r['project_code'], $r['payment_method'], $r['amount']], $expenses));
}

$projects = all('SELECT id, project_code, title FROM cctv_projects ORDER BY project_code DESC LIMIT 100');

$pageTitle = 'Income and expenses';
$pageSub   = fdate($from) . ' to ' . fdate($to);
if (can('accounting.manage')) {
    $pageActions = '<button class="btn btn-outline" data-modal="incModal">Record income</button>'
                 . ' <button class="btn" data-modal="expModal">Record expense</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-ok"><div class="label">Collections from invoices</div><div class="value sm"><?= money($collections) ?></div>
    <div class="foot">payments received in this period</div></div>
  <div class="stat is-blue"><div class="label">Other income</div><div class="value sm"><?= money($otherIncome) ?></div>
    <div class="foot"><?= count($incomes) ?> entries</div></div>
  <div class="stat is-warn"><div class="label">Total expenses</div><div class="value sm"><?= money($expenseTotal) ?></div>
    <div class="foot"><?= count($expenses) ?> entries</div></div>
  <div class="stat <?= $netProfit >= 0 ? 'is-ok' : 'is-danger' ?>"><div class="label">Net profit</div>
    <div class="value sm"><?= money($netProfit) ?></div>
    <div class="foot">income minus expenses</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
      <a class="btn btn-outline" href="<?= e(base_url('reports.php?report=pnl&from=' . $from . '&to=' . $to)) ?>">Profit and loss report</a>
    </form>
  </div>
  <div class="card-body">
    <div class="grid grid-sidebar">
      <div>
        <div class="tabs">
          <a class="tab <?= $tab === 'expenses' ? 'active' : '' ?>" href="<?= e(url_with(['tab' => 'expenses'])) ?>">Expenses</a>
          <a class="tab <?= $tab === 'income' ? 'active' : '' ?>" href="<?= e(url_with(['tab' => 'income'])) ?>">Other income</a>
        </div>

        <?php if ($tab === 'expenses'): ?>
          <?php if (!$expenses): ?>
            <div class="empty"><strong>No expenses in this period</strong>Record equipment purchases, fuel, allowances and bills here.</div>
          <?php else: ?>
          <div class="table-wrap"><table class="data">
            <thead><tr><th>No</th><th>Date</th><th>Category</th><th>Description</th><th>Project</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($expenses as $x): ?>
              <tr>
                <td class="row-main"><?= e($x['expense_no']) ?></td>
                <td class="nowrap"><?= fdate($x['expense_date']) ?></td>
                <td><span class="pill"><?= e($x['category']) ?></span></td>
                <td><?= e($x['description']) ?><div class="row-sub"><?= e($x['vendor'] ?: '') ?><?= $x['vendor'] ? ' · ' : '' ?><?= e($x['payment_method']) ?></div></td>
                <td class="small"><?= e($x['project_code'] ?: '—') ?></td>
                <td class="num"><?= money($x['amount']) ?></td>
                <td class="actions-cell">
                  <?php if (can('accounting.manage')): ?>
                    <button class="btn btn-sm btn-outline" data-modal="expModal"
                      data-row='<?= e(json_encode(['id' => $x['id'], 'category' => $x['category'], 'description' => $x['description'],
                        'amount' => $x['amount'], 'expense_date' => $x['expense_date'], 'project_id' => $x['project_id'],
                        'vendor' => $x['vendor'], 'payment_method' => $x['payment_method']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                    <form method="post" style="display:inline" data-confirm="Delete <?= e($x['expense_no']) ?>?">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                      <button class="linkbtn" type="submit">delete</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="5" class="num">Total</th><th class="num"><?= money($expenseTotal) ?></th><th></th></tr></tfoot>
          </table></div>
          <?php endif; ?>
        <?php else: ?>
          <?php if (!$incomes): ?>
            <div class="empty"><strong>No other income in this period</strong>Invoice payments are tracked separately under Payments.</div>
          <?php else: ?>
          <div class="table-wrap"><table class="data">
            <thead><tr><th>No</th><th>Date</th><th>Source</th><th>Description</th><th>Project</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($incomes as $i): ?>
              <tr>
                <td class="row-main"><?= e($i['income_no']) ?></td>
                <td class="nowrap"><?= fdate($i['income_date']) ?></td>
                <td><span class="pill"><?= e($i['source']) ?></span></td>
                <td><?= e($i['description']) ?></td>
                <td class="small"><?= e($i['project_code'] ?: '—') ?></td>
                <td class="num"><?= money($i['amount']) ?></td>
                <td class="actions-cell">
                  <?php if (can('accounting.manage')): ?>
                    <button class="btn btn-sm btn-outline" data-modal="incModal"
                      data-row='<?= e(json_encode(['id' => $i['id'], 'source' => $i['source'], 'description' => $i['description'],
                        'amount' => $i['amount'], 'income_date' => $i['income_date'], 'project_id' => $i['project_id']],
                        JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                    <form method="post" style="display:inline" data-confirm="Delete <?= e($i['income_no']) ?>?">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_income"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                      <button class="linkbtn" type="submit">delete</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="5" class="num">Total</th><th class="num"><?= money($otherIncome) ?></th><th></th></tr></tfoot>
          </table></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div>
        <div class="card" style="margin:0">
          <div class="card-head"><h2>Where the money went</h2></div>
          <div class="card-body">
            <?php if (!$byCategory): ?>
              <p class="muted small">No expenses recorded in this period.</p>
            <?php else: ?>
              <div class="chart-box short"><canvas id="catChart"></canvas></div>
              <table class="data" style="margin-top:12px">
                <tbody>
                <?php foreach ($byCategory as $cat => $amount): ?>
                  <tr><td class="small"><?= e($cat) ?></td>
                      <td class="num small"><?= money($amount) ?>
                        <span class="muted">(<?= $expenseTotal ? round($amount / $expenseTotal * 100) : 0 ?>%)</span></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (can('accounting.manage')): ?>
<div class="modal-backdrop" id="expModal" data-title-new="Record expense" data-title-edit="Edit expense">
  <div class="modal"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_expense"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Record expense</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="field"><label>Category</label>
          <select class="input" name="category">
            <?php foreach ($expenseCategories as $c): ?><option><?= $c ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="required">Amount</label><input class="input" name="amount" type="number" step="0.01" min="0.01" required></div>
      </div>
      <div class="field"><label class="required">Description</label><input class="input" name="description" required
        placeholder="Restock: 10 bullet cameras and 1 DVR"></div>
      <div class="form-row three">
        <div class="field"><label>Date</label><input class="input" type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="field"><label>Vendor</label><input class="input" name="vendor"></div>
        <div class="field"><label>Paid with</label>
          <select class="input" name="payment_method">
            <?php foreach (['Cash', 'GCash', 'Bank Transfer', 'Cheque', 'Card', 'Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="field"><label>Charge to project</label>
        <select class="input" name="project_id"><option value="">Not project specific</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?> — <?= e($p['title']) ?></option><?php endforeach; ?>
        </select>
        <p class="hint">Project expenses show on the project page so you can see the real margin per job.</p></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save expense</button></div>
  </form></div>
</div>

<div class="modal-backdrop" id="incModal" data-title-new="Record income" data-title-edit="Edit income">
  <div class="modal"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_income"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Record income</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <p class="small muted">Use this for income that is not an invoice payment — consultation fees, training, rebates.</p>
      <div class="form-row">
        <div class="field"><label>Source</label>
          <select class="input" name="source"><?php foreach ($incomeSources as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="required">Amount</label><input class="input" name="amount" type="number" step="0.01" min="0.01" required></div>
      </div>
      <div class="field"><label class="required">Description</label><input class="input" name="description" required></div>
      <div class="form-row">
        <div class="field"><label>Date</label><input class="input" type="date" name="income_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="field"><label>Related project</label>
          <select class="input" name="project_id"><option value="">None</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save income</button></div>
  </form></div>
</div>
<?php endif; ?>

<?php if ($byCategory): ?>
<script src="<?= e(base_url('assets/vendor/chart.umd.js')) ?>"></script>
<script>
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_keys($byCategory)) ?>,
    datasets: [{ data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($byCategory))) ?>,
      backgroundColor: ['#1B2B4D', '#2E6BE6', '#B5714A', '#11875A', '#B26A00', '#6D7F9E', '#C22F39', '#33508A', '#94A3B8'] }]
  },
  options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
