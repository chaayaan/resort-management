<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$bookingId = (int)($_GET['booking_id'] ?? 0);
$type = $_GET['type'] ?? 'checkin'; // checkin | extend | checkout

if ($bookingId <= 0) {
    header('Location: index.php');
    exit;
}

$booking = fetch_one($conn, "
    SELECT b.*, r.room_number, rt.name AS type_name,
           g.full_name, g.phone, g.email, g.address
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN room_types rt ON rt.id = r.room_type_id
    JOIN guests g ON g.id = b.guest_id
    WHERE b.id = $bookingId
");

if (!$booking) {
    header('Location: index.php');
    exit;
}

$payments = fetch_all($conn, "SELECT * FROM payments WHERE booking_id = $bookingId ORDER BY created_at ASC");
$totalPaid = 0;
foreach ($payments as $p) {
    if ($p['payment_type'] !== 'refund') $totalPaid += $p['amount'];
    else $totalPaid -= $p['amount'];
}

$grandTotal = (float)$booking['room_charge_total'] + (float)$booking['extension_charge_total'] - (float)$booking['discount'];
$balanceDue = $grandTotal - $totalPaid;

$pageTitle = 'Receipt';
$active = 'dashboard';

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header no-print">
    <h3>Receipt</h3>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="window.print()">&#128424; Print</button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>
</div>

<div class="receipt-box">
    <div class="text-center mb-4">
        <h4 class="mb-0">Hotel Front Desk</h4>
        <div class="text-muted small">Payment Receipt</div>
    </div>

    <div class="row mb-2">
        <div class="col-6"><span class="text-muted">Invoice ID</span><div class="fw-semibold">#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></div></div>
        <div class="col-6 text-end"><span class="text-muted">Date</span><div class="fw-semibold"><?php echo date('d M Y, h:i A'); ?></div></div>
    </div>

    <hr>

    <div class="row mb-2">
        <div class="col-6"><span class="text-muted">Guest Name</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
        <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
    </div>
    <div class="row mb-2">
        <div class="col-6"><span class="text-muted">Room</span><div class="fw-semibold">#<?php echo e($booking['room_number']); ?> (<?php echo e($booking['type_name']); ?>)</div></div>
        <div class="col-6"><span class="text-muted">Status</span><div class="fw-semibold text-capitalize"><?php echo e(str_replace('_', ' ', $booking['status'])); ?></div></div>
    </div>
    <div class="row mb-2">
        <div class="col-6"><span class="text-muted">Check-in Date</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['check_in_date'])); ?></div></div>
        <div class="col-6"><span class="text-muted">Check-out Date</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['check_out_date'])); ?></div></div>
    </div>

    <hr>

    <table class="table table-sm">
        <thead>
            <tr><th>Description</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Room Charge (<?php echo (int)$booking['total_days']; ?> days &times; $<?php echo money($booking['price_per_day']); ?>)</td>
                <td class="text-end">$<?php echo money($booking['room_charge_total']); ?></td>
            </tr>
            <?php if ((float)$booking['extension_charge_total'] > 0): ?>
            <tr>
                <td>Extension Charges</td>
                <td class="text-end">$<?php echo money($booking['extension_charge_total']); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ((float)$booking['discount'] > 0): ?>
            <tr>
                <td>Discount</td>
                <td class="text-end text-danger">-$<?php echo money($booking['discount']); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="table-light">
                <td class="fw-bold">Grand Total</td>
                <td class="text-end fw-bold">$<?php echo money($grandTotal); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Payments Received</div>
    <table class="table table-sm">
        <thead>
            <tr><th>Type</th><th>Method</th><th>Date</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td class="text-capitalize"><?php echo e($p['payment_type']); ?></td>
                <td class="text-capitalize"><?php echo e(str_replace('_',' ',$p['payment_method'])); ?></td>
                <td><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
                <td class="text-end">$<?php echo money($p['amount']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($payments)): ?>
            <tr><td colspan="4" class="text-center text-muted">No payments recorded yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr>

    <div class="row">
        <div class="col-6"><span class="text-muted">Total Paid</span><div class="fw-bold text-success">$<?php echo money($totalPaid); ?></div></div>
        <div class="col-6 text-end">
            <span class="text-muted">Balance Due</span>
            <div class="fw-bold <?php echo $balanceDue > 0 ? 'text-danger' : 'text-success'; ?>">
                $<?php echo money(max($balanceDue, 0)); ?>
            </div>
        </div>
    </div>

    <div class="text-center text-muted small mt-4">Thank you for staying with us!</div>
</div>

<div class="text-center mt-3 no-print">
    <?php if ($booking['status'] === 'checked_in'): ?>
        <a href="extend.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-outline-secondary btn-sm">Extend Stay</a>
        <a href="checkout.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-danger btn-sm">Checkout</a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
