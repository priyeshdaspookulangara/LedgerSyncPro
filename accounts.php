<?php
// accounts.php - Chart of Accounts Management Module (Accessible to both Admin & Staff)

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$error = '';
$success = '';

// Add New Account Head
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $account_code = sanitize_input($_POST['account_code'] ?? '');
        $account_name = sanitize_input($_POST['account_name'] ?? '');
        $account_type = sanitize_input($_POST['account_type'] ?? 'Expense');
        $currency_type = sanitize_input($_POST['currency_type'] ?? 'BOTH');

        if (empty($account_code) || empty($account_name)) {
            $error = 'Account Code and Account Name are required.';
        } else {
            // Check uniqueness of code
            $stmt_chk = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
            $stmt_chk->bind_param("s", $account_code);
            $stmt_chk->execute();
            if ($stmt_chk->get_result()->num_rows > 0) {
                $error = 'Account Code already exists. Please choose a unique code.';
            } else {
                $stmt_ins = $conn->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, currency_type, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_ins->bind_param("ssss", $account_code, $account_name, $account_type, $currency_type);
                if ($stmt_ins->execute()) {
                    log_audit_action($user['id'], "Created Ledger Account: [$account_code] $account_name ($currency_type)");
                    $success = "Ledger Account Head '$account_name' added successfully!";
                } else {
                    $error = "Failed to add ledger account head.";
                }
                $stmt_ins->close();
            }
            $stmt_chk->close();
        }
    }
}

// Fetch All Accounts
$accounts = $conn->query("SELECT id, account_code, account_name, account_type, currency_type, created_at FROM chart_of_accounts ORDER BY account_code ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = "Chart of Accounts";
$active_nav = "accounts";
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="grid-3">
    <!-- Form to Add New Account Head -->
    <div class="card" style="grid-column: span 1;">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-square-plus"></i> Add Account Head</div>
        </div>
        <div class="card-body">
            <form method="POST" action="/accounts.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="form-group">
                    <label class="form-label" for="account_code">Account Code <span style="color:red;">*</span></label>
                    <input type="text" class="form-control" name="account_code" placeholder="e.g. 1010, 4010, 5010" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="account_name">Account Name / Head <span style="color:red;">*</span></label>
                    <input type="text" class="form-control" name="account_name" placeholder="e.g. Operating Cash, Sales Revenue" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="account_type">Account Type <span style="color:red;">*</span></label>
                    <select class="form-select" name="account_type" required>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                        <option value="Income">Income / Revenue</option>
                        <option value="Expense" selected>Expense</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="currency_type">Currency Type Segregation <span style="color:red;">*</span></label>
                    <select class="form-select" name="currency_type" required>
                        <option value="INR">INR Fiat Only</option>
                        <option value="USDT">USDT Crypto Only</option>
                        <option value="BOTH" selected>BOTH (Universal)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Account Head
                </button>
            </form>
        </div>
    </div>

    <!-- Chart of Accounts Directory Table -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-sitemap"></i> Chart of Accounts Ledger</div>
            <button onclick="exportTableToCSV('coa_table', 'chart_of_accounts.csv')" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </button>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table" id="coa_table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Account Name</th>
                            <th>Account Type</th>
                            <th>Currency Scope</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                    No account heads defined yet. Use the form on the left to add account heads.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $acc): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($acc['account_code']) ?></code></td>
                                    <td><strong><?= htmlspecialchars($acc['account_name']) ?></strong></td>
                                    <td>
                                        <span class="badge" style="background-color: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color);">
                                            <?= strtoupper($acc['account_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($acc['currency_type'] === 'INR'): ?>
                                            <span class="badge badge-inr">INR ONLY</span>
                                        <?php elseif ($acc['currency_type'] === 'USDT'): ?>
                                            <span class="badge badge-usdt">USDT ONLY</span>
                                        <?php else: ?>
                                            <span class="badge" style="background-color: #e2e8f0; color: #334155;">INR & USDT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d M Y', strtotime($acc['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
