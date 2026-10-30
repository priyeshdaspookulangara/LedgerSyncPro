<?php
// api/create_transaction.php - Post multi-currency transaction with double-entry validation

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$user = authenticate_api_request();
$conn = get_db_connection();

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true) ?? $_POST;

$entity_id = intval($data['entity_id'] ?? 0);

// If no entity provided, default to TAX-India
if (!$entity_id) {
    $stmt_default = $conn->prepare("SELECT id FROM entities WHERE name = 'TAX-India' AND status = 'active' LIMIT 1");
    $stmt_default->execute();
    if ($row_default = $stmt_default->get_result()->fetch_assoc()) {
        $entity_id = intval($row_default['id']);
    }
    $stmt_default->close();
}

$tx_type = sanitize_input($data['tx_type'] ?? 'income');
$entry_date = sanitize_input($data['entry_date'] ?? date('Y-m-d'));
$reference_number = sanitize_input($data['reference_number'] ?? '');
$description = sanitize_input($data['description'] ?? '');
$amount = floatval($data['amount'] ?? 0);
$payment_account_id = intval($data['payment_account_id'] ?? 0);
$category_account_id = intval($data['category_account_id'] ?? 0);

// Validate Entity
$stmt_e = $conn->prepare("SELECT name, default_currency FROM entities WHERE id = ? AND status = 'active'");
$stmt_e->bind_param("i", $entity_id);
$stmt_e->execute();
$entity_data = $stmt_e->get_result()->fetch_assoc();
$stmt_e->close();

if (!$entity_data) {
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

$currency = $entity_data['default_currency'];

// Map Accounts for Double Entry
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

// Atomic Transaction Posting
$conn->begin_transaction();

try {
    // 1. Journal Entry Header
    $stmt_je = $conn->prepare("INSERT INTO journal_entries (entity_id, entry_date, currency, reference_number, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt_je->bind_param("issssi", $entity_id, $entry_date, $currency, $reference_number, $description, $user['id']);
    $stmt_je->execute();
    $journal_entry_id = $conn->insert_id;
    $stmt_je->close();

    // 2. Debit Line
    $stmt_dr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0.0000)");
    $stmt_dr->bind_param("iid", $journal_entry_id, $debit_account_id, $amount);
    $stmt_dr->execute();
    $stmt_dr->close();

    // 3. Credit Line
    $stmt_cr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0.0000, ?)");
    $stmt_cr->bind_param("iid", $journal_entry_id, $credit_account_id, $amount);
    $stmt_cr->execute();
    $stmt_cr->close();

    $conn->commit();

    log_audit_action($user['id'], "API Posted Journal Entry #$journal_entry_id ($currency $amount)");

    echo json_encode([
        'success' => true,
        'message' => 'Transaction posted successfully',
        'journal_entry_id' => $journal_entry_id,
        'currency' => $currency,
        'amount' => $amount
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()]);
}
