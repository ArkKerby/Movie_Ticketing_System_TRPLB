<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;

if (!$userId) {
    header("Location: login.php");
    exit();
}

$message = '';
$messageType = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = "All password fields are required.";
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = "New password and confirm password do not match.";
        $messageType = 'error';
    } elseif (strlen($newPassword) < 4) {
        $message = "New password must be at least 4 characters long.";
        $messageType = 'error';
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT user_password FROM USER_ACCOUNT WHERE acc_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && $currentPassword === $user['user_password']) {
            // Update password
            $hashedPassword = $newPassword;
            $stmt = $conn->prepare("UPDATE USER_ACCOUNT SET user_password = ? WHERE acc_id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $message = "Password changed successfully!";
                $messageType = 'success';
            } else {
                $message = "Error changing password: " . $conn->error;
                $messageType = 'error';
            }
            $stmt->close();
        } else {
            $message = "Current password is incorrect.";
            $messageType = 'error';
        }
    }
}

// Handle email change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
    $newEmail = trim($_POST['new_email'] ?? '');
    $confirmEmail = trim($_POST['confirm_email'] ?? '');
    $password = $_POST['email_password'] ?? '';
    
    if (empty($newEmail) || empty($confirmEmail) || empty($password)) {
        $message = "All email change fields are required.";
        $messageType = 'error';
    } elseif ($newEmail !== $confirmEmail) {
        $message = "Email addresses do not match.";
        $messageType = 'error';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = 'error';
    } else {
        // Verify password
        $stmt = $conn->prepare("SELECT user_password FROM USER_ACCOUNT WHERE acc_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && $password === $user['user_password']) {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT acc_id FROM USER_ACCOUNT WHERE email = ? AND acc_id != ?");
            $stmt->bind_param("si", $newEmail, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            
            if ($result->num_rows > 0) {
                $message = "This email is already registered to another account.";
                $messageType = 'error';
            } else {
                // Update email
                $stmt = $conn->prepare("UPDATE USER_ACCOUNT SET email = ? WHERE acc_id = ?");
                $stmt->bind_param("si", $newEmail, $userId);
                
                if ($stmt->execute()) {
                    $message = "Email changed successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error changing email: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = "Password is incorrect.";
            $messageType = 'error';
        }
    }
}

// Get current user data
$stmt = $conn->prepare("SELECT email FROM USER_ACCOUNT WHERE acc_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/account-settings.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Header Bar with Logo -->
    <div class="nav-header">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo">
        </div>
        <span class="header-title">Account Settings</span>
        <a href="TICKETIX NI CLAIRE.php" class="btn-back">← Home</a>
    </div>

    <div class="settings-container">
        <div class="page-header">
            <h1>Account Settings</h1>
            <p>Manage your account security and preferences</p>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Change Password Section -->
        <div class="settings-section">
            <h2>Change Password</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="current_password">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <div class="password-requirements">Must be at least 6 characters long</div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
            </form>
        </div>

        <!-- Change Email Section -->
        <div class="settings-section">
            <h2>Change Email</h2>
            <div class="current-info">
                <div class="current-info-label">Current Email:</div>
                <div class="current-info-value"><?= htmlspecialchars($user['email']) ?></div>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="new_email">New Email *</label>
                    <input type="email" id="new_email" name="new_email" required>
                </div>
                <div class="form-group">
                    <label for="confirm_email">Confirm New Email *</label>
                    <input type="email" id="confirm_email" name="confirm_email" required>
                </div>
                <div class="form-group">
                    <label for="email_password">Confirm with Password *</label>
                    <input type="password" id="email_password" name="email_password" required>
                </div>
                <button type="submit" name="change_email" class="btn btn-primary">Change Email</button>
            </form>
        </div>

        <div class="text-center">
            <a href="TICKETIX NI CLAIRE.php" class="back-link">← Back to Homepage</a>
        </div>
    </div>
</body>
</html>

