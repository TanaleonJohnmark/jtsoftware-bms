<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('inventory.view');

$productId = (int)input('product', 0);
$type      = (string)input('type', '');
$from      = (string)input('from', date('Y-m-01', strtotime('-2 months')));
$to        = (string)input('to', date('Y-m-d'));

$where = ['DATE(t.created_at) BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($productId) { $where[] = 't.product_id = ?'; $args[] = $productId; }
if ($type !== '') { $where[] = 't.trans_type = ?'; $args[] = $type; }
$whereSql = implode(' AND ', $where);

$rows = all("SELECT t.*, p.name AS product_name, p.sku, p.unit, u.full_name AS user_name
             FROM inventory_transactions t JOIN products p ON p.id = t.product_id
             LEFT JOIN users u ON u.id = t.user_id
             WHERE $whereSql ORDER BY t.created_at DESC, t.id DESC LIMIT 400", $args);

if (input('export') === 'csv') {
    export_csv('stock-movements-' . date('Y-m-d') . '.csv',
        ['Date', 'Product', 'SKU', 'Movement', 'Quantity', 'Unit cost', 'Balance after', 'Reference', 'Remarks', 'User'],
        array_map(fn($r) => [$r['created_at'], $r['product_name'], $r['sku'], $r['trans_type'], $r['quantity'],
                             $r['unit_cost'], $r['balance_after'], $r['reference_type'], $r['remarks'], $r['user_name']], $rows));
}

$products = all('SELECT id, name FROM products ORDER BY name');
$totalIn  = array_sum(array_map(fn($r) => $r['trans_type'] === 'in' ? (int)$r['quantity'] : 0, $rows));
$totalOut = array_sum(array_map(fn($r) => $r['trans_type'] === 'out' ? (int)$r['quantity'] : 0, $rows));

$pageTitle = 'Stock movements';
$pageSub   = count($rows) . ' entries between ' . fdate($from) . ' and ' . fdate($to);
include __DIR__ . '/includes/header.php';
?>
<div class="tabs">
  <a class="tab" href="products.php">Products</a>
  <a class="tab" href="categories.php">Categories</a>
  <a class="tab" href="suppliers.php">Suppliers</a>
  <a class="tab active" href="stock.php">Stock movements</a>
</div>

<div class="grid grid-3" style="margin-bottom:16px">
  <div class="stat is-ok"><div class="label">Units received</div><div class="value"><?= $totalIn ?></div><div class="foot">in this period</div></div>
  <div class="stat is-copper"><div class="label">Units released</div><div class="value"><?= $totalOut ?></div><div class="foot">to jobs and sales</div></div>
  <div class="stat is-blue"><div class="label">Entries</div><div class="value"><?= count($rows) ?></div><div class="foot">newest 400 shown</div></div>
</div>

<div class="card">
  <div class="card-head">
    <form class="toolbar" method="get" data-autosubmit>
      <div class="field"><label>Product</label>
        <select class="input" name="product"><option value="">All products</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $productId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Movement</label>
        <select class="input" name="type">
          <option value="">All</option>
          <option value="in" <?= $type === 'in' ? 'selected' : '' ?>>Stock in</option>
          <option value="out" <?= $type === 'out' ? 'selected' : '' ?>>Stock out</option>
          <option value="adjust" <?= $type === 'adjust' ? 'selected' : '' ?>>Adjustment</option>
        </select></div>
      <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(url_with(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </div>
  <div class="card-body tight">
    <?php if (!$rows): ?>
      <div class="empty"><strong>No movements in this period</strong>Widen the dates or clear the filters.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Date</th><th>Product</th><th>Movement</th><th class="num">Qty</th><th class="num">Balance after</th>
        <th>Reference</th><th>Remarks</th><th>Recorded by</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap small"><?= fdatetime($r['created_at']) ?></td>
          <td><span class="row-main"><?= e($r['product_name']) ?></span><div class="row-sub"><?= e($r['sku']) ?></div></td>
          <td><span class="badge badge-<?= $r['trans_type'] === 'in' ? 'ok' : ($r['trans_type'] === 'out' ? 'accent' : 'info') ?>">
            <?= $r['trans_type'] === 'in' ? 'stock in' : ($r['trans_type'] === 'out' ? 'stock out' : 'adjustment') ?></span></td>
          <td class="num"><?= $r['trans_type'] === 'out' ? '−' : '' ?><?= (int)$r['quantity'] ?> <?= e($r['unit']) ?></td>
          <td class="num"><?= (int)$r['balance_after'] ?></td>
          <td class="small"><?= e($r['reference_type'] ?: '—') ?><?= $r['reference_id'] ? ' #' . (int)$r['reference_id'] : '' ?></td>
          <td class="small muted"><?= e($r['remarks'] ?: '—') ?></td>
          <td class="small"><?= e($r['user_name'] ?: 'system') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
