<?php
// accounts.php - Chart of Accounts Management Module (Create, Edit, Delete for empty accounts)

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$error = '';
$success = '';

// Handle Delete Account Head
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $account_id = intval($_POST['account_id'] ?? 0);
        if ($account_id <= 0) {
            $error = 'Invalid account head selected.';
        } else {
            // Check if account is used in any journal_entry_lines
            $stmt_chk = $conn->prepare("SELECT COUNT(*) as line_count FROM journal_entry_lines WHERE account_id = ?");
            $stmt_chk->bind_param("i", $account_id);
            $stmt_chk->execute();
            $res_chk = $stmt_chk->get_result()->fetch_assoc();
            $stmt_chk->close();

            if ($res_chk['line_count'] > 0) {
                $error = 'Cannot delete account head because it has associated journal transactions. Account heads with transaction history cannot be removed.';
            } else {
                // Fetch account name for audit
                $stmt_name = $conn->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = ?");
                $stmt_name->bind_param("i", $account_id);
                $stmt_name->execute();
                $acc_info = $stmt_name->get_result()->fetch_assoc();
                $stmt_name->close();

                if ($acc_info) {
                    $stmt_del = $conn->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
                    $stmt_del->bind_param("i", $account_id);
                    if ($stmt_del->execute()) {
                        log_audit_action($user['id'], "Deleted empty Account Head: [{$acc_info['account_code']}] {$acc_info['account_name']}");
                        $success = "Account Head '{$acc_info['account_name']}' deleted successfully!";
                    } else {
                        $error = "Failed to delete account head.";
                    }
                    $stmt_del->close();
                } else {
                    $error = "Account head not found.";
                }
            }
        }
    }
}

// Handle Add or Edit Account Head
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save')) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $account_id = intval($_POST['account_id'] ?? 0);
        $account_code = sanitize_input($_POST['account_code'] ?? '');
        $account_name = sanitize_input($_POST['account_name'] ?? '');
        $account_type = sanitize_input($_POST['account_type'] ?? 'Expense');
        $currency_type = sanitize_input($_POST['currency_type'] ?? 'BOTH');

        if (empty($account_code) || empty($account_name)) {
            $error = 'Account Code and Account Name are required.';
        } else {
            // Check uniqueness of account_code (excluding current account_id if editing)
            if ($account_id > 0) {
                $stmt_chk = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? AND id != ?");
                $stmt_chk->bind_param("si", $account_code, $account_id);
            } else {
                $stmt_chk = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
                $stmt_chk->bind_param("s", $account_code);
            }
            $stmt_chk->execute();
            if ($stmt_chk->get_result()->num_rows > 0) {
                $error = 'Account Code already exists. Please choose a unique code.';
            } else {
                if ($account_id > 0) {
                    // Update existing account head
                    $stmt_upd = $conn->prepare("UPDATE chart_of_accounts SET account_code = ?, account_name = ?, account_type = ?, currency_type = ? WHERE id = ?");
                    $stmt_upd->bind_param("ssssi", $account_code, $account_name, $account_type, $currency_type, $account_id);
                    if ($stmt_upd->execute()) {
                        log_audit_action($user['id'], "Updated Account Head #$account_id: [$account_code] $account_name ($currency_type)");
                        $success = "Account Head '$account_name' updated successfully!";
                    } else {
                        $error = "Failed to update account head.";
                    }
                    $stmt_upd->close();
                } else {
                    // Insert new account head
                    $stmt_ins = $conn->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, currency_type, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt_ins->bind_param("ssss", $account_code, $account_name, $account_type, $currency_type);
                    if ($stmt_ins->execute()) {
                        log_audit_action($user['id'], "Created Ledger Account Head: [$account_code] $account_name ($currency_type)");
                        $success = "Account Head '$account_name' created successfully!";
                    } else {
                        $error = "Failed to create account head.";
                    }
                    $stmt_ins->close();
                }
            }
            $stmt_chk->close();
        }
    }
}

