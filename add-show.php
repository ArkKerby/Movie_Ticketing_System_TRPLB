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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = trim($_POST['title'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);
    $rating = trim($_POST['rating'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $show_date = $_POST['show_date'] ?? '';
    $show_hour = $_POST['show_hour'] ?? '';
    $end_time = $_POST['end_time'] ?? '22:30'; // Default end time 10:30 PM
    $time_interval = intval($_POST['time_interval'] ?? 3); // Default 3 hours
    $delete_at = $_POST['delete_at'] ?? '';

    // Extract YouTube video ID from either a full URL or a bare ID
    $raw_trailer = trim($_POST['trailer_youtube_id'] ?? '');
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $raw_trailer, $m)) {
        $trailer_youtube_id = $m[1]; // extracted from URL
    } elseif (preg_match('/^[A-Za-z0-9_-]{11}$/', $raw_trailer)) {
        $trailer_youtube_id = $raw_trailer; // already a bare ID
    } else {
        $trailer_youtube_id = ''; // invalid / empty
    };
    
    // Normalize delete_at: accept empty or YYYY-MM-DD
    if (!empty($delete_at)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delete_at)) {
            $error_message = "Invalid delete date format. Use YYYY-MM-DD.";
            $delete_at = null; // invalid → null
        }
    } else {
        $delete_at = null; // empty → null (MySQL strict mode rejects '')
    }
    
    // Make checkboxes mutually exclusive
    $coming_soon = isset($_POST['coming_soon']) ? 1 : 0;
    $now_showing = ($coming_soon == 1) ? 0 : (isset($_POST['now_showing']) ? 1 : 0);
    
    // Handle file uploads
    $image_poster = '';
    $carousel_image = '';
    
    // Create upload directories if they don't exist
    $images_dir = __DIR__ . '/images';
    $carousel_dir = __DIR__ . '/carousel';
    if (!is_dir($images_dir)) {
        mkdir($images_dir, 0755, true);
    }
    if (!is_dir($carousel_dir)) {
        mkdir($carousel_dir, 0755, true);
    }
    
    // Handle image_poster upload
    if (isset($_FILES['image_poster']) && $_FILES['image_poster']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image_poster'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'movie_' . time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $images_dir . '/' . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $image_poster = 'images/' . $new_filename;
            } else {
                $error_message = "Failed to upload poster image.";
            }
        } else {
            $error_message = "Invalid poster image. Please upload a valid image file (JPEG, PNG, GIF, or WebP) under 5MB.";
        }
    }
    
    // Handle carousel_image upload
    if (isset($_FILES['carousel_image']) && $_FILES['carousel_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['carousel_image'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'carousel_' . time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $carousel_dir . '/' . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $carousel_image = 'carousel/' . $new_filename;
            } else {
                $error_message = "Failed to upload carousel image.";
            }
        } else {
            $error_message = "Invalid carousel image. Please upload a valid image file (JPEG, PNG, GIF, or WebP) under 5MB.";
        }
    }
    
    if (empty($title) || empty($genre) || empty($duration) || empty($rating)) {
        $error_message = "Please fill in all required fields (Title, Genre, Duration, Rating).";
    } else {
        // Truncate genre to 100 characters (database limit)
        $genre = substr($genre, 0, 100);
        
        // Check if columns exist
        $columns_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'now_showing'");
        $has_now_showing = $columns_check && $columns_check->num_rows > 0;
        
        $carousel_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'carousel_image'");
        $has_carousel_image = $carousel_check && $carousel_check->num_rows > 0;

        $delete_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'delete_at'");
        $has_delete_at = $delete_check && $delete_check->num_rows > 0;

        $trailer_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'trailer_youtube_id'");
        $has_trailer = $trailer_check && $trailer_check->num_rows > 0;
        $trailer_val = ($has_trailer && $trailer_youtube_id !== '') ? $trailer_youtube_id : null;
        
        // Insert movie — all branches now also include trailer_youtube_id when available
        if ($has_now_showing && $has_carousel_image) {
            if ($has_delete_at) {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, now_showing, coming_soon, delete_at, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, now_showing, coming_soon, delete_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssissssiiss", $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $now_showing, $coming_soon, $delete_at, $trailer_val);
                    else             $stmt->bind_param("ssissssiis",  $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $now_showing, $coming_soon, $delete_at);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            } else {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, now_showing, coming_soon, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, now_showing, coming_soon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssissssiis", $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $now_showing, $coming_soon, $trailer_val);
                    else             $stmt->bind_param("ssissssii",  $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $now_showing, $coming_soon);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            }
        } else if ($has_now_showing) {
            if ($has_delete_at) {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, now_showing, coming_soon, delete_at, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, now_showing, coming_soon, delete_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssisssiiss", $title, $genre, $duration, $rating, $description, $image_poster, $now_showing, $coming_soon, $delete_at, $trailer_val);
                    else             $stmt->bind_param("ssisssiis",  $title, $genre, $duration, $rating, $description, $image_poster, $now_showing, $coming_soon, $delete_at);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            } else {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, now_showing, coming_soon, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, now_showing, coming_soon) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssisssiis", $title, $genre, $duration, $rating, $description, $image_poster, $now_showing, $coming_soon, $trailer_val);
                    else             $stmt->bind_param("ssisssii",  $title, $genre, $duration, $rating, $description, $image_poster, $now_showing, $coming_soon);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            }
        } else if ($has_carousel_image) {
            if ($has_delete_at) {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, delete_at, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, delete_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssissssss", $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $delete_at, $trailer_val);
                    else             $stmt->bind_param("ssisssss",  $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $delete_at);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            } else {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, carousel_image) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssisssss", $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image, $trailer_val);
                    else             $stmt->bind_param("ssissss",  $title, $genre, $duration, $rating, $description, $image_poster, $carousel_image);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            }
        } else {
            if ($has_delete_at) {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, delete_at, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, delete_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssisssss", $title, $genre, $duration, $rating, $description, $image_poster, $delete_at, $trailer_val);
                    else             $stmt->bind_param("ssissss",  $title, $genre, $duration, $rating, $description, $image_poster, $delete_at);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            } else {
                $sql = $has_trailer
                    ? "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster, trailer_youtube_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    : "INSERT INTO MOVIE (title, genre, duration, rating, movie_descrp, image_poster) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if ($has_trailer) $stmt->bind_param("ssissss", $title, $genre, $duration, $rating, $description, $image_poster, $trailer_val);
                    else             $stmt->bind_param("ssisss",  $title, $genre, $duration, $rating, $description, $image_poster);
                    $execute_result = $stmt->execute();
                } else { $execute_result = false; $error_message = "Error preparing statement: " . $conn->error; }
            }
        }
        
        if ($execute_result) {
            $movie_id = $conn->insert_id;
            $success_message = "Movie added successfully!";
            
            // Check if branch_id column exists in MOVIE_SCHEDULE
            $branch_check = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
            $has_branch_id = $branch_check && $branch_check->num_rows > 0;
            
            // ===== AUTO-GENERATE SHOWTIMES =====
            if (!empty($show_date) && !empty($show_hour)) {
                // Generate multiple showtimes from start to end
                $showtimes = [];
                $current = strtotime($show_hour);
                $end = strtotime($end_time);
                
                while ($current <= $end) {
                    $showtimes[] = date('H:i:s', $current);
                    $current = strtotime("+{$time_interval} hours", $current);
                }
                
                // Check if BRANCH table exists
                $table_check = $conn->query("SHOW TABLES LIKE 'BRANCH'");
                if (!$table_check || $table_check->num_rows == 0) {
                    $table_check = $conn->query("SHOW TABLES LIKE 'branch'");
                }
                
                if ($table_check && $table_check->num_rows > 0) {
                    // Fetch all branches
                    $branches_query = @$conn->query("SELECT branch_id FROM BRANCH");
                    if (!$branches_query) {
                        $branches_query = @$conn->query("SELECT branch_id FROM branch");
                    }
                    
                    $schedules_added = 0;
                    $schedules_failed = 0;
                    
                    if ($branches_query && $branches_query->num_rows > 0) {
                        if ($has_branch_id) {
                            // Insert schedule for each branch and each showtime
                            $schedule_stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour, branch_id) VALUES (?, ?, ?, ?)");
                            while ($branch = $branches_query->fetch_assoc()) {
                                foreach ($showtimes as $time) {
                                    $schedule_stmt->bind_param("issi", $movie_id, $show_date, $time, $branch['branch_id']);
                                    if ($schedule_stmt->execute()) {
                                        $schedules_added++;
                                    } else {
                                        $schedules_failed++;
                                    }
                                }
                            }
                            $schedule_stmt->close();
                        } else {
                            // No branch_id column - insert each showtime once
                            $schedule_stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour) VALUES (?, ?, ?)");
                            foreach ($showtimes as $time) {
                                $schedule_stmt->bind_param("iss", $movie_id, $show_date, $time);
                                if ($schedule_stmt->execute()) {
                                    $schedules_added++;
                                } else {
                                    $schedules_failed++;
                                }
                            }
                            $schedule_stmt->close();
                        }
                        
                        if ($schedules_added > 0) {
                            $num_times = count($showtimes);
                            if ($has_branch_id) {
                                $num_branches = $branches_query->num_rows;
                                $success_message .= " Generated {$num_times} showtimes across {$num_branches} branch(es) ({$schedules_added} total schedules)!";
                            } else {
                                $success_message .= " Generated {$schedules_added} showtimes!";
                            }
                            if ($schedules_failed > 0) {
                                $error_message = "Movie added, but {$schedules_failed} schedule(s) failed.";
                            }
                        }
                    } else {
                        if ($branches_query === false) {
                            $error_message = "Movie added but could not fetch branches. Error: " . $conn->error;
                        } else {
                            $error_message = "Movie added but no branches found. Please add branches first.";
                        }
                    }
                } else {
                    $error_message = "Movie added but BRANCH table not found. Please run the database setup script.";
                }
            } else if ($now_showing == 1) {
                // If movie is marked as "now showing" without manual schedule, create defaults
                $table_check = $conn->query("SHOW TABLES LIKE 'BRANCH'");
                if (!$table_check || $table_check->num_rows == 0) {
                    $table_check = $conn->query("SHOW TABLES LIKE 'branch'");
                }
                
                if ($table_check && $table_check->num_rows > 0) {
                    $branches_query = @$conn->query("SELECT branch_id FROM BRANCH");
                    if (!$branches_query) {
                        $branches_query = @$conn->query("SELECT branch_id FROM branch");
                    }
                    
                    if ($branches_query && $branches_query->num_rows > 0) {
                        $default_times = ['10:00:00', '13:00:00', '16:00:00', '19:00:00', '22:00:00'];
                        $today = date('Y-m-d');
                        
                        $schedules_added = 0;
                        
                        if ($has_branch_id) {
                            $schedule_stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour, branch_id) VALUES (?, ?, ?, ?)");
                            while ($branch = $branches_query->fetch_assoc()) {
                                foreach ($default_times as $time) {
                                    $schedule_stmt->bind_param("issi", $movie_id, $today, $time, $branch['branch_id']);
                                    if ($schedule_stmt->execute()) {
                                        $schedules_added++;
                                    }
                                }
                            }
                            $schedule_stmt->close();
                            
                            if ($schedules_added > 0) {
                                $success_message .= " Default schedules created ({$schedules_added} schedule entries).";
                            }
                        } else {
                            $schedule_stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour) VALUES (?, ?, ?)");
                            foreach ($default_times as $time) {
                                $schedule_stmt->bind_param("iss", $movie_id, $today, $time);
                                if ($schedule_stmt->execute()) {
                                    $schedules_added++;
                                }
                            }
                            $schedule_stmt->close();
                            
                            if ($schedules_added > 0) {
                                $success_message .= " Default schedules created ({$schedules_added} schedule entries).";
                            }
                        }
                    }
                }
            } else {
                $success_message .= " You can add schedules for this movie later from the movie list.";
            }
        } else {
            $error_message = "Error adding movie: " . $conn->error;
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
    <title>Add Show - Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 800px;
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
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s;
        }
        
        .form-group input[type="file"] {
            padding: 8px;
            cursor: pointer;
            background: #000000;
        }
        
        .form-group input[type="file"]:hover {
            background: #e9ecef;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #00BFFF;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
        
        .add-schedule-section {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #e0e0e0;
        }
        
        .add-schedule-section h3 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .highlight-box {
            background: #f0f8ff;
            border-left: 4px solid #00BFFF;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #000000;
        }
        
        .highlight-box strong {
            color: #00BFFF;
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
            <a href="add-show.php" class="active">Add Shows</a>
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="mall-admin/assign-movie.php">Assign Movies</a>
        </nav>
    </aside>
    <main class="main-content">
        <header>
            <h1>Add <span class="highlight">Show</span></h1>
        </header>

        <div class="form-container">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" action="add-show.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Movie Title *</label>
                    <input type="text" id="title" name="title" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="genre">Genre *</label>
                        <input type="text" id="genre" name="genre" required>
                    </div>
                    <div class="form-group">
                        <label for="duration">Duration (minutes) *</label>
                        <input type="number" id="duration" name="duration" min="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="rating">Rating *</label>
                    <select id="rating" name="rating" required>
                        <option value="">Select Rating</option>
                        <option value="G">G</option>
                        <option value="PG">PG</option>
                        <option value="PG-13">PG-13</option>
                        <option value="R">R</option>
                        <option value="NC-17">NC-17</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="image_poster">Image Poster (Portrait)</label>
                        <input type="file" id="image_poster" name="image_poster" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                        <small style="color: #666; font-size: 12px;">Portrait/vertical poster image for movie cards (JPEG, PNG, GIF, or WebP, max 5MB)</small>
                    </div>
                    <div class="form-group">
                        <label for="carousel_image">Carousel Image (Landscape)</label>
                        <input type="file" id="carousel_image" name="carousel_image" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                        <small style="color: #666; font-size: 12px;">Landscape/horizontal image for carousel background (JPEG, PNG, GIF, or WebP, max 5MB)</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Enter movie description..."></textarea>
                </div>

                <div class="form-group">
                    <label for="delete_at">Delete On (optional)</label>
                    <input type="date" id="delete_at" name="delete_at">
                    <small style="color: #666; font-size: 12px;">Optional date when this movie should be deleted/archived automatically.</small>
                </div>

                <!-- ── TRAILER FIELD ─────────────────────────────── -->
                <div class="form-group">
                    <label for="trailer_youtube_id">YouTube Trailer</label>
                    <input type="text" id="trailer_youtube_id" name="trailer_youtube_id"
                           placeholder="Paste YouTube URL or video ID  e.g. https://youtu.be/YShVEXb7-ic"
                           oninput="previewTrailer(this.value)">
                    <small style="color:#666;font-size:12px;">Accepts a full YouTube URL <strong>or</strong> just the 11-character video ID. Leave blank if no trailer yet.</small>
                </div>

                <!-- Live preview -->
                <div id="trailer-preview-wrap" style="display:none; margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:13px;font-weight:600;color:#333;">Trailer Preview</span>
                        <span id="trailer-id-badge" style="font-size:11px;background:#00BFFF22;color:#00BFFF;border:1px solid #00BFFF44;border-radius:6px;padding:2px 8px;"></span>
                    </div>
                    <div style="position:relative;padding-bottom:56.25%;height:0;border-radius:10px;overflow:hidden;border:2px solid rgba(0,191,255,0.3);">
                        <iframe id="trailer-preview-iframe"
                            style="position:absolute;top:0;left:0;width:100%;height:100%;"
                            src="" frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen></iframe>
                    </div>
                </div>

                <script>
                function extractYTId(raw) {
                    raw = raw.trim();
                    var m = raw.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
                    if (m) return m[1];
                    if (/^[A-Za-z0-9_-]{11}$/.test(raw)) return raw;
                    return null;
                }
                function previewTrailer(raw) {
                    var wrap   = document.getElementById('trailer-preview-wrap');
                    var iframe = document.getElementById('trailer-preview-iframe');
                    var badge  = document.getElementById('trailer-id-badge');
                    var id = extractYTId(raw);
                    if (id) {
                        iframe.src = 'https://www.youtube.com/embed/' + id + '?rel=0&modestbranding=1';
                        badge.textContent = 'ID: ' + id;
                        wrap.style.display = 'block';
                    } else {
                        iframe.src = '';
                        wrap.style.display = 'none';
                    }
                }
                </script>
                <!-- ── END TRAILER FIELD ──────────────────────────── -->

                <div class="form-row" style="margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="now_showing" name="now_showing" value="1" checked
                                   style="width: auto; accent-color: #00BFFF; transform: scale(1.3);"
                                   onchange="if(this.checked) document.getElementById('coming_soon').checked = false;">
                            <span>Now Showing</span>
                        </label>
                        <small style="color: #666; font-size: 12px; margin-top: 4px;">Movie will appear in the "Now Showing" section</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="coming_soon" name="coming_soon" value="1"
                                   style="width: auto; accent-color: #e67e22; transform: scale(1.3);"
                                   onchange="if(this.checked) document.getElementById('now_showing').checked = false;">
                            <span>Coming Soon</span>
                        </label>
                        <small style="color: #666; font-size: 12px; margin-top: 4px;">Movie will appear in the "Coming Soon" section</small>
                    </div>
                </div>

                <div style="margin-bottom: 20px; padding: 14px 18px; background: rgba(0,180,216,0.1); border: 1px solid rgba(0,180,216,0.3); border-radius: 8px; color: #b0cfe0; font-size: 13px; line-height: 1.5;">
                    <strong style="color: #00b4d8;">Note:</strong> Movies added here will only be visible to customers after you assign them to a Cinema Type (e.g., IMAX, Director's Club, Regular) via <strong>Assign Movies</strong>.
                </div>

                <div class="add-schedule-section">
                    <h3>Auto-Generate Show Schedules</h3>
                    
                    <div class="highlight-box">
                        <strong></strong> Auto-Generate Feature:</strong> Enter a start time and the system will automatically create multiple showtimes until the end time at your chosen interval for all branches!
                    </div>
                    
                    <div class="form-group">
                        <label for="show_date">Show Date</label>
                        <input type="date" id="show_date" name="show_date">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="show_hour">Start Time</label>
                            <input type="time" id="show_hour" name="show_hour" value="10:30">
                            <small style="color: #666; font-size: 12px;">First showtime of the day</small>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" value="22:30">
                            <small style="color: #666; font-size: 12px;">Last showtime of the day (default: 10:30 PM)</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="time_interval">Time Interval Between Shows</label>
                        <select id="time_interval" name="time_interval">
                            <option value="2">Every 2 hours</option>
                            <option value="3" selected>Every 3 hours</option>
                            <option value="4">Every 4 hours</option>
                        </select>
                        <small style="color: #666; font-size: 12px;">
                            Example: Start 10:30 AM, End 10:30 PM, Interval 3 hours = 10:30 AM, 1:30 PM, 4:30 PM, 7:30 PM, 10:30 PM
                        </small>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Add Show with Auto-Generated Times</button>
            </form>
        </div>
    </main>
</body>
</html>