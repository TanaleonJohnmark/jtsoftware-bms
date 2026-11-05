<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('clients.view');

/* ----------------------------- Actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('clients.manage');
    $action = input('action');

    if ($action === 'save') {
        $id     = post_int('id');
        $fields = [
            'full_name'      => (string)input('full_name'),
            'business_name'  => nn(input('business_name')),
            'client_type'    => (string)input('client_type', 'Residential'),
            'contact_number' => nn(input('contact_number')),
            'email'          => nn(input('email')),
            'address'        => nn(input('address')),
            'notes'          => nn(input('notes')),
            'status'         => input('status') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($fields['full_name'] === '') {
            flash('A client name is required.', 'danger');
            redirect(url_with([]));
        }
        if ($fields['email'] && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'danger');
            redirect(url_with([]));
        }

        if ($id) {
            q('UPDATE clients SET full_name=?, business_name=?, client_type=?, contact_number=?, email=?, address=?, notes=?, status=? WHERE id=?',
              array_merge(array_values($fields), [$id]));
            log_activity('Clients', 'update', $id, 'Updated client ' . $fields['full_name']);
            flash('Client details updated.');
        } else {
            $code = next_code('clients', 'client_code', 'CL');
            q('INSERT INTO clients (client_code, full_name, business_name, client_type, contact_number, email, address, notes, status)
               VALUES (?,?,?,?,?,?,?,?,?)', array_merge([$code], array_values($fields)));
            $id = (int)db()->lastInsertId();
            log_activity('Clients', 'create', $id, 'Added client ' . $code . ' - ' . $fields['full_name']);
            flash('Client ' . $code . ' added.');
        }
        redirect(url_with([]));
    }

    if ($action === 'portal') {
        $id       = (int)post_int('id');
        $username = (string)input('portal_username');
        $password = (string)input('portal_password');
        $enabled  = isset($_POST['portal_enabled']) ? 1 : 0;

        if ($enabled && $username === '') {
            flash('Give the client a portal username first.', 'danger');
            redirect(url_with([]));
        }
        $taken = scalar('SELECT COUNT(*) FROM clients WHERE portal_username = ? AND id <> ?', [$username, $id]);
        if ($username !== '' && $taken) {
            flash('That portal username is already used by another client.', 'danger');
            redirect(url_with([]));
        }
        if ($password !== '') {
            if (strlen($password) < 6) {
                flash('The portal password must be at least 6 characters.', 'danger');
                redirect(url_with([]));
            }
            q('UPDATE clients SET portal_username=?, portal_password=?, portal_enabled=? WHERE id=?',
              [nn($username), password_hash($password, PASSWORD_DEFAULT), $enabled, $id]);
        } else {
            q('UPDATE clients SET portal_username=?, portal_enabled=? WHERE id=?', [nn($username), $enabled, $id]);
        }
        log_activity('Clients', 'portal', $id, 'Changed portal access for client #' . $id);
        flash('Portal access saved.');
        redirect(url_with([]));
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        $links = (int)scalar('SELECT
              (SELECT COUNT(*) FROM cctv_projects WHERE client_id = c.id)
            + (SELECT COUNT(*) FROM service_requests WHERE client_id = c.id)
            + (SELECT COUNT(*) FROM sales WHERE client_id = c.id)
            + (SELECT COUNT(*) FROM quotations WHERE client_id = c.id)
            FROM clients c WHERE c.id = ?', [$id]);
        if ($links > 0) {
            flash('This client has ' . $links . ' linked records. Set the client to inactive instead of deleting.', 'warn');
        } else {
            q('DELETE FROM clients WHERE id = ?', [$id]);
            log_activity('Clients', 'delete', $id, 'Deleted client #' . $id);
            flash('Client deleted.');
        }
        redirect(url_with([]));
    }
}

/* ------------------------------ Query ------------------------------ */
$search = (string)input('q', '');
$type   = (string)input('type', '');
$status = (string)input('status', '');
$page   = max(1, (int)input('page', 1));
$per    = 12;

$where = ['1=1'];
$args  = [];
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR business_name LIKE ? OR client_code LIKE ? OR contact_number LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($type !== '')   { $where[] = 'client_type = ?'; $args[] = $type; }
if ($status !== '') { $where[] = 'status = ?';      $args[] = $status; }
$whereSql = implode(' AND ', $where);

if (input('export') === 'csv') {
    $rows = all("SELECT client_code, full_name, business_name, client_type, contact_number, email, address, status
                 FROM clients WHERE $whereSql ORDER BY full_name", $args);
    export_csv('clients-' . date('Y-m-d') . '.csv',
        ['Code', 'Client name', 'Business', 'Type', 'Contact', 'Email', 'Address', 'Status'],
        array_map('array_values', $rows));
}

$total = (int)scalar("SELECT COUNT(*) FROM clients WHERE $whereSql", $args);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$clients = all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM cctv_projects p WHERE p.client_id = c.id) AS project_count,
            (SELECT COUNT(*) FROM service_requests s WHERE s.client_id = c.id) AS service_count,
            (SELECT COALESCE(SUM(balance),0) FROM sales s WHERE s.client_id = c.id AND s.status IN ('Unpaid','Partial')) AS balance
     FROM clients c WHERE $whereSql ORDER BY c.full_name ASC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);

