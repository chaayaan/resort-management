<?php
require_once __DIR__ . '/auth.php';
require_login();

// $active should be set by the including page: frontdesk, dashboard, bookings, reservation, extend, checkout, guests, room_types, rooms, users
if (!isset($active)) $active = '';
$pageTitle = isset($pageTitle) ? $pageTitle : 'Hotel Front Desk';
$me = current_user();

// Resort name for the sidebar brand (falls back if settings table/row is missing)
$resortNameRow = fetch_one($conn, "SELECT setting_value FROM settings WHERE setting_key = 'resort_name'");
$resortName = ($resortNameRow && $resortNameRow['setting_value'] !== '') ? $resortNameRow['setting_value'] : 'Hotel PMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?> | Hotel PMS</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
    /* Sidebar user/logout block — safe to move into assets/css/style.css later */
    .sidebar-user {
        margin-top: auto;
        padding: 14px 18px;
        border-top: 1px solid rgba(255,255,255,0.08);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .sidebar-user-info { min-width: 0; }
    .sidebar-user-name {
        font-weight: 600;
        font-size: 0.85rem;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sidebar-user-role {
        font-size: 0.72rem;
        color: rgba(255,255,255,0.6);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sidebar-logout {
        flex-shrink: 0;
        color: rgba(255,255,255,0.75);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        text-decoration: none;
        transition: background 0.12s ease, color 0.12s ease;
    }
    .sidebar-logout:hover { background: rgba(255,255,255,0.1); color: #fff; }
    .sidebar-logout .nav-text { display: none; }
    .sidebar {
        display: flex;
        flex-direction: column;
    }
</style>
</head>
<body>

<div class="app-wrapper">

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">&#127976;</span>
            <div class="brand-text-group">
                <span class="brand-text"><?php echo e($resortName); ?></span>
                <span class="brand-tagline">Front Desk</span>
            </div>
        </div>

        <ul class="sidebar-nav">
            <li class="<?php echo $active === 'frontdesk' ? 'active' : ''; ?>">
                <a href="frontdesk.php">
                    <span class="nav-icon">&#127968;</span>
                    <span class="nav-text">Front Desk</span>
                </a>
            </li>
            <li class="<?php echo $active === 'reservation' ? 'active' : ''; ?>">
                <a href="reservation.php">
                    <span class="nav-icon">&#128197;</span>
                    <span class="nav-text">Reservations</span>
                </a>
            </li>
            <li class="<?php echo $active === 'rooms' ? 'active' : ''; ?>">
                <a href="rooms.php">
                    <span class="nav-icon">&#128273;</span>
                    <span class="nav-text">Rooms</span>
                </a>
            </li>
            <li class="<?php echo $active === 'guests' ? 'active' : ''; ?>">
                <a href="guests.php">
                    <span class="nav-icon">&#128100;</span>
                    <span class="nav-text">Guests</span>
                </a>
            </li>
            <li class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>">
                <a href="dashboard.php">
                    <span class="nav-icon">&#128202;</span>
                    <span class="nav-text">Reports</span>
                </a>
            </li>
            <li class="<?php echo $active === 'bookings' ? 'active' : ''; ?>">
                <a href="bookings.php">
                    <span class="nav-icon">&#128203;</span>
                    <span class="nav-text">All Bookings</span>
                </a>
            </li>
            <li class="<?php echo $active === 'extend' ? 'active' : ''; ?>">
                <a href="extend.php">
                    <span class="nav-icon">&#8987;</span>
                    <span class="nav-text">Extend Stay</span>
                </a>
            </li>
            <li class="<?php echo $active === 'checkout' ? 'active' : ''; ?>">
                <a href="checkout.php">
                    <span class="nav-icon">&#128683;</span>
                    <span class="nav-text">Checkout</span>
                </a>
            </li>

            <li class="sidebar-heading">Setup</li>

            <li class="<?php echo $active === 'room_types' ? 'active' : ''; ?>">
                <a href="room_types.php">
                    <span class="nav-icon">&#127991;&#65039;</span>
                    <span class="nav-text">Room Types</span>
                </a>
            </li>
            <li class="<?php echo $active === 'settings' ? 'active' : ''; ?>">
                <a href="settings.php">
                    <span class="nav-icon">&#9881;&#65039;</span>
                    <span class="nav-text">Settings</span>
                </a>
            </li>
            <?php if ($me['designation'] === 'admin'): ?>
            <li class="<?php echo $active === 'users' ? 'active' : ''; ?>">
                <a href="users.php">
                    <span class="nav-icon">&#128101;</span>
                    <span class="nav-text">Users</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-user">
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo e($me['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo e(designation_label($me['designation'])); ?></div>
            </div>
            <a href="logout.php" class="sidebar-logout" title="Log Out" onclick="return confirm('Log out of Hotel PMS?');">
                <span class="nav-icon">&#128682;</span>
                <span class="nav-text">Log Out</span>
            </a>
        </div>
    </nav>

    <!-- Mobile top bar -->
    <div class="mobile-topbar d-md-none">
        <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle">&#9776;</button>
        <span class="mobile-title"><?php echo e($pageTitle); ?></span>
        <a href="logout.php" class="btn btn-sm btn-outline-danger ms-auto" onclick="return confirm('Log out of Hotel PMS?');">Log Out</a>
    </div>

    <!-- Main content -->
    <main class="main-content">
        <div class="content-inner">