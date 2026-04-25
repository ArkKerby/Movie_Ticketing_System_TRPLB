<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

// Set Philippine timezone for all time operations
date_default_timezone_set('Asia/Manila');

// Fetch now-showing movies
$movies = [];
$mRes = $conn->query("SELECT movie_show_id, title, image_poster, genre, rating, duration FROM MOVIE WHERE now_showing = 1 AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY title");
if ($mRes) while ($m = $mRes->fetch_assoc()) $movies[] = $m;

// Fetch branches
$branches = [];
$bRes = $conn->query("SELECT branch_id, branch_name, branch_location FROM BRANCH ORDER BY branch_name");
if ($bRes) while ($b = $bRes->fetch_assoc()) $branches[] = $b;

// Fetch cinema types (for the selected branch — default to branch_id 1)
$cinemas = [];
$cRes = $conn->query("SELECT cinema_number_id, cinema_name, capacity, price FROM CINEMA_NUMBER WHERE branch_id = 1 ORDER BY FIELD(cinema_name, 'IMAX', 'Director''s Club', 'Regular'), cinema_name");
if ($cRes) while ($c = $cRes->fetch_assoc()) $cinemas[] = $c;

$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));

// All possible timings
$allTimings = ["10:30 AM","12:30 PM","3:00 PM","05:30 PM","06:30 PM","08:30 PM","9:30 PM","10:30 PM"];

// Filter past timings if selected date is today
$selectedDate = $_GET['date'] ?? $today;
$now = new DateTime('now', new DateTimeZone('Asia/Manila'));
$currentTime = $now->format('H:i');

$timings = [];
foreach ($allTimings as $t) {
    $tp = date_parse($t);
    $timeStr = sprintf("%02d:%02d", $tp['hour'], $tp['minute']);
    // If today, only show times that are at least 20 minutes in the future
    if ($selectedDate === $today) {
        $showDT = new DateTime($today . ' ' . $timeStr, new DateTimeZone('Asia/Manila'));
        $diff = $showDT->getTimestamp() - $now->getTimestamp();
        if ($diff < 1200) { // less than 20 minutes
            continue; // skip this time
        }
    }
    $timings[] = $t;
}

// If no timings available for today, show message
$noTimingsToday = ($selectedDate === $today && empty($timings));
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
      <p>Step 1 of 4 — Select Movie, Cinema Type, Date & Time</p>
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

    <!-- Philippine Time Clock -->
    <div style="background:rgba(85,138,206,0.08); border:1px solid rgba(85,138,206,0.2); border-radius:10px; padding:10px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px;">
      <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Philippine Time</div>
      <div id="ph-clock" style="font-size:1.1rem; font-weight:700; color:var(--blue); font-family:'Courier New',monospace;">--:-- --</div>
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

          <div class="form-group">
            <label for="cinema_id">Cinema Type</label>
            <select name="cinema_id" id="cinema_id" class="form-control" required>
              <option value="">— Select cinema type —</option>
              <?php foreach ($cinemas as $c): ?>
              <option value="<?= $c['cinema_number_id'] ?>" data-price="<?= $c['price'] ?>">
                <?= htmlspecialchars($c['cinema_name']) ?> — ₱<?= number_format($c['price'], 0) ?>/seat (<?= $c['capacity'] ?> seats)
              </option>
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
                <?php if ($noTimingsToday): ?>
                  <option value="">No available times today</option>
                <?php else: ?>
                  <?php foreach ($timings as $t): ?>
                  <option value="<?= $t ?>"><?= $t ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
          </div>

          <?php if ($noTimingsToday): ?>
          <div class="alert alert-info" style="margin-top:8px;">
            All showtimes for today have passed. Please select a future date.
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:8px;" <?= $noTimingsToday ? 'disabled' : '' ?>>
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
        <!-- Cinema Info -->
        <div class="card" id="cinemaPreview" style="margin-top:12px;display:none;">
          <div style="text-align:center;">
            <div id="cinemaTypeName" style="font-size:16px;font-weight:700;color:var(--blue);"></div>
            <div id="cinemaTypePrice" style="font-size:14px;color:var(--text-secondary);margin-top:4px;"></div>
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

// Cinema type preview
document.getElementById('cinema_id').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const preview = document.getElementById('cinemaPreview');
  if (this.value) {
    preview.style.display = 'block';
    document.getElementById('cinemaTypeName').textContent = opt.textContent.split('—')[0].trim();
    document.getElementById('cinemaTypePrice').textContent = '₱' + (opt.dataset.price || '350') + ' per seat';
  } else {
    preview.style.display = 'none';
  }
});

// Date change → refresh to re-filter timings
document.getElementById('date').addEventListener('change', function() {
  const url = new URL(window.location.href);
  url.searchParams.set('date', this.value);
  window.location.href = url.toString();
});

// Philippine time clock
(function() {
  const el = document.getElementById('ph-clock');
  const fmt = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
  });
  function tick() { if (el) el.textContent = fmt.format(new Date()); }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
