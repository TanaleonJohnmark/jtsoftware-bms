<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('sales.view');

/* ------------------------- Create an invoice ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'save') {
    check_csrf();
    guard('sales.manage');

    $clientId = (int)post_int('client_id');
    $refType  = (string)input('reference_type', 'Walk-in');
    $refId    = post_int('reference_id');
    $descs    = $_POST['item_desc'] ?? [];
    $qtys     = $_POST['item_qty'] ?? [];
    $prices   = $_POST['item_price'] ?? [];
    $prodIds  = $_POST['item_product'] ?? [];

    $lines = [];
    $itemsTotal = 0.0;
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        $qty  = (float)($qtys[$i] ?? 0);
        if ($desc === '' || $qty <= 0) { continue; }
        $price = (float)($prices[$i] ?? 0);
        $line  = round($qty * $price, 2);
        $itemsTotal += $line;
        $lines[] = ['product_id' => nn($prodIds[$i] ?? null), 'description' => $desc,
                    'quantity' => $qty, 'unit_price' => $price, 'line_total' => $line];
    }

    $labor = post_num('labor_charge');
    $disc  = post_num('discount');
    $tax   = post_num('tax');
    $total = $itemsTotal + $labor - $disc + $tax;

    if (!$clientId) {
        flash('Choose the client this invoice is for.', 'danger');
    } elseif (!$lines && $labor <= 0) {
        flash('Add at least one line item or a labour charge.', 'danger');
    } elseif ($total <= 0) {
        flash('The invoice total has to be more than zero.', 'danger');
    } else {
        // Stock is only moved for counter sales; project equipment is issued from the project page.
        $deductStock = ($refType !== 'Project') && isset($_POST['deduct_stock']);
        $short = [];
        if ($deductStock) {
            foreach ($lines as $l) {
                if (!$l['product_id']) { continue; }
                $prod = one('SELECT name, stock_qty FROM products WHERE id = ?', [$l['product_id']]);
                if ($prod && $prod['stock_qty'] < $l['quantity']) {
                    $short[] = $prod['name'] . ' (need ' . (int)$l['quantity'] . ', have ' . (int)$prod['stock_qty'] . ')';
                }
            }
        }
        if ($short) {
            flash('Not enough stock for: ' . implode('; ', $short) . '.', 'danger');
            redirect(base_url('sales.php'));
        }

        db()->beginTransaction();
        try {
            $no = next_code('sales', 'invoice_no', 'INV');
            q('INSERT INTO sales (invoice_no, client_id, reference_type, reference_id, sale_date, due_date, items_total,
                 labor_charge, discount, tax, total_amount, amount_paid, balance, status, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,"Unpaid",?,?)',
              [$no, $clientId, $refType, $refId, (string)input('sale_date', date('Y-m-d')), nn(input('due_date')),
               $itemsTotal, $labor, $disc, $tax, $total, $total, nn(input('notes')), current_user()['id']]);
            $saleId = (int)db()->lastInsertId();

            foreach ($lines as $l) {
                q('INSERT INTO sale_items (sale_id, product_id, description, quantity, unit_price, line_total)
                   VALUES (?,?,?,?,?,?)',
                  [$saleId, $l['product_id'], $l['description'], $l['quantity'], $l['unit_price'], $l['line_total']]);
                if ($deductStock && $l['product_id']) {
                    q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [(int)$l['quantity'], $l['product_id']]);
                    $balance = (int)scalar('SELECT stock_qty FROM products WHERE id = ?', [$l['product_id']]);
                    q('INSERT INTO inventory_transactions (product_id, trans_type, quantity, unit_cost, balance_after, reference_type, reference_id, remarks, user_id)
                       VALUES (?,"out",?,?,?,"Sale",?,?,?)',
                      [$l['product_id'], (int)$l['quantity'], $l['unit_price'], $balance, $saleId, 'Sold on ' . $no, current_user()['id']]);
                }
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('The invoice could not be saved: ' . $ex->getMessage(), 'danger');
            redirect(base_url('sales.php'));
        }
        log_activity('Sales', 'create', $saleId, 'Created invoice ' . $no . ' for ' . money($total));
        flash('Invoice ' . $no . ' created.');
        redirect(base_url('sale_view.php?id=' . $saleId));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'cancel') {
    check_csrf();
    guard('sales.manage');
    $id = (int)post_int('id');
    $paid = (float)scalar('SELECT amount_paid FROM sales WHERE id = ?', [$id]);
    if ($paid > 0) {
        flash('Payments are recorded against this invoice, so it cannot be cancelled.', 'warn');
    } else {
        q('UPDATE sales SET status = "Cancelled", balance = 0 WHERE id = ?', [$id]);
        log_activity('Sales', 'cancel', $id, 'Cancelled invoice #' . $id);
        flash('Invoice cancelled.');
    }
    redirect(url_with([]));
}

/* ------------------- Prefill from a project or job ------------------ */
$prefill = ['client_id' => '', 'reference_type' => 'Walk-in', 'reference_id' => '', 'items' => [],
            'labor_charge' => 0, 'discount' => 0, 'notes' => '', 'open' => false];

