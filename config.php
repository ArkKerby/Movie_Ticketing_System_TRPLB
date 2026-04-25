<?php
require_once __DIR__ . '/load_env.php';
// Load environment variables from .env if present
ticketix_load_env(__DIR__ . '/.env');

// Database configuration (env first, fallback to local defaults)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
// MySQL root password - update this if your MySQL root password is different
define('DB_PASSWORD', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '062006');
define('DB_NAME', getenv('DB_NAME') ?: 'ticketix');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);

// Function to create database connection
function getDBConnection() {
	$conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
	
	if ($conn->connect_error) {
		if ($conn->connect_errno == 1049) {
			die("Database '" . DB_NAME . "' not found! Please run setup_database.php first to create the database.");
		} else {
			die("Connection failed: " . $conn->connect_error);
		}
	}
	
	return $conn;
}
?>