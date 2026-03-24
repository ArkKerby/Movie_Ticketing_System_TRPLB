<?php
// One-time script to set staff password
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$hash = password_hash('Staff@2024!', PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE USER_ACCOUNT SET user_password = ? WHERE email = 'kevinlaguador16@gmail.com'");
$stmt->bind_param("s", $hash);
$ok = $stmt->execute();
echo $ok ? " Staff password updated! Hash: $hash" : "gi Failed: " . $stmt->error;
$stmt->close();

// Verify
$r = $conn->query("SELECT acc_id, firstName, lastName, email, role FROM USER_ACCOUNT WHERE role IN ('staff','walkin')");
echo "<br><br><strong>Staff accounts:</strong><br><pre>";
while ($row = $r->fetch_assoc())
    print_r($row);
echo "</pre>";

// Verify email verification exists
$r2 = $conn->query("SELECT ev.id, ua.email, ev.used_at FROM email_verifications ev JOIN USER_ACCOUNT ua ON ev.acc_id = ua.acc_id WHERE ua.role IN ('staff','walkin')");
echo "<strong>Email verifications:</strong><br><pre>";
while ($row = $r2->fetch_assoc())
    print_r($row);
echo "</pre>";
$conn->close();
?>
