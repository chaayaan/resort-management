<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Front Desk';
$active = 'frontdesk';

// ---- Fetch all active rooms with type, and active booking info ----
$sql = "
    SELECT
        r.id, r.room_number, r.price_per_day, r.status AS room_status,
        rt.name AS room_type_name,
        b.id AS booking_id, b.reserved_from, b.reserved_until,
        b.checkin_at, b.status AS booking_status,
        g.full_name AS guest_name, g.phone AS guest_phone
    FROM rooms r
    JOIN room_types rt ON rt.id = r.room_type_id
    LEFT JOIN bookings b ON b.room_id = r.id
        AND b.status IN ('reserved','checked_in')
    LEFT JOIN guests g ON g.id = b.guest_id
    WHERE r.is_active = 1
    ORDER BY r.room_number ASC
";
$rooms = fetch_all($conn, $sql);

// ---- Stats ----
$total = count($rooms);
$available = count(array_filter($rooms, fn($r) => $r['room_status'] === 'available'));
$reserved  = count(array_filter($rooms, fn($r) => $r['room_status'] === 'reserved'));
$occupied  = count(array_filter($rooms, fn($r) => $r['room_status'] === 'occupied'));

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* =========================================================
   Front Desk page — self-contained styles (scoped to #fdPage)
   Does not touch the shared assets/css/style.css
   ========================================================= */
#fdPage {
    --fd-available: #2e7d32;
    --fd-available-bg: #e9f6ec;
    --fd-reserved: #1565c0;
    --fd-reserved-bg: #e8f0fb;
    --fd-occupied: #c62828;
    --fd-occupied-bg: #fdeaea;
    --fd-text: #101828;
    --fd-muted: #6b7280;
    --fd-border: #edf0f4;
    --fd-radius: 14px;
    --fd-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 1px 3px rgba(16, 24, 40, 0.06);
}

#fdPage .fd-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}

#fdPage .fd-header h3 {
    font-weight: 700;
    color: var(--fd-text);
    margin: 0 0 2px 0;
}

#fdPage .fd-header .fd-subtitle {
    color: var(--fd-muted);
    font-size: 0.92rem;
    margin: 0;
}

#fdPage .fd-header-actions {
    display: flex;
    gap: 10px;
}

#fdPage .fd-btn-refresh {
    background: #fff;
    border: 1px solid #dfe3e8;
    color: #344054;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 8px 16px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
#fdPage .fd-btn-refresh:hover { background: #f7f8fa; }

#fdPage .fd-btn-new {
    background: var(--fd-available);
    border: 1px solid var(--fd-available);
    color: #fff;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 8px 18px;
    border-radius: 9px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
#fdPage .fd-btn-new:hover { background: #256a32; color: #fff; }

/* ---------- Stat cards ---------- */
#fdPage .fd-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 26px;
}

#fdPage .fd-stat-card {
    background: #fff;
    border: 1px solid var(--fd-border);
    border-radius: var(--fd-radius);
    box-shadow: var(--fd-shadow);
    padding: 18px 20px;
}

#fdPage .fd-stat-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--fd-muted);
    font-size: 0.85rem;
    margin-bottom: 10px;
}

#fdPage .fd-stat-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #eef1f5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}

#fdPage .fd-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}
#fdPage .fd-dot-available { background: var(--fd-available); }
#fdPage .fd-dot-reserved  { background: var(--fd-reserved); }
#fdPage .fd-dot-occupied  { background: var(--fd-occupied); }

#fdPage .fd-stat-num {
    font-size: 2rem;
    font-weight: 700;
    color: var(--fd-text);
    line-height: 1;
}
#fdPage .fd-stat-num.is-available { color: var(--fd-available); }
#fdPage .fd-stat-num.is-reserved  { color: var(--fd-reserved); }
#fdPage .fd-stat-num.is-occupied  { color: var(--fd-occupied); }

/* ---------- Room grid ---------- */
#fdPage .fd-room-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

#fdPage .fd-room-card {
    background: #fff;
    border: 1px solid var(--fd-border);
    border-radius: var(--fd-radius);
    box-shadow: var(--fd-shadow);
    padding: 18px;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    display: flex;
    flex-direction: column;
}

#fdPage .fd-room-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 24, 40, 0.09);
}

#fdPage .fd-room-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

#fdPage .fd-room-number {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--fd-text);
}

