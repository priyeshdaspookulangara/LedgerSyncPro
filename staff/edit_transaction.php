<?php
// staff/edit_transaction.php - Edit Existing Journal Entry

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$entry_id = intval($_GET['id'] ?? $_POST['entry_id'] ?? 0);
$error = '';
$success = '';

if (!$entry_id) {
    header("Location: /staff/index.php?error=" . urlencode("Invalid transaction entry ID."));
    exit;
}

// Fetch transaction entry
$stmt = $conn->prepare("
    SELECT je.id, je.entity_id, je.entry_date, je.currency, je.reference_number, je.description, je.created_by,
           e.name AS entity_name
    FROM journal_entries je
    JOIN entities e ON je.entity_id = e.id
    WHERE je.id = ?
");
$stmt->bind_param("i", $entry_id);
$stmt->execute();
$je_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$je_data) {
    header("Location: /staff/index.php?error=" . urlencode("Journal entry not found."));
    exit;
}

// RBAC Check: Staff can only edit their own entries unless Admin
if ($user['role'] !== 'admin' && intval($je_data['created_by']) !== intval($user['id'])) {
    header("Location: /staff/index.php?error=" . urlencode("Access denied: You can only edit entries created by yourself."));
    exit;
}

// Fetch current journal lines
$stmt_lines = $conn->prepare("
    SELECT l.id, l.account_id, l.debit, l.credit, a.account_type, a.account_name, a.account_code
    FROM journal_entry_lines l
    JOIN chart_of_accounts a ON l.account_id = a.id
    WHERE l.journal_entry_id = ?
");
$stmt_lines->bind_param("i", $entry_id);
$stmt_lines->execute();
$current_lines = $stmt_lines->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_lines->close();

// Identify Payment Account (Asset) and Category Account
$current_payment_account_id = 0;
$current_category_account_id = 0;
$current_amount = 0.0;
$current_tx_type = 'income';

foreach ($current_lines as $l) {
    if ($l['debit'] > 0) {
        $current_amount = floatval($l['debit']);
    } elseif ($l['credit'] > 0) {
        $current_amount = floatval($l['credit']);
    }

    if ($l['account_type'] === 'Asset') {
        $current_payment_account_id = $l['account_id'];
        if ($l['debit'] > 0) {
            $current_tx_type = 'income'; // +Asset is Debit
        } else {
            $current_tx_type = 'expense'; // -Asset is Credit
        }
    } else {
        $current_category_account_id = $l['account_id'];
    }
}

// Fetch entities & chart of accounts
$entities = $conn->query("SELECT id, name, default_currency FROM entities WHERE status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$accounts = $conn->query("SELECT id, account_code, account_name, account_type, currency_type FROM chart_of_accounts ORDER BY account_code ASC")->fetch_all(MYSQLI_ASSOC);

// Handle POST Edit Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $entity_id = intval($_POST['entity_id'] ?? 0);
        $tx_type = sanitize_input($_POST['tx_type'] ?? 'income');
        $entry_date = sanitize_input($_POST['entry_date'] ?? date('Y-m-d'));
        $reference_number = sanitize_input($_POST['reference_number'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_account_id = intval($_POST['payment_account_id'] ?? 0);
        $category_account_id = intval($_POST['category_account_id'] ?? 0);

        // Fetch selected entity
        $currency = sanitize_input($_POST['currency'] ?? 'INR');
        if (!in_array($currency, ['INR', 'USDT'])) {
            $currency = 'INR';
        }

        $stmt_e = $conn->prepare("SELECT name, default_currency FROM entities WHERE id = ? AND status = 'active'");
        $stmt_e->bind_param("i", $entity_id);
        $stmt_e->execute();
        $entity_info = $stmt_e->get_result()->fetch_assoc();
        $stmt_e->close();

        if (!$entity_info) {
            $error = 'Invalid business entity selected.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } elseif (!$payment_account_id || !$category_account_id) {
            $error = 'Please select both Payment Account and Category Account.';
        } elseif ($payment_account_id === $category_account_id) {
            $error = 'Payment Account and Category Account cannot be identical.';
        } else {

            $debit_account_id = 0;
            $credit_account_id = 0;

            if ($tx_type === 'income') {
                $debit_account_id = $payment_account_id;
                $credit_account_id = $category_account_id;
            } elseif ($tx_type === 'expense' || $tx_type === 'mlm_payout' || $tx_type === 'leader_transfer') {
                $debit_account_id = $category_account_id;
                $credit_account_id = $payment_account_id;
            }

            // Execute Atomic Update
            $conn->begin_transaction();
            try {
                // 1. Update Journal Entry Header
                $stmt_u = $conn->prepare("UPDATE journal_entries SET entity_id = ?, entry_date = ?, currency = ?, reference_number = ?, description = ? WHERE id = ?");
                $stmt_u->bind_param("issssi", $entity_id, $entry_date, $currency, $reference_number, $description, $entry_id);
                $stmt_u->execute();
                $stmt_u->close();

                // 2. Delete old lines
                $stmt_d = $conn->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
                $stmt_d->bind_param("i", $entry_id);
                $stmt_d->execute();
                $stmt_d->close();

                // 3. Re-insert Debit Line
                $stmt_dr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0.0000)");
                $stmt_dr->bind_param("iid", $entry_id, $debit_account_id, $amount);
                $stmt_dr->execute();
                $stmt_dr->close();

                // 4. Re-insert Credit Line
                $stmt_cr = $conn->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0.0000, ?)");
                $stmt_cr->bind_param("iid", $entry_id, $credit_account_id, $amount);
                $stmt_cr->execute();
                $stmt_cr->close();

                $conn->commit();

                log_audit_action($user['id'], "Updated Journal Entry #$entry_id ($currency $amount)");
                $success = "Journal Entry #$entry_id updated successfully!";

                // Update in-memory values for form display
                $je_data['entity_id'] = $entity_id;
                $je_data['entry_date'] = $entry_date;
                $je_data['currency'] = $currency;
                $je_data['reference_number'] = $reference_number;
                $je_data['description'] = $description;
                $current_payment_account_id = $payment_account_id;
                $current_category_account_id = $category_account_id;
                $current_amount = $amount;
                $current_tx_type = $tx_type;

            } catch (Exception $ex) {
                $conn->rollback();
                $error = "Failed to update transaction entry: " . $ex->getMessage();
            }
        }
    }
}

