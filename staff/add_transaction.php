<?php
// staff/add_transaction.php - Transaction Entry Form with Double-Entry Posting

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$error = '';
$success = '';

// Fetch entities
$entities = [];
$res_entities = $conn->query("SELECT id, name, default_currency FROM entities WHERE status = 'active' ORDER BY name ASC");
while ($row = $res_entities->fetch_assoc()) {
    $entities[] = $row;
}

// Fetch Chart of Accounts
$accounts = [];
$res_acc = $conn->query("SELECT id, account_code, account_name, account_type, currency_type FROM chart_of_accounts ORDER BY account_code ASC");
while ($row = $res_acc->fetch_assoc()) {
    $accounts[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $entity_id = intval($_POST['entity_id'] ?? 0);
        $tx_type = sanitize_input($_POST['tx_type'] ?? '');
        $entry_date = sanitize_input($_POST['entry_date'] ?? date('Y-m-d'));
        $reference_number = sanitize_input($_POST['reference_number'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_account_id = intval($_POST['payment_account_id'] ?? 0);
        $category_account_id = intval($_POST['category_account_id'] ?? 0);

        // Fetch selected entity default currency
        $stmt_e = $conn->prepare("SELECT name, default_currency FROM entities WHERE id = ? AND status = 'active'");
        $stmt_e->bind_param("i", $entity_id);
        $stmt_e->execute();
        $entity_data = $stmt_e->get_result()->fetch_assoc();
        $stmt_e->close();

        if (!$entity_data) {
            $error = 'Invalid or inactive business entity selected.';
        } elseif ($amount <= 0) {
            $error = 'Transaction amount must be greater than zero.';
        } elseif (!$payment_account_id || !$category_account_id) {
            $error = 'Please select both Payment Account and Category Account.';
        } elseif ($payment_account_id === $category_account_id) {
            $error = 'Payment Account and Category Account cannot be identical.';
        } else {
            $currency = $entity_data['default_currency'];

            // Double Entry Mapping Logic:
            // Receive Income: Debit Payment/Cash Asset (+Asset), Credit Revenue/Income Account (+Income)
            // Pay Expense: Debit Expense Account (+Expense), Credit Payment/Cash Asset (-Asset)
            // MLM Payout: Debit Expense/Liability Account, Credit Payment/Wallet Account
            // Leader Transfer: Debit Asset/Liability Account, Credit Payment/Wallet Account

            $debit_account_id = 0;
            $credit_account_id = 0;

            if ($tx_type === 'income') {
                $debit_account_id = $payment_account_id;   // e.g. Bank/Cash/Wallet (Asset Dr)
                $credit_account_id = $category_account_id; // e.g. Service Revenue (Income Cr)
            } elseif ($tx_type === 'expense' || $tx_type === 'mlm_payout') {
                $debit_account_id = $category_account_id;  // e.g. Operational Expense (Expense Dr)
                $credit_account_id = $payment_account_id;  // e.g. Bank/Cash/Wallet (Asset Cr)
            } elseif ($tx_type === 'leader_transfer') {
                $debit_account_id = $category_account_id;  // Dr receiving liability/asset
                $credit_account_id = $payment_account_id;  // Cr paying wallet
            } else {
                $error = 'Invalid transaction type selected.';
            }

            if (!$error) {
                // Begin Transaction for Atomic Double Entry Creation
                $conn->begin_transaction();

                try {
                    // 1. Insert into journal_entries
                    $stmt_je = $conn->prepare("INSERT INTO journal_entries (entity_id, entry_date, currency, reference_number, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt_je->bind_param("issssi", $entity_id, $entry_date, $currency, $reference_number, $description, $user['id']);
                    $stmt_je->execute();
                    $journal_entry_id = $conn->insert_id;
                    $stmt_je->close();

                    // 2. Insert Debit Line
                    $stmt_line_dr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0.0000)");
                    $stmt_line_dr->bind_param("iid", $journal_entry_id, $debit_account_id, $amount);
                    $stmt_line_dr->execute();
                    $stmt_line_dr->close();

                    // 3. Insert Credit Line
                    $stmt_line_cr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0.0000, ?)");
                    $stmt_line_cr->bind_param("iid", $journal_entry_id, $credit_account_id, $amount);
                    $stmt_line_cr->execute();
                    $stmt_line_cr->close();

                    // Commit Transaction
                    $conn->commit();

                    log_audit_action($user['id'], "Posted Journal Entry #$journal_entry_id ($currency $amount) for Entity: " . $entity_data['name']);
                    $success = "Journal Entry #{$journal_entry_id} ($currency " . number_format($amount, 2) . ") posted successfully with balanced double-entry lines!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to post transaction: " . $e->getMessage();
                }
            }
        }
    }
}

$page_title = "New Transaction Entry";
$active_nav = "staff_add_tx";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" id="tx_form_card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-pen-to-square"></i> Log Transaction / Journal Entry
        </div>
        <span class="badge badge-info" id="currency_indicator_badge">
            Selected Currency: <strong id="currency_display">INR / USDT</strong>
        </span>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/staff/add_transaction.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="currency" id="currency_input" value="">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="entity_id">Business Unit / Entity <span style="color:red;">*</span></label>
                    <select class="form-select" id="entity_id" name="entity_id" required>
                        <option value="">-- Select Business Enterprise --</option>
                        <?php foreach ($entities as $ent): ?>
                            <option value="<?= $ent['id'] ?>" data-currency="<?= $ent['default_currency'] ?>">
                                <?= htmlspecialchars($ent['name']) ?> (<?= $ent['default_currency'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">Selecting an entity locks the transaction currency automatically.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="tx_type">Transaction Type <span style="color:red;">*</span></label>
                    <select class="form-select" id="tx_type" name="tx_type" required>
                        <option value="income">Receive Income (Revenue)</option>
                        <option value="expense">Pay Expense (Operational)</option>
                        <option value="mlm_payout">MLM Leader Payout</option>
                        <option value="leader_transfer">Leader Transfer / Deposit</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="entry_date">Transaction Date <span style="color:red;">*</span></label>
                    <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reference_number">Reference / TxID / Receipt #</label>
                    <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="e.g. GST-1092, TxHash 0xabc...">
                </div>

                <div class="form-group">
                    <label class="form-label" for="amount">Amount <span style="color:red;">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control" id="amount" name="amount" placeholder="0.00" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="payment_account_id">Payment / Liquid Account (Cash / Bank / Wallet) <span style="color:red;">*</span></label>
                    <select class="form-select" id="payment_account_id" name="payment_account_id" required>
                        <option value="">-- Select Payment Method Account --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <?php if ($acc['account_type'] === 'Asset'): ?>
                                <option value="<?= $acc['id'] ?>" data-currency="<?= $acc['currency_type'] ?>">
                                    [<?= htmlspecialchars($acc['account_code']) ?>] <?= htmlspecialchars($acc['account_name']) ?> (<?= $acc['currency_type'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="category_account_id">Category Account (Income / Expense / Liability) <span style="color:red;">*</span></label>
                    <select class="form-select" id="category_account_id" name="category_account_id" required>
                        <option value="">-- Select Revenue/Expense/Liability Account --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <?php if ($acc['account_type'] !== 'Asset'): ?>
                                <option value="<?= $acc['id'] ?>" data-currency="<?= $acc['currency_type'] ?>">
                                    [<?= htmlspecialchars($acc['account_code']) ?>] <?= htmlspecialchars($acc['account_name']) ?> (<?= $acc['account_type'] ?> - <?= $acc['currency_type'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Transaction Description / Notes</label>
                <textarea class="form-control" id="description" name="description" rows="3" placeholder="Provide details regarding this journal entry..."></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <a href="/staff/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save & Post Entry</button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
