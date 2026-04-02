<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

// Get booking data from POST first, then SESSION
$rawBookingData = null;

if (!empty($_POST['booking_data'])) {
    $rawBookingData = $_POST['booking_data'];
} elseif (!empty($_SESSION['pending_booking'])) {
    $rawBookingData = $_SESSION['pending_booking'];
}

// Clear from session AFTER reading it
unset($_SESSION['pending_booking']);

if (!$rawBookingData) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

$bookingData = json_decode($rawBookingData, true);
if (!$bookingData) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

// Extract booking information
$movieTitle = urldecode($bookingData['movie'] ?? '');
$branchName = urldecode($bookingData['branch'] ?? '');
$showTime = $bookingData['time'] ?? '';
$showDate = $bookingData['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $showDate)) {
    $showDate = date('Y-m-d');
}
$selectedSeats = $bookingData['seats'] ?? [];
$foodItems = $bookingData['food'] ?? [];
$foodTotal = floatval($bookingData['foodTotal'] ?? 0);

// ── 20-Minute Cutoff Validation ───────────────────────────────────────────
// Only check if the booking is for today (future dates are always allowed)
// Use Philippine timezone explicitly for accurate time comparison
$phTimezone = new DateTimeZone('Asia/Manila');
$nowPH = new DateTime('now', $phTimezone);
$todayPH = $nowPH->format('Y-m-d');

if ($showDate === $todayPH && !empty($showTime)) {
    // Parse time like "10:30 AM", "08:30 PM" etc.
    $showDateTimeObj = DateTime::createFromFormat('g:i A', $showTime, $phTimezone);
    if ($showDateTimeObj) {
        $showDateTimeObj->setDate((int)$nowPH->format('Y'), (int)$nowPH->format('m'), (int)$nowPH->format('d'));
        $diffSeconds = $showDateTimeObj->getTimestamp() - $nowPH->getTimestamp();
        if ($diffSeconds <= (20 * 60)) {
            // Too late — redirect back with an error
            header("Location: seat-reservation.php?" . http_build_query([
                'movie'  => $bookingData['movie'] ?? '',
                'branch' => $bookingData['branch'] ?? '',
                'time'   => $showTime,
                'date'   => $showDate,
                'error'  => 'cutoff'
            ]));
            exit();
        }
    }
}
// ── End Cutoff Validation ─────────────────────────────────────────────────



// Get movie details
$movie = null;
$moviePoster = 'images/default.png';
if ($movieTitle) {
    $stmt = $conn->prepare("SELECT movie_show_id, title, image_poster FROM MOVIE WHERE title = ? LIMIT 1");
    $stmt->bind_param("s", $movieTitle);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $movie = $result->fetch_assoc();
        $moviePoster = !empty($movie['image_poster']) ? htmlspecialchars($movie['image_poster']) : 'images/default.png';
    }
    $stmt->close();
}

// Calculate dynamic seat prices
$seatCount = count($selectedSeats);
$seatTotal = 0;
$seatDetails = [];

// Determine max columns per row to find accurate centers
$rowCenters = [];
$globalMaxCol = 0;
foreach ($selectedSeats as $seatId) {
    if (preg_match('/^([A-Z])-?(\d+)$/i', $seatId, $matches)) {
        $r = strtoupper($matches[1]);
        $c = intval($matches[2]);
        if (!isset($rowCenters[$r]) || $c > $rowCenters[$r]['maxCol']) {
            $rowCenters[$r] = ['maxCol' => $c];
        }
        if ($c > $globalMaxCol) $globalMaxCol = $c;
    }
}
// For dynamic calculation, we need to know the width of all rows.
// Since we don't query every seat here, we can use a basic approximation 
// based on standard layout (A-B: 18, C-G: 18), but we will rely centrally on the front-end passed prices if available, 
// or fallback to calculating here.

