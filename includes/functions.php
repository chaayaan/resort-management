<?php
/**
 * Shared helper functions
 */

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function money($num) {
    return number_format((float)$num, 2);
}

function days_between($date1, $date2) {
    $d1 = new DateTime($date1);
    $d2 = new DateTime($date2);
    $diff = $d1->diff($d2)->days;
    return $diff < 1 ? 1 : $diff; // minimum 1 day charge
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_setting($conn, $key, $default = null) {
    // placeholder for future settings table
    return $default;
}

function fetch_all($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function fetch_one($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    if ($res && mysqli_num_rows($res) > 0) {
        return mysqli_fetch_assoc($res);
    }
    return null;
}

function booking_conflict_exists($conn, int $roomId, string $from, string $until, int $excludeBookingId = 0): bool
{
    $roomId = (int)$roomId;
    $from = mysqli_real_escape_string($conn, $from);
    $until = mysqli_real_escape_string($conn, $until);
    $excludeClause = $excludeBookingId > 0 ? "AND id != $excludeBookingId" : '';
 
    $row = fetch_one($conn, "
        SELECT id FROM bookings
        WHERE room_id = $roomId
          AND status IN ('reserved','checked_in')
          $excludeClause
          AND reserved_from < '$until'
          AND reserved_until > '$from'
        LIMIT 1
    ");
 
    return (bool)$row;
}
 
/**
 * Generates a unique, human-readable reservation number, e.g. RES-20260914-0001.
 * Sequence resets daily and is derived from the count of bookings already
 * created on that reservation_date (safe enough for a single front-desk
 * system; wrap in the same transaction as the INSERT to avoid races under
 * concurrent load, or add a UNIQUE constraint retry loop for high traffic).
 *
 * @param mysqli $conn
 * @param string $reservationDate 'Y-m-d'
 * @return string
 */
function generate_reservation_no($conn, string $reservationDate): string
{
    $reservationDate = mysqli_real_escape_string($conn, $reservationDate);
    $datePart = date('Ymd', strtotime($reservationDate));
 
    $row = fetch_one($conn, "
        SELECT COUNT(*) AS cnt FROM bookings WHERE reservation_date = '$reservationDate'
    ");
    $seq = ($row ? (int)$row['cnt'] : 0) + 1;
 
    do {
        $candidate = sprintf('RES-%s-%04d', $datePart, $seq);
        $exists = fetch_one($conn, "SELECT id FROM bookings WHERE reservation_no = '" . mysqli_real_escape_string($conn, $candidate) . "'");
        $seq++;
    } while ($exists);
 
    return $candidate;
}
 
/**
 * Derives what a room's dashboard status should read as *today*, combining
 * the manual `rooms.status` override (maintenance / out_of_service) with the
 * date-derived booking state. This replaces treating rooms.status as the
 * single source of truth for 'reserved'/'occupied'.
 *
 * Returns one of: 'available', 'reserved', 'occupied', 'maintenance', 'out_of_service'
 *
 * @param mysqli $conn
 * @param array  $room  a row from the `rooms` table (must include id, status)
 * @param string|null $today 'Y-m-d', defaults to today
 * @return string
 */
function get_room_effective_status($conn, array $room, ?string $today = null): string
{
    if (in_array($room['status'], ['maintenance', 'out_of_service'], true)) {
        return $room['status'];
    }
 
    $today = $today ?? date('Y-m-d');
    $roomId = (int)$room['id'];
 
    // Is there a booking actively covering today (checked_in)?
    $active = fetch_one($conn, "
        SELECT id FROM bookings
        WHERE room_id = $roomId
          AND status = 'checked_in'
        LIMIT 1
    ");
    if ($active) {
        return 'occupied';
    }
 
    // Is there a reservation that covers today, or starts in the future?
    $reserved = fetch_one($conn, "
        SELECT id FROM bookings
        WHERE room_id = $roomId
          AND status = 'reserved'
          AND '$today' < reserved_until
        LIMIT 1
    ");
    if ($reserved) {
        // Only mark as 'reserved' (blocking) if the reservation covers today;
        // a future-dated reservation still leaves the room 'available' today.
        $coversToday = fetch_one($conn, "
            SELECT id FROM bookings
            WHERE room_id = $roomId
              AND status = 'reserved'
              AND '$today' >= reserved_from AND '$today' < reserved_until
            LIMIT 1
        ");
        if ($coversToday) {
            return 'reserved';
        }
    }
 
    return 'available';
}
 
/**
 * Returns the next upcoming reservation for a room (for dashboard cards showing
 * "Next Booking: 16 Sept"), or null if none.
 *
 * @param mysqli $conn
 * @param int    $roomId
 * @param string|null $today 'Y-m-d'
 * @return array|null
 */
function get_room_next_reservation($conn, int $roomId, ?string $today = null): ?array
{
    $today = $today ?? date('Y-m-d');
    $roomId = (int)$roomId;
 
    $row = fetch_one($conn, "
        SELECT b.reserved_from, b.reserved_until, g.full_name
        FROM bookings b
        JOIN guests g ON g.id = b.guest_id
        WHERE b.room_id = $roomId
          AND b.status = 'reserved'
          AND b.reserved_until > '$today'
        ORDER BY b.reserved_from ASC
        LIMIT 1
    ");
 
    return $row ?: null;}