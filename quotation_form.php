<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('quotations.manage');

$id    = (int)input('id', 0);
$quote = $id ? one('SELECT * FROM quotations WHERE id = ?', [$id]) : null;
if ($id && !$quote) {
    flash('That quotation no longer exists.', 'danger');
    redirect(base_url('quotations.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $clientId = (int)post_int('client_id');
    $subject  = (string)input('subject');
    $descs    = $_POST['item_desc'] ?? [];
    $qtys     = $_POST['item_qty'] ?? [];
    $prices   = $_POST['item_price'] ?? [];
    $prodIds  = $_POST['item_product'] ?? [];

    $lines = [];
    $itemsTotal = 0.0;
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        if ($desc === '') { continue; }
        $qty   = (float)($qtys[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($qty <= 0) { continue; }
        $line  = round($qty * $price, 2);
        $itemsTotal += $line;
        $lines[] = [nn($prodIds[$i] ?? null), $desc, $qty, $price, $line];
    }

    $labor = post_num('labor_charge');
    $disc  = post_num('discount');
    $tax   = post_num('tax');
    $total = $itemsTotal + $labor - $disc + $tax;

    if (!$clientId || $subject === '') {
        flash('Choose a client and write a subject for the quotation.', 'danger');
    } elseif (!$lines) {
        flash('Add at least one line with a description and a quantity.', 'danger');
    } else {
        db()->beginTransaction();
        try {
            if ($id) {
                q('UPDATE quotations SET client_id=?, subject=?, quote_date=?, valid_until=?, items_total=?,
                     labor_charge=?, discount=?, tax=?, total_amount=?, status=?, terms=?, notes=? WHERE id=?',
                  [$clientId, $subject, (string)input('quote_date', date('Y-m-d')), nn(input('valid_until')),
                   $itemsTotal, $labor, $disc, $tax, $total, (string)input('status', 'Draft'),
                   nn(input('terms')), nn(input('notes')), $id]);
                q('DELETE FROM quotation_items WHERE quotation_id = ?', [$id]);
                $quoteId = $id;
                $msg = 'Quotation updated.';
            } else {
                $no = next_code('quotations', 'quotation_no', 'QT');
                q('INSERT INTO quotations (quotation_no, client_id, subject, quote_date, valid_until, items_total,
                     labor_charge, discount, tax, total_amount, status, terms, notes, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$no, $clientId, $subject, (string)input('quote_date', date('Y-m-d')), nn(input('valid_until')),
                   $itemsTotal, $labor, $disc, $tax, $total, (string)input('status', 'Draft'),
                   nn(input('terms')), nn(input('notes')), current_user()['id']]);
                $quoteId = (int)db()->lastInsertId();
                $msg = 'Quotation ' . $no . ' saved.';
            }
            foreach ($lines as $l) {
                q('INSERT INTO quotation_items (quotation_id, product_id, description, quantity, unit_price, line_total)
                   VALUES (?,?,?,?,?,?)', array_merge([$quoteId], $l));
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('The quotation could not be saved: ' . $ex->getMessage(), 'danger');
            redirect(base_url('quotations.php'));
        }
        log_activity('Quotations', $id ? 'update' : 'create', $quoteId, $msg);
        flash($msg);
        redirect(base_url('quotation_print.php?id=' . $quoteId));
    }
}

$clients  = all('SELECT id, full_name, client_code FROM clients WHERE status = "active" ORDER BY full_name');
$products = all('SELECT id, name, selling_price, stock_qty FROM products WHERE status = "active" ORDER BY name');
$items    = $id ? all('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id', [$id]) : [];

$pageTitle   = $id ? 'Edit ' . $quote['quotation_no'] : 'New quotation';
$pageSub     = 'Lines pull prices from inventory, and the totals update as you type';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('quotations.php')) . '">Back to quotations</a>';
include __DIR__ . '/includes/header.php';
?>

<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-sidebar">
    <div>
      <div class="card">
        <div class="card-head"><h2>Quotation details</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="field"><label class="required">Client</label>
              <select class="input" name="client_id" required>
                <option value="">Select a client…</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $quote && (int)$quote['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['full_name']) ?> (<?= e($c['client_code']) ?>)</option>
                <?php endforeach; ?>
              </select></div>
            <div class="field"><label class="required">Subject</label>
              <input class="input" name="subject" required value="<?= e($quote['subject'] ?? '') ?>"
                     placeholder="8-Camera CCTV System with DVR and installation"></div>
          </div>
          <div class="form-row three">
            <div class="field"><label>Quotation date</label>
              <input class="input" name="quote_date" type="date" value="<?= e($quote['quote_date'] ?? date('Y-m-d')) ?>"></div>
            <div class="field"><label>Valid until</label>
              <input class="input" name="valid_until" type="date" value="<?= e($quote['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>"></div>
            <div class="field"><label>Status</label>
              <select class="input" name="status">
                <?php foreach (['Draft', 'Sent', 'Approved', 'Rejected', 'Expired'] as $s): ?>
                  <option <?= ($quote['status'] ?? 'Draft') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Products and services</h2>
          <button type="button" class="spacer btn btn-sm" onclick="addItemRow()">Add line</button></div>
        <div class="card-body tight">
          <div class="table-wrap"><table class="data">
            <thead><tr><th style="width:230px">From inventory</th><th>Description</th><th class="num">Qty</th>
              <th class="num">Unit price</th><th class="num">Line total</th><th></th></tr></thead>
            <tbody id="itemRows"></tbody>
          </table></div>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h2>Totals</h2></div>
        <div class="card-body">
          <input type="hidden" name="items_total" id="items_total">
          <div class="flex-between" style="padding:4px 0"><span class="muted">Items subtotal</span>
            <strong><span id="sumItems">0.00</span></strong></div>
          <div class="field" style="margin-top:12px"><label>Labour / installation charge</label>
            <input class="input" id="labor_charge" name="labor_charge" type="number" step="0.01" min="0"
                   value="<?= e($quote['labor_charge'] ?? '0') ?>"></div>
          <div class="field"><label>Discount</label>
            <input class="input" id="discount" name="discount" type="number" step="0.01" min="0"
                   value="<?= e($quote['discount'] ?? '0') ?>"></div>
          <div class="field"><label>Tax</label>
            <input class="input" id="tax" name="tax" type="number" step="0.01" min="0"
                   value="<?= e($quote['tax'] ?? '0') ?>"></div>
          <div class="flex-between" style="border-top:2px solid var(--navy-800);padding-top:10px;margin-top:6px">
            <strong>Total</strong>
            <strong style="font-size:1.2rem"><?= e(setting('currency_symbol', 'PHP ')) ?><span id="sumGrand">0.00</span></strong>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Terms and notes</h2></div>
        <div class="card-body">
          <div class="field"><label>Terms and conditions</label>
            <textarea class="input" name="terms" style="min-height:140px"><?= e($quote['terms'] ?? setting('quotation_terms')) ?></textarea></div>
          <div class="field"><label>Internal notes</label>
            <textarea class="input" name="notes"><?= e($quote['notes'] ?? '') ?></textarea>
            <p class="hint">Notes stay inside the system and are not printed on the client copy.</p></div>
          <button class="btn btn-block" type="submit">Save quotation</button>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
window.PRODUCT_CATALOG = <?= json_encode($products) ?>;
window.EXISTING_ITEMS = <?= json_encode(array_map(fn($i) => [
    'product_id' => $i['product_id'], 'description' => $i['description'],
    'quantity' => (float)$i['quantity'], 'unit_price' => (float)$i['unit_price'],
], $items)) ?>;
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
