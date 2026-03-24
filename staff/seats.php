<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

$movieTitle = $_GET['movie'] ?? null;
$branchName = $_GET['branch'] ?? null;
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedTime = $_GET['time'] ?? '10:30 AM';

if (!$movieTitle || !$branchName) {
    header("Location: booking.php");
    exit();
}

// Validate date
$today = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = $today;

// Get movie
$movie = null;
$stmt = $conn->prepare("SELECT movie_show_id, title, image_poster, genre, rating, duration, trailer_youtube_id FROM MOVIE WHERE title = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
$stmt->bind_param("s", $movieTitle);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) $movie = $res->fetch_assoc();
$stmt->close();

if (!$movie) { header("Location: booking.php"); exit(); }
$trailerYoutubeId = $movie['trailer_youtube_id'] ?? null;

// Get branch
$branch = null;
$stmt = $conn->prepare("SELECT branch_id, branch_name, branch_location FROM BRANCH WHERE branch_name = ? LIMIT 1");
$stmt->bind_param("s", $branchName);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) $branch = $res->fetch_assoc();
$stmt->close();

if (!$branch) { header("Location: booking.php"); exit(); }
$branchId = $branch['branch_id'];

// Get booked seats for this schedule
$bookedSeats = [];
$timeParts = date_parse($selectedTime);
$timeFormatted = sprintf("%02d:%02d:00", $timeParts['hour'], $timeParts['minute'] ?? 0);

$stmt = $conn->prepare("SELECT schedule_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ? AND branch_id = ? AND show_date = ? AND TIME(show_hour) = TIME(?) LIMIT 1");
$stmt->bind_param("iiss", $movie['movie_show_id'], $branchId, $selectedDate, $timeFormatted);
$stmt->execute();
$schedRes = $stmt->get_result();
$scheduleId = null;
if ($schedRes && $schedRes->num_rows > 0) $scheduleId = $schedRes->fetch_assoc()['schedule_id'];
$stmt->close();

