<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

// Get booking data from POST
if (!isset($_POST['booking_data'])) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

$bookingData = json_decode($_POST['booking_data'], true);
$seatTotal = floatval($_POST['seat_total'] ?? 0);
$foodTotal = floatval($_POST['food_total'] ?? 0);
$grandTotal = floatval($_POST['grand_total'] ?? 0);

if (!$bookingData) {
    header("Location: TICKETIX NI CLAIRE.php");
    exit();
}

$movieTitle = $bookingData['movie'] ?? '';
$branchName = $bookingData['branch'] ?? '';

// Get user ID
$userId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;

$message = '';
$messageType = '';

// Check for session messages
if (isset($_SESSION['payment_success_message'])) {
    $message = $_SESSION['payment_success_message'];
    $messageType = 'success';
    unset($_SESSION['payment_success_message']);
}
if (isset($_SESSION['payment_error_message'])) {
    $message = $_SESSION['payment_error_message'];
    $messageType = 'error';
    unset($_SESSION['payment_error_message']);
}

// Handle adding payment method
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment_method_checkout'])) {
    $paymentType = $_POST['payment_type'] ?? '';
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    
    // Store booking data for redirect
    $bookingDataForRedirect = $_POST['booking_data'];
    $seatTotalForRedirect = $_POST['seat_total'];
    $foodTotalForRedirect = $_POST['food_total'];
    $grandTotalForRedirect = $_POST['grand_total'];
    
    if ($isDefault) {
        $stmt = $conn->prepare("UPDATE USER_PAYMENT_METHODS SET is_default = 0 WHERE acc_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }

    $success = false;
    $errorMsg = '';

    if ($paymentType === 'gcash' || $paymentType === 'grabpay' || $paymentType === 'paymaya') {
        $numberField = $paymentType . "_number";
        $number = trim($_POST[$numberField] ?? '');
        
        if (empty($number)) {
            $errorMsg = ucfirst($paymentType) . " number is required.";
        } elseif (!preg_match('/^(09\d{9}|63\d{10})$/', $number)) {
            $errorMsg = "Please enter a valid Philippine number (09XXXXXXXXX or 63XXXXXXXXXX).";
        } else {
            $stmt = $conn->prepare("INSERT INTO USER_PAYMENT_METHODS (acc_id, payment_type, gcash_number, is_default) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $userId, $paymentType, $number, $isDefault);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $errorMsg = "Error adding " . ucfirst($paymentType) . ": " . $conn->error;
            }
            $stmt->close();
        }
    } elseif ($paymentType === 'paypal') {
        $paypalEmail = trim($_POST['paypal_email'] ?? '');
        if (empty($paypalEmail) || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Please enter a valid PayPal email address.";
        } else {
            $stmt = $conn->prepare("INSERT INTO USER_PAYMENT_METHODS (acc_id, payment_type, paypal_email, is_default) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $userId, $paymentType, $paypalEmail, $isDefault);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $errorMsg = "Error adding PayPal: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    $conn->close();
    
    // Redirect to prevent duplicate submissions
    if ($success) {
        $_SESSION['payment_success_message'] = ucfirst($paymentType) . " added successfully! Please select it below.";
        // Redirect back to payment.php with booking data
        echo '<form id="redirectForm" method="POST" action="payment.php">
                <input type="hidden" name="booking_data" value="' . htmlspecialchars($bookingDataForRedirect) . '">
                <input type="hidden" name="seat_total" value="' . $seatTotalForRedirect . '">
                <input type="hidden" name="food_total" value="' . $foodTotalForRedirect . '">
                <input type="hidden" name="grand_total" value="' . $grandTotalForRedirect . '">
              </form>
              <script>document.getElementById("redirectForm").submit();</script>';
        exit();
    } else {
        $_SESSION['payment_error_message'] = $errorMsg;
        // Redirect back to payment.php with booking data and error
        echo '<form id="redirectForm" method="POST" action="payment.php">
                <input type="hidden" name="booking_data" value="' . htmlspecialchars($bookingDataForRedirect) . '">
                <input type="hidden" name="seat_total" value="' . $seatTotalForRedirect . '">
                <input type="hidden" name="food_total" value="' . $foodTotalForRedirect . '">
                <input type="hidden" name="grand_total" value="' . $grandTotalForRedirect . '">
              </form>
              <script>document.getElementById("redirectForm").submit();</script>';
        exit();
    }
}

// Get user's saved payment methods
$savedPaymentMethods = [];
$defaultPaymentMethod = null;
if ($userId) {
    $stmt = $conn->prepare("SELECT * FROM USER_PAYMENT_METHODS WHERE acc_id = ? ORDER BY is_default DESC, created_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $savedPaymentMethods[] = $row;
        if ($row['is_default'] == 1) {
            $defaultPaymentMethod = $row;
        }
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/payment.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Header Bar with Logo -->
    <div class="payment-header">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo">
        </div>
        <span class="header-title">Payment</span>
        <a href="checkout.php" class="btn-back" onclick="history.back(); return false;">← Back</a>
    </div>

    <div class="payment-container">
        <h1>Payment Method</h1>
        <div class="total-amount">Total: ₱<?= number_format($grandTotal, 2) ?></div>
        
        <?php if ($message): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
            <strong>Error:</strong> <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>
        
        <!-- Add Payment Method Section -->
        <div class="add-payment-section">
            <button type="button" class="toggle-add-payment" id="toggleAddPaymentBtn">
                Add New Payment Method
            </button>
            <form method="POST" class="add-payment-form" id="addPaymentForm">
                <!-- Preserve booking data -->
                <input type="hidden" name="booking_data" value="<?= htmlspecialchars($_POST['booking_data']) ?>">
                <input type="hidden" name="seat_total" value="<?= $seatTotal ?>">
                <input type="hidden" name="food_total" value="<?= $foodTotal ?>">
                <input type="hidden" name="grand_total" value="<?= $grandTotal ?>">
                
                <div class="form-group">
                    <label for="payment_type_select">Payment Method Type *</label>
                    <select id="payment_type_select" name="payment_type" required>
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
                        <label for="gcash_number">GCash Number *</label>
                        <input type="text" id="gcash_number" name="gcash_number" placeholder="09123456789" maxlength="13">
                        <small>Format: 09XXXXXXXXX or 63XXXXXXXXXX</small>
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
                        <input type="text" id="grabpay_number" name="grabpay_number" placeholder="09123456789" maxlength="13">
                        <small>Format: 09XXXXXXXXX or 63XXXXXXXXXX</small>
                    </div>
                </div>

                <!-- PayMaya Fields -->
                <div id="paymaya-fields" style="display: none;">
                    <div class="form-group">
                        <label for="paymaya_number">PayMaya Number *</label>
                        <input type="text" id="paymaya_number" name="paymaya_number" placeholder="09123456789" maxlength="13">
                        <small>Format: 09XXXXXXXXX or 63XXXXXXXXXX</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="is_default" style="margin-right: 8px; width: auto;">
                        <span>Set as default payment method</span>
                    </label>
                </div>
                
                <button type="submit" name="add_payment_method_checkout" class="btn-pay btn-save">
                    Save Payment Method
                </button>
            </form>
        </div>
        
        <form method="POST" action="process-booking.php" id="paymentForm">
            <input type="hidden" name="booking_data" value="<?= htmlspecialchars($_POST['booking_data']) ?>">
            <input type="hidden" name="seat_total" value="<?= $seatTotal ?>">
            <input type="hidden" name="food_total" value="<?= $foodTotal ?>">
            <input type="hidden" name="grand_total" value="<?= $grandTotal ?>">
            <input type="hidden" name="payment_type" id="paymentType" value="">
            <input type="hidden" name="reference_number" id="referenceNumber" value="">
            <input type="hidden" name="debug" value="1">
            <input type="hidden" name="saved_payment_method_id" id="savedPaymentMethodId" value="">
            
            <!-- Saved Payment Methods Section -->
            <?php if (!empty($savedPaymentMethods)): ?>
            <div class="saved-payment-methods">
                <h3>Select Payment Method</h3>
                <div class="saved-methods-grid">
                    <?php foreach ($savedPaymentMethods as $method): ?>
                        <div class="saved-method-option" data-method='<?= htmlspecialchars(json_encode($method)) ?>'>
                            <div class="method-row">
                                <div>
                                    <?php if ($method['payment_type'] === 'gcash'): ?>
                                        <strong>GCash</strong>
                                        <div class="method-detail">
                                            <?= htmlspecialchars($method['gcash_number'] ?? '') ?>
                                            <?php if ($method['is_default']): ?>
                                                <span class="default-badge">Default</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paypal'): ?>
                                        <strong>PayPal</strong>
                                        <div class="method-detail">
                                            <?= htmlspecialchars($method['paypal_email'] ?? '') ?>
                                            <?php if ($method['is_default']): ?>
                                                <span class="default-badge">Default</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'paymaya'): ?>
                                        <strong>PayMaya</strong>
                                        <div class="method-detail">
                                            <?= htmlspecialchars($method['gcash_number'] ?? '') ?>
                                            <?php if ($method['is_default']): ?>
                                                <span class="default-badge">Default</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($method['payment_type'] === 'grabpay'): ?>
                                        <strong>GrabPay</strong>
                                        <div class="method-detail">
                                            <?= htmlspecialchars($method['gcash_number'] ?? '') ?>
                                            <?php if ($method['is_default']): ?>
                                                <span class="default-badge">Default</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="radio" name="saved_payment_method" value="<?= $method['payment_method_id'] ?>" style="margin-left: 10px; accent-color: #558ace;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="no-payment-warning">
                <p>No saved payment methods found.</p>
                <p>Please add a payment method above to continue.</p>
            </div>
            <?php endif; ?>
            
            <div id="paymentSummary" class="payment-summary" style="display: none;">
                <div class="summary-row">
                    <span class="summary-label">Total to Pay:</span>
                    <span class="summary-value">₱<?= number_format($grandTotal, 2) ?></span>
                </div>
                <div id="referenceBanner" class="reference-banner" style="display: none;"></div>
            </div>
            
            <button type="button" class="btn-pay" id="initiatePaymentBtn" style="display: none;">Pay with <span id="selectedEwalletName"></span></button>
            
            <div id="completePaymentSection" style="display: none;">
                <div id="completeReferenceBanner" class="reference-banner" style="margin-bottom: 15px; display: none;"></div>
                <button type="submit" class="btn-pay btn-complete" id="completePaymentBtn">Complete Payment</button>
            </div>
        </form>
        
    </div>

    
    
    <script>
        let selectedSavedMethod = null;
        let paymentInitiated = false;

        // Toggle add payment form
        document.getElementById('toggleAddPaymentBtn').addEventListener('click', function() {
            if (paymentInitiated) return;
            const form = document.getElementById('addPaymentForm');
            form.classList.toggle('active');
        });

        // Toggle payment fields based on selection
        document.getElementById('payment_type_select').addEventListener('change', function() {
            const paymentType = this.value;

            document.getElementById('gcash-fields').style.display = (paymentType === 'gcash') ? 'block' : 'none';
            document.getElementById('paypal-fields').style.display = (paymentType === 'paypal') ? 'block' : 'none';
            document.getElementById('grabpay-fields').style.display = (paymentType === 'grabpay') ? 'block' : 'none';
            document.getElementById('paymaya-fields').style.display = (paymentType === 'paymaya') ? 'block' : 'none';
        });

        // Handle saved payment method selection
        document.querySelectorAll('.saved-method-option').forEach(function(element) {
            element.addEventListener('click', function() {
                if (paymentInitiated) return;
                const methodData = this.getAttribute('data-method');
                const method = JSON.parse(methodData);
                selectSavedPaymentMethod(method);
                
                // Highlight selected
                document.querySelectorAll('.saved-method-option').forEach(el => {
                    el.classList.remove('selected');
                });
                this.classList.add('selected');
            });
        });

        function selectSavedPaymentMethod(method) {
            if (paymentInitiated) return;
            selectedSavedMethod = method;
            document.getElementById('savedPaymentMethodId').value = method.payment_method_id;
            
            const radio = document.querySelector(`input[name="saved_payment_method"][value="${method.payment_method_id}"]`);
            if (radio) {
                radio.checked = true;
            }
            
            document.getElementById('paymentType').value = method.payment_type;
            updatePayButton();
        }
        
        function updatePayButton() {
            const initiateBtn = document.getElementById('initiatePaymentBtn');
            const completeSection = document.getElementById('completePaymentSection');
            const completeBtn = document.getElementById('completePaymentBtn');
            const paymentSummary = document.getElementById('paymentSummary');
            const savedMethodId = document.getElementById('savedPaymentMethodId').value;
            
            if (initiateBtn) initiateBtn.disabled = true;
            if (completeBtn) completeBtn.disabled = true;
            
            if (savedMethodId && selectedSavedMethod) {
                        if (initiateBtn) {
                        initiateBtn.disabled = false;
                        initiateBtn.style.display = 'inline-block';
                        const paymentTypeNames = {
                            'gcash': 'GCash',
                            'paymaya': 'PayMaya',
                            'grabpay': 'GrabPay',
                            'paypal': 'PayPal'
                        };
                        const displayName = paymentTypeNames[selectedSavedMethod.payment_type] || 'e-wallet';
                        initiateBtn.textContent = `Pay with ${displayName}`;
                    }
                    if (completeSection) completeSection.style.display = 'none';
                if (paymentSummary) paymentSummary.style.display = 'block';
                return;
            }

            if (completeSection) {
                completeSection.style.display = 'block';
                completeBtn.textContent = 'Select a payment method';
                completeBtn.disabled = true;
            }
            if (initiateBtn) initiateBtn.style.display = 'none';
            if (paymentSummary) paymentSummary.style.display = 'none';
        }

        function lockPaymentSelection() {
            paymentInitiated = true;
            // Visually disable all payment method options
            document.querySelectorAll('.saved-method-option').forEach(el => {
                el.style.opacity = '0.5';
                el.style.pointerEvents = 'none';
            });
            // Hide the "Add New Payment Method" button
            const addBtn = document.getElementById('toggleAddPaymentBtn');
            if (addBtn) addBtn.style.display = 'none';
            // Hide the add payment form if open
            const addForm = document.getElementById('addPaymentForm');
            if (addForm) addForm.classList.remove('active');
        }
        
        // Handle initiate payment button
        const initiateBtn = document.getElementById('initiatePaymentBtn');
        if (initiateBtn) {
            initiateBtn.addEventListener('click', function() {
    const prefix = selectedSavedMethod.payment_type ? selectedSavedMethod.payment_type.toUpperCase() : 'PAY';
    const walletDigits = String(Math.floor(100000 + Math.random() * 900000));
    const referenceNumber = prefix + '-' + walletDigits;
    
    document.getElementById('referenceNumber').value = referenceNumber;
    
    // Lock payment method selection after initiating payment
    lockPaymentSelection();
    
    // Hide the entire payment summary section
    const paymentSummary = document.getElementById('paymentSummary');
    if (paymentSummary) {
        paymentSummary.style.display = 'none';
    }
    
    initiateBtn.style.display = 'none';
    const completeSection = document.getElementById('completePaymentSection');
    if (completeSection) {
        completeSection.style.display = 'block';
        const completeReferenceBanner = document.getElementById('completeReferenceBanner');
        if (completeReferenceBanner) {
            completeReferenceBanner.textContent = `Reference Number: ${referenceNumber}`;
            completeReferenceBanner.style.display = 'block';
        }
        const completeBtn = document.getElementById('completePaymentBtn');
        if (completeBtn) {
            completeBtn.disabled = false;
            completeBtn.textContent = 'Complete Payment';
        }
    }
});
        }
        
        // Handle complete payment button
        const completeBtn = document.getElementById('completePaymentBtn');
        if (completeBtn) {
            completeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                this.disabled = true;
                this.innerHTML = 'Processing...';
                document.getElementById('paymentForm').submit();
            });
        }

        // Handle payment form submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            const savedMethodId = document.getElementById('savedPaymentMethodId').value;
            
            if (!savedMethodId || !selectedSavedMethod) {
                e.preventDefault();
                alert('Please select a payment method.');
                return false;
            }
            
            const refNumber = document.getElementById('referenceNumber').value;
            if (!refNumber || refNumber === '') {
                let prefix = 'PAY';
                let digits = '';
                
                prefix = selectedSavedMethod.payment_type.toUpperCase();
                digits = String(Math.floor(100000 + Math.random() * 900000));
                
                document.getElementById('referenceNumber').value = `${prefix}-${digits}`;
            }
            
            return true;
        });



        // Format phone numbers
        const phoneInputs = ['gcash_number', 'grabpay_number', 'paymaya_number'];
        phoneInputs.forEach(function(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('input', function(e) {
                    e.target.value = e.target.value.replace(/\D/g, '');
                });
            }
        });
    </script>
</body>
</html>