<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('inventory.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('inventory.manage');
    $action = input('action');
    if ($action === 'save') {
        $id   = post_int('id');
        $name = (string)input('name');
        if ($name === '') {
            flash('Give the category a name.', 'danger');
            redirect('categories.php');
        }
        if ($id) {
            q('UPDATE categories SET name = ?, description = ? WHERE id = ?', [$name, nn(input('description')), $id]);
            flash('Category updated.');
        } else {
            if (scalar('SELECT COUNT(*) FROM categories WHERE name = ?', [$name])) {
                flash('That category already exists.', 'warn');
                redirect('categories.php');
            }
            q('INSERT INTO categories (name, description) VALUES (?,?)', [$name, nn(input('description'))]);
            log_activity('Inventory', 'create', (int)db()->lastInsertId(), 'Added category ' . $name);
            flash('Category added.');
        }
        redirect('categories.php');
    }
    if ($action === 'delete') {
        $id = (int)post_int('id');
        if (scalar('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id])) {
            flash('Products still use this category. Move them first.', 'warn');
        } else {
            q('DELETE FROM categories WHERE id = ?', [$id]);
            flash('Category deleted.');
        }
        redirect('categories.php');
    }
}

$rows = all('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
                    (SELECT COALESCE(SUM(p.stock_qty * p.purchase_price),0) FROM products p WHERE p.category_id = c.id) AS value
             FROM categories c ORDER BY c.name');

$pageTitle = 'Product categories';
$pageSub   = count($rows) . ' categories';
if (can('inventory.manage')) { $pageActions = '<button class="btn" data-modal="catModal">Add category</button>'; }
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <a class="tab" href="products.php">Products</a>
  <a class="tab active" href="categories.php">Categories</a>
  <a class="tab" href="suppliers.php">Suppliers</a>
  <a class="tab" href="stock.php">Stock movements</a>
</div>

<div class="card"><div class="card-body tight">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No categories yet</strong>Group products so stock reports read clearly.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>Category</th><th>Description</th><th class="num">Products</th><th class="num">Stock value</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="row-main"><?= e($r['name']) ?></td>
        <td class="small muted"><?= e($r['description'] ?: '—') ?></td>
        <td class="num"><?= (int)$r['product_count'] ?></td>
        <td class="num"><?= money($r['value']) ?></td>
        <td class="actions-cell">
          <?php if (can('inventory.manage')): ?>
            <button class="btn btn-sm btn-outline" data-modal="catModal"
              data-row='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'description' => $r['description']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
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
<div class="modal-backdrop" id="catModal" data-title-new="Add category" data-title-edit="Edit category">
  <div class="modal narrow"><form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" data-clear>
    <div class="modal-head"><h3>Add category</h3><button type="button" class="modal-close" data-close>&times;</button></div>
    <div class="modal-body">
      <div class="field"><label class="required">Name</label><input class="input" name="name" required></div>
      <div class="field"><label>Description</label><input class="input" name="description"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button>
      <button class="btn" type="submit">Save category</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
