<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Cancel Reservation';
$active = 'reservation';
$error = '';

$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

// =====================================================
// No booking specified -> show picker of reserved bookings
// =====================================================
if ($bookingId <= 0) {
    $reserved = fetch_all($conn, "
        SELECT b.id AS booking_id, b.reservation_no, r.room_number, g.full_name, g.phone, b.reserved_from, b.reserved_until
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN guests g ON g.id = b.guest_id
        WHERE b.status = 'reserved'
        ORDER BY b.reserved_from ASC
    ");

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-header">
        <h3>Cancel Reservation — Select Booking</h3>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>

    <?php if (empty($reserved)): ?>
        <div class="alert alert-info">No pending reservations to cancel right now.</div>
    <?php else: ?>
        <div class="card-form">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Reservation #</th><th>Room</th><th>Guest</th><th>Reserved From</th><th>Reserved Until</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($reserved as $r): ?>
                        <tr>
                            <td class="text-muted small"><?php echo e($r['reservation_no']); ?></td>
                            <td class="fw-semibold">#<?php echo e($r['room_number']); ?></td>
                            <td><?php echo e($r['full_name']); ?> <span class="text-muted small">(<?php echo e($r['phone']); ?>)</span></td>
                            <td><?php echo date('d M Y', strtotime($r['reserved_from'])); ?></td>
                            <td><?php echo date('d M Y', strtotime($r['reserved_until'])); ?></td>
                            <td class="text-end">
                                <a href="reservation_cancel.php?booking_id=<?php echo (int)$r['booking_id']; ?>" class="btn btn-sm btn-danger">Cancel</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// =====================================================
// Handle cancellation submission
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['cancel_reason'] ?? '');

    $booking = fetch_one($conn, "SELECT * FROM bookings WHERE id = $bookingId AND status = 'reserved'");

    if (!$booking) {
        $error = 'Booking not found or cannot be cancelled (already checked in, checked out, or cancelled).';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "UPDATE bookings SET status = 'cancelled', notes = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $reason, $bookingId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // No change to rooms.status needed here: a 'reserved' booking never set
            // rooms.status to 'occupied' in the first place (that only happens at
            // check-in), and availability is derived from booking date ranges, so
            // cancelling simply removes this booking from the overlap check.

            mysqli_commit($conn);
            header('Location: bookings.php?cancelled=1');
            exit;

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Failed to cancel the reservation.';
        }
    }
}

// =====================================================
// Load booking details for confirmation form
// =====================================================
$booking = fetch_one($conn, "
    SELECT b.*, r.room_number, rt.name AS type_name,
           g.full_name, g.phone
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN room_types rt ON rt.id = r.room_type_id
    JOIN guests g ON g.id = b.guest_id
    WHERE b.id = $bookingId
");

if (!$booking || $booking['status'] !== 'reserved') {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">This booking cannot be cancelled (not found or not in reserved status).</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Cancel Reservation — Room #<?php echo e($booking['room_number']); ?></h3>
    <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Reservation Details</div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reservation #</span><div class="fw-semibold"><?php echo e($booking['reservation_no']); ?></div></div>
                <div class="col-6"><span class="text-muted">Reservation Date</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reservation_date'])); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Guest</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Room Type</span><div class="fw-semibold"><?php echo e($booking['type_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Price / Day</span><div class="fw-semibold">৳<?php echo money($booking['price_per_day']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reserved From</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_from'])); ?></div></div>
                <div class="col-6"><span class="text-muted">Reserved Until</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reserved Nights</span><div class="fw-semibold"><?php echo (int)$booking['reserved_nights']; ?></div></div>
                <div class="col-6"><span class="text-muted">Total Room Charge</span><div class="fw-semibold text-success">৳<?php echo money($booking['room_charge_total']); ?></div></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Confirm Cancellation</div>
            <div class="alert alert-warning">
                Cancelling will free up Room #<?php echo e($booking['room_number']); ?> for these dates and mark this reservation as <strong>cancelled</strong>. This cannot be undone.
            </div>
            <form method="POST">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                <div class="mb-3">
                    <label class="form-label">Reason (optional)</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" placeholder="e.g. Guest requested cancellation, duplicate booking, etc."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <a href="bookings.php" class="btn btn-outline-secondary w-100">Go Back</a>
                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to cancel this reservation?');">
                        Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>