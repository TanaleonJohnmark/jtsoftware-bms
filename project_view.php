<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('projects.view');

$id = (int)input('id', 0);

/** Keep equipment_cost and total_cost in step with the equipment lines. */
function recalc_project_cost(int $projectId): void
{
    $equip = (float)scalar('SELECT COALESCE(SUM(line_total),0) FROM project_equipment WHERE project_id = ?', [$projectId]);
    q('UPDATE cctv_projects SET equipment_cost = ?, total_cost = installation_fee + ? - discount WHERE id = ?',
      [$equip, $equip, $projectId]);
}

/* ----------------------------- Actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('projects.manage');
    $action = input('action');
    $back   = base_url('project_view.php?id=' . $id);

    if ($action === 'progress') {
        $progress = max(0, min(100, (int)post_int('progress', 0)));
        $status   = (string)input('status');
        q('UPDATE cctv_projects SET progress = ?, status = ? WHERE id = ?', [$progress, $status, $id]);
        if ($status === 'Completed') {
            q('UPDATE cctv_projects SET progress = 100, completed_at = COALESCE(completed_at, CURDATE()) WHERE id = ?', [$id]);
        }
        q('INSERT INTO project_updates (project_id, progress, status, note, user_id) VALUES (?,?,?,?,?)',
          [$id, $progress, $status, nn(input('note')), current_user()['id']]);
        log_activity('Projects', 'progress', $id, 'Project progress set to ' . $progress . '% (' . $status . ')');
        flash('Progress updated.');
        redirect($back);
    }

    if ($action === 'add_equipment') {
        $productId = post_int('product_id');
        $qty       = max(1, (int)post_int('quantity', 1));
        $cost      = post_num('unit_cost');
        $desc      = (string)input('description');
        if ($productId) {
            $prod = one('SELECT * FROM products WHERE id = ?', [$productId]);
            if ($prod) {
                $desc = $desc !== '' ? $desc : $prod['name'];
                $cost = $cost > 0 ? $cost : (float)$prod['selling_price'];
            }
        }
        if ($desc === '') {
            flash('Describe the equipment line.', 'danger');
            redirect($back);
        }
        q('INSERT INTO project_equipment (project_id, product_id, description, quantity, unit_cost, line_total)
           VALUES (?,?,?,?,?,?)', [$id, $productId, $desc, $qty, $cost, $qty * $cost]);
        recalc_project_cost($id);
        log_activity('Projects', 'equipment', $id, 'Added ' . $qty . ' x ' . $desc . ' to project #' . $id);
        flash('Equipment line added.');
        redirect($back);
    }

    if ($action === 'remove_equipment') {
        $lineId = (int)post_int('line_id');
        $line = one('SELECT * FROM project_equipment WHERE id = ? AND project_id = ?', [$lineId, $id]);
        if ($line && $line['stock_deducted']) {
            flash('Return the stock first — this line has already been taken out of inventory.', 'warn');
            redirect($back);
        }
        q('DELETE FROM project_equipment WHERE id = ? AND project_id = ?', [$lineId, $id]);
        recalc_project_cost($id);
        flash('Equipment line removed.');
        redirect($back);
    }

    if ($action === 'deduct_stock') {
        guard('inventory.manage');
        $lines = all('SELECT * FROM project_equipment WHERE project_id = ? AND stock_deducted = 0 AND product_id IS NOT NULL', [$id]);
        if (!$lines) {
            flash('Nothing left to take out of stock for this project.', 'info');
            redirect($back);
        }
        $short = [];
        foreach ($lines as $l) {
            $prod = one('SELECT * FROM products WHERE id = ?', [$l['product_id']]);
            if (!$prod) { continue; }
            if ((int)$prod['stock_qty'] < (int)$l['quantity']) {
                $short[] = $prod['name'] . ' (need ' . (int)$l['quantity'] . ', have ' . (int)$prod['stock_qty'] . ')';
            }
        }
        if ($short) {
            flash('Not enough stock for: ' . implode('; ', $short) . '. Receive stock first.', 'danger');
            redirect($back);
        }
        db()->beginTransaction();
        try {
            foreach ($lines as $l) {
                q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [$l['quantity'], $l['product_id']]);
                $balance = (int)scalar('SELECT stock_qty FROM products WHERE id = ?', [$l['product_id']]);
                q('INSERT INTO inventory_transactions (product_id, trans_type, quantity, unit_cost, balance_after, reference_type, reference_id, remarks, user_id)
                   VALUES (?,?,?,?,?,?,?,?,?)',
                  [$l['product_id'], 'out', $l['quantity'], $l['unit_cost'], $balance, 'Project', $id,
                   'Issued to project #' . $id, current_user()['id']]);
                q('UPDATE project_equipment SET stock_deducted = 1 WHERE id = ?', [$l['id']]);
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Stock was not deducted: ' . $ex->getMessage(), 'danger');
            redirect($back);
        }
        log_activity('Inventory', 'issue', $id, 'Issued project equipment for project #' . $id);
        flash(count($lines) . ' item line(s) taken out of inventory.');
        redirect($back);
    }

    if ($action === 'assign') {
        $empId = (int)post_int('employee_id');
        if ($empId) {
            q('INSERT IGNORE INTO project_assignments (project_id, employee_id, role_in_project) VALUES (?,?,?)',
              [$id, $empId, (string)input('role_in_project', 'Technician')]);
            log_activity('Projects', 'assign', $id, 'Assigned employee #' . $empId . ' to project #' . $id);
            flash('Technician assigned.');
        }
        redirect($back);
    }

    if ($action === 'unassign') {
        q('DELETE FROM project_assignments WHERE project_id = ? AND employee_id = ?', [$id, (int)post_int('employee_id')]);
        flash('Technician removed from this project.');
        redirect($back);
    }

    if ($action === 'approval') {
        q('UPDATE cctv_projects SET customer_approved = ?, approved_by_name = ?, approved_date = ? WHERE id = ?',
          [isset($_POST['customer_approved']) ? 1 : 0, nn(input('approved_by_name')), nn(input('approved_date')), $id]);
        log_activity('Projects', 'approval', $id, 'Updated customer approval for project #' . $id);
        flash('Customer approval saved.');
        redirect($back);
    }

    if ($action === 'photo') {
        $file = $_FILES['photo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            flash('Choose a photo to upload (max 5 MB).', 'danger');
            redirect($back);
        }
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!isset($allowed[$mime]) || $file['size'] > 5 * 1024 * 1024) {
            flash('Only JPG, PNG, WEBP or GIF images up to 5 MB can be uploaded.', 'danger');
            redirect($back);
        }
        $dir = __DIR__ . '/uploads/projects';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        $name = 'p' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            flash('The file could not be saved. Check that the uploads folder is writable.', 'danger');
            redirect($back);
        }
        q('INSERT INTO project_photos (project_id, photo_type, file_path, caption) VALUES (?,?,?,?)',
          [$id, (string)input('photo_type', 'Before'), 'uploads/projects/' . $name, nn(input('caption'))]);
        flash('Photo uploaded.');
        redirect($back);
    }

    if ($action === 'delete_photo') {
        $photo = one('SELECT * FROM project_photos WHERE id = ? AND project_id = ?', [(int)post_int('photo_id'), $id]);
        if ($photo) {
            @unlink(__DIR__ . '/' . $photo['file_path']);
            q('DELETE FROM project_photos WHERE id = ?', [$photo['id']]);
            flash('Photo removed.');
        }
        redirect($back);
    }
}

/* ------------------------------ Data ------------------------------- */
$p = one('SELECT p.*, c.full_name AS client_name, c.client_code, c.contact_number, c.email, c.address, c.client_type,
                 u.full_name AS created_by_name
          FROM cctv_projects p JOIN clients c ON c.id = p.client_id
          LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id]);
