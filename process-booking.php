<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    if (isset($_GET['debug'])) {
        die("Error: User not logged in. Session data: " . print_r($_SESSION, true));
    }
    header("Location: login.php");
    exit();
}

// Get booking data
if (!isset($_POST['booking_data'])) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

$bookingData = json_decode($_POST['booking_data'], true);
$seatTotal = floatval($_POST['seat_total'] ?? 0);
$foodTotal = floatval($_POST['food_total'] ?? 0);
$grandTotal = floatval($_POST['grand_total'] ?? 0);
// Get payment type (now includes sub-options like 'visa', 'gcash', etc.)
$paymentType = $_POST['payment_type'] ?? 'credit-card';
// Normalize and map incoming payment_type values to the database ENUM values
// Allowed DB values: 'cash', 'credit', 'e-wallet'
$dbPaymentType = $paymentType;
$pt = strtolower(trim($paymentType));

// Map known values and common variants (including saved-method markers)
$creditValues = ['visa', 'mastercard', 'amex', 'discover', 'credit-card', 'credit-card-saved', 'credit', 'card'];
$ewalletValues = ['gcash', 'paymaya', 'paypal', 'grabpay', 'e-wallet', 'ewallet'];
$cashValues = ['cash', 'cod', 'cash-on-delivery'];

if (in_array($pt, $creditValues, true)) {
    $dbPaymentType = 'credit';
} elseif (in_array($pt, $ewalletValues, true)) {
    $dbPaymentType = 'e-wallet';
} elseif (in_array($pt, $cashValues, true)) {
    $dbPaymentType = 'cash';
} else {
    // Fallback heuristics
    if (strpos($pt, 'card') !== false || strpos($pt, 'credit') !== false) {
        $dbPaymentType = 'credit';
    } elseif (strpos($pt, 'gcash') !== false || strpos($pt, 'pay') !== false || strpos($pt, 'wallet') !== false) {
        $dbPaymentType = 'e-wallet';
    } else {
        // Default to 'credit' to avoid ENUM truncation; safer than inserting an invalid value
        $dbPaymentType = 'credit';
    }
}

// Debug log mapping
error_log("Payment type mapping: input='{$paymentType}' -> db='{$dbPaymentType}'");
$referenceNumber = $_POST['reference_number'] ?? '';

// Ensure grandTotal is correct (seat + food)
// Use the passed grandTotal, but verify it matches
$calculatedTotal = $seatTotal + $foodTotal;
if (abs($grandTotal - $calculatedTotal) > 0.01) {
    // If there's a mismatch, use the calculated total
    $grandTotal = $calculatedTotal;
}

if (!$bookingData) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null; // Support both session variable names
$movieTitle = urldecode($bookingData['movie'] ?? ''); // Decode URL-encoded movie title
$branchName = urldecode($bookingData['branch'] ?? ''); // Decode URL-encoded branch name
$showTime = $bookingData['time'] ?? '';
$showDate = $bookingData['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $showDate)) {
    $showDate = date('Y-m-d');
}
$selectedSeats = $bookingData['seats'] ?? [];
$foodItems = $bookingData['food'] ?? [];

if (isset($_GET['debug'])) {
    echo "<pre>";
    echo "User ID: " . var_export($userId, true) . "\n";
    echo "Movie Title: " . var_export($movieTitle, true) . "\n";
    echo "Branch Name: " . var_export($branchName, true) . "\n";
    echo "Show Date: " . var_export($showDate, true) . "\n";
    echo "Show Time: " . var_export($showTime, true) . "\n";
    echo "Selected Seats: " . var_export($selectedSeats, true) . "\n";
    echo "Seat Total: " . var_export($seatTotal, true) . "\n";
    echo "Food Total: " . var_export($foodTotal, true) . "\n";
    echo "Grand Total: " . var_export($grandTotal, true) . "\n";
    echo "Booking Data: " . print_r($bookingData, true) . "\n";
    echo "</pre>";
}

