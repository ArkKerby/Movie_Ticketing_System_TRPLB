<?php
session_start();
require_once __DIR__ . '/../config.php';

// Mark user offline
if (isset($_SESSION['user_id'])) {
    $conn = getDBConnection();
    $conn->query("UPDATE USER_ACCOUNT SET user_status = 'offline' WHERE acc_id = " . (int)$_SESSION['user_id']);
    $conn->close();
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header("Location: ../staff-login.php");
exit();
