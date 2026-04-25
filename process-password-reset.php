<?php
require_once __DIR__ . "/config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$passwordConfirmation = isset($_POST['password_confirmation']) ? $_POST['password_confirmation'] : '';

if ($token === '' || $password === '' || $passwordConfirmation === '') {
    die("Missing fields.");
}

if ($password !== $passwordConfirmation) {
    die("Passwords do not match.");
}

$tokenHash = hash('sha256', $token);

$mysqli = getDBConnection();

// Find user by token and ensure not expired
$selectSql = "SELECT acc_id, reset_token_expires_at FROM USER_ACCOUNT WHERE reset_token_hash = ? LIMIT 1";
$selectStmt = $mysqli->prepare($selectSql);
$selectStmt->bind_param("s", $tokenHash);
$selectStmt->execute();
$result = $selectStmt->get_result();
$user = $result ? $result->fetch_assoc() : null;

if (!$user) {
    die("Invalid or used reset token.");
}

if (isset($user['reset_token_expires_at']) && strtotime($user['reset_token_expires_at']) <= time()) {
    die("Reset token has expired.");
}

$passwordHash = $password;

// Update password and clear token fields atomically
$updateSql = "UPDATE USER_ACCOUNT
              SET user_password = ?, reset_token_hash = NULL, reset_token_expires_at = NULL
              WHERE reset_token_hash = ?";
$updateStmt = $mysqli->prepare($updateSql);
$updateStmt->bind_param("ss", $passwordHash, $tokenHash);
$ok = $updateStmt->execute();

if (!$ok || $updateStmt->affected_rows !== 1) {
    die("Failed to update password.");
}

// Redirect to the desired page after success
header("Location: TICKETIX NI CLAIRE.php");
exit;