<?php
/**
 * Application bootstrap: sessions, settings, shared helpers.
 * Every page includes this file first.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/database.php';

/* Fallbacks for PHP builds without the mbstring extension. */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) { return $length === null ? substr((string)$s, $start) : substr((string)$s, $start, $length); }
    function mb_strtoupper($s, $enc = null) { return strtoupper((string)$s); }
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}

define('APP_NAME', 'JTSOFTWARE I.T. SERVICES');
define('APP_SHORT', 'JT Software');

/** Base URL of the installation, e.g. /jtsoftware */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        // Pages inside /portal still link to the app root.
        if (substr($script, -7) === '/portal') {
            $script = substr($script, 0, -7);
        }
        $base = rtrim($script, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Read a company setting. */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = (string)$r['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Format an amount using the configured currency symbol. */
function money($amount, bool $symbol = true): string
{
    $txt = number_format((float)$amount, 2);
    return $symbol ? setting('currency_symbol', 'PHP ') . $txt : $txt;
}

function fdate($date, string $format = 'M d, Y'): string
{
    if (!$date || $date === '0000-00-00') {
        return '—';
    }
    return date($format, strtotime((string)$date));
}

function fdatetime($value): string
{
    return fdate($value, 'M d, Y g:i A');
}

/** Read a POST/GET value. */
function input(string $key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function post_num(string $key, float $default = 0): float
{
    $v = str_replace([',', ' '], '', (string)($_POST[$key] ?? ''));
    return $v === '' ? $default : (float)$v;
}

function post_int(string $key, ?int $default = null): ?int
{
    $v = $_POST[$key] ?? '';
    if ($v === '' || $v === null) {
        return $default;
    }
    return (int)$v;
}

/** Null for empty strings so optional foreign keys stay NULL. */
function nn($value)
{
    if (is_string($value)) {
        $value = trim($value);
    }
    return ($value === '' || $value === null) ? null : $value;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ------------------------------------------------------------------ */
/* Flash messages                                                      */
/* ------------------------------------------------------------------ */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/* CSRF protection                                                     */
/* ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('This form expired. Go back, reload the page and submit again.');
    }
}

/* ------------------------------------------------------------------ */
/* Document numbering                                                  */
/* ------------------------------------------------------------------ */
/**
 * Build the next running number for a document, e.g. PRJ-0007.
 */
function next_code(string $table, string $column, string $prefix, int $pad = 4): string
{
    $last = scalar(
        "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1",
        [$prefix . '-%'],
        ''
    );
    $n = $last ? (int)substr((string)$last, strlen($prefix) + 1) : 0;
    return $prefix . '-' . str_pad((string)($n + 1), $pad, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ */
/* Activity log                                                        */
/* ------------------------------------------------------------------ */
function log_activity(string $module, string $action, ?int $referenceId, string $description): void
{
    $userId = $_SESSION['user']['id'] ?? null;
    $actorType = isset($_SESSION['client']) ? 'Client' : (isset($_SESSION['user']) ? 'Staff' : 'System');
    $actorName = $_SESSION['user']['full_name'] ?? ($_SESSION['client']['full_name'] ?? 'System');
    q(
        'INSERT INTO activity_logs (user_id, actor_type, actor_name, module, action, reference_id, description, ip_address)
         VALUES (?,?,?,?,?,?,?,?)',
        [$userId, $actorType, $actorName, $module, $action, $referenceId, mb_substr($description, 0, 255),
         $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']
    );
}

/* ------------------------------------------------------------------ */
/* UI helpers                                                          */
/* ------------------------------------------------------------------ */
/** Map a status word to a badge class. */
function badge(string $status): string
{
    $map = [
        'Pending' => 'warn', 'New' => 'info', 'Draft' => 'muted', 'Unpaid' => 'danger',
        'Approved' => 'info', 'Sent' => 'info', 'Assigned' => 'info', 'Scheduled' => 'info',
        'Ongoing' => 'accent', 'In Progress' => 'accent', 'Partial' => 'warn', 'On Hold' => 'warn',
        'Completed' => 'ok', 'Paid' => 'ok', 'Converted' => 'ok', 'active' => 'ok', 'Present' => 'ok',
        'Cancelled' => 'muted', 'Rejected' => 'danger', 'Expired' => 'muted', 'inactive' => 'muted',
        'Absent' => 'danger', 'Late' => 'warn', 'Urgent' => 'danger', 'High' => 'warn',
        'Normal' => 'info', 'Low' => 'muted', 'Field Work' => 'accent', 'Leave' => 'muted',
        'Half Day' => 'warn', 'Rescheduled' => 'warn',
    ];
    $class = $map[$status] ?? 'muted';
    return '<span class="badge badge-' . $class . '">' . e($status) . '</span>';
}

/** Keep the current query string while changing one parameter. */
function url_with(array $changes): string
{
    $params = array_merge($_GET, $changes);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    return basename($_SERVER['PHP_SELF']) . (empty($params) ? '' : '?' . http_build_query($params));
}

/** Send an array of rows to the browser as a CSV download (opens in Excel). */
function export_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
