<?php
// admin/reports.php - Financial Reports Generator (Trial Balance & Profit & Loss)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_admin();
$conn = get_db_connection();

$report_type = sanitize_input($_GET['report_type'] ?? 'pnl'); // 'pnl' or 'trial_balance'
$selected_currency = sanitize_input($_GET['currency'] ?? 'INR');
if (!in_array($selected_currency, ['INR', 'USDT'])) {
    $selected_currency = 'INR';
}

$start_date = sanitize_input($_GET['start_date'] ?? '');
$end_date = sanitize_input($_GET['end_date'] ?? '');

$where_dates = "";
if (!empty($start_date)) {
    $where_dates .= " AND je.entry_date >= '" . $conn->real_escape_string($start_date) . "'";
}
if (!empty($end_date)) {
    $where_dates .= " AND je.entry_date <= '" . $conn->real_escape_string($end_date) . "'";
}

$page_title = "Financial Reports Generator ($selected_currency)";
$active_nav = "admin_reports";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Company Financial Reports Generator</div>
        <div style="display: flex; gap: 0.5rem;">
            <button onclick="exportTableToCSV('report_table', '<?= $report_type ?>_<?= $selected_currency ?>.csv')" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </button>
        </div>
    </div>
    <div class="card-body">

        <!-- Controls / Filters -->
        <form method="GET" action="/admin/reports.php" class="filter-bar">
            <div style="width: 200px;">
                <label class="form-label">Report Type</label>
                <select name="report_type" class="form-select">
                    <option value="pnl" <?= $report_type === 'pnl' ? 'selected' : '' ?>>Profit & Loss Statement</option>
                    <option value="trial_balance" <?= $report_type === 'trial_balance' ? 'selected' : '' ?>>Trial Balance</option>
                </select>
            </div>

            <div style="width: 180px;">
                <label class="form-label">Isolated Currency</label>
                <select name="currency" class="form-select">
                    <option value="INR" <?= $selected_currency === 'INR' ? 'selected' : '' ?>>INR Entities (Fiat)</option>
                    <option value="USDT" <?= $selected_currency === 'USDT' ? 'selected' : '' ?>>USDT Wings (Crypto)</option>
                </select>
            </div>

            <div style="width: 160px;">
                <label class="form-label">From Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>

            <div style="width: 160px;">
                <label class="form-label">To Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>

            <div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-chart-column"></i> Generate Report</button>
            </div>
        </form>

        <?php if ($report_type === 'pnl'): ?>
            <!-- ================= PROFIT & LOSS STATEMENT ================= -->
            <?php
            // Fetch Income Accounts
            $income_sql = "
                SELECT a.account_code, a.account_name,
                       COALESCE(SUM(l.credit - l.debit), 0) as total_amount
                FROM chart_of_accounts a
                LEFT JOIN journal_entry_lines l ON l.account_id = a.id
                LEFT JOIN journal_entries je ON l.journal_entry_id = je.id $where_dates
                WHERE a.account_type = 'Income'
                  AND (a.currency_type = '$selected_currency' OR a.currency_type = 'BOTH')
                  AND (je.currency = '$selected_currency' OR je.currency IS NULL)
                GROUP BY a.id, a.account_code, a.account_name
                HAVING total_amount <> 0
                ORDER BY a.account_code ASC
            ";
            $income_rows = $conn->query($income_sql)->fetch_all(MYSQLI_ASSOC);
            $total_income = array_sum(array_column($income_rows, 'total_amount'));

            // Fetch Expense Accounts
            $expense_sql = "
                SELECT a.account_code, a.account_name,
                       COALESCE(SUM(l.debit - l.credit), 0) as total_amount
                FROM chart_of_accounts a
                LEFT JOIN journal_entry_lines l ON l.account_id = a.id
                LEFT JOIN journal_entries je ON l.journal_entry_id = je.id $where_dates
                WHERE a.account_type = 'Expense'
                  AND (a.currency_type = '$selected_currency' OR a.currency_type = 'BOTH')
                  AND (je.currency = '$selected_currency' OR je.currency IS NULL)
                GROUP BY a.id, a.account_code, a.account_name
                HAVING total_amount <> 0
                ORDER BY a.account_code ASC
            ";
            $expense_rows = $conn->query($expense_sql)->fetch_all(MYSQLI_ASSOC);
            $total_expense = array_sum(array_column($expense_rows, 'total_amount'));

            $net_profit_loss = $total_income - $total_expense;
            $symbol = $selected_currency === 'INR' ? '₹' : '$';
            ?>

            <div style="margin-bottom: 1rem; text-align: center;">
                <h2>Profit & Loss Statement (<?= $selected_currency ?>)</h2>
                <p style="color: var(--text-muted);">
                    Iso-currency accounting for <?= $selected_currency === 'INR' ? 'Consultation & GST Services, Burfee Cart' : 'Optimus Infinity, Rayon Global Impact' ?>
                </p>
            </div>

            <div class="table-responsive">
                <table class="table" id="report_table">
                    <thead>
                        <tr style="background-color: var(--sidebar-bg); color: #fff;">
                            <th colspan="2">Account Details</th>
                            <th style="text-align: right;">Amount (<?= $selected_currency ?>)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- REVENUE CATEGORY -->
                        <tr style="background-color: #f1f5f9; font-weight: 700;">
                            <td colspan="3"><i class="fa-solid fa-arrow-trend-up" style="color: var(--inr-primary);"></i> REVENUES & INCOME</td>
                        </tr>
                        <?php if (empty($income_rows)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: var(--text-muted);">No revenue records for this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($income_rows as $inc): ?>
                                <tr>
                                    <td style="width: 120px;"><code><?= htmlspecialchars($inc['account_code']) ?></code></td>
                                    <td><?= htmlspecialchars($inc['account_name']) ?></td>
                                    <td style="text-align: right;"><?= $symbol ?><?= number_format($inc['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr style="font-weight: 700; background-color: #e2e8f0;">
                            <td colspan="2" style="text-align: right;">TOTAL REVENUE:</td>
                            <td style="text-align: right; color: var(--inr-primary);"><?= $symbol ?><?= number_format($total_income, 2) ?></td>
                        </tr>

                        <!-- EXPENSE CATEGORY -->
                        <tr style="background-color: #f1f5f9; font-weight: 700;">
                            <td colspan="3"><i class="fa-solid fa-arrow-trend-down" style="color: var(--danger-color);"></i> OPERATIONAL & DIRECT EXPENSES</td>
                        </tr>
                        <?php if (empty($expense_rows)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: var(--text-muted);">No expense records for this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expense_rows as $exp): ?>
                                <tr>
                                    <td style="width: 120px;"><code><?= htmlspecialchars($exp['account_code']) ?></code></td>
                                    <td><?= htmlspecialchars($exp['account_name']) ?></td>
                                    <td style="text-align: right;"><?= $symbol ?><?= number_format($exp['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr style="font-weight: 700; background-color: #e2e8f0;">
                            <td colspan="2" style="text-align: right;">TOTAL EXPENSES:</td>
                            <td style="text-align: right; color: var(--danger-color);"><?= $symbol ?><?= number_format($total_expense, 2) ?></td>
                        </tr>

                        <!-- NET PROFIT / LOSS -->
                        <tr style="font-weight: 800; font-size: 1.1rem; background-color: <?= $net_profit_loss >= 0 ? '#dcfce7' : '#fee2e2' ?>;">
                            <td colspan="2" style="text-align: right;">NET <?= $net_profit_loss >= 0 ? 'PROFIT (SURPLUS)' : 'LOSS (DEFICIT)' ?>:</td>
                            <td style="text-align: right; color: <?= $net_profit_loss >= 0 ? 'var(--inr-dark)' : 'var(--danger-color)' ?>;">
                                <?= $symbol ?><?= number_format($net_profit_loss, 2) ?> <?= $selected_currency ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- ================= TRIAL BALANCE ================= -->
            <?php
            $tb_sql = "
                SELECT a.account_code, a.account_name, a.account_type,
                       COALESCE(SUM(l.debit), 0) as total_debit,
                       COALESCE(SUM(l.credit), 0) as total_credit
                FROM chart_of_accounts a
                LEFT JOIN journal_entry_lines l ON l.account_id = a.id
                LEFT JOIN journal_entries je ON l.journal_entry_id = je.id $where_dates
                WHERE (a.currency_type = '$selected_currency' OR a.currency_type = 'BOTH')
                  AND (je.currency = '$selected_currency' OR je.currency IS NULL)
                GROUP BY a.id, a.account_code, a.account_name, a.account_type
                HAVING total_debit <> 0 OR total_credit <> 0
                ORDER BY a.account_code ASC
            ";
            $tb_rows = $conn->query($tb_sql)->fetch_all(MYSQLI_ASSOC);

            $sum_debits = array_sum(array_column($tb_rows, 'total_debit'));
            $sum_credits = array_sum(array_column($tb_rows, 'total_credit'));
            $is_balanced = abs($sum_debits - $sum_credits) < 0.001;
            ?>

            <div style="margin-bottom: 1rem; text-align: center;">
                <h2>Trial Balance Statement (<?= $selected_currency ?>)</h2>
                <p style="color: var(--text-muted);">Verifying Double-Entry Equation: Sum(Debits) = Sum(Credits)</p>
            </div>

            <?php if ($is_balanced): ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
                    <i class="fa-solid fa-scale-balanced"></i> <strong>Trial Balance Balanced!</strong> Total Debits equal Total Credits perfectly (<?= number_format($sum_debits, 2) ?> <?= $selected_currency ?>).
                </div>
            <?php else: ?>
                <div class="alert alert-danger" style="margin-bottom: 1rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <strong>Unbalanced Ledger Warning!</strong> Discrepancy detected between Debits and Credits.
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table" id="report_table">
                    <thead>
                        <tr style="background-color: var(--sidebar-bg); color: #fff;">
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th style="text-align: right;">Total Debit (Dr)</th>
                            <th style="text-align: right;">Total Credit (Cr)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tb_rows as $row): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($row['account_code']) ?></code></td>
                                <td><strong><?= htmlspecialchars($row['account_name']) ?></strong></td>
                                <td><span class="badge" style="background:#f1f5f9; color:var(--text-main);"><?= strtoupper($row['account_type']) ?></span></td>
                                <td style="text-align: right; color: var(--inr-dark);">
                                    <?= $row['total_debit'] > 0 ? number_format($row['total_debit'], 2) : '-' ?>
                                </td>
                                <td style="text-align: right; color: var(--usdt-dark);">
                                    <?= $row['total_credit'] > 0 ? number_format($row['total_credit'], 2) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: 800; font-size: 1.05rem; background-color: #e2e8f0;">
                            <td colspan="3" style="text-align: right;">TOTALS:</td>
                            <td style="text-align: right; color: var(--inr-dark);"><?= number_format($sum_debits, 2) ?> <?= $selected_currency ?></td>
                            <td style="text-align: right; color: var(--usdt-dark);"><?= number_format($sum_credits, 2) ?> <?= $selected_currency ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