$frontEndSeatsData = $bookingData['seatsData'] ?? []; 
if (!empty($frontEndSeatsData)) {
    // If seat-reservation passed the exact calculated prices, use them!
    foreach ($frontEndSeatsData as $sd) {
        $price = floatval($sd['price']);
        $seatTotal += $price;
        $seatDetails[] = [
            'id' => $sd['id'],
            'tier' => $sd['tier'],
            'price' => $price
        ];
    }
} else {
    // Fallback if not passed (legacy)
    $seatPrice = 350.00;
    $seatTotal = $seatPrice * $seatCount;
    foreach ($selectedSeats as $s) {
        $seatDetails[] = ['id' => $s, 'tier' => 'Standard', 'price' => 350.00];
    }
}

$grandTotal = $seatTotal + $foodTotal;

// Get user ID
$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;

// Check PWD discount eligibility
$pwdApproved = false;
$pwdDiscount  = 0;
// Check Senior Citizen discount eligibility
$seniorApproved = false;
$seniorDiscount = 0;
if ($userId) {
    $discountStmt = $conn->prepare("SELECT pwd_approved, senior_approved FROM USER_ACCOUNT WHERE acc_id = ?");
    $discountStmt->bind_param("i", $userId);
    $discountStmt->execute();
    $discountRow = $discountStmt->get_result()->fetch_assoc();
    $discountStmt->close();
    if ($discountRow) {
        if (!empty($discountRow['pwd_approved'])) {
            $pwdApproved = true;
            $pwdDiscount = $seatTotal * 0.20;
        } elseif (!empty($discountRow['senior_approved'])) {
            $seniorApproved = true;
            $seniorDiscount = $seatTotal * 0.20;
        }
    }
}

