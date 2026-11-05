<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('inventory.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('inventory.manage');
    $action = input('action');
    if ($action === 'save') {
        $id   = post_int('id');
        $data = [(string)input('name'), nn(input('contact_person')), nn(input('contact_number')),
                 nn(input('email')), nn(input('address')), input('status') === 'inactive' ? 'inactive' : 'active'];
        if ($data[0] === '') {
            flash('Give the supplier a name.', 'danger');
            redirect('suppliers.php');
        }
        if ($id) {
            q('UPDATE suppliers SET name=?, contact_person=?, contact_number=?, email=?, address=?, status=? WHERE id=?',
              array_merge($data, [$id]));
            flash('Supplier updated.');
        } else {
            q('INSERT INTO suppliers (name, contact_person, contact_number, email, address, status) VALUES (?,?,?,?,?,?)', $data);
            log_activity('Inventory', 'create', (int)db()->lastInsertId(), 'Added supplier ' . $data[0]);
            flash('Supplier added.');
        }
        redirect('suppliers.php');
    }
    if ($action === 'delete') {
        $id = (int)post_int('id');
        if (scalar('SELECT COUNT(*) FROM products WHERE supplier_id = ?', [$id])) {
            q('UPDATE suppliers SET status = "inactive" WHERE id = ?', [$id]);
            flash('Products are linked to this supplier, so it was set to inactive instead.', 'warn');
        } else {
            q('DELETE FROM suppliers WHERE id = ?', [$id]);
            flash('Supplier deleted.');
        }
        redirect('suppliers.php');
    }
}

$rows = all('SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id) AS product_count
             FROM suppliers s ORDER BY s.name');

$pageTitle = 'Suppliers';
$pageSub   = count($rows) . ' suppliers';
if (can('inventory.manage')) { $pageActions = '<button class="btn" data-modal="supModal">Add supplier</button>'; }
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <a class="tab" href="products.php">Products</a>
  <a class="tab" href="categories.php">Categories</a>
  <a class="tab active" href="suppliers.php">Suppliers</a>
  <a class="tab" href="stock.php">Stock movements</a>
</div>

<div class="card"><div class="card-body tight">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No suppliers yet</strong>Keep your equipment sources here for reordering.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>Supplier</th><th>Contact person</th><th>Contact</th><th>Address</th><th class="num">Products</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="row-main"><?= e($r['name']) ?></td>
        <td><?= e($r['contact_person'] ?: '—') ?></td>
        <td class="small"><?= e($r['contact_number'] ?: '—') ?><?php if ($r['email']): ?><div class="row-sub"><?= e($r['email']) ?></div><?php endif; ?></td>
        <td class="small muted"><?= e($r['address'] ?: '—') ?></td>
        <td class="num"><?= (int)$r['product_count'] ?></td>
        <td><?= badge($r['status']) ?></td>
        <td class="actions-cell">
          <?php if (can('inventory.manage')): ?>
            <button class="btn btn-sm btn-outline" data-modal="supModal"
              data-row='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'contact_person' => $r['contact_person'],
                'contact_number' => $r['contact_number'], 'email' => $r['email'], 'address' => $r['address'],
                'status' => $r['status']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
            <form method="post" style="display:inline" data-confirm="Delete <?= e($r['name']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div></div>

<?php if (can('inventory.manage')): ?>
<div class="modal-backdrop" id="supModal" data-title-new="Add supplier" data-title-edit="Edit supplier">
  <div class="modal"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Add supplier</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="field"><label class="required">Supplier name</label><input class="input" name="name" required></div>
        <div class="field"><label>Contact person</label><input class="input" name="contact_person"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>Contact number</label><input class="input" name="contact_number"></div>
        <div class="field"><label>Email</label><input class="input" name="email" type="email"></div>
      </div>
      <div class="field"><label>Address</label><input class="input" name="address"></div>
      <div class="field"><label>Status</label>
        <select class="input" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save supplier</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
