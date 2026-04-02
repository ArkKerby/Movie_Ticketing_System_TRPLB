<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

echo "<h1>Booking Debug Page</h1>";
echo "<style>body { font-family: Arial; padding: 20px; } pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }</style>";

echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
echo "<p><strong>User ID:</strong> " . ($userId ?? 'NOT SET') . "</p>";

echo "<h2>Database Tables Check:</h2>";
$tables = ['RESERVE', 'PAYMENT', 'TICKET', 'SEAT', 'RESERVE_SEAT', 'TICKET_FOOD'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    echo "<p>$table: " . ($result && $result->num_rows > 0 ? "✅ EXISTS" : "❌ MISSING") . "</p>";
}

echo "<h2>Recent Bookings:</h2>";
$recentReserves = $conn->query("SELECT * FROM RESERVE ORDER BY reservation_id DESC LIMIT 5");
if ($recentReserves && $recentReserves->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Reservation ID</th><th>User ID</th><th>Schedule ID</th><th>Ticket Amount</th><th>Sum Price</th><th>Food Total</th><th>Date</th></tr>";
    while ($row = $recentReserves->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['reservation_id'] . "</td>";
        echo "<td>" . $row['acc_id'] . "</td>";
        echo "<td>" . $row['schedule_id'] . "</td>";
        echo "<td>" . $row['ticket_amount'] . "</td>";
        echo "<td>" . $row['sum_price'] . "</td>";
        echo "<td>" . $row['food_total'] . "</td>";
        echo "<td>" . $row['reserve_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No reservations found.</p>";
}

echo "<h2>Recent Payments:</h2>";
$recentPayments = $conn->query("SELECT * FROM TICKET ORDER BY ticket_id DESC LIMIT 5");
if ($recentPayments && $recentPayments->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Payment ID</th><th>Reserve ID</th><th>Payment Type</th><th>Amount Paid</th><th>Status</th><th>Date</th></tr>";
    while ($row = $recentPayments->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['payment_id'] . "</td>";
        echo "<td>" . $row['reserve_id'] . "</td>";
        echo "<td>" . $row['payment_type'] . "</td>";
        echo "<td>" . $row['amount_paid'] . "</td>";
        echo "<td>" . $row['payment_status'] . "</td>";
        echo "<td>" . $row['payment_date'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No payments found.</p>";
}

echo "<h2>Total Bookings Count:</h2>";
$totalBookings = $conn->query("SELECT COUNT(*) as total FROM RESERVE");
$bookingCount = $totalBookings ? $totalBookings->fetch_assoc()['total'] : 0;
echo "<p><strong>Total:</strong> $bookingCount</p>";

echo "<h2>Total Revenue:</h2>";
$totalRevenue = $conn->query("SELECT IFNULL(SUM(amount_paid), 0) AS total FROM TICKET WHERE payment_status = 'paid'");
$revenue = $totalRevenue ? $totalRevenue->fetch_assoc()['total'] : 0;
echo "<p><strong>Total:</strong> ₱" . number_format($revenue, 2) . "</p>";

echo "<h2>Recent Tickets:</h2>";
$recentTickets = $conn->query("SELECT * FROM TICKET ORDER BY ticket_id DESC LIMIT 5");
if ($recentTickets && $recentTickets->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Ticket ID</th><th>Reserve ID</th><th>Payment ID</th><th>Ticket Number</th><th>E-Ticket Code</th><th>Status</th></tr>";
    while ($row = $recentTickets->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['ticket_id'] . "</td>";
        echo "<td>" . $row['reserve_id'] . "</td>";
        echo "<td>" . $row['payment_id'] . "</td>";
        echo "<td>" . $row['ticket_number'] . "</td>";
        echo "<td>" . ($row['e_ticket_code'] ?? 'NULL') . "</td>";
        echo "<td>" . $row['ticket_status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No tickets found.</p>";
}

$conn->close();
?>