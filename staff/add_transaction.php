<?php
// staff/add_transaction.php - Transaction Entry Form with Quick Entry and Standard Modes

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$error = '';
$success = '';

// Fetch active entities
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

// Helper to get or auto-create default payment asset account for currency
function get_or_create_default_payment_account($conn, $currency, $user_id) {
    // Search for existing Asset account for currency or BOTH
    $stmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_type = 'Asset' AND (currency_type = ? OR currency_type = 'BOTH') ORDER BY id ASC LIMIT 1");
    $stmt->bind_param("s", $currency);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return $row['id'];
    }
    $stmt->close();

    // Auto-create standard payment account head if none exists
    $code = ($currency === 'INR') ? '1010' : '1030';
    $name = ($currency === 'INR') ? 'Cash in Hand (Default)' : 'USDT Crypto Wallet (Default)';

    // Check if code exists, shift code if taken
    $stmt_c = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
    $stmt_c->bind_param("s", $code);
    $stmt_c->execute();
    if ($stmt_c->get_result()->num_rows > 0) {
        $code = strval(intval($code) + 1);
    }
    $stmt_c->close();

    $stmt_ins = $conn->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, currency_type, created_at) VALUES (?, ?, 'Asset', ?, NOW())");
    $stmt_ins->bind_param("sss", $code, $name, $currency);
    $stmt_ins->execute();
    $new_id = $conn->insert_id;
    $stmt_ins->close();

    log_audit_action($user_id, "Auto-created default payment asset account: [$code] $name");
    return $new_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $entry_mode = sanitize_input($_POST['entry_mode'] ?? 'quick');
        $entity_id = intval($_POST['entity_id'] ?? 0);

        // Fallback to TAX-India if no entity specified
        if (!$entity_id) {
            $stmt_default = $conn->prepare("SELECT id FROM entities WHERE name = 'TAX-India' AND status = 'active' LIMIT 1");
            $stmt_default->execute();
            if ($row_default = $stmt_default->get_result()->fetch_assoc()) {
                $entity_id = intval($row_default['id']);
            }
            $stmt_default->close();
        }

        $tx_type = sanitize_input($_POST['tx_type'] ?? 'income');
        $entry_date = sanitize_input($_POST['entry_date'] ?? date('Y-m-d'));
        $reference_number = sanitize_input($_POST['reference_number'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
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
        } elseif (!$category_account_id) {
            $error = 'Please select a Category / Account Head.';
        } else {
            $currency = $entity_data['default_currency'];
            $payment_account_id = 0;

            if ($entry_mode === 'quick') {
                // Quick Entry mode: auto-resolve payment account head
                $payment_account_id = get_or_create_default_payment_account($conn, $currency, $user['id']);
            } else {
                // Standard mode: user explicitly picked payment account
                $payment_account_id = intval($_POST['payment_account_id'] ?? 0);
            }

            if (!$payment_account_id) {
                $error = 'Payment / Liquid Asset Account could not be resolved.';
            } elseif ($payment_account_id === $category_account_id) {
                $error = 'Payment Account and Category Account cannot be identical.';
            } else {
                // Double Entry Mapping Logic:
                // Income: Debit Payment Asset (+Asset), Credit Revenue Account (+Income)
                // Expense: Debit Expense Account (+Expense), Credit Payment Asset (-Asset)
                $debit_account_id = 0;
                $credit_account_id = 0;

                if ($tx_type === 'income') {
                    $debit_account_id = $payment_account_id;   // Dr Cash/Bank/Wallet
                    $credit_account_id = $category_account_id; // Cr Income
                } else {
                    $debit_account_id = $category_account_id;  // Dr Expense/Liability
                    $credit_account_id = $payment_account_id;  // Cr Cash/Bank/Wallet
                }

                // Atomic Transaction
                $conn->begin_transaction();

                try {
                    // 1. Header
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

                    log_audit_action($user['id'], "Posted Entry #$journal_entry_id ($currency " . number_format($amount, 2) . ") for " . $entity_data['name']);
                    $success = "Transaction Entry #{$journal_entry_id} ($currency " . number_format($amount, 2) . ") saved successfully!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to save transaction: " . $e->getMessage();
                }
            }
        }
    }
}

// Re-fetch accounts for rendering
$accounts = [];
$res_acc = $conn->query("SELECT id, account_code, account_name, account_type, currency_type FROM chart_of_accounts ORDER BY account_code ASC");
while ($row = $res_acc->fetch_assoc()) {
    $accounts[] = $row;
}