// Fetch edit target account if edit_id param provided
$edit_account = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $stmt_e = $conn->prepare("SELECT id, account_code, account_name, account_type, currency_type FROM chart_of_accounts WHERE id = ?");
    $stmt_e->bind_param("i", $edit_id);
    $stmt_e->execute();
    $edit_account = $stmt_e->get_result()->fetch_assoc();
    $stmt_e->close();
}

// Fetch All Accounts with transaction count
$query = "
    SELECT coa.id, coa.account_code, coa.account_name, coa.account_type, coa.currency_type, coa.created_at,
           COUNT(jel.id) as usage_count
    FROM chart_of_accounts coa
    LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
    GROUP BY coa.id
    ORDER BY coa.account_code ASC
";
$accounts = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

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
    <!-- Form to Add / Edit Account Head -->
    <div class="card" style="grid-column: span 1;">
        <div class="card-header">
            <div class="card-title">
                <?php if ($edit_account): ?>
                    <i class="fa-solid fa-pen-to-square"></i> Edit Account Head
                <?php else: ?>
                    <i class="fa-solid fa-square-plus"></i> Add Account Head
                <?php endif; ?>
            </div>
            <?php if ($edit_account): ?>
                <a href="/accounts.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-plus"></i> New</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="/accounts.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($edit_account): ?>
                    <input type="hidden" name="account_id" value="<?= $edit_account['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label" for="account_code">Account Code <span style="color:red;">*</span></label>
                    <input type="text" class="form-control" name="account_code" value="<?= htmlspecialchars($edit_account['account_code'] ?? '') ?>" placeholder="e.g. 1010, 4010, 5010" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="account_name">Account Name / Head <span style="color:red;">*</span></label>
                    <input type="text" class="form-control" name="account_name" value="<?= htmlspecialchars($edit_account['account_name'] ?? '') ?>" placeholder="e.g. Operating Cash, Sales Revenue" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="account_type">Account Type <span style="color:red;">*</span></label>
                    <select class="form-select" name="account_type" required>
                        <?php $types = ['Asset', 'Liability', 'Equity', 'Income', 'Expense']; ?>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t ?>" <?= ($edit_account['account_type'] ?? 'Expense') === $t ? 'selected' : '' ?>>
                                <?= $t === 'Income' ? 'Income / Revenue' : $t ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="currency_type">Currency Type Segregation <span style="color:red;">*</span></label>
                    <select class="form-select" name="currency_type" required>
                        <option value="INR" <?= ($edit_account['currency_type'] ?? '') === 'INR' ? 'selected' : '' ?>>INR Fiat Only</option>
                        <option value="USDT" <?= ($edit_account['currency_type'] ?? '') === 'USDT' ? 'selected' : '' ?>>USDT Crypto Only</option>
                        <option value="BOTH" <?= ($edit_account['currency_type'] ?? 'BOTH') === 'BOTH' ? 'selected' : '' ?>>BOTH (Universal)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $edit_account ? 'Update Account Head' : 'Save Account Head' ?>
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
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
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
                                    <td>
                                        <?php if ($acc['usage_count'] > 0): ?>
                                            <span class="badge badge-info" title="<?= $acc['usage_count'] ?> journal lines linked"><i class="fa-solid fa-lock"></i> Used (<?= $acc['usage_count'] ?>)</span>
                                        <?php else: ?>
                                            <span class="badge badge-success"><i class="fa-solid fa-leaf"></i> Empty</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.35rem;">
                                            <a href="/accounts.php?edit_id=<?= $acc['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Account Head">
                                                <i class="fa-solid fa-pen-to-square"></i> Edit
                                            </a>

                                            <?php if ($acc['usage_count'] == 0): ?>
                                                <form method="POST" action="/accounts.php" onsubmit="return confirm('Are you sure you want to delete account head \'<?= htmlspecialchars(addslashes($acc['account_name'])) ?>\'?');" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Empty Account Head">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-secondary btn-sm" disabled style="opacity: 0.5; cursor: not-allowed;" title="Cannot delete: Account head has transactions">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
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