#fdPage .fd-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 5px 11px;
    border-radius: 999px;
}
#fdPage .fd-badge::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
#fdPage .fd-badge-available { background: var(--fd-available-bg); color: var(--fd-available); }
#fdPage .fd-badge-reserved  { background: var(--fd-reserved-bg); color: var(--fd-reserved); }
#fdPage .fd-badge-occupied  { background: var(--fd-occupied-bg); color: var(--fd-occupied); }

#fdPage .fd-room-type {
    display: inline-block;
    background: #eef1f5;
    color: var(--fd-muted);
    font-size: 0.78rem;
    font-weight: 500;
    padding: 4px 12px;
    border-radius: 999px;
    margin-bottom: 10px;
    width: fit-content;
}

#fdPage .fd-price {
    font-size: 1rem;
    font-weight: 700;
    color: var(--fd-text);
    margin-bottom: 12px;
}
#fdPage .fd-price .fd-per-day {
    font-weight: 400;
    font-size: 0.8rem;
    color: var(--fd-muted);
}

#fdPage .fd-guest-name {
    font-weight: 600;
    color: var(--fd-text);
    margin-bottom: 3px;
}

#fdPage .fd-meta {
    font-size: 0.82rem;
    color: var(--fd-muted);
    margin-bottom: 3px;
}
#fdPage .fd-meta.fd-ready { color: var(--fd-available); }
#fdPage .fd-meta.fd-next-date {
    font-weight: 700;
    color: var(--fd-occupied);
}

#fdPage .fd-spacer { flex: 1; }

#fdPage .fd-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

#fdPage .fd-btn {
    border: none;
    border-radius: 9px;
    font-weight: 600;
    font-size: 0.86rem;
    padding: 9px 12px;
    width: 100%;
    text-align: center;
}

#fdPage .fd-btn-book {
    background: var(--fd-available);
    color: #fff;
}
#fdPage .fd-btn-book:hover { background: #256a32; }

#fdPage .fd-btn-checkin {
    background: var(--fd-reserved);
    color: #fff;
}
#fdPage .fd-btn-checkin:hover { background: #0d4ea1; }

#fdPage .fd-btn-extend {
    background: #fff;
    border: 1px solid #d7dbe2;
    color: #344054;
}
#fdPage .fd-btn-extend:hover { background: #f7f8fa; }

#fdPage .fd-btn-checkout {
    background: var(--fd-occupied);
    color: #fff;
}
#fdPage .fd-btn-checkout:hover { background: #a02020; }

#fdPage .fd-empty {
    background: #fff7e6;
    border: 1px solid #ffe3a3;
    color: #7a5b00;
    padding: 14px 18px;
    border-radius: var(--fd-radius);
    grid-column: 1 / -1;
}
#fdPage .fd-empty a { color: #7a5b00; font-weight: 700; }

@media (max-width: 991.98px) {
    #fdPage .fd-stats { grid-template-columns: repeat(2, 1fr); }
    #fdPage .fd-room-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575.98px) {
    #fdPage .fd-stats { grid-template-columns: 1fr 1fr; }
    #fdPage .fd-room-grid { grid-template-columns: 1fr; }
}
</style>

