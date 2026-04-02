<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

$ticketId = intval($_GET['ticket_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null; // Support both session variable names

if (!$ticketId || !$userId) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

// Check if booking_status column exists
$bookingStatusCheck = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
$has_booking_status = $bookingStatusCheck && $bookingStatusCheck->num_rows > 0;

// Check if payment_status column exists in TICKET
$paymentStatusCheck = $conn->query("SHOW COLUMNS FROM TICKET LIKE 'payment_status'");
$has_payment_status = $paymentStatusCheck && $paymentStatusCheck->num_rows > 0;

// Get ticket details
$ticketQuery = "
    SELECT t.*, r.*, m.title, m.image_poster, ms.show_date, ms.show_hour,
           t.payment_type, t.amount_paid, t.reference_number
";

// Add payment_status if column exists
if ($has_payment_status) {
    $ticketQuery .= ", t.payment_status";
}

// Check if MOVIE_SCHEDULE has branch_id
$msBranchCheck = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
$msHasBranch = $msBranchCheck && $msBranchCheck->num_rows > 0;

if ($msHasBranch) {
    $ticketQuery .= ", b.branch_name
        FROM TICKET t
        JOIN RESERVE r ON t.reserve_id = r.reservation_id
        JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
        LEFT JOIN BRANCH b ON ms.branch_id = b.branch_id
        WHERE t.ticket_id = ? AND r.acc_id = ?";
} else {
    $ticketQuery .= "
        FROM TICKET t
        JOIN RESERVE r ON t.reserve_id = r.reservation_id
        JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
        WHERE t.ticket_id = ? AND r.acc_id = ?";
}

$stmt = $conn->prepare($ticketQuery);
// Use user_id from session (which is acc_id from database)
$stmt->bind_param("ii", $ticketId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$ticket = $result->fetch_assoc();
$stmt->close();

if (!$ticket) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

// Get seats
$stmt = $conn->prepare("
    SELECT rs.seat_number, 'Regular' AS seat_type
    FROM RESERVE_SEAT rs
    WHERE rs.reservation_id = ?
");
$stmt->bind_param("i", $ticket['reserve_id']);
$stmt->execute();
$seatsResult = $stmt->get_result();
$seats = [];
while ($row = $seatsResult->fetch_assoc()) {
    $seats[] = $row;
}
$stmt->close();

// Get food items
$stmt = $conn->prepare("
    SELECT f.food_name, tf.quantity, f.food_price
    FROM TICKET_FOOD tf
    JOIN FOOD f ON tf.food_id = f.food_id
    WHERE tf.ticket_id = ?
");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$foodResult = $stmt->get_result();
$foodItems = [];
while ($row = $foodResult->fetch_assoc()) {
    $foodItems[] = $row;
}
$stmt->close();

// Format show time
$showTime = date('g:i A', strtotime($ticket['show_hour']));
$showDate = date('F d, Y', strtotime($ticket['show_date']));

// Generate QR code URL (using a free QR code API)
$qrData = $ticket['e_ticket_code'] ?? $ticket['ticket_number'] ?? 'TICKET-' . $ticketId;
if (empty($qrData)) {
    $qrData = 'TICKET-' . $ticketId;
}

// Use multiple QR code API options for reliability
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrData);
$qrCodeUrl2 = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qrData);

// Fallback QR code if API fails (SVG-based)
$qrCodeAlt = "data:image/svg+xml;charset=utf-8," . rawurlencode('
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
    <rect width="200" height="200" fill="white" stroke="#000" stroke-width="2"/>
    <text x="100" y="90" text-anchor="middle" font-size="14" font-weight="bold" fill="#000">QR Code</text>
    <text x="100" y="110" text-anchor="middle" font-size="10" fill="#666">' . htmlspecialchars(substr($qrData, 0, 20)) . '</text>
    <text x="100" y="130" text-anchor="middle" font-size="10" fill="#666">' . htmlspecialchars(substr($qrData, 20, 20)) . '</text>
</svg>');

// Determine payment status (paid/pending/refunded)
$paymentStatus = 'pending';
if ($has_payment_status && isset($ticket['payment_status'])) {
    $paymentStatus = strtolower($ticket['payment_status']);
} else {
    // If payment_status column doesn't exist, assume paid if there's a reference number
    if (!empty($ticket['reference_number']) && $ticket['amount_paid'] > 0) {
        $paymentStatus = 'paid';
    }
}

// Determine booking approval status (pending/approved/declined)
$bookingApprovalStatus = 'pending';
if ($has_booking_status && isset($ticket['booking_status'])) {
    $bookingStatus = strtolower($ticket['booking_status']);
    if ($bookingStatus === 'approved') {
        $bookingApprovalStatus = 'approved';
    } elseif ($bookingStatus === 'declined') {
        $bookingApprovalStatus = 'declined';
    }
} else {
    $ticketStatus = strtolower($ticket['ticket_status'] ?? 'pending');
    if ($ticketStatus === 'valid') {
        $bookingApprovalStatus = 'approved';
    }
}

// Status classes for styling
$paymentStatusClass = 'status-' . str_replace([' ', '_'], '-', $paymentStatus);
$bookingStatusClass = 'status-' . str_replace([' ', '_'], '-', $bookingApprovalStatus);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Ticket - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/ticket.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Header Bar with Logo -->
    <div class="page-header">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo">
        </div>
        <span class="header-title">Your Ticket</span>
        <a href="TICKETIX NI CLAIRE.php" class="btn-back">← Home</a>
    </div>

    <div class="ticket-container">
        <?php
            // Show message based on booking approval status
            if ($bookingApprovalStatus === 'approved') {
                echo '<div class="success-message success"> Booking confirmed! Enjoy your movie.</div>';
            } elseif ($bookingApprovalStatus === 'declined') {
                echo '<div class="success-message error"> Booking was declined. Please contact support.</div>';
            } else {
                // Fallback for any legacy pending bookings
                echo '<div class="success-message"> Payment successful! Your booking is being processed.</div>';
            }
        ?>
        
        <div class="ticket-header">
            <h1>Ticketix</h1>
            <div class="ticket-number">Ticket #<?= htmlspecialchars($ticket['ticket_number']) ?></div>
        </div>
        
        <div class="ticket-content">
            <div class="ticket-details">
                <div class="movie-poster-float">
                    <img src="<?= htmlspecialchars($ticket['image_poster'] ?? 'images/default-poster.jpg') ?>" 
                         alt="<?= htmlspecialchars($ticket['title']) ?>" 
                         onerror="this.src='images/default-poster.jpg'">
                </div>
                <h2>Movie Details</h2>
                <div class="detail-item">
                    <span class="detail-label">Booking Type:</span>
                    <span class="detail-value">
                        <span style="background:rgba(85,138,206,0.15);color:#558ace;border:1px solid rgba(85,138,206,0.35);border-radius:6px;padding:2px 10px;font-size:12px;font-weight:700;letter-spacing:.04em;">Client (Online)</span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Movie:</span>
                    <span class="detail-value"><?= htmlspecialchars($ticket['title']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Branch:</span>
                    <span class="detail-value">
                        <?php
                            $branchDisplay = $ticket['branch_name'] ?? $branchName ?? 'SM Mall of Asia';
                            echo htmlspecialchars($branchDisplay);
                        ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Date & Time:</span>
                    <span class="detail-value"><?= $showDate ?> at <?= $showTime ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Seats:</span>
                    <div class="seats-list">
                        <?php foreach ($seats as $seat): ?>
                        <span class="seat-badge"><?= htmlspecialchars($seat['seat_number']) ?> (<?= $seat['seat_type'] ?>)</span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <?php if (count($foodItems) > 0): ?>
                <div class="detail-item">
                    <span class="detail-label">Food Items:</span>
                    <div class="detail-value">
                        <?php foreach ($foodItems as $food): ?>
                        <div><?= htmlspecialchars($food['food_name']) ?> x<?= $food['quantity'] ?> - ₱<?= number_format($food['food_price'] * $food['quantity'], 2) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <span class="detail-label">Payment Method:</span>
                    <span class="detail-value">
                        <?php
                        // Format payment type display
                        $paymentDisplay = ucfirst(str_replace('-', ' ', $ticket['payment_type'] ?? 'N/A'));
                        // Try to extract sub-option from reference number if available
                        if (isset($ticket['reference_number']) && !empty($ticket['reference_number'])) {
                            $refParts = explode('-', $ticket['reference_number']);
                            if (count($refParts) > 0) {
                                $subOption = strtolower($refParts[0]);
                                $subOptionMap = [
                                    'visa' => 'Visa',
                                    'mastercard' => 'Mastercard',
                                    'amex' => 'American Express',
                                    'discover' => 'Discover',
                                    'gcash' => 'GCash',
                                    'paymaya' => 'PayMaya',
                                    'paypal' => 'PayPal',
                                    'grabpay' => 'GrabPay',
                                    'card' => 'Credit Card'
                                ];
                                // Handle uppercase versions too
                                if (isset($subOptionMap[$subOption])) {
                                    $paymentDisplay = $subOptionMap[$subOption];
                                } else {
                                    $subOptionUpper = strtoupper($refParts[0]);
                                    $upperMap = [
                                        'VISA' => 'Visa',
                                        'MASTERCARD' => 'Mastercard',
                                        'AMEX' => 'American Express',
                                        'DISCOVER' => 'Discover',
                                        'GCASH' => 'GCash',
                                        'PAYMAYA' => 'PayMaya',
                                        'PAYPAL' => 'PayPal',
                                        'GRABPAY' => 'GrabPay',
                                        'CARD' => 'Credit Card'
                                    ];
                                    if (isset($upperMap[$subOptionUpper])) {
                                        $paymentDisplay = $upperMap[$subOptionUpper];
                                    }
                                }
                            }
                        }
                        echo htmlspecialchars($paymentDisplay);
                        ?>
                    </span>
                </div>
                
                <!-- Payment Status -->
                <div class="detail-item">
                    <span class="detail-label">Payment Status:</span>
                    <span class="detail-value <?= 'status-badge ' . $paymentStatusClass ?>">
                        <?= ucfirst($paymentStatus) ?>
                    </span>
                </div>
                
                <!-- Booking Approval Status -->
                <div class="detail-item">
                    <span class="detail-label">Booking Status:</span>
                    <span class="detail-value <?= 'status-badge ' . $bookingStatusClass ?>">
                        <?php 
                        if ($bookingApprovalStatus === 'approved') {
                            echo 'Approved';
                        } elseif ($bookingApprovalStatus === 'declined') {
                            echo 'Declined';
                        } else {
                            echo 'Pending Approval';
                        }
                        ?>
                    </span>
                </div>
                
                <?php if ($paymentStatus === 'refunded'): ?>
                <div class="detail-alert detail-alert-info">Refund processed. Please check your payment provider for the returned funds.</div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <span class="detail-label">Total Paid:</span>
                    <span class="detail-value highlight">₱<?= number_format($ticket['amount_paid'], 2) ?></span>
                </div>
                <?php if (!empty($ticket['reference_number'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Reference #:</span>
                    <span class="detail-value"><?= htmlspecialchars($ticket['reference_number']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($bookingApprovalStatus === 'approved'): ?>
            <div class="qr-section">
                <h2>QR Code</h2>
                <div class="qr-code">
                    <img src="<?= $qrCodeUrl ?>" alt="QR Code" 
                         onerror="if(this.src !== '<?= $qrCodeUrl2 ?>') { this.src='<?= $qrCodeUrl2 ?>'; } else { this.src='<?= $qrCodeAlt ?>'; this.onerror=null; }"
                         class="qr-code-img">
                </div>
                <p class="qr-code-text">Present this QR code at the cinema</p>
                <p class="qr-code-code">Code: <?= htmlspecialchars($ticket['e_ticket_code']) ?></p>
            </div>
            <?php elseif ($bookingApprovalStatus === 'declined'): ?>
            <div class="qr-section">
                <div class="qr-declined">
                    <div class="qr-declined-icon"></div>
                    <h3>Booking Declined</h3>
                    <p class="declined-message">The admin doesn't approve your booking!</p>
                    <p>Your payment of ₱<?= number_format($ticket['amount_paid'], 2) ?> has been refunded, since the admin didn't approve the booking!</p>
                </div>
            </div>
            <?php else: ?>
            <div class="qr-section">
                <div class="qr-pending">
                    <div class="qr-pending-icon"></div>
                    <h3>Processing Booking</h3>
                    <p>Your booking is being processed. Please refresh this page shortly.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <a href="TICKETIX NI CLAIRE.php" class="btn-home">Back to Home</a>
    </div>
</body>
</html>