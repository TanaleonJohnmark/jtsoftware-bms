<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('sales.view');

$id = (int)input('id', 0);

/** Recalculate what a client still owes on one invoice. */
function refresh_sale_balance(int $saleId): void
{
    $sale = one('SELECT total_amount, status FROM sales WHERE id = ?', [$saleId]);
    if (!$sale) { return; }
    $paid    = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE sale_id = ?', [$saleId]);
    $balance = max(0, round((float)$sale['total_amount'] - $paid, 2));
    $status  = $sale['status'] === 'Cancelled' ? 'Cancelled'
             : ($balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid'));
    q('UPDATE sales SET amount_paid = ?, balance = ?, status = ? WHERE id = ?', [$paid, $balance, $status, $saleId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('sales.manage');
    $action = input('action');
    $back   = base_url('sale_view.php?id=' . $id);
    $sale   = one('SELECT * FROM sales WHERE id = ?', [$id]);
    if (!$sale) {
        flash('That invoice no longer exists.', 'danger');
        redirect(base_url('sales.php'));
    }

    if ($action === 'pay') {
        $amount = post_num('amount');
        if ($amount <= 0) {
            flash('Enter a payment amount greater than zero.', 'danger');
            redirect($back);
        }
        if ($amount > (float)$sale['balance'] + 0.001) {
            flash('That is more than the remaining balance of ' . money($sale['balance']) . '.', 'danger');
            redirect($back);
        }
        $no = next_code('payments', 'payment_no', 'PAY');
        q('INSERT INTO payments (payment_no, sale_id, client_id, amount, payment_date, method, reference_no, received_by, notes)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$no, $id, $sale['client_id'], $amount, (string)input('payment_date', date('Y-m-d')),
           (string)input('method', 'Cash'), nn(input('reference_no')), current_user()['id'], nn(input('notes'))]);
        refresh_sale_balance($id);
        log_activity('Payments', 'create', (int)db()->lastInsertId(), $no . ' — ' . money($amount) . ' on ' . $sale['invoice_no']);
        flash('Payment ' . $no . ' recorded.');
        redirect($back);
    }

    if ($action === 'void_payment') {
        $paymentId = (int)post_int('payment_id');
        $payment = one('SELECT * FROM payments WHERE id = ? AND sale_id = ?', [$paymentId, $id]);
        if ($payment) {
            q('DELETE FROM payments WHERE id = ?', [$paymentId]);
            refresh_sale_balance($id);
            log_activity('Payments', 'void', $paymentId, 'Voided payment ' . $payment['payment_no']);
            flash('Payment ' . $payment['payment_no'] . ' voided and the balance restored.');
        }
        redirect($back);
    }

    if ($action === 'due_date') {
        q('UPDATE sales SET due_date = ?, notes = ? WHERE id = ?', [nn(input('due_date')), nn(input('notes')), $id]);
        flash('Invoice updated.');
        redirect($back);
    }
}

$sale = one('SELECT s.*, c.full_name, c.business_name, c.address, c.contact_number, c.email, c.client_code,
                    u.full_name AS created_by_name
             FROM sales s JOIN clients c ON c.id = s.client_id
             LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
if (!$sale) {
    flash('That invoice no longer exists.', 'danger');
    redirect(base_url('sales.php'));
}
$items    = all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$payments = all('SELECT p.*, u.full_name AS receiver FROM payments p LEFT JOIN users u ON u.id = p.received_by
                 WHERE p.sale_id = ? ORDER BY p.payment_date, p.id', [$id]);

$sourceLink = '';
if ($sale['reference_type'] === 'Project' && $sale['reference_id']) {
    $sourceLink = '<a href="project_view.php?id=' . (int)$sale['reference_id'] . '">open project</a>';
} elseif ($sale['reference_type'] === 'Quotation' && $sale['reference_id']) {
    $sourceLink = '<a href="quotation_print.php?id=' . (int)$sale['reference_id'] . '">open quotation</a>';
}

$pageTitle = $sale['invoice_no'];
$pageSub   = $sale['full_name'] . ' · ' . $sale['reference_type'];
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('sales.php')) . '">All invoices</a>'
             . ' <a class="btn btn-outline" href="' . e(base_url('invoice_print.php?id=' . $id)) . '">Print invoice</a>';
if ((float)$sale['amount_paid'] > 0) {
    $pageActions .= ' <a class="btn btn-outline" href="' . e(base_url('invoice_print.php?id=' . $id . '&type=receipt')) . '">Print receipt</a>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Invoice total</div><div class="value sm"><?= money($sale['total_amount']) ?></div>
    <div class="foot"><?= fdate($sale['sale_date']) ?></div></div>
  <div class="stat is-ok"><div class="label">Paid</div><div class="value sm"><?= money($sale['amount_paid']) ?></div>
    <div class="foot"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?></div></div>
  <div class="stat <?= $sale['balance'] > 0 ? 'is-danger' : '' ?>"><div class="label">Balance</div>
    <div class="value sm"><?= money($sale['balance']) ?></div><div class="foot">due <?= fdate($sale['due_date']) ?></div></div>
  <div class="stat is-copper"><div class="label">Status</div><div class="value sm"><?= e($sale['status']) ?></div>
    <div class="foot"><?= $sourceLink ?: e($sale['reference_type']) ?></div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Invoice lines</h2></div>
      <div class="card-body tight">
        <?php if (!$items): ?>
          <div class="empty">This invoice has no item lines — only a labour or service charge.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['description']) ?></td>
                <td class="num"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
                <td class="num"><?= money($it['unit_price'], false) ?></td>
                <td class="num"><?= money($it['line_total'], false) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
        <div class="card-body">
          <div class="doc-totals" style="width:300px">
            <div><span>Items subtotal</span><span><?= money($sale['items_total']) ?></span></div>
            <?php if ((float)$sale['labor_charge'] > 0): ?><div><span>Labour / service</span><span><?= money($sale['labor_charge']) ?></span></div><?php endif; ?>
            <?php if ((float)$sale['discount'] > 0): ?><div><span>Discount</span><span>− <?= money($sale['discount']) ?></span></div><?php endif; ?>
            <?php if ((float)$sale['tax'] > 0): ?><div><span>Tax</span><span><?= money($sale['tax']) ?></span></div><?php endif; ?>
            <div class="grand"><span>Total</span><span><?= money($sale['total_amount']) ?></span></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Payments received</h2>
        <span class="spacer small muted">balance <?= money($sale['balance']) ?></span></div>
      <div class="card-body tight">
        <?php if (!$payments): ?>
          <div class="empty"><strong>No payments yet</strong>Record one on the right as soon as the client pays.</div>
        <?php else: ?>
        <table class="data">
          <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Received by</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="row-main"><?= e($p['payment_no']) ?></td>
              <td class="nowrap"><?= fdate($p['payment_date']) ?></td>
              <td><?= e($p['method']) ?></td>
              <td class="small muted"><?= e($p['reference_no'] ?: '—') ?></td>
              <td class="num"><?= money($p['amount']) ?></td>
              <td class="small"><?= e($p['receiver'] ?: '—') ?></td>
              <td class="actions-cell">
                <?php if (can('sales.manage')): ?>
                  <form method="post" data-confirm="Void <?= e($p['payment_no']) ?>? The balance goes back up.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="void_payment">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <button class="linkbtn" type="submit">void</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <?php if (can('sales.manage') && $sale['balance'] > 0 && $sale['status'] !== 'Cancelled'): ?>
    <div class="card">
      <div class="card-head"><h2>Record a payment</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="pay">
          <div class="field"><label class="required">Amount</label>
            <input class="input" name="amount" type="number" step="0.01" min="0.01"
                   max="<?= e($sale['balance']) ?>" value="<?= e($sale['balance']) ?>" required>
            <p class="hint">Remaining balance: <?= money($sale['balance']) ?></p></div>
          <div class="field"><label>Payment date</label><input class="input" name="payment_date" type="date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>Method</label>
            <select class="input" name="method">
              <?php foreach (['Cash', 'GCash', 'Bank Transfer', 'Cheque', 'Card', 'Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Reference number</label><input class="input" name="reference_no" placeholder="GCash or bank reference"></div>
          <div class="field"><label>Notes</label><input class="input" name="notes" placeholder="50% downpayment"></div>
          <button class="btn btn-block" type="submit">Record payment</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h2>Bill to</h2></div>
      <div class="card-body">
        <dl class="dl">
          <dt>Client</dt><dd><a href="client_view.php?id=<?= (int)$sale['client_id'] ?>"><?= e($sale['full_name']) ?></a></dd>
          <dt>Business</dt><dd><?= e($sale['business_name'] ?: '—') ?></dd>
          <dt>Contact</dt><dd><?= e($sale['contact_number'] ?: '—') ?></dd>
          <dt>Address</dt><dd><?= e($sale['address'] ?: '—') ?></dd>
          <dt>Raised by</dt><dd><?= e($sale['created_by_name'] ?: '—') ?></dd>
          <dt>Notes</dt><dd><?= e($sale['notes'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <?php if (can('sales.manage') && $sale['status'] !== 'Cancelled'): ?>
    <div class="card">
      <div class="card-head"><h2>Adjust invoice</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="due_date">
          <div class="field"><label>Payment due date</label>
            <input class="input" type="date" name="due_date" value="<?= e($sale['due_date']) ?>"></div>
          <div class="field"><label>Notes</label><input class="input" name="notes" value="<?= e($sale['notes']) ?>"></div>
          <button class="btn btn-outline btn-block" type="submit">Save changes</button>
        </form>
        <?php if ((float)$sale['amount_paid'] <= 0): ?>
          <form method="post" action="sales.php" data-confirm="Cancel this invoice?" style="margin-top:10px">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$id ?>">
            <button class="btn btn-danger btn-block" type="submit">Cancel invoice</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
