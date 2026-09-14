<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Extend Stay';
$active = 'extend';
$error = '';

$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

// =====================================================
// No booking specified -> show picker of occupied rooms
// =====================================================
if ($bookingId <= 0) {
    $occupied = fetch_all($conn, "
        SELECT b.id AS booking_id, r.room_number, g.full_name, b.reserved_until
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN guests g ON g.id = b.guest_id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
    ");

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-header">
        <h3>Extend Stay — Select Room</h3>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>

    <?php if (empty($occupied)): ?>
        <div class="alert alert-info">No occupied rooms to extend right now.</div>
    <?php else: ?>
        <div class="card-form">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Room</th><th>Guest</th><th>Current Check-out</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($occupied as $o): ?>
                        <tr>
                            <td class="fw-semibold">#<?php echo e($o['room_number']); ?></td>
                            <td><?php echo e($o['full_name']); ?></td>
                            <td><?php echo date('d M Y', strtotime($o['reserved_until'])); ?></td>
                            <td class="text-end">
                                <a href="extend.php?booking_id=<?php echo (int)$o['booking_id']; ?>" class="btn btn-sm btn-primary">Extend</a>
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
// Handle extension submission
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newReservedUntil = trim($_POST['new_check_out_date'] ?? '');
    $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

    $booking = fetch_one($conn, "SELECT * FROM bookings WHERE id = $bookingId AND status = 'checked_in'");

    if (!$booking) {
        $error = 'Booking not found or not currently checked in.';
    } elseif (strtotime($newReservedUntil) <= strtotime($booking['reserved_until'])) {
        $error = 'New check-out date must be after the current check-out date (' . date('d M Y', strtotime($booking['reserved_until'])) . ').';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $oldReservedUntil = $booking['reserved_until'];
            $addedNights = days_between($oldReservedUntil, $newReservedUntil);
            $addedCharge = $addedNights * (float)$booking['price_per_day'];

            $newReservedNights = (int)$booking['reserved_nights'] + $addedNights;
            $newExtensionTotal = (float)$booking['extension_charge_total'] + $addedCharge;
            $newExtraPaid = (float)$booking['extra_paid'] + $paymentAmount;

            $stmt = mysqli_prepare($conn, "
                UPDATE bookings
                SET reserved_until = ?, reserved_nights = ?, extension_charge_total = ?, extra_paid = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, 'siddi', $newReservedUntil, $newReservedNights, $newExtensionTotal, $newExtraPaid, $bookingId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "
                INSERT INTO booking_extensions (booking_id, old_reserved_until, new_reserved_until, added_nights, added_charge, payment_amount)
                VALUES (?,?,?,?,?,?)
            ");
            mysqli_stmt_bind_param($stmt, 'issidd', $bookingId, $oldReservedUntil, $newReservedUntil, $addedNights, $addedCharge, $paymentAmount);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($paymentAmount > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO payments (booking_id, payment_type, amount, payment_method, reference_note) VALUES (?, 'extension', ?, ?, ?)");
                $note = "Extended to " . $newReservedUntil;
                mysqli_stmt_bind_param($stmt, 'idss', $bookingId, $paymentAmount, $paymentMethod, $note);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            header('Location: receipt.php?booking_id=' . $bookingId . '&type=extend');
            exit;

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Failed to process extension.';
        }
    }
}

// =====================================================
// Load booking details for the form
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

if (!$booking || $booking['status'] !== 'checked_in') {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">This booking cannot be extended (not found or not checked-in).</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$paidSoFar = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE booking_id = $bookingId AND payment_type != 'refund'");
$totalPaidSoFar = $paidSoFar ? (float)$paidSoFar['total'] : 0;
$currentGrandTotal = (float)$booking['room_charge_total'] + (float)$booking['extension_charge_total'] - (float)$booking['discount'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Extend Stay — Room #<?php echo e($booking['room_number']); ?></h3>
    <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Current Booking</div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Guest</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Room Type</span><div class="fw-semibold"><?php echo e($booking['type_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Price / Day</span><div class="fw-semibold">৳<?php echo money($booking['price_per_day']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Current Check-out</span><div class="fw-semibold" id="currentCheckout"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></div></div>
                <div class="col-6"><span class="text-muted">Total Nights So Far</span><div class="fw-semibold"><?php echo (int)$booking['reserved_nights']; ?></div></div>
            </div>
            <hr>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Grand Total (current)</span><div class="fw-semibold">৳<?php echo money($currentGrandTotal); ?></div></div>
                <div class="col-6"><span class="text-muted">Paid So Far</span><div class="fw-semibold text-success">৳<?php echo money($totalPaidSoFar); ?></div></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">New Check-out &amp; Payment</div>
            <form method="POST" id="extendForm">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">

                <div class="mb-3">
                    <label class="form-label">New Check-out Date *</label>
                    <input type="date" name="new_check_out_date" id="new_check_out_date" class="form-control" required
                        min="<?php echo date('Y-m-d', strtotime($booking['reserved_until'] . ' +1 day')); ?>"
                        value="<?php echo date('Y-m-d', strtotime($booking['reserved_until'] . ' +1 day')); ?>">
                </div>

                <div class="p-3 rounded mb-3" style="background:#f4f6f9;">
                    <div class="d-flex justify-content-between"><span>Additional Days</span><strong id="addedDays">1</strong></div>
                    <div class="d-flex justify-content-between"><span>Additional Charge</span><strong id="addedCharge" class="text-success">৳<?php echo money($booking['price_per_day']); ?></strong></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Extension Payment Amount (৳)</label>
                    <input type="number" step="0.01" min="0" name="payment_amount" id="payment_amount" class="form-control" value="0">
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

                <button type="submit" class="btn btn-primary w-100">Save Extension &amp; Print Receipt</button>
            </form>
        </div>
    </div>
</div>

<?php
$pricePerDay = (float)$booking['price_per_day'];
$currentCheckoutJs = $booking['reserved_until'];
$extraScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pricePerDay = {$pricePerDay};
    const currentCheckout = new Date('{$currentCheckoutJs}');
    const newCheckoutInput = document.getElementById('new_check_out_date');
    const addedDaysEl = document.getElementById('addedDays');
    const addedChargeEl = document.getElementById('addedCharge');

    function recalc() {
        const newDate = new Date(newCheckoutInput.value);
        let days = Math.round((newDate - currentCheckout) / (1000*60*60*24));
        if (isNaN(days) || days < 1) days = 1;
        addedDaysEl.textContent = days;
        addedChargeEl.textContent = '৳' + (days * pricePerDay).toFixed(2);
    }

    newCheckoutInput.addEventListener('change', recalc);
    recalc();
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>