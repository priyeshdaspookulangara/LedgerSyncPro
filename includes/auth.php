<?php
// includes/auth.php - Session Management, RBAC, CSRF & Audit Logging

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/**
 * Generate CSRF Token for HTML Forms
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token from POST request
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

/**
 * Log actions into `audit_logs` table
 */
function log_audit_action($user_id, $action) {
    $conn = get_db_connection();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($ip_address === '::1') {
        $ip_address = '127.0.0.1';
    }

    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, ip_address, timestamp) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iss", $user_id, $action, $ip_address);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get current authenticated web user or false
 */
function get_logged_in_user() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return [
        'id' => $_SESSION['user_id'],
        'full_name' => $_SESSION['full_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? 'staff'
    ];
}

/**
 * Require Login for HTML Pages (Redirects if unauthenticated)
 */
function require_login() {
    $user = get_logged_in_user();
    if (!$user) {
        // Find relative path to root login page
        header("Location: /index.php");
        exit;
    }
    return $user;
}

/**
 * Require Admin role for HTML Pages
 */
function require_admin() {
    $user = require_login();
    if ($user['role'] !== 'admin') {
        header("Location: /staff/index.php?error=" . urlencode("Access denied: Admin privileges required."));
        exit;
    }
    return $user;
}

/**
 * Authenticate API Request via Bearer Token or Session
 * Returns user array or exits with 401 JSON
 */
function authenticate_api_request() {
    $conn = get_db_connection();

    // Check Authorization header first
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.role, u.status
                                FROM api_tokens t
                                JOIN users u ON t.user_id = u.id
                                WHERE t.token = ? AND t.expires_at > NOW() AND u.status = 'active'");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row;
        }
    }

    // Fallback to Session Auth
    if (isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT id, full_name, email, role, status FROM users WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row;
        }
    }

    // Unauthorized
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Invalid or expired token/session']);
    exit;
}

/**
 * Sanitize string input
 */
function sanitize_input($data) {
    if (is_null($data)) return '';
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
