<?php
require_once __DIR__ . '/staff-guard.php';
requireStaff();

if (!isset($_SESSION['staff_booking']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: booking.php");
    exit();
}

$conn = getDBConnection();

$staffBooking = $_SESSION['staff_booking'];
$bookingData  = json_decode($staffBooking['booking_data'], true);
$seatTotal    = floatval($staffBooking['seat_total']);
$foodTotal    = floatval($staffBooking['food_total']);
$grandTotal   = floatval($staffBooking['grand_total']);

$paymentType  = $_POST['payment_type'] ?? 'cash';
$refNumber    = $_POST['reference_number'] ?? '';
$ewalletProv  = $_POST['ewallet_provider'] ?? '';
$cashReceived = floatval($_POST['cash_received'] ?? 0);

// PWD Discount handling
$pwdDiscount   = isset($_POST['pwd_discount']) ? 1 : 0;
$pwdIdNumber   = trim($_POST['pwd_id_number'] ?? '');
$pwdIdImagePath = '';

// Apply PWD/Senior discount (20% off 1 seat only — the cheapest seat price)
if ($pwdDiscount) {
    // Determine the cheapest seat price from booking data
    $seatsData = $bookingData['seatsData'] ?? [];
    if (!empty($seatsData)) {
        $prices = array_map(fn($s) => floatval($s['price'] ?? 0), $seatsData);
        $cheapestSeatPrice = min($prices);
    } elseif (count($bookingData['seats'] ?? []) > 0) {
        // Fallback: use average seat price
        $cheapestSeatPrice = $seatTotal / count($bookingData['seats']);
    } else {
        $cheapestSeatPrice = 0;
    }
    $pwdDiscountAmount = $cheapestSeatPrice * 0.20;
    $grandTotal = ($seatTotal - $pwdDiscountAmount) + $foodTotal;
}

// Build reference number
if ($paymentType === 'cash') {
    $refNumber = 'CASH-' . date('YmdHis');
    $dbPaymentType = 'cash';
} else {
    $dbPaymentType = 'e-wallet';
    if ($ewalletProv && !$refNumber) {
        $refNumber = strtoupper($ewalletProv) . '-' . date('YmdHis');
    }
    // Prefix reference number with provider if not already prefixed
    if ($ewalletProv && $refNumber && stripos($refNumber, $ewalletProv) === false) {
        $refNumber = strtoupper($ewalletProv) . '-' . $refNumber;
    }
}

$movieTitle    = urldecode($bookingData['movie'] ?? '');
$branchName    = urldecode($bookingData['branch'] ?? '');
$showTime      = $bookingData['time'] ?? '';
$showDate      = $bookingData['date'] ?? date('Y-m-d');
$selectedSeats = $bookingData['seats'] ?? [];
$foodItems     = $bookingData['food'] ?? [];

// Use walk-in customer account — auto-create if it doesn't exist
$stmt = $conn->prepare("SELECT acc_id FROM USER_ACCOUNT WHERE email = 'walkin@ticketix.staff' LIMIT 1");
$stmt->execute();
$res = $stmt->get_result();
$walkinUser = $res->fetch_assoc();
$userId = $walkinUser['acc_id'] ?? null;
$stmt->close();

if (!$userId) {
    // Auto-create the walk-in placeholder account
    $stmt = $conn->prepare("INSERT INTO USER_ACCOUNT (fullName, firstName, lastName, email, user_password, time_created, role) VALUES ('Walk-In Customer', 'Walk-In', 'Customer', 'walkin@ticketix.staff', '', NOW(), 'walkin')");
    $stmt->execute();
    $userId = $conn->insert_id;
    $stmt->close();
}

if (!$userId || empty($selectedSeats) || !$movieTitle) {
    $_SESSION['staff_error'] = "Booking failed: Missing required data.";
    header("Location: payment.php");
    exit();
}

$conn->begin_transaction();

try {
    // Get movie
    $stmt = $conn->prepare("SELECT movie_show_id FROM MOVIE WHERE title = ? LIMIT 1");
    $stmt->bind_param("s", $movieTitle);
    $stmt->execute();
    $mov = $stmt->get_result()->fetch_assoc();
    $movieId = $mov['movie_show_id'] ?? null;
    $stmt->close();
    if (!$movieId) throw new Exception("Movie not found: $movieTitle");

    // Get branch
    $stmt = $conn->prepare("SELECT branch_id FROM BRANCH WHERE branch_name = ? LIMIT 1");
    $stmt->bind_param("s", $branchName);
    $stmt->execute();
    $br = $stmt->get_result()->fetch_assoc();
    $branchId = $br['branch_id'] ?? null;
    $stmt->close();
    if (!$branchId) throw new Exception("Branch not found: $branchName");

    // Convert time
    $tp = date_parse($showTime);
    $timeFormatted = sprintf("%02d:%02d:00", $tp['hour'], $tp['minute'] ?? 0);

    // Find or create schedule
    $stmt = $conn->prepare("SELECT schedule_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ? AND branch_id = ? AND show_date = ? AND TIME(show_hour) = TIME(?) LIMIT 1");
    $stmt->bind_param("iiss", $movieId, $branchId, $showDate, $timeFormatted);
    $stmt->execute();
    $sched = $stmt->get_result()->fetch_assoc();
    $scheduleId = $sched['schedule_id'] ?? null;
    $stmt->close();

    if (!$scheduleId) {
        $stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour, branch_id) VALUES (?,?,?,?)");
        $stmt->bind_param("issi", $movieId, $showDate, $timeFormatted, $branchId);
        $stmt->execute();
        $scheduleId = $conn->insert_id;
        $stmt->close();
        if (!$scheduleId) throw new Exception("Failed to create schedule");
    }

    // Create reservation
    $seatCount = count($selectedSeats);
    $bookingType = 'walk-in';
    $stmt = $conn->prepare("INSERT INTO RESERVE (acc_id, schedule_id, reserve_date, ticket_amount, sum_price, food_total, booking_status, booking_type, pwd_discount, pwd_id_number, pwd_id_image) VALUES (?,?,NOW(),?,?,?,'approved',?,?,?,?)");
    $stmt->bind_param("iiidd" . "siss", $userId, $scheduleId, $seatCount, $seatTotal, $foodTotal, $bookingType, $pwdDiscount, $pwdIdNumber, $pwdIdImagePath);
    $stmt->execute();
    $reservationId = $conn->insert_id;
    $stmt->close();
    if (!$reservationId) throw new Exception("Failed to create reservation");

    // Create / link seats (RESERVE_SEAT stores seat_number directly)
    foreach ($selectedSeats as $seatNumber) {
        $stmt = $conn->prepare("INSERT INTO RESERVE_SEAT (reservation_id, seat_number) VALUES (?,?)");
        $stmt->bind_param("is", $reservationId, $seatNumber);
        $stmt->execute();
        $stmt->close();
    }

    // Create ticket (payment fields are on the TICKET table)
    $amountPaid   = $grandTotal;
    $ticketNumber = 'TIX-' . strtoupper(substr(uniqid(), -8)) . '-' . date('Ymd');
    $eTicketCode  = bin2hex(random_bytes(16));
    $ticketStatus = 'valid';

    $stmt = $conn->prepare("INSERT INTO TICKET (reserve_id, ticket_number, date_issued, ticket_status, e_ticket_code, payment_type, amount_paid, payment_status, payment_date, reference_number) VALUES (?,?,NOW(),?,?,?,?,'paid',NOW(),?)");
    $stmt->bind_param("issssds", $reservationId, $ticketNumber, $ticketStatus, $eTicketCode, $dbPaymentType, $amountPaid, $refNumber);
    if (!$stmt->execute()) throw new Exception("Ticket insert failed: " . $stmt->error);
    $ticketId = $conn->insert_id;
    $stmt->close();

    // Link food items
    foreach ($foodItems as $food) {
        if (isset($food['id']) && $food['id'] > 0 && $food['quantity'] > 0) {
            $qty = intval($food['quantity']);
            $fid = intval($food['id']);
            $stmt = $conn->prepare("INSERT INTO TICKET_FOOD (ticket_id, food_id, quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
            $stmt->bind_param("iiii", $ticketId, $fid, $qty, $qty);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();

    // If PWD discount was applied, create a PWD_APPLICATIONS record for admin review
    if ($pwdDiscount && $pwdIdNumber) {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'PWD_APPLICATIONS'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $pwdStmt = $conn->prepare("INSERT INTO PWD_APPLICATIONS (acc_id, pwd_id_number, pwd_id_image, status, submitted_at) VALUES (?, ?, '', 'pending', NOW())");
            $pwdStmt->bind_param("is", $userId, $pwdIdNumber);
            $pwdStmt->execute();
            $pwdStmt->close();
        }
    }

    // Store change amount and ticket id
    $_SESSION['staff_last_ticket_id'] = $ticketId;
    $_SESSION['staff_last_change']    = ($paymentType === 'cash') ? max(0, $cashReceived - $grandTotal) : null;
    $_SESSION['staff_last_payment']   = $paymentType;
    unset($_SESSION['staff_booking']);

    header("Location: booking-confirmation.php?ticket_id=$ticketId");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['staff_error'] = "Booking failed: " . $e->getMessage();
    header("Location: payment.php");
    exit();
}
