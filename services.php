<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pageTitle = 'All Services';
$active = 'service';

// =====================================================
// Filters
// =====================================================
$search = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['service_type'] ?? '');
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';

$where = ['1=1'];

if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(g.full_name LIKE '%$s%' OR g.phone LIKE '%$s%' OR r.room_number LIKE '%$s%' OR bs.service_type LIKE '%$s%')";
}
if ($typeFilter !== '') {
    $where[] = "bs.service_type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
}
if ($fromDate !== '') {
    $where[] = "DATE(bs.created_at) >= '" . mysqli_real_escape_string($conn, $fromDate) . "'";
}
if ($toDate !== '') {
    $where[] = "DATE(bs.created_at) <= '" . mysqli_real_escape_string($conn, $toDate) . "'";
}

$whereSql = implode(' AND ', $where);

// =====================================================
// Distinct service types for the filter dropdown
// =====================================================
$typeRows = fetch_all($conn, "SELECT DISTINCT service_type FROM booking_services ORDER BY service_type ASC");

// =====================================================
// Pagination
// =====================================================
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countRow = fetch_one($conn, "
    SELECT COUNT(*) AS c
    FROM booking_services bs
    JOIN bookings b ON b.id = bs.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN guests g ON g.id = b.guest_id
    WHERE $whereSql
");
$totalRows = (int)$countRow['c'];
$totalPages = max(1, ceil($totalRows / $perPage));

$orders = fetch_all($conn, "
    SELECT bs.*, b.status AS booking_status, r.room_number, g.full_name, g.phone,
        u.full_name AS added_by_name,
        (SELECT COUNT(*) FROM booking_service_items bsi WHERE bsi.booking_service_id = bs.id) AS item_count,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_service_id = bs.id AND p.payment_type = 'service') AS order_paid
    FROM booking_services bs
    JOIN bookings b ON b.id = bs.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN guests g ON g.id = b.guest_id
    LEFT JOIN users u ON u.id = bs.created_by
    WHERE $whereSql
    ORDER BY bs.created_at DESC
    LIMIT $perPage OFFSET $offset
");

// =====================================================
// Summary stats (today + all-time for current filter)
// =====================================================
$todayTotalRow = fetch_one($conn, "
    SELECT COALESCE(SUM(bs.amount),0) AS total, COUNT(*) AS cnt
    FROM booking_services bs
    WHERE DATE(bs.created_at) = CURDATE()
");
$filteredTotalRow = fetch_one($conn, "
    SELECT COALESCE(SUM(bs.amount),0) AS total
    FROM booking_services bs
    JOIN bookings b ON b.id = bs.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN guests g ON g.id = b.guest_id
    WHERE $whereSql
");

function qs($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>All Services</h3>
    <a href="service.php" class="btn btn-primary btn-sm">+ Add Service</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card-form py-3">
            <div class="text-muted small">Today's Service Orders</div>
            <div class="fs-4 fw-bold"><?php echo (int)$todayTotalRow['cnt']; ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-form py-3">
            <div class="text-muted small">Today's Service Revenue</div>
            <div class="fs-4 fw-bold text-success">৳<?php echo money($todayTotalRow['total']); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-form py-3">
            <div class="text-muted small">Total (Current Filter)</div>
            <div class="fs-4 fw-bold">৳<?php echo money($filteredTotalRow['total']); ?></div>
        </div>
    </div>
</div>

<div class="card-form mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small text-muted">Search (guest, phone, room, type)</label>
            <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Search...">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">Service Type</label>
            <select name="service_type" class="form-select">
                <option value="">All</option>
                <?php foreach ($typeRows as $t): ?>
                    <option value="<?php echo e($t['service_type']); ?>" <?php echo $typeFilter===$t['service_type']?'selected':''; ?>><?php echo e($t['service_type']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">From</label>
            <input type="date" name="from" class="form-control" value="<?php echo e($fromDate); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">To</label>
            <input type="date" name="to" class="form-control" value="<?php echo e($toDate); ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
            <a href="services.php" class="btn btn-outline-secondary" title="Clear filters">&#10005;</a>
        </div>
    </form>
</div>

<div class="card-form">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small"><?php echo $totalRows; ?> service order<?php echo $totalRows === 1 ? '' : 's'; ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Booking #</th>
                    <th>Room / Guest</th>
                    <th>Type</th>
                    <th>Items</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Paid Now</th>
                    <th>Added By</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="text-muted">#<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td>
                        <a href="receipt.php?booking_id=<?php echo (int)$o['booking_id']; ?>" class="fw-semibold text-decoration-none">#<?php echo str_pad($o['booking_id'], 5, '0', STR_PAD_LEFT); ?></a>
                        <div class="text-muted small text-capitalize"><?php echo e(str_replace('_', ' ', $o['booking_status'])); ?></div>
                    </td>
                    <td>
                        <div class="fw-semibold">Room #<?php echo e($o['room_number']); ?></div>
                        <div class="text-muted small"><?php echo e($o['full_name']); ?> &middot; <?php echo e($o['phone']); ?></div>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?php echo e($o['service_type']); ?></span></td>
                    <td class="text-muted"><?php echo (int)$o['item_count']; ?> item<?php echo $o['item_count'] == 1 ? '' : 's'; ?></td>
                    <td class="text-end fw-semibold">৳<?php echo money($o['amount']); ?></td>
                    <td class="text-end text-success">৳<?php echo money($o['order_paid']); ?></td>
                    <td class="text-muted small"><?php echo e($o['added_by_name'] ?? '—'); ?></td>
                    <td class="text-muted small"><?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?></td>
                    <td class="text-end">
                        <a href="service_receipt.php?service_id=<?php echo (int)$o['id']; ?>" class="btn btn-sm btn-outline-secondary">Print Bill</a>
                        <!-- <a href="service.php?booking_id=<?php echo (int)$o['booking_id']; ?>" class="btn btn-sm btn-outline-primary">Booking</a> -->
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No service orders found.</td></tr>
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