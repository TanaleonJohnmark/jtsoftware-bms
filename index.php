<?php
require_once __DIR__ . '/includes/auth.php';
redirect(base_url(isset($_SESSION['user']) ? 'dashboard.php' : 'login.php'));
