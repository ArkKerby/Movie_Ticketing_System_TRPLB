<?php
/**
 * Send booking confirmation email to user
 * 
 * This file:
 *  1. Defines sendBookingConfirmationEmail() for use by other scripts.
 *  2. Handles direct AJAX POST requests from my-bookings.php Download button.
 */

// --- Handle AJAX POST request (from my-bookings.php "Download" button) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_id'])) {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/config.php';
    $conn = getDBConnection();

    $ticketId = intval($_POST['ticket_id']);

    // Verify the user owns this ticket (security check)
    $userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to download tickets.']);
        exit;
    }

    // Verify ticket belongs to user
    $verifyStmt = $conn->prepare("
        SELECT t.ticket_id 
        FROM TICKET t 
        JOIN RESERVE r ON t.reserve_id = r.reservation_id 
        WHERE t.ticket_id = ? AND r.acc_id = ?
    ");
    $verifyStmt->bind_param("ii", $ticketId, $userId);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();
    if ($verifyResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or access denied.']);
        $verifyStmt->close();
        exit;
    }
    $verifyStmt->close();

    // Call the function defined below
    $success = sendBookingConfirmationEmail($ticketId, $conn);

    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Your ticket receipt has been sent to your email. Please check your inbox or spam folder.']);
    } else {
        // Check if the reason is that booking is not yet approved
        $statusStmt = $conn->prepare("
            SELECT COALESCE(r.booking_status, 'approved') as booking_status
            FROM TICKET t 
            JOIN RESERVE r ON t.reserve_id = r.reservation_id 
            WHERE t.ticket_id = ?
        ");
        $statusStmt->bind_param("i", $ticketId);
        $statusStmt->execute();
        $statusRow = $statusStmt->get_result()->fetch_assoc();
        $statusStmt->close();

        if ($statusRow && $statusRow['booking_status'] !== 'approved') {
            echo json_encode(['success' => false, 'message' => 'Cannot send ticket — your booking is still pending approval.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check your email settings or try again later.']);
        }
    }
    $conn->close();
    exit;
}