if (!$p) {
    flash('That project no longer exists.', 'danger');
    redirect(base_url('projects.php'));
}

$equipment   = all('SELECT pe.*, pr.sku, pr.stock_qty FROM project_equipment pe
                    LEFT JOIN products pr ON pr.id = pe.product_id WHERE pe.project_id = ? ORDER BY pe.id', [$id]);
$assignments = all('SELECT pa.*, e.full_name, e.position, e.contact_number FROM project_assignments pa
                    JOIN employees e ON e.id = pa.employee_id WHERE pa.project_id = ? ORDER BY pa.assigned_at', [$id]);
$updates     = all('SELECT pu.*, u.full_name FROM project_updates pu LEFT JOIN users u ON u.id = pu.user_id
                    WHERE pu.project_id = ? ORDER BY pu.created_at DESC, pu.id DESC', [$id]);
$photos      = all('SELECT * FROM project_photos WHERE project_id = ? ORDER BY photo_type, id', [$id]);
$invoices    = all('SELECT * FROM sales WHERE reference_type = "Project" AND reference_id = ?', [$id]);
$quotes      = all('SELECT * FROM quotations WHERE project_id = ?', [$id]);
$appointments= all('SELECT a.*, e.full_name AS tech FROM appointments a LEFT JOIN employees e ON e.id = a.employee_id
                    WHERE a.project_id = ? ORDER BY a.start_datetime', [$id]);
$expenses    = all('SELECT * FROM expenses WHERE project_id = ? ORDER BY expense_date', [$id]);

$products  = all('SELECT id, name, sku, selling_price, stock_qty FROM products WHERE status = "active" ORDER BY name');
$techs     = all('SELECT id, full_name, position FROM employees WHERE status = "active" ORDER BY is_technician DESC, full_name');
$pendingStock = count(array_filter($equipment, fn($l) => !$l['stock_deducted'] && $l['product_id']));
$projectExpense = array_sum(array_map(fn($x) => (float)$x['amount'], $expenses));

$pageTitle = $p['project_code'] . ' · ' . $p['title'];
$pageSub   = $p['client_name'] . ' · ' . ($p['location'] ?: 'no location set');
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('projects.php')) . '">All projects</a>';
if (can('sales.manage') && !$invoices) {
    $pageActions .= ' <a class="btn" href="' . e(base_url('sales.php?from_project=' . $id)) . '">Create invoice</a>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Status</div><div class="value sm"><?= e($p['status']) ?></div>
    <div class="foot"><?= (int)$p['progress'] ?>% complete</div></div>
  <div class="stat is-copper"><div class="label">System size</div><div class="value sm"><?= (int)$p['camera_count'] ?> cameras</div>
    <div class="foot"><?= e($p['recorder_type']) ?> · <?= e($p['storage_capacity'] ?: 'storage not set') ?></div></div>
  <div class="stat"><div class="label">Contract value</div><div class="value sm"><?= money($p['total_cost']) ?></div>
    <div class="foot">fee <?= money($p['installation_fee'], false) ?> + equipment <?= money($p['equipment_cost'], false) ?></div></div>
  <div class="stat <?= $p['customer_approved'] ? 'is-ok' : 'is-warn' ?>"><div class="label">Customer approval</div>
    <div class="value sm"><?= $p['customer_approved'] ? 'Approved' : 'Not yet' ?></div>
    <div class="foot"><?= $p['approved_by_name'] ? e($p['approved_by_name']) . ' · ' . fdate($p['approved_date']) : 'waiting for sign-off' ?></div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Equipment and materials</h2>
        <?php if (can('projects.manage')): ?>
          <div class="spacer btn-group">
            <?php if ($pendingStock): ?>
              <form method="post" data-confirm="Take <?= $pendingStock ?> item line(s) out of inventory for this project?">
                <?= csrf_field() ?><input type="hidden" name="action" value="deduct_stock">
                <button class="btn btn-sm btn-navy" type="submit">Issue <?= $pendingStock ?> line(s) from stock</button>
              </form>
            <?php endif; ?>
            <button class="btn btn-sm" data-modal="equipModal">Add item</button>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-body tight">
        <?php if (!$equipment): ?>
          <div class="empty"><strong>No equipment listed</strong>Add the cameras, recorder, drives and cabling this job needs.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Line total</th><th>Stock</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($equipment as $l): ?>
            <tr>
              <td><span class="row-main"><?= e($l['description']) ?></span>
                  <?php if ($l['sku']): ?><div class="row-sub"><?= e($l['sku']) ?> · <?= (int)$l['stock_qty'] ?> in stock</div><?php endif; ?></td>
              <td class="num"><?= (int)$l['quantity'] ?></td>
              <td class="num"><?= money($l['unit_cost']) ?></td>
              <td class="num"><?= money($l['line_total']) ?></td>
              <td><?= $l['stock_deducted'] ? '<span class="badge badge-ok">issued</span>'
                     : ($l['product_id'] ? '<span class="badge badge-warn">in stock</span>' : '<span class="pill">custom</span>') ?></td>
              <td class="actions-cell">
                <?php if (can('projects.manage') && !$l['stock_deducted']): ?>
                  <form method="post" data-confirm="Remove this line?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="remove_equipment">
                    <input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
                    <button class="linkbtn" type="submit">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <th colspan="3" class="num">Equipment total</th>
            <th class="num"><?= money($p['equipment_cost']) ?></th><th colspan="2"></th>
          </tr></tfoot>
        </table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Installation progress</h2>
        <span class="spacer small muted"><?= count($updates) ?> update<?= count($updates) === 1 ? '' : 's' ?></span>
      </div>
      <div class="card-body">
        <?php if (can('projects.manage')): ?>
        <form method="post" class="form-row" style="align-items:end;margin-bottom:18px">
          <?= csrf_field() ?><input type="hidden" name="action" value="progress">
          <div class="field" style="margin:0">
            <label>Progress (%)</label>
            <input class="input" name="progress" type="number" min="0" max="100" value="<?= (int)$p['progress'] ?>">
          </div>
          <div class="field" style="margin:0">
            <label>Status</label>
            <select class="input" name="status">
              <?php foreach (['Pending', 'Approved', 'Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
                <option <?= $p['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="grid-column:1/-1;margin:0">
            <label>What happened on site?</label>
            <input class="input" name="note" placeholder="Cameras 1–6 mounted and aimed; DVR configured.">
          </div>
          <div style="grid-column:1/-1"><button class="btn" type="submit">Post update</button></div>
        </form>
        <?php endif; ?>

        <ul class="timeline">
          <?php foreach ($updates as $u): ?>
            <li>
              <div><strong><?= (int)$u['progress'] ?>%</strong> · <?= e($u['status']) ?><?= $u['note'] ? ' — ' . e($u['note']) : '' ?></div>
              <div class="t-meta"><?= e($u['full_name'] ?: 'System') ?> · <?= fdatetime($u['created_at']) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$updates): ?><li class="muted">No progress notes posted yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Before and after photos</h2>
        <?php if (can('projects.manage')): ?><button class="spacer btn btn-sm" data-modal="photoModal">Upload photo</button><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$photos): ?>
          <div class="empty"><strong>No photos yet</strong>Site photos help settle questions about scope and workmanship later.</div>
        <?php else: ?>
          <?php foreach (['Before', 'After', 'Documentation'] as $group):
              $set = array_filter($photos, fn($ph) => $ph['photo_type'] === $group);
              if (!$set) { continue; } ?>
            <h3 style="margin-top:8px"><?= $group ?></h3>
            <div class="thumbs" style="margin-bottom:18px">
              <?php foreach ($set as $ph): ?>
                <figure>
                  <a href="<?= e(base_url($ph['file_path'])) ?>" target="_blank">
                    <img src="<?= e(base_url($ph['file_path'])) ?>" alt="<?= e($ph['caption'] ?: $group . ' photo') ?>">
                  </a>
                  <figcaption>
                    <?= e($ph['caption'] ?: fdate($ph['uploaded_at'])) ?>
                    <?php if (can('projects.manage')): ?>
                      <form method="post" data-confirm="Delete this photo?" style="display:inline">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
                        <button class="linkbtn" type="submit">delete</button>
                      </form>
                    <?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Technical documentation</h2></div>
      <div class="card-body">
        <dl class="dl">
          <dt>Client</dt><dd><a href="client_view.php?id=<?= (int)$p['client_id'] ?>"><?= e($p['client_name']) ?></a><br>
            <span class="small muted"><?= e($p['contact_number'] ?: '') ?></span></dd>
          <dt>Location</dt><dd><?= e($p['location'] ?: '—') ?></dd>
          <dt>Package</dt><dd><?= e($p['package_name'] ?: '—') ?></dd>
          <dt>Cameras</dt><dd><?= (int)$p['camera_count'] ?> — <?= e($p['camera_specs'] ?: 'specs not set') ?></dd>
          <dt>Recorder</dt><dd><?= e($p['recorder_type']) ?><?= $p['recorder_model'] ? ' — ' . e($p['recorder_model']) : '' ?></dd>
          <dt>Storage</dt><dd><?= e($p['storage_capacity'] ?: '—') ?></dd>
          <dt>Cabling</dt><dd><?= e($p['cable_details'] ?: '—') ?></dd>
          <dt>Install date</dt><dd><?= fdate($p['installation_date']) ?></dd>
          <dt>Deadline</dt><dd><?= fdate($p['deadline']) ?></dd>
          <dt>Completed</dt><dd><?= fdate($p['completed_at']) ?></dd>
          <dt>Created by</dt><dd><?= e($p['created_by_name'] ?: '—') ?> · <?= fdate($p['created_at']) ?></dd>
        </dl>
        <?php if ($p['notes']): ?>
          <p class="small" style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px"><?= nl2br(e($p['notes'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Assigned crew</h2></div>
      <div class="card-body tight">
        <table class="data"><tbody>
        <?php foreach ($assignments as $a): ?>
          <tr>
            <td><span class="row-main"><?= e($a['full_name']) ?></span>
                <div class="row-sub"><?= e($a['role_in_project']) ?><?= $a['contact_number'] ? ' · ' . e($a['contact_number']) : '' ?></div></td>
            <td class="actions-cell">
              <?php if (can('projects.manage')): ?>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="unassign">
                  <input type="hidden" name="employee_id" value="<?= (int)$a['employee_id'] ?>">
                  <button class="linkbtn" type="submit">remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?><tr><td class="muted">Nobody assigned yet.</td></tr><?php endif; ?>
        </tbody></table>
        <?php if (can('projects.manage')): ?>
        <form method="post" class="card-body" style="border-top:1px solid var(--line)">
          <?= csrf_field() ?><input type="hidden" name="action" value="assign">
          <div class="field"><label>Assign technician</label>
            <select class="input" name="employee_id" required>
              <option value="">Choose an employee…</option>
              <?php foreach ($techs as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?> — <?= e($t['position']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Role on this job</label>
            <input class="input" name="role_in_project" value="Technician"></div>
          <button class="btn btn-block" type="submit">Assign</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('projects.manage')): ?>
    <div class="card">
      <div class="card-head"><h2>Customer approval</h2></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="approval">
          <label class="check" style="margin-bottom:12px">
            <input type="checkbox" name="customer_approved" value="1" <?= $p['customer_approved'] ? 'checked' : '' ?>>
            Customer approved this scope and price
          </label>
          <div class="field"><label>Approved by</label>
            <input class="input" name="approved_by_name" value="<?= e($p['approved_by_name']) ?>"></div>
          <div class="field"><label>Approval date</label>
            <input class="input" name="approved_date" type="date" value="<?= e($p['approved_date']) ?>"></div>
          <button class="btn btn-block" type="submit">Save approval</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h2>Linked records</h2></div>
      <div class="card-body small">
        <p class="mb0"><strong>Quotations</strong></p>
        <?php if ($quotes): foreach ($quotes as $qt): ?>
          <div><a href="quotation_print.php?id=<?= (int)$qt['id'] ?>"><?= e($qt['quotation_no']) ?></a> · <?= money($qt['total_amount']) ?> · <?= badge($qt['status']) ?></div>
        <?php endforeach; else: ?><div class="muted">None</div><?php endif; ?>

        <p class="mb0" style="margin-top:12px"><strong>Invoices</strong></p>
        <?php if ($invoices): foreach ($invoices as $inv): ?>
          <div><a href="sale_view.php?id=<?= (int)$inv['id'] ?>"><?= e($inv['invoice_no']) ?></a> · <?= money($inv['total_amount']) ?> · <?= badge($inv['status']) ?></div>
        <?php endforeach; else: ?><div class="muted">None</div><?php endif; ?>

        <p class="mb0" style="margin-top:12px"><strong>Scheduled visits</strong></p>
        <?php if ($appointments): foreach ($appointments as $ap): ?>
          <div><?= fdatetime($ap['start_datetime']) ?> · <?= e($ap['tech'] ?: 'unassigned') ?></div>
        <?php endforeach; else: ?><div class="muted">None</div><?php endif; ?>

        <p class="mb0" style="margin-top:12px"><strong>Job expenses</strong> <?= money($projectExpense) ?></p>
        <?php foreach ($expenses as $x): ?>
          <div class="muted"><?= fdate($x['expense_date']) ?> · <?= e($x['description']) ?> · <?= money($x['amount']) ?></div>
        <?php endforeach; ?>
        <?php if ($p['total_cost'] > 0): ?>
          <p style="margin-top:10px">Gross margin after job expenses:
            <strong><?= money((float)$p['total_cost'] - $projectExpense) ?></strong></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (can('projects.manage')): ?>
<div class="modal-backdrop" id="equipModal">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_equipment">
      <div class="modal-head"><h3>Add equipment to this project</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="field"><label>Pick from inventory</label>
          <select class="input" name="product_id" id="eqProduct">
            <option value="">Custom item (not in inventory)</option>
            <?php foreach ($products as $pr): ?>
              <option value="<?= (int)$pr['id'] ?>" data-name="<?= e($pr['name']) ?>" data-price="<?= e($pr['selling_price']) ?>">
                <?= e($pr['name']) ?> — <?= money($pr['selling_price']) ?> (<?= (int)$pr['stock_qty'] ?> in stock)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label class="required">Description</label><input class="input" name="description" id="eqDesc" required></div>
        <div class="form-row">
          <div class="field"><label>Quantity</label><input class="input" name="quantity" id="eqQty" type="number" min="1" value="1"></div>
          <div class="field"><label>Unit cost charged</label><input class="input" name="unit_cost" id="eqCost" type="number" step="0.01" min="0" value="0"></div>
        </div>
        <p class="hint">Stock is only deducted when you press “Issue from stock”, so planning a job never moves inventory by accident.</p>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Add item</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="photoModal">
  <div class="modal narrow">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="photo">
      <div class="modal-head"><h3>Upload installation photo</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="field"><label>Photo type</label>
          <select class="input" name="photo_type"><option>Before</option><option>After</option><option>Documentation</option></select></div>
        <div class="field"><label>Image file</label><input class="input" type="file" name="photo" accept="image/*" required></div>
        <div class="field"><label>Caption</label><input class="input" name="caption" placeholder="Rear gate camera, mounted"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Upload</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('eqProduct').addEventListener('change', function () {
  var opt = this.options[this.selectedIndex];
  if (opt.value) {
    document.getElementById('eqDesc').value = opt.getAttribute('data-name');
    document.getElementById('eqCost').value = opt.getAttribute('data-price');
  }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
