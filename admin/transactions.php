<?php
// admin/transactions.php - Company Transaction Audit Log with Edit and Delete capabilities

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_admin();
$conn = get_db_connection();

$error = '';
$success = '';

// Handle Delete Entry Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entry') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $delete_id = intval($_POST['entry_id'] ?? 0);
        if ($delete_id > 0) {
            $stmt_del = $conn->prepare("DELETE FROM journal_entries WHERE id = ?");
            $stmt_del->bind_param("i", $delete_id);
            if ($stmt_del->execute()) {
                log_audit_action($user['id'], "Admin Deleted Journal Entry #$delete_id");
                $success = "Journal Entry #$delete_id has been deleted permanently.";
            } else {
                $error = "Failed to delete transaction entry.";
            }
            $stmt_del->close();
        }
    }
}

// Filters
$entity_filter = intval($_GET['entity_id'] ?? 0);
$currency_filter = sanitize_input($_GET['currency'] ?? '');
$staff_filter = intval($_GET['staff_id'] ?? 0);
$start_date = sanitize_input($_GET['start_date'] ?? '');
$end_date = sanitize_input($_GET['end_date'] ?? '');
$search = sanitize_input($_GET['search'] ?? '');

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Entities & Users dropdown
$entities = $conn->query("SELECT id, name FROM entities ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$users_list = $conn->query("SELECT id, full_name FROM users ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// Where clause construction
$where = ["1=1"];

if ($entity_filter > 0) $where[] = "je.entity_id = " . $entity_filter;
if (!empty($currency_filter) && in_array($currency_filter, ['INR', 'USDT'])) $where[] = "je.currency = '" . $conn->real_escape_string($currency_filter) . "'";
if ($staff_filter > 0) $where[] = "je.created_by = " . $staff_filter;
if (!empty($start_date)) $where[] = "je.entry_date >= '" . $conn->real_escape_string($start_date) . "'";
if (!empty($end_date)) $where[] = "je.entry_date <= '" . $conn->real_escape_string($end_date) . "'";
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $where[] = "(je.reference_number LIKE '%$s%' OR je.description LIKE '%$s%')";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Count
$total_rows = $conn->query("SELECT COUNT(*) as cnt FROM journal_entries je $where_sql")->fetch_assoc()['cnt'];
$total_pages = ceil($total_rows / $limit);

// Query with line details
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

$entries = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$page_title = "Company Audit Log & Entries";
$active_nav = "admin_transactions";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Company-Wide Transaction Audit Log</div>
        <button onclick="exportTableToCSV('admin_tx_table', 'company_transactions_log.csv')" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
    </div>
    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Search & Filter Bar -->
        <form method="GET" action="/admin/transactions.php" class="filter-bar">
            <div style="flex: 1; min-width: 180px;">
                <label class="form-label">Keyword Search</label>
                <input type="text" name="search" class="form-control" placeholder="Reference # or Description..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div style="width: 180px;">
                <label class="form-label">Entity</label>
                <select name="entity_id" class="form-select">
                    <option value="">-- All Entities --</option>
                    <?php foreach ($entities as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $entity_filter == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 120px;">
                <label class="form-label">Currency</label>
                <select name="currency" class="form-select">
                    <option value="">-- All --</option>
                    <option value="INR" <?= $currency_filter === 'INR' ? 'selected' : '' ?>>INR</option>
                    <option value="USDT" <?= $currency_filter === 'USDT' ? 'selected' : '' ?>>USDT</option>
                </select>
            </div>

            <div style="width: 160px;">
                <label class="form-label">Staff Member</label>
                <select name="staff_id" class="form-select">
                    <option value="">-- All Staff --</option>
                    <?php foreach ($users_list as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $staff_filter == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 140px;">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>

            <div style="width: 140px;">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>

            <div>
                <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="/admin/transactions.php" class="btn btn-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table" id="admin_tx_table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Entry Date</th>
                        <th>Entity</th>
                        <th>Currency</th>
                        <th>Reference / TxID</th>
                        <th>Description</th>
                        <th>Logged By</th>
                        <th style="text-align: right;">Total Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                No transaction entries found matching filter conditions.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $je): ?>
                            <tr>
                                <td><strong>#<?= $je['id'] ?></strong></td>
                                <td><?= date('d M Y', strtotime($je['entry_date'])) ?></td>
                                <td><?= htmlspecialchars($je['entity_name']) ?></td>
                                <td>
                                    <span class="badge <?= $je['currency'] === 'INR' ? 'badge-inr' : 'badge-usdt' ?>">
                                        <?= $je['currency'] ?>
                                    </span>
                                </td>
                                <td><code><?= htmlspecialchars($je['reference_number'] ?: '-') ?></code></td>
                                <td><?= htmlspecialchars($je['description'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($je['created_by_name']) ?></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?= number_format($je['total_amount'], 2) ?> <?= $je['currency'] ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.3rem;">
                                        <button class="btn btn-secondary btn-sm" onclick="toggleLinesRow(<?= $je['id'] ?>)" title="View Lines">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>

                                        <a href="/staff/edit_transaction.php?id=<?= $je['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Entry">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>

                                        <form method="POST" action="/admin/transactions.php" onsubmit="return confirm('Are you sure you want to delete Journal Entry #<?= $je['id'] ?> permanently?');" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="action" value="delete_entry">
                                            <input type="hidden" name="entry_id" value="<?= $je['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete Entry">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <!-- Double Entry Lines Row Expansion -->
                            <tr id="lines_row_<?= $je['id'] ?>" style="display: none; background-color: #f1f5f9;">
                                <td colspan="9" style="padding: 1rem;">
                                    <?php
                                    $lines_stmt = $conn->prepare("
                                        SELECT l.debit, l.credit, a.account_code, a.account_name, a.account_type
                                        FROM journal_entry_lines l
                                        JOIN chart_of_accounts a ON l.account_id = a.id
                                        WHERE l.journal_entry_id = ?
                                    ");
                                    $lines_stmt->bind_param("i", $je['id']);
                                    $lines_stmt->execute();
                                    $lines = $lines_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $lines_stmt->close();
                                    ?>
                                    <div style="font-weight: 600; margin-bottom: 0.5rem; font-size: 0.85rem; color: var(--text-main);">
                                        <i class="fa-solid fa-scale-balanced"></i> Double Entry Breakdown for Entry #<?= $je['id'] ?>:
                                    </div>
                                    <table class="table" style="background: #fff; border-radius: 4px;">
                                        <thead>
                                            <tr style="background: #e2e8f0;">
                                                <th>Account Code</th>
                                                <th>Account Name</th>
                                                <th>Account Type</th>
                                                <th style="text-align: right;">Debit (Dr)</th>
                                                <th style="text-align: right;">Credit (Cr)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $tot_dr = 0; $tot_cr = 0;
                                            foreach ($lines as $line):
                                                $tot_dr += $line['debit'];
                                                $tot_cr += $line['credit'];
                                            ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars($line['account_code']) ?></code></td>
                                                    <td><?= htmlspecialchars($line['account_name']) ?></td>
                                                    <td><?= htmlspecialchars($line['account_type']) ?></td>
                                                    <td style="text-align: right; color: var(--inr-dark);">
                                                        <?= $line['debit'] > 0 ? number_format($line['debit'], 2) : '-' ?>
                                                    </td>
                                                    <td style="text-align: right; color: var(--usdt-dark);">
                                                        <?= $line['credit'] > 0 ? number_format($line['credit'], 2) : '-' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr style="font-weight: 700; background-color: #f8fafc;">
                                                <td colspan="3" style="text-align: right;">Balanced Total:</td>
                                                <td style="text-align: right; color: var(--inr-dark);"><?= number_format($tot_dr, 2) ?></td>
                                                <td style="text-align: right; color: var(--usdt-dark);"><?= number_format($tot_cr, 2) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="?page=<?= $p ?>&entity_id=<?= $entity_filter ?>&currency=<?= urlencode($currency_filter) ?>&staff_id=<?= $staff_filter ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&search=<?= urlencode($search) ?>"
                       class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleLinesRow(id) {
    const row = document.getElementById('lines_row_' + id);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
