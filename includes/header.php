<?php
// includes/header.php - Global Header & Navigation Layout

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_user = get_logged_in_user();
$page_title = $page_title ?? 'Accounting Engine';
$active_nav = $active_nav ?? '';
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Enterprise Accounting</title>
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="app-container">
    <!-- Mobile Sidebar Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="fa-solid fa-vault brand-icon"></i>
            <span class="brand-name">AccuTrack Enterprise</span>
        </div>

        <nav class="sidebar-menu">
            <?php if ($current_user['role'] === 'admin'): ?>
                <div class="menu-category">ADMIN MANAGEMENT</div>
                <a href="/admin/index.php" class="menu-item <?= $active_nav === 'admin_dashboard' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chart-line"></i> <span>Dashboard (Multi-Currency)</span>
                </a>
                <a href="/admin/users.php" class="menu-item <?= $active_nav === 'admin_users' ? 'active' : '' ?>">
                    <i class="fa-solid fa-users-gear"></i> <span>User & Staff Directory</span>
                </a>
                <a href="/accounts.php" class="menu-item <?= $active_nav === 'accounts' ? 'active' : '' ?>">
                    <i class="fa-solid fa-sitemap"></i> <span>Chart of Accounts</span>
                </a>
                <a href="/admin/transactions.php" class="menu-item <?= $active_nav === 'admin_transactions' ? 'active' : '' ?>">
                    <i class="fa-solid fa-receipt"></i> <span>Audit Log / Entries</span>
                </a>
                <a href="/admin/reports.php" class="menu-item <?= $active_nav === 'admin_reports' ? 'active' : '' ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i> <span>Financial Reports & P&L</span>
                </a>
            <?php endif; ?>

            <div class="menu-category">STAFF PORTAL</div>
            <a href="/staff/index.php" class="menu-item <?= $active_nav === 'staff_dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i> <span>My Entry Log</span>
            </a>
            <a href="/accounts.php" class="menu-item <?= $active_nav === 'accounts' && $current_user['role'] === 'staff' ? 'active' : '' ?>">
                <i class="fa-solid fa-sitemap"></i> <span>Account Heads</span>
            </a>
            <a href="/staff/add_transaction.php" class="menu-item <?= $active_nav === 'staff_add_tx' ? 'active' : '' ?>">
                <i class="fa-solid fa-square-plus"></i> <span>New Journal Entry</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <i class="fa-solid fa-user-circle"></i>
                <div class="user-details">
                    <span class="user-name"><?= htmlspecialchars($current_user['full_name'] ?? 'User') ?></span>
                    <span class="user-role badge <?= $current_user['role'] === 'admin' ? 'badge-admin' : 'badge-staff' ?>">
                        <?= strtoupper(htmlspecialchars($current_user['role'] ?? 'STAFF')) ?>
                    </span>
                </div>
            </div>
            <a href="/logout.php" class="logout-btn" title="Sign Out">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </aside>

    <!-- Main Content wrapper -->
    <div class="main-wrapper">
        <!-- Top Navigation Bar -->
        <header class="top-navbar">
            <div class="navbar-left">
                <button id="sidebarToggle" class="sidebar-toggle-btn" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="breadcrumb">
                    <span class="breadcrumb-item"><i class="fa-solid fa-building-columns"></i> Multi-Entity Platform</span>
                    <span class="breadcrumb-separator"><i class="fa-solid fa-chevron-right"></i></span>
                    <span class="breadcrumb-item active"><?= htmlspecialchars($page_title) ?></span>
                </div>
            </div>

            <div class="navbar-right">
                <div class="currency-indicators">
                    <span class="currency-badge inr-badge" title="INR Fiat Entities">
                        <i class="fa-solid fa-indian-rupee-sign"></i> INR Entities
                    </span>
                    <span class="currency-badge usdt-badge" title="USDT Crypto Entities">
                        <i class="fa-solid fa-dollar-sign"></i> USDT Wings
                    </span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="content-area">
