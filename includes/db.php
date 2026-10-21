<?php
// includes/db.php - MySQLi Database Connection Configuration

$db_host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$db_user = defined('DB_USER') ? DB_USER : 'accounting_user';
$db_pass = defined('DB_PASS') ? DB_PASS : 'secret123';
$db_name = defined('DB_NAME') ? DB_NAME : 'accounting_db';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    if (defined('IS_API') && IS_API) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    } else {
        die('Database Connection Error: ' . $e->getMessage());
    }
}

/**
 * Returns active MySQLi connection handle.
 */
function get_db_connection() {
    global $conn;
    return $conn;
}
