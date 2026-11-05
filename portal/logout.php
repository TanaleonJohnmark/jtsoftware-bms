<?php
require_once __DIR__ . '/../includes/auth.php';
if (isset($_SESSION['client'])) {
    log_activity('Portal', 'logout', (int)$_SESSION['client']['id'], $_SESSION['client']['full_name'] . ' signed out of the portal');
    unset($_SESSION['client']);
}
redirect(base_url('portal/login.php'));
