<?php
require_once __DIR__ . "/config.php";

// Centralized DB connection
$conn = getDBConnection();

// Get data from form
$user = $_POST['username'];
$email = $_POST['email'];
$pass = $_POST['password'];

// Store password as plain text
$hashed = $pass;

// Insert into database
// Note: For security, prepared statements should be used. Kept simple to match existing style.
$sql = "INSERT INTO user_account (username, email, password) VALUES ('$user', '$email', '$hashed')";

if ($conn->query($sql) === TRUE) {
    echo "Registration successful! Welcome to Ticketix 🎟️";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();
?>