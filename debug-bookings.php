<?php
// Debug script to check if bookings and payments are being saved
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    die("Access denied. Admin only.");
}

echo "<h1>Booking & Payment Debug Info</h1>";

// Check RESERVE table
echo "<h2>RESERVE Table</h2>";
$reserveQuery = $conn->query("SELECT * FROM RESERVE ORDER BY reservation_id DESC LIMIT 10");
if ($reserveQuery) {
    echo "<p>Total Reservations: " . $reserveQuery->num_rows . "</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Schedule ID</th><th>Date</th><th>Tickets</th><th>Seat Price</th><th>Food Total</th></tr>";
    while ($row = $reserveQuery->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['reservation_id'] . "</td>";
        echo "<td>" . $row['acc_id'] . "</td>";
        echo "<td>" . $row['schedule_id'] . "</td>";
        echo "<td>" . $row['reserve_date'] . "</td>";
        echo "<td>" . $row['ticket_amount'] . "</td>";
        echo "<td>₱" . number_format($row['sum_price'], 2) . "</td>";
        echo "<td>₱" . number_format($row['food_total'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Error: " . $conn->error . "</p>";
}

// Check PAYMENT table
echo "<h2>PAYMENT Table</h2>";
$paymentQuery = $conn->query("SELECT * FROM TICKET ORDER BY ticket_id DESC LIMIT 10");
if ($paymentQuery) {
    echo "<p>Total Payments: " . $paymentQuery->num_rows . "</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Reserve ID</th><th>Type</th><th>Amount Paid</th><th>Status</th><th>Date</th></tr>";
    while ($row = $paymentQuery->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['payment_id'] . "</td>";
        echo "<td>" . $row['reserve_id'] . "</td>";
        echo "<td>" . $row['payment_type'] . "</td>";
        echo "<td>₱" . number_format($row['amount_paid'], 2) . "</td>";
        echo "<td>" . $row['payment_status'] . "</td>";
        echo "<td>" . $row['payment_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Calculate total revenue
    $revenueQuery = $conn->query("SELECT IFNULL(SUM(amount_paid), 0) AS total FROM TICKET WHERE payment_status = 'paid'");
    $revenue = $revenueQuery->fetch_assoc()['total'] ?? 0;
    echo "<p><strong>Total Revenue (paid only): ₱" . number_format($revenue, 2) . "</strong></p>";
} else {
    echo "<p>Error: " . $conn->error . "</p>";
}

// Check TICKET table
echo "<h2>TICKET Table</h2>";
$ticketQuery = $conn->query("SELECT COUNT(*) AS total FROM TICKET");
$ticketCount = $ticketQuery->fetch_assoc()['total'] ?? 0;
echo "<p>Total Tickets: " . $ticketCount . "</p>";

// Summary
echo "<h2>Summary</h2>";
$totalReservations = $conn->query("SELECT COUNT(*) AS total FROM RESERVE")->fetch_assoc()['total'] ?? 0;
$totalPayments = $conn->query("SELECT COUNT(*) AS total FROM TICKET WHERE payment_status = 'paid'")->fetch_assoc()['total'] ?? 0;
$totalRevenue = $conn->query("SELECT IFNULL(SUM(amount_paid), 0) AS total FROM TICKET WHERE payment_status = 'paid'")->fetch_assoc()['total'] ?? 0;

echo "<p><strong>Total Reservations:</strong> " . $totalReservations . "</p>";
echo "<p><strong>Total Paid Payments:</strong> " . $totalPayments . "</p>";
echo "<p><strong>Total Revenue:</strong> ₱" . number_format($totalRevenue, 2) . "</p>";

echo "<br><a href='admin-panel.php'>Back to Admin Panel</a>";
?>
