<?php
// staff/index.php - Staff Portal & My Submitted Journal Entries

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$conn = get_db_connection();

$error = '';
$success = $_GET['success'] ?? '';
if (isset($_GET['error'])) {
    $error = sanitize_input($_GET['error']);
}

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entry') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $delete_id = intval($_POST['entry_id'] ?? 0);

        // Fetch entry
        $stmt_chk = $conn->prepare("SELECT id, created_by, currency FROM journal_entries WHERE id = ?");
        $stmt_chk->bind_param("i", $delete_id);
        $stmt_chk->execute();
        $entry_row = $stmt_chk->get_result()->fetch_assoc();
        $stmt_chk->close();

        if (!$entry_row) {
            $error = 'Transaction entry not found.';
        } elseif ($user['role'] !== 'admin' && intval($entry_row['created_by']) !== intval($user['id'])) {
            $error = 'Access denied: You can only delete entries created by yourself.';
        } else {
            // Delete entry (Cascades to journal_entry_lines in DB foreign key)
            $stmt_del = $conn->prepare("DELETE FROM journal_entries WHERE id = ?");
            $stmt_del->bind_param("i", $delete_id);
            if ($stmt_del->execute()) {
                log_audit_action($user['id'], "Deleted Journal Entry #$delete_id");
                $success = "Journal Entry #$delete_id deleted successfully.";
            } else {
                $error = "Failed to delete entry.";
            }
            $stmt_del->close();
        }
    }
}

// Pagination & Filtering Params
$entity_filter = intval($_GET['entity_id'] ?? 0);
$currency_filter = sanitize_input($_GET['currency'] ?? '');
$search = sanitize_input($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Fetch entities for filter dropdown
$entities = $conn->query("SELECT id, name, default_currency FROM entities WHERE status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Build query
$where_clauses = ["je.created_by = " . intval($user['id'])];

if ($entity_filter > 0) {
    $where_clauses[] = "je.entity_id = " . $entity_filter;
}
if (!empty($currency_filter) && in_array($currency_filter, ['INR', 'USDT'])) {
    $where_clauses[] = "je.currency = '" . $conn->real_escape_string($currency_filter) . "'";
}
if (!empty($search)) {
    $escaped = $conn->real_escape_string($search);
    $where_clauses[] = "(je.reference_number LIKE '%$escaped%' OR je.description LIKE '%$escaped%')";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Count Total Entries
$total_rows = $conn->query("SELECT COUNT(*) as total FROM journal_entries je $where_sql")->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch Entries
$sql = "SELECT je.id, je.entry_date, je.currency, je.reference_number, je.description, je.created_at,
               e.name AS entity_name,
               (SELECT SUM(debit) FROM journal_entry_lines WHERE journal_entry_id = je.id) AS total_amount
        FROM journal_entries je
        JOIN entities e ON je.entity_id = e.id
        $where_sql
        ORDER BY je.id DESC
        LIMIT $limit OFFSET $offset";

$transactions = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$page_title = "My Submitted Entries";
$active_nav = "staff_dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-list-check"></i> My Journal Entries Log
        </div>
        <a href="/staff/add_transaction.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> New Transaction
        </a>
    </div>
    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Search & Filter Bar -->
        <form method="GET" action="/staff/index.php" class="filter-bar">
            <div style="flex: 1; min-width: 200px;">
                <label class="form-label">Search Keyword</label>
                <input type="text" name="search" class="form-control" placeholder="Search reference # or description..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div style="width: 200px;">
                <label class="form-label">Business Unit</label>
                <select name="entity_id" class="form-select">
                    <option value="">-- All Entities --</option>
                    <?php foreach ($entities as $ent): ?>
                        <option value="<?= $ent['id'] ?>" <?= $entity_filter == $ent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ent['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 150px;">
                <label class="form-label">Currency</label>
                <select name="currency" class="form-select">
                    <option value="">-- All --</option>
                    <option value="INR" <?= $currency_filter === 'INR' ? 'selected' : '' ?>>INR</option>
                    <option value="USDT" <?= $currency_filter === 'USDT' ? 'selected' : '' ?>>USDT</option>
                </select>
            </div>

            <div>
                <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="/staff/index.php" class="btn btn-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table" id="staff_tx_table">
                <thead>
                    <tr>
                        <th>Entry ID</th>
                        <th>Date</th>
                        <th>Business Enterprise</th>
                        <th>Currency</th>
                        <th>Reference / TxID</th>
                        <th>Description</th>
                        <th style="text-align: right;">Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                No transaction entries found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><strong>#<?= $tx['id'] ?></strong></td>
                                <td><?= date('d M Y', strtotime($tx['entry_date'])) ?></td>
                                <td><?= htmlspecialchars($tx['entity_name']) ?></td>
                                <td>
                                    <span class="badge <?= $tx['currency'] === 'INR' ? 'badge-inr' : 'badge-usdt' ?>">
                                        <?= $tx['currency'] ?>
                                    </span>
                                </td>
                                <td><code><?= htmlspecialchars($tx['reference_number'] ?: '-') ?></code></td>
                                <td><?= htmlspecialchars($tx['description'] ?: '-') ?></td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?= number_format($tx['total_amount'], 2) ?> <?= $tx['currency'] ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.3rem; justify-content: flex-end;">
                                        <a href="/staff/edit_transaction.php?id=<?= $tx['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Entry">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>

                                        <form method="POST" action="/staff/index.php" onsubmit="return confirm('Are you sure you want to delete Journal Entry #<?= $tx['id'] ?>?');" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="action" value="delete_entry">
                                            <input type="hidden" name="entry_id" value="<?= $tx['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete Entry">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
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
                    <a href="?page=<?= $p ?>&entity_id=<?= $entity_filter ?>&currency=<?= urlencode($currency_filter) ?>&search=<?= urlencode($search) ?>"
                       class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