if ($fromProject = (int)input('from_project', 0)) {
    $p = one('SELECT * FROM cctv_projects WHERE id = ?', [$fromProject]);
    if ($p) {
        $prefill['client_id']      = $p['client_id'];
        $prefill['reference_type'] = 'Project';
        $prefill['reference_id']   = $p['id'];
        $prefill['labor_charge']   = (float)$p['installation_fee'];
        $prefill['discount']       = (float)$p['discount'];
        $prefill['notes']          = 'Installation project ' . $p['project_code'] . ' — ' . $p['title'];
        $prefill['open']           = true;
        $prefill['items'][] = ['product_id' => null,
            'description' => $p['title'] . ' — equipment and materials',
            'quantity' => 1, 'unit_price' => (float)$p['equipment_cost']];
    }
}
if ($fromService = (int)input('from_service', 0)) {
    $s = one('SELECT * FROM service_requests WHERE id = ?', [$fromService]);
    if ($s) {
        $prefill['client_id']      = $s['client_id'];
        $prefill['reference_type'] = 'Service';
        $prefill['reference_id']   = $s['id'];
        $prefill['labor_charge']   = (float)$s['service_fee'];
        $prefill['notes']          = 'Service request ' . $s['request_no'] . ' — ' . $s['service_type'];
        $prefill['open']           = true;
    }
}

/* ------------------------------ Listing ----------------------------- */
$search = (string)input('q', '');
$status = (string)input('status', '');
$from   = (string)input('from', date('Y-m-01', strtotime('-3 months')));
$to     = (string)input('to', date('Y-m-d'));

$where = ['s.sale_date BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($search !== '') {
    $where[] = '(s.invoice_no LIKE ? OR c.full_name LIKE ? OR s.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like);
}
if ($status !== '') { $where[] = 's.status = ?'; $args[] = $status; }
$whereSql = implode(' AND ', $where);

