<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $movieId = intval($_POST['id']);
    
    // Check if movie has any bookings
    $checkBookings = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM RESERVE r
        INNER JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        WHERE ms.movie_show_id = ?
    ");
    $checkBookings->bind_param("i", $movieId);
    $checkBookings->execute();
    $result = $checkBookings->get_result()->fetch_assoc();
    $checkBookings->close();
    
    if ($result['count'] > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot permanently delete movie with existing bookings. Revenue data must be preserved.'
        ]);
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // Delete in correct order to respect foreign keys
        
        // 1. Delete cinema assignments for this movie
        $conn->query("DELETE FROM CINEMA_MOVIE_ASSIGNMENT WHERE movie_show_id = $movieId");
        
        // 2. Delete reserve_seats linked to schedules of this movie (if any exist)
        $conn->query("
            DELETE rs FROM RESERVE_SEAT rs
            INNER JOIN RESERVE r ON rs.reservation_id = r.reservation_id
            INNER JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
            WHERE ms.movie_show_id = $movieId
        ");
        
        // 3. Delete reservations linked to schedules of this movie
        $conn->query("
            DELETE r FROM RESERVE r
            INNER JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
            WHERE ms.movie_show_id = $movieId
        ");
        
        // 4. Delete schedules
        $conn->query("DELETE FROM MOVIE_SCHEDULE WHERE movie_show_id = $movieId");
        
        // 5. Delete the movie
        $stmt = $conn->prepare("DELETE FROM MOVIE WHERE movie_show_id = ?");
        $stmt->bind_param("i", $movieId);
        
        if ($stmt->execute()) {
            $conn->commit();
            echo json_encode([
                'success' => true, 
                'message' => 'Movie has been permanently deleted from the database.'
            ]);
        } else {
            throw new Exception('Error deleting movie: ' . $conn->error);
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
    
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>