if (!$userId || !$movieTitle || empty($selectedSeats)) {
    if (isset($_GET['debug'])) {
        die("Error: Missing required data. User ID: " . var_export($userId, true) . ", Movie: " . var_export($movieTitle, true) . ", Seats: " . count($selectedSeats));
    }
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

// Enable error reporting for debugging (only in debug mode)
if (isset($_GET['debug']) || isset($_POST['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Start transaction
$conn->begin_transaction();

try {
    // Debug: Log the start of booking process
    error_log("=== BOOKING PROCESS START ===");
    error_log("User ID: " . $userId);
    error_log("Movie Title: " . $movieTitle);
    error_log("Branch: " . $branchName);
    error_log("Show Date: " . $showDate);
    error_log("Seats: " . json_encode($selectedSeats));
    error_log("Grand Total: " . $grandTotal);
    // Get movie ID
    $stmt = $conn->prepare("SELECT movie_show_id FROM MOVIE WHERE title = ? LIMIT 1");
    $stmt->bind_param("s", $movieTitle);
    $stmt->execute();
    $result = $stmt->get_result();
    $movie = $result->fetch_assoc();
    $movieId = $movie['movie_show_id'] ?? null;
    $stmt->close();
    
    if (!$movieId) {
        throw new Exception("Movie not found");
    }
    
    // Get branch ID (required for MOVIE_SCHEDULE)
    $branchId = null;
    if ($branchName) {
        $stmt = $conn->prepare("SELECT branch_id FROM BRANCH WHERE branch_name = ? LIMIT 1");
        $stmt->bind_param("s", $branchName);
        $stmt->execute();
        $result = $stmt->get_result();
        $branch = $result->fetch_assoc();
        $branchId = $branch['branch_id'] ?? null;
        $stmt->close();
    }
    
    // Check if branch_id column exists and is required
    $column_check = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
    $has_branch_id = $column_check && $column_check->num_rows > 0;
    
    if ($has_branch_id) {
        // Get column info to check if it's nullable
        $column_info = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE WHERE Field = 'branch_id'");
        $col_data = $column_info->fetch_assoc();
        $is_nullable = ($col_data['Null'] ?? 'NO') === 'YES';
        
        // If branch_id is required (NOT NULL) and we don't have it, we need to get it or use a default
        if (!$is_nullable && !$branchId) {
            // Try to get branch_id from existing schedules for this movie
            $stmt = $conn->prepare("SELECT branch_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ? AND branch_id IS NOT NULL LIMIT 1");
            $stmt->bind_param("i", $movieId);
            $stmt->execute();
            $result = $stmt->get_result();
            $existingSchedule = $result->fetch_assoc();
            if ($existingSchedule) {
                $branchId = $existingSchedule['branch_id'];
            } else {
                // Use first available branch as fallback
                $branchResult = $conn->query("SELECT branch_id FROM BRANCH ORDER BY branch_id ASC LIMIT 1");
                if ($branchResult && $branchResult->num_rows > 0) {
                    $branchId = $branchResult->fetch_assoc()['branch_id'];
                } else {
                    throw new Exception("No branch available. Please select a branch.");
                }
            }
            $stmt->close();
        }
    }
    
    // Convert show time to TIME format (e.g., "10:30 AM" to "10:30:00")
    $timeParts = explode(' ', $showTime);
    $timeValue = $timeParts[0];
    $ampm = strtoupper($timeParts[1] ?? 'AM');
    list($hours, $minutes) = explode(':', $timeValue);
    $hours = intval($hours);
    if ($ampm === 'PM' && $hours != 12) $hours += 12;
    if ($ampm === 'AM' && $hours == 12) $hours = 0;
    $timeFormatted = sprintf("%02d:%02d:00", $hours, $minutes);
    
    // Find or create schedule
    $scheduleId = null;
    if ($has_branch_id && $branchId) {
        $stmt = $conn->prepare("SELECT schedule_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ? AND show_date = ? AND show_hour = ? AND branch_id = ? LIMIT 1");
        $stmt->bind_param("issi", $movieId, $showDate, $timeFormatted, $branchId);
    } else {
        $stmt = $conn->prepare("SELECT schedule_id FROM MOVIE_SCHEDULE WHERE movie_show_id = ? AND show_date = ? AND show_hour = ? LIMIT 1");
        $stmt->bind_param("iss", $movieId, $showDate, $timeFormatted);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule = $result->fetch_assoc();
    $scheduleId = $schedule['schedule_id'] ?? null;
    $stmt->close();
    
    // If no schedule found, create one
    if (!$scheduleId) {
        // Determine if we need to include branch_id in the INSERT
        $needsBranchId = false;
        $isNullable = true;
        
        if ($has_branch_id) {
            // Get column info to check if it's nullable
            $column_info = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE WHERE Field = 'branch_id'");
            $col_data = $column_info->fetch_assoc();
            $isNullable = ($col_data['Null'] ?? 'NO') === 'YES';
            
            // If branch_id is NOT NULL, we must include it
            if (!$isNullable) {
                $needsBranchId = true;
                // If we don't have branchId yet, we should have gotten it from the fallback above
                // But double-check here
                if (!$branchId) {
                    throw new Exception("branch_id is required but not provided. Please select a branch.");
                }
            } else {
                // If nullable, we can include it if we have it
                $needsBranchId = ($branchId !== null);
            }
        }
        
        // Prepare the INSERT statement
        if ($needsBranchId && $branchId) {
            // Include branch_id
            $stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour, branch_id) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Failed to prepare schedule insert: " . $conn->error);
            }
            $stmt->bind_param("issi", $movieId, $showDate, $timeFormatted, $branchId);
        } else {
            // Don't include branch_id (either column doesn't exist or it's nullable and we don't have a value)
            $stmt = $conn->prepare("INSERT INTO MOVIE_SCHEDULE (movie_show_id, show_date, show_hour) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Failed to prepare schedule insert: " . $conn->error);
            }
            $stmt->bind_param("iss", $movieId, $showDate, $timeFormatted);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create schedule: " . $stmt->error . ". Movie ID: $movieId, Branch ID: " . ($branchId ?? 'NULL') . ", Needs Branch ID: " . ($needsBranchId ? 'YES' : 'NO'));
        }
        $scheduleId = $conn->insert_id;
        if (!$scheduleId) {
            throw new Exception("Failed to get schedule ID. MySQL Error: " . $conn->error);
        }
        $stmt->close();
    }
    
    // Create reservation with 'pending' booking status if column exists
    $seatCount = count($selectedSeats);
    
    // Check if booking_status column exists
    $bookingStatusCheck = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
    $hasBookingStatus = $bookingStatusCheck && $bookingStatusCheck->num_rows > 0;
    
    if ($hasBookingStatus) {
        // Set booking_status to 'pending' — admin must approve
        $stmt = $conn->prepare("INSERT INTO RESERVE (acc_id, schedule_id, reserve_date, ticket_amount, sum_price, food_total, booking_status) VALUES (?, ?, NOW(), ?, ?, ?, 'pending')");
        if (!$stmt) {
            throw new Exception("Failed to prepare reservation statement: " . $conn->error);
        }
        $stmt->bind_param("iiidd", $userId, $scheduleId, $seatCount, $seatTotal, $foodTotal);
    } else {
        $stmt = $conn->prepare("INSERT INTO RESERVE (acc_id, schedule_id, reserve_date, ticket_amount, sum_price, food_total) VALUES (?, ?, NOW(), ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare reservation statement: " . $conn->error);
        }
        $stmt->bind_param("iiidd", $userId, $scheduleId, $seatCount, $seatTotal, $foodTotal);
    }
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute reservation: " . $stmt->error);
    }
    $reservationId = $conn->insert_id;
    if (!$reservationId) {
        throw new Exception("Failed to get reservation ID. MySQL Error: " . $conn->error);
    }
    $stmt->close();
    
    // Create or get seats and link to reservation
    foreach ($selectedSeats as $seatNumber) {
        // Insert directly into RESERVE_SEAT with seat_number
        $stmt = $conn->prepare("INSERT INTO RESERVE_SEAT (reservation_id, seat_number) VALUES (?, ?)");
        $stmt->bind_param("is", $reservationId, $seatNumber);
        $stmt->execute();
        $stmt->close();
    }
    
    // Create ticket with payment info (merged)
    $amountPaid = $grandTotal;
    $ticketNumber = 'TIX-' . strtoupper(substr(uniqid(), -8)) . '-' . date('Ymd');
    $eTicketCode = bin2hex(random_bytes(16));
    $ticketStatus = 'pending';
    
    $stmt = $conn->prepare("INSERT INTO TICKET (reserve_id, ticket_number, date_issued, ticket_status, e_ticket_code, payment_type, amount_paid, payment_status, payment_date, reference_number) VALUES (?, ?, NOW(), ?, ?, ?, ?, 'paid', NOW(), ?)");
    if (!$stmt) {
        throw new Exception("Failed to prepare ticket statement: " . $conn->error);
    }
    $stmt->bind_param("issssds", $reservationId, $ticketNumber, $ticketStatus, $eTicketCode, $dbPaymentType, $amountPaid, $referenceNumber);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute ticket: " . $stmt->error);
    }
    $ticketId = $conn->insert_id;
    if (!$ticketId) {
        throw new Exception("Failed to get ticket ID. MySQL Error: " . $conn->error);
    }
    $stmt->close();
    
    // Link food items to ticket
    
    foreach ($foodItems as $food) {
    if (isset($food['id']) && $food['id'] > 0 && $food['quantity'] > 0) {
        // First, try to update if the record exists
        $stmt = $conn->prepare("
            UPDATE TICKET_FOOD 
            SET quantity = quantity + ? 
            WHERE ticket_id = ? AND food_id = ?
        ");
        $stmt->bind_param("iii", $food['quantity'], $ticketId, $food['id']);
        $stmt->execute();
        
        // If no rows were updated, insert a new record
        if ($stmt->affected_rows === 0) {
            $stmt = $conn->prepare("
                INSERT INTO TICKET_FOOD (ticket_id, food_id, quantity) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmt->bind_param("iii", $ticketId, $food['id'], $food['quantity']);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception("Failed to insert ticket food item: " . $err);
            }
        }
        $stmt->close();
    }
}
    
    // Commit transaction
    if (!$conn->commit()) {
        throw new Exception("Failed to commit transaction: " . $conn->error);
    }
    
    // Debug: Log successful booking
    error_log("=== BOOKING SUCCESS ===");
    error_log("Reservation ID: " . $reservationId);
    error_log("Payment ID: " . $paymentId);
    error_log("Ticket ID: " . $ticketId);
    error_log("Amount Paid: " . $amountPaid);
    
    // Verify the data was actually saved
    $verifyReserve = $conn->query("SELECT COUNT(*) as count FROM RESERVE WHERE reservation_id = " . $reservationId);
    $verifyTicket = $conn->query("SELECT COUNT(*) as count FROM TICKET WHERE ticket_id = " . $ticketId);
    
    if ($verifyReserve && $verifyReserve->fetch_assoc()['count'] == 0) {
        throw new Exception("Reservation was not saved!");
    }
    if ($verifyTicket && $verifyTicket->fetch_assoc()['count'] == 0) {
        throw new Exception("Ticket was not saved!");
    }
    
    // Email will be sent when the customer clicks "Download" in My Bookings
    
    // Redirect to ticket page
    header("Location: ticket.php?ticket_id=" . $ticketId);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    
    // Debug: Log detailed error
    error_log("=== BOOKING ERROR ===");
    error_log("Error Message: " . $errorMsg);
    error_log("Error Code: " . $errorCode);
    error_log("MySQL Error: " . $conn->error);
    error_log("MySQL Errno: " . $conn->errno);
    error_log("Movie: " . $movieTitle);
    error_log("User: " . $userId);
    error_log("Seats Count: " . count($selectedSeats));
    
    // Show error message to user
    $_SESSION['booking_error'] = "Booking failed: " . $errorMsg;
    
    // For debugging, show the error on the page
    if (isset($_GET['debug']) || isset($_POST['debug'])) {
        die("
        <h1>Booking Error</h1>
        <p><strong>Error Message:</strong> " . htmlspecialchars($errorMsg) . "</p>
        <p><strong>MySQL Error:</strong> " . htmlspecialchars($conn->error) . "</p>
        <p><strong>Error Code:</strong> " . $errorCode . "</p>
        <p><strong>User ID:</strong> " . ($userId ?? 'NOT SET') . "</p>
        <p><strong>Movie:</strong> " . htmlspecialchars($movieTitle ?? 'NOT SET') . "</p>
        <p><strong>Seats Count:</strong> " . (isset($selectedSeats) ? count($selectedSeats) : 0) . "</p>
        <p><a href='debug-booking.php'>View Debug Page</a></p>
        ");
    }
    
    header("Location: checkout.php?error=" . urlencode("Booking failed. Please try again. Error: " . $errorMsg));
    exit();
} catch (mysqli_sql_exception $e) {
    // Rollback transaction on MySQL error
    $conn->rollback();
    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    
    // Debug: Log detailed MySQL error
    error_log("=== BOOKING MYSQL ERROR ===");
    error_log("Error Message: " . $errorMsg);
    error_log("Error Code: " . $errorCode);
    error_log("MySQL Error: " . $conn->error);
    error_log("MySQL Errno: " . $conn->errno);
    
    $_SESSION['booking_error'] = "Booking failed: " . $errorMsg;
    
    if (isset($_GET['debug'])) {
        die("MySQL Booking Error: " . $errorMsg . "<br>MySQL Error: " . $conn->error . "<br>Error Code: " . $errorCode);
    }
    
    header("Location: checkout.php?error=" . urlencode("Booking failed. Please try again. Error: " . $errorMsg));
    exit();
}