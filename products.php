<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('inventory.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    guard('inventory.manage');
    $action = input('action');

    if ($action === 'save') {
        $id = post_int('id');
        $data = [
            (string)input('sku'),
            nn(input('barcode')),
            (string)input('name'),
            post_int('category_id'),
            post_int('supplier_id'),
            (string)input('unit', 'pc'),
            post_num('purchase_price'),
            post_num('selling_price'),
            (int)post_int('reorder_level', 5),
            nn(input('description')),
            input('status') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($data[0] === '' || $data[2] === '') {
            flash('An SKU and a product name are required.', 'danger');
            redirect(url_with([]));
        }
        $dupe = scalar('SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?', [$data[0], (int)$id]);
        if ($dupe) {
            flash('That SKU is already used by another product.', 'danger');
            redirect(url_with([]));
        }

        if ($id) {
            q('UPDATE products SET sku=?, barcode=?, name=?, category_id=?, supplier_id=?, unit=?, purchase_price=?,
                 selling_price=?, reorder_level=?, description=?, status=? WHERE id=?', array_merge($data, [$id]));
            log_activity('Inventory', 'update', (int)$id, 'Updated product ' . $data[2]);
            flash('Product updated.');
        } else {
            $opening = max(0, (int)post_int('stock_qty', 0));
            q('INSERT INTO products (sku, barcode, name, category_id, supplier_id, unit, purchase_price, selling_price,
                 reorder_level, description, status, stock_qty) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
              array_merge($data, [$opening]));
            $newId = (int)db()->lastInsertId();
            if ($opening > 0) {
                q('INSERT INTO inventory_transactions (product_id, trans_type, quantity, unit_cost, balance_after, reference_type, remarks, user_id)
                   VALUES (?,"in",?,?,?,"Opening","Opening stock balance",?)',
                  [$newId, $opening, $data[6], $opening, current_user()['id']]);
            }
            log_activity('Inventory', 'create', $newId, 'Added product ' . $data[2]);
            flash('Product added.');
        }
        redirect(url_with([]));
    }

    if ($action === 'stock') {
        $productId = (int)post_int('product_id');
        $type      = (string)input('trans_type', 'in');
        $qty       = (int)post_int('quantity', 0);
        $cost      = post_num('unit_cost');
        $product   = one('SELECT * FROM products WHERE id = ?', [$productId]);

        if (!$product || $qty <= 0) {
            flash('Enter a quantity greater than zero.', 'danger');
            redirect(url_with([]));
        }
        if ($type === 'out' && $qty > (int)$product['stock_qty']) {
            flash('Only ' . (int)$product['stock_qty'] . ' ' . $product['unit'] . ' of ' . $product['name'] . ' are on hand.', 'danger');
            redirect(url_with([]));
        }

        db()->beginTransaction();
        try {
            if ($type === 'in') {
                q('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?', [$qty, $productId]);
            } elseif ($type === 'out') {
                q('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?', [$qty, $productId]);
            } else { // adjust: set the counted quantity
                q('UPDATE products SET stock_qty = ? WHERE id = ?', [$qty, $productId]);
            }
            $balance = (int)scalar('SELECT stock_qty FROM products WHERE id = ?', [$productId]);
            q('INSERT INTO inventory_transactions (product_id, trans_type, quantity, unit_cost, balance_after, reference_type, remarks, user_id)
               VALUES (?,?,?,?,?,?,?,?)',
              [$productId, $type, $qty, $cost, $balance, 'Manual', nn(input('remarks')), current_user()['id']]);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Stock was not changed: ' . $ex->getMessage(), 'danger');
            redirect(url_with([]));
        }
        $word = $type === 'in' ? 'received' : ($type === 'out' ? 'released' : 'adjusted to');
        log_activity('Inventory', $type, $productId, $product['name'] . ' ' . $word . ' ' . $qty);
        flash($product['name'] . ' ' . $word . ' ' . $qty . ' ' . $product['unit'] . '. New balance: ' . $balance . '.');
        redirect(url_with([]));
    }

    if ($action === 'delete') {
        $id = (int)post_int('id');
        $used = (int)scalar('SELECT (SELECT COUNT(*) FROM project_equipment WHERE product_id = ?)
                                  + (SELECT COUNT(*) FROM sale_items WHERE product_id = ?)
                                  + (SELECT COUNT(*) FROM quotation_items WHERE product_id = ?)', [$id, $id, $id]);
        if ($used) {
            q('UPDATE products SET status = "inactive" WHERE id = ?', [$id]);
            flash('This product appears in ' . $used . ' document(s), so it was set to inactive instead of deleted.', 'warn');
        } else {
            q('DELETE FROM products WHERE id = ?', [$id]);
            log_activity('Inventory', 'delete', $id, 'Deleted product #' . $id);
            flash('Product deleted.');
        }
        redirect(url_with([]));
    }
}

/* ------------------------------ Query ------------------------------ */
$search   = (string)input('q', '');
$category = (int)input('category', 0);
$filter   = (string)input('filter', '');

$where = ['1=1'];
$args  = [];
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($category) { $where[] = 'p.category_id = ?'; $args[] = $category; }
if ($filter === 'low')  { $where[] = 'p.stock_qty <= p.reorder_level'; }
if ($filter === 'out')  { $where[] = 'p.stock_qty <= 0'; }
$whereSql = implode(' AND ', $where);

$products = all(
    "SELECT p.*, c.name AS category_name, s.name AS supplier_name
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     WHERE $whereSql ORDER BY p.name", $args
);

if (input('export') === 'csv') {
    export_csv('inventory-' . date('Y-m-d') . '.csv',
        ['SKU', 'Product', 'Category', 'Supplier', 'Unit', 'Purchase price', 'Selling price', 'Stock', 'Reorder level', 'Status'],
        array_map(fn($p) => [$p['sku'], $p['name'], $p['category_name'], $p['supplier_name'], $p['unit'],
                             $p['purchase_price'], $p['selling_price'], $p['stock_qty'], $p['reorder_level'], $p['status']], $products));
}

$categories = all('SELECT id, name FROM categories ORDER BY name');
$suppliers  = all('SELECT id, name FROM suppliers WHERE status = "active" ORDER BY name');
$stockValue = (float)scalar('SELECT COALESCE(SUM(stock_qty * purchase_price),0) FROM products WHERE status = "active"');
$retailValue= (float)scalar('SELECT COALESCE(SUM(stock_qty * selling_price),0) FROM products WHERE status = "active"');
$lowCount   = (int)scalar('SELECT COUNT(*) FROM products WHERE status = "active" AND stock_qty <= reorder_level');
$outCount   = (int)scalar('SELECT COUNT(*) FROM products WHERE status = "active" AND stock_qty <= 0');

$pageTitle = 'Inventory and equipment';
$pageSub   = count($products) . ' product' . (count($products) === 1 ? '' : 's') . ' shown';
if (can('inventory.manage')) {
    $pageActions = '<button class="btn btn-outline" data-modal="stockModal">Stock in / out</button>'
                 . ' <button class="btn" data-modal="prodModal">Add product</button>';
}
include __DIR__ . '/includes/header.php';
?>

<div class="tabs">
  <a class="tab active" href="products.php">Products</a>
  <a class="tab" href="categories.php">Categories</a>
  <a class="tab" href="suppliers.php">Suppliers</a>
  <a class="tab" href="stock.php">Stock movements</a>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Stock value at cost</div><div class="value sm"><?= money($stockValue) ?></div>
    <div class="foot">retail <?= money($retailValue) ?></div></div>
  <div class="stat is-copper"><div class="label">Products tracked</div><div class="value"><?= count($products) ?></div>
    <div class="foot"><?= count($categories) ?> categories · <?= count($suppliers) ?> suppliers</div></div>
  <div class="stat is-warn"><div class="label">At or below reorder level</div><div class="value"><?= $lowCount ?></div>
    <div class="foot"><a href="<?= e(url_with(['filter' => 'low', 'q' => '', 'category' => ''])) ?>">show these</a></div></div>
  <div class="stat is-danger"><div class="label">Out of stock</div><div class="value"><?= $outCount ?></div>
    <div class="foot"><a href="<?= e(url_with(['filter' => 'out', 'q' => '', 'category' => ''])) ?>">show these</a></div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><input class="input search-input" name="q" value="<?= e($search) ?>" placeholder="Search name, SKU, barcode…"></div>
      <div class="field"><select class="input" name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
      <div class="field"><select class="input" name="filter">
        <option value="">All stock levels</option>
        <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock</option>
        <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>Out of stock</option>
      </select></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <?php if ($search || $category || $filter): ?><a class="btn btn-outline" href="products.php">Clear</a><?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$products): ?>
      <div class="empty"><strong>No products match</strong>Add cameras, recorders, drives, cable and accessories here.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Product</th><th>Category</th><th>Supplier</th><th class="num">Cost</th><th class="num">Price</th>
        <th class="num">On hand</th><th class="num">Value</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $low = (int)$p['stock_qty'] <= (int)$p['reorder_level']; ?>
        <tr>
          <td><span class="row-main"><?= e($p['name']) ?></span>
              <div class="row-sub"><?= e($p['sku']) ?><?= $p['barcode'] ? ' · ' . e($p['barcode']) : '' ?>
                <?= $p['status'] === 'inactive' ? ' · <span class="badge badge-muted">inactive</span>' : '' ?></div></td>
          <td class="small"><?= e($p['category_name'] ?: '—') ?></td>
          <td class="small"><?= e($p['supplier_name'] ?: '—') ?></td>
          <td class="num"><?= money($p['purchase_price'], false) ?></td>
          <td class="num"><?= money($p['selling_price'], false) ?></td>
          <td class="num">
            <span class="badge badge-<?= (int)$p['stock_qty'] <= 0 ? 'danger' : ($low ? 'warn' : 'ok') ?>">
              <?= (int)$p['stock_qty'] ?> <?= e($p['unit']) ?></span>
            <div class="row-sub">reorder at <?= (int)$p['reorder_level'] ?></div>
          </td>
          <td class="num"><?= money((float)$p['stock_qty'] * (float)$p['purchase_price'], false) ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-outline" href="stock.php?product=<?= (int)$p['id'] ?>">History</a>
            <?php if (can('inventory.manage')): ?>
              <button class="btn btn-sm btn-outline" data-modal="stockModal"
                data-row='<?= e(json_encode(['product_id' => $p['id']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Stock</button>
              <button class="btn btn-sm btn-outline" data-modal="prodModal"
                data-row='<?= e(json_encode([
                  'id' => $p['id'], 'sku' => $p['sku'], 'barcode' => $p['barcode'], 'name' => $p['name'],
                  'category_id' => $p['category_id'], 'supplier_id' => $p['supplier_id'], 'unit' => $p['unit'],
                  'purchase_price' => $p['purchase_price'], 'selling_price' => $p['selling_price'],
                  'reorder_level' => $p['reorder_level'], 'description' => $p['description'], 'status' => $p['status'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
              <form method="post" style="display:inline" data-confirm="Delete <?= e($p['name']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('inventory.manage')): ?>
<div class="modal-backdrop" id="prodModal" data-title-new="Add product" data-title-edit="Edit product">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" data-clear>
      <div class="modal-head"><h3>Add product</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="field"><label class="required">SKU</label><input class="input" name="sku" required placeholder="CAM-DOME-2MP"></div>
          <div class="field"><label>Barcode</label><input class="input" name="barcode"></div>
        </div>
        <div class="field"><label class="required">Product name</label><input class="input" name="name" required></div>
        <div class="form-row three">
          <div class="field"><label>Category</label>
            <select class="input" name="category_id"><option value="">Uncategorised</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Supplier</label>
            <select class="input" name="supplier_id"><option value="">None</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Unit</label><input class="input" name="unit" value="pc"></div>
        </div>
        <div class="form-row four">
          <div class="field"><label>Purchase price</label><input class="input" name="purchase_price" type="number" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Selling price</label><input class="input" name="selling_price" type="number" step="0.01" min="0" value="0"></div>
          <div class="field"><label>Opening stock</label><input class="input" name="stock_qty" type="number" min="0" value="0">
            <p class="hint">New products only.</p></div>
          <div class="field"><label>Reorder level</label><input class="input" name="reorder_level" type="number" min="0" value="5"></div>
        </div>
        <div class="field"><label>Description</label><textarea class="input" name="description"></textarea></div>
        <div class="field"><label>Status</label>
          <select class="input" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save product</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="stockModal">
  <div class="modal narrow">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="stock">
      <div class="modal-head"><h3>Record stock movement</h3><button type="button" class="modal-close" data-close>&times;</button></div>
      <div class="modal-body">
        <div class="field"><label class="required">Product</label>
          <select class="input" name="product_id" required>
            <option value="">Select a product…</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> — <?= (int)$p['stock_qty'] ?> <?= e($p['unit']) ?> on hand</option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row">
          <div class="field"><label>Movement</label>
            <select class="input" name="trans_type">
              <option value="in">Stock in (received)</option>
              <option value="out">Stock out (released)</option>
              <option value="adjust">Adjustment (set counted quantity)</option>
            </select></div>
          <div class="field"><label class="required">Quantity</label><input class="input" name="quantity" type="number" min="1" value="1" required></div>
        </div>
        <div class="field"><label>Unit cost</label><input class="input" name="unit_cost" type="number" step="0.01" min="0" value="0"></div>
        <div class="field"><label>Remarks</label><input class="input" name="remarks" placeholder="Delivery from Cebu Security Supply"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save movement</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
