<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();
$conn = getDBConnection();

$ticketId = intval($_GET['ticket_id'] ?? $_SESSION['staff_last_ticket_id'] ?? 0);
if (!$ticketId) { header("Location: dashboard.php"); exit(); }

$changeGiven  = $_SESSION['staff_last_change'] ?? null;
$paymentMethod = $_SESSION['staff_last_payment'] ?? 'cash';

// Fetch ticket
$q = "SELECT t.ticket_id, t.ticket_number, t.e_ticket_code, t.ticket_status,
             t.payment_type, t.amount_paid, t.reference_number,
             r.reservation_id, r.ticket_amount, r.sum_price, r.food_total,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Confirmed – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="staff-main">

    <!-- STEPS - all done -->
    <div class="steps-bar">
      <div class="step-item done"><span class="step-num">✓</span> Details</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Seats & Food</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Checkout</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Payment</div>
    </div>

    <!-- SUCCESS BANNER -->
    <div class="card" style="text-align:center;padding:36px;margin-bottom:24px;">
      <div class="confirmation-title">Booking Confirmed!</div>
      <p class="confirmation-sub">Walk-in booking completed successfully. Ticket is ready.</p>

      <?php if ($changeGiven !== null): ?>
      <div style="background:rgba(46,204,113,0.15);border:1px solid rgba(46,204,113,0.4);border-radius:10px;padding:14px;margin:16px auto;max-width:260px;">
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;">Change to Give Customer</div>
        <div style="font-size:32px;font-weight:800;color:#2ecc71;">₱<?= number_format($changeGiven, 2) ?></div>
      </div>
      <?php endif; ?>

      <div class="confirmation-actions" style="margin-top:24px;">
        <a href="print-ticket.php?ticket_id=<?= $ticketId ?>" target="_blank"
           class="btn btn-primary btn-lg"> Print Physical Ticket</a>
        <a href="receipt.php?ticket_id=<?= $ticketId ?>"
           class="btn btn-outline btn-lg"> View E-Receipt</a>
        <a href="booking.php" class="btn btn-success btn-lg"> New Booking</a>
      </div>
    </div>

    <!-- Ticket Summary -->
    <div class="card" style="max-width:600px;margin:0 auto;">
      <h2>Ticket Details</h2>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Ticket #</span>
        <span class="ticket-detail-value" style="color:var(--blue);font-weight:800;"><?= htmlspecialchars($ticket['ticket_number']) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Movie</span>
        <span class="ticket-detail-value"><?= htmlspecialchars($ticket['title']) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Branch</span>
        <span class="ticket-detail-value"><?= htmlspecialchars($ticket['branch_name'] ?? 'N/A') ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Show</span>
        <span class="ticket-detail-value"><?= date('M d, Y', strtotime($ticket['show_date'])) ?> · <?= date('g:i A', strtotime($ticket['show_hour'])) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Seats</span>
        <span class="ticket-detail-value"><?= $ticket['ticket_amount'] ?> seat(s)</span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Payment</span>
        <span class="ticket-detail-value"><?= ucfirst(str_replace('-', ' ', $ticket['payment_type'])) ?></span>
      </div>
      <div class="ticket-detail-row">
        <span class="ticket-detail-label">Amount Paid</span>
        <span class="ticket-detail-value summary-total">₱<?= number_format($ticket['amount_paid'], 2) ?></span>
      </div>
      <div class="ticket-detail-row" style="border:none;">
        <span class="ticket-detail-label">Status</span>
        <span class="status-badge status-valid">✓ Valid</span>
      </div>
    </div>

    <div style="text-align:center;margin-top:20px;">
      <a href="dashboard.php" class="btn btn-outline"> Back to Dashboard</a>
    </div>
  </main>
</div>
</body>
</html>
