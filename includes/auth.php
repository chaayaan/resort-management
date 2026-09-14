<?php
/**
 * Authentication helpers.
 * Include this AFTER config/db.php on every protected page:
 *
 *     require_once __DIR__ . '/config/db.php';
 *     require_once __DIR__ . '/includes/auth.php';
 *     require_login();
 *
 * index.php and logout.php also include this file but do NOT call require_login().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DESIGNATIONS = [
    'admin'                    => 'Admin',
    'general_manager'          => 'General Manager',
    'hotel_desk_manager'       => 'Hotel Desk Manager',
    'restaurant_desk_manager'  => 'Restaurant Desk Manager',
    'staff'                    => 'Staff',
];

/**
 * Returns the logged-in user's session data, or null if not logged in.
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

/**
 * Redirects to the login page if not authenticated.
 * Preserves the originally requested URL so index.php can bounce back to it.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'frontdesk.php';
        header('Location: index.php');
        exit;
    }
}

/**
 * Restricts a page to one or more designations. Call after require_login().
 * Example: require_role('admin');
 *          require_role(['admin', 'general_manager']);
 */
function require_role($allowed): void
{
    require_login();
    $allowed = is_array($allowed) ? $allowed : [$allowed];
    $user = current_user();
    if (!in_array($user['designation'], $allowed, true)) {
        http_response_code(403);
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-danger">You do not have permission to view this page.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

function designation_label(string $designation): string
{
    return DESIGNATIONS[$designation] ?? ucfirst(str_replace('_', ' ', $designation));
}
