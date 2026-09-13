<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Manage Rooms';
$active = 'rooms';
$message = '';
$error = '';

// ---- Handle Add ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $roomNumber = trim($_POST['room_number'] ?? '');
    $typeId = (int)($_POST['room_type_id'] ?? 0);
    $price = (float)($_POST['price_per_day'] ?? 0);
    $floor = trim($_POST['floor'] ?? '');

    if ($roomNumber === '' || $typeId <= 0 || $price <= 0) {
        $error = 'Please fill room number, type and a valid price.';
    } else {
        $dupe = fetch_one($conn, "SELECT id FROM rooms WHERE room_number = '" . mysqli_real_escape_string($conn, $roomNumber) . "'");
        if ($dupe) {
            $error = 'Room number already exists.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO rooms (room_number, room_type_id, price_per_day, floor, status) VALUES (?, ?, ?, ?, 'available')");
            mysqli_stmt_bind_param($stmt, 'sids', $roomNumber, $typeId, $price, $floor);
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Room added successfully.';
            } else {
                $error = 'Failed to add room.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ---- Handle Edit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $roomNumber = trim($_POST['room_number'] ?? '');
    $typeId = (int)($_POST['room_type_id'] ?? 0);
    $price = (float)($_POST['price_per_day'] ?? 0);
    $floor = trim($_POST['floor'] ?? '');

    if ($id > 0 && $roomNumber !== '' && $typeId > 0 && $price > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE rooms SET room_number=?, room_type_id=?, price_per_day=?, floor=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'sidsi', $roomNumber, $typeId, $price, $floor, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $message = 'Room updated.';
    }
}

// ---- Handle Disable/Enable ----
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $room = fetch_one($conn, "SELECT is_active, status FROM rooms WHERE id = $id");
    if ($room) {
        if ($room['status'] !== 'available' && $room['is_active'] == 1) {
            $error = 'Cannot disable a room that is reserved/occupied.';
        } else {
            $newVal = $room['is_active'] == 1 ? 0 : 1;
            mysqli_query($conn, "UPDATE rooms SET is_active = $newVal WHERE id = $id");
            $message = $newVal == 1 ? 'Room enabled.' : 'Room disabled.';
        }
    }
}

$roomTypes = fetch_all($conn, "SELECT * FROM room_types ORDER BY name ASC");
$rooms = fetch_all($conn, "
    SELECT r.*, rt.name AS type_name
    FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
    ORDER BY r.room_number ASC
");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Manage Rooms</h3>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRoomModal"
        <?php echo empty($roomTypes) ? 'disabled' : ''; ?>>+ Add Room</button>
</div>

<?php if (empty($roomTypes)): ?>
    <div class="alert alert-warning">Please add at least one <a href="room_types.php">Room Type</a> before adding rooms.</div>
<?php endif; ?>
<?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="card-form">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Type</th>
                    <th>Price/Day</th>
                    <th>Floor</th>
                    <th>Status</th>
                    <th>Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $r): ?>
                <tr>
                    <td class="fw-semibold">#<?php echo e($r['room_number']); ?></td>
                    <td><?php echo e($r['type_name']); ?></td>
                    <td>৳<?php echo money($r['price_per_day']); ?></td>
                    <td><?php echo e($r['floor']); ?></td>
                    <td><span class="status-pill status-<?php echo $r['status']; ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
                    <td>
                        <?php if ($r['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary btn-edit-room"
                            data-id="<?php echo (int)$r['id']; ?>"
                            data-room_number="<?php echo e($r['room_number']); ?>"
                            data-room_type_id="<?php echo (int)$r['room_type_id']; ?>"
                            data-price_per_day="<?php echo e($r['price_per_day']); ?>"
                            data-floor="<?php echo e($r['floor']); ?>">
                            Edit
                        </button>
                        <a href="rooms.php?toggle=<?php echo (int)$r['id']; ?>"
                           class="btn btn-sm btn-outline-secondary"
                           onclick="return confirm('<?php echo $r['is_active'] ? 'Disable' : 'Enable'; ?> this room?');">
                            <?php echo $r['is_active'] ? 'Disable' : 'Enable'; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rooms)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No rooms yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Room Number</label>
                    <input type="text" name="room_number" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Room Type</label>
                    <select name="room_type_id" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach ($roomTypes as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Price / Day (৳)</label>
                    <input type="number" step="0.01" min="0" name="price_per_day" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Floor</label>
                    <input type="text" name="floor" class="form-control">
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
<div class="modal fade" id="editRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editRoomId">
            <div class="modal-header">
                <h5 class="modal-title">Edit Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Room Number</label>
                    <input type="text" name="room_number" id="editRoomNumber" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Room Type</label>
                    <select name="room_type_id" id="editRoomTypeId" class="form-select" required>
                        <?php foreach ($roomTypes as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Price / Day (৳)</label>
                    <input type="number" step="0.01" min="0" name="price_per_day" id="editRoomPrice" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Floor</label>
                    <input type="text" name="floor" id="editRoomFloor" class="form-control">
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
    document.querySelectorAll('.btn-edit-room').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editRoomId').value = btn.dataset.id;
            document.getElementById('editRoomNumber').value = btn.dataset.room_number;
            document.getElementById('editRoomTypeId').value = btn.dataset.room_type_id;
            document.getElementById('editRoomPrice').value = btn.dataset.price_per_day;
            document.getElementById('editRoomFloor').value = btn.dataset.floor;
            var modal = new bootstrap.Modal(document.getElementById('editRoomModal'));
            modal.show();
        });
    });
});
</script>
HTML;
require_once __DIR__ . '/includes/footer.php';
?>