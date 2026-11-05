<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$cid = (int)current_client()['id'];

$quotes = all('SELECT * FROM quotations WHERE client_id = ? AND status <> "Draft" ORDER BY quote_date DESC', [$cid]);
$openId = (int)input('id', 0);
$quote  = $openId ? one('SELECT * FROM quotations WHERE id = ? AND client_id = ? AND status <> "Draft"', [$openId, $cid]) : null;
$items  = $quote ? all('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id', [$quote['id']]) : [];

$pageTitle = 'Quotations';
$pageSub   = 'Prices we have given you, and what they cover';
include __DIR__ . '/header.php';
?>
<?php if ($quote): ?>
  <div class="print-bar" style="margin:0 0 16px">
    <a class="btn btn-outline" href="quotations.php">Back to list</a>
    <button class="btn" onclick="window.print()">Print or save as PDF</button>
  </div>
  <div class="doc">
    <div class="doc-head">
      <div><img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="">
        <div class="small muted" style="margin-top:10px"><?= e(setting('company_address')) ?><br>
          <?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?></div></div>
      <div class="doc-meta"><h1>Quotation</h1>
        <div><strong><?= e($quote['quotation_no']) ?></strong></div>
        <div>Date: <?= fdate($quote['quote_date']) ?></div>
        <div>Valid until: <?= fdate($quote['valid_until']) ?></div></div>
    </div>
    <div class="doc-parties">
      <div><div class="lbl">Prepared for</div><strong><?= e(current_client()['full_name']) ?></strong><br>
        <?= e(current_client()['address'] ?: '') ?></div>
      <div><div class="lbl">Subject</div><strong><?= e($quote['subject']) ?></strong><br>
        <span class="muted">Status: <?= e($quote['status']) ?></span></div>
    </div>
    <table class="doc-table">
      <thead><tr><th style="width:34px">#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
          <td class="num"><?= money($it['unit_price'], false) ?></td>
          <td class="num"><?= money($it['line_total'], false) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="doc-totals">
      <div><span>Items subtotal</span><span><?= money($quote['items_total']) ?></span></div>
      <?php if ((float)$quote['labor_charge'] > 0): ?><div><span>Labour and installation</span><span><?= money($quote['labor_charge']) ?></span></div><?php endif; ?>
      <?php if ((float)$quote['discount'] > 0): ?><div><span>Discount</span><span>− <?= money($quote['discount']) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span><?= money($quote['total_amount']) ?></span></div>
    </div>
    <?php if ($quote['terms']): ?><div class="doc-terms"><strong>Terms and conditions</strong><br><?= e($quote['terms']) ?></div><?php endif; ?>
  </div>
<?php else: ?>
  <div class="card"><div class="card-body tight">
    <?php if (!$quotes): ?>
      <div class="empty"><strong>No quotations yet</strong>Once we price a job for you it appears here.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Quotation</th><th>Subject</th><th>Date</th><th>Valid until</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($quotes as $qt): ?>
        <tr><td class="row-main"><?= e($qt['quotation_no']) ?></td><td><?= e($qt['subject']) ?></td>
          <td class="nowrap"><?= fdate($qt['quote_date']) ?></td><td class="nowrap"><?= fdate($qt['valid_until']) ?></td>
          <td class="num"><?= money($qt['total_amount']) ?></td><td><?= badge($qt['status']) ?></td>
          <td class="actions-cell"><a class="btn btn-sm btn-outline" href="quotations.php?id=<?= (int)$qt['id'] ?>">Open</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
