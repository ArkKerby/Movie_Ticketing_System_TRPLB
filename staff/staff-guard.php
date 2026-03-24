<?php
// Staff session guard - include at top of every staff page
session_start();
require_once __DIR__ . '/../config.php';

function requireStaff() {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header("Location: ../login.php");
        exit();
    }
    if (!in_array($_SESSION['role'] ?? '', ['staff', 'admin'])) {
        header("Location: ../login.php");
        exit();
    }
}

function staffName() {
    return htmlspecialchars($_SESSION['user_name'] ?? 'Staff');
}

function staffRole() {
    return ucfirst($_SESSION['role'] ?? 'staff');
}

// Navigation helper
function navLink($href, $icon, $label, $current) {
    $active = (basename($_SERVER['PHP_SELF']) === $href || strpos($_SERVER['PHP_SELF'], rtrim($href, '.php')) !== false) ? 'active' : '';
    echo "<a href=\"$href\" class=\"$active\">$label</a>";
}
?>
