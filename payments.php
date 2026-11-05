<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('sales.view');

$from   = (string)input('from', date('Y-m-01', strtotime('-2 months')));
$to     = (string)input('to', date('Y-m-d'));
$method = (string)input('method', '');
$search = (string)input('q', '');

$where = ['p.payment_date BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($method !== '') { $where[] = 'p.method = ?'; $args[] = $method; }
if ($search !== '') {
    $where[] = '(p.payment_no LIKE ? OR s.invoice_no LIKE ? OR c.full_name LIKE ? OR p.reference_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$rows = all("SELECT p.*, s.invoice_no, s.total_amount, s.balance, c.full_name AS client_name, u.full_name AS receiver
             FROM payments p JOIN sales s ON s.id = p.sale_id JOIN clients c ON c.id = p.client_id
             LEFT JOIN users u ON u.id = p.received_by
             WHERE $whereSql ORDER BY p.payment_date DESC, p.id DESC", $args);

if (input('export') === 'csv') {
    export_csv('payments-' . date('Y-m-d') . '.csv',
        ['Receipt no', 'Date', 'Client', 'Invoice', 'Method', 'Reference', 'Amount', 'Received by'],
        array_map(fn($r) => [$r['payment_no'], $r['payment_date'], $r['client_name'], $r['invoice_no'],
                             $r['method'], $r['reference_no'], $r['amount'], $r['receiver']], $rows));
}

$total   = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
$byMethod = [];
foreach ($rows as $r) { $byMethod[$r['method']] = ($byMethod[$r['method']] ?? 0) + (float)$r['amount']; }
arsort($byMethod);
$outstanding = (float)scalar("SELECT COALESCE(SUM(balance),0) FROM sales WHERE status IN ('Unpaid','Partial')");

$pageTitle = 'Payments received';
$pageSub   = count($rows) . ' payments between ' . fdate($from) . ' and ' . fdate($to);
include __DIR__ . '/includes/header.php';
?>
<div class="grid grid-3" style="margin-bottom:16px">
  <div class="stat is-ok"><div class="label">Collected in this period</div><div class="value sm"><?= money($total) ?></div>
    <div class="foot"><?= count($rows) ?> payments</div></div>
  <div class="stat is-blue"><div class="label">Most used method</div>
    <div class="value sm"><?= e(array_key_first($byMethod) ?: '—') ?></div>
    <div class="foot"><?= $byMethod ? money(reset($byMethod)) : 'nothing recorded' ?></div></div>
  <div class="stat is-danger"><div class="label">Still outstanding</div><div class="value sm"><?= money($outstanding) ?></div>
    <div class="foot">across all open invoices</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search receipt, invoice, client…"></div>
      <div class="field"><label>Method</label>
        <select class="input" name="method"><option value="">All methods</option>
          <?php foreach (['Cash', 'GCash', 'Bank Transfer', 'Cheque', 'Card', 'Other'] as $m): ?>
            <option value="<?= $m ?>" <?= $method === $m ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>No payments in this period</strong>Record payments from each invoice page.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Receipt</th><th>Date</th><th>Client</th><th>Invoice</th><th>Method</th>
        <th>Reference</th><th class="num">Amount</th><th>Received by</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="row-main"><?= e($r['payment_no']) ?></td>
          <td class="nowrap"><?= fdate($r['payment_date']) ?></td>
          <td><?= e($r['client_name']) ?></td>
          <td><a href="sale_view.php?id=<?= (int)$r['sale_id'] ?>"><?= e($r['invoice_no']) ?></a>
              <div class="row-sub">balance <?= money($r['balance']) ?></div></td>
          <td><?= e($r['method']) ?></td>
          <td class="small muted"><?= e($r['reference_no'] ?: '—') ?></td>
          <td class="num"><?= money($r['amount']) ?></td>
          <td class="small"><?= e($r['receiver'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="6" class="num">Total collected</th><th class="num"><?= money($total) ?></th><th></th></tr></tfoot>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
