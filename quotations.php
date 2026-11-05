<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('quotations.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('quotations.manage');
    $action = input('action');
    $id     = (int)post_int('id');
    $quote  = one('SELECT * FROM quotations WHERE id = ?', [$id]);
    if (!$quote) {
        flash('That quotation no longer exists.', 'danger');
        redirect(url_with([]));
    }

    if ($action === 'status') {
        $status = (string)input('status');
        q('UPDATE quotations SET status = ? WHERE id = ?', [$status, $id]);
        log_activity('Quotations', 'status', $id, $quote['quotation_no'] . ' marked ' . $status);
        flash('Quotation marked ' . $status . '.');
        redirect(url_with([]));
    }

    if ($action === 'delete') {
        q('DELETE FROM quotations WHERE id = ?', [$id]);
        log_activity('Quotations', 'delete', $id, 'Deleted quotation ' . $quote['quotation_no']);
        flash('Quotation deleted.');
        redirect(url_with([]));
    }

    if ($action === 'to_project') {
        guard('projects.manage');
        if ($quote['project_id']) {
            flash('This quotation is already linked to project #' . $quote['project_id'] . '.', 'warn');
            redirect(url_with([]));
        }
        $items = all('SELECT * FROM quotation_items WHERE quotation_id = ?', [$id]);
        $cams  = 0;
        foreach ($items as $it) {
            if (stripos($it['description'], 'camera') !== false) {
                $cams += (int)$it['quantity'];
            }
        }
        db()->beginTransaction();
        try {
            $code = next_code('cctv_projects', 'project_code', 'PRJ');
            q('INSERT INTO cctv_projects (project_code, client_id, title, camera_count, installation_fee, equipment_cost,
                 discount, total_cost, status, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,"Approved",?,?)',
              [$code, $quote['client_id'], $quote['subject'], $cams, $quote['labor_charge'], $quote['items_total'],
               $quote['discount'], $quote['total_amount'], 'Created from quotation ' . $quote['quotation_no'], current_user()['id']]);
            $projectId = (int)db()->lastInsertId();
            foreach ($items as $it) {
                q('INSERT INTO project_equipment (project_id, product_id, description, quantity, unit_cost, line_total)
                   VALUES (?,?,?,?,?,?)',
                  [$projectId, $it['product_id'], $it['description'], (int)$it['quantity'], $it['unit_price'], $it['line_total']]);
            }
            q('UPDATE quotations SET status = "Converted", project_id = ? WHERE id = ?', [$projectId, $id]);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('The project could not be created: ' . $ex->getMessage(), 'danger');
            redirect(url_with([]));
        }
        log_activity('Quotations', 'convert', $id, $quote['quotation_no'] . ' converted to project ' . $code);
        flash('Project ' . $code . ' created from ' . $quote['quotation_no'] . '.');
        redirect(base_url('project_view.php?id=' . $projectId));
    }

    if ($action === 'to_invoice') {
        guard('sales.manage');
        $existing = one('SELECT id FROM sales WHERE reference_type = "Quotation" AND reference_id = ?', [$id]);
        if ($existing) {
            flash('An invoice was already raised from this quotation.', 'warn');
            redirect(base_url('sale_view.php?id=' . $existing['id']));
        }
        $items = all('SELECT * FROM quotation_items WHERE quotation_id = ?', [$id]);
        db()->beginTransaction();
        try {
            $no = next_code('sales', 'invoice_no', 'INV');
            q('INSERT INTO sales (invoice_no, client_id, reference_type, reference_id, sale_date, due_date, items_total,
                 labor_charge, discount, tax, total_amount, balance, status, notes, created_by)
               VALUES (?,?,"Quotation",?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 7 DAY),?,?,?,?,?,?,"Unpaid",?,?)',
              [$no, $quote['client_id'], $id, $quote['items_total'], $quote['labor_charge'], $quote['discount'],
               $quote['tax'], $quote['total_amount'], $quote['total_amount'],
               'Raised from quotation ' . $quote['quotation_no'], current_user()['id']]);
            $saleId = (int)db()->lastInsertId();
            foreach ($items as $it) {
                q('INSERT INTO sale_items (sale_id, product_id, description, quantity, unit_price, line_total)
                   VALUES (?,?,?,?,?,?)',
                  [$saleId, $it['product_id'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
            }
            q('UPDATE quotations SET status = "Converted" WHERE id = ?', [$id]);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('The invoice could not be created: ' . $ex->getMessage(), 'danger');
            redirect(url_with([]));
        }
        log_activity('Quotations', 'convert', $id, $quote['quotation_no'] . ' converted to invoice ' . $no);
        flash('Invoice ' . $no . ' created.');
        redirect(base_url('sale_view.php?id=' . $saleId));
    }
}

