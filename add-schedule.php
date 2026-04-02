<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$movie_id = intval($_GET['movie_id'] ?? 0);
$success_message = '';
$error_message = '';

// Get movie info
$movie = null;
if ($movie_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM MOVIE WHERE movie_show_id = ?");
    $stmt->bind_param("i", $movie_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $movie = $result->fetch_assoc();
    $stmt->close();
}

if (!$movie) {
    header("Location: view-shows.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $show_date = $_POST['show_date'] ?? '';
    $show_hour = $_POST['show_hour'] ?? '';
    
    if (empty($show_date) || empty($show_hour)) {
        $error_message = "Please fill in both date and time.";
    } else {
        $stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $movie_id, $show_date, $show_hour);
        
        if ($stmt->execute()) {
            $success_message = "Schedule added successfully!";
            // Redirect after 2 seconds
            header("Refresh: 2; url=view-shows.php");
        } else {
            $error_message = "Error adding schedule: " . $conn->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Add Schedule - Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #00BFFF;
        }
        
        .movie-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .movie-info h3 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #00BFFF, #3C50B2);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            width: 100%;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="mall-admin/assign-movie.php">Assign Movies</a>
        </nav>
    </aside>
    <main class="main-content">
        <header>
            <h1>Add <span class="highlight">Schedule</span></h1>
        </header>

        <div class="form-container">
            <div class="movie-info">
                <h3><?= htmlspecialchars($movie['title']) ?></h3>
                <p style="color: #666; font-size: 14px;"><?= htmlspecialchars($movie['genre']) ?> • <?= htmlspecialchars($movie['duration']) ?> min • <?= htmlspecialchars($movie['rating']) ?></p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" action="add-schedule.php?movie_id=<?= $movie_id ?>">
                <div class="form-group">
                    <label for="show_date">Show Date *</label>
                    <input type="date" id="show_date" name="show_date" required>
                </div>

                <div class="form-group">
                    <label for="show_hour">Show Time *</label>
                    <input type="time" id="show_hour" name="show_hour" required>
                </div>

                <button type="submit" class="btn-submit">Add Schedule</button>
            </form>
            
            <p style="text-align: center; margin-top: 20px;">
                <a href="view-shows.php" style="color: #00BFFF; text-decoration: none;">← Back to Shows</a>
            </p>
        </div>
    </main>
</body>
</html>