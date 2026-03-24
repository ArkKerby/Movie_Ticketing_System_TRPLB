<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

$today = date('Y-m-d');

// Get all branches with today's schedule occupancy
$branches = [];
$bRes = $conn->query("SELECT branch_id, branch_name, branch_location, contact_number FROM BRANCH ORDER BY branch_name");
if ($bRes) {
    while ($b = $bRes->fetch_assoc()) {
        // Get today's schedules for this branch
        $sRes = $conn->prepare("
            SELECT ms.schedule_id, ms.show_hour, m.title,
                   (SELECT COUNT(DISTINCT rs.reserve_seat_id)
                    FROM RESERVE r
                    JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
                    WHERE r.schedule_id = ms.schedule_id
                    AND (r.booking_status IS NULL OR r.booking_status IN ('pending','approved'))
                   ) as seats_taken
            FROM MOVIE_SCHEDULE ms
            JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
            WHERE ms.branch_id = ? AND ms.show_date = ?
            ORDER BY ms.show_hour ASC
        ");
        $sRes->bind_param("is", $b['branch_id'], $today);
        $sRes->execute();
        $scheds = $sRes->get_result();
        $b['schedules'] = [];
        $b['total_taken'] = 0;
        while ($s = $scheds->fetch_assoc()) {
            $b['schedules'][] = $s;
            $b['total_taken'] += $s['seats_taken'];
        }
        $sRes->close();
        $branches[] = $b;
    }
}

// Total seats in cinema (layout: 7 rows, A/B=9, C-G=18)
$totalSeatsPerCinema = (2*9) + (5*18); // 108
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Locations & Seats – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="staff-main">
    <div class="page-header page-header-row">
      <div>
        <h1>Locations & Seat Occupancy</h1>
        <p>Today's schedule — <?= date('F d, Y') ?> · Synced with online system</p>
      </div>
      <a href="booking.php" class="btn btn-primary"> New Walk-In Booking</a>
    </div>

    <?php if (empty($branches)): ?>
    <div class="alert alert-info">No branches configured. Please add branches in the admin panel.</div>
    <?php else: ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;">
      <?php foreach ($branches as $b): 
        $schedCount = count($b['schedules']);
        $taken = $b['total_taken'];
        $total = $totalSeatsPerCinema * max(1, $schedCount);
        $pct   = $total > 0 ? min(100, round(($taken / $total) * 100)) : 0;
        $fillClass = $pct >= 80 ? 'high' : ($pct >= 50 ? 'mid' : '');
      ?>
      <div class="location-card">
        <div class="location-name"> <?= htmlspecialchars($b['branch_name']) ?></div>
        <div class="location-address"><?= htmlspecialchars($b['branch_location'] ?? '') ?></div>

        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
          <span style="color:var(--text-secondary);">Total Seats Taken Today</span>
          <span style="font-weight:700;color:<?= $pct >= 80 ? 'var(--danger)' : ($pct >= 50 ? 'var(--warning)' : 'var(--blue)') ?>"><?= $taken ?> of <?= $total ?> (<?= $pct ?>%)</span>
        </div>
        <div class="occupancy-bar">
          <div class="occupancy-fill <?= $fillClass ?>" style="width:<?= $pct ?>%;"></div>
        </div>

        <?php if (empty($b['schedules'])): ?>
        <div style="font-size:13px;color:var(--text-muted);padding:12px 0;text-align:center;">No shows scheduled today</div>
        <?php else: ?>
        <div style="margin-top:12px;">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Today's Shows</div>
          <?php foreach ($b['schedules'] as $s):
            $sPct = $totalSeatsPerCinema > 0 ? min(100, round(($s['seats_taken'] / $totalSeatsPerCinema) * 100)) : 0;
          ?>
          <div class="show-row">
            <span class="show-time"><?= date('g:i A', strtotime($s['show_hour'])) ?></span>
            <span style="font-size:12px;color:var(--text-secondary);flex:1;padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($s['title']) ?></span>
            <span class="show-seats" style="color:<?= $sPct >= 80 ? 'var(--danger)' : ($sPct >= 50 ? 'var(--warning)' : 'var(--text-secondary)') ?>;">
              <?= $s['seats_taken'] ?>/<?= $totalSeatsPerCinema ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($b['contact_number']): ?>
        <div style="font-size:12px;color:var(--text-muted);margin-top:10px;"> <?= htmlspecialchars($b['contact_number']) ?></div>
        <?php endif; ?>

        <div style="margin-top:14px;">
          <a href="booking.php?branch=<?= urlencode($b['branch_name']) ?>" class="btn btn-outline btn-sm">Book Walk-In Here →</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>
  </main>
</div>
</body>
</html>
