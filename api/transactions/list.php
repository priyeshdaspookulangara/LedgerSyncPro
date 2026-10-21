<?php
// api/transactions/list.php - Filtered & Paginated Transactions List

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = authenticate_api_request();
$conn = get_db_connection();

$entity_id = intval($_GET['entity_id'] ?? 0);
$currency = sanitize_input($_GET['currency'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$where = ["1=1"];
if ($entity_id > 0) {
    $where[] = "je.entity_id = " . $entity_id;
}
if (!empty($currency) && in_array($currency, ['INR', 'USDT'])) {
    $where[] = "je.currency = '" . $conn->real_escape_string($currency) . "'";
}

// Staff restricted to their own entries unless Admin
if ($user['role'] !== 'admin') {
    $where[] = "je.created_by = " . intval($user['id']);
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Count
$count_res = $conn->query("SELECT COUNT(*) as cnt FROM journal_entries je $where_sql")->fetch_assoc();
$total_records = intval($count_res['cnt']);

// Query
$sql = "SELECT je.id, je.entry_date, je.currency, je.reference_number, je.description, je.created_at,
               e.name AS entity_name,
               u.full_name AS created_by_name,
               (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = je.id) AS total_amount
        FROM journal_entries je
        JOIN entities e ON je.entity_id = e.id
        JOIN users u ON je.created_by = u.id
        $where_sql
        ORDER BY je.id DESC
        LIMIT $limit OFFSET $offset";

$transactions = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'pagination' => [
        'total_records' => $total_records,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total_records / $limit)
    ],
    'transactions' => $transactions
]);
