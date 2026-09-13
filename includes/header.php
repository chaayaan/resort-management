<?php
// $active should be set by the including page: frontdesk, dashboard, bookings, reservation, extend, checkout, guests, room_types, rooms
if (!isset($active)) $active = '';
$pageTitle = isset($pageTitle) ? $pageTitle : 'Hotel Front Desk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?> | Hotel PMS</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="app-wrapper">

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">&#127976;</span>
            <div class="brand-text-group">
                <span class="brand-text">Hotel Grand View</span>
                <span class="brand-tagline">Stay Better</span>
            </div>
        </div>

        <ul class="sidebar-nav">
            <li class="<?php echo $active === 'frontdesk' ? 'active' : ''; ?>">
                <a href="index.php">
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
        </ul>
    </nav>

    <!-- Mobile top bar -->
    <div class="mobile-topbar d-md-none">
        <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle">&#9776;</button>
        <span class="mobile-title"><?php echo e($pageTitle); ?></span>
    </div>

    <!-- Main content -->
    <main class="main-content">
        <div class="content-inner">
