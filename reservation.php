<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// =====================================================
// AJAX: Guest search (same-page endpoint, no external API)
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_guest') {
    $q = mysqli_real_escape_string($conn, $_GET['q'] ?? '');
    if (strlen($q) < 2) json_out([]);
    $rows = fetch_all($conn, "
        SELECT id, full_name, phone, email, id_proof_type, id_proof_number, address
        FROM guests
        WHERE full_name LIKE '%$q%' OR phone LIKE '%$q%'
        ORDER BY full_name ASC LIMIT 8
    ");
    json_out($rows);
}

// =====================================================
// AJAX: Room price lookup
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'room_info') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $room = fetch_one($conn, "
        SELECT r.id, r.room_number, r.price_per_day, r.status, rt.name AS type_name
        FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
        WHERE r.id = $roomId
    ");
    json_out($room ?: []);
}

// =====================================================
// AJAX: Availability check for a room across a date range
// (used to warn the receptionist live, before submit)
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_availability') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $from = trim($_GET['reserved_from'] ?? '');
    $until = trim($_GET['reserved_until'] ?? '');
    if ($roomId <= 0 || $from === '' || $until === '') json_out(['available' => false, 'reason' => 'Missing parameters']);
    $conflict = booking_conflict_exists($conn, $roomId, $from, $until);
    json_out(['available' => !$conflict]);
}

$pageTitle = 'New Reservation';
$active = 'reservation';
$error = '';
$success = '';

$preselectedRoomId = (int)($_GET['room_id'] ?? 0);
$today = date('Y-m-d');