$page_title = "Log Transaction";
$active_nav = "staff_add_tx";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.entry-mode-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 0.5rem;
}
.mode-tab {
    padding: 0.6rem 1.25rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    background-color: var(--bg-light);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
}
.mode-tab.active {
    background-color: var(--primary-color);
    color: #ffffff;
    border-color: var(--primary-color);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.quick-badge {
    background: #e0e7ff;
    color: #3730a3;
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 12px;
    margin-left: 0.35rem;
}
</style>

<div class="card" id="tx_form_card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-bolt" style="color: var(--crypto-gold);"></i> Quick Transaction Entry
        </div>
        <span class="badge badge-info" id="currency_indicator_badge">
            Entity Currency: <strong id="currency_display">Select Entity</strong>
        </span>
    </div>

    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Mode Toggle Tabs -->
        <div class="entry-mode-tabs">
            <div class="mode-tab active" id="tab_quick" onclick="setEntryMode('quick')">
                <i class="fa-solid fa-bolt"></i> Quick Entry <span class="quick-badge">Fast & Easy</span>
            </div>
            <div class="mode-tab" id="tab_advanced" onclick="setEntryMode('advanced')">
                <i class="fa-solid fa-sliders"></i> Standard / Advanced Entry
            </div>
        </div>

        <form method="POST" action="/staff/add_transaction.php" id="tx_form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="entry_mode" id="entry_mode_input" value="quick">

            <!-- Entity & Type -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="entity_id">Business Unit / Entity (Default: TAX-India)</label>
                    <select class="form-select" id="entity_id" name="entity_id" onchange="updateEntityCurrency()">
                        <option value="">-- Select Business Entity --</option>
                        <?php foreach ($entities as $ent): ?>
                            <?php $is_tax_india = ($ent['name'] === 'TAX-India'); ?>
                            <option value="<?= $ent['id'] ?>" data-currency="<?= $ent['default_currency'] ?>" <?= $is_tax_india ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ent['name']) ?> (<?= $ent['default_currency'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="tx_type">Transaction Type <span style="color:red;">*</span></label>
                    <select class="form-select" id="tx_type" name="tx_type" onchange="filterCategoryAccounts()" required>
                        <option value="income">Receive Income (Revenue)</option>
                        <option value="expense">Pay Expense / Payout</option>
                    </select>
                </div>
            </div>

            <!-- Amount & Account Head -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="amount">Amount <span style="color:red;">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control" id="amount" name="amount" placeholder="e.g. 500.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="category_account_id">Category / Account Head <span style="color:red;">*</span></label>
                    <select class="form-select" id="category_account_id" name="category_account_id" required>
                        <option value="">-- Select Account Head --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <?php if ($acc['account_type'] !== 'Asset'): ?>
                                <option value="<?= $acc['id'] ?>" data-type="<?= $acc['account_type'] ?>" data-currency="<?= $acc['currency_type'] ?>">
                                    [<?= htmlspecialchars($acc['account_code']) ?>] <?= htmlspecialchars($acc['account_name']) ?> (<?= $acc['account_type'] ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Standard Mode Only: Explicit Payment Liquid Account & Date/Ref -->
            <div id="advanced_fields" style="display: none; padding-top: 0.5rem; border-top: 1px dashed var(--border-color); margin-top: 0.5rem;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="payment_account_id">Payment Asset Account (Cash / Bank / Wallet)</label>
                        <select class="form-select" id="payment_account_id" name="payment_account_id">
                            <option value="">-- Select Cash/Bank/Wallet Account --</option>
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
                        <label class="form-label" for="entry_date">Date</label>
                        <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>

            <!-- Description / Reference -->
            <div class="form-row" style="margin-top: 0.5rem;">
                <div class="form-group">
                    <label class="form-label" for="reference_number">Reference / TxID / Invoice #</label>
                    <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Optional ref # or TxHash">
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Note / Description</label>
                    <input type="text" class="form-control" id="description" name="description" placeholder="Short description of entry...">
                </div>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <a href="/staff/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" id="submit_btn">
                    <i class="fa-solid fa-check"></i> Save Transaction Entry
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function setEntryMode(mode) {
    document.getElementById('entry_mode_input').value = mode;
    const tabQuick = document.getElementById('tab_quick');
    const tabAdvanced = document.getElementById('tab_advanced');
    const advFields = document.getElementById('advanced_fields');

    if (mode === 'quick') {
        tabQuick.classList.add('active');
        tabAdvanced.classList.remove('active');
        advFields.style.display = 'none';
    } else {
        tabAdvanced.classList.add('active');
        tabQuick.classList.remove('active');
        advFields.style.display = 'block';
    }
}

function updateEntityCurrency() {
    const select = document.getElementById('entity_id');
    const selectedOption = select.options[select.selectedIndex];
    const currency = selectedOption.getAttribute('data-currency');
    const display = document.getElementById('currency_display');

    if (currency) {
        display.textContent = currency;
    } else {
        display.textContent = 'Select Entity';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateEntityCurrency();
});

function filterCategoryAccounts() {
    const txType = document.getElementById('tx_type').value;
    const categorySelect = document.getElementById('category_account_id');
    const options = categorySelect.options;

    for (let i = 1; i < options.length; i++) {
        const accType = options[i].getAttribute('data-type');
        if (txType === 'income' && accType === 'Expense') {
            options[i].style.display = 'none';
        } else if (txType === 'expense' && accType === 'Income') {
            options[i].style.display = 'none';
        } else {
            options[i].style.display = 'block';
        }
    }
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
