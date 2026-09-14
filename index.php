<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in -> go straight to front desk
if (is_logged_in()) {
    header('Location: frontdesk.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, username, password_hash, full_name, designation, is_active FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'This account has been disabled. Contact an administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'          => (int)$user['id'],
                'username'    => $user['username'],
                'full_name'   => $user['full_name'],
                'designation' => $user['designation'],
            ];

            $upd = mysqli_prepare($conn, "UPDATE users SET last_login_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'i', $user['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            $redirect = $_SESSION['redirect_after_login'] ?? 'frontdesk.php';
            unset($_SESSION['redirect_after_login']);
            // Guard against open-redirect: only allow local paths
            if (!preg_match('#^/?[a-zA-Z0-9_\-./]+\.php#', $redirect)) {
                $redirect = 'frontdesk.php';
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Hotel Eco Resort</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f4f6f9 0%, #e8f0fb 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 380px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(16,24,40,0.08);
            padding: 36px 32px;
        }
        .login-logo {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: #4b5a3c;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            margin: 0 auto 14px;
        }
        .login-title { text-align: center; font-weight: 700; color: #101828; margin-bottom: 2px; }
        .login-subtitle { text-align: center; color: #6b7280; font-size: 0.88rem; margin-bottom: 26px; }
        .btn-primary { background: #4b5a3c; border-color: #4b5a3c; }
        .btn-primary:hover { background: #34402a; border-color: #34402a; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">&#127976;</div>
        <div class="login-title">Hotel Eco Resort</div>
        <div class="login-subtitle">Front Desk Management System</div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required autofocus
                       value="<?php echo e($_POST['username'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log In</button>
        </form>
    </div>
</body>
</html>
