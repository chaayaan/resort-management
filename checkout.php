<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pageTitle = 'Checkout';
$active = 'checkout';
$error = '';

$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

// =====================================================
// No booking specified -> show picker of occupied rooms
// =====================================================
if ($bookingId <= 0) {
    $occupied = fetch_all($conn, "
        SELECT b.id AS booking_id, r.room_number, g.full_name, b.reserved_from, b.reserved_until
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN guests g ON g.id = b.guest_id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
    ");

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-header">
        <h3>Checkout — Select Room</h3>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>

    <?php if (empty($occupied)): ?>
        <div class="alert alert-info">No occupied rooms right now.</div>
    <?php else: ?>
        <div class="card-form">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Room</th><th>Guest</th><th>Reservation Range</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($occupied as $o): ?>
                        <tr>
                            <td class="fw-semibold">#<?php echo e($o['room_number']); ?></td>
                            <td><?php echo e($o['full_name']); ?></td>
                            <td><?php echo date('d M Y', strtotime($o['reserved_from'])); ?> &ndash; <?php echo date('d M Y', strtotime($o['reserved_until'])); ?></td>
                            <td class="text-end">
                                <a href="checkout.php?booking_id=<?php echo (int)$o['booking_id']; ?>" class="btn btn-sm btn-danger">Checkout</a>
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
// Handle final settlement submission
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $finalPayment = (float)($_POST['final_payment'] ?? 0);
    $checkoutDiscount = (float)($_POST['discount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

    $booking = fetch_one($conn, "SELECT * FROM bookings WHERE id = $bookingId AND status = 'checked_in'");

    if (!$booking) {
        $error = 'Booking not found or not currently checked in.';
    } elseif ($checkoutDiscount < 0) {
        $error = 'Discount cannot be negative.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $newFinalPaid = (float)$booking['final_paid'] + $finalPayment;
            // Checkout discount is added on top of any discount already applied at
            // check-in, rather than overwriting it.
            $newTotalDiscount = (float)$booking['discount'] + $checkoutDiscount;

            // checkout_at records the ACTUAL departure timestamp, independent of the
            // planned reserved_until date (guest may leave early, late, or on time).
            $stmt = mysqli_prepare($conn, "
                UPDATE bookings
                SET final_paid = ?, discount = ?, status = 'checked_out', checkout_at = NOW(), checkout_by = ?
                WHERE id = ?
            ");
            $checkoutByUserId = (int)current_user()['id'];
            mysqli_stmt_bind_param($stmt, 'ddii', $newFinalPaid, $newTotalDiscount, $checkoutByUserId, $bookingId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($finalPayment > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO payments (booking_id, payment_type, amount, payment_method, reference_note) VALUES (?, 'final', ?, ?, 'Checkout settlement')");
                mysqli_stmt_bind_param($stmt, 'ids', $bookingId, $finalPayment, $paymentMethod);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            // Reset room to available, clearing occupant association (booking stays for history, filtered by status elsewhere)
            mysqli_query($conn, "UPDATE rooms SET status = 'available' WHERE id = " . (int)$booking['room_id']);

            mysqli_commit($conn);
            header('Location: receipt.php?booking_id=' . $bookingId . '&type=checkout');
            exit;

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Failed to process checkout.';
        }
    }
}

// =====================================================
// Load booking + ledger for the settlement form
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
    echo '<div class="alert alert-danger">This booking cannot be checked out (not found or not checked-in).</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$paidSoFar = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE booking_id = $bookingId AND payment_type != 'refund'");
$totalPaidSoFar = $paidSoFar ? (float)$paidSoFar['total'] : 0;

$grandTotal = (float)$booking['room_charge_total'] + (float)$booking['extension_charge_total'];
$existingDiscount = (float)$booking['discount']; // discount already applied at check-in
$balanceBeforeDiscount = $grandTotal - $existingDiscount - $totalPaidSoFar;

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Checkout — Room #<?php echo e($booking['room_number']); ?></h3>
    <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Financial Ledger</div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Guest</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Reserved From</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_from'])); ?></div></div>
                <div class="col-6"><span class="text-muted">Reserved Until</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></div></div>
            </div>
            <table class="table table-sm mt-3">
                <tbody>
                    <tr><td>Room Charge (<?php echo (int)$booking['reserved_nights']; ?> nights)</td><td class="text-end">৳<?php echo money($booking['room_charge_total']); ?></td></tr>
                    <?php if ((float)$booking['extension_charge_total'] > 0): ?>
                    <tr><td>Extension Charges</td><td class="text-end">৳<?php echo money($booking['extension_charge_total']); ?></td></tr>
                    <?php endif; ?>
                    <tr class="table-light"><td class="fw-bold">Total Charges</td><td class="text-end fw-bold">৳<?php echo money($grandTotal); ?></td></tr>
                    <?php if ($existingDiscount > 0): ?>
                    <tr><td>Discount (applied at check-in)</td><td class="text-end text-danger">-৳<?php echo money($existingDiscount); ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Advance Paid</td><td class="text-end text-success">-৳<?php echo money($booking['advance_paid']); ?></td></tr>
                    <?php if ((float)$booking['extra_paid'] > 0): ?>
                    <tr><td>Extension Payments</td><td class="text-end text-success">-৳<?php echo money($booking['extra_paid']); ?></td></tr>
                    <?php endif; ?>
                    <tr class="table-light"><td class="fw-bold">Balance Before Additional Discount</td><td class="text-end fw-bold" id="balanceBeforeDiscount" data-value="<?php echo $balanceBeforeDiscount; ?>">৳<?php echo money($balanceBeforeDiscount); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Settle &amp; Checkout</div>
            <form method="POST">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">

                <div class="mb-3">
                    <label class="form-label">Additional Discount (৳)</label>
                    <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="0">
                    <div class="form-text">
                        Added on top of the ৳<?php echo money($existingDiscount); ?> discount already applied at check-in
                        (total discount will become ৳<?php echo money($existingDiscount); ?> + this amount).
                    </div>
                </div>

                <div class="p-3 rounded mb-3" style="background:#f4f6f9;">
                    <div class="d-flex justify-content-between fs-5">
                        <span>Final Balance Due</span>
                        <strong class="text-danger" id="finalBalance">৳<?php echo money($balanceBeforeDiscount); ?></strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Final Payment Received (৳)</label>
                    <input type="number" step="0.01" min="0" name="final_payment" id="final_payment" class="form-control" value="<?php echo money(max($balanceBeforeDiscount,0)); ?>">
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

                <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Confirm checkout? This will mark the room as available.');">
                    Complete Checkout &amp; Print Receipt
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const baseBalance = parseFloat(document.getElementById('balanceBeforeDiscount').dataset.value || 0);
    const discountInput = document.getElementById('discount');
    const finalBalanceEl = document.getElementById('finalBalance');
    const finalPaymentInput = document.getElementById('final_payment');

    function recalc() {
        let discount = parseFloat(discountInput.value || 0);
        if (isNaN(discount) || discount < 0) discount = 0;
        let balance = baseBalance - discount;
        if (balance < 0) balance = 0;
        finalBalanceEl.textContent = '৳' + balance.toFixed(2);
        finalPaymentInput.value = balance.toFixed(2);
    }

    discountInput.addEventListener('input', recalc);
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>