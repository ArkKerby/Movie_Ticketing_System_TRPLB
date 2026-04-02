<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$success_message = '';
$error_message = '';

// Handle delete action
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $type = $_GET['delete']; // 'movie' or 'schedule'
    
    if ($type === 'movie') {
        // Get all schedule_ids for this movie
        $getSchedules = $conn->prepare("SELECT schedule_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ?");
        $getSchedules->bind_param("i", $id);
        $getSchedules->execute();
        $result = $getSchedules->get_result();
        $scheduleIds = [];
        while ($row = $result->fetch_assoc()) {
            $scheduleIds[] = $row['schedule_id'];
        }
        $getSchedules->close();
        
        // For each schedule, delete related reservations and their dependencies
        foreach ($scheduleIds as $scheduleId) {
            // Get all reservation_ids for this schedule
            $getReservations = $conn->prepare("SELECT reservation_id FROM RESERVE WHERE schedule_id = ?");
            $getReservations->bind_param("i", $scheduleId);
            $getReservations->execute();
            $resResult = $getReservations->get_result();
            $reservationIds = [];
            while ($row = $resResult->fetch_assoc()) {
                $reservationIds[] = $row['reservation_id'];
            }
            $getReservations->close();
            
            // Delete related records for each reservation
            foreach ($reservationIds as $reservationId) {
                // First get all ticket_ids for this reservation
                $getTickets = $conn->prepare("SELECT ticket_id FROM TICKET WHERE reserve_id = ?");
                $getTickets->bind_param("i", $reservationId);
                $getTickets->execute();
                $ticketResult = $getTickets->get_result();
                $ticketIds = [];
                while ($row = $ticketResult->fetch_assoc()) {
                    $ticketIds[] = $row['ticket_id'];
                }
                $getTickets->close();
                
                // Delete TICKET_FOOD records for each ticket
                foreach ($ticketIds as $ticketId) {
                    $deleteTicketFood = $conn->prepare("DELETE FROM TICKET_FOOD WHERE ticket_id = ?");
                    $deleteTicketFood->bind_param("i", $ticketId);
                    $deleteTicketFood->execute();
                    $deleteTicketFood->close();
                }
                
                // Delete TICKET records
                $deleteTickets = $conn->prepare("DELETE FROM TICKET WHERE reserve_id = ?");
                $deleteTickets->bind_param("i", $reservationId);
                $deleteTickets->execute();
                $deleteTickets->close();
                
                // Delete RESERVE_SEAT records
                $deleteReserveSeats = $conn->prepare("DELETE FROM RESERVE_SEAT WHERE reservation_id = ?");
                $deleteReserveSeats->bind_param("i", $reservationId);
                $deleteReserveSeats->execute();
                $deleteReserveSeats->close();
            }
            
            // Delete RESERVE records for this schedule
            $deleteReserves = $conn->prepare("DELETE FROM RESERVE WHERE schedule_id = ?");
            $deleteReserves->bind_param("i", $scheduleId);
            $deleteReserves->execute();
            $deleteReserves->close();
        }
        
        // Now delete all schedules for this movie
        $deleteSchedules = $conn->prepare("DELETE FROM MOVIE_SCHEDULE WHERE movie_show_id = ?");
        $deleteSchedules->bind_param("i", $id);
        $deleteSchedules->execute();
        $deleteSchedules->close();
        
        // Finally delete the movie
        $stmt = $conn->prepare("DELETE FROM MOVIE WHERE movie_show_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success_message = "Movie and all its schedules deleted successfully!";
        } else {
            $error_message = "Error deleting movie: " . $conn->error;
        }
        $stmt->close();
    } elseif ($type === 'schedule') {
        // Get all reservation_ids for this schedule
        $getReservations = $conn->prepare("SELECT reservation_id FROM RESERVE WHERE schedule_id = ?");
        $getReservations->bind_param("i", $id);
        $getReservations->execute();
        $resResult = $getReservations->get_result();
        $reservationIds = [];
        while ($row = $resResult->fetch_assoc()) {
            $reservationIds[] = $row['reservation_id'];
        }
        $getReservations->close();
        
        // Delete related records for each reservation
        foreach ($reservationIds as $reservationId) {
            // First get all ticket_ids for this reservation
            $getTickets = $conn->prepare("SELECT ticket_id FROM TICKET WHERE reserve_id = ?");
            $getTickets->bind_param("i", $reservationId);
            $getTickets->execute();
            $ticketResult = $getTickets->get_result();
            $ticketIds = [];
            while ($row = $ticketResult->fetch_assoc()) {
                $ticketIds[] = $row['ticket_id'];
            }
            $getTickets->close();
            
            // Delete TICKET_FOOD records for each ticket
            foreach ($ticketIds as $ticketId) {
                $deleteTicketFood = $conn->prepare("DELETE FROM TICKET_FOOD WHERE ticket_id = ?");
                $deleteTicketFood->bind_param("i", $ticketId);
                $deleteTicketFood->execute();
                $deleteTicketFood->close();
            }
            
            // Delete TICKET records
            $deleteTickets = $conn->prepare("DELETE FROM TICKET WHERE reserve_id = ?");
            $deleteTickets->bind_param("i", $reservationId);
            $deleteTickets->execute();
            $deleteTickets->close();
            
            // Delete RESERVE_SEAT records
            $deleteReserveSeats = $conn->prepare("DELETE FROM RESERVE_SEAT WHERE reservation_id = ?");
            $deleteReserveSeats->bind_param("i", $reservationId);
            $deleteReserveSeats->execute();
            $deleteReserveSeats->close();
        }
        
        // Delete RESERVE records for this schedule
        $deleteReserves = $conn->prepare("DELETE FROM RESERVE WHERE schedule_id = ?");
        $deleteReserves->bind_param("i", $id);
        $deleteReserves->execute();
        $deleteReserves->close();
        
        // Finally delete the schedule
        $stmt = $conn->prepare("DELETE FROM MOVIE_SCHEDULE WHERE schedule_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success_message = "Schedule deleted successfully!";
        } else {
            $error_message = "Error deleting schedule: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch all movies with their schedules
$movies_query = $conn->query("
    SELECT 
        m.movie_show_id,
        m.title,
        m.genre,
        m.duration,
        m.rating,
        m.movie_descrp,
        m.image_poster,
        m.delete_at,
        ms.schedule_id,
        ms.show_date,
        ms.show_hour
    FROM MOVIE m
    LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
    ORDER BY m.title, ms.show_date, ms.show_hour
");

$movies = [];
if ($movies_query) {
    while ($row = $movies_query->fetch_assoc()) {
        $movie_id = $row['movie_show_id'];
        if (!isset($movies[$movie_id])) {
            $movies[$movie_id] = [
                'movie_show_id' => $row['movie_show_id'],
                'title' => $row['title'],
                'genre' => $row['genre'],
                'duration' => $row['duration'],
                'rating' => $row['rating'],
                'movie_descrp' => $row['movie_descrp'],
                'image_poster' => $row['image_poster'],
                'delete_at' => $row['delete_at'],
                'schedules' => []
            ];
        }
        if ($row['schedule_id']) {
            $movies[$movie_id]['schedules'][] = [
                'schedule_id' => $row['schedule_id'],
                'show_date' => $row['show_date'],
                'show_hour' => $row['show_hour']
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>View Shows - Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .shows-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: visible;
        }
        
        .movie-card {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid #00BFFF;
            transition: transform 0.3s ease, background 0.3s ease, box-shadow 0.3s ease;
            overflow: visible;
            min-height: auto;
            width: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .movie-card:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .movie-header {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
            align-items: start;
        }
        
        .movie-info {
            flex: 1;
            overflow: visible;
            display: flex;
            flex-direction: column;
        }
        
        .movie-main-content {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 30px;
            align-items: start;
        }
        
        .movie-content-left {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .movie-actions-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
            min-width: 150px;
        }
        
        .movie-content-left h3 {
            color: #fff;
            margin-bottom: 15px;
            font-size: 24px;
            font-weight: 600;
        }
        
        .movie-info h3 {
            color: #fff;
            margin-bottom: 10px;
            font-size: 20px;
            font-weight: 600;
        }
        
        .movie-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
            color: #cbd5e1;
            font-size: 14px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .movie-details span {
            white-space: nowrap;
        }
        
        .movie-details strong {
            color: #fff;
        }
        
        .movie-info p {
            color: #cbd5e1;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.6;
            margin-top: 10px;
            max-width: 100%;
        }
        
        .movie-description {
            color: #cbd5e1;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.6;
            margin-top: 10px;
            padding: 10px 0;
            max-width: 100%;
            display: block;
        }
        
        .schedules-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            grid-column: 1 / -1;
        }
        
        .schedules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .schedules-section h4 {
            color: #fff;
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 600;
        }
        
        .schedule-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: background 0.3s ease;
        }
        
        .schedule-item:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        
        .schedule-info {
            color: #fff;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s, transform 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-delete:hover {
            background: #c82333;
            transform: scale(1.05);
        }
        
        .btn-add-schedule {
            background: #28a745;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s, transform 0.2s;
        }
        
        .btn-add-schedule:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: rgba(212, 237, 218, 0.9);
            color: #155724;
            border: 1px solid rgba(195, 230, 203, 0.5);
        }
        
        .alert-error {
            background: rgba(248, 215, 218, 0.9);
            color: #721c24;
            border: 1px solid rgba(245, 198, 203, 0.5);
        }
        
        .no-schedules {
            color: #cbd5e1;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .movie-header {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .movie-main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .movie-actions-right {
                align-items: flex-start;
            }
            
            .movie-details {
                flex-direction: column;
                gap: 10px;
            }
            
            .schedules-grid {
                grid-template-columns: 1fr;
            }
            
            .schedule-item {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="images/brand x.png" alt="Profile Picture" class="profile-pic" />
            <h2>Mall Admin</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="admin-panel.php">Dashboard</a>
            <a href="add-show.php">Add Shows</a>
            <a href="view-shows.php" class="active">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="mall-admin/assign-movie.php">Assign Movies</a>
        </nav>
    </aside>
    <main class="main-content">
        <header>
            <h1>View <span class="highlight">Shows</span></h1>
        </header>

        <div class="shows-container">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <?php if (empty($movies)): ?>
                <p style="color: #cbd5e1; text-align: center; padding: 40px;">No shows found. <a href="add-show.php" style="color: #00BFFF; text-decoration: underline;">Add a show</a> to get started.</p>
            <?php else: ?>
                <?php foreach ($movies as $movie): ?>
                    <div class="movie-card">
                        <div class="movie-main-content">
                            <div class="movie-content-left">
                                <h3><?= htmlspecialchars($movie['title']) ?></h3>
                                <div class="movie-details">
                                    <span><strong>Genre:</strong> <?= htmlspecialchars($movie['genre']) ?></span>
                                    <span><strong>Duration:</strong> <?= htmlspecialchars($movie['duration']) ?> min</span>
                                    <span><strong>Rated:</strong> <?= htmlspecialchars($movie['rating']) ?></span>
                                    <?php if (!empty($movie['delete_at'])): ?>
                                        <span><strong>Delete On:</strong> <?= date('M d, Y', strtotime($movie['delete_at'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($movie['movie_descrp']): ?>
                                    <p class="movie-description"><?= htmlspecialchars($movie['movie_descrp']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="movie-actions-right">
                                <a href="?delete=movie&id=<?= $movie['movie_show_id'] ?>" 
                                   class="btn-delete" 
                                   onclick="return confirm('Are you sure you want to delete this movie and all its schedules?')">
                                    Delete Movie
                                </a>
                            </div>
                        </div>

                        <div class="schedules-section">
                            <h4>Show Schedules:</h4>
                            <?php if (empty($movie['schedules'])): ?>
                                <p class="no-schedules">No schedules added yet.</p>
                            <?php else: ?>
                                <div class="schedules-grid">
                                    <?php foreach ($movie['schedules'] as $schedule): ?>
                                        <div class="schedule-item">
                                            <div class="schedule-info">
                                                <strong><?= date('M d, Y', strtotime($schedule['show_date'])) ?></strong> 
                                                at <?= date('g:i A', strtotime($schedule['show_hour'])) ?>
                                            </div>
                                            <a href="?delete=schedule&id=<?= $schedule['schedule_id'] ?>" 
                                               class="btn-delete" 
                                               onclick="return confirm('Are you sure you want to delete this schedule?')">
                                                Delete
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <a href="add-schedule.php?movie_id=<?= $movie['movie_show_id'] ?>" class="btn-add-schedule">+ Add Schedule</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>