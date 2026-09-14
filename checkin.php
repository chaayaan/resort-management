<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Check-In';
$active = 'dashboard';
$error = '';

$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
    header('Location: frontdesk.php');
    exit;
}

// ---- Handle advance payment + discount submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $advance = (float)($_POST['advance_paid'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

    $booking = fetch_one($conn, "SELECT * FROM bookings WHERE id = $bookingId AND status = 'reserved'");

    if (!$booking) {
        $error = 'Booking not found or already checked in.';
    } elseif ($advance < 0) {
        $error = 'Advance payment cannot be negative.';
    } elseif ($discount < 0) {
        $error = 'Discount cannot be negative.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // checkin_at records the ACTUAL arrival timestamp, independent of the
            // planned reserved_from date (guest may arrive early, late, or exactly on time).
            $stmt = mysqli_prepare($conn, "UPDATE bookings SET advance_paid = ?, discount = ?, status = 'checked_in', checkin_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ddi', $advance, $discount, $bookingId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_query($conn, "UPDATE rooms SET status = 'occupied' WHERE id = " . (int)$booking['room_id']);

            if ($advance > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO payments (booking_id, payment_type, amount, payment_method) VALUES (?, 'advance', ?, ?)");
                mysqli_stmt_bind_param($stmt, 'ids', $bookingId, $advance, $paymentMethod);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            header('Location: receipt.php?booking_id=' . $bookingId . '&type=checkin');
            exit;
        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Failed to process check-in.';
        }
    }
}

$booking = fetch_one($conn, "
    SELECT b.*, r.room_number, r.price_per_day AS current_price, rt.name AS type_name,
           g.full_name, g.phone, g.email, g.id_proof_type, g.id_proof_number, g.address
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN room_types rt ON rt.id = r.room_type_id
    JOIN guests g ON g.id = b.guest_id
    WHERE b.id = $bookingId
");

if (!$booking) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Booking not found.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$alreadyCheckedIn = $booking['status'] !== 'reserved';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Check-In — Room #<?php echo e($booking['room_number']); ?></h3>
    <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<?php if ($alreadyCheckedIn): ?>
    <div class="alert alert-info">
        This booking is already <strong><?php echo e($booking['status']); ?></strong>.
        <a href="receipt.php?booking_id=<?php echo $bookingId; ?>&type=checkin">View Receipt</a>
    </div>
<?php else: ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card-form">
            <div class="section-title">Guest &amp; Stay Details</div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Guest Name</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Room Type</span><div class="fw-semibold"><?php echo e($booking['type_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Price / Day</span><div class="fw-semibold">৳<?php echo money($booking['price_per_day']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reservation #</span><div class="fw-semibold"><?php echo e($booking['reservation_no']); ?></div></div>
                <div class="col-6"><span class="text-muted">Reservation Date</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reservation_date'])); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reserved From</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_from'])); ?></div></div>
                <div class="col-6"><span class="text-muted">Reserved Until</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reserved Nights</span><div class="fw-semibold"><?php echo (int)$booking['reserved_nights']; ?></div></div>
                <div class="col-6"><span class="text-muted">Total Room Charge</span><div class="fw-semibold text-success">৳<?php echo money($booking['room_charge_total']); ?></div></div>
            </div>

            <hr>
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">Changed your mind, or guest is a no-show?</div>
                <a href="reservation_cancel.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-sm btn-outline-danger">
                    Cancel This Reservation
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-form">
            <div class="section-title">Advance Payment</div>
            <form method="POST">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">

                <div class="mb-3">
                    <label class="form-label">Discount (৳)</label>
                    <input type="number" step="0.01" min="0" name="discount" id="checkinDiscount" class="form-control" value="0">
                    <div class="form-text">Applied against the total room charge below.</div>
                </div>

                <div class="p-3 rounded mb-3" style="background:#f4f6f9;" data-total="<?php echo (float)$booking['room_charge_total']; ?>">
                    <div class="d-flex justify-content-between"><span>Total Room Charge</span><strong>৳<?php echo money($booking['room_charge_total']); ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Discount</span><strong id="checkinDiscountDisplay">-৳0.00</strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between fs-5"><span>Net Due</span><strong class="text-success" id="checkinNetDue">৳<?php echo money($booking['room_charge_total']); ?></strong></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Advance Amount (৳)</label>
                    <input type="number" step="0.01" min="0" name="advance_paid" id="checkinAdvance" class="form-control" value="0" required>
                    <div class="form-text">This is the amount collected now; it does not need to equal the net due.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="mobile_banking">Mobile Banking</option>
                        <option value="bank_transfer">Bank Transfer</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100">Confirm Check-In &amp; Print Receipt</button>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const discountInput = document.getElementById('checkinDiscount');
    const discountDisplay = document.getElementById('checkinDiscountDisplay');
    const netDueEl = document.getElementById('checkinNetDue');
    const totalBox = discountInput ? discountInput.closest('form').querySelector('[data-total]') : null;

    if (!discountInput || !totalBox) return;

    const total = parseFloat(totalBox.dataset.total || 0);

    function recalc() {
        let discount = parseFloat(discountInput.value || 0);
        if (isNaN(discount) || discount < 0) discount = 0;
        let net = total - discount;
        if (net < 0) net = 0;
        discountDisplay.textContent = '-৳' + discount.toFixed(2);
        netDueEl.textContent = '৳' + net.toFixed(2);
    }

    discountInput.addEventListener('input', recalc);
    recalc();
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>