/* Mark overdue quotations as expired so the list stays honest. */
q('UPDATE quotations SET status = "Expired"
   WHERE status IN ("Draft","Sent") AND valid_until IS NOT NULL AND valid_until < CURDATE()');

$search = (string)input('q', '');
$status = (string)input('status', '');
$where  = ['1=1'];
$args   = [];
if ($search !== '') {
    $where[] = '(qt.quotation_no LIKE ? OR qt.subject LIKE ? OR c.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like);
}
if ($status !== '') { $where[] = 'qt.status = ?'; $args[] = $status; }
$whereSql = implode(' AND ', $where);

$quotes = all(
    "SELECT qt.*, c.full_name AS client_name, c.business_name,
            (SELECT COUNT(*) FROM quotation_items qi WHERE qi.quotation_id = qt.id) AS item_count
     FROM quotations qt JOIN clients c ON c.id = qt.client_id
     WHERE $whereSql ORDER BY qt.quote_date DESC, qt.id DESC", $args
);

if (input('export') === 'csv') {
    export_csv('quotations-' . date('Y-m-d') . '.csv',
        ['Quotation no', 'Client', 'Subject', 'Date', 'Valid until', 'Items', 'Labour', 'Discount', 'Total', 'Status'],
        array_map(fn($r) => [$r['quotation_no'], $r['client_name'], $r['subject'], $r['quote_date'], $r['valid_until'],
                             $r['items_total'], $r['labor_charge'], $r['discount'], $r['total_amount'], $r['status']], $quotes));
}

$approvedValue = (float)scalar("SELECT COALESCE(SUM(total_amount),0) FROM quotations WHERE status IN ('Approved','Converted')");
$pendingValue  = (float)scalar("SELECT COALESCE(SUM(total_amount),0) FROM quotations WHERE status IN ('Draft','Sent')");
$sentCount     = (int)scalar("SELECT COUNT(*) FROM quotations WHERE status = 'Sent'");
$winRate       = (int)scalar("SELECT COUNT(*) FROM quotations WHERE status IN ('Approved','Converted')");
$decided       = (int)scalar("SELECT COUNT(*) FROM quotations WHERE status IN ('Approved','Converted','Rejected','Expired')");

$pageTitle = 'Quotations';
$pageSub   = count($quotes) . ' quotation' . (count($quotes) === 1 ? '' : 's');
if (can('quotations.manage')) {
    $pageActions = '<a class="btn" href="' . e(base_url('quotation_form.php')) . '">New quotation</a>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Awaiting a decision</div><div class="value sm"><?= money($pendingValue) ?></div>
    <div class="foot"><?= $sentCount ?> sent to clients</div></div>
  <div class="stat is-ok"><div class="label">Approved value</div><div class="value sm"><?= money($approvedValue) ?></div>
    <div class="foot">approved and converted</div></div>
  <div class="stat is-copper"><div class="label">Win rate</div>
    <div class="value"><?= $decided ? round($winRate / $decided * 100) : 0 ?>%</div>
    <div class="foot"><?= $winRate ?> won of <?= $decided ?> decided</div></div>
  <div class="stat"><div class="label">Total quotations</div><div class="value"><?= count($quotes) ?></div>
    <div class="foot">in the current filter</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search number, subject, client…"></div>
      <div class="field"><select class="input" name="status">
        <option value="">Any status</option>
        <?php foreach (['Draft', 'Sent', 'Approved', 'Rejected', 'Expired', 'Converted'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <?php if ($search || $status): ?><a class="btn btn-outline" href="quotations.php">Clear</a><?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$quotes): ?>
      <div class="empty"><strong>No quotations yet</strong>Build one and it can turn straight into a project or an invoice.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Quotation</th><th>Client</th><th>Date</th><th>Valid until</th>
        <th class="num">Items</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($quotes as $qt): ?>
        <tr>
          <td><a class="row-main" href="quotation_print.php?id=<?= (int)$qt['id'] ?>"><?= e($qt['quotation_no']) ?></a>
              <div class="row-sub"><?= e($qt['subject']) ?></div></td>
          <td><?= e($qt['client_name']) ?><div class="row-sub"><?= e($qt['business_name'] ?: '') ?></div></td>
          <td class="nowrap"><?= fdate($qt['quote_date']) ?></td>
          <td class="nowrap"><?= fdate($qt['valid_until']) ?></td>
          <td class="num"><?= (int)$qt['item_count'] ?></td>
          <td class="num"><?= money($qt['total_amount']) ?></td>
          <td><?= badge($qt['status']) ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-outline" href="quotation_print.php?id=<?= (int)$qt['id'] ?>">View / print</a>
            <?php if (can('quotations.manage')): ?>
              <a class="btn btn-sm btn-outline" href="quotation_form.php?id=<?= (int)$qt['id'] ?>">Edit</a>
              <?php if (in_array($qt['status'], ['Draft', 'Sent'], true)): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$qt['id'] ?>">
                  <input type="hidden" name="status" value="<?= $qt['status'] === 'Draft' ? 'Sent' : 'Approved' ?>">
                  <button class="btn btn-sm <?= $qt['status'] === 'Draft' ? 'btn-outline' : 'btn-ok' ?>" type="submit">
                    Mark <?= $qt['status'] === 'Draft' ? 'sent' : 'approved' ?></button>
                </form>
              <?php endif; ?>
              <?php if ($qt['status'] === 'Approved'): ?>
                <form method="post" style="display:inline" data-confirm="Create a CCTV project from this quotation?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="to_project"><input type="hidden" name="id" value="<?= (int)$qt['id'] ?>">
                  <button class="btn btn-sm btn-navy" type="submit">To project</button>
                </form>
                <form method="post" style="display:inline" data-confirm="Raise an invoice from this quotation?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="to_invoice"><input type="hidden" name="id" value="<?= (int)$qt['id'] ?>">
                  <button class="btn btn-sm btn-navy" type="submit">To invoice</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline" data-confirm="Delete <?= e($qt['quotation_no']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$qt['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