$sales = all("SELECT s.*, c.full_name AS client_name, c.business_name
              FROM sales s JOIN clients c ON c.id = s.client_id
              WHERE $whereSql ORDER BY s.sale_date DESC, s.id DESC", $args);

if (input('export') === 'csv') {
    export_csv('sales-' . date('Y-m-d') . '.csv',
        ['Invoice', 'Client', 'Date', 'Due', 'Source', 'Total', 'Paid', 'Balance', 'Status'],
        array_map(fn($s) => [$s['invoice_no'], $s['client_name'], $s['sale_date'], $s['due_date'], $s['reference_type'],
                             $s['total_amount'], $s['amount_paid'], $s['balance'], $s['status']], $sales));
}

$billed    = array_sum(array_map(fn($s) => $s['status'] === 'Cancelled' ? 0 : (float)$s['total_amount'], $sales));
$paidSum   = array_sum(array_map(fn($s) => (float)$s['amount_paid'], $sales));
$dueSum    = array_sum(array_map(fn($s) => (float)$s['balance'], $sales));
$overdue   = array_filter($sales, fn($s) => $s['balance'] > 0 && $s['due_date'] && $s['due_date'] < date('Y-m-d'));

$clients   = all('SELECT id, full_name, client_code FROM clients WHERE status = "active" ORDER BY full_name');
$products  = all('SELECT id, name, selling_price, stock_qty FROM products WHERE status = "active" ORDER BY name');

$pageTitle = 'Sales and invoices';
$pageSub   = count($sales) . ' invoices between ' . fdate($from) . ' and ' . fdate($to);
if (can('sales.manage')) {
    $pageActions = '<button class="btn" data-modal="saleModal">New invoice</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Billed in this period</div><div class="value sm"><?= money($billed) ?></div>
    <div class="foot"><?= count($sales) ?> invoices</div></div>
  <div class="stat is-ok"><div class="label">Collected</div><div class="value sm"><?= money($paidSum) ?></div>
    <div class="foot"><?= $billed > 0 ? round($paidSum / $billed * 100) : 0 ?>% of billed</div></div>
  <div class="stat is-warn"><div class="label">Still owed</div><div class="value sm"><?= money($dueSum) ?></div>
    <div class="foot">unpaid and partial</div></div>
  <div class="stat is-danger"><div class="label">Past due date</div><div class="value"><?= count($overdue) ?></div>
    <div class="foot">needs following up</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search invoice or client…"></div>
      <div class="field"><select class="input" name="status">
        <option value="">Any status</option>
        <?php foreach (['Unpaid', 'Partial', 'Paid', 'Cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$sales): ?>
      <div class="empty"><strong>No invoices in this period</strong>Create one, or raise it straight from a project or service job.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Invoice</th><th>Client</th><th>Date</th><th>Due</th><th class="num">Total</th>
        <th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s):
          $late = $s['balance'] > 0 && $s['due_date'] && $s['due_date'] < date('Y-m-d'); ?>
        <tr>
          <td><a class="row-main" href="sale_view.php?id=<?= (int)$s['id'] ?>"><?= e($s['invoice_no']) ?></a>
              <div class="row-sub"><?= e($s['reference_type']) ?></div></td>
          <td><?= e($s['client_name']) ?><div class="row-sub"><?= e($s['business_name'] ?: '') ?></div></td>
          <td class="nowrap"><?= fdate($s['sale_date']) ?></td>
          <td class="nowrap"><?= fdate($s['due_date']) ?><?= $late ? ' <span class="badge badge-danger">overdue</span>' : '' ?></td>
          <td class="num"><?= money($s['total_amount']) ?></td>
          <td class="num"><?= money($s['amount_paid']) ?></td>
          <td class="num"><?= money($s['balance']) ?></td>
          <td><?= badge($s['status']) ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-outline" href="sale_view.php?id=<?= (int)$s['id'] ?>">Open</a>
            <a class="btn btn-sm btn-outline" href="invoice_print.php?id=<?= (int)$s['id'] ?>">Print</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('sales.manage')): ?>
<div class="modal-backdrop <?= $prefill['open'] ? 'open' : '' ?>" id="saleModal">
  <div class="modal wide">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <input type="hidden" name="reference_type" value="<?= e($prefill['reference_type']) ?>">
      <input type="hidden" name="reference_id" value="<?= e($prefill['reference_id']) ?>">
      <div class="modal-head"><h3>New invoice<?= $prefill['reference_type'] !== 'Walk-in' ? ' from ' . e(strtolower($prefill['reference_type'])) : '' ?></h3>
        <button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row three">
          <div class="field"><label class="required">Client</label>
            <select class="input" name="client_id" required>
              <option value="">Select a client…</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$prefill['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['full_name']) ?> (<?= e($c['client_code']) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Invoice date</label><input class="input" type="date" name="sale_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>Payment due</label><input class="input" type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
        </div>

        <h3 style="margin-top:6px">Items</h3>
        <div class="table-wrap"><table class="data">
          <thead><tr><th style="width:210px">From inventory</th><th>Description</th><th class="num">Qty</th>
            <th class="num">Unit price</th><th class="num">Line total</th><th></th></tr></thead>
          <tbody id="itemRows"></tbody>
        </table></div>
        <div style="margin:10px 0 18px"><button type="button" class="btn btn-sm btn-outline" onclick="addItemRow()">Add line</button></div>

        <div class="form-row">
          <div>
            <div class="form-row three">
              <div class="field"><label>Labour / service charge</label>
                <input class="input" id="labor_charge" name="labor_charge" type="number" step="0.01" min="0" value="<?= e((string)$prefill['labor_charge']) ?>"></div>
              <div class="field"><label>Discount</label>
                <input class="input" id="discount" name="discount" type="number" step="0.01" min="0" value="<?= e((string)$prefill['discount']) ?>"></div>
              <div class="field"><label>Tax</label>
                <input class="input" id="tax" name="tax" type="number" step="0.01" min="0" value="0"></div>
            </div>
            <div class="field"><label>Notes</label><input class="input" name="notes" value="<?= e($prefill['notes']) ?>"></div>
            <?php if ($prefill['reference_type'] !== 'Project'): ?>
              <label class="check"><input type="checkbox" name="deduct_stock" value="1" checked>
                Take the item quantities out of inventory</label>
            <?php else: ?>
              <p class="hint">Project equipment is issued from the project page, so this invoice will not move stock again.</p>
            <?php endif; ?>
          </div>
          <div class="card" style="margin:0">
            <div class="card-body">
              <input type="hidden" name="items_total" id="items_total">
              <div class="flex-between"><span class="muted">Items subtotal</span><strong><span id="sumItems">0.00</span></strong></div>
              <div class="flex-between" style="border-top:2px solid var(--navy-800);padding-top:10px;margin-top:10px">
                <strong>Invoice total</strong>
                <strong style="font-size:1.18rem"><?= e(setting('currency_symbol', 'PHP ')) ?><span id="sumGrand">0.00</span></strong>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Create invoice</button>
      </div>
    </form>
  </div>
</div>

<script>
window.PRODUCT_CATALOG = <?= json_encode($products) ?>;
window.EXISTING_ITEMS = <?= json_encode($prefill['items']) ?>;
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