$page_title = "Edit Journal Entry #" . $je_data['id'];
$active_nav = "staff_dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" id="tx_form_card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-pen-to-square"></i> Edit Journal Entry #<?= $je_data['id'] ?>
        </div>
        <span class="badge badge-info" id="currency_indicator_badge">
            Currency: <strong id="currency_display"><?= $je_data['currency'] ?></strong>
        </span>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/staff/edit_transaction.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="entry_id" value="<?= $je_data['id'] ?>">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="currency_select">Currency <span style="color:red;">*</span></label>
                    <select class="form-select" id="currency_select" name="currency" required>
                        <option value="INR" <?= $je_data['currency'] === 'INR' ? 'selected' : '' ?>>₹ INR - Indian Rupee (Fiat)</option>
                        <option value="USDT" <?= $je_data['currency'] === 'USDT' ? 'selected' : '' ?>>$ USDT - Tether (Crypto)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="entity_id">Business Unit / Entity <span style="color:red;">*</span></label>
                    <select class="form-select" id="entity_id" name="entity_id" required>
                        <?php foreach ($entities as $ent): ?>
                            <option value="<?= $ent['id'] ?>" <?= $je_data['entity_id'] == $ent['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ent['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="tx_type">Transaction Type <span style="color:red;">*</span></label>
                    <select class="form-select" id="tx_type" name="tx_type" required>
                        <option value="income" <?= $current_tx_type === 'income' ? 'selected' : '' ?>>Receive Income (Revenue)</option>
                        <option value="expense" <?= $current_tx_type === 'expense' ? 'selected' : '' ?>>Pay Expense (Operational)</option>
                        <option value="mlm_payout" <?= $current_tx_type === 'mlm_payout' ? 'selected' : '' ?>>MLM Leader Payout</option>
                        <option value="leader_transfer" <?= $current_tx_type === 'leader_transfer' ? 'selected' : '' ?>>Leader Transfer / Deposit</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="entry_date">Transaction Date <span style="color:red;">*</span></label>
                    <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= htmlspecialchars($je_data['entry_date']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reference_number">Reference / TxID / Receipt #</label>
                    <input type="text" class="form-control" id="reference_number" name="reference_number" value="<?= htmlspecialchars($je_data['reference_number'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="amount">Amount <span style="color:red;">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control" id="amount" name="amount" value="<?= htmlspecialchars($current_amount) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="payment_account_id">Payment / Liquid Account <span style="color:red;">*</span></label>
                    <select class="form-select" id="payment_account_id" name="payment_account_id" required>
                        <?php foreach ($accounts as $acc): ?>
                            <?php if ($acc['account_type'] === 'Asset'): ?>
                                <option value="<?= $acc['id'] ?>" data-currency="<?= $acc['currency_type'] ?>" <?= $current_payment_account_id == $acc['id'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($acc['account_code']) ?>] <?= htmlspecialchars($acc['account_name']) ?> (<?= $acc['currency_type'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="category_account_id">Category Account <span style="color:red;">*</span></label>
                    <select class="form-select" id="category_account_id" name="category_account_id" required>
                        <?php foreach ($accounts as $acc): ?>
                            <?php if ($acc['account_type'] !== 'Asset'): ?>
                                <option value="<?= $acc['id'] ?>" data-currency="<?= $acc['currency_type'] ?>" <?= $current_category_account_id == $acc['id'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($acc['account_code']) ?>] <?= htmlspecialchars($acc['account_name']) ?> (<?= $acc['account_type'] ?> - <?= $acc['currency_type'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Transaction Description / Notes</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($je_data['description'] ?? '') ?></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <a href="<?= $user['role'] === 'admin' ? '/admin/transactions.php' : '/staff/index.php' ?>" class="btn btn-secondary">Back</a>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Update Journal Entry</button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
