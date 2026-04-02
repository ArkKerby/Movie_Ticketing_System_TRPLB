<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
// Auto-approve pending bookings within 1hr of showtime
require_once __DIR__ . '/auto-approve-bookings.php';
$conn = getDBConnection();

// Ensure payment_status column supports 'refunded'
$paymentStatusColumn = $conn->query("SHOW COLUMNS FROM TICKET LIKE 'payment_status'");
if ($paymentStatusColumn && $paymentStatusColumn->num_rows > 0) {
    $paymentStatusInfo = $paymentStatusColumn->fetch_assoc();
    if (strpos($paymentStatusInfo['Type'], "'refunded'") === false) {
        $conn->query("ALTER TABLE TICKET MODIFY COLUMN payment_status ENUM('paid','pending','not-yet','refunded') DEFAULT 'pending'");
    }
}

// Handle approve/decline action
$action_message = '';
$action_error = '';
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action']; // 'approve' or 'decline'
    $id = intval($_GET['id']);
    
    if (in_array($action, ['approve', 'decline'])) {
        $status = ($action === 'approve') ? 'approved' : 'declined';
        
        // Update booking status in RESERVE table
        $stmt = $conn->prepare("UPDATE RESERVE SET booking_status = ? WHERE reservation_id = ?");
        $stmt->bind_param("si", $status, $id);
        if (!$stmt->execute()) {
            $action_error = "Error updating booking: " . $conn->error;
            $stmt->close();
        } else {
            $stmt->close();
            
            // If approving, also update ticket_status to 'valid'
            if ($action === 'approve') {
                // Check if 'pending' status exists in TICKET table
                $enumCheck = $conn->query("SHOW COLUMNS FROM TICKET WHERE Field = 'ticket_status'");
                $hasPendingStatus = false;
                if ($enumCheck && $enumCheck->num_rows > 0) {
                    $enumRow = $enumCheck->fetch_assoc();
                    $enumValues = $enumRow['Type'] ?? '';
                    if (stripos($enumValues, 'pending') !== false) {
                        $hasPendingStatus = true;
                    }
                }
                
                // Update ticket status to 'valid' for approved bookings
                if ($hasPendingStatus) {
                    $ticketStmt = $conn->prepare("
                        UPDATE TICKET t
                        JOIN RESERVE r ON t.reserve_id = r.reservation_id
                        SET t.ticket_status = 'valid'
                        WHERE r.reservation_id = ? AND t.ticket_status = 'pending'
                    ");
                    $ticketStmt->bind_param("i", $id);
                    $ticketStmt->execute();
                    $ticketStmt->close();
                }
            } elseif ($action === 'decline') {
                // If declining, set ticket status to 'cancelled'
                $ticketStmt = $conn->prepare("
                    UPDATE TICKET t
                    JOIN RESERVE r ON t.reserve_id = r.reservation_id
                    SET t.ticket_status = 'cancelled'
                    WHERE r.reservation_id = ?
                ");
                $ticketStmt->bind_param("i", $id);
                $ticketStmt->execute();
                $ticketStmt->close();

                // Update payment status to refunded (payment columns now in TICKET)
                $paymentStmt = $conn->prepare("
                    UPDATE TICKET t
                    JOIN RESERVE r ON t.reserve_id = r.reservation_id
                    SET t.payment_status = 'refunded',
                        t.reference_number = CASE
                            WHEN t.reference_number IS NULL OR t.reference_number = '' THEN CONCAT('REFUND-', UUID())
                            WHEN t.reference_number LIKE 'REFUND-%' THEN t.reference_number
                            ELSE CONCAT('REFUND-', t.reference_number)
                        END,
                        t.payment_date = NOW()
                    WHERE r.reservation_id = ?
                ");
                $paymentStmt->bind_param("i", $id);
                $paymentStmt->execute();
                $paymentStmt->close();
            }
            
            // Email will be sent when the customer clicks "Download" in My Bookings
            
            $action_message = "Booking " . ($action === 'approve' ? 'approved' : 'declined') . " successfully!";
            header("Location: view-bookings.php?success=" . urlencode($action_message));
            exit();
        }
    }
}

// Handle delete old bookings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_old_bookings'])) {
    $today = date('Y-m-d');
    
    // Find old booking IDs: declined/cancelled OR past show dates
    $oldBookings = $conn->query("
        SELECT r.reservation_id
        FROM RESERVE r
        LEFT JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
        WHERE r.booking_status IN ('declined')
           OR (ms.show_date IS NOT NULL AND ms.show_date < '$today')
    ");
    
    $deletedCount = 0;
    if ($oldBookings && $oldBookings->num_rows > 0) {
        $ids = [];
        while ($row = $oldBookings->fetch_assoc()) {
            $ids[] = intval($row['reservation_id']);
        }
        $idList = implode(',', $ids);
        
        // Delete in order: TICKET_FOOD → TICKET → RESERVE_SEAT → RESERVE
        $conn->query("DELETE tf FROM TICKET_FOOD tf INNER JOIN TICKET t ON tf.ticket_id = t.ticket_id WHERE t.reserve_id IN ($idList)");
        $conn->query("DELETE FROM TICKET WHERE reserve_id IN ($idList)");
        $conn->query("DELETE FROM RESERVE_SEAT WHERE reservation_id IN ($idList)");
        $conn->query("DELETE FROM RESERVE WHERE reservation_id IN ($idList)");
        $deletedCount = count($ids);
    }
    
    header("Location: view-bookings.php?success=" . urlencode("Deleted $deletedCount old booking(s) successfully."));
    exit();
}

// Check for success message
if (isset($_GET['success'])) {
    $action_message = $_GET['success'];
}

// Check if booking_status column exists
$column_check = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
$has_booking_status = $column_check && $column_check->num_rows > 0;

// Check if food_total column exists
$food_column_check = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'food_total'");
$has_food_total = $food_column_check && $food_column_check->num_rows > 0;

// Fetch all bookings with related information
$bookingStatusSelect = $has_booking_status ? "r.booking_status" : "'pending' AS booking_status";
$foodTotalSelect = $has_food_total ? "r.food_total" : "0 AS food_total";
$bookings_query = $conn->query("
    SELECT 
        r.reservation_id,
        r.reserve_date,
        r.ticket_amount,
        r.sum_price,
        $foodTotalSelect,
        $bookingStatusSelect,
        u.firstName,
        u.lastName,
        u.email,
        m.title AS movie_title,
        ms.show_date,
        ms.show_hour,
        t2.payment_status,
        t2.amount_paid,
        t2.payment_type,
        t2.payment_date
    FROM RESERVE r
    LEFT JOIN USER_ACCOUNT u ON r.acc_id = u.acc_id
    LEFT JOIN MOVIE_SCHEDULE ms ON r.schedule_id = ms.schedule_id
    LEFT JOIN MOVIE m ON ms.movie_show_id = m.movie_show_id
    LEFT JOIN TICKET t2 ON r.reservation_id = t2.reserve_id
    ORDER BY r.reserve_date DESC
");

$bookings = [];
if ($bookings_query) {
    while ($row = $bookings_query->fetch_assoc()) {
        $bookings[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>View Bookings - Admin Panel</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .bookings-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th {
            background: #00BFFF;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-not-yet {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-declined {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-refunded {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .no-bookings {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-approve, .btn-decline {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-decline {
            background: #dc3545;
            color: white;
        }
        
        .btn-decline:hover {
            background: #c82333;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: rgba(212, 237, 218, 0.9);
            color: #155724;
            border: 1px solid rgba(195, 230, 203, 0.5);
        }
        
        .alert-error {
            background: rgba(248, 215, 218, 0.9);
            color: #721c24;
            border: 1px solid rgba(245, 198, 203, 0.5);
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="images/brand x.png" alt="Profile Picture" class="profile-pic" />
            <h2>Mall Admin</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="admin-panel.php">Dashboard</a>
            <a href="add-show.php">Add Shows</a>
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php" class="active">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="mall-admin/assign-movie.php">Assign Movies</a>
        </nav>
    </aside>
    <main class="main-content">
        <header style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h1>View <span class="highlight">Bookings</span></h1>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="delete_old_bookings" value="1">
                <button type="submit" onclick="return confirm('This will permanently delete all declined bookings and bookings with past show dates. Continue?')" style="background:#dc3545;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:0.9em;transition:background 0.3s;"> Delete Old Bookings</button>
            </form>
        </header>

        <div class="bookings-container">
            <?php if ($action_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($action_message) ?></div>
            <?php endif; ?>
            
            <?php if ($action_error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($action_error) ?></div>
            <?php endif; ?>
            
            <?php if (empty($bookings)): ?>
                <div class="no-bookings">
                    <p>No bookings found.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Reservation ID</th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Movie</th>
                            <th>Show Date & Time</th>
                            <th>Tickets</th>
                            <th>Total Price</th>
                            <th>Booking Status</th>
                            <th>Payment Status</th>
                            <th>Payment Type</th>
                            <th>Reservation Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>#<?= $booking['reservation_id'] ?></td>
                                <td><?= htmlspecialchars(($booking['firstName'] ?? '') . ' ' . ($booking['lastName'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($booking['email'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($booking['movie_title'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($booking['show_date']): ?>
                                        <?= date('M d, Y', strtotime($booking['show_date'])) ?><br>
                                        <?= date('g:i A', strtotime($booking['show_hour'])) ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?= $booking['ticket_amount'] ?? 0 ?></td>
                                <td>₱<?= number_format(($booking['sum_price'] ?? 0) + ($booking['food_total'] ?? 0), 2) ?></td>
                                <td>
                                    <?php 
                                    $bookingStatus = $booking['booking_status'] ?? 'pending';
                                    $bookingStatusClass = 'status-' . $bookingStatus;
                                    ?>
                                    <span class="status-badge <?= $bookingStatusClass ?>">
                                        <?= ucfirst($bookingStatus) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $status = $booking['payment_status'] ?? 'not-yet';
                                    $statusClass = 'status-' . str_replace('-', '-', $status);
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>">
                                        <?= ucfirst(str_replace('-', ' ', $status)) ?>
                                    </span>
                                </td>
                                <td><?= ucfirst($booking['payment_type'] ?? 'N/A') ?></td>
                                <td><?= $booking['reserve_date'] ? date('M d, Y g:i A', strtotime($booking['reserve_date'])) : 'N/A' ?></td>
                                <td>
                                    <?php if ($has_booking_status): ?>
                                        <div class="action-buttons">
                                            <?php if ($bookingStatus === 'pending'): ?>
                                                <a href="?action=approve&id=<?= $booking['reservation_id'] ?>" 
                                                   class="btn-approve" 
                                                   onclick="return confirm('Are you sure you want to approve this booking?')">Approve</a>
                                                <a href="?action=decline&id=<?= $booking['reservation_id'] ?>" 
                                                   class="btn-decline" 
                                                   onclick="return confirm('Are you sure you want to decline this booking?')">Decline</a>
                                            <?php else: ?>
                                                <span style="color: #666; font-size: 12px;">No actions available</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: #dc3545; font-size: 11px; padding: 4px;">
                                            ⚠️ Run add_booking_status_column.sql to enable actions
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>