// --- Function definition (used by both the handler above and other scripts) ---

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
    $seatCount = count($seats);
    $amountPaidFormatted = number_format($ticket['amount_paid'], 2);
    $refNum = htmlspecialchars($ticket['reference_number'] ?? '');
    $seatsList = implode(', ', $seats);
    $issuedDate = date('F d, Y g:i A', strtotime($ticket['date_issued'] ?? 'now'));
    $paymentType = ucfirst(str_replace('-', ' ', $ticket['payment_type'] ?? 'N/A'));

    // Build food rows HTML
    $foodRowsHtml = '';
    if (!empty($foodItems)) {
        $foodRowsHtml .= "
            <tr>
                <td colspan='2' style='padding:8px 14px;background:#e6ecff;font-size:12px;font-weight:700;color:#3b5fc0;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #d0d8f0;'>
                    Food &amp; Drinks
                </td>
            </tr>";
        $foodAlt = false;
        foreach ($foodItems as $food) {
            $foodName = htmlspecialchars($food['food_name']);
            $quantity = $food['quantity'];
            $subtotal = number_format($food['food_price'] * $quantity, 2);
            $bgColor = $foodAlt ? '#f9fafb' : '#ffffff';
            $foodRowsHtml .= "
            <tr>
                <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:$bgColor;border-bottom:1px solid #d0d8f0;'>$foodName &times;$quantity</td>
                <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:$bgColor;border-bottom:1px solid #d0d8f0;text-align:right;'>&#8369;$subtotal</td>
            </tr>";
            $foodAlt = !$foodAlt;
        }
        $foodRowsHtml .= "
            <tr>
                <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#f9fafb;border-bottom:1px solid #d0d8f0;'>Food Subtotal</td>
                <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#f9fafb;border-bottom:1px solid #d0d8f0;text-align:right;'>&#8369;" . number_format($foodTotal, 2) . "</td>
            </tr>";
    }

    // Reference number row
    $refRowHtml = '';
    if ($refNum) {
        $refRowHtml = "
            <tr>
                <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#f9fafb;border-bottom:1px solid #d0d8f0;'>Reference #</td>
                <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#f9fafb;border-bottom:1px solid #d0d8f0;text-align:right;'>$refNum</td>
            </tr>";
    }

    // QR code via public API
    $qrData = htmlspecialchars($ticket['e_ticket_code'] ?? $ticket['ticket_number'] ?? ('TICKET-' . $ticketId));
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrData);

    // Reminders list
    $reminderFood = '';
    if (!empty($foodItems)) {
        $reminderFood = "<li style='margin-bottom:6px;'>Your food orders will be ready for pickup at the designated stalls</li>";
    }

    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;'>
        <!-- Page background -->
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6fb;'>
            <tr>
                <td align='center' style='padding:30px 10px;'>

                    <!-- Card container -->
                    <table role='presentation' width='580' cellpadding='0' cellspacing='0' style='background:#ffffff;border:1px solid #d0d8f0;border-radius:8px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);'>

                        <!-- ══ HEADER BAND ══ -->
                        <tr>
                            <td style='background:#0f1a2e;padding:18px 24px;'>
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td style='color:#ffffff;font-size:20px;font-weight:700;letter-spacing:1px;'>TICKETIX</td>
                                        <td style='text-align:right;'>
                                            <span style='color:#b4c3e6;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;display:block;'>OFFICIAL RECEIPT</span>
                                            <span style='color:#ffffff;font-size:12px;font-weight:700;margin-top:2px;display:block;'>$ticketNumber</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan='2' style='padding-top:4px;'>
                                            <span style='color:#96aad2;font-size:12px;'>Online Booking Receipt</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ BLUE ACCENT STRIP ══ -->
                        <tr>
                            <td style='background:#3b5fc0;height:4px;font-size:0;line-height:0;'>&nbsp;</td>
                        </tr>

                        <!-- ══ BOOKING CONFIRMED BADGE ══ -->
                        <tr>
                            <td align='center' style='padding:22px 24px 6px;'>
                                <table role='presentation' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td style='background:#228b50;border-radius:4px;padding:8px 32px;'>
                                            <span style='color:#ffffff;font-size:13px;font-weight:700;letter-spacing:1px;'>&#10003; BOOKING CONFIRMED</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td align='center' style='padding:6px 24px 16px;'>
                                <span style='color:#788296;font-size:11px;'>Issued: $issuedDate</span>
                            </td>
                        </tr>

                        <!-- ══ GREETING ══ -->
                        <tr>
                            <td style='padding:0 24px 16px;text-align:center;'>
                                <span style='font-size:14px;color:#111827;'>Thank you for your booking, <strong>$userName</strong>!</span>
                            </td>
                        </tr>

                        <!-- ══ DASHED DIVIDER ══ -->
                        <tr>
                            <td style='padding:0 20px;'>
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr><td style='border-top:2px dashed #b4c3e6;font-size:0;line-height:0;height:1px;'>&nbsp;</td></tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ DETAIL ROWS ══ -->
                        <tr>
                            <td style='padding:14px 20px 0;'>
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #d0d8f0;border-radius:6px;overflow:hidden;'>
                                    <!-- Booking Type -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#ffffff;border-bottom:1px solid #d0d8f0;width:40%;'>Booking Type</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#ffffff;border-bottom:1px solid #d0d8f0;text-align:right;'>Client (Online)</td>
                                    </tr>
                                    <!-- Movie -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#f9fafb;border-bottom:1px solid #d0d8f0;'>Movie</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#f9fafb;border-bottom:1px solid #d0d8f0;text-align:right;'>$movieTitle</td>
                                    </tr>
                                    <!-- Branch / Cinema -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#ffffff;border-bottom:1px solid #d0d8f0;'>Branch / Cinema</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#ffffff;border-bottom:1px solid #d0d8f0;text-align:right;'>$branchName</td>
                                    </tr>
                                    <!-- Date & Time -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#f9fafb;border-bottom:1px solid #d0d8f0;'>Date &amp; Time</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#f9fafb;border-bottom:1px solid #d0d8f0;text-align:right;'>$showDate at $showTime</td>
                                    </tr>
                                    <!-- Seats -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#ffffff;border-bottom:1px solid #d0d8f0;'>Seats ($seatCount)</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#ffffff;border-bottom:1px solid #d0d8f0;text-align:right;'>$seatsList</td>
                                    </tr>

                                    <!-- Food rows (if any) -->
                                    $foodRowsHtml

                                    <!-- Payment Method -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#f9fafb;border-bottom:1px solid #d0d8f0;'>Payment Method</td>
                                        <td style='padding:8px 14px;font-size:13px;font-weight:700;color:#111827;background:#f9fafb;border-bottom:1px solid #d0d8f0;text-align:right;'>$paymentType</td>
                                    </tr>
                                    $refRowHtml
                                    <!-- Payment Status -->
                                    <tr>
                                        <td style='padding:8px 14px;font-size:13px;color:#6b7280;background:#ffffff;border-bottom:1px solid #d0d8f0;'>Payment Status</td>
                                        <td style='padding:8px 14px;background:#ffffff;border-bottom:1px solid #d0d8f0;text-align:right;'>
                                            <span style='display:inline-block;background:#228b50;color:#ffffff;font-size:10px;font-weight:700;padding:3px 12px;border-radius:3px;text-transform:uppercase;'>PAID</span>
                                        </td>
                                    </tr>
                                    <!-- Total Amount -->
                                    <tr>
                                        <td style='padding:10px 14px;font-size:14px;font-weight:700;color:#3b5fc0;background:#e6ecff;'>Total Amount Paid</td>
                                        <td style='padding:10px 14px;font-size:16px;font-weight:800;color:#0f1a2e;background:#e6ecff;text-align:right;'>&#8369;$amountPaidFormatted</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ DASHED DIVIDER ══ -->
                        <tr>
                            <td style='padding:18px 20px 0;'>
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr><td style='border-top:2px dashed #b4c3e6;font-size:0;line-height:0;height:1px;'>&nbsp;</td></tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ QR CODE SECTION ══ -->
                        <tr>
                            <td align='center' style='padding:14px 24px 4px;'>
                                <span style='font-size:10px;font-weight:700;color:#788296;text-transform:uppercase;letter-spacing:0.8px;'>SCAN QR CODE AT CINEMA ENTRANCE</span>
                            </td>
                        </tr>
                        <tr>
                            <td align='center' style='padding:10px 24px;'>
                                <img src='$qrUrl' alt='QR Code' width='160' height='160' style='display:block;border:4px solid #d0d8f0;border-radius:8px;'>
                            </td>
                        </tr>
                        <tr>
                            <td align='center' style='padding:0 24px 8px;'>
                                <span style='font-size:11px;font-weight:700;color:#3b5fc0;font-family:monospace;word-break:break-all;'>$qrData</span>
                            </td>
                        </tr>

                        <!-- ══ VIEW TICKET BUTTON ══ -->
                        <tr>
                            <td align='center' style='padding:10px 24px 18px;'>
                                <table role='presentation' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td style='background:#3b5fc0;border-radius:6px;'>
                                            <a href='$ticketUrl' style='display:inline-block;padding:12px 36px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;letter-spacing:0.5px;'>View Your Ticket Online &rarr;</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ DASHED DIVIDER ══ -->
                        <tr>
                            <td style='padding:0 20px;'>
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr><td style='border-top:2px dashed #b4c3e6;font-size:0;line-height:0;height:1px;'>&nbsp;</td></tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ══ IMPORTANT REMINDERS ══ -->
                        <tr>
                            <td style='padding:16px 24px 6px;'>
                                <span style='font-size:13px;font-weight:700;color:#111827;'>Important Reminders:</span>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding:0 24px 16px;'>
                                <ul style='margin:8px 0 0 0;padding-left:18px;color:#6b7280;font-size:12px;line-height:1.8;'>
                                    <li style='margin-bottom:6px;'>Please arrive at least 15 minutes before the show time</li>
                                    <li style='margin-bottom:6px;'>Present your QR code at the cinema entrance</li>
                                    <li style='margin-bottom:6px;'>Keep this email as your booking confirmation</li>
                                    $reminderFood
                                </ul>
                            </td>
                        </tr>

                        <!-- ══ FOOTER ══ -->
                        <tr>
                            <td style='padding:14px 24px;text-align:center;background:#f4f6fb;border-top:1px solid #d0d8f0;'>
                                <span style='font-size:11px;font-style:italic;color:#96a0b8;line-height:1.6;'>
                                    This is the official record of your booking at Ticketix Cinema.<br>
                                    Present the QR code or ticket number at the cinema entrance.
                                </span>
                            </td>
                        </tr>

                        <!-- ══ BOTTOM ACCENT STRIP ══ -->
                        <tr>
                            <td style='background:#3b5fc0;height:4px;font-size:0;line-height:0;'>&nbsp;</td>
                        </tr>
                    </table>

                    <!-- Sub-footer -->
                    <table role='presentation' width='580' cellpadding='0' cellspacing='0'>
                        <tr>
                            <td align='center' style='padding:16px 24px;'>
                                <span style='font-size:11px;color:#96a0b8;'>If you have any questions, please contact our support team.</span><br>
                                <span style='font-size:11px;color:#96a0b8;'>Thank you for choosing <strong style=\"color:#3b5fc0;\">Ticketix</strong>!</span>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
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