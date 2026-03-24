<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

// === STATS ===
$today = date('Y-m-d');

// Total bookings today
$totalBookings = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM RESERVE WHERE DATE(reserve_date) = '$today'");
if ($r) $totalBookings = $r->fetch_assoc()['cnt'];

// Today's revenue
$todayRevenue = 0;
$r = $conn->query("SELECT SUM(p.amount_paid) as rev FROM PAYMENT p JOIN RESERVE r ON p.reserve_id = r.reservation_id WHERE DATE(r.reserve_date) = '$today' AND p.payment_status = 'paid'");
if ($r) { $row = $r->fetch_assoc(); $todayRevenue = $row['rev'] ?? 0; }

// Total seats taken today
$seatsTaken = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM RESERVE_SEAT rs JOIN RESERVE r ON rs.reservation_id = r.reservation_id WHERE DATE(r.reserve_date) = '$today'");
if ($r) $seatsTaken = $r->fetch_assoc()['cnt'];

// Active movies
$activeMovies = 0;
$r = $conn->query("SELECT COUNT(*) as cnt FROM MOVIE WHERE now_showing = 1 AND (is_deleted = 0 OR is_deleted IS NULL)");
if ($r) $activeMovies = $r->fetch_assoc()['cnt'];

// Recent walk-in bookings (last 10)
$recentBookings = [];
$q = "SELECT r.reservation_id, r.reserve_date, r.ticket_amount, r.sum_price, r.food_total,
             m.title, ms.show_hour, b.branch_name,
             p.payment_type, p.payment_status, t.ticket_number
      FROM RESERVE r
      JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
      JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
      LEFT JOIN BRANCH b ON ms.branch_id = b.branch_id
      LEFT JOIN PAYMENT p ON p.reserve_id = r.reservation_id
      LEFT JOIN TICKET t ON t.reserve_id = r.reservation_id
      ORDER BY r.reserve_date DESC
      LIMIT 10";
$res = $conn->query($q);
if ($res) while ($row = $res->fetch_assoc()) $recentBookings[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Dashboard – Ticketix</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">

  <!-- SIDEBAR -->
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <!-- MAIN -->
  <main class="staff-main">
    <div class="page-header page-header-row">
      <div>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= staffName() ?>! Here's what's happening today.</p>
      </div>
      <a href="booking.php" class="btn btn-primary btn-lg">＋ New Walk-In Booking</a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div>
          <div class="stat-value"><?= number_format($totalBookings) ?></div>
          <div class="stat-label">Bookings Today</div>
        </div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-value">₱<?= number_format($todayRevenue, 2) ?></div>
          <div class="stat-label">Revenue Today</div>
        </div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-value"><?= number_format($seatsTaken) ?></div>
          <div class="stat-label">Seats Taken Today</div>
        </div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-value"><?= number_format($activeMovies) ?></div>
          <div class="stat-label">Movies Showing</div>
        </div>
      </div>
    </div>

    <!-- RECENT BOOKINGS -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h2 style="margin:0;">Recent Bookings</h2>
        <a href="locations.php" class="btn btn-outline btn-sm">View Locations →</a>
      </div>

      <?php if (empty($recentBookings)): ?>
        <div class="alert alert-info">No bookings yet today. <a href="booking.php" style="color:inherit;font-weight:700;">Create the first walk-in booking →</a></div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Ticket #</th>
              <th>Movie</th>
              <th>Branch</th>
              <th>Showtime</th>
              <th>Seats</th>
              <th>Total</th>
              <th>Payment</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentBookings as $b): ?>
            <tr>
              <td style="font-size:12px;font-weight:600;color:var(--blue);"><?= htmlspecialchars($b['ticket_number'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($b['title']) ?></td>
              <td><?= htmlspecialchars($b['branch_name'] ?? 'N/A') ?></td>
              <td><?= date('g:i A', strtotime($b['show_hour'])) ?></td>
              <td><?= $b['ticket_amount'] ?></td>
              <td>₱<?= number_format(($b['sum_price'] ?? 0) + ($b['food_total'] ?? 0), 2) ?></td>
              <td style="text-transform:capitalize;"><?= htmlspecialchars($b['payment_type'] ?? 'N/A') ?></td>
              <td>
                <?php $ps = $b['payment_status'] ?? 'pending'; ?>
                <span class="status-badge status-<?= $ps ?>"><?= ucfirst($ps) ?></span>
              </td>
              <td>
                <?php
                  // Get ticket_id for this reservation
                  $tid_r = $conn->query("SELECT ticket_id FROM TICKET WHERE reserve_id = " . (int)$b['reservation_id'] . " LIMIT 1");
                  $tid = $tid_r ? $tid_r->fetch_assoc()['ticket_id'] ?? null : null;
                ?>
                <?php if ($tid): ?>
                <a href="receipt.php?ticket_id=<?= $tid ?>" class="btn btn-outline btn-sm">Receipt</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
