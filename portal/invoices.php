<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$cid = (int)current_client()['id'];

$invoices = all('SELECT * FROM sales WHERE client_id = ? ORDER BY sale_date DESC', [$cid]);
$openId   = (int)input('id', 0);
$invoice  = $openId ? one('SELECT * FROM sales WHERE id = ? AND client_id = ?', [$openId, $cid]) : null;
$items    = $invoice ? all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$invoice['id']]) : [];
$payments = $invoice ? all('SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date', [$invoice['id']]) : [];

$billed  = array_sum(array_map(fn($s) => $s['status'] === 'Cancelled' ? 0 : (float)$s['total_amount'], $invoices));
$paid    = array_sum(array_map(fn($s) => (float)$s['amount_paid'], $invoices));
$balance = array_sum(array_map(fn($s) => (float)$s['balance'], $invoices));

$pageTitle = 'Invoices and payments';
$pageSub   = 'What has been billed, what you have paid and what is left';
include __DIR__ . '/header.php';
?>
<div class="grid grid-3" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Total billed</div><div class="value sm"><?= money($billed) ?></div>
    <div class="foot"><?= count($invoices) ?> invoices</div></div>
  <div class="stat is-ok"><div class="label">Paid</div><div class="value sm"><?= money($paid) ?></div>
    <div class="foot">received with thanks</div></div>
  <div class="stat <?= $balance > 0 ? 'is-danger' : '' ?>"><div class="label">Balance</div>
    <div class="value sm"><?= money($balance) ?></div><div class="foot">still open</div></div>
</div>

<?php if ($invoice): ?>
  <div class="print-bar" style="margin:0 0 16px">
    <a class="btn btn-outline" href="invoices.php">Back to list</a>
    <button class="btn" onclick="window.print()">Print or save as PDF</button>
  </div>
  <div class="doc">
    <div class="doc-head">
      <div><img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="">
        <div class="small muted" style="margin-top:10px"><?= e(setting('company_address')) ?><br>
          <?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?></div></div>
      <div class="doc-meta"><h1>Invoice</h1><div><strong><?= e($invoice['invoice_no']) ?></strong></div>
        <div>Date: <?= fdate($invoice['sale_date']) ?></div><div>Due: <?= fdate($invoice['due_date']) ?></div></div>
    </div>
    <div class="doc-parties">
      <div><div class="lbl">Billed to</div><strong><?= e(current_client()['full_name']) ?></strong><br>
        <?= e(current_client()['address'] ?: '') ?></div>
      <div><div class="lbl">Reference</div><?= e($invoice['reference_type']) ?><br>
        <span class="muted"><?= e($invoice['notes'] ?: '') ?></span></div>
    </div>
    <table class="doc-table">
      <thead><tr><th style="width:34px">#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
          <td class="num"><?= money($it['unit_price'], false) ?></td><td class="num"><?= money($it['line_total'], false) ?></td></tr>
      <?php endforeach; ?>
      <?php if ((float)$invoice['labor_charge'] > 0): ?>
        <tr><td><?= count($items) + 1 ?></td><td>Labour, installation and service charge</td><td class="num">1</td>
          <td class="num"><?= money($invoice['labor_charge'], false) ?></td><td class="num"><?= money($invoice['labor_charge'], false) ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <div class="doc-totals">
      <div><span>Subtotal</span><span><?= money((float)$invoice['items_total'] + (float)$invoice['labor_charge']) ?></span></div>
      <?php if ((float)$invoice['discount'] > 0): ?><div><span>Discount</span><span>− <?= money($invoice['discount']) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span><?= money($invoice['total_amount']) ?></span></div>
      <div><span>Paid</span><span><?= money($invoice['amount_paid']) ?></span></div>
      <div class="grand"><span>Balance</span><span><?= money($invoice['balance']) ?></span></div>
    </div>
    <?php if ($payments): ?>
      <h3 style="margin-top:28px">Payments received</h3>
      <table class="doc-table">
        <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
          <tr><td><?= e($p['payment_no']) ?></td><td><?= fdate($p['payment_date']) ?></td>
            <td><?= e($p['method']) ?></td><td class="num"><?= money($p['amount'], false) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card"><div class="card-body tight">
    <?php if (!$invoices): ?>
      <div class="empty"><strong>Nothing billed yet</strong>Your invoices will appear here.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Paid</th>
        <th class="num">Balance</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $s): ?>
        <tr><td class="row-main"><?= e($s['invoice_no']) ?><div class="row-sub"><?= e($s['reference_type']) ?></div></td>
          <td class="nowrap"><?= fdate($s['sale_date']) ?></td><td class="nowrap"><?= fdate($s['due_date']) ?></td>
          <td class="num"><?= money($s['total_amount']) ?></td><td class="num"><?= money($s['amount_paid']) ?></td>
          <td class="num"><?= money($s['balance']) ?></td><td><?= badge($s['status']) ?></td>
          <td class="actions-cell"><a class="btn btn-sm btn-outline" href="invoices.php?id=<?= (int)$s['id'] ?>">Open</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
