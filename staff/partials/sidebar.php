<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="staff-sidebar" id="staffSidebar">
  <div class="sidebar-logo">
    <img src="../images/brand x.png" alt="Ticketix">
    <span class="sidebar-logo-text">Staff Portal</span>
  </div>
  <div class="sidebar-user">
    <div class="sidebar-user-name"><?= staffName() ?></div>
    <div class="sidebar-user-role"><?= staffRole() ?></div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php" <?= $currentPage === 'dashboard.php' ? 'class="active"' : '' ?>>Dashboard</a>
    <a href="booking.php" <?= $currentPage === 'booking.php' ? 'class="active"' : '' ?>>New Booking</a>
    <a href="locations.php" <?= $currentPage === 'locations.php' ? 'class="active"' : '' ?>>Locations & Seats</a>
    <div class="nav-divider"></div>
    <a href="../TICKETIX NI CLAIRE.php" target="_blank">View Website</a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php">Sign Out</a>
  </div>
</aside>
