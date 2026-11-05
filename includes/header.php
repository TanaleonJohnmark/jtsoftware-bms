<?php
/**
 * Page shell for every staff page.
 * Set $pageTitle, optionally $pageSub and $pageActions before including.
 */
require_once __DIR__ . '/auth.php';
require_login();

$u          = current_user();
$pageTitle  = $pageTitle  ?? 'Dashboard';
$pageSub    = $pageSub    ?? '';
$pageActions = $pageActions ?? '';
$current    = basename($_SERVER['PHP_SELF']);

/** Small inline icon set (no external icon font needed). */
function icon(string $name): string
{
    $p = [
        'dashboard' => '<path d="M3 3h7v8H3zM14 3h7v5h-7zM14 11h7v10h-7zM3 14h7v7H3z"/>',
        'clients'   => '<path d="M16 19v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 19v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
        'camera'    => '<path d="M3 7h13l5 4v6H3z"/><circle cx="9" cy="12" r="2.5"/><path d="M7 7V5h6v2"/>',
        'wrench'    => '<path d="M14.7 6.3a4 4 0 0 1 5 5l-9 9a2.1 2.1 0 0 1-3-3l9-9"/><path d="M9 3 6 6 3 5l1 3-3 3 4 1"/>',
        'quote'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
        'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'sales'     => '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
        'cash'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>',
        'staff'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'ledger'    => '<path d="M4 4h16v16H4z"/><path d="M8 4v16M12 9h5M12 13h5"/>',
        'report'    => '<path d="M4 20h16"/><rect x="5" y="11" width="3" height="7"/><rect x="10.5" y="7" width="3" height="11"/><rect x="16" y="13" width="3" height="5"/>',
        'users'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'log'       => '<path d="M12 8v5l3 2"/><circle cx="12" cy="12" r="9"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.2A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 15a2 2 0 1 1 0-4 1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9 4.6 2 2 0 1 1 13 4.6a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.6 1.6 0 0 0 19.4 11a2 2 0 1 1 0 4z"/>',
        'portal'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ];
    $body = $p[$name] ?? $p['dashboard'];
    return '<svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/** Render one sidebar link when the role is allowed to see it. */
function nav_link(string $file, string $label, string $ico, string $perm, array $alsoActive = [], $count = null): void
{
    if ($perm !== '' && !can($perm)) {
        return;
    }
    $cur    = basename($_SERVER['PHP_SELF']);
    $active = ($cur === $file || in_array($cur, $alsoActive, true)) ? ' active' : '';
    echo '<a class="nav-item' . $active . '" href="' . e(base_url($file)) . '">' . icon($ico)
       . '<span>' . e($label) . '</span>'
       . ($count ? '<span class="nav-count">' . (int)$count . '</span>' : '')
       . '</a>';
}

$openProjects = (int)scalar("SELECT COUNT(*) FROM cctv_projects WHERE status IN ('Pending','Approved','Scheduled','Ongoing')");
$openServices = (int)scalar("SELECT COUNT(*) FROM service_requests WHERE status IN ('New','Assigned','In Progress','On Hold')");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e(APP_SHORT) ?></title>
<link rel="icon" href="<?= e(base_url('assets/img/logo.jpg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="JT Software logo">
      <div>
        <div class="wordmark">JTSOFTWARE</div>
        <div class="tagline">I.T. SERVICES</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', 'dashboard.view'); ?>

      <div class="nav-group">Operations</div>
      <?php
      nav_link('clients.php', 'Clients', 'clients', 'clients.view', ['client_view.php']);
      nav_link('projects.php', 'CCTV Projects', 'camera', 'projects.view', ['project_view.php'], $openProjects);
      nav_link('services.php', 'Service Requests', 'wrench', 'services.view', [], $openServices);
      nav_link('quotations.php', 'Quotations', 'quote', 'quotations.view', ['quotation_form.php']);
      nav_link('schedule.php', 'Schedule', 'calendar', 'schedule.view');
      ?>

      <div class="nav-group">Stock and money</div>
      <?php
      nav_link('products.php', 'Inventory', 'box', 'inventory.view', ['categories.php', 'suppliers.php', 'stock.php']);
      nav_link('sales.php', 'Sales and Invoices', 'sales', 'sales.view', ['sale_view.php']);
      nav_link('payments.php', 'Payments', 'cash', 'sales.view');
      nav_link('accounting.php', 'Income and Expenses', 'ledger', 'accounting.view');
      ?>

      <div class="nav-group">Company</div>
      <?php
      nav_link('employees.php', 'Employees', 'staff', 'employees.view', ['employee_view.php', 'attendance.php']);
      nav_link('reports.php', 'Reports', 'report', 'reports.view');
      nav_link('users.php', 'User Accounts', 'users', 'users.manage', ['roles.php']);
      nav_link('activity_logs.php', 'Activity Logs', 'log', 'logs.view');
      nav_link('settings.php', 'Settings', 'gear', 'settings.manage');
      ?>

      <div class="nav-group">Links</div>
      <a class="nav-item" href="<?= e(base_url('portal/login.php')) ?>" target="_blank"><?= icon('portal') ?><span>Client Portal</span></a>
      <a class="nav-item" href="<?= e(base_url('logout.php')) ?>"><?= icon('logout') ?><span>Sign out</span></a>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" id="menuToggle" aria-label="Show menu"><?= icon('menu') ?></button>
      <div>
        <div class="page-title"><?= e($pageTitle) ?></div>
        <?php if ($pageSub): ?><div class="page-sub"><?= e($pageSub) ?></div><?php endif; ?>
      </div>
      <div class="topbar-right">
        <?= $pageActions ?>
        <a class="userchip" href="<?= e(base_url('profile.php')) ?>">
          <span class="avatar"><?= e(strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
          <span>
            <span class="uname"><?= e($u['full_name']) ?></span><br>
            <span class="urole"><?= e($u['role_name']) ?></span>
          </span>
        </a>
      </div>
    </header>
    <main class="content">
      <?php foreach (take_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
