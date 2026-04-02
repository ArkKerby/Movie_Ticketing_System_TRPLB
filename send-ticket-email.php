<?php
/**
 * Send booking confirmation email to user
 * 
 * @param int $ticketId Ticket ID
 * @param object $conn Database connection
 * @return bool Success status
 */
function sendBookingConfirmationEmail($ticketId, $conn) {
    require_once __DIR__ . '/mailer.php';
    
    // Build query dynamically depending on presence of branch_id in MOVIE_SCHEDULE
    $msBranchCheck = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
    $msHasBranch = $msBranchCheck && $msBranchCheck->num_rows > 0;
    
    $query = "
        SELECT t.*, r.*, m.title, m.image_poster, ms.show_date, ms.show_hour,
               t.payment_type, t.amount_paid,
               u.email, u.firstName, u.lastName,
               COALESCE(r.booking_status, 'approved') as booking_status
    ";
    
    if ($msHasBranch) {
        $query .= ", b.branch_name
            FROM TICKET t
            JOIN RESERVE r ON t.reserve_id = r.reservation_id
            JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
            JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
            LEFT JOIN BRANCH b ON ms.branch_id = b.branch_id
            -- payment columns now in TICKET
            JOIN USER_ACCOUNT u ON r.acc_id = u.acc_id
            WHERE t.ticket_id = ?
        ";
    } else {
        $query .= "
            FROM TICKET t
            JOIN RESERVE r ON t.reserve_id = r.reservation_id
            JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
            JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
            -- payment columns now in TICKET
            JOIN USER_ACCOUNT u ON r.acc_id = u.acc_id
            WHERE t.ticket_id = ?
        ";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        return false;
    }
    
    // Check if booking_status column exists and if booking is approved
    $bookingStatusCheck = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
    $hasBookingStatus = $bookingStatusCheck && $bookingStatusCheck->num_rows > 0;
    
    if ($hasBookingStatus) {
        // Only send email if booking is approved
        if (!isset($ticket['booking_status']) || $ticket['booking_status'] !== 'approved') {
            error_log("Booking confirmation email not sent: Booking status is not 'approved' (status: " . ($ticket['booking_status'] ?? 'not set') . ")");
            return false;
        }
    }
    
    // Get seats
    $stmt = $conn->prepare("
        SELECT rs.seat_number
        FROM RESERVE_SEAT rs
        -- seat_number now in RESERVE_SEAT
        WHERE rs.reservation_id = ?
        ORDER BY rs.seat_number ASC
    ");
    $stmt->bind_param("i", $ticket['reserve_id']);
    $stmt->execute();
    $seatsResult = $stmt->get_result();
    $seats = [];
    while ($row = $seatsResult->fetch_assoc()) {
        $seats[] = $row['seat_number'];
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
    $foodTotal = 0;
    while ($row = $foodResult->fetch_assoc()) {
        $foodItems[] = $row;
        $foodTotal += $row['food_price'] * $row['quantity'];
    }
    $stmt->close();
    
    // Format dates and times
    $showDate = date('F d, Y', strtotime($ticket['show_date']));
    $showTime = date('g:i A', strtotime($ticket['show_hour']));
    $reserveDate = date('F d, Y g:i A', strtotime($ticket['reserve_date']));
    
    // Generate ticket URL
    $ticketUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                 "://" . $_SERVER['HTTP_HOST'] . 
                 dirname($_SERVER['PHP_SELF']) . 
                 "/ticket.php?ticket_id=" . $ticketId;
    
    // Create email content
    $branchDisplay = $ticket['branch_name'] ?? 'Branch not specified';
    $userName = htmlspecialchars($ticket['firstName'] . ' ' . $ticket['lastName']);
    $movieTitle = htmlspecialchars($ticket['title']);
    $branchName = htmlspecialchars($branchDisplay);
    $ticketNumber = htmlspecialchars($ticket['ticket_number']);
    $seatsList = implode(', ', $seats);
    $paymentType = ucfirst(str_replace('-', ' ', $ticket['payment_type']));
    
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .ticket-box { background: white; border: 2px solid #667eea; border-radius: 10px; padding: 20px; margin: 20px 0; }
            .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
            .info-row:last-child { border-bottom: none; }
            .info-label { font-weight: bold; color: #666; }
            .info-value { color: #333; }
            .btn { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .food-item { background: #f0f0f0; padding: 10px; margin: 5px 0; border-radius: 5px; }
            .footer { text-align: center; color: #666; font-size: 0.9em; margin-top: 30px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎬 Booking Confirmed!</h1>
                <p>Thank you for your booking, $userName!</p>
            </div>
            <div class='content'>
                <p>Your movie ticket has been successfully booked. Here are your booking details:</p>
                
                <div class='ticket-box'>
                    <h2 style='margin-top: 0; color: #667eea;'>$movieTitle</h2>
                    <div class='info-row'>
                        <span class='info-label'>Ticket Number:</span>
                        <span class='info-value'><strong>$ticketNumber</strong></span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Branch:</span>
                        <span class='info-value'>$branchName</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Show Date:</span>
                        <span class='info-value'>$showDate</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Show Time:</span>
                        <span class='info-value'>$showTime</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Seats:</span>
                        <span class='info-value'><strong>$seatsList</strong></span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Number of Seats:</span>
                        <span class='info-value'>" . count($seats) . " seat(s)</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Payment Method:</span>
                        <span class='info-value'>$paymentType</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Total Amount:</span>
                        <span class='info-value'><strong>₱" . number_format($ticket['amount_paid'], 2) . "</strong></span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Booking Date:</span>
                        <span class='info-value'>$reserveDate</span>
                    </div>
                </div>
    ";
    
    // Add food items if any
    if (!empty($foodItems)) {
        $emailBody .= "
                <h3 style='color: #667eea; margin-top: 30px;'>Food Orders:</h3>
        ";
        foreach ($foodItems as $food) {
            $foodName = htmlspecialchars($food['food_name']);
            $quantity = $food['quantity'];
            $foodPrice = number_format($food['food_price'], 2);
            $subtotal = number_format($food['food_price'] * $quantity, 2);
            $emailBody .= "
                <div class='food-item'>
                    <strong>$foodName</strong> × $quantity = ₱$subtotal
                </div>
            ";
        }
        $emailBody .= "
                <p style='text-align: right; margin-top: 10px;'><strong>Food Total: ₱" . number_format($foodTotal, 2) . "</strong></p>
        ";
    }
    
    $emailBody .= "
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$ticketUrl' class='btn'>View Your Ticket & QR Code</a>
                </div>
                
                <p><strong>Important Reminders:</strong></p>
                <ul>
                    <li>Please arrive at least 15 minutes before the show time</li>
                    <li>Present your QR code at the cinema entrance</li>
                    <li>Keep this email as your booking confirmation</li>
    ";
    
    if (!empty($foodItems)) {
        $emailBody .= "<li>Your food orders will be ready for pickup at the designated stalls</li>";
    }
    
    $emailBody .= "
                </ul>
                
                <div class='footer'>
                    <p>If you have any questions, please contact our support team.</p>
                    <p>Thank you for choosing Ticketix!</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    try {
        // Get mailer instance - mailer.php returns a PHPMailer object
        $mail = require __DIR__ . '/mailer.php';
        
        // Clear any previous recipients
        $mail->clearAddresses();
        $mail->clearAttachments();
        
        // Generate PDF attachment
        require_once __DIR__ . '/generate-booking-pdf.php';
        $qrData = $ticket['e_ticket_code'] ?? $ticket['ticket_number'] ?? ('TICKET-' . $ticketId);
        $ticket['branch_name'] = $branchDisplay;
        $pdfPath = generateBookingPDF($ticket, $seats, $foodItems, $foodTotal, $branchDisplay, $qrData, $ticketUrl);
        if ($pdfPath && file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, 'Booking_Confirmation_' . $ticketNumber . '.pdf');
        }
        
        $mail->setFrom('ticketix0@gmail.com', 'Ticketix');
        $mail->addAddress($ticket['email'], $userName);
        $mail->Subject = "Booking Confirmation - $movieTitle - $ticketNumber";
        $mail->Body = $emailBody;
        $mail->AltBody = "Booking Confirmed!\n\nMovie: $movieTitle\nTicket Number: $ticketNumber\nShow Date: $showDate\nShow Time: $showTime\nSeats: $seatsList\nTotal: ₱" . number_format($ticket['amount_paid'], 2) . "\n\nView your ticket: $ticketUrl";
        
        $result = $mail->send();
        
        // Clean up temporary PDF file
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        // Clean up temporary PDF file on error
        if (isset($pdfPath) && $pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        return false;
    }
}