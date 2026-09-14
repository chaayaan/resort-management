<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Load resort/wifi settings directly (no separate helper file)
$settings = [];
$settingsRows = fetch_all($conn, "SELECT setting_key, setting_value FROM settings");
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$resortName    = $settings['resort_name'] ?? 'Hotel PMS';
$resortAddress = $settings['resort_address'] ?? '';
$resortPhone   = $settings['resort_phone'] ?? '';
$resortEmail   = $settings['resort_email'] ?? '';
$resortWebsite = $settings['resort_website'] ?? '';
$wifiName      = $settings['wifi_name'] ?? '';
$wifiPassword  = $settings['wifi_password'] ?? '';

$bookingId = (int)($_GET['booking_id'] ?? 0);
$type = $_GET['type'] ?? 'checkin'; // checkin | extend | service | checkout

if ($bookingId <= 0) {
    header('Location: frontdesk.php');
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
    header('Location: frontdesk.php');
    exit;
}

$payments = fetch_all($conn, "SELECT * FROM payments WHERE booking_id = $bookingId ORDER BY created_at ASC");
$totalPaid = 0;
foreach ($payments as $p) {
    if ($p['payment_type'] !== 'refund') $totalPaid += $p['amount'];
    else $totalPaid -= $p['amount'];
}

$services = fetch_all($conn, "SELECT * FROM booking_services WHERE booking_id = $bookingId ORDER BY created_at ASC");
$serviceIds = array_column($services, 'id');
$serviceItemsByOrder = [];
if (!empty($serviceIds)) {
    $idList = implode(',', array_map('intval', $serviceIds));
    $allServiceItems = fetch_all($conn, "SELECT * FROM booking_service_items WHERE booking_service_id IN ($idList) ORDER BY id ASC");
    foreach ($allServiceItems as $it) {
        $serviceItemsByOrder[$it['booking_service_id']][] = $it;
    }
}

$grandTotal = (float)$booking['room_charge_total'] + (float)$booking['extension_charge_total'] + (float)$booking['service_charge_total'] - (float)$booking['discount'];
$balanceDue = $grandTotal - $totalPaid;

$invoiceNo = 'INV-' . date('Y') . '-' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT);

