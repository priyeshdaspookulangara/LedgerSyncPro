<?php
// api/get_reports.php - Financial Summary & Ledger Breakdown API

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = authenticate_api_request();
$conn = get_db_connection();

$currency = sanitize_input($_GET['currency'] ?? 'USDT');
if (!in_array($currency, ['INR', 'USDT'])) {
    $currency = 'USDT';
}

// 1. Total Revenue
$income_res = $conn->query("
    SELECT COALESCE(SUM(l.credit - l.debit), 0) as total
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = '$currency' AND a.account_type = 'Income'
")->fetch_assoc();
$total_income = floatval($income_res['total']);

// 2. Total Expense
$expense_res = $conn->query("
    SELECT COALESCE(SUM(l.debit - l.credit), 0) as total
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = '$currency' AND a.account_type = 'Expense'
")->fetch_assoc();
$total_expense = floatval($expense_res['total']);

$net_profit = $total_income - $total_expense;

// 3. Entity Breakdown
$entities_res = $conn->query("
    SELECT e.id, e.name,
           COALESCE(SUM(CASE WHEN a.account_type = 'Income' THEN (l.credit - l.debit) ELSE 0 END), 0) as income,
           COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN (l.debit - l.credit) ELSE 0 END), 0) as expense
    FROM entities e
    LEFT JOIN journal_entries je ON je.entity_id = e.id
    LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
    LEFT JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.default_currency = '$currency'
    GROUP BY e.id, e.name
")->fetch_all(MYSQLI_ASSOC);

foreach ($entities_res as &$ent) {
    $ent['income'] = floatval($ent['income']);
    $ent['expense'] = floatval($ent['expense']);
    $ent['net_profit'] = $ent['income'] - $ent['expense'];
}

echo json_encode([
    'success' => true,
    'currency' => $currency,
    'summary' => [
        'total_income' => $total_income,
        'total_expense' => $total_expense,
        'net_profit' => $net_profit
    ],
    'entity_breakdown' => $entities_res
]);
