<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Dashboard';
$active = 'dashboard';

// =====================================================
// Date range filter (default: last 30 days)
// =====================================================
$rangeDays = (int)($_GET['range'] ?? 30);
if (!in_array($rangeDays, [7, 30, 90, 365], true)) $rangeDays = 30;
$startDate = date('Y-m-d', strtotime("-$rangeDays days"));
$today = date('Y-m-d');

// =====================================================
// Top-line stats
// =====================================================

// Revenue = sum of all payments in range
$revenueRow = fetch_one($conn, "
    SELECT COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE payment_type != 'refund' AND DATE(created_at) BETWEEN '$startDate' AND '$today'
");
$totalRevenue = (float)$revenueRow['total'];

// Revenue by type (advance / extension / final)
$revenueByType = fetch_all($conn, "
    SELECT payment_type, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE payment_type != 'refund' AND DATE(created_at) BETWEEN '$startDate' AND '$today'
    GROUP BY payment_type
");

// Room counts by current status
$roomStats = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_rooms,
        SUM(status='available') AS available_rooms,
        SUM(status='reserved') AS reserved_rooms,
        SUM(status='occupied') AS occupied_rooms
    FROM rooms WHERE is_active = 1
");
$totalRooms = (int)$roomStats['total_rooms'];
$occupiedRooms = (int)$roomStats['occupied_rooms'];
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

// Bookings in range
$bookingCounts = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_bookings,
        SUM(status='reserved') AS reserved_count,
        SUM(status='checked_in') AS checkedin_count,
        SUM(status='checked_out') AS checkedout_count,
        SUM(status='cancelled') AS cancelled_count
    FROM bookings
    WHERE DATE(created_at) BETWEEN '$startDate' AND '$today'
");

// Average stay length (checked-out bookings, all-time for stability)
$avgStayRow = fetch_one($conn, "SELECT AVG(total_days) AS avg_days FROM bookings WHERE status = 'checked_out'");
$avgStay = $avgStayRow && $avgStayRow['avg_days'] ? round((float)$avgStayRow['avg_days'], 1) : 0;

// =====================================================
// Chart data: daily revenue (last N days)
// =====================================================
$dailyRevenue = fetch_all($conn, "
    SELECT DATE(created_at) AS day, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE payment_type != 'refund' AND DATE(created_at) BETWEEN '$startDate' AND '$today'
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$revenueByDay = [];
foreach ($dailyRevenue as $row) {
    $revenueByDay[$row['day']] = (float)$row['total'];
}
// Fill in missing days with 0
$chartLabels = [];
$chartValues = [];
$cursor = strtotime($startDate);
$end = strtotime($today);
while ($cursor <= $end) {
    $d = date('Y-m-d', $cursor);
    $chartLabels[] = date('d M', $cursor);
    $chartValues[] = $revenueByDay[$d] ?? 0;
    $cursor = strtotime('+1 day', $cursor);
}

// =====================================================
// Chart data: room type popularity (by number of bookings, all-time)
// =====================================================
$typePopularity = fetch_all($conn, "
    SELECT rt.name, COUNT(b.id) AS booking_count
    FROM room_types rt
    LEFT JOIN rooms r ON r.room_type_id = rt.id
    LEFT JOIN bookings b ON b.room_id = r.id
    GROUP BY rt.id, rt.name
    ORDER BY booking_count DESC
");

// =====================================================
// Top rooms by revenue (room_charge_total + extension_charge_total, all-time)
// =====================================================
$topRooms = fetch_all($conn, "
    SELECT r.room_number, rt.name AS type_name,
           COALESCE(SUM(b.room_charge_total + b.extension_charge_total),0) AS revenue,
           COUNT(b.id) AS bookings_count
    FROM rooms r
    JOIN room_types rt ON rt.id = r.room_type_id
    LEFT JOIN bookings b ON b.room_id = r.id AND b.status != 'cancelled'
    GROUP BY r.id, r.room_number, rt.name
    ORDER BY revenue DESC
    LIMIT 5
");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Dashboard</h3>
    <div class="btn-group btn-group-sm" role="group">
        <a href="dashboard.php?range=7" class="btn btn-outline-secondary <?php echo $rangeDays===7?'active':''; ?>">7d</a>
        <a href="dashboard.php?range=30" class="btn btn-outline-secondary <?php echo $rangeDays===30?'active':''; ?>">30d</a>
        <a href="dashboard.php?range=90" class="btn btn-outline-secondary <?php echo $rangeDays===90?'active':''; ?>">90d</a>
        <a href="dashboard.php?range=365" class="btn btn-outline-secondary <?php echo $rangeDays===365?'active':''; ?>">1y</a>
    </div>
</div>

<!-- Top stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-box">
            <div class="text-muted small">Revenue (<?php echo $rangeDays; ?>d)</div>
            <div class="stat-num text-success">৳<?php echo money($totalRevenue); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-box">
            <div class="text-muted small">Occupancy Rate</div>
            <div class="stat-num text-primary"><?php echo $occupancyRate; ?>%</div>
            <div class="text-muted small"><?php echo $occupiedRooms; ?> / <?php echo $totalRooms; ?> rooms</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-box">
            <div class="text-muted small">Bookings (<?php echo $rangeDays; ?>d)</div>
            <div class="stat-num"><?php echo (int)$bookingCounts['total_bookings']; ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-box">
            <div class="text-muted small">Avg. Stay Length</div>
            <div class="stat-num"><?php echo $avgStay; ?> <span class="fs-6 text-muted">days</span></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card-form h-100">
            <div class="section-title">Revenue Trend</div>
            <canvas id="revenueChart" height="90"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-form h-100">
            <div class="section-title">Current Room Status</div>
            <canvas id="roomStatusChart" height="200"></canvas>
            <div class="d-flex justify-content-around mt-3 small">
                <div><span class="legend-dot" style="background:#2e7d32"></span>Available (<?php echo (int)$roomStats['available_rooms']; ?>)</div>
                <div><span class="legend-dot" style="background:#1565c0"></span>Reserved (<?php echo (int)$roomStats['reserved_rooms']; ?>)</div>
                <div><span class="legend-dot" style="background:#c62828"></span>Occupied (<?php echo $occupiedRooms; ?>)</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card-form h-100">
            <div class="section-title">Revenue by Payment Type (<?php echo $rangeDays; ?>d)</div>
            <canvas id="revenueTypeChart" height="180"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-form h-100">
            <div class="section-title">Bookings by Room Type (All-Time)</div>
            <canvas id="typePopularityChart" height="180"></canvas>
        </div>
    </div>
</div>

<div class="card-form">
    <div class="section-title">Top Performing Rooms (All-Time Revenue)</div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr><th>Room</th><th>Type</th><th>Bookings</th><th class="text-end">Total Revenue</th></tr>
            </thead>
            <tbody>
                <?php foreach ($topRooms as $r): ?>
                <tr>
                    <td class="fw-semibold">#<?php echo e($r['room_number']); ?></td>
                    <td><?php echo e($r['type_name']); ?></td>
                    <td><?php echo (int)$r['bookings_count']; ?></td>
                    <td class="text-end fw-semibold text-success">৳<?php echo money($r['revenue']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topRooms)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No data yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$chartLabelsJson = json_encode($chartLabels);
$chartValuesJson = json_encode($chartValues);

$revTypeLabels = json_encode(array_map(fn($r) => ucfirst($r['payment_type']), $revenueByType));
$revTypeValues = json_encode(array_map(fn($r) => (float)$r['total'], $revenueByType));

$typePopLabels = json_encode(array_map(fn($r) => $r['name'], $typePopularity));
$typePopValues = json_encode(array_map(fn($r) => (int)$r['booking_count'], $typePopularity));

$availableRoomsCount = (int)$roomStats['available_rooms'];
$reservedRoomsCount = (int)$roomStats['reserved_rooms'];

$extraScript = <<<HTML
<script src="assets/js/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // Revenue trend line chart
    new Chart(document.getElementById('revenueChart'), {
        type: 'line',
        data: {
            labels: {$chartLabelsJson},
            datasets: [{
                label: 'Revenue (৳)',
                data: {$chartValuesJson},
                borderColor: '#2f4b7c',
                backgroundColor: 'rgba(47,75,124,0.08)',
                fill: true,
                tension: 0.3,
                pointRadius: 2
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Room status doughnut
    new Chart(document.getElementById('roomStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Available', 'Reserved', 'Occupied'],
            datasets: [{
                data: [{$availableRoomsCount}, {$reservedRoomsCount}, {$occupiedRooms}],
                backgroundColor: ['#2e7d32', '#1565c0', '#c62828']
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } }
        }
    });

    // Revenue by payment type bar chart
    new Chart(document.getElementById('revenueTypeChart'), {
        type: 'bar',
        data: {
            labels: {$revTypeLabels},
            datasets: [{
                label: 'Revenue (৳)',
                data: {$revTypeValues},
                backgroundColor: '#4fc3f7'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Room type popularity bar chart
    new Chart(document.getElementById('typePopularityChart'), {
        type: 'bar',
        data: {
            labels: {$typePopLabels},
            datasets: [{
                label: 'Bookings',
                data: {$typePopValues},
                backgroundColor: '#8e7cc3'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
});
</script>
HTML;

require_once __DIR__ . '/includes/footer.php';
?>