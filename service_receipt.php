<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Load resort settings directly (same approach as receipt.php)
$settings = [];
$settingsRows = fetch_all($conn, "SELECT setting_key, setting_value FROM settings");
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$resortName    = $settings['resort_name'] ?? 'Hotel PMS';
$resortAddress = $settings['resort_address'] ?? '';
$resortPhone   = $settings['resort_phone'] ?? '';

$serviceId = (int)($_GET['service_id'] ?? 0);

if ($serviceId <= 0) {
    header('Location: frontdesk.php');
    exit;
}

$order = fetch_one($conn, "
    SELECT bs.*, b.reservation_no, r.room_number, g.full_name, g.phone
    FROM booking_services bs
    JOIN bookings b ON b.id = bs.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN guests g ON g.id = b.guest_id
    WHERE bs.id = $serviceId
");

if (!$order) {
    header('Location: frontdesk.php');
    exit;
}

$items = fetch_all($conn, "SELECT * FROM booking_service_items WHERE booking_service_id = $serviceId ORDER BY id ASC");

$paidRow = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE booking_service_id = $serviceId AND payment_type = 'service'");
$paidForOrder = $paidRow ? (float)$paidRow['total'] : 0;
$dueForOrder = (float)$order['amount'] - $paidForOrder;
if ($dueForOrder < 0) $dueForOrder = 0;

$bookingId = (int)$order['booking_id'];
$billNo = 'SVC-' . date('Y') . '-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);

$pageTitle = 'Service Bill';
$active = 'service';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .svc-bill {
        width: 72mm;
        margin: 0 auto;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12px;
        color: #000;
        line-height: 1.4;
        background: #fff;
        padding: 14px;
        border: 1px solid #ddd;
    }
    .svc-bill .center { text-align: center; }
    .svc-bill .right { text-align: right; }
    .svc-bill .bold { font-weight: bold; }
    .svc-bill .divider { border-top: 1px dashed #000; margin: 6px 0; }
    .svc-bill table { width: 100%; border-collapse: collapse; }
    .svc-bill td, .svc-bill th { padding: 2px 0; vertical-align: top; }
    .svc-bill .brand { font-size: 15px; }
    .svc-bill .small { font-size: 10px; }

    @media print {
        body * { visibility: hidden !important; }
        .svc-bill, .svc-bill * { visibility: visible !important; }
        .svc-bill {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 72mm !important;
            margin: 0 !important;
            border: none !important;
        }
        .no-print { display: none !important; }
        @page { size: 80mm auto; margin: 2mm; }
    }
</style>

<div class="page-header no-print">
    <h3>Service Bill</h3>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="window.print()">&#128424; Print Bill</button>
        <a href="service.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Service</a>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">Front Desk</a>
    </div>
</div>

<div class="no-print mb-3">
    <?php if ($dueForOrder > 0): ?>
        <span class="badge bg-danger">Due: ৳<?php echo money($dueForOrder); ?></span>
        <span class="text-muted small">of ৳<?php echo money($order['amount']); ?> total &middot; ৳<?php echo money($paidForOrder); ?> paid</span>
    <?php else: ?>
        <span class="badge bg-success">Paid in Full</span>
        <span class="text-muted small">৳<?php echo money($order['amount']); ?></span>
    <?php endif; ?>
</div>

<div class="svc-bill">
    <div class="center bold brand"><?php echo e(strtoupper($resortName)); ?></div>
    <?php if ($resortAddress): ?><div class="center small"><?php echo nl2br(e($resortAddress)); ?></div><?php endif; ?>
    <?php if ($resortPhone): ?><div class="center small"><?php echo e($resortPhone); ?></div><?php endif; ?>
    <div class="center small"><?php echo e($order['service_type']); ?> Bill</div>
    <div class="divider"></div>

    <table>
        <tr><td>Bill #</td><td class="right"><?php echo e($billNo); ?></td></tr>
        <tr><td>Date</td><td class="right"><?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?></td></tr>
        <tr><td>Room</td><td class="right">#<?php echo e($order['room_number']); ?></td></tr>
        <tr><td>Guest</td><td class="right"><?php echo e($order['full_name']); ?></td></tr>
        <?php if (!empty($order['notes'])): ?>
        <tr><td>Notes</td><td class="right"><?php echo e($order['notes']); ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="divider"></div>

    <table>
        <thead>
            <tr class="small bold"><td>Item</td><td class="right">Qty</td><td class="right">Price</td><td class="right">Total</td></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?php echo e($it['item_name']); ?></td>
                <td class="right">x<?php echo (int)$it['quantity']; ?></td>
                <td class="right"><?php echo money($it['price']); ?></td>
                <td class="right"><?php echo money($it['total_price']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
            <tr><td colspan="4" class="center small">No items.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="divider"></div>

    <table>
        <tr class="bold"><td>TOTAL</td><td class="right">BDT <?php echo money($order['amount']); ?></td></tr>
        <tr><td>Paid Now</td><td class="right">BDT <?php echo money($paidForOrder); ?></td></tr>
        <tr class="bold"><td><?php echo $dueForOrder > 0 ? 'DUE' : 'BALANCE'; ?></td><td class="right"><?php echo $dueForOrder > 0 ? 'BDT ' . money($dueForOrder) : 'Paid in Full'; ?></td></tr>
    </table>
    <div class="divider"></div>

    <?php if ($dueForOrder > 0): ?>
    <div class="center small bold">Due amount will be added to Room #<?php echo e($order['room_number']); ?>'s account.</div>
    <div class="center small">Settle at checkout. THANK YOU</div>
    <?php else: ?>
    <div class="center small">Paid in full at time of order.</div>
    <?php endif; ?>
</div>

<div class="text-center mt-3 no-print">
    <a href="receipt.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-outline-secondary btn-sm">View Full Booking Receipt</a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>