if ($scheduleId) {
    $seatStmt = $conn->prepare("
        SELECT DISTINCT s.seat_number
        FROM RESERVE r
        JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
        JOIN SEAT s ON rs.seat_id = s.seat_id
        WHERE r.schedule_id = ? 
        AND (r.booking_status IS NULL OR r.booking_status IN ('pending','approved'))
    ");
    $seatStmt->bind_param("i", $scheduleId);
    $seatStmt->execute();
    $seatRes = $seatStmt->get_result();
    while ($row = $seatRes->fetch_assoc()) $bookedSeats[] = $row['seat_number'];
    $seatStmt->close();
}

// Count total seats in layout (7 rows x max 18 seats = 98, but A/B only 9 each)
$totalSeats = (2 * 9) + (5 * 18); // = 108 seats
$takenCount = count($bookedSeats);

// Get food items
$foods = [];
$fRes = $conn->query("SELECT food_id, food_name, food_price, image_path FROM FOOD ORDER BY food_id");
if ($fRes) while ($f = $fRes->fetch_assoc()) $foods[] = $f;

$rows = ['A','B','C','D','E','F','G'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Select Seats – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">

  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="staff-main">
    <div class="page-header page-header-row">
      <div>
        <h1>Select Seats & Food</h1>
        <p>Step 2 of 4 — <?= htmlspecialchars($movie['title']) ?> · <?= htmlspecialchars($branchName) ?> · <?= date('M d, Y', strtotime($selectedDate)) ?> · <?= $selectedTime ?></p>
      </div>
      <a href="booking.php" class="btn btn-outline">← Change Details</a>
    </div>

    <!-- STEPS -->
    <div class="steps-bar">
      <div class="step-item done"><span class="step-num">✓</span> Details</div>
      <span class="step-sep">›</span>
      <div class="step-item active"><span class="step-num">2</span> Seats & Food</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">3</span> Checkout</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">4</span> Payment</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

      <!-- SEAT MAP -->
      <div>
        <!-- Seat count from online + staff -->
        <div class="seat-count-bar">
          <span> Current Occupancy (All Bookings)</span>
          <span><span class="seat-count-taken"><?= $takenCount ?></span> <span class="seat-count-total">of <?= $totalSeats ?> seats taken</span></span>
        </div>

        <div class="seat-map-wrapper">
          <div class="screen-bar"></div>
          <p class="screen-label">SCREEN</p>

          <div class="seats-grid">
            <?php foreach ($rows as $row):
              $maxSeat = ($row === 'A' || $row === 'B') ? 9 : 18;
            ?>
            <div class="seat-row">
              <span class="seat-row-label"><?= $row ?></span>
              <?php for ($i = 1; $i <= 18; $i++):
                if (($row === 'A' || $row === 'B') && $i > 9) continue;
                if ($i == 10): ?>
                <div class="seat-gap"></div>
              <?php endif;
                $seatNum = $row . '-' . $i;
                $seatNumAlt = $row . $i;
                $isTaken = in_array($seatNum, $bookedSeats) || in_array($seatNumAlt, $bookedSeats);
                $cssClass = 'seat' . ($isTaken ? ' taken' : '');
              ?>
              <div class="<?= $cssClass ?>"
                   data-seat="<?= $seatNum ?>"
                   title="Seat <?= $seatNum ?>"
                   <?= $isTaken ? '' : 'tabindex="0"' ?>>
                <span class="seat-label"><?= $seatNum ?></span>
              </div>
              <?php endfor; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Legend -->
          <div class="seat-legend">
            <div class="legend-item"><div class="legend-dot available"></div> Available</div>
            <div class="legend-item"><div class="legend-dot selected"></div> Selected</div>
            <div class="legend-item"><div class="legend-dot taken"></div> Taken</div>
          </div>
        </div>

        <!-- Food Selection -->
        <div class="card" style="margin-top:16px;">
          <h2> Add Food & Drinks</h2>
          <div class="food-grid">
            <?php foreach ($foods as $food): ?>
            <div class="food-item" data-food-id="<?= $food['food_id'] ?>" data-food-name="<?= htmlspecialchars($food['food_name']) ?>" data-food-price="<?= $food['food_price'] ?>">
              <img src="../<?= htmlspecialchars($food['image_path'] ?? 'images/default.png') ?>"
                   alt="<?= htmlspecialchars($food['food_name']) ?>"
                   onerror="this.src='../images/default.png'">
              <div class="food-item-name"><?= htmlspecialchars($food['food_name']) ?></div>
              <div class="food-item-price">₱<?= number_format($food['food_price'], 2) ?></div>
              <div class="food-controls">
                <button type="button" class="decrease">−</button>
                <span class="count">0</span>
                <button type="button" class="increase">+</button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- RIGHT: Summary + Proceed -->
      <div style="position:sticky;top:20px;">
        <!-- Movie Info -->
        <div class="card card-sm" style="margin-bottom:16px;text-align:center;">
          <img src="../<?= htmlspecialchars($movie['image_poster'] ?? 'images/default.png') ?>"
               alt="<?= htmlspecialchars($movie['title']) ?>"
               style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-bottom:12px;"
               onerror="this.src='../images/default.png'">
          <div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?= htmlspecialchars($movie['title']) ?></div>
          <div style="font-size:12px;color:var(--text-secondary);"><?= htmlspecialchars($movie['genre'] ?? '') ?> · <?= $movie['duration'] ?? '?' ?> min</div>
        </div>

        <!-- Order Summary -->
        <div class="card card-sm">
          <h3>Order Summary</h3>
          <div id="selectedSeatsSummary" style="font-size:13px;color:var(--text-secondary);margin-bottom:10px;">No seats selected yet.</div>
          <div id="foodSummary"></div>
          <div class="summary-divider"></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px;">
            <span>Seats Subtotal</span><span id="seatSubtotal">₱0.00</span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:12px;font-size:14px;">
            <span>Food Subtotal</span><span id="foodSubtotal">₱0.00</span>
          </div>
          <div class="summary-divider"></div>
          <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:var(--blue);margin:8px 0 16px;">
            <span>Total</span><span id="grandTotal">₱0.00</span>
          </div>
          <button id="proceed-btn" class="btn btn-primary" style="width:100%;" disabled>
            Proceed to Checkout →
          </button>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
const SEAT_PRICE = 350;
let selectedSeats = new Set();
let foodSelections = {};

// Seat click
document.querySelectorAll('.seat:not(.taken)').forEach(seat => {
  seat.addEventListener('click', () => {
    const id = seat.dataset.seat;
    if (seat.classList.contains('selected')) {
      seat.classList.remove('selected');
      selectedSeats.delete(id);
    } else {
      seat.classList.add('selected');
      selectedSeats.add(id);
    }
    updateSummary();
  });
});

// Food controls
document.querySelectorAll('.food-item').forEach(item => {
  const name = item.dataset.foodName;
  const id = item.dataset.foodId;
  const price = parseFloat(item.dataset.foodPrice);
  const countEl = item.querySelector('.count');
  let qty = 0;

  item.querySelector('.increase').addEventListener('click', () => {
    qty++;
    countEl.textContent = qty;
    foodSelections[id] = { name, qty, price, id };
    updateSummary();
  });
  item.querySelector('.decrease').addEventListener('click', () => {
    if (qty > 0) { qty--; countEl.textContent = qty; }
    if (qty === 0) delete foodSelections[id];
    else foodSelections[id] = { name, qty, price, id };
    updateSummary();
  });
});

function updateSummary() {
  const seatArr = [...selectedSeats];
  const seatTotal = seatArr.length * SEAT_PRICE;

  // Seats display
  const seatsEl = document.getElementById('selectedSeatsSummary');
  if (seatArr.length === 0) {
    seatsEl.textContent = 'No seats selected yet.';
  } else {
    seatsEl.innerHTML = seatArr.map(s =>
      `<span class="ticket-seat-chip">${s}</span>`
    ).join('') + `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${seatArr.length} seat(s) × ₱${SEAT_PRICE}</div>`;
  }

  // Food display
  let foodTotal = 0;
  const foodEl = document.getElementById('foodSummary');
  const foodLines = Object.values(foodSelections).filter(f => f.qty > 0);
  if (foodLines.length > 0) {
    foodEl.innerHTML = foodLines.map(f => {
      foodTotal += f.price * f.qty;
      return `<div class="ticket-food-item">${f.name} ×${f.qty} — ₱${(f.price * f.qty).toFixed(2)}</div>`;
    }).join('');
  } else {
    foodEl.innerHTML = '';
  }

  const grandTotal = seatTotal + foodTotal;
  document.getElementById('seatSubtotal').textContent = '₱' + seatTotal.toFixed(2);
  document.getElementById('foodSubtotal').textContent = '₱' + foodTotal.toFixed(2);
  document.getElementById('grandTotal').textContent = '₱' + grandTotal.toFixed(2);

  document.getElementById('proceed-btn').disabled = seatArr.length === 0;

  // Keep the POV trigger button in sync (injected by seat-pov.php)
  const povBtn = document.getElementById('pov-trigger-btn');
  if (povBtn) povBtn.disabled = seatArr.length === 0;
}

document.getElementById('proceed-btn').addEventListener('click', () => {
  if (selectedSeats.size === 0) return;

  const seatArr = [...selectedSeats];
  const seatTotal = seatArr.length * SEAT_PRICE;
  let foodTotal = 0;
  const foodData = Object.values(foodSelections).filter(f => f.qty > 0).map(f => {
    foodTotal += f.price * f.qty;
    return { id: f.id, name: f.name, quantity: f.qty, price: f.price, subtotal: f.price * f.qty };
  });
  const grandTotal = seatTotal + foodTotal;

  const bookingData = {
    movie: '<?= addslashes(urlencode($movieTitle)) ?>',
    branch: '<?= addslashes(urlencode($branchName)) ?>',
    date: '<?= $selectedDate ?>',
    time: '<?= addslashes($selectedTime) ?>',
    seats: seatArr,
    seatsData: seatArr.map(s => ({ id: s, tier: 'Standard', price: SEAT_PRICE })),
    food: foodData,
    foodTotal: foodTotal
  };

  // Store in session via hidden form
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'checkout.php';

  [
    ['booking_data', JSON.stringify(bookingData)],
    ['seat_total', seatTotal],
    ['food_total', foodTotal],
    ['grand_total', grandTotal]
  ].forEach(([name, value]) => {
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = name;
    inp.value = value;
    form.appendChild(inp);
  });

  document.body.appendChild(form);
  form.submit();
});
</script>
<?php include __DIR__ . '/../seat-pov.php'; ?>
</body>
</html>
