<?php
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config.php';
$conn = getDBConnection();

$acc_id = $_SESSION['acc_id'];
$user_name = $_SESSION['user_name'] ?? 'Mall Admin';

// Single branch — TICKETIX
$branch_id = 1;
$branch_name = 'TICKETIX';

// Get cinema types for TICKETIX
$cinemaStmt = $conn->prepare("
    SELECT cn.cinema_number_id, cn.cinema_name, cn.capacity
    FROM CINEMA_NUMBER cn
    WHERE cn.branch_id = ?
    ORDER BY FIELD(cn.cinema_name, 'IMAX', 'Director''s Club', 'Regular'), cn.cinema_name
");
$cinemaStmt->bind_param("i", $branch_id);
$cinemaStmt->execute();
$cinemaResult = $cinemaStmt->get_result();
$cinemas = [];
while ($row = $cinemaResult->fetch_assoc()) {
    $cinemas[] = $row;
}
$cinemaStmt->close();

// Get all assignments for cinemas
$assignments = [];
if (!empty($cinemas)) {
    $cinemaIds = array_column($cinemas, 'cinema_number_id');
    $placeholders = implode(',', array_fill(0, count($cinemaIds), '?'));
    $types = str_repeat('i', count($cinemaIds));

    $assignStmt = $conn->prepare("
        SELECT cma.assignment_id, cma.cinema_number_id, cma.assigned_at,
               m.movie_show_id, m.title, m.genre, m.duration, m.rating, m.image_poster
        FROM CINEMA_MOVIE_ASSIGNMENT cma
        JOIN MOVIE m ON cma.movie_show_id = m.movie_show_id
        WHERE cma.cinema_number_id IN ($placeholders)
        AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
        ORDER BY cma.assigned_at DESC
    ");
    $assignStmt->bind_param($types, ...$cinemaIds);
    $assignStmt->execute();
    $assignResult = $assignStmt->get_result();
    while ($row = $assignResult->fetch_assoc()) {
        $assignments[$row['cinema_number_id']][] = $row;
    }
    $assignStmt->close();
}

// Stats
$totalCinemas = count($cinemas);
$totalAssignments = array_sum(array_map('count', $assignments));
$uniqueMovies = [];
foreach ($assignments as $cinemaAssignments) {
    foreach ($cinemaAssignments as $a) {
        $uniqueMovies[$a['movie_show_id']] = true;
    }
}
$totalUniqueMovies = count($uniqueMovies);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Mall Admin - TICKETIX</title>
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
            <a href="assign-movie.php">Assign Movies</a>
            <a href="../admin-pwd-applications.php">PWD Applications</a>
            <a href="../admin-senior-applications.php">Senior Applications</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php" class="logout-btn" id="logoutBtn" style="display: none;">➜ Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>Mall Admin <span class="highlight">Dashboard</span></h1>
        </header>

        <section class="stats-cards">
            <div class="card">
                <div class="card-info">
                    <label>Cinema Screens</label>
                    <h3><?= $totalCinemas ?></h3>
                </div>
            </div>
            <div class="card">
                <div class="card-info">
                    <label>Movies Assigned</label>
                    <h3><?= $totalUniqueMovies ?></h3>
                </div>
            </div>
            <div class="card">
                <div class="card-info">
                    <label>Total Assignments</label>
                    <h3><?= $totalAssignments ?></h3>
                </div>
            </div>
        </section>

        <section class="cinema-section">
            <h2>Cinema Screens</h2>

            <div class="cinema-grid">
                <?php foreach ($cinemas as $cinema): ?>
                    <div class="cinema-card">
                        <div class="cinema-card-header">
                            <h3><?= htmlspecialchars($cinema['cinema_name']) ?></h3>
                            <span class="capacity-badge"><?= $cinema['capacity'] ?> seats</span>
                        </div>

                        <?php if (!empty($assignments[$cinema['cinema_number_id']])): ?>
                            <?php foreach ($assignments[$cinema['cinema_number_id']] as $movie): ?>
                                <div class="assigned-movie">
                                    <img src="../<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                                    <div class="assigned-movie-info">
                                        <h4><?= htmlspecialchars($movie['title']) ?></h4>
                                        <p><?= htmlspecialchars($movie['genre']) ?> • <?= $movie['duration'] ?>min • <?= htmlspecialchars($movie['rating']) ?></p>
                                    </div>
                                    <button class="btn-remove" data-id="<?= $movie['assignment_id'] ?>">
                                        Delete
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-movies-assigned">No movies assigned yet</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <script>
    function toggleLogout() {
        const btn = document.getElementById('logoutBtn');
        btn.style.display = (btn.style.display === 'none' || btn.style.display === '') ? 'block' : 'none';
    }

    document.querySelectorAll('.btn-remove').forEach(button => {
        button.addEventListener('click', function() {
            const assignmentId = this.getAttribute('data-id');
            if (!confirm('Are you sure you want to permanently remove this movie from this cinema? This action cannot be undone.')) return;

            fetch('remove-assignment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `assignment_id=${assignmentId}`
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
