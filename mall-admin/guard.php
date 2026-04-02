<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mall_admin') {
    header("Location: ../login.php");
    exit();
}
?>
