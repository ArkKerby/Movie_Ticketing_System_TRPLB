<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Handle PWD discount application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_pwd_discount'])) {
    $userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
    $pwdIdNumber = trim($_POST['pwd_id_number'] ?? '');
    $agreement = isset($_POST['pwd_agreement']) ? 1 : 0;

    if (!$agreement) {
        $message = 'You must agree to the terms before submitting.';
        $messageType = 'error';
    } elseif (empty($pwdIdNumber)) {
        $message = 'PWD ID number is required.';
        $messageType = 'error';
    } elseif (!isset($_FILES['pwd_id_image']) || $_FILES['pwd_id_image']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please upload your PWD ID image.';
        $messageType = 'error';
    } else {
        $file = $_FILES['pwd_id_image'];
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        $allowedExts  = ['png', 'jpg', 'jpeg'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileMime = mime_content_type($file['tmp_name']);

        if (!in_array($fileMime, $allowedTypes) || !in_array($fileExt, $allowedExts)) {
            $message = 'Only PNG, JPG, and JPEG images are allowed.';
            $messageType = 'error';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = 'Image file must be 5MB or less.';
            $messageType = 'error';
        } else {
            // Check existing application
            $chkStmt = $conn->prepare("SELECT app_id, status FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'pwd' ORDER BY submitted_at DESC LIMIT 1");
            $chkStmt->bind_param("i", $userId);
            $chkStmt->execute();
            $chkResult = $chkStmt->get_result();
            $existingApp = $chkResult->fetch_assoc();
            $chkStmt->close();

            if ($existingApp && in_array($existingApp['status'], ['pending', 'approved'])) {
                $message = 'You already have a ' . $existingApp['status'] . ' application.';
                $messageType = 'error';
            } else {
                // Save file
                $uploadDir = __DIR__ . '/uploads/pwd_ids/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName  = 'pwd_' . $userId . '_' . time() . '.' . $fileExt;
                $filePath  = $uploadDir . $fileName;
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $relPath = 'uploads/pwd_ids/' . $fileName;
                    // Insert application
                    $ins = $conn->prepare("INSERT INTO DISCOUNT_APPLICATIONS (acc_id, discount_type, id_number, id_image, status) VALUES (?, 'pwd', ?, ?, 'pending')");
                    $ins->bind_param("iss", $userId, $pwdIdNumber, $relPath);
                    if ($ins->execute()) {
                        $newAppId = $conn->insert_id;
                        $ins->close();
                        // Fetch user name for notification
                        $uStmt = $conn->prepare("SELECT firstName, lastName FROM USER_ACCOUNT WHERE acc_id = ?");
                        $uStmt->bind_param("i", $userId);
                        $uStmt->execute();
                        $uRow = $uStmt->get_result()->fetch_assoc();
                        $uStmt->close();
                        $userName = trim(($uRow['firstName'] ?? '') . ' ' . ($uRow['lastName'] ?? ''));
                        $notifMsg = "User $userName has submitted a PWD discount application. PWD ID: $pwdIdNumber";
                        $tblCheck = $conn->query("SHOW TABLES LIKE 'ADMIN_NOTIFICATION'");
                        if ($tblCheck && $tblCheck->num_rows > 0) {
                            $nStmt = $conn->prepare("INSERT INTO ADMIN_NOTIFICATION (type, message, reference_id) VALUES ('pwd_application', ?, ?)");
                            $nStmt->bind_param("si", $notifMsg, $newAppId);
                            $nStmt->execute();
                            $nStmt->close();
                        }
                        $message = 'Your PWD discount application has been submitted and is under review.';
                        $messageType = 'success';
                    } else {
                        $message = 'Error submitting application: ' . $conn->error;
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Failed to upload image. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    }
}

// Handle Senior Citizen discount application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_senior_discount'])) {
    $userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
    $seniorIdNumber = trim($_POST['senior_id_number'] ?? '');
    $seniorAgreement = isset($_POST['senior_agreement']) ? 1 : 0;

    if (!$seniorAgreement) {
        $message = 'You must agree to the terms before submitting.';
        $messageType = 'error';
    } elseif (empty($seniorIdNumber)) {
        $message = 'Senior Citizen ID number is required.';
        $messageType = 'error';
    } elseif (!isset($_FILES['senior_id_image']) || $_FILES['senior_id_image']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please upload your Senior Citizen ID image.';
        $messageType = 'error';
    } else {
        // Check if PWD is already approved (can't have both)
        $pwdCheckStmt = $conn->prepare("SELECT pwd_approved FROM USER_ACCOUNT WHERE acc_id = ?");
        $pwdCheckStmt->bind_param("i", $userId);
        $pwdCheckStmt->execute();
        $pwdCheckRow = $pwdCheckStmt->get_result()->fetch_assoc();
        $pwdCheckStmt->close();

        if ($pwdCheckRow && !empty($pwdCheckRow['pwd_approved'])) {
            $message = 'You already have an approved PWD discount. You cannot have both PWD and Senior discounts.';
            $messageType = 'error';
        } else {
            $file = $_FILES['senior_id_image'];
            $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
            $allowedExts  = ['png', 'jpg', 'jpeg'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileMime = mime_content_type($file['tmp_name']);

            if (!in_array($fileMime, $allowedTypes) || !in_array($fileExt, $allowedExts)) {
                $message = 'Only PNG, JPG, and JPEG images are allowed.';
                $messageType = 'error';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $message = 'Image file must be 5MB or less.';
                $messageType = 'error';
            } else {
                $chkStmt = $conn->prepare("SELECT app_id, status FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'senior' ORDER BY submitted_at DESC LIMIT 1");
                $chkStmt->bind_param("i", $userId);
                $chkStmt->execute();
                $existingApp = $chkStmt->get_result()->fetch_assoc();
                $chkStmt->close();

                if ($existingApp && in_array($existingApp['status'], ['pending', 'approved'])) {
                    $message = 'You already have a ' . $existingApp['status'] . ' Senior Citizen application.';
                    $messageType = 'error';
                } else {
                    $uploadDir = __DIR__ . '/uploads/senior_ids/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fileName  = 'senior_' . $userId . '_' . time() . '.' . $fileExt;
                    $filePath  = $uploadDir . $fileName;
                    if (move_uploaded_file($file['tmp_name'], $filePath)) {
                        $relPath = 'uploads/senior_ids/' . $fileName;
                        $ins = $conn->prepare("INSERT INTO DISCOUNT_APPLICATIONS (acc_id, discount_type, id_number, id_image, status) VALUES (?, 'senior', ?, ?, 'pending')");
                        $ins->bind_param("iss", $userId, $seniorIdNumber, $relPath);
                        if ($ins->execute()) {
                            $newAppId = $conn->insert_id;
                            $ins->close();
                            $uStmt = $conn->prepare("SELECT firstName, lastName FROM USER_ACCOUNT WHERE acc_id = ?");
                            $uStmt->bind_param("i", $userId);
                            $uStmt->execute();
                            $uRow = $uStmt->get_result()->fetch_assoc();
                            $uStmt->close();
                            $userName = trim(($uRow['firstName'] ?? '') . ' ' . ($uRow['lastName'] ?? ''));
                            $notifMsg = "User $userName has submitted a Senior Citizen discount application. Senior ID: $seniorIdNumber";
                            $tblCheck2 = $conn->query("SHOW TABLES LIKE 'ADMIN_NOTIFICATION'");
                            if ($tblCheck2 && $tblCheck2->num_rows > 0) {
                                $nStmt = $conn->prepare("INSERT INTO ADMIN_NOTIFICATION (type, message, reference_id) VALUES ('senior_application', ?, ?)");
                                $nStmt->bind_param("si", $notifMsg, $newAppId);
                                $nStmt->execute();
                                $nStmt->close();
                            }
                            $message = 'Your Senior Citizen discount application has been submitted and is under review.';
                            $messageType = 'success';
                        } else {
                            $message = 'Error submitting application: ' . $conn->error;
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Failed to upload image. Please try again.';
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

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

// Handle payment method operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add payment method

    if (isset($_POST['add_payment_method'])) {
        $paymentType = $_POST['payment_type'] ?? '';
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        if ($isDefault) {
            $stmt = $conn->prepare("UPDATE USER_PAYMENT_METHODS SET is_default = 0 WHERE acc_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
        }

        if ($paymentType === 'gcash' || $paymentType === 'grabpay' || $paymentType === 'paymaya') {
            $numberField = $paymentType . "_number";
            $number = trim($_POST[$numberField] ?? '');
            
            if (empty($number)) {
                $message = ucfirst($paymentType) . " number is required.";
                $messageType = 'error';
            } elseif (!preg_match('/^(09\d{9}|63\d{10})$/', $number)) {
                $message = "Please enter a valid Philippine number (09XXXXXXXXX or 63XXXXXXXXXX).";
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare("INSERT INTO USER_PAYMENT_METHODS (acc_id, payment_type, gcash_number, is_default) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("issi", $userId, $paymentType, $number, $isDefault);
                if ($stmt->execute()) {
                    $message = ucfirst($paymentType) . " added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error adding " . ucfirst($paymentType) . ": " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } elseif ($paymentType === 'paypal') {
            $paypalEmail = trim($_POST['paypal_email'] ?? '');
            if (empty($paypalEmail) || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid PayPal email address.";
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare("INSERT INTO USER_PAYMENT_METHODS (acc_id, payment_type, paypal_email, is_default) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("issi", $userId, $paymentType, $paypalEmail, $isDefault);
                if ($stmt->execute()) {
                    $message = "PayPal added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error adding PayPal: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
        }
    }

    
    // Delete payment method
    if (isset($_POST['delete_payment_method'])) {
        $methodId = intval($_POST['method_id'] ?? 0);
        if ($methodId > 0) {
            $stmt = $conn->prepare("DELETE FROM USER_PAYMENT_METHODS WHERE payment_method_id = ? AND acc_id = ?");
            $stmt->bind_param("ii", $methodId, $userId);
            if ($stmt->execute()) {
                $message = "Payment method deleted successfully!";
                $messageType = 'success';
            } else {
                $message = "Error deleting payment method: " . $conn->error;
                $messageType = 'error';
            }
            $stmt->close();
        }
    }
    
    // Set default payment method
    if (isset($_POST['set_default'])) {
        $methodId = intval($_POST['method_id'] ?? 0);
        if ($methodId > 0) {
            // Remove default flag from all methods
            $stmt = $conn->prepare("UPDATE USER_PAYMENT_METHODS SET is_default = 0 WHERE acc_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
            
            // Set selected method as default
            $stmt = $conn->prepare("UPDATE USER_PAYMENT_METHODS SET is_default = 1 WHERE payment_method_id = ? AND acc_id = ?");
            $stmt->bind_param("ii", $methodId, $userId);
            if ($stmt->execute()) {
                $message = "Default payment method updated!";
                $messageType = 'success';
            } else {
                $message = "Error setting default payment method: " . $conn->error;
                $messageType = 'error';
            }
            $stmt->close();
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $fullName = trim($_POST['fullName'] ?? '');
    $contNo = trim($_POST['contNo'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = $_POST['birthdate'] ?? null;
    
    if (empty($firstName)) {
        $message = "First name is required.";
        $messageType = 'error';
    } else {
        // Check if fullName column exists in the DB
        $checkFN = $conn->query("SHOW COLUMNS FROM USER_ACCOUNT LIKE 'fullName'");
        $hasFN = $checkFN && $checkFN->num_rows > 0;

        if ($hasFN) {
            $stmt = $conn->prepare("UPDATE USER_ACCOUNT SET firstName = ?, lastName = ?, fullName = ?, contNo = ?, address = ? WHERE acc_id = ?");
            $stmt->bind_param("sssssi", $firstName, $lastName, $fullName, $contNo, $address, $userId);
        } else {
            $stmt = $conn->prepare("UPDATE USER_ACCOUNT SET firstName = ?, lastName = ?, contNo = ?, address = ? WHERE acc_id = ?");
            $stmt->bind_param("ssssi", $firstName, $lastName, $contNo, $address, $userId);
        }
        
        if ($stmt->execute()) {
            $message = "Profile updated successfully!";
            $messageType = 'success';
            // Update session: prefer fullName (display name) over firstName+lastName
            if (!empty($fullName)) {
                $_SESSION['user_name'] = $fullName;
            } else {
                $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);
            }
        } else {
            $message = "Error updating profile: " . $conn->error;
            $messageType = 'error';
        }
        $stmt->close();
    }
}

// Get user data (including fullName / display name)
$checkFN = $conn->query("SHOW COLUMNS FROM USER_ACCOUNT LIKE 'fullName'");
$hasFN = $checkFN && $checkFN->num_rows > 0;

if ($hasFN) {
    $stmt = $conn->prepare("SELECT acc_id, firstName, lastName, fullName, email, contNo, address, birthdate, time_created FROM USER_ACCOUNT WHERE acc_id = ?");
} else {
    $stmt = $conn->prepare("SELECT acc_id, firstName, lastName, email, contNo, address, birthdate, time_created FROM USER_ACCOUNT WHERE acc_id = ?");
}
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Get user payment methods
$stmt = $conn->prepare("SELECT * FROM USER_PAYMENT_METHODS WHERE acc_id = ? ORDER BY is_default DESC, created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$paymentMethodsResult = $stmt->get_result();
$paymentMethods = [];
while ($row = $paymentMethodsResult->fetch_assoc()) {
    $paymentMethods[] = $row;
}
$stmt->close();

// Get PWD application status
$pwdApp = null;
$pStmt = $conn->prepare("SELECT *, id_number AS pwd_id_number, id_image AS pwd_id_image FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'pwd' ORDER BY submitted_at DESC LIMIT 1");
$pStmt->bind_param("i", $userId);
$pStmt->execute();
$pwdApp = $pStmt->get_result()->fetch_assoc();
$pStmt->close();

// Get Senior Citizen application status
$seniorApp = null;
$sStmt = $conn->prepare("SELECT *, id_number AS senior_id_number, id_image AS senior_id_image FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'senior' ORDER BY submitted_at DESC LIMIT 1");
$sStmt->bind_param("i", $userId);
$sStmt->execute();
$seniorApp = $sStmt->get_result()->fetch_assoc();
$sStmt->close();

$conn->close();

if (!$user) {
    header("Location: login.php");
    exit();
}

// Format birthdate for input
$birthdateFormatted = $user['birthdate'] ? date('Y-m-d', strtotime($user['birthdate'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/profile.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Header Bar with Logo -->
    <div class="nav-header">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo">
        </div>
        <span class="header-title">My Profile</span>
        <a href="TICKETIX NI CLAIRE.php" class="btn-back">← Home</a>
    </div>

    <div class="profile-container">
        <div class="page-header">
            <div class="profile-avatar">
                <?php
                $initials = strtoupper(substr($user['firstName'], 0, 1) . substr($user['lastName'], 0, 1));
                echo $initials;
                ?>
            </div>
            <h1>My Profile</h1>
            <p>Manage your personal information</p>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="account-info">
            <div class="account-info-item">
                <span class="account-info-label">Email:</span>
                <span class="account-info-value"><?= htmlspecialchars($user['email']) ?></span>
            </div>
            <div class="account-info-item">
                <span class="account-info-label">Account Created:</span>
                <span class="account-info-value"><?= date('F d, Y', strtotime($user['time_created'])) ?></span>
            </div>
        </div>

        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-row">
                <div class="form-group">
                    <label for="firstName">First Name *</label>
                    <input type="text" id="firstName" name="firstName" value="<?= htmlspecialchars($user['firstName']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="lastName">Last Name (Optional)</label>
                    <input type="text" id="lastName" name="lastName" value="<?= htmlspecialchars($user['lastName'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="fullName">Display Name / Nickname</label>
                <input type="text" id="fullName" name="fullName" value="<?= htmlspecialchars($user['fullName'] ?? '') ?>" placeholder="How your name appears on the site">
                <small>This is the name shown when you're logged in. If left blank, your first and last name will be used.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="contNo">Contact Number</label>
                    <input type="text" id="contNo" name="contNo" value="<?= htmlspecialchars($user['contNo'] ?? '') ?>" placeholder="09123456789">
                </div>
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" placeholder="Enter your address"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <a href="TICKETIX NI CLAIRE.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
            </div>
        </form>

        <!-- Payment Methods Section -->
        <div class="payment-methods-section">
            <h2>Manage Payment Methods</h2>
            
            <!-- Display existing payment methods -->
            <?php if (!empty($paymentMethods)): ?>
                <div class="existing-payment-methods" style="margin-bottom: 30px;">
                    <?php foreach ($paymentMethods as $method): ?>
                        <div class="payment-method-item">
                            <div class="payment-method-row">
                                <div>
                                    <?php if ($method['payment_type'] === 'gcash'): ?>
                                        <strong> GCash</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'grabpay'): ?>
                                        <strong> GrabPay</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paymaya'): ?>
                                        <strong> PayMaya</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paypal'): ?>
                                        <strong> PayPal</strong>
                                        <div class="payment-method-detail">
                                            Email: <?= htmlspecialchars($method['paypal_email']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($method['is_default']): ?>
                                        <span class="default-badge">Default</span>
                                    <?php endif; ?>

                                </div>
                                <div class="payment-method-actions">
                                    <?php if (!$method['is_default']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Set this as default payment method?');">
                                            <input type="hidden" name="method_id" value="<?= $method['payment_method_id'] ?>">
                                            <button type="submit" name="set_default" class="btn btn-success">Set Default</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this payment method?');">
                                        <input type="hidden" name="method_id" value="<?= $method['payment_method_id'] ?>">
                                        <button type="submit" name="delete_payment_method" class="btn btn-danger">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-payment-text">No payment methods saved yet. Add one below.</p>
            <?php endif; ?>
            
            <!-- Add Payment Method Form -->
            <div class="add-payment-method">
                <h3>Add Payment Method</h3>
                <form method="POST" id="addPaymentForm">
                    <div class="form-group">
                        <label for="payment_type_select">Payment Method Type *</label>
                        <select id="payment_type_select" name="payment_type" required onchange="togglePaymentFields()">
                            <option value="">Select Payment Method</option>

                            <option value="gcash">GCash</option>
                            <option value="paypal">PayPal</option>
                            <option value="grabpay">GrabPay</option>
                            <option value="paymaya">PayMaya</option>
                        </select>
                    </div>
                    

                    
                    <!-- GCash Fields -->
                    <div id="gcash-fields" style="display: none;">
                        <div class="form-group">
                            <label for="gcash_number">Philippine Phone Number *</label>
                            <input type="text" id="gcash_number" name="gcash_number" placeholder="09123456789 or 639123456789" maxlength="13" pattern="(09[0-9]{9}|63[0-9]{10})">
                            <small>Format: 09XXXXXXXXX (11 digits, starts with 09) or 63XXXXXXXXXX (13 digits, starts with 63)</small>
                        </div>
                    </div>
                    
                    <!-- PayPal Fields -->
                    <div id="paypal-fields" style="display: none;">
                        <div class="form-group">
                            <label for="paypal_email">PayPal Email *</label>
                            <input type="email" id="paypal_email" name="paypal_email" placeholder="your.email@example.com">
                        </div>
                    </div>

                    <!-- GrabPay Fields -->
                    <div id="grabpay-fields" style="display: none;">
                        <div class="form-group">
                            <label for="grabpay_number">GrabPay Number *</label>
                            <input type="text" id="grabpay_number" name="grabpay_number" placeholder="09123456789 or 639123456789" maxlength="13" pattern="(09[0-9]{9}|63[0-9]{10})">
                            <small>Format: 09XXXXXXXXX or 63XXXXXXXXXX</small>
                        </div>
                    </div>

                    <!-- PayMaya Fields -->
                    <div id="paymaya-fields" style="display: none;">
                        <div class="form-group">
                            <label for="paymaya_number">PayMaya Number *</label>
                            <input type="text" id="paymaya_number" name="paymaya_number" placeholder="09123456789 or 639123456789" maxlength="13" pattern="(09[0-9]{9}|63[0-9]{10})">
                            <small style="color: #666;">Format: 09XXXXXXXXX or 63XXXXXXXXXX</small>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="is_default" style="margin-right: 8px; width: auto;">
                            <span>Set as default payment method</span>
                        </label>
                    </div>
                    
                    <button type="submit" name="add_payment_method" class="btn btn-primary">Add Payment Method</button>
                </form>
            </div>
        </div>

        <!-- PWD Discount Application Section -->
        <div class="pwd-section">
            <h2>PWD Discount Application</h2>
            <p class="pwd-intro">Persons with Disabilities (PWD) are entitled to a <strong>20% discount</strong> on seat prices. Submit your PWD Card / PWD ID below to apply.</p>

            <?php if ($pwdApp && $pwdApp['status'] === 'approved'): ?>
                <div class="pwd-status approved">
                    Your PWD discount (20% off seats) is <strong>active and approved</strong>. The discount will be automatically applied at checkout.
                </div>
            <?php elseif ($pwdApp && $pwdApp['status'] === 'pending'): ?>
                <div class="pwd-status pending">
                    Your PWD discount application is <strong>under review</strong>. You submitted PWD ID: <strong><?= htmlspecialchars($pwdApp['pwd_id_number']) ?></strong>. Please wait for admin approval.
                </div>
            <?php elseif ($pwdApp && $pwdApp['status'] === 'rejected'): ?>
                <div class="pwd-status rejected">
                    Your previous PWD application was <strong>rejected</strong>.
                    <?php if (!empty($pwdApp['admin_notes'])): ?>
                        Reason: <?= htmlspecialchars($pwdApp['admin_notes']) ?>
                    <?php endif; ?>
                    You may re-apply below.
                </div>
                <?php // fall through to show form ?>
            <?php endif; ?>

            <?php if (!$pwdApp || $pwdApp['status'] === 'rejected'): ?>
            <form method="POST" action="" enctype="multipart/form-data" class="pwd-form">
                <div class="form-group">
                    <label for="pwd_id_number">PWD Card / PWD ID Number *</label>
                    <input type="text" id="pwd_id_number" name="pwd_id_number" placeholder="Enter your PWD ID number (e.g. 2024-001234)" required>
                </div>

                <div class="form-group">
                    <label for="pwd_id_image">Upload PWD ID Image * (PNG, JPG, or JPEG only, max 5MB)</label>
                    <input type="file" id="pwd_id_image" name="pwd_id_image" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required>
                    <small>Only PNG, JPG, and JPEG image files are accepted. The image must clearly show your PWD ID card.</small>
                </div>

                <div class="form-group pwd-agreement">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" name="pwd_agreement" id="pwd_agreement" style="width:auto;margin-top:3px;" required>
                        <span>I certify that the information and PWD ID I am submitting is genuine and authentic. I understand that submitting false information may result in account suspension and revocation of the discount. By submitting, I give Ticketix permission to verify my PWD status.</span>
                    </label>
                </div>

                <button type="submit" name="apply_pwd_discount" class="btn btn-primary" id="pwdSubmitBtn" disabled>Submit PWD Application</button>
            </form>
            <script>
            (function(){
                var cb = document.getElementById('pwd_agreement');
                var btn = document.getElementById('pwdSubmitBtn');
                if(cb && btn){
                    cb.addEventListener('change', function(){ btn.disabled = !this.checked; });
                }
            })();
            </script>
            <?php endif; ?>
        </div>

        <!-- Senior Citizen Discount Application Section -->
        <div class="pwd-section" id="senior">
            <h2>Senior Citizen Discount Application</h2>
            <p class="pwd-intro">Senior Citizens (60 years old and above) are entitled to a <strong>20% discount</strong> on seat prices. Submit your Senior Citizen ID below to apply.</p>

            <?php if ($pwdApp && $pwdApp['status'] === 'approved'): ?>
                <div class="pwd-status pending">
                    You already have an <strong>approved PWD discount</strong>. You cannot combine PWD and Senior Citizen discounts.
                </div>
            <?php elseif ($seniorApp && $seniorApp['status'] === 'approved'): ?>
                <div class="pwd-status approved">
                    Your Senior Citizen discount (20% off seats) is <strong>active and approved</strong>. The discount will be automatically applied at checkout.
                </div>
            <?php elseif ($seniorApp && $seniorApp['status'] === 'pending'): ?>
                <div class="pwd-status pending">
                    Your Senior Citizen discount application is <strong>under review</strong>. You submitted Senior Citizen ID: <strong><?= htmlspecialchars($seniorApp['senior_id_number']) ?></strong>. Please wait for admin approval.
                </div>
            <?php elseif ($seniorApp && $seniorApp['status'] === 'rejected'): ?>
                <div class="pwd-status rejected">
                    Your previous Senior Citizen application was <strong>rejected</strong>.
                    <?php if (!empty($seniorApp['admin_notes'])): ?>
                        Reason: <?= htmlspecialchars($seniorApp['admin_notes']) ?>
                    <?php endif; ?>
                    You may re-apply below.
                </div>
            <?php endif; ?>

            <?php if (!($pwdApp && $pwdApp['status'] === 'approved') && (!$seniorApp || $seniorApp['status'] === 'rejected')): ?>
            <form method="POST" action="" enctype="multipart/form-data" class="pwd-form">
                <div class="form-group">
                    <label for="senior_id_number">Senior Citizen ID Number *</label>
                    <input type="text" id="senior_id_number" name="senior_id_number" placeholder="Enter your Senior Citizen ID number" required>
                </div>

                <div class="form-group">
                    <label for="senior_id_image">Upload Senior Citizen ID Image * (PNG, JPG, or JPEG only, max 5MB)</label>
                    <input type="file" id="senior_id_image" name="senior_id_image" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required>
                    <small>Only PNG, JPG, and JPEG image files are accepted. The image must clearly show your Senior Citizen ID.</small>
                </div>

                <div class="form-group pwd-agreement">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" name="senior_agreement" id="senior_agreement" style="width:auto;margin-top:3px;" required>
                        <span>I certify that the information and Senior Citizen ID I am submitting is genuine and authentic. I understand that submitting false information may result in account suspension and revocation of the discount. By submitting, I give Ticketix permission to verify my Senior Citizen status.</span>
                    </label>
                </div>

                <button type="submit" name="apply_senior_discount" class="btn btn-primary" id="seniorSubmitBtn" disabled>Submit Senior Citizen Application</button>
            </form>
            <script>
            (function(){
                var cb = document.getElementById('senior_agreement');
                var btn = document.getElementById('seniorSubmitBtn');
                if(cb && btn){
                    cb.addEventListener('change', function(){ btn.disabled = !this.checked; });
                }
            })();
            </script>
            <?php endif; ?>
        </div>

        <div class="text-center">
            <a href="TICKETIX NI CLAIRE.php" class="back-link">← Back to Homepage</a>
        </div>
    </div>
    
    <script>
        function togglePaymentFields() {
            const paymentType = document.getElementById('payment_type_select').value;
            document.getElementById('gcash-fields').style.display = (paymentType === 'gcash') ? 'block' : 'none';
    document.getElementById('paypal-fields').style.display = (paymentType === 'paypal') ? 'block' : 'none';
    document.getElementById('grabpay-fields').style.display = (paymentType === 'grabpay') ? 'block' : 'none';
    document.getElementById('paymaya-fields').style.display = (paymentType === 'paymaya') ? 'block' : 'none';
        }
        

        
        // Format GCash number input
        const gcashNumberInput = document.getElementById('gcash_number');
        if (gcashNumberInput) {
            gcashNumberInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '');
            });
        }
    </script>
</body>
</html>