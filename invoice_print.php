<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('sales.view');

$id   = (int)input('id', 0);
$type = input('type') === 'receipt' ? 'receipt' : 'invoice';

$sale = one('SELECT s.*, c.full_name, c.business_name, c.address, c.contact_number, c.email, c.client_code,
                    u.full_name AS created_by_name
             FROM sales s JOIN clients c ON c.id = s.client_id
             LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
if (!$sale) {
    flash('That invoice no longer exists.', 'danger');
    redirect(base_url('sales.php'));
}
$items    = all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$payments = all('SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date, id', [$id]);

$pageTitle   = $sale['invoice_no'] . ($type === 'receipt' ? ' · receipt' : '');
$pageSub     = $sale['full_name'];
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('sale_view.php?id=' . $id)) . '">Back to invoice</a>';
include __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print">
  <button class="btn" onclick="window.print()">Print or save as PDF</button>
  <a class="btn btn-outline" href="invoice_print.php?id=<?= $id ?>&type=<?= $type === 'receipt' ? 'invoice' : 'receipt' ?>">
    Switch to <?= $type === 'receipt' ? 'invoice' : 'receipt' ?></a>
  <span class="flex muted small" style="margin-left:auto">Status: <?= badge($sale['status']) ?></span>
</div>

<div class="doc">
  <div class="doc-head">
    <div>
      <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="<?= e(setting('company_name')) ?>">
      <div class="small muted" style="margin-top:10px">
        <?= e(setting('company_address')) ?><br>
        <?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1><?= $type === 'receipt' ? 'Receipt' : 'Invoice' ?></h1>
      <div><strong><?= e($sale['invoice_no']) ?></strong></div>
      <div>Date: <?= fdate($sale['sale_date']) ?></div>
      <?php if ($type === 'invoice'): ?><div>Due: <?= fdate($sale['due_date']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="doc-parties">
    <div>
      <div class="lbl">Billed to</div>
      <strong><?= e($sale['full_name']) ?></strong><br>
      <?= $sale['business_name'] ? e($sale['business_name']) . '<br>' : '' ?>
      <?= e($sale['address'] ?: '') ?><br>
      <?= e($sale['contact_number'] ?: '') ?>
    </div>
    <div>
      <div class="lbl">Reference</div>
      <?= e($sale['reference_type']) ?><?= $sale['reference_id'] ? ' #' . (int)$sale['reference_id'] : '' ?><br>
      <span class="muted"><?= e($sale['notes'] ?: '') ?></span>
    </div>
  </div>

  <?php if ($type === 'invoice'): ?>
    <table class="doc-table">
      <thead><tr><th style="width:34px">#</th><th>Description</th><th class="num">Qty</th>
        <th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
            <td class="num"><?= money($it['unit_price'], false) ?></td>
            <td class="num"><?= money($it['line_total'], false) ?></td></tr>
        <?php endforeach; ?>
        <?php if ((float)$sale['labor_charge'] > 0): ?>
          <tr><td><?= count($items) + 1 ?></td><td>Labour, installation and service charge</td>
            <td class="num">1</td><td class="num"><?= money($sale['labor_charge'], false) ?></td>
            <td class="num"><?= money($sale['labor_charge'], false) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="doc-totals">
      <div><span>Subtotal</span><span><?= money((float)$sale['items_total'] + (float)$sale['labor_charge']) ?></span></div>
      <?php if ((float)$sale['discount'] > 0): ?><div><span>Discount</span><span>− <?= money($sale['discount']) ?></span></div><?php endif; ?>
      <?php if ((float)$sale['tax'] > 0): ?><div><span>Tax</span><span><?= money($sale['tax']) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total due</span><span><?= money($sale['total_amount']) ?></span></div>
      <?php if ((float)$sale['amount_paid'] > 0): ?>
        <div><span>Paid to date</span><span><?= money($sale['amount_paid']) ?></span></div>
        <div class="grand"><span>Balance</span><span><?= money($sale['balance']) ?></span></div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table class="doc-table">
      <thead><tr><th>Receipt no</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr><td><?= e($p['payment_no']) ?></td><td><?= fdate($p['payment_date']) ?></td>
            <td><?= e($p['method']) ?></td><td><?= e($p['reference_no'] ?: '—') ?></td>
            <td class="num"><?= money($p['amount'], false) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="5">No payments recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="doc-totals">
      <div><span>Invoice total</span><span><?= money($sale['total_amount']) ?></span></div>
      <div><span>Total received</span><span><?= money($sale['amount_paid']) ?></span></div>
      <div class="grand"><span>Balance</span><span><?= money($sale['balance']) ?></span></div>
    </div>
  <?php endif; ?>

  <div class="doc-terms">
    <strong>Payment details</strong><br>
    Please settle the balance on or before the due date. Cash, GCash, bank transfer and cheque are accepted.
    Reference the invoice number on any transfer so the payment is credited to the right account.
  </div>

  <div class="doc-sign">
    <div>Issued by<br><strong><?= e($sale['created_by_name'] ?: setting('company_name')) ?></strong><br>
      <span class="muted"><?= e(setting('company_division')) ?></span></div>
    <div>Received by — signature over printed name<br><br><?= e($sale['full_name']) ?></div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
