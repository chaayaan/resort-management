<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Room Types';
$active = 'room_types';
$message = '';
$error = '';

// ---- Handle Add ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = 'Room type name is required.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO room_types (name, description) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, 'ss', $name, $desc);
        if (mysqli_stmt_execute($stmt)) {
            $message = 'Room type added successfully.';
        } else {
            $error = 'Failed to add room type.';
        }
        mysqli_stmt_close($stmt);
    }
}

// ---- Handle Edit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($id > 0 && $name !== '') {
        $stmt = mysqli_prepare($conn, "UPDATE room_types SET name = ?, description = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $name, $desc, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $message = 'Room type updated.';
    }
}

// ---- Handle Delete ----
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $inUse = fetch_one($conn, "SELECT COUNT(*) AS c FROM rooms WHERE room_type_id = $id");
    if ($inUse && $inUse['c'] > 0) {
        $error = 'Cannot delete: rooms are assigned to this type.';
    } else {
        mysqli_query($conn, "DELETE FROM room_types WHERE id = $id");
        $message = 'Room type deleted.';
    }
}

$types = fetch_all($conn, "
    SELECT rt.*, (SELECT COUNT(*) FROM rooms r WHERE r.room_type_id = rt.id) AS room_count
    FROM room_types rt ORDER BY rt.name ASC
");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Room Types</h3>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTypeModal">+ Add Room Type</button>
</div>

<?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card-form">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Rooms</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $t): ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($t['name']); ?></td>
                    <td class="text-muted"><?php echo e($t['description']); ?></td>
                    <td><span class="badge bg-secondary"><?php echo (int)$t['room_count']; ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary btn-edit-type"
                            data-id="<?php echo (int)$t['id']; ?>"
                            data-name="<?php echo e($t['name']); ?>"
                            data-description="<?php echo e($t['description']); ?>">
                            Edit
                        </button>
                        <a href="room_types.php?delete=<?php echo (int)$t['id']; ?>"
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete this room type?');">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($types)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No room types yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add Room Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editTypeId">
            <div class="modal-header">
                <h5 class="modal-title">Edit Room Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" id="editTypeName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="editTypeDescription" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-edit-type').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editTypeId').value = btn.dataset.id;
            document.getElementById('editTypeName').value = btn.dataset.name;
            document.getElementById('editTypeDescription').value = btn.dataset.description;
            var modal = new bootstrap.Modal(document.getElementById('editTypeModal'));
            modal.show();
        });
    });
});
</script>
HTML;
require_once __DIR__ . '/includes/footer.php';
?>