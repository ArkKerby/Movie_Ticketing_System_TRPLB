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
    
    // Restore the movie
    $stmt = $conn->prepare("UPDATE MOVIE SET is_deleted = 0, deleted_at = NULL WHERE movie_show_id = ?");
    $stmt->bind_param("i", $movieId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Movie has been successfully restored and is now visible.'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error restoring movie: ' . $conn->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>