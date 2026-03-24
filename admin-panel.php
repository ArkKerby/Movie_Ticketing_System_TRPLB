<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// --- Fetch Stats ---
$totalUsersQuery = $conn->query("SELECT COUNT(*) AS total FROM USER_ACCOUNT WHERE role = 'user'");
$totalUsers = $totalUsersQuery ? intval($totalUsersQuery->fetch_assoc()['total'] ?? 0) : 0;

// Count all reservations (bookings)
$totalBookingsQuery = $conn->query("SELECT COUNT(*) AS total FROM RESERVE");
$totalBookings = $totalBookingsQuery ? intval($totalBookingsQuery->fetch_assoc()['total'] ?? 0) : 0;

// Calculate total revenue from all paid payments
$totalRevenueQuery = $conn->query("SELECT IFNULL(SUM(amount_paid), 0) AS total FROM PAYMENT WHERE payment_status = 'paid'");
$revenueResult = $totalRevenueQuery ? $totalRevenueQuery->fetch_assoc() : null;
$totalRevenue = $revenueResult ? floatval($revenueResult['total'] ?? 0) : 0.00;

// --- Fetch Movies ---
$today = date('Y-m-d');

// Now Showing - ONLY active movies (not deleted)
$nowShowingResult = $conn->query("
    SELECT DISTINCT m.*
    FROM MOVIE m
    LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
    WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
    AND (m.coming_soon = 0 OR m.coming_soon IS NULL)
    AND (m.now_showing = 1 OR (ms.show_date >= '$today' AND ms.show_date IS NOT NULL))
    ORDER BY m.title ASC
    LIMIT 10
");

$nowShowing = [];
if ($nowShowingResult) {
    while ($row = $nowShowingResult->fetch_assoc()) {
        $nowShowing[] = $row;
    }
}

// Fallback if no results
if (empty($nowShowing)) {
    $fallbackResult = $conn->query("
        SELECT * FROM MOVIE 
        WHERE (is_deleted = 0 OR is_deleted IS NULL)
        AND (now_showing = 1)
        AND (coming_soon = 0 OR coming_soon IS NULL)
        ORDER BY title ASC 
        LIMIT 10
    ");
    if ($fallbackResult) {
        while ($row = $fallbackResult->fetch_assoc()) {
            $nowShowing[] = $row;
        }
    }
}

$activeMovies = count($nowShowing);

// Pending PWD applications count
$pwdPendingCount = 0;
$pwdTableCheck = $conn->query("SHOW TABLES LIKE 'PWD_APPLICATIONS'");
if ($pwdTableCheck && $pwdTableCheck->num_rows > 0) {
    $pwdCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM PWD_APPLICATIONS WHERE status = 'pending'");
    if ($pwdCountRes) $pwdPendingCount = intval($pwdCountRes->fetch_assoc()['cnt']);
}

// Coming Soon - ONLY active movies (not deleted)
$comingSoonResult = $conn->query("
    SELECT DISTINCT m.*
    FROM MOVIE m
    LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
    WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
    AND (m.coming_soon = 1)
    AND (m.now_showing = 0 OR m.now_showing IS NULL)
    ORDER BY m.title ASC
    LIMIT 10
");

$comingSoon = [];
if ($comingSoonResult) {
    while ($row = $comingSoonResult->fetch_assoc()) {
        $comingSoon[] = $row;
    }
}

// Fallback if no results
if (empty($comingSoon)) {
    $fallbackResult = $conn->query("
        SELECT * FROM MOVIE 
        WHERE (is_deleted = 0 OR is_deleted IS NULL)
        AND coming_soon = 1 
        AND (now_showing = 0 OR now_showing IS NULL)
        ORDER BY title ASC 
        LIMIT 10
    ");
    if ($fallbackResult) {
        while ($row = $fallbackResult->fetch_assoc()) {
            $comingSoon[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link rel="stylesheet" href="css/ticketix-main.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="images/brand x.png" alt="Profile Picture" class="profile-pic clickable-logo" onclick="toggleLogout()" style="cursor: pointer;" />
            <h2>Admin</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="admin-panel.php" class="active">Dashboard</a>
            <a href="add-show.php">Add Shows</a>
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="admin-pwd-applications.php" style="display:flex;align-items:center;justify-content:space-between;">PWD Applications<?php if($pwdPendingCount>0): ?><span style="background:#e74c3c;color:#fff;font-size:0.72rem;font-weight:700;padding:1px 7px;border-radius:12px;"><?= $pwdPendingCount ?></span><?php endif; ?></a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn" id="logoutBtn" style="display: none;">➜ Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>Admin <span class="highlight">Dashboard</span></h1>
        </header>

        <section class="stats-cards">
            <div class="card"><div class="card-info"><label>Total Bookings</label><h3><?= $totalBookings ?></h3></div><div class="card-icon"></div></div>
            <div class="card"><div class="card-info"><label>Total Revenue</label><h3>₱<?= number_format($totalRevenue, 2) ?></h3></div><div class="card-icon"></div></div>
            <div class="card"><div class="card-info"><label>Active Movies</label><h3><?= $activeMovies ?></h3></div><div class="card-icon"></div></div>
            <div class="card"><div class="card-info"><label>Total Users</label><h3><?= $totalUsers ?></h3></div><div class="card-icon"></div></div>
        </section>

        <!-- Now Showing -->
        <section id="now-showing">
            <h2>Now Showing</h2>
            <div class="movie-grid">
                <?php if (count($nowShowing) > 0): ?>
                    <?php foreach ($nowShowing as $movie): 
                        $duration_min = intval($movie['duration'] ?? 0);
                        $hours = floor($duration_min / 60);
                        $minutes = $duration_min % 60;
                        $duration_formatted = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
                    ?>
                        <div class="movie">
                            <img src="<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                            <div class="movie-overlay">
                                <div class="movie-info">
                                    <h3><?= htmlspecialchars($movie['title']) ?></h3>
                                    <p><?= htmlspecialchars($movie['genre']) ?> • <?= $duration_formatted ?> • <?= htmlspecialchars($movie['rating'] ?: 'N/A') ?></p>
                                    <div class="movie-actions">
                                        <button class="action-btn trailer-btn">▶ Trailer</button>
                                        <a href="view-shows.php" class="action-btn ticket-btn" style="text-decoration: none;">🎟 View Details</a>
                                        <button class="action-btn delete-btn" data-id="<?= $movie['movie_show_id'] ?>" style="background-color: #e74c3c;">🗑 Remove</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #666; grid-column: 1 / -1; padding: 40px;">No movies currently showing.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Coming Soon -->
        <section id="coming-soon">
            <h2>Coming Soon</h2>
            <div class="movie-grid">
                <?php if (count($comingSoon) > 0): ?>
                    <?php foreach ($comingSoon as $movie): 
                        $duration_min = intval($movie['duration'] ?? 0);
                        $hours = floor($duration_min / 60);
                        $minutes = $duration_min % 60;
                        $duration_formatted = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
                    ?>
                        <div class="movie">
                            <img src="<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                            <div class="movie-info">
                                <h3><?= htmlspecialchars($movie['title']) ?></h3>
                                <p><?= htmlspecialchars($movie['genre']) ?> • <?= $duration_formatted ?> • <?= htmlspecialchars($movie['rating'] ?: 'N/A') ?></p>
                                <button class="notify-btn">Notify Me</button>
                            </div>
                            <button class="action-btn delete-btn" data-id="<?= $movie['movie_show_id'] ?>" style="background-color: #e74c3c; margin-top: 10px;">
                                🗑 Remove
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #666; grid-column: 1 / -1; padding: 40px;">No upcoming movies.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
    function toggleLogout() {
        const logoutBtn = document.getElementById('logoutBtn');
        logoutBtn.style.display = (logoutBtn.style.display === 'none' || logoutBtn.style.display === '') ? 'block' : 'none';
    }

    // Handle delete button clicks
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const movieId = this.getAttribute('data-id');

            if (!confirm('Are you sure you want to remove this movie from display? All booking history will be preserved.')) return;

            fetch('delete-movie.php', {
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

    // Keep existing status button handler if you have it
    document.querySelectorAll('.status-btn').forEach(button => {
        button.addEventListener('click', function() {
            const movieId = this.getAttribute('data-id');
            const action = this.getAttribute('data-action');

            if (!confirm(`Are you sure you want to mark this movie as ${action.replace('_', ' ')}?`)) return;

            fetch('update-movie-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${movieId}&action=${action}`
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