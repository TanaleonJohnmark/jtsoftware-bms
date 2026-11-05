<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('clients.view');

$id = (int)input('id', 0);
$client = one('SELECT * FROM clients WHERE id = ?', [$id]);
if (!$client) {
    flash('That client no longer exists.', 'danger');
    redirect(base_url('clients.php'));
}

$projects = all('SELECT * FROM cctv_projects WHERE client_id = ? ORDER BY created_at DESC', [$id]);
$services = all('SELECT s.*, e.full_name AS tech FROM service_requests s
                 LEFT JOIN employees e ON e.id = s.assigned_to
                 WHERE s.client_id = ? ORDER BY s.request_date DESC', [$id]);
$quotes   = all('SELECT * FROM quotations WHERE client_id = ? ORDER BY quote_date DESC', [$id]);
$invoices = all('SELECT * FROM sales WHERE client_id = ? ORDER BY sale_date DESC', [$id]);
$payments = all('SELECT p.*, s.invoice_no FROM payments p JOIN sales s ON s.id = p.sale_id
                 WHERE p.client_id = ? ORDER BY p.payment_date DESC', [$id]);

$billed    = array_sum(array_map(fn($r) => $r['status'] === 'Cancelled' ? 0 : (float)$r['total_amount'], $invoices));
$paid      = array_sum(array_map(fn($r) => (float)$r['amount'], $payments));
$balance   = array_sum(array_map(fn($r) => in_array($r['status'], ['Unpaid', 'Partial'], true) ? (float)$r['balance'] : 0, $invoices));

$pageTitle = $client['full_name'];
$pageSub   = $client['client_code'] . ' · ' . $client['client_type'] . ' client';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('clients.php')) . '">Back to clients</a>';
include __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4" style="margin-bottom:16px">
  <div class="stat is-blue"><div class="label">Installations</div><div class="value"><?= count($projects) ?></div>
    <div class="foot"><?= count(array_filter($projects, fn($p) => $p['status'] === 'Completed')) ?> completed</div></div>
  <div class="stat is-copper"><div class="label">Service requests</div><div class="value"><?= count($services) ?></div>
    <div class="foot"><?= count(array_filter($services, fn($s) => $s['status'] === 'Completed')) ?> completed</div></div>
  <div class="stat is-ok"><div class="label">Total paid</div><div class="value sm"><?= money($paid) ?></div>
    <div class="foot">billed <?= money($billed) ?></div></div>
  <div class="stat <?= $balance > 0 ? 'is-danger' : '' ?>"><div class="label">Outstanding</div>
    <div class="value sm"><?= money($balance) ?></div><div class="foot"><?= count($invoices) ?> invoices</div></div>
</div>

<div class="grid grid-sidebar">
  <div>
    <div class="card">
      <div class="card-head"><h2>Installation history</h2></div>
      <div class="card-body tight">
        <?php if (!$projects): ?><div class="empty"><strong>No installations yet</strong>Create one from the CCTV Projects page.</div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Project</th><th>Cameras</th><th>Installed</th><th class="num">Total</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><a class="row-main" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?></a>
                  <div class="row-sub"><?= e($p['title']) ?></div></td>
              <td><?= (int)$p['camera_count'] ?> · <?= e($p['recorder_type']) ?></td>
              <td class="nowrap"><?= fdate($p['installation_date']) ?></td>
              <td class="num"><?= money($p['total_cost']) ?></td>
              <td><?= badge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Service requests</h2></div>
      <div class="card-body tight">
        <?php if (!$services): ?><div class="empty"><strong>No service requests</strong>Jobs logged for this client will show here.</div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Request</th><th>Type</th><th>Technician</th><th>Date</th><th class="num">Fee</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td><span class="row-main"><?= e($s['request_no']) ?></span>
                  <div class="row-sub"><?= e(mb_substr($s['description'], 0, 70)) ?></div></td>
              <td><?= e($s['service_type']) ?></td>
              <td><?= e($s['tech'] ?: '—') ?></td>
              <td class="nowrap"><?= fdate($s['request_date']) ?></td>
              <td class="num"><?= money($s['service_fee']) ?></td>
              <td><?= badge($s['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Invoices and payments</h2></div>
      <div class="card-body tight">
        <?php if (!$invoices): ?><div class="empty"><strong>Nothing billed yet</strong>Invoices raised for this client appear here.</div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Invoice</th><th>Date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($invoices as $s): ?>
            <tr>
              <td><a class="row-main" href="sale_view.php?id=<?= (int)$s['id'] ?>"><?= e($s['invoice_no']) ?></a>
                  <div class="row-sub"><?= e($s['reference_type']) ?></div></td>
              <td class="nowrap"><?= fdate($s['sale_date']) ?></td>
              <td class="num"><?= money($s['total_amount']) ?></td>
              <td class="num"><?= money($s['amount_paid']) ?></td>
              <td class="num"><?= money($s['balance']) ?></td>
              <td><?= badge($s['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h2>Contact details</h2></div>
      <div class="card-body">
        <dl class="dl">
          <dt>Business</dt><dd><?= e($client['business_name'] ?: '—') ?></dd>
          <dt>Type</dt><dd><?= e($client['client_type']) ?></dd>
          <dt>Contact</dt><dd><?= e($client['contact_number'] ?: '—') ?></dd>
          <dt>Email</dt><dd><?= e($client['email'] ?: '—') ?></dd>
          <dt>Address</dt><dd><?= e($client['address'] ?: '—') ?></dd>
          <dt>Portal</dt><dd><?= $client['portal_enabled'] ? 'Enabled (' . e($client['portal_username']) . ')' : 'Not enabled' ?></dd>
          <dt>Client since</dt><dd><?= fdate($client['created_at']) ?></dd>
          <dt>Status</dt><dd><?= badge($client['status']) ?></dd>
        </dl>
        <?php if ($client['notes']): ?>
          <p class="small" style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px"><?= nl2br(e($client['notes'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Quotations</h2></div>
      <div class="card-body tight">
        <?php if (!$quotes): ?><div class="empty">No quotations yet.</div><?php else: ?>
        <table class="data"><tbody>
        <?php foreach ($quotes as $qt): ?>
          <tr>
            <td><a class="row-main" href="quotation_print.php?id=<?= (int)$qt['id'] ?>"><?= e($qt['quotation_no']) ?></a>
                <div class="row-sub"><?= fdate($qt['quote_date']) ?> · <?= money($qt['total_amount']) ?></div></td>
            <td class="text-right"><?= badge($qt['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Payment history</h2></div>
      <div class="card-body tight">
        <?php if (!$payments): ?><div class="empty">No payments recorded.</div><?php else: ?>
        <table class="data"><tbody>
        <?php foreach (array_slice($payments, 0, 8) as $p): ?>
          <tr>
            <td><span class="row-main"><?= money($p['amount']) ?></span>
                <div class="row-sub"><?= e($p['invoice_no']) ?> · <?= e($p['method']) ?></div></td>
            <td class="text-right small nowrap"><?= fdate($p['payment_date']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
