<?php
/**
 * Staff authentication and role-based access control.
 */
require_once __DIR__ . '/../config/config.php';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Permission codes granted to the signed-in user's role. */
function user_permissions(): array
{
    if (!isset($_SESSION['user'])) {
        return [];
    }
    if (!isset($_SESSION['permissions'])) {
        $_SESSION['permissions'] = array_column(all(
            'SELECT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?',
            [$_SESSION['user']['role_id']]
        ), 'code');
    }
    return $_SESSION['permissions'];
}

function can(string $code): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if ((int)$_SESSION['user']['role_id'] === 1) {
        return true; // Super Admin
    }
    return in_array($code, user_permissions(), true);
}

function require_login(): void
{
    if (!isset($_SESSION['user'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(base_url('login.php'));
    }
}

/** Guard a whole page behind a permission. */
function require_permission(string $code): void
{
    require_login();
    if (!can($code)) {
        http_response_code(403);
        include __DIR__ . '/denied.php';
        exit;
    }
}

/** Guard a POST action behind a permission. */
function guard(string $code): void
{
    if (!can($code)) {
        flash('Your role does not have permission to do that.', 'danger');
        redirect(basename($_SERVER['PHP_SELF']));
    }
}

function attempt_login(string $username, string $password): bool
{
    $user = one(
        'SELECT u.*, r.name AS role_name, r.slug AS role_slug
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? AND u.status = "active"',
        [$username]
    );
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    unset($user['password_hash']);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    unset($_SESSION['permissions']);
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    log_activity('Auth', 'login', (int)$user['id'], $user['full_name'] . ' signed in');
    return true;
}

function logout(): void
{
    if (isset($_SESSION['user'])) {
        log_activity('Auth', 'logout', (int)$_SESSION['user']['id'], $_SESSION['user']['full_name'] . ' signed out');
    }
    $_SESSION = [];
    session_destroy();
}

/* --------------------------- Client portal --------------------------- */
function current_client(): ?array
{
    return $_SESSION['client'] ?? null;
}

function require_client_login(): void
{
    if (!isset($_SESSION['client'])) {
        redirect(base_url('portal/login.php'));
    }
}

function attempt_client_login(string $username, string $password): bool
{
    $client = one(
        'SELECT * FROM clients WHERE portal_username = ? AND portal_enabled = 1 AND status = "active"',
        [$username]
    );
    if (!$client || !$client['portal_password'] || !password_verify($password, $client['portal_password'])) {
        return false;
    }
    unset($client['portal_password']);
    session_regenerate_id(true);
    $_SESSION['client'] = $client;
    log_activity('Portal', 'login', (int)$client['id'], $client['full_name'] . ' signed in to the client portal');
    return true;
}
