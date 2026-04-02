<?php
/**
 * Auto-approve pending bookings that are within 1 hour of showtime.
 * Include this file at the top of admin/staff pages to run on page load.
 */
require_once __DIR__ . '/config.php';

function autoApprovePendingBookings() {
    $conn = getDBConnection();
    
    // Find all pending reservations where showtime is within 1 hour from now
    $stmt = $conn->prepare("
        SELECT r.reservation_id
        FROM RESERVE r
        JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        WHERE r.booking_status = 'pending'
        AND CONCAT(ms.show_date, ' ', ms.show_hour) <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
    ");
    
    if (!$stmt) return;
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = $row['reservation_id'];
    }
    $stmt->close();
    
    if (empty($ids)) {
        $conn->close();
        return;
    }
    
    foreach ($ids as $resId) {
        // Approve the reservation
        $upd = $conn->prepare("UPDATE RESERVE SET booking_status = 'approved' WHERE reservation_id = ? AND booking_status = 'pending'");
        $upd->bind_param("i", $resId);
        $upd->execute();
        $upd->close();
        
        // Set ticket to valid
        $tkt = $conn->prepare("UPDATE TICKET SET ticket_status = 'valid' WHERE reserve_id = ? AND ticket_status = 'pending'");
        $tkt->bind_param("i", $resId);
        $tkt->execute();
        $tkt->close();
    }
    
    $conn->close();
}

// Run immediately when included
autoApprovePendingBookings();
