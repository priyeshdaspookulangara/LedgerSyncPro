<?php
// api/delete_transaction.php - Delete transaction entry via REST API

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST or DELETE']);
    exit;
}

$user = authenticate_api_request();
$conn = get_db_connection();

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true) ?? $_POST ?? $_GET;

$entry_id = intval($data['entry_id'] ?? $data['id'] ?? $_GET['id'] ?? 0);
if (!$entry_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required transaction entry_id']);
    exit;
}

// Fetch entry
$stmt_check = $conn->prepare("SELECT id, created_by FROM journal_entries WHERE id = ?");
$stmt_check->bind_param("i", $entry_id);
$stmt_check->execute();
$entry_row = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

if (!$entry_row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Journal entry not found']);
    exit;
}

// RBAC
if ($user['role'] !== 'admin' && intval($entry_row['created_by']) !== intval($user['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: You can only delete entries created by yourself']);
    exit;
}

// Delete entry
$stmt_del = $conn->prepare("DELETE FROM journal_entries WHERE id = ?");
$stmt_del->bind_param("i", $entry_id);

if ($stmt_del->execute()) {
    log_audit_action($user['id'], "API Deleted Journal Entry #$entry_id");
    echo json_encode([
        'success' => true,
        'message' => "Journal Entry #$entry_id deleted successfully",
        'entry_id' => $entry_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete transaction entry']);
}
$stmt_del->close();
