<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

$ticketId = intval($_GET['ticket_id'] ?? 0);
if (!$ticketId) { header("Location: dashboard.php"); exit(); }

// Fetch all ticket data
$q = "SELECT t.ticket_id, t.ticket_number, t.e_ticket_code, t.ticket_status, t.date_issued,
             t.payment_type, t.amount_paid, t.reference_number,
             r.reservation_id, r.ticket_amount, r.sum_price, r.food_total, r.booking_type,
             m.title, m.image_poster, m.rating, m.duration,
             ms.show_date, ms.show_hour,
             b.branch_name, b.branch_location
      FROM TICKET t
      JOIN RESERVE r ON t.reserve_id = r.reservation_id
      JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
      JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
      LEFT JOIN BRANCH b ON ms.branch_id = b.branch_id
      WHERE t.ticket_id = ?";
$stmt = $conn->prepare($q);
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) { header("Location: dashboard.php"); exit(); }

// Get seats
$seats = [];
$stmt = $conn->prepare("SELECT rs.seat_number, 'Regular' as seat_type FROM RESERVE_SEAT rs WHERE rs.reservation_id = ?");
$stmt->bind_param("i", $ticket['reservation_id']);
$stmt->execute();
$sr = $stmt->get_result();
while ($row = $sr->fetch_assoc()) $seats[] = $row;
$stmt->close();

// Get food
$foodItems = [];
$stmt = $conn->prepare("SELECT f.food_name, tf.quantity, f.food_price FROM TICKET_FOOD tf JOIN FOOD f ON tf.food_id = f.food_id WHERE tf.ticket_id = ?");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$fr = $stmt->get_result();
while ($row = $fr->fetch_assoc()) $foodItems[] = $row;
$stmt->close();

