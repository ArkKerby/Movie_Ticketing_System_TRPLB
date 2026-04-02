<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;

if (!$userId) {
    header("Location: login.php");
    exit();
}

// Check if food_total column exists in RESERVE table
$column_check = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'food_total'");
$has_food_total = $column_check && $column_check->num_rows > 0;

// Check if booking_status column exists
$bookingStatusCheck = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
$has_booking_status = $bookingStatusCheck && $bookingStatusCheck->num_rows > 0;

// Get all bookings/tickets for this user
$foodTotalSelect = $has_food_total ? "r.food_total" : "0 AS food_total";
$bookingStatusSelect = $has_booking_status ? "r.booking_status" : "NULL AS booking_status";
$query = "
    SELECT t.ticket_id, t.ticket_number, t.date_issued, t.ticket_status, t.e_ticket_code,
           r.reservation_id, r.reserve_date, r.ticket_amount, r.sum_price, $foodTotalSelect,
           $bookingStatusSelect,
           m.title, m.image_poster,
           ms.show_date, ms.show_hour,
           t.payment_type, t.amount_paid, t.payment_status, t.reference_number
";

$msBranchCheck = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
$msHasBranch = $msBranchCheck && $msBranchCheck->num_rows > 0;

if ($msHasBranch) {
    $query .= ",
           b.branch_name
        FROM TICKET t
        JOIN RESERVE r ON t.reserve_id = r.reservation_id
        JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
        LEFT JOIN BRANCH b ON ms.branch_id = b.branch_id
        WHERE r.acc_id = ?
        ORDER BY t.date_issued DESC
    ";
} else {
    $query .= "
        FROM TICKET t
        JOIN RESERVE r ON t.reserve_id = r.reservation_id
        JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
        WHERE r.acc_id = ?
        ORDER BY t.date_issued DESC
    ";
}
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$bookings = [];
while ($row = $result->fetch_assoc()) {
    // Get seats for this reservation
    $seatStmt = $conn->prepare("
        SELECT rs.seat_number
        FROM RESERVE_SEAT rs
        WHERE rs.reservation_id = ?
        ORDER BY rs.seat_number
    ");
    $seatStmt->bind_param("i", $row['reservation_id']);
    $seatStmt->execute();
    $seatResult = $seatStmt->get_result();
    $seatNumbers = [];
    while ($seatRow = $seatResult->fetch_assoc()) {
        $seatNumbers[] = $seatRow['seat_number'];
    }
    $row['seat_numbers'] = $seatNumbers;
    $seatStmt->close();
    
    $bookings[] = $row;
}
$stmt->close();

// Get user name
$userStmt = $conn->prepare("SELECT firstName, lastName, email FROM USER_ACCOUNT WHERE acc_id = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Ticketix</title>
    <link rel="stylesheet" href="css/ticketix-main.css">
    <link rel="stylesheet" href="css/my-bookings.css">
    <link rel="icon" type="image/png" href="images/brand x.png" />
</head>
<body>
    <div class="bookings-container">
        <div class="page-header">
            <h1>My Bookings</h1>
            <p>View all your movie ticket bookings</p>
        </div>

        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎬</div>
                <h2>No Bookings Yet</h2>
                <p>You haven't made any bookings yet. Start booking your favorite movies!</p>
                <a href="branch-selection.php" class="back-link">Buy a Ticket!</a>
            </div>
        <?php else: ?>
            <div class="bookings-list">
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $showTime = date('g:i A', strtotime($booking['show_hour']));
                    $showDate = date('F d, Y', strtotime($booking['show_date']));
                    $reserveDate = date('F d, Y g:i A', strtotime($booking['reserve_date']));
                    
                    // Determine display status: prefer booking_status over ticket_status
                    // If booking_status exists and is pending/declined, use that
                    // Otherwise, use ticket_status
                    // Default to pending until explicitly approved
                    $displayStatus = 'pending';
                    if ($has_booking_status && isset($booking['booking_status'])) {
                        $bookingStatus = strtolower($booking['booking_status'] ?? '');
                        if ($bookingStatus === 'approved') {
                            $displayStatus = 'valid';
                        } elseif ($bookingStatus === 'declined') {
                            $displayStatus = 'cancelled';
                        }
                    } else {
                        // Fallback when booking_status column doesn't exist
                        $displayStatus = strtolower($booking['ticket_status'] ?? 'pending');
                    }

                    // Check if booking has expired (showtime has passed)
                    // Only override if not already cancelled or refunded
                    if (!in_array($displayStatus, ['cancelled', 'refunded'])) {
                        $tz = new DateTimeZone('Asia/Manila');
                        $showDateTime = new DateTime($booking['show_date'] . ' ' . $booking['show_hour'], $tz);
                        $now = new DateTime('now', $tz);
                        if ($now > $showDateTime) {
                            $displayStatus = 'expired';
                        }
                    }
                    
                    $statusClass = 'status-' . strtolower($displayStatus);
                    ?>
                    <div class="booking-card">
                        <img src="<?= htmlspecialchars($booking['image_poster'] ?? 'images/default-poster.jpg') ?>" 
                             alt="<?= htmlspecialchars($booking['title']) ?>" 
                             class="booking-poster"
                             onerror="this.src='images/default-poster.jpg'">
                        <div class="booking-details">
                            <div class="booking-header">
                                <div>
                                    <div class="booking-title"><?= htmlspecialchars($booking['title']) ?></div>
                                    <div class="booking-branch">
                                        <?= htmlspecialchars($booking['branch_name'] ?? 'Branch not specified') ?>
                                    </div>
                                </div>
                                <span class="ticket-status <?= $statusClass ?>">
                                    <?= ucfirst($displayStatus) ?>
                                </span>
                            </div>
                            <div class="booking-info">
                                <div class="info-item">
                                    <span class="info-label">Ticket Number</span>
                                    <span class="info-value"><?= htmlspecialchars($booking['ticket_number']) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Show Date & Time</span>
                                    <span class="info-value"><?= $showDate ?> at <?= $showTime ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Seats</span>
                                    <span class="info-value">
                                        <?php if (!empty($booking['seat_numbers'])): ?>
                                            <?= implode(', ', array_map('htmlspecialchars', $booking['seat_numbers'])) ?>
                                        <?php else: ?>
                                            <?= $booking['ticket_amount'] ?> seat(s)
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Total Amount</span>
                                    <span class="info-value">₱<?= number_format($booking['amount_paid'], 2) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Payment Method</span>
                                    <span class="info-value">
                                        <?php
                                        // Format payment type display
                                        $paymentDisplay = ucfirst(str_replace('-', ' ', $booking['payment_type'] ?? 'N/A'));
                                        // Try to extract sub-option from reference number if available
                                        if (isset($booking['reference_number']) && !empty($booking['reference_number'])) {
                                            $refParts = explode('-', $booking['reference_number']);
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
                                                    // Try uppercase version
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
                                <div class="info-item">
                                    <span class="info-label">Payment Status</span>
                                    <span class="info-value">
                                        <?php $paymentStatus = $booking['payment_status'] ?? 'pending'; ?>
                                        <span class="ticket-status <?= 'status-' . strtolower($paymentStatus) ?>">
                                            <?= ucfirst(str_replace('-', ' ', $paymentStatus)) ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Booked On</span>
                                    <span class="info-value"><?= $reserveDate ?></span>
                                </div>
                            </div>
                            <div class="booking-actions">
                                <a href="ticket.php?ticket_id=<?= $booking['ticket_id'] ?>" class="btn btn-primary">View Ticket</a>
                                <?php if ($displayStatus === 'valid'): ?>
                                    <button class="btn btn-secondary" onclick="sendTicketEmail(<?= $booking['ticket_id'] ?>, this)">Download</button>
                                <?php elseif ($displayStatus === 'pending'): ?>
                                    <span class="btn btn-secondary" style="opacity: 0.6; cursor: not-allowed;">Pending Approval</span>
                                <?php endif; ?>
                                <?php if (($booking['payment_status'] ?? '') === 'refunded'): ?>
                                    <p class="refund-note">Refund initiated. Funds should appear in your account shortly.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="text-center">
            <a href="TICKETIX NI CLAIRE.php" class="back-link">← Back to Homepage</a>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast-overlay" id="toastOverlay" onclick="hideDownloadToast()"></div>
    <div class="toast-notification" id="downloadToast">
        <div class="toast-message" id="toastMessage">Your ticket receipt has been sent to your email. Please check your inbox or spam folder.</div>
        <button class="toast-close" onclick="hideDownloadToast()">OK</button>
    </div>

    <script>
    function sendTicketEmail(ticketId, btn) {
        // Disable the button while sending
        btn.disabled = true;
        btn.textContent = 'Sending...';

        fetch('send-ticket-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ticket_id=' + ticketId
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('toastMessage').textContent = data.message;
            showDownloadToast();
            btn.disabled = false;
            btn.textContent = 'Download';
        })
        .catch(() => {
            document.getElementById('toastMessage').textContent = 'An error occurred. Please try again later.';
            showDownloadToast();
            btn.disabled = false;
            btn.textContent = 'Download';
        });
    }

    function showDownloadToast() {
        document.getElementById('toastOverlay').classList.add('show');
        document.getElementById('downloadToast').classList.add('show');
    }
    function hideDownloadToast() {
        document.getElementById('toastOverlay').classList.remove('show');
        document.getElementById('downloadToast').classList.remove('show');
    }
    </script>
</body>
</html>