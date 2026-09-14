<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_role('admin');

$pageTitle = 'Users';
$active = 'users';
$error = '';
$success = '';
$me = current_user();

// =====================================================
// AJAX-style POST handling (add / edit / toggle-active / delete)
// All actions redirect back to users.php with a flash message via query string.
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // ---------- Add or Edit ----------
    if ($formAction === 'save') {
        $userId      = (int)($_POST['user_id'] ?? 0);
        $username    = trim($_POST['username'] ?? '');
        $fullName    = trim($_POST['full_name'] ?? '');
        $designation = $_POST['designation'] ?? '';
        $password    = (string)($_POST['password'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        $validDesignations = array_keys(DESIGNATIONS);

        if ($username === '' || $fullName === '' || !in_array($designation, $validDesignations, true)) {
            $error = 'Please fill all required fields with valid values.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters: letters, numbers, dot, underscore only.';
        } elseif ($userId <= 0 && $password === '') {
            $error = 'Password is required for a new user.';
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Uniqueness check (excluding self when editing)
            $dupCheck = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id != ?");
            mysqli_stmt_bind_param($dupCheck, 'si', $username, $userId);
            mysqli_stmt_execute($dupCheck);
            $dupResult = mysqli_stmt_get_result($dupCheck);
            $duplicate = mysqli_fetch_assoc($dupResult);
            mysqli_stmt_close($dupCheck);

            if ($duplicate) {
                $error = 'That username is already taken.';
            } elseif ($userId > 0 && $userId === (int)$me['id'] && $isActive === 0) {
                $error = 'You cannot deactivate your own account.';
            } elseif ($userId > 0 && $userId === (int)$me['id'] && $designation !== $me['designation'] && $designation !== 'admin') {
                $error = 'You cannot remove your own admin designation.';
            } else {
                if ($userId > 0) {
                    // ---- Update existing user ----
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = mysqli_prepare($conn, "UPDATE users SET username=?, full_name=?, designation=?, password_hash=?, is_active=? WHERE id=?");
                        mysqli_stmt_bind_param($stmt, 'ssssii', $username, $fullName, $designation, $hash, $isActive, $userId);
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE users SET username=?, full_name=?, designation=?, is_active=? WHERE id=?");
                        mysqli_stmt_bind_param($stmt, 'sssii', $username, $fullName, $designation, $isActive, $userId);
                    }
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $success = 'User updated successfully.';

                    // Keep session in sync if the admin edited their own account
                    if ($userId === (int)$me['id']) {
                        $_SESSION['user']['username']    = $username;
                        $_SESSION['user']['full_name']   = $fullName;
                        $_SESSION['user']['designation'] = $designation;
                    }
                } else {
                    // ---- Create new user ----
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password_hash, full_name, designation, is_active) VALUES (?,?,?,?,?)");
                    mysqli_stmt_bind_param($stmt, 'ssssi', $username, $hash, $fullName, $designation, $isActive);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $success = 'User created successfully.';
                }
            }
        }
    }

    // ---------- Delete ----------
    if ($formAction === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$me['id']) {
            $error = 'You cannot delete your own account.';
        } elseif ($userId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success = 'User deleted.';
        }
    }

    // If nothing went wrong, redirect (POST/Redirect/GET) with a flash message.
    if ($error === '') {
        $_SESSION['flash_success'] = $success;
        header('Location: users.php');
        exit;
    }
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// =====================================================
// Search + list
// =====================================================
$search = trim($_GET['q'] ?? '');
$where = '1=1';
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where = "(username LIKE '%$s%' OR full_name LIKE '%$s%')";
}

$users = fetch_all($conn, "SELECT * FROM users WHERE $where ORDER BY full_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Users</h3>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">+ Add User</button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<div class="card-form mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label small text-muted">Search (username, full name)</label>
            <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Search users...">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Search</button>
            <a href="users.php" class="btn btn-outline-secondary" title="Clear search">&#10005;</a>
        </div>
    </form>
</div>

<div class="card-form">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small"><?php echo count($users); ?> user<?php echo count($users) === 1 ? '' : 's'; ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Designation</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="fw-semibold">
                        <?php echo e($u['full_name']); ?>
                        <?php if ((int)$u['id'] === (int)$me['id']): ?>
                            <span class="badge bg-info text-dark ms-1">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($u['username']); ?></td>
                    <td><span class="badge bg-light text-dark border"><?php echo e(designation_label($u['designation'])); ?></span></td>
                    <td>
                        <?php if ((int)$u['is_active'] === 1): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?php echo $u['last_login_at'] ? date('d M Y, h:i A', strtotime($u['last_login_at'])) : 'Never'; ?></td>
                    <td class="text-muted small"><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                            onclick='openEditUserModal(<?php echo json_encode([
                                'id' => (int)$u['id'],
                                'username' => $u['username'],
                                'full_name' => $u['full_name'],
                                'designation' => $u['designation'],
                                'is_active' => (int)$u['is_active'],
                            ]); ?>)'>
                            Edit
                        </button>
                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete user &quot;<?php echo e($u['full_name']); ?>&quot;? This cannot be undone.');">
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================================================
     Add User Modal
     ===================================================== -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="form_action" value="save">
                <input type="hidden" name="user_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required maxlength="150">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" required
                               pattern="[a-zA-Z0-9_.]{3,50}" maxlength="50"
                               title="3-50 characters: letters, numbers, dot, underscore only">
                        <div class="form-text">Letters, numbers, dot and underscore only. Minimum 3 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Designation *</label>
                        <select name="designation" class="form-select" required>
                            <?php foreach (DESIGNATIONS as $value => $label): ?>
                                <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password">
                        <div class="form-text">Minimum 6 characters.</div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="addIsActive">
                        <label class="form-check-label" for="addIsActive">Active (can log in)</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =====================================================
     Edit User Modal
     ===================================================== -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="form_action" value="save">
                <input type="hidden" name="user_id" id="editUserId" value="0">

                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalTitle">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" id="editFullName" class="form-control" required maxlength="150">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" id="editUsername" class="form-control" required
                               pattern="[a-zA-Z0-9_.]{3,50}" maxlength="50"
                               title="3-50 characters: letters, numbers, dot, underscore only">
                        <div class="form-text">Letters, numbers, dot and underscore only. Minimum 3 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Designation *</label>
                        <select name="designation" id="editDesignation" class="form-select" required>
                            <?php foreach (DESIGNATIONS as $value => $label): ?>
                                <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password">
                        <div class="form-text">Leave blank to keep the current password.</div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editIsActive">
                        <label class="form-check-label" for="editIsActive">Active (can log in)</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScript = <<<'HTML'
<script>
function openEditUserModal(user) {
    document.getElementById('editUserModalTitle').textContent = 'Edit User — ' + user.full_name;
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editDesignation').value = user.designation;
    document.getElementById('editIsActive').checked = user.is_active === 1;
}
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>