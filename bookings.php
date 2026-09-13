<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'All Bookings';
$active = 'bookings';

// =====================================================
// Filters
// =====================================================
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';

$validStatuses = ['reserved', 'checked_in', 'checked_out', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

$where = ['1=1'];

if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(g.full_name LIKE '%$s%' OR g.phone LIKE '%$s%' OR r.room_number LIKE '%$s%')";
}
if ($statusFilter !== '') {
    $where[] = "b.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}
if ($fromDate !== '') {
    $where[] = "b.check_in_date >= '" . mysqli_real_escape_string($conn, $fromDate) . "'";
}
if ($toDate !== '') {
    $where[] = "b.check_out_date <= '" . mysqli_real_escape_string($conn, $toDate) . "'";
}

$whereSql = implode(' AND ', $where);

// =====================================================
// Pagination
// =====================================================
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countRow = fetch_one($conn, "
    SELECT COUNT(*) AS c
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN guests g ON g.id = b.guest_id
    WHERE $whereSql
");
$totalRows = (int)$countRow['c'];
$totalPages = max(1, ceil($totalRows / $perPage));

$bookings = fetch_all($conn, "
    SELECT b.*, r.room_number, rt.name AS type_name, g.full_name, g.phone,
        (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.payment_type != 'refund') AS total_paid
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN room_types rt ON rt.id = r.room_type_id
    JOIN guests g ON g.id = b.guest_id
    WHERE $whereSql
    ORDER BY b.created_at DESC
    LIMIT $perPage OFFSET $offset
");

// Build query string helper for pagination links (preserves filters)
function qs($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>All Bookings</h3>
    <a href="reservation.php" class="btn btn-primary btn-sm">+ New Reservation</a>
</div>

<div class="card-form mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small text-muted">Search (guest, phone, room)</label>
            <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Search...">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="reserved" <?php echo $statusFilter==='reserved'?'selected':''; ?>>Reserved</option>
                <option value="checked_in" <?php echo $statusFilter==='checked_in'?'selected':''; ?>>Checked In</option>
                <option value="checked_out" <?php echo $statusFilter==='checked_out'?'selected':''; ?>>Checked Out</option>
                <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">Check-in From</label>
            <input type="date" name="from" class="form-control" value="<?php echo e($fromDate); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">Check-out To</label>
            <input type="date" name="to" class="form-control" value="<?php echo e($toDate); ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
            <a href="bookings.php" class="btn btn-outline-secondary" title="Clear filters">&#10005;</a>
        </div>
    </form>
</div>

<div class="card-form">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small"><?php echo $totalRows; ?> booking<?php echo $totalRows === 1 ? '' : 's'; ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Days</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <?php
                    $grandTotal = (float)$b['room_charge_total'] + (float)$b['extension_charge_total'] - (float)$b['discount'];
                    $paid = (float)$b['total_paid'];
                    $balance = $grandTotal - $paid;
                    $statusClass = [
                        'reserved' => 'status-reserved',
                        'checked_in' => 'status-occupied',
                        'checked_out' => 'bg-secondary',
                        'cancelled' => 'bg-dark',
                    ][$b['status']] ?? 'bg-secondary';
                ?>
                <tr>
                    <td class="text-muted">#<?php echo str_pad($b['id'], 5, '0', STR_PAD_LEFT); ?></td>
                    <td>
                        <div class="fw-semibold"><?php echo e($b['full_name']); ?></div>
                        <div class="text-muted small"><?php echo e($b['phone']); ?></div>
                    </td>
                    <td>
                        <div class="fw-semibold">#<?php echo e($b['room_number']); ?></div>
                        <div class="text-muted small"><?php echo e($b['type_name']); ?></div>
                    </td>
                    <td><?php echo date('d M Y', strtotime($b['check_in_date'])); ?></td>
                    <td><?php echo date('d M Y', strtotime($b['check_out_date'])); ?></td>
                    <td><?php echo (int)$b['total_days']; ?></td>
                    <td class="fw-semibold">৳<?php echo money($grandTotal); ?></td>
                    <td class="text-success">৳<?php echo money($paid); ?></td>
                    <td>
                        <?php if ($b['status'] === 'checked_out' || $b['status'] === 'cancelled'): ?>
                            <span class="badge <?php echo $statusClass; ?> text-capitalize"><?php echo e(str_replace('_',' ',$b['status'])); ?></span>
                        <?php else: ?>
                            <span class="status-pill <?php echo $statusClass; ?> text-capitalize"><?php echo e(str_replace('_',' ',$b['status'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="receipt.php?booking_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-secondary">Receipt</a>
                        <?php if ($b['status'] === 'reserved'): ?>
                            <a href="checkin.php?booking_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-primary">Check In</a>
                        <?php elseif ($b['status'] === 'checked_in'): ?>
                            <a href="extend.php?booking_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-secondary">Extend</a>
                            <a href="checkout.php?booking_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-danger">Checkout</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No bookings found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?<?php echo qs(['page' => $page - 1]); ?>">Previous</a>
            </li>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo qs(['page' => $p]); ?>"><?php echo $p; ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?<?php echo qs(['page' => $page + 1]); ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>