<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../config.php';
$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assignment_id'])) {
    $assignment_id = intval($_POST['assignment_id']);

    // Permanently delete the assignment
    $deleteStmt = $conn->prepare("DELETE FROM CINEMA_MOVIE_ASSIGNMENT WHERE assignment_id = ?");
    $deleteStmt->bind_param("i", $assignment_id);

    if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Movie assignment permanently deleted.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Assignment not found or already deleted.'
        ]);
    }

    $deleteStmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
