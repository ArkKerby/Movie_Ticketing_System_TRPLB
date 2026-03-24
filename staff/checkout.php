<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_data'])) {
    header("Location: booking.php");
    exit();
}

// Store booking data in session
$_SESSION['staff_booking'] = [
    'booking_data' => $_POST['booking_data'],
    'seat_total'   => floatval($_POST['seat_total'] ?? 0),
    'food_total'   => floatval($_POST['food_total'] ?? 0),
    'grand_total'  => floatval($_POST['grand_total'] ?? 0),
];

$bookingData = json_decode($_POST['booking_data'], true);
$seatTotal   = floatval($_POST['seat_total'] ?? 0);
$foodTotal   = floatval($_POST['food_total'] ?? 0);
$grandTotal  = floatval($_POST['grand_total'] ?? 0);

$movieTitle  = urldecode($bookingData['movie'] ?? '');
$branchName  = urldecode($bookingData['branch'] ?? '');
$showDate    = $bookingData['date'] ?? date('Y-m-d');
$showTime    = $bookingData['time'] ?? '';
$seats       = $bookingData['seats'] ?? [];
$foodItems   = $bookingData['food'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout – Staff Portal</title>
  <link rel="icon" type="image/png" href="../images/brand x.png">
  <link rel="stylesheet" href="css/staff.css">
</head>
<body class="staff-bg">
<div class="staff-layout">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="staff-main">
    <div class="page-header">
      <h1>Booking Summary</h1>
      <p>Step 3 of 4 — Review before payment</p>
    </div>

    <!-- STEPS -->
    <div class="steps-bar">
      <div class="step-item done"><span class="step-num">✓</span> Details</div>
      <span class="step-sep">›</span>
      <div class="step-item done"><span class="step-num">✓</span> Seats & Food</div>
      <span class="step-sep">›</span>
      <div class="step-item active"><span class="step-num">3</span> Checkout</div>
      <span class="step-sep">›</span>
      <div class="step-item"><span class="step-num">4</span> Payment</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

      <!-- Summary Card -->
      <div class="card">
        <h2>Order Details</h2>

        <table class="summary-table">
          <thead><tr><th>Item</th><th>Details</th><th>Amount</th></tr></thead>
          <tbody>
            <tr>
              <td> Movie</td>
              <td><?= htmlspecialchars($movieTitle) ?></td>
              <td></td>
            </tr>
            <tr>
              <td> Branch</td>
              <td><?= htmlspecialchars($branchName) ?></td>
              <td></td>
            </tr>
            <tr>
              <td> Date & Time</td>
              <td><?= date('M d, Y', strtotime($showDate)) ?> at <?= htmlspecialchars($showTime) ?></td>
              <td></td>
            </tr>
            <tr>
              <td> Seats (<?= count($seats) ?>)</td>
              <td>
                <?php foreach ($seats as $s): ?>
                <span class="ticket-seat-chip"><?= htmlspecialchars($s) ?></span>
                <?php endforeach; ?>
              </td>
              <td>₱<?= number_format($seatTotal, 2) ?></td>
            </tr>

            <?php if (!empty($foodItems)): ?>
            <tr><td colspan="3" style="padding-top:8px;font-weight:600;color:var(--text-secondary);font-size:13px;"> Food & Drinks</td></tr>
            <?php foreach ($foodItems as $food): ?>
            <tr>
              <td style="padding-left:16px;"><?= htmlspecialchars($food['name']) ?></td>
              <td style="color:var(--text-secondary);">×<?= $food['quantity'] ?> @ ₱<?= number_format($food['price'], 2) ?></td>
              <td>₱<?= number_format($food['subtotal'] ?? ($food['price'] * $food['quantity']), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="summary-divider" style="margin-top:16px;"></div>

        <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:14px;">
          <span>Seats Subtotal</span><span>₱<?= number_format($seatTotal, 2) ?></span>
        </div>
        <?php if ($foodTotal > 0): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:14px;">
          <span>Food Subtotal</span><span>₱<?= number_format($foodTotal, 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="summary-divider"></div>
        <div style="display:flex;justify-content:space-between;padding:10px 0;font-size:22px;font-weight:800;color:var(--blue);">
          <span>Grand Total</span><span>₱<?= number_format($grandTotal, 2) ?></span>
        </div>

        <div style="display:flex;gap:12px;margin-top:8px;">
          <a href="javascript:history.back()" class="btn btn-outline">← Edit</a>
          <a href="payment.php" class="btn btn-primary btn-lg" style="flex:1;justify-content:center;">Proceed to Payment →</a>
        </div>
      </div>

      <!-- Booking recap card -->
      <div class="card card-sm">
        <h3 style="color:var(--blue);margin-bottom:16px;"> Quick Recap</h3>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Customer</span>
          <span class="ticket-detail-value">Walk-In</span>
        </div>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Served By</span>
          <span class="ticket-detail-value"><?= staffName() ?></span>
        </div>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Movie</span>
          <span class="ticket-detail-value"><?= htmlspecialchars($movieTitle) ?></span>
        </div>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Branch</span>
          <span class="ticket-detail-value"><?= htmlspecialchars($branchName) ?></span>
        </div>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Show</span>
          <span class="ticket-detail-value"><?= date('M d', strtotime($showDate)) ?> · <?= htmlspecialchars($showTime) ?></span>
        </div>
        <div class="ticket-detail-row">
          <span class="ticket-detail-label">Seats</span>
          <span class="ticket-detail-value"><?= count($seats) ?> seat(s)</span>
        </div>
        <div class="ticket-detail-row" style="border:none;">
          <span class="ticket-detail-label">Total</span>
          <span class="ticket-detail-value summary-total">₱<?= number_format($grandTotal, 2) ?></span>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
