<?php
// api/login.php - Mobile REST API Authentication Endpoint

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true) ?? $_POST;

$email = sanitize_input($data['email'] ?? '');
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and password are required']);
    exit;
}

$conn = get_db_connection();
$stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, status FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    if ($row['status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Account is inactive']);
        exit;
    }

    if (password_verify($password, $row['password_hash'])) {
        // Generate Bearer Token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt_t = $conn->prepare("INSERT INTO api_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt_t->bind_param("iss", $row['id'], $token, $expires_at);
        $stmt_t->execute();
        $stmt_t->close();

        log_audit_action($row['id'], "User authenticated via REST API login");

        echo json_encode([
            'success' => true,
            'token' => $token,
            'expires_at' => $expires_at,
            'user' => [
                'id' => $row['id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'role' => $row['role']
            ]
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