$pageTitle = 'Receipt';
$active = 'dashboard';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Arial&display=swap');

    :root {
        --inv-ink: #2c2c26;
        --inv-accent: #4b5a3c;      /* deep olive green */
        --inv-accent-dark: #34402a;
        --inv-gold: #b3925a;
        --inv-cream: #fbf9f3;
        --inv-line: #ddd7c6;
        --inv-muted: #8a8a7c;
    }

    /* ============ A4 INVOICE STYLE ============ */
    .receipt-box.invoice-a4 {
        background: var(--inv-cream);
        color: var(--inv-ink);
        font-family: Georgia, 'Times New Roman', serif;
        padding: 40px 44px;
        max-width: 780px;
        margin: 0 auto;
        border: 1px solid var(--inv-line);
    }

    .inv-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        padding-bottom: 22px;
        border-bottom: 2px solid var(--inv-accent);
    }
    .inv-brand { display: flex; align-items: center; gap: 14px; }
    .inv-logo {
        width: 52px; height: 52px;
        border-radius: 50%;
        background: var(--inv-accent);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }
    .inv-hotel-name {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--inv-accent-dark);
        letter-spacing: 0.5px;
        line-height: 1.1;
    }
    .inv-hotel-tag {
        font-family: Arial, sans-serif;
        font-size: 10.5px;
        letter-spacing: 3px;
        color: var(--inv-gold);
        margin-top: 3px;
    }
    .inv-title-block { text-align: right; }
    .inv-title {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 34px;
        font-weight: 700;
        color: var(--inv-accent-dark);
        letter-spacing: 3px;
    }
    .inv-title-sub {
        font-family: Arial, sans-serif;
        font-size: 11px;
        color: var(--inv-muted);
        letter-spacing: 1px;
        margin-top: 2px;
    }

    .inv-meta-row {
        display: flex;
        justify-content: space-between;
        gap: 30px;
        margin-top: 26px;
        font-family: Arial, sans-serif;
    }
    .inv-label {
        font-size: 11px;
        letter-spacing: 1.5px;
        color: var(--inv-gold);
        text-transform: uppercase;
        margin-bottom: 6px;
        font-weight: bold;
    }
    .inv-guest-name { font-size: 15px; font-weight: bold; color: var(--inv-ink); margin-bottom: 2px; }
    .inv-guest-detail { font-size: 12.5px; color: #555; line-height: 1.5; }

    .inv-meta-table { border-collapse: collapse; font-size: 12.5px; }
    .inv-meta-table td { padding: 2px 0; }
    .inv-meta-table td:first-child { color: var(--inv-muted); padding-right: 16px; white-space: nowrap; }
    .inv-meta-table td:last-child { text-align: right; font-weight: bold; color: var(--inv-ink); }

    .inv-items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 30px;
        font-family: Arial, sans-serif;
        font-size: 13px;
    }
    .inv-items-table thead th {
        text-align: left;
        font-size: 11px;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: var(--inv-accent-dark);
        border-bottom: 2px solid var(--inv-accent);
        padding: 8px 6px;
    }
    .inv-items-table thead th.num { text-align: right; }
    .inv-items-table tbody td { padding: 10px 6px; border-bottom: 1px solid var(--inv-line); color: var(--inv-ink); }
    .inv-items-table tbody td.num { text-align: right; }
    .inv-items-table tbody tr:nth-child(even) { background: #f4f1e6; }
    .inv-items-table .discount-row td { color: #a33d3d; }

    .inv-summary { display: flex; justify-content: flex-end; margin-top: 8px; font-family: Arial, sans-serif; }
    .inv-summary table { border-collapse: collapse; min-width: 260px; }
    .inv-summary td { padding: 6px 8px; font-size: 13px; }
    .inv-summary td:first-child { color: var(--inv-muted); }
    .inv-summary td.num { text-align: right; font-weight: bold; color: var(--inv-ink); }
    .inv-summary tr.inv-total-due td {
        background: #ece4cd;
        font-size: 15px;
        font-weight: bold;
        color: var(--inv-accent-dark);
        padding-top: 10px;
        padding-bottom: 10px;
        border-top: 2px solid var(--inv-accent);
    }
    .inv-summary tr.inv-balance td.num { color: #a33d3d; }
    .inv-summary tr.inv-paid td.num { color: #3f6b46; }

    .inv-payments {
        margin-top: 30px;
        font-family: Arial, sans-serif;
    }
    .inv-payments .inv-label { margin-bottom: 8px; }
    .inv-payments table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .inv-payments thead th {
        text-align: left; font-size: 10.5px; letter-spacing: 1px; text-transform: uppercase;
        color: var(--inv-muted); padding: 6px; border-bottom: 1px solid var(--inv-line);
    }
    .inv-payments thead th.num { text-align: right; }
    .inv-payments tbody td { padding: 7px 6px; border-bottom: 1px solid var(--inv-line); }
    .inv-payments tbody td.num { text-align: right; }

    .inv-footer {
        margin-top: 34px;
        padding-top: 18px;
        border-top: 1px solid var(--inv-line);
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 20px;
        font-family: Arial, sans-serif;
    }
    .inv-payment-method { font-size: 11.5px; color: var(--inv-muted); line-height: 1.6; }
    .inv-payment-method strong { color: var(--inv-ink); }
    .inv-thankyou { text-align: right; font-size: 12px; color: var(--inv-accent-dark); font-style: italic; }
    .inv-wifi-qr { text-align: center; flex-shrink: 0; }
    .inv-wifi-qr canvas { display: block; margin: 0 auto; }
    .inv-wifi-qr-label { font-size: 9.5px; color: var(--inv-muted); margin-top: 4px; letter-spacing: 0.3px; }

    /* ============ POS RECEIPT STYLE ============ */
    .pos-receipt {
        display: none;
        width: 72mm;
        margin: 0 auto;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12px;
        color: #000;
        line-height: 1.4;
    }
    .pos-receipt .center { text-align: center; }
    .pos-receipt .right { text-align: right; }
    .pos-receipt .bold { font-weight: bold; }
    .pos-receipt .divider { border-top: 1px dashed #000; margin: 6px 0; }
    .pos-receipt table { width: 100%; border-collapse: collapse; }
    .pos-receipt td { padding: 1px 0; vertical-align: top; }
    .pos-receipt .brand { font-size: 15px; }
    .pos-receipt .small { font-size: 10px; }
    .pos-wifi-qr { padding: 6px 0; text-align: center; }
    .pos-wifi-qr #wifiQrPos { display: flex; justify-content: center; }
    .pos-wifi-qr canvas, .pos-wifi-qr img { display: block; margin: 0 auto; }
    .pos-wifi-qr .small { display: block; margin-top: 4px; }

    @media print {
        /* Hide EVERYTHING on the page (sidebar, header, nav, wrappers, etc.) */
        body * { visibility: hidden !important; }

        /* Then reveal only the receipt block that matches the chosen print mode,
           plus all of its children */
        body:not(.print-mode-pos) .receipt-box,
        body:not(.print-mode-pos) .receipt-box * {
            visibility: visible !important;
        }
        body.print-mode-pos .pos-receipt,
        body.print-mode-pos .pos-receipt * {
            visibility: visible !important;
        }

        /* visibility:hidden doesn't override display:none, so also fix display */
        body.print-mode-pos .pos-receipt { display: block !important; }
        body.print-mode-pos .receipt-box { display: none !important; }
        body:not(.print-mode-pos) .pos-receipt { display: none !important; }

        /* Pull the visible block out of the layout flow and pin it to the page
           so it isn't affected by the sidebar's width/columns/flex layout */
        .receipt-box, .pos-receipt {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            box-shadow: none !important;
            border: none !important;
        }

        .pos-receipt { width: 72mm !important; }

        /* Explicitly never print action buttons/back links even if caught by a selector above */
        .no-print { display: none !important; }
    }
</style>

<div class="page-header no-print">
    <h3>Receipt</h3>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="printReceipt('a4')">&#128424; Print A4 Invoice</button>
        <button class="btn btn-secondary btn-sm" onclick="printReceipt('pos')">&#128424; Print POS Receipt</button>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>
</div>

<div class="receipt-box invoice-a4">

    <div class="inv-header">
        <div class="inv-brand">
            <div class="inv-logo">&#127976;</div>
            <div>
                <div class="inv-hotel-name"><?php echo e($resortName); ?></div>
                <?php if ($resortAddress || $resortPhone || $resortEmail): ?>
                <div class="inv-hotel-tag">
                    <?php
                        $tagParts = array_filter([$resortPhone, $resortEmail, $resortWebsite]);
                        echo e(implode(' · ', $tagParts));
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="inv-title-block">
            <div class="inv-title">INVOICE</div>
            <div class="inv-title-sub">Payment Receipt</div>
        </div>
    </div>
    <?php if ($resortAddress): ?>
    <div class="small text-muted mt-2" style="font-family: Arial, sans-serif; font-size: 11px;"><?php echo nl2br(e($resortAddress)); ?></div>
    <?php endif; ?>

    <div class="inv-meta-row">
        <div>
            <div class="inv-label">Billed To</div>
            <div class="inv-guest-name"><?php echo e($booking['full_name']); ?></div>
            <?php if (!empty($booking['phone'])): ?><div class="inv-guest-detail"><?php echo e($booking['phone']); ?></div><?php endif; ?>
            <?php if (!empty($booking['email'])): ?><div class="inv-guest-detail"><?php echo e($booking['email']); ?></div><?php endif; ?>
            <?php if (!empty($booking['address'])): ?><div class="inv-guest-detail"><?php echo e($booking['address']); ?></div><?php endif; ?>
        </div>
        <table class="inv-meta-table">
            <tr><td>Invoice No:</td><td><?php echo e($invoiceNo); ?></td></tr>
            <tr><td>Date Issued:</td><td><?php echo date('d M Y'); ?></td></tr>
            <tr><td>Room:</td><td>#<?php echo e($booking['room_number']); ?> &mdash; <?php echo e($booking['type_name']); ?></td></tr>
            <tr><td>Stay:</td><td><?php echo date('d M', strtotime($booking['reserved_from'])); ?> &ndash; <?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></td></tr>
            <?php if (!empty($booking['checkin_at'])): ?>
            <tr><td>Checked In:</td><td><?php echo date('d M Y, h:i A', strtotime($booking['checkin_at'])); ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($booking['checkout_at'])): ?>
            <tr><td>Checked Out:</td><td><?php echo date('d M Y, h:i A', strtotime($booking['checkout_at'])); ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <table class="inv-items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Room Charge &ndash; #<?php echo e($booking['room_number']); ?> (<?php echo e($booking['type_name']); ?>)</td>
                <td class="num"><?php echo (int)$booking['reserved_nights']; ?></td>
                <td class="num">BDT <?php echo money($booking['price_per_day']); ?></td>
                <td class="num">BDT <?php echo money($booking['room_charge_total']); ?></td>
            </tr>
            <?php if ((float)$booking['extension_charge_total'] > 0): ?>
            <tr>
                <td>Extension Charges</td>
                <td class="num">&mdash;</td>
                <td class="num">&mdash;</td>
                <td class="num">BDT <?php echo money($booking['extension_charge_total']); ?></td>
            </tr>
            <?php endif; ?>
            <?php foreach ($services as $s): ?>
            <tr>
                <td>Service Order &ndash; <?php echo e($s['service_type']); ?> #<?php echo (int)$s['id']; ?><?php if (!empty($s['notes'])): ?><span class="text-muted"> (<?php echo e($s['notes']); ?>)</span><?php endif; ?></td>
                <td class="num">&mdash;</td>
                <td class="num">&mdash;</td>
                <td class="num">BDT <?php echo money($s['amount']); ?></td>
            </tr>
            <?php foreach (($serviceItemsByOrder[$s['id']] ?? []) as $it): ?>
            <tr>
                <td style="padding-left: 22px; color:#7a7a6c;">&ndash; <?php echo e($it['item_name']); ?></td>
                <td class="num"><?php echo (int)$it['quantity']; ?></td>
                <td class="num">BDT <?php echo money($it['price']); ?></td>
                <td class="num">BDT <?php echo money($it['total_price']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ((float)$booking['discount'] > 0): ?>
            <tr class="discount-row">
                <td>Discount</td>
                <td class="num">&mdash;</td>
                <td class="num">&mdash;</td>
                <td class="num">-BDT <?php echo money($booking['discount']); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="inv-summary">
        <table>
            <tr><td>Subtotal:</td><td class="num">BDT <?php echo money($booking['room_charge_total'] + $booking['extension_charge_total'] + $booking['service_charge_total']); ?></td></tr>
            <?php if ((float)$booking['discount'] > 0): ?>
            <tr><td>Discount:</td><td class="num">-BDT <?php echo money($booking['discount']); ?></td></tr>
            <?php endif; ?>
            <tr class="inv-total-due"><td>Total Due:</td><td class="num">BDT <?php echo money($grandTotal); ?></td></tr>
            <tr class="inv-paid"><td>Total Paid:</td><td class="num">BDT <?php echo money($totalPaid); ?></td></tr>
            <tr class="inv-balance"><td>Balance Due:</td><td class="num">BDT <?php echo money(max($balanceDue, 0)); ?></td></tr>
        </table>
    </div>

    <div class="inv-payments">
        <div class="inv-label">Payments Received</div>
        <table>
            <thead>
                <tr><th>Type</th><th>Method</th><th>Date</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-capitalize"><?php echo e($p['payment_type']); ?></td>
                    <td class="text-capitalize"><?php echo e(str_replace('_', ' ', $p['payment_method'])); ?></td>
                    <td><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
                    <td class="num"><?php echo ($p['payment_type'] !== 'refund' ? '' : '-'); ?>BDT <?php echo money($p['amount']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                <tr><td colspan="4" class="text-center text-muted">No payments recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="inv-footer">
        <div class="inv-payment-method">
            <?php if ($wifiName): ?>
                <strong>Guest Wi-Fi:</strong> <?php echo e($wifiName); ?>
                <?php if ($wifiPassword): ?> &nbsp;|&nbsp; <strong>Password:</strong> <?php echo e($wifiPassword); ?><?php endif; ?>
                <br>
            <?php endif; ?>
            <?php if ($resortWebsite): ?><?php echo e($resortWebsite); ?><?php endif; ?>
        </div>
        <?php if ($wifiName): ?>
        <div class="inv-wifi-qr">
            <div id="wifiQrA4"></div>
            <div class="inv-wifi-qr-label">Scan to join Wi-Fi</div>
        </div>
        <?php endif; ?>
        <div class="inv-thankyou">
            Thank you for staying with us!<br>
            We hope to welcome you back soon.
        </div>
    </div>

</div>

<div class="pos-receipt">
    <div class="center bold brand"><?php echo e(strtoupper($resortName)); ?></div>
    <?php if ($resortAddress): ?><div class="center small"><?php echo nl2br(e($resortAddress)); ?></div><?php endif; ?>
    <?php if ($resortPhone): ?><div class="center small"><?php echo e($resortPhone); ?></div><?php endif; ?>
    <?php if ($resortWebsite): ?><div class="center small"><?php echo e($resortWebsite); ?></div><?php endif; ?>
    <div class="center small">Payment Receipt</div>
    <div class="divider"></div>

    <table>
        <tr><td>Invoice #</td><td class="right"><?php echo e($invoiceNo); ?></td></tr>
        <tr><td>Date</td><td class="right"><?php echo date('d M Y, h:i A'); ?></td></tr>
    </table>
    <div class="divider"></div>

    <table>
        <tr><td>Guest</td><td class="right"><?php echo e($booking['full_name']); ?></td></tr>
        <tr><td>Phone</td><td class="right"><?php echo e($booking['phone']); ?></td></tr>
        <tr><td>Room</td><td class="right">#<?php echo e($booking['room_number']); ?> (<?php echo e($booking['type_name']); ?>)</td></tr>
        <tr><td>Status</td><td class="right text-capitalize"><?php echo e(str_replace('_', ' ', $booking['status'])); ?></td></tr>
        <tr><td>Reserved From</td><td class="right"><?php echo date('d M Y', strtotime($booking['reserved_from'])); ?></td></tr>
        <tr><td>Reserved Until</td><td class="right"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></td></tr>
        <?php if (!empty($booking['checkin_at'])): ?>
        <tr><td>Actual Check-in</td><td class="right"><?php echo date('d M Y, h:i A', strtotime($booking['checkin_at'])); ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($booking['checkout_at'])): ?>
        <tr><td>Actual Check-out</td><td class="right"><?php echo date('d M Y, h:i A', strtotime($booking['checkout_at'])); ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="divider"></div>

    <table>
        <tr>
            <td>Room (<?php echo (int)$booking['reserved_nights']; ?>d &times; BDT <?php echo money($booking['price_per_day']); ?>)</td>
            <td class="right">BDT <?php echo money($booking['room_charge_total']); ?></td>
        </tr>
        <?php if ((float)$booking['extension_charge_total'] > 0): ?>
        <tr>
            <td>Extension</td>
            <td class="right">BDT <?php echo money($booking['extension_charge_total']); ?></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($services as $s): ?>
        <tr>
            <td>Svc: <?php echo e($s['service_type']); ?> #<?php echo (int)$s['id']; ?></td>
            <td class="right">BDT <?php echo money($s['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ((float)$booking['discount'] > 0): ?>
        <tr>
            <td>Discount</td>
            <td class="right">-BDT <?php echo money($booking['discount']); ?></td>
        </tr>
        <?php endif; ?>
    </table>
    <div class="divider"></div>
    <table>
        <tr class="bold"><td>GRAND TOTAL</td><td class="right">BDT <?php echo money($grandTotal); ?></td></tr>
    </table>
    <div class="divider"></div>

    <div class="bold">Payments</div>
    <table>
        <?php foreach ($payments as $p): ?>
        <tr>
            <td>
                <span class="text-capitalize"><?php echo e($p['payment_type']); ?></span>
                <span class="small"><?php echo e(str_replace('_', ' ', $p['payment_method'])); ?></span><br>
                <span class="small"><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></span>
            </td>
            <td class="right"><?php echo ($p['payment_type'] !== 'refund' ? '' : '-'); ?>BDT <?php echo money($p['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?>
        <tr><td colspan="2" class="center small">No payments recorded yet.</td></tr>
        <?php endif; ?>
    </table>
    <div class="divider"></div>

    <table>
        <tr><td>Total Paid</td><td class="right">BDT <?php echo money($totalPaid); ?></td></tr>
        <tr class="bold"><td>Balance Due</td><td class="right">BDT <?php echo money(max($balanceDue, 0)); ?></td></tr>
    </table>
    <div class="divider"></div>

    <?php if ($wifiName): ?>
    <table>
        <tr><td>Wi-Fi</td><td class="right"><?php echo e($wifiName); ?></td></tr>
        <?php if ($wifiPassword): ?>
        <tr><td>Password</td><td class="right"><?php echo e($wifiPassword); ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="center pos-wifi-qr">
        <div id="wifiQrPos"></div>
        <div class="small">Scan to join Wi-Fi</div>
    </div>
    <div class="divider"></div>
    <?php endif; ?>

    <div class="center small">Thank you for staying with us!</div>
</div>

<div class="text-center mt-3 no-print">
    <?php if ($booking['status'] === 'checked_in'): ?>
        <a href="extend.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-outline-secondary btn-sm">Extend Stay</a>
        <a href="service.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-outline-secondary btn-sm">Add Service</a>
        <a href="checkout.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-danger btn-sm">Checkout</a>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
function printReceipt(mode) {
    // mode: 'a4' or 'pos'
    document.body.classList.remove('print-mode-a4', 'print-mode-pos');
    document.body.classList.add('print-mode-' + mode);

    // @page size can't be scoped by a CSS class, so inject it dynamically
    var pageStyle = document.getElementById('dynamic-page-style');
    if (!pageStyle) {
        pageStyle = document.createElement('style');
        pageStyle.id = 'dynamic-page-style';
        document.head.appendChild(pageStyle);
    }
    pageStyle.innerHTML = (mode === 'pos')
        ? '@page { size: 80mm auto; margin: 2mm; }'
        : '@page { size: A4; margin: 15mm; }';

    window.print();
}

<?php if ($wifiName): ?>
document.addEventListener('DOMContentLoaded', function () {
    // Escape special characters per the WIFI: URI spec (\, ;, ,, :)
    function escapeWifiField(value) {
        return String(value).replace(/([\\;,:"])/g, '\\$1');
    }

    var ssid = <?php echo json_encode($wifiName); ?>;
    var password = <?php echo json_encode($wifiPassword); ?>;
    var authType = password ? 'WPA' : 'nopass';
    var wifiString = 'WIFI:T:' + authType + ';S:' + escapeWifiField(ssid) + ';'
        + (password ? 'P:' + escapeWifiField(password) + ';' : '')
        + ';';

    var a4Target = document.getElementById('wifiQrA4');
    if (a4Target) {
        new QRCode(a4Target, { text: wifiString, width: 84, height: 84, correctLevel: QRCode.CorrectLevel.M });
    }
    var posTarget = document.getElementById('wifiQrPos');
    if (posTarget) {
        new QRCode(posTarget, { text: wifiString, width: 100, height: 100, correctLevel: QRCode.CorrectLevel.M });
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>