$grandTotal = $seatTotal + $foodTotal - $pwdDiscount - $seniorDiscount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/checkout.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php
    // PWD banner: show if user has no pending/approved application
    $showPwdBannerCheckout = false;
    if ($userId && !$pwdApproved && !$seniorApproved) {
        $conn2 = getDBConnection();
        $bannerCheckTbl = $conn2->query("SHOW TABLES LIKE 'DISCOUNT_APPLICATIONS'");
        if ($bannerCheckTbl && $bannerCheckTbl->num_rows > 0) {
            $bStmt = $conn2->prepare("SELECT status FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'pwd' ORDER BY submitted_at DESC LIMIT 1");
            $bStmt->bind_param("i", $userId);
            $bStmt->execute();
            $bRow = $bStmt->get_result()->fetch_assoc();
            $bStmt->close();
            if (!$bRow || !in_array($bRow['status'], ['pending', 'approved'])) {
                $showPwdBannerCheckout = true;
            }
        } else {
            $showPwdBannerCheckout = true;
        }
        $conn2->close();
    }
    ?>
    <?php if ($showPwdBannerCheckout): ?>
    <div id="pwdToast" style="
      position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-120px);
      background:linear-gradient(135deg,#1a2a4a,#0f1e38);
      border:1px solid rgba(85,138,206,0.45);
      border-radius:12px;
      padding:14px 22px;
      display:flex;align-items:center;justify-content:space-between;gap:16px;
      font-family:'Montserrat',sans-serif;font-size:0.88rem;
      color:rgba(255,255,255,0.9);
      box-shadow:0 8px 32px rgba(0,0,0,0.55),0 0 0 1px rgba(85,138,206,0.15);
      z-index:99999;
      min-width:320px;max-width:600px;width:90%;
      transition:transform 0.5s cubic-bezier(0.34,1.56,0.64,1),opacity 0.5s ease;
      opacity:0;
    ">
      <span>Have you submitted your PWD ID or Senior Citizen ID to have a 20% discount? <a href="profile.php#pwd" style="color:#7ab5ff;font-weight:600;text-decoration:underline;">Apply on your Profile.</a></span>
      <button onclick="dismissPwdToast()" style="background:none;border:none;color:rgba(255,255,255,0.45);cursor:pointer;font-size:1rem;padding:0 2px;line-height:1;flex-shrink:0;">x</button>
    </div>
    <script>
    (function(){
      var toast = document.getElementById('pwdToast');
      var dismissed = false;
      function showToast(){
        if(dismissed) return;
        toast.style.transform = 'translateX(-50%) translateY(0)';
        toast.style.opacity   = '1';
        setTimeout(hideToast, 4000);
      }
      function hideToast(){
        if(dismissed) return;
        toast.style.transform = 'translateX(-50%) translateY(-120px)';
        toast.style.opacity   = '0';
        setTimeout(showToast, 12000);
      }
      window.dismissPwdToast = function(){
        dismissed = true;
        toast.style.transform = 'translateX(-50%) translateY(-120px)';
        toast.style.opacity   = '0';
      };
      setTimeout(showToast, 800);
    })();
    </script>
    <?php endif; ?>
    <!-- Header Bar with Logo -->
    <div class="checkout-header">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo">
        </div>
        <span class="header-title">Checkout</span>
        <a href="javascript:history.back()" class="btn-back">← Back</a>
    </div>

    <div class="checkout-container">
        <h1>Checkout</h1>
        
        <div class="booking-summary">
            <div class="movie-poster-section">
                <img src="<?= htmlspecialchars($moviePoster) ?>" alt="<?= htmlspecialchars($movieTitle) ?>">
                <h3><?= htmlspecialchars($movieTitle) ?></h3>
            </div>
            
            <div class="summary-details">
                <h2>Booking Summary</h2>
                <div class="detail-row">
                    <strong>Branch:</strong>
                    <span><?= htmlspecialchars($branchName ?: 'TICKETIX') ?></span>
                </div>
                <?php
                $cinemaNameDisplay = $bookingData['cinemaName'] ?? '';
                if ($cinemaNameDisplay): ?>
                <div class="detail-row">
                    <strong>Cinema:</strong>
                    <span><?= htmlspecialchars($cinemaNameDisplay) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <strong>Show Date:</strong>
                    <span><?= date('F d, Y', strtotime($showDate)) ?></span>
                </div>
                <div class="detail-row">
                    <strong>Show Time:</strong>
                    <span><?= htmlspecialchars($showTime) ?></span>
                </div>
                <div class="detail-row">
                    <strong>Seats (<?= $seatCount ?>):</strong>
                    <div class="seats-list">
                        <?php foreach($seatDetails as $sd): ?>
                            <span class="seat-badge <?= strtolower($sd['tier']) ?>">
                                <?= htmlspecialchars($sd['id']) ?> (<?= $sd['tier'] ?>)  - ₱<?= number_format($sd['price'], 2) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <?php if (count($foodItems) > 0): ?>
                <div class="detail-row food-items-row">
                    <strong>Food Items:</strong>
                    <div class="food-items-table-wrapper">
                        <table class="food-items-table">
                            <thead>
                                <tr>
                                    <th>Quantity</th>
                                    <th>Name</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($foodItems as $food): ?>
                                <tr>
                                    <td><?= htmlspecialchars($food['quantity'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($food['name'] ?? 'N/A') ?></td>
                                    <td>₱<?= number_format($food['subtotal'] ?? 0, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="price-section">
            <div class="price-row">
                <span>Seat Total (<?= $seatCount ?> seats):</span>
                <span>₱<?= number_format($seatTotal, 2) ?></span>
            </div>
            <?php if ($foodTotal > 0): ?>
            <div class="price-row">
                <span>Food Total:</span>
                <span>₱<?= number_format($foodTotal, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($pwdApproved): ?>
            <div class="price-row" style="color:#8ec98e;">
                <span>PWD Discount (20% off seats):</span>
                <span>- ₱<?= number_format($pwdDiscount, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($seniorApproved): ?>
            <div class="price-row" style="color:#8ec98e;">
                <span>Senior Citizen Discount (20% off seats):</span>
                <span>- ₱<?= number_format($seniorDiscount, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="price-row total">
                <span>Grand Total:</span>
                <span>₱<?= number_format($grandTotal, 2) ?></span>
            </div>
        </div>
        
        <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
            <strong>Error:</strong> <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="payment.php">
            <input type="hidden" name="booking_data" value="<?= htmlspecialchars($rawBookingData) ?>">
            <input type="hidden" name="seat_total" value="<?= $seatTotal ?>">
            <input type="hidden" name="food_total" value="<?= $foodTotal ?>">
            <input type="hidden" name="grand_total" value="<?= $grandTotal ?>">
            <input type="hidden" name="pwd_discount" value="<?= $pwdDiscount ?>">
            <button type="submit" class="btn-proceed">Proceed to Payment →</button>
        </form>
    </div>
</body>
</html>