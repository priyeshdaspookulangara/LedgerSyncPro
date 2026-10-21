<?php
// admin/users.php - Staff Definition & User Management Interface

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_admin();
$conn = get_db_connection();

$error = '';
$success = '';

// Handle Actions (Create / Edit / Reset Password / Status Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_csrf)) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user') {
            $full_name = sanitize_input($_POST['full_name'] ?? '');
            $email = sanitize_input($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitize_input($_POST['role'] ?? 'staff');
            $status = sanitize_input($_POST['status'] ?? 'active');

            if (empty($full_name) || empty($email) || empty($password)) {
                $error = 'Please fill in all required fields.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address format.';
            } else {
                // Check email uniqueness
                $stmt_chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt_chk->bind_param("s", $email);
                $stmt_chk->execute();
                if ($stmt_chk->get_result()->num_rows > 0) {
                    $error = 'A user with this email address already exists.';
                } else {
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt_ins = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt_ins->bind_param("sssss", $full_name, $email, $password_hash, $role, $status);
                    if ($stmt_ins->execute()) {
                        log_audit_action($user['id'], "Created new user record: $full_name ($email, Role: $role)");
                        $success = "User account for '$full_name' created successfully!";
                    } else {
                        $error = "Failed to create user account.";
                    }
                    $stmt_ins->close();
                }
                $stmt_chk->close();
            }
        } elseif ($action === 'edit_user') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $full_name = sanitize_input($_POST['full_name'] ?? '');
            $email = sanitize_input($_POST['email'] ?? '');
            $role = sanitize_input($_POST['role'] ?? 'staff');
            $status = sanitize_input($_POST['status'] ?? 'active');
            $new_password = $_POST['new_password'] ?? '';

            if (empty($full_name) || empty($email) || !$user_id) {
                $error = 'Full Name and Email are required.';
            } else {
                // Update basic details
                if (!empty($new_password)) {
                    $pw_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt_u = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ?, password_hash = ? WHERE id = ?");
                    $stmt_u->bind_param("sssssi", $full_name, $email, $role, $status, $pw_hash, $user_id);
                } else {
                    $stmt_u = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                    $stmt_u->bind_param("ssssi", $full_name, $email, $role, $status, $user_id);
                }

                if ($stmt_u->execute()) {
                    log_audit_action($user['id'], "Updated user record #$user_id: $full_name");
                    $success = "User details updated successfully.";
                } else {
                    $error = "Failed to update user record.";
                }
                $stmt_u->close();
            }
        } elseif ($action === 'toggle_status') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $new_status = sanitize_input($_POST['status'] ?? 'active');

            if ($user_id === $user['id']) {
                $error = 'You cannot alter your own account status.';
            } else {
                $stmt_t = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt_t->bind_param("si", $new_status, $user_id);
                if ($stmt_t->execute()) {
                    log_audit_action($user['id'], "Toggled user #$user_id status to $new_status");
                    $success = "User status updated to " . strtoupper($new_status);
                }
                $stmt_t->close();
            }
        }
    }
}

// Fetch all users
$users = $conn->query("SELECT id, full_name, email, role, status, created_at FROM users ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = "User & Staff Directory";
$active_nav = "admin_users";
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="grid-3">
    <!-- Form to Add New User -->
    <div class="card" style="grid-column: span 1;">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-user-plus"></i> Define New Staff / User</div>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/users.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="create_user">

                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name <span style="color:red;">*</span></label>
                    <input type="text" class="form-control" name="full_name" placeholder="e.g. John Doe" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address <span style="color:red;">*</span></label>
                    <input type="email" class="form-control" name="email" placeholder="john@company.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Initial Password <span style="color:red;">*</span></label>
                    <input type="password" class="form-control" name="password" placeholder="••••••••" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="role">User Role</label>
                    <select class="form-select" name="role" required>
                        <option value="staff" selected>Staff (Entry Operator)</option>
                        <option value="admin">Admin (Full Control)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Account Status</label>
                    <select class="form-select" name="status" required>
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Staff Account
                </button>
            </form>
        </div>
    </div>

    <!-- Users Directory Table -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-users"></i> System User Accounts</div>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>#<?= $u['id'] ?></td>
                                <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-staff' ?>">
                                        <?= strtoupper($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $u['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= strtoupper($u['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.3rem;">
                                        <!-- Edit Modal Trigger / Quick Form -->
                                        <button class="btn btn-secondary btn-sm" onclick="toggleEditRow(<?= $u['id'] ?>)">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>

                                        <?php if ($u['id'] !== $user['id']): ?>
                                            <form method="POST" action="/admin/users.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="btn <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-inr' ?> btn-sm">
                                                    <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Edit Row Form -->
                            <tr id="edit_row_<?= $u['id'] ?>" style="display: none; background-color: #f8fafc;">
                                <td colspan="7">
                                    <form method="POST" action="/admin/users.php" style="padding: 0.5rem;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="edit_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Full Name</label>
                                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($u['full_name']) ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($u['email']) ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label class="form-label">Role</label>
                                                <select name="role" class="form-select">
                                                    <option value="staff" <?= $u['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select">
                                                    <option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= $u['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Reset Password (Optional)</label>
                                                <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                                            </div>
                                        </div>

                                        <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditRow(<?= $u['id'] ?>)">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Save Changes</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleEditRow(id) {
    const row = document.getElementById('edit_row_' + id);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
