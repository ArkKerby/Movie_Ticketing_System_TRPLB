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

// Read flash message from session (Post/Redirect/Get pattern)
if (isset($_SESSION['profile_message'])) {
    $message = $_SESSION['profile_message'];
    $messageType = $_SESSION['profile_message_type'] ?? 'success';
    unset($_SESSION['profile_message'], $_SESSION['profile_message_type']);
}

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

// Handle PWD discount application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pwd_application'])) {
    $pwdIdNumber = trim($_POST['pwd_id_number'] ?? '');
    
    if (empty($pwdIdNumber)) {
        $message = "PWD ID Number is required.";
        $messageType = 'error';
    } elseif (empty($_FILES['pwd_id_image']['name'])) {
        $message = "PWD ID Photo is required.";
        $messageType = 'error';
    } else {
        // Upload image
        $uploadDir = __DIR__ . '/uploads/pwd/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['pwd_id_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            $message = "Invalid image format. Please upload JPG, PNG, GIF, or WebP.";
            $messageType = 'error';
        } else {
            $filename = 'pwd_' . $userId . '_' . date('YmdHis') . '.' . $ext;
            $destPath = $uploadDir . $filename;
            $dbImagePath = 'uploads/pwd/' . $filename;

            if (move_uploaded_file($_FILES['pwd_id_image']['tmp_name'], $destPath)) {
                // Check if PWD_APPLICATIONS table exists
                $tableCheck = $conn->query("SHOW TABLES LIKE 'PWD_APPLICATIONS'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    // Check for existing pending application
                    $checkStmt = $conn->prepare("SELECT pwd_app_id FROM PWD_APPLICATIONS WHERE acc_id = ? AND status = 'pending' LIMIT 1");
                    $checkStmt->bind_param("i", $userId);
                    $checkStmt->execute();
                    $existingApp = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    if ($existingApp) {
                        $message = "You already have a pending PWD application. Please wait for admin review.";
                        $messageType = 'error';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO PWD_APPLICATIONS (acc_id, pwd_id_number, pwd_id_image, status, submitted_at) VALUES (?, ?, ?, 'pending', NOW())");
                        $stmt->bind_param("iss", $userId, $pwdIdNumber, $dbImagePath);
                        if ($stmt->execute()) {
                            $pwdAppId = $conn->insert_id;
                            // Create admin notification
                            $notifTableCheck = $conn->query("SHOW TABLES LIKE 'ADMIN_NOTIFICATIONS'");
                            if ($notifTableCheck && $notifTableCheck->num_rows > 0) {
                                $notifMsg = "New PWD discount application submitted by user #$userId (PWD ID: $pwdIdNumber)";
                                $notifType = 'pwd_application';
                                $nStmt = $conn->prepare("INSERT INTO ADMIN_NOTIFICATIONS (type, message, reference_id, created_at) VALUES (?, ?, ?, NOW())");
                                $nStmt->bind_param("ssi", $notifType, $notifMsg, $pwdAppId);
                                $nStmt->execute();
                                $nStmt->close();
                            }
                            $message = "PWD discount application submitted successfully! Admin will review it shortly.";
                            $messageType = 'success';
                        } else {
                            $message = "Error submitting application: " . $conn->error;
                            $messageType = 'error';
                        }
                        $stmt->close();
                    }
                } else {
                    $message = "PWD applications feature is not yet set up. Please contact admin.";
                    $messageType = 'error';
                }
            } else {
                $message = "Error uploading image. Please try again.";
                $messageType = 'error';
            }
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

// After any POST, redirect to prevent resubmission on refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message) {
    $_SESSION['profile_message'] = $message;
    $_SESSION['profile_message_type'] = $messageType;
    header("Location: profile.php");
    exit();
}

// Get user data (including fullName / display name)
$stmt = $conn->prepare("SELECT acc_id, firstName, lastName, fullName, email, contNo, address, birthdate, time_created FROM USER_ACCOUNT WHERE acc_id = ?");
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
$pwdStatus = null;
$pwdApp = null;
$tableCheck = $conn->query("SHOW TABLES LIKE 'PWD_APPLICATIONS'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT * FROM PWD_APPLICATIONS WHERE acc_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $pwdApp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($pwdApp) {
        $pwdStatus = $pwdApp['status'];
    }
}