// =====================================================
// Form submission (create guest if needed + booking)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = (int)($_POST['room_id'] ?? 0);
    $guestId = (int)($_POST['guest_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $idProofType = trim($_POST['id_proof_type'] ?? '');
    $idProofNumber = trim($_POST['id_proof_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $reservedFrom = trim($_POST['reserved_from'] ?? '');
    $reservedNights = (int)($_POST['reserved_nights'] ?? 0);
    $adults = max(1, (int)($_POST['adults'] ?? 1));
    $children = max(0, (int)($_POST['children'] ?? 0));

    if ($roomId <= 0 || $fullName === '' || $phone === '' || $reservedFrom === '' || $reservedNights < 1) {
        $error = 'Please fill all required fields (room, guest, reserved-from date, and at least 1 night).';
    } else {
        // reserved_until is always derived server-side, never trusted from the client directly
        $reservedUntil = date('Y-m-d', strtotime($reservedFrom . " +$reservedNights days"));

        $room = fetch_one($conn, "SELECT * FROM rooms WHERE id = $roomId AND is_active = 1 AND status NOT IN ('maintenance','out_of_service')");
        if (!$room) {
            $error = 'Selected room is not bookable (inactive, in maintenance, or out of service).';
        } elseif (booking_conflict_exists($conn, $roomId, $reservedFrom, $reservedUntil)) {
            $error = 'This room already has an overlapping reservation for the selected dates.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                if ($guestId > 0) {
                    $existing = fetch_one($conn, "SELECT id FROM guests WHERE id = $guestId");
                    if (!$existing) $guestId = 0;
                }
                if ($guestId <= 0) {
                    $stmt = mysqli_prepare($conn, "INSERT INTO guests (full_name, phone, email, id_proof_type, id_proof_number, address) VALUES (?,?,?,?,?,?)");
                    mysqli_stmt_bind_param($stmt, 'ssssss', $fullName, $phone, $email, $idProofType, $idProofNumber, $address);
                    mysqli_stmt_execute($stmt);
                    $guestId = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE guests SET full_name=?, phone=?, email=?, id_proof_type=?, id_proof_number=?, address=? WHERE id=?");
                    mysqli_stmt_bind_param($stmt, 'ssssssi', $fullName, $phone, $email, $idProofType, $idProofNumber, $address, $guestId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }

                $pricePerDay = (float)$room['price_per_day'];
                $roomChargeTotal = $reservedNights * $pricePerDay;
                $reservationDate = $today;
                $reservationNo = generate_reservation_no($conn, $reservationDate);

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO bookings
                        (reservation_no, room_id, guest_id, reservation_date, reserved_from, reserved_nights, reserved_until,
                         adults, children, price_per_day, room_charge_total, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'reserved')
                ");
                // Params in order: reservation_no(s), room_id(i), guest_id(i), reservation_date(s),
                // reserved_from(s), reserved_nights(i), reserved_until(s), adults(i), children(i),
                // price_per_day(d), room_charge_total(d)
                mysqli_stmt_bind_param(
                    $stmt, 'siissisiidd',
                    $reservationNo, $roomId, $guestId, $reservationDate, $reservedFrom, $reservedNights, $reservedUntil,
                    $adults, $children, $pricePerDay, $roomChargeTotal
                );
                mysqli_stmt_execute($stmt);
                $bookingId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                // Room's manual `status` flag is left as-is (available); the dashboard/
                // reservation list derives day-to-day occupancy from booking date ranges,
                // so a future reservation does not block the room from showing as
                // available today. See get_room_effective_status().

                mysqli_commit($conn);

                header('Location: checkin.php?booking_id=' . $bookingId);
                exit;

            } catch (Exception $ex) {
                mysqli_rollback($conn);
                $error = 'Something went wrong while saving the reservation.';
            }
        }
    }
}

// Rooms bookable today: active, not in maintenance/out-of-service, and with
// no reservation covering today's date. Rooms with a *future* reservation
// still show up here (they're available today) — the pending list below
// just makes the upcoming booking visible.
$availableRooms = fetch_all($conn, "
    SELECT r.id, r.room_number, r.price_per_day, rt.name AS type_name
    FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
    WHERE r.is_active = 1
      AND r.status NOT IN ('maintenance','out_of_service')
      AND NOT EXISTS (
          SELECT 1 FROM bookings b
          WHERE b.room_id = r.id
            AND b.status IN ('reserved','checked_in')
            AND '$today' >= b.reserved_from AND '$today' < b.reserved_until
      )
    ORDER BY r.room_number ASC
");

// Pending reservations (reserved, not yet checked in) — surfaced here so staff can
// jump straight to check-in or cancel without leaving the reservation workflow.
$pendingReservations = fetch_all($conn, "
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
    <h3>New Reservation</h3>
    <div class="d-flex gap-2">
        <a href="reservation_cancel.php" class="btn btn-outline-danger btn-sm">Cancel a Reservation</a>
        <a href="frontdesk.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Front Desk</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<?php if (!empty($pendingReservations)): ?>
<div class="card-form mb-3">
    <div class="section-title">Pending Reservations (Not Yet Checked In)</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Reservation #</th><th>Room</th><th>Guest</th><th>Reserved From</th><th>Reserved Until</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pendingReservations as $p): ?>
                <tr>
                    <td class="text-muted small"><?php echo e($p['reservation_no']); ?></td>
                    <td class="fw-semibold">#<?php echo e($p['room_number']); ?></td>
                    <td><?php echo e($p['full_name']); ?> <span class="text-muted small">(<?php echo e($p['phone']); ?>)</span></td>
                    <td><?php echo date('d M Y', strtotime($p['reserved_from'])); ?></td>
                    <td><?php echo date('d M Y', strtotime($p['reserved_until'])); ?></td>
                    <td class="text-end">
                        <a href="checkin.php?booking_id=<?php echo (int)$p['booking_id']; ?>" class="btn btn-sm btn-primary">Check In</a>
                        <a href="reservation_cancel.php?booking_id=<?php echo (int)$p['booking_id']; ?>" class="btn btn-sm btn-outline-danger">Cancel</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($availableRooms)): ?>
    <div class="alert alert-warning">No rooms are currently available.</div>
<?php else: ?>

<div class="card-form">
    <form method="POST" id="reservationForm" autocomplete="off">
        <input type="hidden" name="guest_id" id="guest_id" value="0">

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="section-title">Room &amp; Dates</div>

                <div class="mb-3">
                    <label class="form-label">Select Room *</label>
                    <select name="room_id" id="room_id" class="form-select" required>
                        <option value="">-- Choose a room --</option>
                        <?php foreach ($availableRooms as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"
                                data-price="<?php echo e($r['price_per_day']); ?>"
                                <?php echo $preselectedRoomId === (int)$r['id'] ? 'selected' : ''; ?>>
                                #<?php echo e($r['room_number']); ?> — <?php echo e($r['type_name']); ?> (৳<?php echo money($r['price_per_day']); ?>/day)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <span class="text-muted small">Reservation Date (today)</span>
                    <div class="fw-semibold"><?php echo date('d M Y'); ?></div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Reserved From *</label>
                        <input type="date" name="reserved_from" id="reserved_from" class="form-control" required value="<?php echo $today; ?>" min="<?php echo $today; ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Number of Nights *</label>
                        <input type="number" name="reserved_nights" id="reserved_nights" class="form-control" required min="1" value="1">
                    </div>
                </div>

                <div class="mb-3">
                    <span class="text-muted small">Reserved Until (auto-calculated)</span>
                    <div class="fw-semibold" id="reservedUntilDisplay">—</div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Adults</label>
                        <input type="number" name="adults" id="adults" class="form-control" min="1" value="1">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Children</label>
                        <input type="number" name="children" id="children" class="form-control" min="0" value="0">
                    </div>
                </div>

                <div id="availabilityWarning" class="alert alert-danger d-none py-2 mb-3">
                    This room already has an overlapping reservation for these dates.
                </div>

                <div class="p-3 rounded" style="background:#f4f6f9;">
                    <div class="d-flex justify-content-between"><span>Price / day</span><strong id="calcPrice">৳0.00</strong></div>
                    <div class="d-flex justify-content-between"><span>Total nights</span><strong id="calcDays">0</strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between fs-5"><span>Total Cost</span><strong id="calcTotal" class="text-success">৳0.00</strong></div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-title">Guest Information</div>

                <div class="mb-3 position-relative">
                    <label class="form-label">Guest Name / Phone (search existing) *</label>
                    <input type="text" id="guestSearch" class="form-control" placeholder="Type name or phone to search...">
                    <div class="guest-suggestion-list" id="guestSuggestions"></div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" id="full_name" class="form-control" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" id="phone" class="form-control" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">ID Proof Type</label>
                        <select name="id_proof_type" id="id_proof_type" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="NID">NID</option>
                            <option value="Passport">Passport</option>
                            <option value="Driving License">Driving License</option>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">ID Proof Number</label>
                        <input type="text" name="id_proof_number" id="id_proof_number" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                </div>
            </div>
        </div>

        <hr class="my-4">
        <div class="d-flex justify-content-end gap-2">
            <a href="frontdesk.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" id="submitReservation">Save Reservation &amp; Proceed to Check-In</button>
        </div>
    </form>
</div>

<?php endif; ?>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const roomSelect = document.getElementById('room_id');
    const reservedFrom = document.getElementById('reserved_from');
    const reservedNights = document.getElementById('reserved_nights');
    const reservedUntilDisplay = document.getElementById('reservedUntilDisplay');
    const calcPrice = document.getElementById('calcPrice');
    const calcDays = document.getElementById('calcDays');
    const calcTotal = document.getElementById('calcTotal');
    const availabilityWarning = document.getElementById('availabilityWarning');
    const submitBtn = document.getElementById('submitReservation');

    function computeUntil() {
        const from = new Date(reservedFrom.value);
        let nights = parseInt(reservedNights.value || '0', 10);
        if (isNaN(nights) || nights < 1) nights = 1;
        if (isNaN(from.getTime())) return null;
        const until = new Date(from);
        until.setDate(until.getDate() + nights);
        return until;
    }

    function fmt(d) {
        if (!d) return '—';
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    let availCheckTimer = null;
    function checkAvailability() {
        const opt = roomSelect.options[roomSelect.selectedIndex];
        const roomId = opt ? opt.value : '';
        const until = computeUntil();
        if (!roomId || !reservedFrom.value || !until) {
            availabilityWarning.classList.add('d-none');
            return;
        }
        const untilStr = until.toISOString().slice(0, 10);

        clearTimeout(availCheckTimer);
        availCheckTimer = setTimeout(function () {
            fetch('reservation.php?ajax=check_availability&room_id=' + encodeURIComponent(roomId)
                + '&reserved_from=' + encodeURIComponent(reservedFrom.value)
                + '&reserved_until=' + encodeURIComponent(untilStr))
                .then(res => res.json())
                .then(data => {
                    const isAvailable = !!data.available;
                    availabilityWarning.classList.toggle('d-none', isAvailable);
                    submitBtn.disabled = !isAvailable;
                });
        }, 250);
    }

    function recalc() {
        const opt = roomSelect.options[roomSelect.selectedIndex];
        const price = opt ? parseFloat(opt.dataset.price || 0) : 0;
        let nights = parseInt(reservedNights.value || '0', 10);
        if (isNaN(nights) || nights < 1) nights = 1;

        const until = computeUntil();
        reservedUntilDisplay.textContent = fmt(until);

        calcPrice.textContent = '৳' + price.toFixed(2);
        calcDays.textContent = nights;
        calcTotal.textContent = '৳' + (price * nights).toFixed(2);

        checkAvailability();
    }

    roomSelect.addEventListener('change', recalc);
    reservedFrom.addEventListener('change', recalc);
    reservedNights.addEventListener('input', recalc);
    recalc();

    const guestSearch = document.getElementById('guestSearch');
    const suggestionsBox = document.getElementById('guestSuggestions');
    let debounceTimer = null;

    guestSearch.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const q = guestSearch.value.trim();
        document.getElementById('guest_id').value = 0;

        if (q.length < 2) {
            suggestionsBox.style.display = 'none';
            suggestionsBox.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(function () {
            fetch('reservation.php?ajax=search_guest&q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    suggestionsBox.innerHTML = '';
                    if (!data.length) {
                        suggestionsBox.style.display = 'none';
                        return;
                    }
                    data.forEach(function (g) {
                        const div = document.createElement('div');
                        div.className = 'item';
                        div.innerHTML = '<strong>' + g.full_name + '</strong> — ' + g.phone;
                        div.addEventListener('click', function () {
                            document.getElementById('guest_id').value = g.id;
                            document.getElementById('full_name').value = g.full_name;
                            document.getElementById('phone').value = g.phone;
                            document.getElementById('email').value = g.email || '';
                            document.getElementById('id_proof_type').value = g.id_proof_type || '';
                            document.getElementById('id_proof_number').value = g.id_proof_number || '';
                            document.getElementById('address').value = g.address || '';
                            guestSearch.value = g.full_name;
                            suggestionsBox.style.display = 'none';
                        });
                        suggestionsBox.appendChild(div);
                    });
                    suggestionsBox.style.display = 'block';
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!suggestionsBox.contains(e.target) && e.target !== guestSearch) {
            suggestionsBox.style.display = 'none';
        }
    });
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>