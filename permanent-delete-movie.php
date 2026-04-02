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
    
    // If no bookings, safe to permanently delete
    // Delete in correct order to respect foreign keys
    
    // 1. Delete seats associated with schedules
    $conn->query("
        DELETE s FROM SEATS s
        INNER JOIN MOVIE_SCHEDULE ms ON s.schedule_id = ms.schedule_id
        WHERE ms.movie_show_id = $movieId
    ");
    
    // 2. Delete schedules
    $conn->query("DELETE FROM MOVIE_SCHEDULE WHERE movie_show_id = $movieId");
    
    // 3. Delete the movie
    $stmt = $conn->prepare("DELETE FROM MOVIE WHERE movie_show_id = ?");
    $stmt->bind_param("i", $movieId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Movie has been permanently deleted from the database.'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error deleting movie: ' . $conn->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>