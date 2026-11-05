<?php
/**
 * Database connection settings.
 * On a default XAMPP install the user is "root" with a blank password.
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'jtsoftware_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo '<div style="font:15px/1.6 system-ui;max-width:640px;margin:80px auto;padding:28px;'
               . 'border:1px solid #E3E8F0;border-radius:10px">'
               . '<h2 style="margin:0 0 10px;color:#16233F">Cannot reach the database</h2>'
               . '<p style="color:#475569">Start MySQL in the XAMPP control panel, then import '
               . '<code>database/jtsoftware_db.sql</code> through phpMyAdmin.</p>'
               . '<p style="color:#94A3B8;font-size:13px">' . htmlspecialchars($e->getMessage()) . '</p></div>';
            exit;
        }
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row of a query, or null. */
function one(string $sql, array $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows of a query. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** First column of the first row. */
function scalar(string $sql, array $params = [], $default = 0)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false || $v === null ? $default : $v;
}
