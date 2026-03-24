<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

// Fetch now-showing movies
$movies = [];
$mRes = $conn->query("SELECT movie_show_id, title, image_poster, genre, rating, duration FROM MOVIE WHERE now_showing = 1 AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY title");
if ($mRes) while ($m = $mRes->fetch_assoc()) $movies[] = $m;

// Fetch branches
$branches = [];
$bRes = $conn->query("SELECT branch_id, branch_name, branch_location FROM BRANCH ORDER BY branch_name");
if ($bRes) while ($b = $bRes->fetch_assoc()) $branches[] = $b;

$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));

$timings = ["10:30 AM","12:30 PM","3:00 PM","05:30 PM","06:30 PM","08:30 PM","9:30 PM","10:30 PM"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Booking – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">

  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="staff-main">
    <div class="page-header">
      <h1>New Walk-In Booking</h1>
      <p>Step 1 of 4 — Select Movie, Branch, Date & Time</p>
    </div>

    <!-- STEPS -->
    <div class="steps-bar">
      <div class="step-item active"><span class="step-num">1</span> Details</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">2</span> Seats & Food</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">3</span> Checkout</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">4</span> Payment</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;">

      <!-- LEFT: Form -->
      <div class="card">
        <h2>Booking Details</h2>

        <?php if (empty($movies)): ?>
        <div class="alert alert-info">No movies are currently showing. Please add movies in the admin panel.</div>
        <?php else: ?>

        <form id="bookingForm" action="seats.php" method="GET">

          <div class="form-group">
            <label for="movie">Movie</label>
            <select name="movie" id="movie" class="form-control" required>
              <option value="">— Select a movie —</option>
              <?php foreach ($movies as $m): ?>
              <option value="<?= htmlspecialchars($m['title']) ?>"><?= htmlspecialchars($m['title']) ?>
                (<?= htmlspecialchars($m['rating'] ?? 'N/A') ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="branch">Branch / Location</label>
            <select name="branch" id="branch" class="form-control" required>
              <option value="">— Select a branch —</option>
              <?php foreach ($branches as $b): ?>
              <option value="<?= htmlspecialchars($b['branch_name']) ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="date">Show Date</label>
              <input type="date" name="date" id="date" class="form-control"
                     value="<?= $today ?>" min="<?= $today ?>" max="<?= $maxDate ?>" required>
            </div>
            <div class="form-group">
              <label for="time">Show Time</label>
              <select name="time" id="time" class="form-control" required>
                <?php foreach ($timings as $t): ?>
                <option value="<?= $t ?>"><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:8px;">
            Continue to Seat Selection →
          </button>
        </form>

        <?php endif; ?>
      </div>

      <!-- RIGHT: Movie Preview -->
      <div>
        <div class="card" id="moviePreview" style="text-align:center;min-height:300px;">
          <div id="moviePlaceholder" style="padding:40px 0;color:var(--text-muted);">
            <div style="font-size:48px;margin-bottom:12px;">🎬</div>
            <p>Select a movie to preview</p>
          </div>
          <div id="movieInfo" style="display:none;">
            <img id="moviePoster" src="" alt="" style="width:100%;max-height:280px;object-fit:cover;border-radius:8px;margin-bottom:12px;">
            <div id="movieTitle" style="font-size:16px;font-weight:700;margin-bottom:6px;"></div>
            <div id="movieMeta" style="font-size:13px;color:var(--text-secondary);"></div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
// Movie data for preview
const movieData = <?php echo json_encode(array_column($movies, null, 'title')); ?>;

document.getElementById('movie').addEventListener('change', function() {
  const title = this.value;
  const info = movieData[title];
  if (info) {
    document.getElementById('moviePlaceholder').style.display = 'none';
    document.getElementById('movieInfo').style.display = 'block';
    document.getElementById('moviePoster').src = info.image_poster || '../images/default.png';
    document.getElementById('moviePoster').onerror = function() { this.src='../images/default.png'; };
    document.getElementById('movieTitle').textContent = info.title;
    document.getElementById('movieMeta').textContent =
      (info.genre || '') + (info.duration ? ' • ' + info.duration + ' min' : '') + (info.rating ? ' • ' + info.rating : '');
  } else {
    document.getElementById('moviePlaceholder').style.display = 'block';
    document.getElementById('movieInfo').style.display = 'none';
  }
});
</script>
</body>
</html>
