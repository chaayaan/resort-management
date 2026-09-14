<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pageTitle = 'Guest Service';
$active = 'service';
$error = '';

$bookingId = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

// Preset list of common service types; "Other" lets staff type a custom label.
$serviceTypes = ['Food', 'Room Service', 'Laundry', 'Spa', 'Transport', 'Other'];

// =====================================================
// No booking specified -> show picker of checked-in bookings
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
        <h3>Guest Service — Select Room</h3>
        <div class="d-flex gap-2">
            <a href="services.php" class="btn btn-outline-secondary btn-sm">All Services</a>
            <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
        </div>
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
                                <a href="service.php?booking_id=<?php echo (int)$o['booking_id']; ?>" class="btn btn-sm btn-primary">Add Service</a>
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
// Handle new service order submission (order header + N items)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceType = trim($_POST['service_type'] ?? '');
    $customType = trim($_POST['custom_service_type'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $itemNames = $_POST['item_name'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemPrices = $_POST['item_price'] ?? [];
    $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

    if ($serviceType === 'Other' && $customType !== '') {
        $serviceType = $customType;
    }

    $booking = fetch_one($conn, "SELECT * FROM bookings WHERE id = $bookingId AND status = 'checked_in'");

    // Build a clean list of valid item rows (non-empty name, qty >= 1, price >= 0)
    $items = [];
    $rowCount = max(count($itemNames), count($itemQtys), count($itemPrices));
    for ($i = 0; $i < $rowCount; $i++) {
        $name = trim($itemNames[$i] ?? '');
        if ($name === '') continue;
        $qty = max(1, (int)($itemQtys[$i] ?? 1));
        $price = (float)($itemPrices[$i] ?? 0);
        if ($price < 0) $price = 0;
        $items[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'total' => $qty * $price];
    }

    if (!$booking) {
        $error = 'Booking not found or not currently checked in.';
    } elseif ($serviceType === '') {
        $error = 'Please select or enter a service type.';
    } elseif (empty($items)) {
        $error = 'Please add at least one item.';
    } elseif ($paymentAmount < 0) {
        $error = 'Payment amount cannot be negative.';
    } else {
        $orderTotal = array_sum(array_column($items, 'total'));

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO booking_services (booking_id, service_type, notes, amount, created_by)
                VALUES (?,?,?,?,?)
            ");
            $createdByUserId = (int)current_user()['id'];
            mysqli_stmt_bind_param($stmt, 'issdi', $bookingId, $serviceType, $notes, $orderTotal, $createdByUserId);
            mysqli_stmt_execute($stmt);
            $serviceOrderId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $itemStmt = mysqli_prepare($conn, "
                INSERT INTO booking_service_items (booking_id, booking_service_id, item_name, quantity, price, total_price)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($items as $item) {
                mysqli_stmt_bind_param($itemStmt, 'iisidd', $bookingId, $serviceOrderId, $item['name'], $item['qty'], $item['price'], $item['total']);
                mysqli_stmt_execute($itemStmt);
            }
            mysqli_stmt_close($itemStmt);

            $newServiceTotal = (float)$booking['service_charge_total'] + $orderTotal;
            $newServicePaid = (float)$booking['service_paid'] + $paymentAmount;

            $stmt = mysqli_prepare($conn, "
                UPDATE bookings
                SET service_charge_total = ?, service_paid = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, 'ddi', $newServiceTotal, $newServicePaid, $bookingId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($paymentAmount > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO payments (booking_id, booking_service_id, payment_type, amount, payment_method, reference_note) VALUES (?, ?, 'service', ?, ?, ?)");
                $note = $serviceType . ' order #' . $serviceOrderId;
                mysqli_stmt_bind_param($stmt, 'iidss', $bookingId, $serviceOrderId, $paymentAmount, $paymentMethod, $note);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);
            // Straight to the dedicated service bill so it can be printed immediately.
            header('Location: service_receipt.php?service_id=' . $serviceOrderId);
            exit;

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = 'Failed to record the service.';
        }
    }
}

// =====================================================
// Load booking + service order history for the form
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
    echo '<div class="alert alert-danger">Services can only be added to a currently checked-in booking (not found or not checked-in).</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$orders = fetch_all($conn, "SELECT bs.*, u.full_name AS added_by_name FROM booking_services bs LEFT JOIN users u ON u.id = bs.created_by WHERE bs.booking_id = $bookingId ORDER BY bs.created_at DESC");
$orderIds = array_column($orders, 'id');
$itemsByOrder = [];
if (!empty($orderIds)) {
    $idList = implode(',', array_map('intval', $orderIds));
    $allItems = fetch_all($conn, "SELECT * FROM booking_service_items WHERE booking_service_id IN ($idList) ORDER BY id ASC");
    foreach ($allItems as $it) {
        $itemsByOrder[$it['booking_service_id']][] = $it;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Guest Service — Room #<?php echo e($booking['room_number']); ?></h3>
    <div class="d-flex gap-2">
        <a href="services.php" class="btn btn-outline-secondary btn-sm">All Services</a>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">Guest &amp; Booking</div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Guest</span><div class="fw-semibold"><?php echo e($booking['full_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Phone</span><div class="fw-semibold"><?php echo e($booking['phone']); ?></div></div>
            </div>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Room Type</span><div class="fw-semibold"><?php echo e($booking['type_name']); ?></div></div>
                <div class="col-6"><span class="text-muted">Reserved Until</span><div class="fw-semibold"><?php echo date('d M Y', strtotime($booking['reserved_until'])); ?></div></div>
            </div>
            <hr>
            <div class="row mb-2">
                <div class="col-6"><span class="text-muted">Total Services Charged</span><div class="fw-semibold">৳<?php echo money($booking['service_charge_total']); ?></div></div>
                <div class="col-6"><span class="text-muted">Total Services Paid</span><div class="fw-semibold text-success">৳<?php echo money($booking['service_paid']); ?></div></div>
            </div>

            <div class="section-title mt-4">Service Order History</div>
            <?php if (empty($orders)): ?>
                <div class="text-muted small">No services added yet.</div>
            <?php else: ?>
                <?php foreach ($orders as $ord): ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?php echo e($ord['service_type']); ?> <span class="text-muted small">#<?php echo (int)$ord['id']; ?></span></div>
                                <div class="text-muted small"><?php echo date('d M Y, h:i A', strtotime($ord['created_at'])); ?> &middot; <?php echo e($ord['added_by_name'] ?? '—'); ?></div>
                                <?php if (!empty($ord['notes'])): ?><div class="text-muted small"><?php echo e($ord['notes']); ?></div><?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold">৳<?php echo money($ord['amount']); ?></div>
                                <a href="service_receipt.php?service_id=<?php echo (int)$ord['id']; ?>" class="small">View Bill</a>
                            </div>
                        </div>
                        <?php if (!empty($itemsByOrder[$ord['id']])): ?>
                        <table class="table table-sm mb-0 mt-2">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Item</th>
                                    <th class="text-end" style="width:60px;">Qty</th>
                                    <th class="text-end" style="width:90px;">Price</th>
                                    <th class="text-end" style="width:100px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($itemsByOrder[$ord['id']] as $it): ?>
                                <tr>
                                    <td><?php echo e($it['item_name']); ?></td>
                                    <td class="text-end text-muted">x<?php echo (int)$it['quantity']; ?></td>
                                    <td class="text-end text-muted">৳<?php echo money($it['price']); ?></td>
                                    <td class="text-end fw-semibold">৳<?php echo money($it['total_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-form">
            <div class="section-title">New Service Order</div>
            <form method="POST" id="serviceForm">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">

                <div class="mb-3">
                    <label class="form-label">Service Type *</label>
                    <select name="service_type" id="service_type" class="form-select" required>
                        <?php foreach ($serviceTypes as $st): ?>
                            <option value="<?php echo e($st); ?>"><?php echo e($st); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3 d-none" id="customTypeWrap">
                    <label class="form-label">Custom Service Name *</label>
                    <input type="text" name="custom_service_type" id="custom_service_type" class="form-control" placeholder="e.g. Airport Pickup">
                </div>

                <div class="mb-3">
                    <label class="form-label">Notes (optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. Table service, deliver to room">
                </div>

                <label class="form-label">Items *</label>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-4">Item Name</div>
                    <div class="col-2">Qty</div>
                    <div class="col-2">Unit Price (৳)</div>
                    <div class="col-2">Line Total</div>
                    <div class="col-2"></div>
                </div>
                <div id="itemRows">
                    <div class="row g-2 mb-2 item-row align-items-center">
                        <div class="col-4">
                            <input type="text" name="item_name[]" class="form-control item-name" placeholder="e.g. Chicken Biryani">
                        </div>
                        <div class="col-2">
                            <input type="number" name="item_qty[]" class="form-control item-qty" min="1" step="1" value="1" title="Quantity">
                        </div>
                        <div class="col-2">
                            <input type="number" name="item_price[]" class="form-control item-price" min="0" step="0.01" value="0" placeholder="0.00" title="Unit Price">
                        </div>
                        <div class="col-2 text-end fw-semibold row-line-total">৳0.00</div>
                        <div class="col-2 d-flex align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row w-100">&times;</button>
                        </div>
                    </div>
                </div>
                <button type="button" id="addItemRow" class="btn btn-sm btn-outline-primary mb-3">+ Add Item</button>

                <div class="p-3 rounded mb-3" style="background:#f4f6f9;">
                    <div class="d-flex justify-content-between fs-5"><span>Order Total</span><strong class="text-success" id="orderTotal">৳0.00</strong></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Payment Collected Now (৳)</label>
                    <input type="number" step="0.01" min="0" name="payment_amount" id="payment_amount" class="form-control" value="0">
                    <div class="form-text">Optional. Leave as 0 to bill this to the room and settle at checkout.</div>
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

                <button type="submit" class="btn btn-primary w-100">Save Order &amp; Print Bill</button>
            </form>
        </div>
    </div>
</div>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const serviceType = document.getElementById('service_type');
    const customTypeWrap = document.getElementById('customTypeWrap');
    const customType = document.getElementById('custom_service_type');
    const itemRows = document.getElementById('itemRows');
    const addItemRow = document.getElementById('addItemRow');
    const orderTotalEl = document.getElementById('orderTotal');

    function toggleCustom() {
        const isOther = serviceType.value === 'Other';
        customTypeWrap.classList.toggle('d-none', !isOther);
        customType.required = isOther;
    }

    function rowTemplate() {
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 item-row align-items-center';
        div.innerHTML = `
            <div class="col-4">
                <input type="text" name="item_name[]" class="form-control item-name" placeholder="Item name">
            </div>
            <div class="col-2">
                <input type="number" name="item_qty[]" class="form-control item-qty" min="1" step="1" value="1" title="Quantity">
            </div>
            <div class="col-2">
                <input type="number" name="item_price[]" class="form-control item-price" min="0" step="0.01" value="0" placeholder="0.00" title="Unit Price">
            </div>
            <div class="col-2 text-end fw-semibold row-line-total">৳0.00</div>
            <div class="col-2 d-flex align-items-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row w-100">&times;</button>
            </div>
        `;
        return div;
    }

    function recalcTotal() {
        let total = 0;
        itemRows.querySelectorAll('.item-row').forEach(function (row) {
            let qty = parseInt(row.querySelector('.item-qty').value || '1', 10);
            if (isNaN(qty) || qty < 1) qty = 1;
            let price = parseFloat(row.querySelector('.item-price').value || 0);
            if (isNaN(price) || price < 0) price = 0;
            const lineTotal = qty * price;
            const lineTotalEl = row.querySelector('.row-line-total');
            if (lineTotalEl) lineTotalEl.textContent = '৳' + lineTotal.toFixed(2);
            total += lineTotal;
        });
        orderTotalEl.textContent = '৳' + total.toFixed(2);
    }

    addItemRow.addEventListener('click', function () {
        itemRows.appendChild(rowTemplate());
    });

    itemRows.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remove-row')) {
            const rows = itemRows.querySelectorAll('.item-row');
            if (rows.length > 1) {
                e.target.closest('.item-row').remove();
                recalcTotal();
            }
        }
    });

    itemRows.addEventListener('input', function (e) {
        if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) {
            recalcTotal();
        }
    });

    serviceType.addEventListener('change', toggleCustom);
    toggleCustom();
    recalcTotal();
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>