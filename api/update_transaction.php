<?php
// api/update_transaction.php - Update existing transaction entry via REST API

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST or PUT']);
    exit;
}

$user = authenticate_api_request();
$conn = get_db_connection();

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true) ?? $_POST;

$entry_id = intval($data['entry_id'] ?? $data['id'] ?? 0);
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
    echo json_encode(['success' => false, 'error' => 'Access denied: You can only edit entries created by yourself']);
    exit;
}

$entity_id = intval($data['entity_id'] ?? 0);
$tx_type = sanitize_input($data['tx_type'] ?? 'income');
$entry_date = sanitize_input($data['entry_date'] ?? date('Y-m-d'));
$reference_number = sanitize_input($data['reference_number'] ?? '');
$description = sanitize_input($data['description'] ?? '');
$currency = sanitize_input($data['currency'] ?? 'INR');
if (!in_array($currency, ['INR', 'USDT'])) {
    $currency = 'INR';
}

$amount = floatval($data['amount'] ?? 0);
$payment_account_id = intval($data['payment_account_id'] ?? 0);
$category_account_id = intval($data['category_account_id'] ?? 0);

// Validate Entity
$stmt_e = $conn->prepare("SELECT name, default_currency FROM entities WHERE id = ? AND status = 'active'");
$stmt_e->bind_param("i", $entity_id);
$stmt_e->execute();
$entity_info = $stmt_e->get_result()->fetch_assoc();
$stmt_e->close();

if (!$entity_info) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or inactive business entity']);
    exit;
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit;
}

if (!$payment_account_id || !$category_account_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment account and Category account are required']);
    exit;
}

if ($payment_account_id === $category_account_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment account and Category account cannot be the same']);
    exit;
}

$debit_account_id = 0;
$credit_account_id = 0;

if ($tx_type === 'income') {
    $debit_account_id = $payment_account_id;
    $credit_account_id = $category_account_id;
} elseif ($tx_type === 'expense' || $tx_type === 'mlm_payout' || $tx_type === 'leader_transfer') {
    $debit_account_id = $category_account_id;
    $credit_account_id = $payment_account_id;
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction type']);
    exit;
}

// Atomic update
$conn->begin_transaction();
try {
    $stmt_u = $conn->prepare("UPDATE journal_entries SET entity_id = ?, entry_date = ?, currency = ?, reference_number = ?, description = ? WHERE id = ?");
    $stmt_u->bind_param("issssi", $entity_id, $entry_date, $currency, $reference_number, $description, $entry_id);
    $stmt_u->execute();
    $stmt_u->close();

    $stmt_d = $conn->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
    $stmt_d->bind_param("i", $entry_id);
    $stmt_d->execute();
    $stmt_d->close();

    $stmt_dr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0.0000)");
    $stmt_dr->bind_param("iid", $entry_id, $debit_account_id, $amount);
    $stmt_dr->execute();
    $stmt_dr->close();

    $stmt_cr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0.0000, ?)");
    $stmt_cr->bind_param("iid", $entry_id, $credit_account_id, $amount);
    $stmt_cr->execute();
    $stmt_cr->close();

    $conn->commit();

    log_audit_action($user['id'], "API Updated Journal Entry #$entry_id ($currency $amount)");

    echo json_encode([
        'success' => true,
        'message' => 'Transaction updated successfully',
        'entry_id' => $entry_id,
        'currency' => $currency,
        'amount' => $amount
    ]);
} catch (Exception $ex) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $ex->getMessage()]);
}
