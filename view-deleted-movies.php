<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Fetch deleted movies
$deletedMoviesQuery = $conn->query("
    SELECT m.*, 
           COUNT(DISTINCT ms.schedule_id) as schedule_count,
           COUNT(DISTINCT r.reservation_id) as booking_count
    FROM MOVIE m
    LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
    LEFT JOIN RESERVE r ON ms.schedule_id = r.schedule_id
    WHERE m.is_deleted = 1
    GROUP BY m.movie_show_id
    ORDER BY m.deleted_at DESC
");

$deletedMovies = [];
if ($deletedMoviesQuery) {
    while ($row = $deletedMoviesQuery->fetch_assoc()) {
        $deletedMovies[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Deleted Movies - Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link rel="stylesheet" href="css/ticketix-main.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .deleted-movie-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            gap: 20px;
            align-items: center;
        }
        .deleted-movie-card img {
            width: 100px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        .deleted-movie-info {
            flex: 1;
        }
        .deleted-movie-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .deleted-movie-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            font-size: 0.9em;
            color: #666;
        }
        .restore-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .restore-btn:hover {
            background: #218838;
        }
        .permanent-delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin-left: 10px;
        }
        .permanent-delete-btn:hover {
            background: #c82333;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="images/brand x.png" alt="Profile Picture" class="profile-pic clickable-logo" onclick="toggleLogout()" style="cursor: pointer;" />
            <h2>Mall Admin</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="admin-panel.php">Dashboard</a>
            <a href="add-show.php">Add Shows</a>
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php" class="active">Deleted Movies</a>
            <a href="mall-admin/assign-movie.php">Assign Movies</a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn" id="logoutBtn" style="display: none;">➜ Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>Deleted <span class="highlight">Movies</span></h1>
        </header>

        <div class="warning-box">
            <strong>Note:</strong> These movies are hidden from public view but all booking history and revenue data is preserved. 
            You can restore them or permanently delete them (which will remove all associated data).
        </div>

        <?php if (count($deletedMovies) > 0): ?>
            <?php foreach ($deletedMovies as $movie): ?>
                <div class="deleted-movie-card">
                    <img src="<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                    <div class="deleted-movie-info">
                        <h3><?= htmlspecialchars($movie['title']) ?></h3>
                        <p><?= htmlspecialchars($movie['genre']) ?> • <?= htmlspecialchars($movie['rating'] ?: 'N/A') ?></p>
                        <div class="deleted-movie-stats">
                            <span>Deleted: <?= date('M d, Y', strtotime($movie['deleted_at'])) ?></span>
                            <span>Schedules: <?= $movie['schedule_count'] ?></span>
                            <span>Bookings: <?= $movie['booking_count'] ?></span>
                        </div>
                    </div>
                    <div>
                        <button class="restore-btn" data-id="<?= $movie['movie_show_id'] ?>">
                            Restore
                        </button>
                        <?php if ($movie['booking_count'] == 0): ?>
                            <button class="permanent-delete-btn" data-id="<?= $movie['movie_show_id'] ?>">
                                Delete Permanently
                            </button>
                        <?php else: ?>
                            <button class="permanent-delete-btn" disabled title="Cannot permanently delete movies with bookings" style="opacity: 0.5; cursor: not-allowed;">
                                Delete Permanently
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 60px; color: #666;">
                <h3>No deleted movies</h3>
                <p>Movies that are removed will appear here.</p>
            </div>
        <?php endif; ?>
    </main>

    <script>
    function toggleLogout() {
        const logoutBtn = document.getElementById('logoutBtn');
        logoutBtn.style.display = (logoutBtn.style.display === 'none' || logoutBtn.style.display === '') ? 'block' : 'none';
    }

    // Handle restore button
    document.querySelectorAll('.restore-btn').forEach(button => {
        button.addEventListener('click', function() {
            const movieId = this.getAttribute('data-id');

            if (!confirm('Are you sure you want to restore this movie?')) return;

            fetch('restore-movie.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${movieId}`
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Something went wrong.');
            });
        });
    });

    // Handle permanent delete button
    document.querySelectorAll('.permanent-delete-btn:not([disabled])').forEach(button => {
        button.addEventListener('click', function() {
            const movieId = this.getAttribute('data-id');

            if (!confirm('WARNING: This will PERMANENTLY delete the movie and ALL associated data (schedules, seats, etc.). This action CANNOT be undone!\n\nAre you absolutely sure?')) return;

            fetch('permanent-delete-movie.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${movieId}`
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Something went wrong.');
            });
        });
    });
    </script>
</body>
</html>