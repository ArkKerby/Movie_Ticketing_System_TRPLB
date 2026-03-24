<?php
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $conn = getDBConnection(); // from your config.php

    // Check if the email exists in the database
    $stmt = $conn->prepare("SELECT * FROM USER_ACCOUNT WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Generate a reset token
        $token = bin2hex(random_bytes(32));

        // Save token & expiry in a new table or reuse USER_ACCOUNT
        // For now, let's store it temporarily in USER_ACCOUNT (you can add two columns: reset_token, reset_expiry)
        $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));
        $update = $conn->prepare("UPDATE USER_ACCOUNT SET reset_token = ?, reset_expiration = ? WHERE email = ?");
        $update->bind_param("sss", $token, $expiry, $email);
        $update->execute();

        // Create the reset link (dynamically detect host so it works from any device)
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
        $resetLink = $baseUrl . "/reset_password.php?token=" . $token;

        echo "<div style='text-align:center; margin-top:50px; font-family:Arial'>";
        echo "<h2>Password Reset Link</h2>";
        echo "<p>A reset link has been generated (for demo/testing purposes):</p>";
        echo "<a href='$resetLink'>$resetLink</a>";
        echo "<br><br><a href='login.php'>Back to Login</a>";
        echo "</div>";

    } else {
        echo "<div style='text-align:center; margin-top:50px; font-family:Arial'>";
        echo "<h2>Email not found!</h2>";
        echo "<p>Please use your registered email address.</p>";
        echo "<a href='forgotpassword.php'>Try Again</a>";
        echo "</div>";
    }

    $stmt->close();
    $conn->close();
}
?>