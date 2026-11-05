<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('settings.manage');

$keys = ['company_name', 'company_division', 'company_address', 'company_phone', 'company_email',
         'currency_symbol', 'tax_rate', 'low_stock_threshold', 'quotation_terms'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach ($keys as $k) {
        if (array_key_exists($k, $_POST)) {
            q('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, (string)$_POST[$k]]);
        }
    }
    log_activity('Settings', 'update', null, 'Updated company settings');
    flash('Settings saved.');
    redirect('settings.php');
}

$pageTitle = 'Company settings';
$pageSub   = 'These details appear on quotations, invoices and reports';
include __DIR__ . '/includes/header.php';
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-sidebar">
    <div>
      <div class="card">
        <div class="card-head"><h2>Business details</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="field"><label>Business name</label><input class="input" name="company_name" value="<?= e(setting('company_name')) ?>"></div>
            <div class="field"><label>Division</label><input class="input" name="company_division" value="<?= e(setting('company_division')) ?>"></div>
          </div>
          <div class="field"><label>Address</label><input class="input" name="company_address" value="<?= e(setting('company_address')) ?>"></div>
          <div class="form-row">
            <div class="field"><label>Contact number</label><input class="input" name="company_phone" value="<?= e(setting('company_phone')) ?>"></div>
            <div class="field"><label>Email</label><input class="input" name="company_email" value="<?= e(setting('company_email')) ?>"></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Default quotation terms</h2></div>
        <div class="card-body">
          <div class="field">
            <textarea class="input" name="quotation_terms" style="min-height:190px"><?= e(setting('quotation_terms')) ?></textarea>
            <p class="hint">New quotations start with this text. You can still change it on each one.</p>
          </div>
          <button class="btn" type="submit">Save settings</button>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h2>Figures and formats</h2></div>
        <div class="card-body">
          <div class="field"><label>Currency prefix</label>
            <input class="input" name="currency_symbol" value="<?= e(setting('currency_symbol')) ?>">
            <p class="hint">Shown before every amount, for example "PHP " or "₱".</p></div>
          <div class="field"><label>Default tax rate (%)</label>
            <input class="input" name="tax_rate" type="number" step="0.01" min="0" value="<?= e(setting('tax_rate', '0')) ?>"></div>
          <div class="field"><label>Low stock warning level</label>
            <input class="input" name="low_stock_threshold" type="number" min="0" value="<?= e(setting('low_stock_threshold', '5')) ?>">
            <p class="hint">Products also carry their own reorder level, which wins when it is set.</p></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Logo</h2></div>
        <div class="card-body">
          <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="Company logo" style="max-width:100%;border:1px solid var(--line);border-radius:8px">
          <p class="hint">To change it, replace <code>assets/img/logo.jpg</code> with your new file, keeping the same name.</p>
        </div>
      </div>
    </div>
  </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
