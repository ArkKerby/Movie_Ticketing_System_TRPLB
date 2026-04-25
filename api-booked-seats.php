<?php
/**
 * API endpoint: Returns currently booked seats for a given schedule.
 * Used by seat-reservation.php and staff/seats.php for real-time polling.
 *
 * GET params:
 *   movie_id   - MOVIE.movie_show_id
 *   branch_id  - BRANCH.branch_id
 *   date       - show date (YYYY-MM-DD)
 *   time       - show time formatted as HH:MM:SS (24-hour)
 *
 * Returns JSON: { "bookedSeats": ["A-1","A-2",...] }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$movieId  = isset($_GET['movie_id'])  ? intval($_GET['movie_id'])  : 0;
$branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$date     = $_GET['date'] ?? '';
$time     = $_GET['time'] ?? '';

if (!$movieId || !$date || !$time) {
    echo json_encode(['bookedSeats' => []]);
    exit;
}

// Find the schedule_id
if ($branchId) {
    $stmt = $conn->prepare("
        SELECT schedule_id 
        FROM MOVIE_SCHEDULE 
        WHERE movie_show_id = ? 
          AND branch_id = ? 
          AND show_date = ? 
          AND TIME(show_hour) = TIME(?)
        LIMIT 1
    ");
    $stmt->bind_param("iiss", $movieId, $branchId, $date, $time);
} else {
    $stmt = $conn->prepare("
        SELECT schedule_id 
        FROM MOVIE_SCHEDULE 
        WHERE movie_show_id = ? 
          AND show_date = ? 
          AND TIME(show_hour) = TIME(?)
        LIMIT 1
    ");
    $stmt->bind_param("iss", $movieId, $date, $time);
}
$stmt->execute();
$result = $stmt->get_result();
$schedule = $result->fetch_assoc();
$stmt->close();

$bookedSeats = [];

if ($schedule) {
    $scheduleId = $schedule['schedule_id'];

    // Check if booking_status column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
    $hasStatus = $colCheck && $colCheck->num_rows > 0;

    if ($hasStatus) {
        $seatStmt = $conn->prepare("
            SELECT DISTINCT rs.seat_number
            FROM RESERVE r
            JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
            WHERE r.schedule_id = ?
              AND (r.booking_status IS NULL OR r.booking_status = 'pending' OR r.booking_status = 'approved')
        ");
    } else {
        $seatStmt = $conn->prepare("
            SELECT DISTINCT rs.seat_number
            FROM RESERVE r
            JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
            WHERE r.schedule_id = ?
        ");
    }
    $seatStmt->bind_param("i", $scheduleId);
    $seatStmt->execute();
    $seatResult = $seatStmt->get_result();
    while ($row = $seatResult->fetch_assoc()) {
        $bookedSeats[] = $row['seat_number'];
    }
    $seatStmt->close();
}

echo json_encode(['bookedSeats' => $bookedSeats]);