$qrData    = $ticket['e_ticket_code'] ?: $ticket['ticket_number'];
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($qrData);
$showTime  = date('g:i A', strtotime($ticket['show_hour']));
$showDate  = date('F d, Y', strtotime($ticket['show_date']));
$issued    = date('M d, Y g:i A', strtotime($ticket['date_issued']));
$grandTotal = ($ticket['sum_price'] ?? 0) + ($ticket['food_total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Print Ticket – <?= htmlspecialchars($ticket['ticket_number']) ?></title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap');

    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      font-family: 'Montserrat', sans-serif;
      background: #f0f4f8;
      display: flex; flex-direction: column;
      align-items: center; padding: 20px;
      min-height: 100vh;
    }

    /* Print controls (hidden when printing) */
    .print-controls {
      display: flex; gap: 12px; margin-bottom: 24px;
      flex-wrap: wrap; justify-content: center;
    }
    .btn-print {
      background: #558ace; color: #fff;
      border: none; border-radius: 8px;
      padding: 12px 28px; font-family: 'Montserrat', sans-serif;
      font-size: 15px; font-weight: 700; cursor: pointer;
      transition: background 0.2s;
    }
    .btn-print:hover { background: #3a6aaa; }
    .btn-back {
      background: transparent; color: #558ace;
      border: 2px solid #558ace; border-radius: 8px;
      padding: 12px 24px; font-family: 'Montserrat', sans-serif;
      font-size: 14px; font-weight: 600; cursor: pointer;
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }

    /* TICKET */
    .ticket-wrapper {
      width: 100%; max-width: 560px;
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.12);
      overflow: hidden;
      border: 1px solid #e0e8f0;
    }

    /* Header stripe */
    .ticket-top {
      background: linear-gradient(135deg, #1a2a4a, #2d4a7a);
      padding: 24px 28px;
      display: flex; align-items: center; justify-content: space-between;
      color: #fff;
    }
    .ticket-logo { display: flex; align-items: center; gap: 10px; }
    .ticket-logo img { height: 36px; filter: brightness(0) invert(1); }
    .ticket-logo-text { font-size: 11px; font-weight: 600; letter-spacing: 1px; opacity: 0.8; text-transform:uppercase; }
    .ticket-num { font-size: 11px; font-weight: 700; opacity: 0.7; letter-spacing: 0.5px; }
    .ticket-num span { font-size: 14px; font-weight: 800; opacity: 1; display:block; color: #89bbf3; }

    /* Movie Banner */
    .movie-banner {
      background: #f8fafc;
      border-bottom: 2px dashed #d0dce8;
      padding: 20px 28px;
      display: flex; gap: 16px; align-items: flex-start;
    }
    .movie-poster-small {
      width: 72px; height: 100px; object-fit: cover;
      border-radius: 6px; border: 2px solid #e0e8f0;
      flex-shrink: 0;
    }
    .movie-title { font-size: 18px; font-weight: 800; color: #1a2a4a; margin-bottom: 4px; }
    .movie-meta { font-size: 12px; color: #6b7c93; }

    /* Details section */
    .ticket-body { padding: 20px 28px; }
    .detail-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
      margin-bottom: 16px;
    }
    .detail-block { }
    .detail-label { font-size: 10px; font-weight: 700; color: #6b7c93; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 3px; }
    .detail-value { font-size: 14px; font-weight: 700; color: #1a2a4a; }
    .detail-value.large { font-size: 16px; }

    /* Seats */
    .seats-section { margin: 14px 0; padding-top: 14px; border-top: 1px dashed #d0dce8; }
    .seats-label { font-size: 10px; font-weight: 700; color: #6b7c93; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 8px; }
    .seat-chip {
      display: inline-block; background: #eef4ff;
      border: 1px solid #558ace; border-radius: 4px;
      padding: 3px 10px; font-size: 12px; font-weight: 700; color: #2d4a7a;
      margin: 2px;
    }

    /* Food */
    .food-section { padding-top: 12px; border-top: 1px dashed #d0dce8; margin-top:12px; }
    .food-row { display: flex; justify-content: space-between; font-size: 13px; padding: 3px 0; color: #3a4a5a; }

    /* Totals */
    .totals-section { padding-top: 14px; border-top: 2px dashed #d0dce8; margin-top: 14px; }
    .total-row { display: flex; justify-content: space-between; font-size: 13px; color: #6b7c93; margin-bottom: 4px; }
    .grand-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: 800; color: #1a2a4a; margin-top: 6px; }

    /* Divider perforation */
    .perforation {
      border-top: 2px dashed #d0dce8;
      margin: 0 28px;
      position: relative;
    }
    .perforation::before,
    .perforation::after {
      content: '';
      position: absolute; top: -10px;
      width: 20px; height: 20px;
      border-radius: 50%; background: #f0f4f8;
    }
    .perforation::before { left: -36px; }
    .perforation::after { right: -36px; }

    /* QR Section (stub) */
    .ticket-stub {
      padding: 20px 28px;
      display: flex; align-items: center; justify-content: space-between; gap: 16px;
      background: #f8fafc;
    }
    .stub-left { flex: 1; }
    .stub-venue { font-size: 11px; font-weight: 700; color: #6b7c93; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
    .stub-value { font-size: 13px; font-weight: 600; color: #1a2a4a; }
    .qr-block { text-align: center; }
    .qr-block img { width: 110px; height: 110px; border: 2px solid #d0dce8; border-radius: 6px; display:block; }
    .qr-code-text { font-size: 9px; color: #6b7c93; margin-top: 4px; word-break: break-all; max-width: 110px; }

    /* Payment info */
    .payment-info {
      background: #eef4ff; border-radius: 8px;
      padding: 10px 14px; margin-top: 14px;
      font-size: 12px; color: #2d4a7a; font-weight: 600;
    }

    /* Footer */
    .ticket-footer {
      background: #1a2a4a; color: rgba(255,255,255,0.6);
      text-align: center; padding: 10px;
      font-size: 10px; letter-spacing: 0.5px;
    }

    /* PRINT STYLES */
    @media print {
      body { background: #fff !important; padding: 0 !important; }
      .print-controls { display: none !important; }
      .ticket-wrapper {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
        border-radius: 0 !important;
        max-width: 100% !important;
      }
      .perforation::before, .perforation::after { background: #fff !important; }
    }
  </style>
</head>
<body>
  <div class="print-controls no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print Ticket</button>
    <a href="booking-confirmation.php?ticket_id=<?= $ticketId ?>" class="btn-back">← Back</a>
    <a href="receipt.php?ticket_id=<?= $ticketId ?>" class="btn-back">View E-Receipt</a>
  </div>

  <div class="ticket-wrapper">
    <!-- TOP -->
    <div class="ticket-top">
      <div class="ticket-logo">
        <img src="../images/brand x.png" alt="Ticketix">
        <span class="ticket-logo-text">E-Ticket</span>
      </div>
      <div class="ticket-num">
        <?php $clientLabel = (isset($ticket['booking_type']) && $ticket['booking_type'] === 'walk-in') ? 'Walk-in' : 'Client'; ?>
        <span style="font-size:10px;font-weight:600;opacity:0.7;display:block;margin-bottom:2px;"><?= $clientLabel ?></span>
        Ticket Number
        <span><?= htmlspecialchars($ticket['ticket_number']) ?></span>
      </div>
    </div>

    <!-- MOVIE BANNER -->
    <div class="movie-banner">
      <img class="movie-poster-small"
           src="../<?= htmlspecialchars($ticket['image_poster'] ?? 'images/default.png') ?>"
           alt="<?= htmlspecialchars($ticket['title']) ?>"
           onerror="this.src='../images/default.png'">
      <div>
        <div class="movie-title"><?= htmlspecialchars($ticket['title']) ?></div>
        <div class="movie-meta"><?= htmlspecialchars($ticket['rating'] ?? '') ?><?= $ticket['duration'] ? ' · ' . $ticket['duration'] . ' min' : '' ?></div>
      </div>
    </div>

    <!-- BODY -->
    <div class="ticket-body">
      <div class="detail-grid">
        <div class="detail-block">
          <div class="detail-label">Branch</div>
          <div class="detail-value"><?= htmlspecialchars($ticket['branch_name'] ?? 'N/A') ?></div>
        </div>
        <div class="detail-block">
          <div class="detail-label">Date</div>
          <div class="detail-value"><?= $showDate ?></div>
        </div>
        <div class="detail-block">
          <div class="detail-label">Show Time</div>
          <div class="detail-value large"><?= $showTime ?></div>
        </div>
        <div class="detail-block">
          <div class="detail-label">Number of Seats</div>
          <div class="detail-value large"><?= count($seats) ?></div>
        </div>
      </div>

      <!-- Seats -->
      <div class="seats-section">
        <div class="seats-label">Seat Numbers</div>
        <?php foreach ($seats as $s): ?>
        <span class="seat-chip"><?= htmlspecialchars($s['seat_number']) ?></span>
        <?php endforeach; ?>
      </div>

      <!-- Food -->
      <?php if (!empty($foodItems)): ?>
      <div class="food-section">
        <div class="seats-label" style="margin-bottom:8px;">Food & Drinks</div>
        <?php foreach ($foodItems as $food): ?>
        <div class="food-row">
          <span><?= htmlspecialchars($food['food_name']) ?> ×<?= $food['quantity'] ?></span>
          <span>₱<?= number_format($food['food_price'] * $food['quantity'], 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Totals -->
      <div class="totals-section">
        <div class="total-row"><span>Seats</span><span>₱<?= number_format($ticket['sum_price'], 2) ?></span></div>
        <?php if ($ticket['food_total'] > 0): ?>
        <div class="total-row"><span>Food & Drinks</span><span>₱<?= number_format($ticket['food_total'], 2) ?></span></div>
        <?php endif; ?>
        <div class="grand-row"><span>Total Paid</span><span>₱<?= number_format($ticket['amount_paid'], 2) ?></span></div>
      </div>

      <!-- Payment -->
      <div class="payment-info">
         Payment: <?= ucfirst(str_replace('-', ' ', $ticket['payment_type'])) ?> · Ref: <?= htmlspecialchars($ticket['reference_number'] ?? 'N/A') ?>
      </div>
    </div>

    <!-- PERFORATION -->
    <div class="perforation"></div>

    <!-- STUB with QR -->
    <div class="ticket-stub">
      <div class="stub-left">
        <div class="stub-venue">Present at Cinema Entrance</div>
        <div class="stub-value" style="margin-bottom:8px;"><?= htmlspecialchars($ticket['branch_name'] ?? 'Cinema') ?></div>
        <div class="stub-venue">Issued</div>
        <div class="stub-value"><?= $issued ?></div>
        <div class="stub-venue" style="margin-top:8px;">Status</div>
        <div class="stub-value" style="color:#27ae60;"> VALID</div>
      </div>
      <div class="qr-block">
        <img src="<?= $qrCodeUrl ?>" alt="QR Code"
             onerror="this.src='https://chart.googleapis.com/chart?chs=110x110&cht=qr&chl=<?= urlencode($qrData) ?>'">
        <div class="qr-code-text"><?= htmlspecialchars(substr($qrData, 0, 32)) ?></div>
      </div>
    </div>

    <div class="ticket-footer">
      This ticket is non-transferable. Present QR code at the cinema entrance. · Ticketix © <?= date('Y') ?>
    </div>
  </div>

  <script>
    // Auto-open print dialog on load (staff physical ticket)
    window.addEventListener('load', () => {
      // Small delay to let QR load
      setTimeout(() => window.print(), 800);
    });
  </script>
</body>
</html>
