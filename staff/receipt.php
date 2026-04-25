<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

$ticketId = intval($_GET['ticket_id'] ?? 0);
if (!$ticketId) { header("Location: dashboard.php"); exit(); }

// Fetch ticket (staff can access any ticket - not restricted by user_id)
$q = "SELECT t.ticket_id, t.ticket_number, t.e_ticket_code, t.ticket_status, t.date_issued,
             t.payment_type, t.amount_paid, t.reference_number, t.payment_status,
             r.reservation_id, r.ticket_amount, r.sum_price, r.food_total, r.booking_type,
             m.title, m.image_poster,
             ms.show_date, ms.show_hour,
             b.branch_name
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

$qrData     = $ticket['e_ticket_code'] ?: $ticket['ticket_number'];
$qrCodeUrl  = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrData);
$qrCodeUrl2 = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qrData);
$showTime   = date('g:i A', strtotime($ticket['show_hour']));
$showDate   = date('F d, Y', strtotime($ticket['show_date']));
$grandTotal = ($ticket['sum_price'] ?? 0) + ($ticket['food_total'] ?? 0);

// Payment display
$paymentDisplay = ucfirst(str_replace('-', ' ', $ticket['payment_type'] ?? 'N/A'));
if (!empty($ticket['reference_number'])) {
    $refParts = explode('-', $ticket['reference_number']);
    $provMap  = ['gcash'=>'GCash','paymaya'=>'PayMaya','grabpay'=>'GrabPay','cash'=>'Cash'];
    $prov     = strtolower($refParts[0] ?? '');
    if (isset($provMap[$prov])) $paymentDisplay = $provMap[$prov];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>E-Receipt – <?= htmlspecialchars($ticket['ticket_number']) ?></title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="staff-main">
    <div class="page-header page-header-row no-print">
      <div>
        <h1>E-Receipt</h1>
        <p>Staff copy — matches customer ticket receipt</p>
      </div>
      <div style="display:flex;gap:10px;">
        <button onclick="window.print()" class="btn btn-primary">🖨 Print Receipt</button>
        <a href="print-ticket.php?ticket_id=<?= $ticketId ?>" target="_blank" class="btn btn-outline">Physical Ticket</a>
        <a href="booking-confirmation.php?ticket_id=<?= $ticketId ?>" class="btn btn-outline">← Back</a>
      </div>
    </div>

    <!-- Receipt Card -->
    <div class="ticket-card" style="max-width:620px;margin:0 auto;">

      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding-bottom:18px;border-bottom:1px dashed rgba(85,138,206,0.4);margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <img src="../images/brand x.png" alt="Ticketix" style="height:40px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Official Receipt</div>
            <?php
              $clientLabel = (isset($ticket['booking_type']) && $ticket['booking_type'] === 'walk-in') ? 'Walk-in' : 'Client';
            ?>
            <div style="font-size:13px;font-weight:700;color:var(--text-secondary);">Staff-Issued <?= $clientLabel ?> Ticket</div>
          </div>
        </div>
        <div style="text-align:right;">
          <span class="ticket-number-badge"><?= htmlspecialchars($ticket['ticket_number']) ?></span>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= date('M d, Y g:i A', strtotime($ticket['date_issued'])) ?></div>
        </div>
      </div>

      <!-- Status -->
      <div style="text-align:center;margin-bottom:18px;">
        <span class="status-badge status-valid" style="font-size:14px;padding:6px 20px;">
           BOOKING CONFIRMED
        </span>
        <div style="font-size:13px;color:var(--text-secondary);margin-top:8px;">Enjoy your movie! </div>
      </div>

      <!-- Movie Details -->
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Movie</span>
        <span class="ticket-detail-value" style="font-size:16px;font-weight:800;"><?= htmlspecialchars($ticket['title']) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Branch / Cinema</span>
        <span class="ticket-detail-value"><?= htmlspecialchars($ticket['branch_name'] ?? 'N/A') ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Date & Time</span>
        <span class="ticket-detail-value"><?= $showDate ?> at <?= $showTime ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Seats (<?= count($seats) ?>)</span>
        <span class="ticket-detail-value">
          <?php foreach ($seats as $s): ?>
          <span class="ticket-seat-chip"><?= htmlspecialchars($s['seat_number']) ?> (<?= $s['seat_type'] ?>)</span>
          <?php endforeach; ?>
        </span>
      </div>

      <!-- Food Items -->
      <?php if (!empty($foodItems)): ?>
      <div class="ticket-detail-row" style="align-items:flex-start;">
        <span class="ticket-detail-label">Food & Drinks</span>
        <span class="ticket-detail-value">
          <?php foreach ($foodItems as $food): ?>
          <div class="ticket-food-item"><?= htmlspecialchars($food['food_name']) ?> ×<?= $food['quantity'] ?> — ₱<?= number_format($food['food_price'] * $food['quantity'], 2) ?></div>
          <?php endforeach; ?>
        </span>
      </div>
      <?php endif; ?>

      <!-- Payment -->
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Payment Method</span>
        <span class="ticket-detail-value"><?= htmlspecialchars($paymentDisplay) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Payment Status</span>
        <span class="status-badge status-paid">Paid</span>
      </div>
      <?php if (!empty($ticket['reference_number'])): ?>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Reference #</span>
        <span class="ticket-detail-value" style="font-size:12px;"><?= htmlspecialchars($ticket['reference_number']) ?></span>
      </div>
      <?php endif; ?>

      <!-- Total -->
      <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-top:2px solid rgba(85,138,206,0.3);margin-top:8px;">
        <span style="font-size:16px;font-weight:700;">Total Amount Paid</span>
        <span class="ticket-total">₱<?= number_format($ticket['amount_paid'], 2) ?></span>
      </div>

      <!-- QR Code -->
      <div class="ticket-qr-section">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px;">Scan QR at Cinema Entrance</div>
        <img src="<?= $qrCodeUrl ?>" alt="QR Code"
             onerror="this.src='<?= $qrCodeUrl2 ?>'">
        <div class="ticket-qr-code" style="margin-top:8px;"><?= htmlspecialchars($qrData) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:16px;">
          This receipt is the official record of your walk-in booking at Ticketix Cinema.<br>
          Present the QR code OR ticket number at the cinema entrance.
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
