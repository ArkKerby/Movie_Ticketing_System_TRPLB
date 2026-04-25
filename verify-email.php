<?php
require_once __DIR__ . '/config.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

function respond($message, $success = false) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Email Verification</title></head><body style="background:black;color:white;font-family:Montserrat,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">
    <div style="background:linear-gradient(to bottom,#00BFFF,#3C50B2);padding:30px;border-radius:10px;width:360px;text-align:center;">
    <h2>' . ($success ? 'Email Verified' : 'Verification') . '</h2>
    <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
    <p><a href="login.php" style="color:white;text-decoration:underline;">Go to Login</a></p>
    </div></body></html>';
    exit;
}

if ($token === '') {
    respond('Invalid or missing token.');
}

$conn = getDBConnection();

// Find token record that is not used and not expired
$stmt = $conn->prepare("SELECT id, acc_id, expires_at, used_at FROM EMAIL_VERIFICATION WHERE token = ? LIMIT 1");
if (!$stmt) {
    respond('Server error. Please try again later.');
}
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    respond('Invalid verification link or already used.');
}

if (!empty($row['used_at'])) {
    respond('This verification link has already been used. You can log in now.', true);
}

if (strtotime($row['expires_at']) < time()) {
    respond('This verification link has expired. Please sign in and request a new verification link.');
}

// Mark as used
$now = date('Y-m-d H:i:s');
$upd = $conn->prepare('UPDATE EMAIL_VERIFICATION SET used_at = ? WHERE id = ?');
if ($upd) {
    $upd->bind_param('si', $now, $row['id']);
    $upd->execute();
    $upd->close();
}

// Optional: set user_status to online/active or similar if needed
// $conn->query("UPDATE USER_ACCOUNT SET user_status = 'active' WHERE acc_id = " . (int)$row['acc_id']);

$conn->close();

respond('Your email has been verified successfully. You can now log in.', true);

