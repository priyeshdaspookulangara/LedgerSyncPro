<?php
// admin/index.php - Multi-Entity & Multi-Currency Admin Dashboard

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_admin();
$conn = get_db_connection();

// --- 1. Compute INR Metrics (Consultation & GST, Burfee Cart) ---
// Revenue (Account Type = Income)
$inr_income_res = $conn->query("
    SELECT SUM(l.credit - l.debit) as total_income
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = 'INR' AND a.account_type = 'Income'
")->fetch_assoc();
$inr_income = floatval($inr_income_res['total_income'] ?? 0);

// Expense (Account Type = Expense)
$inr_expense_res = $conn->query("
    SELECT SUM(l.debit - l.credit) as total_expense
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = 'INR' AND a.account_type = 'Expense'
")->fetch_assoc();
$inr_expense = floatval($inr_expense_res['total_expense'] ?? 0);

$inr_net_profit = $inr_income - $inr_expense;

// INR Entity breakdown
$inr_entities_res = $conn->query("
    SELECT e.id, e.name,
           COALESCE(SUM(CASE WHEN a.account_type = 'Income' THEN (l.credit - l.debit) ELSE 0 END), 0) as income,
           COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN (l.debit - l.credit) ELSE 0 END), 0) as expense
    FROM entities e
    LEFT JOIN journal_entries je ON je.entity_id = e.id
    LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
    LEFT JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.default_currency = 'INR'
    GROUP BY e.id, e.name
")->fetch_all(MYSQLI_ASSOC);


// --- 2. Compute USDT Metrics (Optimus Infinity, Rayon Global Impact) ---
// Revenue
$usdt_income_res = $conn->query("
    SELECT SUM(l.credit - l.debit) as total_income
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = 'USDT' AND a.account_type = 'Income'
")->fetch_assoc();
$usdt_income = floatval($usdt_income_res['total_income'] ?? 0);

// Expense
$usdt_expense_res = $conn->query("
    SELECT SUM(l.debit - l.credit) as total_expense
    FROM journal_entry_lines l
    JOIN journal_entries e ON l.journal_entry_id = e.id
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.currency = 'USDT' AND a.account_type = 'Expense'
")->fetch_assoc();
$usdt_expense = floatval($usdt_expense_res['total_expense'] ?? 0);

$usdt_net_profit = $usdt_income - $usdt_expense;

// USDT Entity breakdown
$usdt_entities_res = $conn->query("
    SELECT e.id, e.name,
           COALESCE(SUM(CASE WHEN a.account_type = 'Income' THEN (l.credit - l.debit) ELSE 0 END), 0) as income,
           COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN (l.debit - l.credit) ELSE 0 END), 0) as expense
    FROM entities e
    LEFT JOIN journal_entries je ON je.entity_id = e.id
    LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
    LEFT JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE e.default_currency = 'USDT'
    GROUP BY e.id, e.name
")->fetch_all(MYSQLI_ASSOC);

// Recent Audit Logs
$recent_logs = $conn->query("
    SELECT log.id, log.action, log.ip_address, log.timestamp, u.full_name, u.role
    FROM audit_logs log
    LEFT JOIN users u ON log.user_id = u.id
    ORDER BY log.id DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

$page_title = "Executive Multi-Currency Dashboard";
$active_nav = "admin_dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Tab Switcher Bar -->
<div class="dash-tab-container">
    <button class="btn btn-inr dash-tab-btn active" data-currency="INR">
        <i class="fa-solid fa-indian-rupee-sign"></i> Total INR Metrics (Fiat Wing)
    </button>
    <button class="btn btn-usdt dash-tab-btn" data-currency="USDT">
        <i class="fa-solid fa-dollar-sign"></i> Total USDT Metrics (Crypto Wing)
    </button>
</div>

<!-- ================= INR SECTION ================= -->
<div id="section_INR" class="currency-section">
    <div style="margin-bottom: 1rem; color: var(--inr-dark); font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-building-columns"></i> INR Entity Group Overview (Consultation, GST & Burfee Cart)
    </div>

    <div class="grid-3" style="margin-bottom: 1.5rem;">
        <div class="stat-card inr-card">
            <span class="stat-title">Total INR Revenue</span>
            <span class="stat-value" style="color: var(--inr-primary);">₹<?= number_format($inr_income, 2) ?></span>
            <span class="stat-subtitle">Across all INR Business Units</span>
        </div>

        <div class="stat-card inr-card">
            <span class="stat-title">Total INR Expenses</span>
            <span class="stat-value" style="color: var(--danger-color);">₹<?= number_format($inr_expense, 2) ?></span>
            <span class="stat-subtitle">Operational & GST Expenditures</span>
        </div>

        <div class="stat-card inr-card">
            <span class="stat-title">Net INR Profit / Loss</span>
            <span class="stat-value" style="color: <?= $inr_net_profit >= 0 ? 'var(--inr-primary)' : 'var(--danger-color)' ?>;">
                ₹<?= number_format($inr_net_profit, 2) ?>
            </span>
            <span class="stat-subtitle"><?= $inr_net_profit >= 0 ? 'Net Surplus' : 'Net Deficit' ?></span>
        </div>
    </div>

    <!-- Entity Breakdown Cards -->
    <div class="grid-2">
        <?php foreach ($inr_entities_res as $e_item): ?>
            <?php $net = $e_item['income'] - $e_item['expense']; ?>
            <div class="card">
                <div class="card-header inr-theme">
                    <div class="card-title"><i class="fa-solid fa-store"></i> <?= htmlspecialchars($e_item['name']) ?></div>
                    <span class="badge badge-inr">INR Entity</span>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Total Revenue:</span>
                        <strong style="color: var(--inr-primary);">₹<?= number_format($e_item['income'], 2) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Total Expenses:</span>
                        <strong style="color: var(--danger-color);">₹<?= number_format($e_item['expense'], 2) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.5rem; font-size: 1.05rem;">
                        <strong>Net Profit:</strong>
                        <strong style="color: <?= $net >= 0 ? 'var(--inr-primary)' : 'var(--danger-color)' ?>;">
                            ₹<?= number_format($net, 2) ?>
                        </strong>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ================= USDT SECTION ================= -->
<div id="section_USDT" class="currency-section" style="display: none;">
    <div style="margin-bottom: 1rem; color: var(--usdt-dark); font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-coins"></i> USDT Entity Group Overview (Optimus Infinity & Rayon Global Impact)
    </div>

    <div class="grid-3" style="margin-bottom: 1.5rem;">
        <div class="stat-card usdt-card">
            <span class="stat-title">Total USDT Revenue</span>
            <span class="stat-value" style="color: var(--usdt-primary);">$<?= number_format($usdt_income, 2) ?> USDT</span>
            <span class="stat-subtitle">Subscriptions & Network Direct Revenues</span>
        </div>

        <div class="stat-card usdt-card">
            <span class="stat-title">Total USDT Expenses</span>
            <span class="stat-value" style="color: var(--danger-color);">$<?= number_format($usdt_expense, 2) ?> USDT</span>
            <span class="stat-subtitle">MLM Commissions & Blockchain Gas Fees</span>
        </div>

        <div class="stat-card usdt-card">
            <span class="stat-title">Net USDT Profit / Loss</span>
            <span class="stat-value" style="color: <?= $usdt_net_profit >= 0 ? 'var(--usdt-primary)' : 'var(--danger-color)' ?>;">
                $<?= number_format($usdt_net_profit, 2) ?> USDT
            </span>
            <span class="stat-subtitle"><?= $usdt_net_profit >= 0 ? 'Net Surplus' : 'Net Deficit' ?></span>
        </div>
    </div>

    <!-- Entity Breakdown Cards -->
    <div class="grid-2">
        <?php foreach ($usdt_entities_res as $e_item): ?>
            <?php $net = $e_item['income'] - $e_item['expense']; ?>
            <div class="card">
                <div class="card-header usdt-theme">
                    <div class="card-title"><i class="fa-solid fa-cubes-stacked"></i> <?= htmlspecialchars($e_item['name']) ?></div>
                    <span class="badge badge-usdt">USDT Entity</span>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Total Revenue:</span>
                        <strong style="color: var(--usdt-primary);">$<?= number_format($e_item['income'], 2) ?> USDT</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Total Expenses:</span>
                        <strong style="color: var(--danger-color);">$<?= number_format($e_item['expense'], 2) ?> USDT</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.5rem; font-size: 1.05rem;">
                        <strong>Net Profit:</strong>
                        <strong style="color: <?= $net >= 0 ? 'var(--usdt-primary)' : 'var(--danger-color)' ?>;">
                            $<?= number_format($net, 2) ?> USDT
                        </strong>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- System Audit Log Card -->
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> System Security & Activity Feed</div>
        <a href="/admin/transactions.php" class="btn btn-secondary btn-sm">Full Audit Logs</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action Logged</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i:s', strtotime($log['timestamp'])) ?></td>
                            <td><strong><?= htmlspecialchars($log['full_name'] ?? 'System') ?></strong></td>
                            <td><span class="badge <?= ($log['role'] ?? '') === 'admin' ? 'badge-admin' : 'badge-staff' ?>"><?= strtoupper($log['role'] ?? 'SYSTEM') ?></span></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                            <td><code><?= htmlspecialchars($log['ip_address']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
