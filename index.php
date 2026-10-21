<?php
// index.php - Global Login Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
$user = get_logged_in_user();
if ($user) {
    if ($user['role'] === 'admin') {
        header("Location: /admin/index.php");
    } else {
        header("Location: /staff/index.php");
    }
    exit;
}

$error = '';
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verify_csrf_token($submitted_token)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please fill in both email and password.';
    } else {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            if ($row['status'] !== 'active') {
                $error = 'Account is deactivated. Please contact an administrator.';
            } elseif (password_verify($password, $row['password_hash'])) {
                // Success: Regenerate session ID to prevent session fixation
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['email'] = $row['email'];
                $_SESSION['role'] = $row['role'];

                // Log audit action
                log_audit_action($row['id'], "User logged in successfully");

                if ($row['role'] === 'admin') {
                    header("Location: /admin/index.php");
                } else {
                    header("Location: /staff/index.php");
                }
                exit;
            } else {
                $error = 'Invalid email address or password.';
            }
        } else {
            $error = 'Invalid email address or password.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Enterprise Accounting Engine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">

<div class="login-card">
    <div class="login-brand">
        <i class="fa-solid fa-vault"></i>
        <h1 class="login-title">AccuTrack Engine</h1>
        <p class="login-subtitle">Multi-Entity & Multi-Currency Income Tracker</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/index.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="form-group">
            <label class="form-label" for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="user@company.com" required autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem; padding: 0.75rem;">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In to Account
        </button>
    </form>

    <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
        <p><strong>Demo Credentials:</strong></p>
        <p style="margin-top: 0.2rem;">Admin: <code>admin@company.com</code> / <code>admin123</code></p>
        <p style="margin-top: 0.2rem;">Staff: <code>staff@company.com</code> / <code>staff123</code></p>
    </div>
</div>

</body>
</html>
