<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('quotations.view');

$id = (int)input('id', 0);
$qt = one('SELECT qt.*, c.full_name, c.business_name, c.address, c.contact_number, c.email, c.client_code,
                  u.full_name AS prepared_by
           FROM quotations qt JOIN clients c ON c.id = qt.client_id
           LEFT JOIN users u ON u.id = qt.created_by WHERE qt.id = ?', [$id]);
if (!$qt) {
    flash('That quotation no longer exists.', 'danger');
    redirect(base_url('quotations.php'));
}
$items = all('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id', [$id]);

$pageTitle   = $qt['quotation_no'];
$pageSub     = 'Quotation for ' . $qt['full_name'];
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('quotations.php')) . '">All quotations</a>';
include __DIR__ . '/includes/header.php';
?>

<div class="print-bar no-print">
  <button class="btn" onclick="window.print()">Print or save as PDF</button>
  <?php if (can('quotations.manage')): ?>
    <a class="btn btn-outline" href="quotation_form.php?id=<?= (int)$qt['id'] ?>">Edit quotation</a>
  <?php endif; ?>
  <span class="flex muted small" style="margin-left:auto">Status: <?= badge($qt['status']) ?></span>
</div>

<div class="doc">
  <div class="doc-head">
    <div>
      <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="<?= e(setting('company_name')) ?>">
      <div class="small muted" style="margin-top:10px">
        <?= e(setting('company_address')) ?><br>
        <?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1>Quotation</h1>
      <div><strong><?= e($qt['quotation_no']) ?></strong></div>
      <div>Date: <?= fdate($qt['quote_date']) ?></div>
      <div>Valid until: <?= fdate($qt['valid_until']) ?></div>
    </div>
  </div>

  <div class="doc-parties">
    <div>
      <div class="lbl">Prepared for</div>
      <strong><?= e($qt['full_name']) ?></strong><br>
      <?= $qt['business_name'] ? e($qt['business_name']) . '<br>' : '' ?>
      <?= e($qt['address'] ?: '') ?><br>
      <?= e($qt['contact_number'] ?: '') ?><?= $qt['email'] ? ' · ' . e($qt['email']) : '' ?>
    </div>
    <div>
      <div class="lbl">Subject</div>
      <strong><?= e($qt['subject']) ?></strong><br>
      <span class="muted">Client code <?= e($qt['client_code']) ?></span>
    </div>
  </div>

  <table class="doc-table">
    <thead><tr><th style="width:34px">#</th><th>Description</th><th class="num">Qty</th>
      <th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($it['description']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
          <td class="num"><?= money($it['unit_price'], false) ?></td>
          <td class="num"><?= money($it['line_total'], false) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="doc-totals">
    <div><span>Items subtotal</span><span><?= money($qt['items_total']) ?></span></div>
    <?php if ((float)$qt['labor_charge'] > 0): ?>
      <div><span>Labour and installation</span><span><?= money($qt['labor_charge']) ?></span></div><?php endif; ?>
    <?php if ((float)$qt['discount'] > 0): ?>
      <div><span>Discount</span><span>− <?= money($qt['discount']) ?></span></div><?php endif; ?>
    <?php if ((float)$qt['tax'] > 0): ?>
      <div><span>Tax</span><span><?= money($qt['tax']) ?></span></div><?php endif; ?>
    <div class="grand"><span>Total</span><span><?= money($qt['total_amount']) ?></span></div>
  </div>

  <?php if ($qt['terms']): ?>
    <div class="doc-terms"><strong>Terms and conditions</strong><br><?= e($qt['terms']) ?></div>
  <?php endif; ?>

  <div class="doc-sign">
    <div>Prepared by<br><strong><?= e($qt['prepared_by'] ?: setting('company_name')) ?></strong><br>
      <span class="muted"><?= e(setting('company_division')) ?></span></div>
    <div>Conforme — client signature over printed name<br><br><?= e($qt['full_name']) ?></div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