<div id="fdPage">

    <div class="fd-header">
        <div>
            <h3>Front Desk — Room Status</h3>
        </div>
        <div class="fd-header-actions">
            <button class="fd-btn-refresh" id="refreshBtn">&#8635; Refresh</button>
            <a href="reservation.php" class="fd-btn-new">+ New Reservation</a>
        </div>
    </div>

    <div class="fd-stats">
        <div class="fd-stat-card">
            <div class="fd-stat-label"><span class="fd-stat-icon">&#128716;</span>Total Rooms</div>
            <div class="fd-stat-num" id="statTotal"><?php echo $total; ?></div>
        </div>
        <div class="fd-stat-card">
            <div class="fd-stat-label"><span class="fd-dot fd-dot-available"></span>Available</div>
            <div class="fd-stat-num is-available" id="statAvailable"><?php echo $available; ?></div>
        </div>
        <div class="fd-stat-card">
            <div class="fd-stat-label"><span class="fd-dot fd-dot-reserved"></span>Reserved</div>
            <div class="fd-stat-num is-reserved" id="statReserved"><?php echo $reserved; ?></div>
        </div>
        <div class="fd-stat-card">
            <div class="fd-stat-label"><span class="fd-dot fd-dot-occupied"></span>Occupied</div>
            <div class="fd-stat-num is-occupied" id="statOccupied"><?php echo $occupied; ?></div>
        </div>
    </div>

    <div id="roomGrid" class="fd-room-grid">
        <?php foreach ($rooms as $r): ?>
            <?php
                $status = $r['room_status']; // available | reserved | occupied
                $nextAvailable = '';
                if ($status !== 'available' && $r['reserved_until']) {
                    $nextAvailable = date('d M Y', strtotime($r['reserved_until'])) . ' — 12:00 PM';
                }
            ?>
            <div class="fd-room-card"
                 data-room-id="<?php echo (int)$r['id']; ?>"
                 data-status="<?php echo $status; ?>"
                 data-booking-id="<?php echo (int)$r['booking_id']; ?>">

                <div class="fd-room-top">
                    <div class="fd-room-number">#<?php echo e($r['room_number']); ?></div>
                    <span class="fd-badge fd-badge-<?php echo $status; ?>"><?php echo e(ucfirst($status)); ?></span>
                </div>

                <span class="fd-room-type"><?php echo e($r['room_type_name']); ?></span>

                <div class="fd-price">৳<?php echo money($r['price_per_day']); ?> <span class="fd-per-day">/ day</span></div>

                <?php if ($status === 'available'): ?>
                    <div class="fd-meta fd-ready">Ready for new guest</div>
                    <div class="fd-spacer"></div>
                    <div class="fd-actions">
                        <button class="fd-btn fd-btn-book btn-book">Book Now</button>
                    </div>

                <?php elseif ($status === 'reserved'): ?>
                    <div class="fd-guest-name"><?php echo e($r['guest_name']); ?></div>
                    <div class="fd-meta">&#128222; <?php echo e($r['guest_phone']); ?></div>
                    <div class="fd-meta">Check-in: <?php echo date('d M Y', strtotime($r['reserved_from'])); ?></div>
                    <div class="fd-spacer"></div>
                    <div class="fd-actions">
                        <button class="fd-btn fd-btn-checkin btn-checkin">Check In</button>
                    </div>

                <?php elseif ($status === 'occupied'): ?>
                    <div class="fd-guest-name"><?php echo e($r['guest_name']); ?></div>
                    <div class="fd-meta">&#128222; <?php echo e($r['guest_phone']); ?></div>
                    <div class="fd-meta">Next available:</div>
                    <div class="fd-meta fd-next-date"><?php echo e($nextAvailable); ?></div>
                    <div class="fd-spacer"></div>
                    <div class="fd-actions">
                        <button class="fd-btn fd-btn-extend btn-extend">Extend</button>
                        <button class="fd-btn fd-btn-checkout btn-checkout">Checkout</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($rooms)): ?>
            <div class="fd-empty">No rooms found. Please add rooms from <a href="rooms.php">Manage Rooms</a>.</div>
        <?php endif; ?>
    </div>

</div>

<?php
$extraScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {

    // Card click / button routing
    document.getElementById('roomGrid').addEventListener('click', function (e) {
        const card = e.target.closest('.fd-room-card');
        if (!card) return;
        const roomId = card.dataset.roomId;
        const bookingId = card.dataset.bookingId;
        const status = card.dataset.status;

        if (e.target.classList.contains('btn-book')) {
            window.location.href = 'reservation.php?room_id=' + roomId;
        } else if (e.target.classList.contains('btn-checkin')) {
            window.location.href = 'checkin.php?booking_id=' + bookingId;
        } else if (e.target.classList.contains('btn-extend')) {
            window.location.href = 'extend.php?booking_id=' + bookingId;
        } else if (e.target.classList.contains('btn-checkout')) {
            window.location.href = 'checkout.php?booking_id=' + bookingId;
        } else {
            // clicked card body (not a button) -> route based on status
            if (status === 'available') {
                window.location.href = 'reservation.php?room_id=' + roomId;
            } else if (status === 'reserved') {
                window.location.href = 'checkin.php?booking_id=' + bookingId;
            } else if (status === 'occupied') {
                window.location.href = 'checkout.php?booking_id=' + bookingId;
            }
        }
    });

    document.getElementById('refreshBtn').addEventListener('click', function () {
        window.location.reload();
    });
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>