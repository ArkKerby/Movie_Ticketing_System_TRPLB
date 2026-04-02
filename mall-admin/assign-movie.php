<?php
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config.php';
$conn = getDBConnection();

$acc_id = $_SESSION['acc_id'];
$user_name = $_SESSION['user_name'] ?? 'Mall Admin';

// Single branch — TICKETIX
$branch_id = 1;
$branch_name = 'TICKETIX';

// Get cinema types
$cinemaStmt = $conn->prepare("SELECT cinema_number_id, cinema_name FROM CINEMA_NUMBER WHERE branch_id = ? ORDER BY FIELD(cinema_name, 'IMAX', 'Director''s Club', 'Regular'), cinema_name");
$cinemaStmt->bind_param("i", $branch_id);
$cinemaStmt->execute();
$cinemaResult = $cinemaStmt->get_result();
$cinemas = [];
while ($row = $cinemaResult->fetch_assoc()) {
    $cinemas[] = $row;
}
$cinemaStmt->close();

// Handle assignment POST
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['movie_id']) && isset($_POST['cinema_number_id'])) {
    $movie_id = intval($_POST['movie_id']);
    $cinema_number_id = intval($_POST['cinema_number_id']);

    // Check if already assigned
    $dupCheck = $conn->prepare("SELECT 1 FROM CINEMA_MOVIE_ASSIGNMENT WHERE cinema_number_id = ? AND movie_show_id = ?");
    $dupCheck->bind_param("ii", $cinema_number_id, $movie_id);
    $dupCheck->execute();
    $dupResult = $dupCheck->get_result();

    if ($dupResult->num_rows > 0) {
        $error_message = "This movie is already assigned to that cinema.";
    } else {
        $insertStmt = $conn->prepare("INSERT INTO CINEMA_MOVIE_ASSIGNMENT (cinema_number_id, movie_show_id, assigned_by) VALUES (?, ?, ?)");
        $insertStmt->bind_param("iii", $cinema_number_id, $movie_id, $acc_id);
        if ($insertStmt->execute()) {
            $success_message = "Movie assigned successfully!";
        } else {
            $error_message = "Failed to assign movie: " . $conn->error;
        }
        $insertStmt->close();
    }
    $dupCheck->close();
}

// Fetch available movies
$moviesResult = $conn->query("
    SELECT movie_show_id, title, genre, duration, rating, image_poster
    FROM MOVIE
    WHERE (is_deleted = 0 OR is_deleted IS NULL)
    ORDER BY title ASC
");

$movies = [];
if ($moviesResult) {
    while ($row = $moviesResult->fetch_assoc()) {
        $movies[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assign Movies - TICKETIX</title>
    <link rel="icon" type="image/png" href="../images/brand x.png" />
    <link rel="stylesheet" href="../css/mall-admin.css" />
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="../images/brand x.png" alt="Logo" class="profile-pic clickable-logo" onclick="toggleLogout()" style="cursor: pointer;" />
            <h2><?= htmlspecialchars($user_name) ?></h2>
            <p class="branch-label">Mall Admin</p>
        </div>
        <nav class="sidebar-nav">
            <a href="../admin-panel.php">Dashboard</a>
            <a href="../add-show.php">Add Shows</a>
            <a href="../view-shows.php">List Shows</a>
            <a href="../view-bookings.php">List Bookings</a>
            <a href="../view-deleted-movies.php">Deleted Movies</a>
            <a href="assign-movie.php" class="active">Assign Movies</a>
            <a href="../admin-pwd-applications.php">PWD Applications</a>
            <a href="../admin-senior-applications.php">Senior Applications</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" class="logout-btn" id="logoutBtn" style="display: none;">➜ Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>Assign <span class="highlight">Movies</span></h1>
        </header>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <section class="movies-available">
            <h2>Available Movies — Assign to Cinema</h2>

            <?php if (empty($movies)): ?>
                <p style="color: #5a8a9e; text-align: center; padding: 40px;">No movies available. The Super Admin has not added any movies yet.</p>
            <?php else: ?>
                <div class="movie-assign-grid">
                    <?php foreach ($movies as $movie):
                        $dur = intval($movie['duration']);
                        $h = floor($dur / 60);
                        $m = $dur % 60;
                        $dur_fmt = $h > 0 ? $h . 'h ' . $m . 'm' : $m . 'm';
                    ?>
                        <div class="movie-assign-card">
                            <img src="../<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                            <div class="movie-card-body">
                                <h3><?= htmlspecialchars($movie['title']) ?></h3>
                                <p class="movie-meta"><?= htmlspecialchars($movie['genre']) ?> • <?= $dur_fmt ?> • <?= htmlspecialchars($movie['rating'] ?: 'N/A') ?></p>

                                <form method="POST" class="assign-form">
                                    <input type="hidden" name="movie_id" value="<?= $movie['movie_show_id'] ?>" />
                                    <select name="cinema_number_id" required>
                                        <option value="">Select Cinema</option>
                                        <?php foreach ($cinemas as $cinema): ?>
                                            <option value="<?= $cinema['cinema_number_id'] ?>"><?= htmlspecialchars($cinema['cinema_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-assign">Add to Cinema</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
    function toggleLogout() {
        const btn = document.getElementById('logoutBtn');
        btn.style.display = (btn.style.display === 'none' || btn.style.display === '') ? 'block' : 'none';
    }
    </script>
</body>
</html>
