<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Guests';
$active = 'guests';

// =====================================================
// Search
// =====================================================
$search = trim($_GET['q'] ?? '');
$where = '1=1';
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where = "(g.full_name LIKE '%$s%' OR g.phone LIKE '%$s%' OR g.email LIKE '%$s%' OR g.id_proof_number LIKE '%$s%')";
}

// =====================================================
// Pagination
// =====================================================
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countRow = fetch_one($conn, "SELECT COUNT(*) AS c FROM guests g WHERE $where");
$totalRows = (int)$countRow['c'];
$totalPages = max(1, ceil($totalRows / $perPage));

// =====================================================
// Guest list with booking stats
// =====================================================
$guests = fetch_all($conn, "
    SELECT g.*,
        COUNT(b.id) AS total_bookings,
        SUM(b.status = 'checked_in') AS active_bookings,
        MAX(b.reserved_from) AS last_check_in,
        COALESCE(SUM(b.room_charge_total + b.extension_charge_total - b.discount), 0) AS lifetime_value
    FROM guests g
    LEFT JOIN bookings b ON b.guest_id = g.id AND b.status != 'cancelled'
    WHERE $where
    GROUP BY g.id
    ORDER BY g.full_name ASC
    LIMIT $perPage OFFSET $offset
");

function qs($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

// =====================================================
// Guest detail view (booking history) via ?view=ID
// =====================================================
$viewGuestId = (int)($_GET['view'] ?? 0);
$viewGuest = null;
$viewBookings = [];
if ($viewGuestId > 0) {
    $viewGuest = fetch_one($conn, "SELECT * FROM guests WHERE id = $viewGuestId");
    if ($viewGuest) {
        $viewBookings = fetch_all($conn, "
            SELECT b.*, r.room_number, rt.name AS type_name,
                (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.payment_type != 'refund') AS total_paid
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN room_types rt ON rt.id = r.room_type_id
            WHERE b.guest_id = $viewGuestId
            ORDER BY b.created_at DESC
        ");
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($viewGuestId > 0 && $viewGuest): ?>

<div class="page-header">
    <h3>Guest — <?php echo e($viewGuest['full_name']); ?></h3>
    <a href="guests.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Guests</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card-form">
            <div class="section-title">Guest Details</div>
            <div class="mb-2"><span class="text-muted small">Full Name</span><div class="fw-semibold"><?php echo e($viewGuest['full_name']); ?></div></div>
            <div class="mb-2"><span class="text-muted small">Phone</span><div class="fw-semibold"><?php echo e($viewGuest['phone']); ?></div></div>
            <div class="mb-2"><span class="text-muted small">Email</span><div class="fw-semibold"><?php echo e($viewGuest['email'] ?: '—'); ?></div></div>
            <div class="mb-2"><span class="text-muted small">ID Proof</span><div class="fw-semibold"><?php echo e($viewGuest['id_proof_type'] ?: '—'); ?> <?php echo e($viewGuest['id_proof_number'] ?: ''); ?></div></div>
            <div class="mb-2"><span class="text-muted small">Address</span><div class="fw-semibold"><?php echo e($viewGuest['address'] ?: '—'); ?></div></div>
            <div class="mb-0"><span class="text-muted small">Guest Since</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($viewGuest['created_at'])); ?></div></div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card-form">
            <div class="section-title">Booking History</div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Room</th><th>Reserved From</th><th>Reserved Until</th><th>Nights</th>
                            <th>Total</th><th>Paid</th><th>Status</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewBookings as $b): ?>
                        <?php
                            $grandTotal = (float)$b['room_charge_total'] + (float)$b['extension_charge_total'] - (float)$b['discount'];
                            $statusClass = [
                                'reserved' => 'bg-primary',
                                'checked_in' => 'bg-success',
                                'checked_out' => 'bg-secondary',
                                'cancelled' => 'bg-danger',
                            ][$b['status']] ?? 'bg-secondary';
                        ?>
                        <tr>
                            <td class="fw-semibold">#<?php echo e($b['room_number']); ?><div class="text-muted small"><?php echo e($b['type_name']); ?></div></td>
                            <td><?php echo date('d M Y', strtotime($b['reserved_from'])); ?></td>
                            <td><?php echo date('d M Y', strtotime($b['reserved_until'])); ?></td>
                            <td><?php echo (int)$b['reserved_nights']; ?></td>
                            <td class="fw-semibold">৳<?php echo money($grandTotal); ?></td>
                            <td class="text-success">৳<?php echo money($b['total_paid']); ?></td>
                            <td><span class="badge <?php echo $statusClass; ?> text-capitalize"><?php echo e(str_replace('_',' ',$b['status'])); ?></span></td>
                            <td class="text-end"><a href="receipt.php?booking_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-secondary">Receipt</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($viewBookings)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No bookings yet for this guest.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-header">
    <h3>Guests</h3>
    <a href="reservation.php" class="btn btn-primary btn-sm">+ New Reservation</a>
</div>

<div class="card-form mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label small text-muted">Search (name, phone, email, ID proof)</label>
            <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Search guests...">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Search</button>
            <a href="guests.php" class="btn btn-outline-secondary" title="Clear search">&#10005;</a>
        </div>
    </form>
</div>

<div class="card-form">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small"><?php echo $totalRows; ?> guest<?php echo $totalRows === 1 ? '' : 's'; ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>ID Proof</th>
                    <th>Bookings</th>
                    <th>Last Stay</th>
                    <th>Lifetime Value</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($guests as $g): ?>
                <tr>
                    <td class="fw-semibold">
                        <?php echo e($g['full_name']); ?>
                        <?php if ((int)$g['active_bookings'] > 0): ?>
                            <span class="badge bg-success ms-1">Staying</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($g['phone']); ?></td>
                    <td class="text-muted"><?php echo e($g['email'] ?: '—'); ?></td>
                    <td class="text-muted"><?php echo e($g['id_proof_type'] ?: '—'); ?><?php echo $g['id_proof_number'] ? ' · ' . e($g['id_proof_number']) : ''; ?></td>
                    <td><?php echo (int)$g['total_bookings']; ?></td>
                    <td><?php echo $g['last_check_in'] ? date('d M Y', strtotime($g['last_check_in'])) : '—'; ?></td>
                    <td class="fw-semibold text-success">৳<?php echo money($g['lifetime_value']); ?></td>
                    <td class="text-end">
                        <a href="guests.php?view=<?php echo (int)$g['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($guests)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No guests found.</td></tr>
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

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>