$pageTitle = 'Clients';
$pageSub   = $total . ' client' . ($total === 1 ? '' : 's') . ' on record';
if (can('clients.manage')) {
    $pageActions = '<button class="btn" data-modal="clientModal">Add client</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field">
        <input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search name, business, contact…">
      </div>
      <div class="field">
        <select class="input" name="type">
          <option value="">All types</option>
          <?php foreach (['Residential', 'Commercial', 'Corporate'] as $t): ?>
            <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <select class="input" name="status">
          <option value="">Any status</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button class="btn btn-outline" type="submit">Search</button>
      <?php if ($search || $type || $status): ?>
        <a class="btn btn-outline" href="clients.php">Clear</a>
      <?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>

  <div class="card-body tight">
    <?php if (!$clients): ?>
      <div class="empty">
        <strong>No clients match this search</strong>
        Clear the filters, or add the client with the button at the top right.
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Client</th><th>Type</th><th>Contact</th><th>Address</th>
            <th class="num">Jobs</th><th class="num">Balance</th><th>Portal</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td>
              <a class="row-main" href="client_view.php?id=<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?></a>
              <div class="row-sub"><?= e($c['client_code']) ?><?= $c['business_name'] ? ' · ' . e($c['business_name']) : '' ?></div>
            </td>
            <td><?= e($c['client_type']) ?><?= $c['status'] === 'inactive' ? ' <span class="badge badge-muted">inactive</span>' : '' ?></td>
            <td>
              <?= e($c['contact_number'] ?: '—') ?>
              <?php if ($c['email']): ?><div class="row-sub"><?= e($c['email']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($c['address'] ?: '—') ?></td>
            <td class="num"><?= (int)$c['project_count'] ?> / <?= (int)$c['service_count'] ?></td>
            <td class="num"><?= $c['balance'] > 0 ? '<span class="badge badge-danger">' . money($c['balance']) . '</span>' : '<span class="muted">—</span>' ?></td>
            <td><?= $c['portal_enabled'] ? '<span class="badge badge-ok">on</span>' : '<span class="muted small">off</span>' ?></td>
            <td class="actions-cell">
              <a class="btn btn-sm btn-outline" href="client_view.php?id=<?= (int)$c['id'] ?>">Open</a>
              <?php if (can('clients.manage')): ?>
                <button class="btn btn-sm btn-outline" data-modal="clientModal"
                        data-row='<?= e(json_encode([
                            'id' => $c['id'], 'full_name' => $c['full_name'], 'business_name' => $c['business_name'],
                            'client_type' => $c['client_type'], 'contact_number' => $c['contact_number'],
                            'email' => $c['email'], 'address' => $c['address'], 'notes' => $c['notes'],
                            'status' => $c['status'],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                <button class="btn btn-sm btn-outline" data-modal="portalModal"
                        data-row='<?= e(json_encode([
                            'id' => $c['id'], 'portal_username' => $c['portal_username'],
                            'portal_enabled' => $c['portal_enabled'], 'client_label' => $c['full_name'],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Portal</button>
                <form method="post" style="display:inline" data-confirm="Delete <?= e($c['full_name']) ?>? This cannot be undone.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
          <?php else: ?><a href="<?= e(url_with(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <span class="info">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> records</span>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (can('clients.manage')): ?>
<!-- Add / edit client -->
<div class="modal-backdrop" id="clientModal" data-title-new="Add client" data-title-edit="Edit client">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>Add client</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="field"><label class="required">Client full name</label><input class="input" name="full_name" required></div>
          <div class="field"><label>Business or property name</label><input class="input" name="business_name"></div>
        </div>
        <div class="form-row three">
          <div class="field">
            <label>Client type</label>
            <select class="input" name="client_type">
              <option>Residential</option><option>Commercial</option><option>Corporate</option>
            </select>
          </div>
          <div class="field"><label>Contact number</label><input class="input" name="contact_number" placeholder="0917-000-0000"></div>
          <div class="field"><label>Email address</label><input class="input" name="email" type="email"></div>
        </div>
        <div class="field"><label>Complete address</label><input class="input" name="address"></div>
        <div class="form-row">
          <div class="field"><label>Notes</label><textarea class="input" name="notes" placeholder="Access instructions, preferred schedule…"></textarea></div>
          <div class="field">
            <label>Status</label>
            <select class="input" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            <p class="hint">Inactive clients stay in reports and history but are hidden from new job forms.</p>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save client</button>
      </div>
    </form>
  </div>
</div>

<!-- Portal access -->
<div class="modal-backdrop" id="portalModal">
  <div class="modal narrow">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="portal">
      <input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>Client portal access</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <p class="small muted">The client signs in at <code>portal/login.php</code> and sees only their own
           projects, requests, quotations and invoices.</p>
        <div class="field"><label>Portal username</label><input class="input" name="portal_username"></div>
        <div class="field">
          <label>New password</label>
          <input class="input" name="portal_password" type="text" placeholder="Leave blank to keep the current one">
          <p class="hint">At least 6 characters. Give it to the client by message or in person.</p>
        </div>
        <label class="check"><input type="checkbox" name="portal_enabled" value="1"> Allow this client to sign in</label>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save access</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
