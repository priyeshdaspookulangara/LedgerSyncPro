<?php
// staff/index.php - Staff Portal & My Submitted Journal Entries

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$conn = get_db_connection();

// Pagination & Filtering Params
$entity_filter = intval($_GET['entity_id'] ?? 0);
$currency_filter = sanitize_input($_GET['currency'] ?? '');
$search = sanitize_input($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Fetch entities for filter dropdown
$entities = [];
$res_e = $conn->query("SELECT id, name, default_currency FROM entities WHERE status = 'active' ORDER BY name ASC");
while ($e = $res_e->fetch_assoc()) {
    $entities[] = $e;
}

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
$count_query = "SELECT COUNT(*) as total FROM journal_entries je $where_sql";
$total_rows = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch Entries with Line Totals
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
                        <th>Posted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 0.5rem;"></i><br>
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
                                <td><?= date('d M Y, H:i', strtotime($tx['created_at'])) ?></td>
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
