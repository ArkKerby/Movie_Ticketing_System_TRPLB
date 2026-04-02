<?php
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Single branch — TICKETIX (branch_id = 1)
$branch_id = 1;

// Get cinema numbers
$cinemaStmt = $conn->prepare("SELECT cinema_number_id, cinema_name, capacity, price FROM CINEMA_NUMBER WHERE branch_id = ? ORDER BY FIELD(cinema_name, 'IMAX', 'Director''s Club', 'Regular'), cinema_name");
$cinemaStmt->bind_param('i', $branch_id);
$cinemaStmt->execute();
$cinemaResult = $cinemaStmt->get_result();

$cinemaMovies = [];
$cinemaIds = [];
while ($cinema = $cinemaResult->fetch_assoc()) {
    $cinemaMovies[$cinema['cinema_number_id']] = [
        'cinema_name' => $cinema['cinema_name'],
        'capacity' => $cinema['capacity'],
        'price' => $cinema['price'] ?? 350,
        'movies' => []
    ];
    $cinemaIds[] = $cinema['cinema_number_id'];
}
$cinemaStmt->close();

// Fetch assigned movies for each cinema
if (!empty($cinemaIds)) {
    $placeholders = implode(',', array_fill(0, count($cinemaIds), '?'));
    $types = str_repeat('i', count($cinemaIds));

    $assignStmt = $conn->prepare("
        SELECT cma.cinema_number_id, m.title, m.genre, m.duration, m.rating, m.image_poster, m.movie_show_id
        FROM CINEMA_MOVIE_ASSIGNMENT cma
        JOIN MOVIE m ON cma.movie_show_id = m.movie_show_id
        WHERE cma.cinema_number_id IN ($placeholders)
        AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
        ORDER BY m.title ASC
    ");
    $assignStmt->bind_param($types, ...$cinemaIds);
    $assignStmt->execute();
    $assignResult = $assignStmt->get_result();

    while ($row = $assignResult->fetch_assoc()) {
        $cinemaMovies[$row['cinema_number_id']]['movies'][] = $row;
    }
    $assignStmt->close();
}

$hasCinemaAssignments = false;
foreach ($cinemaMovies as $cinema) {
    if (!empty($cinema['movies'])) {
        $hasCinemaAssignments = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Now Showing - TICKETIX</title>
    <link rel="stylesheet" href="css/select-movie-branch.css?v=<?php echo time(); ?>">
    <link rel="icon" type="image/png" href="images/brand x.png">
    <style>
        .cinema-block {
            margin-bottom: 28px;
            padding: 18px 22px;
            background: rgba(0, 180, 216, 0.08);
            border: 1px solid rgba(0, 180, 216, 0.25);
            border-radius: 12px;
        }
        .cinema-block h3 {
            margin: 0 0 6px 0;
            color: #00b4d8;
            font-size: 1.2em;
            font-weight: 700;
        }
        .cinema-block .cinema-capacity {
            font-size: 0.8em;
            color: rgba(255,255,255,0.5);
            margin-bottom: 14px;
        }
        .cinema-movie-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .cinema-movie-list li {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px;
            margin-bottom: 8px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.06);
            transition: all 0.2s ease;
        }
        .cinema-movie-list li:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(0,180,216,0.3);
        }
        .cinema-movie-list li img {
            width: 50px;
            height: 72px;
            object-fit: cover;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .cinema-movie-list .movie-info-text {
            flex: 1;
        }
        .cinema-movie-list .movie-info-text .movie-title {
            font-weight: 600;
            font-size: 1em;
            color: #fff;
            display: block;
            margin-bottom: 3px;
        }
        .cinema-movie-list .movie-info-text .movie-meta {
            font-size: 0.8em;
            color: rgba(255,255,255,0.5);
        }
        .cinema-movie-list .select-btn {
            background: linear-gradient(135deg, #00b4d8, #0077b6);
            color: #fff;
            padding: 8px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85em;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .cinema-movie-list .select-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,180,216,0.4);
            text-decoration: none;
        }
        .no-movies-msg {
            color: rgba(255,255,255,0.4);
            font-style: italic;
            padding: 8px 0;
            font-size: 0.9em;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 18px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 0.9em;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container">
    <a class="back-link" href="TICKETIX NI CLAIRE.php">← Back to Homepage</a>
    <h2>TICKETIX <strong>Cinema</strong></h2>

    <?php foreach ($cinemaMovies as $cinemaId => $cinema): ?>
        <div class="cinema-block">
            <h3><?= htmlspecialchars($cinema['cinema_name']) ?></h3>
            <div class="cinema-capacity"><?= $cinema['capacity'] ?> seats • <strong style="color:#00b4d8;">₱<?= number_format($cinema['price'], 0) ?></strong>/ticket</div>

            <?php if (!empty($cinema['movies'])): ?>
                <ul class="cinema-movie-list">
                    <?php foreach ($cinema['movies'] as $movie):
                        $dur = intval($movie['duration']);
                        $h = floor($dur / 60);
                        $m = $dur % 60;
                        $dur_fmt = $h > 0 ? $h . 'h ' . $m . 'm' : $m . 'm';
                    ?>
                        <li>
                            <img src="<?= htmlspecialchars($movie['image_poster'] ?: 'images/default.png') ?>" alt="<?= htmlspecialchars($movie['title']) ?>">
                            <div class="movie-info-text">
                                <span class="movie-title"><?= htmlspecialchars($movie['title']) ?></span>
                                <span class="movie-meta"><?= htmlspecialchars($movie['genre']) ?> • <?= $dur_fmt ?> • <?= htmlspecialchars($movie['rating'] ?: 'N/A') ?></span>
                            </div>
                            <a class="select-btn" href="seat-reservation.php?branch=TICKETIX&movie=<?= urlencode($movie['title']) ?>&cinema_id=<?= $cinemaId ?>">Select</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="no-movies-msg">No movies assigned to this cinema yet.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$hasCinemaAssignments): ?>
        <p class="no-movies-msg">No movies are currently showing. Check back soon!</p>
    <?php endif; ?>
</div>
</body>
</html>