// Check if user has pwd_approved flag
$pwdApproved = false;
$colCheck = $conn->query("SHOW COLUMNS FROM USER_ACCOUNT LIKE 'pwd_approved'");
if ($colCheck && $colCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT pwd_approved FROM USER_ACCOUNT WHERE acc_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $pwdRow = $stmt->get_result()->fetch_assoc();
    $pwdApproved = ($pwdRow['pwd_approved'] ?? 0) == 1;
    $stmt->close();
}

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

        <form method="POST" action="">
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
                                        <strong>📱 GCash</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'grabpay'): ?>
                                        <strong>📱 GrabPay</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paymaya'): ?>
                                        <strong>📱 PayMaya</strong>
                                        <div class="payment-method-detail">
                                            Number: <?= htmlspecialchars($method['gcash_number']) ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paypal'): ?>
                                        <strong>📧 PayPal</strong>
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
        <div class="payment-methods-section" style="margin-top:30px;">
            <h2>PWD Discount Application</h2>
            <p style="color:rgba(255,255,255,0.5);font-size:0.9rem;margin-bottom:20px;">Submit your PWD ID to receive a 20% discount on seat prices. Admin will review your application.</p>

            <?php if ($pwdApproved): ?>
                <div style="background:rgba(46,204,113,0.12);border:1px solid rgba(46,204,113,0.4);border-radius:10px;padding:20px;text-align:center;">
                    <div style="font-size:28px;margin-bottom:8px;">✓</div>
                    <div style="font-size:16px;font-weight:700;color:#2ecc71;margin-bottom:4px;">PWD Discount Active</div>
                    <div style="font-size:13px;color:rgba(255,255,255,0.5);">Your PWD discount (20% off seats) is applied automatically at checkout.</div>
                    <?php if ($pwdApp): ?>
                        <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:8px;">PWD ID: <?= htmlspecialchars($pwdApp['pwd_id_number']) ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($pwdStatus === 'pending'): ?>
                <div style="background:rgba(255,193,7,0.12);border:1px solid rgba(255,193,7,0.3);border-radius:10px;padding:20px;text-align:center;">
                    <div style="font-size:28px;margin-bottom:8px;">⏳</div>
                    <div style="font-size:16px;font-weight:700;color:#ffd966;margin-bottom:4px;">Application Pending</div>
                    <div style="font-size:13px;color:rgba(255,255,255,0.5);">Your PWD discount application is being reviewed by admin.</div>
                    <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:8px;">PWD ID: <?= htmlspecialchars($pwdApp['pwd_id_number']) ?> · Submitted: <?= date('M d, Y', strtotime($pwdApp['submitted_at'])) ?></div>
                </div>
            <?php elseif ($pwdStatus === 'rejected'): ?>
                <div style="background:rgba(244,67,54,0.1);border:1px solid rgba(244,67,54,0.3);border-radius:10px;padding:16px;margin-bottom:20px;text-align:center;">
                    <div style="font-size:14px;color:#ff8a8a;font-weight:600;">Previous application was rejected</div>
                    <?php if (!empty($pwdApp['admin_notes'])): ?>
                        <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:4px;">Reason: <?= htmlspecialchars($pwdApp['admin_notes']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:4px;">You may resubmit below.</div>
                </div>

                <div class="add-payment-method">
                    <h3>Resubmit PWD Application</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="pwd_id_number">PWD ID Number *</label>
                            <input type="text" id="pwd_id_number" name="pwd_id_number" placeholder="Enter your PWD ID Number" required>
                        </div>
                        <div class="form-group">
                            <label for="pwd_id_image">PWD ID Photo *</label>
                            <input type="file" id="pwd_id_image" name="pwd_id_image" accept="image/*" required onchange="previewPwdImg(this)" style="color:rgba(255,255,255,0.7);">
                            <small>Upload a clear photo of your PWD ID card</small>
                            <div id="pwdImgPreview" style="display:none;margin-top:10px;text-align:center;">
                                <img id="pwdPreviewImg" src="" alt="Preview" style="max-width:100%;max-height:180px;border-radius:8px;border:2px solid rgba(85,138,206,0.4);">
                            </div>
                        </div>
                        <button type="submit" name="submit_pwd_application" class="btn btn-primary">Submit PWD Application</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="add-payment-method">
                    <h3>Apply for PWD Discount</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="pwd_id_number">PWD ID Number *</label>
                            <input type="text" id="pwd_id_number" name="pwd_id_number" placeholder="Enter your PWD ID Number" required>
                        </div>
                        <div class="form-group">
                            <label for="pwd_id_image">PWD ID Photo *</label>
                            <input type="file" id="pwd_id_image" name="pwd_id_image" accept="image/*" required onchange="previewPwdImg(this)" style="color:rgba(255,255,255,0.7);">
                            <small>Upload a clear photo of your PWD ID card</small>
                            <div id="pwdImgPreview" style="display:none;margin-top:10px;text-align:center;">
                                <img id="pwdPreviewImg" src="" alt="Preview" style="max-width:100%;max-height:180px;border-radius:8px;border:2px solid rgba(85,138,206,0.4);">
                            </div>
                        </div>
                        <button type="submit" name="submit_pwd_application" class="btn btn-primary">Submit PWD Application</button>
                    </form>
                </div>
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

        // PWD ID image preview
        function previewPwdImg(input) {
            const preview = document.getElementById('pwdImgPreview');
            const img = document.getElementById('pwdPreviewImg');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    img.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }
    </script>
</body>
</html>

