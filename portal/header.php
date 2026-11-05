<?php
require_once __DIR__ . '/../includes/auth.php';
require_client_login();
$client = current_client();
$cur    = basename($_SERVER['PHP_SELF']);

function pnav(string $file, string $label): void
{
    $active = basename($_SERVER['PHP_SELF']) === $file ? ' class="active"' : '';
    echo '<a href="' . e($file) . '"' . $active . '>' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Client portal') ?> · JT Software</title>
<link rel="icon" href="<?= e(base_url('assets/img/logo.jpg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<header class="portal-top">
  <img src="<?= e(base_url('assets/img/logo.jpg')) ?>" alt="JT Software">
  <nav class="portal-nav">
    <?php pnav('index.php', 'Overview'); pnav('projects.php', 'My installations');
          pnav('requests.php', 'Service requests'); pnav('quotations.php', 'Quotations');
          pnav('invoices.php', 'Invoices'); pnav('schedule.php', 'Schedule'); ?>
  </nav>
  <div style="margin-left:auto;display:flex;align-items:center;gap:14px">
    <span class="small"><?= e($client['full_name']) ?></span>
    <a class="btn btn-sm btn-outline" href="logout.php">Sign out</a>
  </div>
</header>
<main class="portal-main">
  <?php foreach (take_flash() as $f): ?>
    <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <h1 style="margin-bottom:4px"><?= e($pageTitle ?? 'Client portal') ?></h1>
  <?php if (!empty($pageSub)): ?><p class="muted" style="margin-top:0"><?= e($pageSub) ?></p><?php endif; ?>
