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
