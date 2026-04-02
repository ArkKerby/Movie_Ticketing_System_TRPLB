<?php
session_start();
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = $_POST['password'] ?? '';

    $conn = getDBConnection();

    if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM USER_ACCOUNT WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $input);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();

                if (password_verify($password, $row['user_password'])) {
                    // Check email verification
                    $verifyStmt = $conn->prepare("SELECT 1 FROM email_verifications WHERE acc_id = ? AND used_at IS NOT NULL ORDER BY used_at DESC LIMIT 1");
                    if ($verifyStmt) {
                        $verifyStmt->bind_param("i", $row['acc_id']);
                        $verifyStmt->execute();
                        $verifiedRes = $verifyStmt->get_result();
                        $isVerified = $verifiedRes && $verifiedRes->num_rows > 0;
                        $verifyStmt->close();
                    } else {
                        $isVerified = false;
                    }

                    if (!$isVerified) {
                        $error_message = "Please verify your email before logging in. Check your inbox.";
                    } else {
                        // Save pending booking data BEFORE clearing session
                          $pendingBooking = $_SESSION['pending_booking'] ?? null;
                          $returnAfterLogin = $_SESSION['return_after_login'] ?? null;

                          $_SESSION = array();
                          session_regenerate_id(false);

                          // Restore pending booking data AFTER clearing session
                          if ($pendingBooking) $_SESSION['pending_booking'] = $pendingBooking;
                          if ($returnAfterLogin) $_SESSION['return_after_login'] = $returnAfterLogin;
                        
                        if (isset($row['fullName']) && !empty($row['fullName'])) {
                            $_SESSION['user_name'] = $row['fullName'];
                        } elseif (isset($row['firstName']) && isset($row['lastName'])) {
                            $_SESSION['user_name'] = $row['firstName'] . ' ' . $row['lastName'];
                        } else {
                            $_SESSION['user_name'] = $row['email'];
                        }
                        $_SESSION['user_id'] = $row['acc_id'];
                        $_SESSION['acc_id'] = $row['acc_id'];
                        $_SESSION['role'] = $row['role'] ?? 'user';
                        $_SESSION['logged_in'] = true;

                        // TEMPORARY DEBUG

                        $update_query = "UPDATE USER_ACCOUNT SET user_status = 'online' WHERE acc_id = " . (int)$row['acc_id'];
                        $conn->query($update_query);

                        // Route by role
                        if ($row['role'] === 'mall_admin') {
                            header("Location: admin-panel.php");
                        } elseif ($row['role'] === 'staff') {
                            header("Location: staff/dashboard.php");
                        } elseif (!empty($_SESSION['return_after_login'])) {
                            $redirect = $_SESSION['return_after_login'];
                            unset($_SESSION['return_after_login']);
                            header("Location: " . $redirect);
                        } else {
                            header("Location: TICKETIX NI CLAIRE.php");
                        }
                        exit();
                    }
                } else {
                    $error_message = "Invalid password.";
                }
            } else {
                $error_message = "No user found.";
            }

            $stmt->close();
        } else {
            $error_message = "Login error. Please try again later.";
        }
    }

    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ticketix Login / Sign Up</title>
  <link rel="icon" type="image/png" href="images/brand x.png" />
  <link rel="stylesheet" href="css/login.css">
  <style>
    /* Password strength indicator styles */
    .password-wrapper {
      position: relative;
      width: 100%;
    }
    
    .password-input-container {
      position: relative;
      width: 100%;
    }
    
    .password-input-container input {
      width: 100%;
      padding-right: 15px;
    }
    
    .password-toggle-wrapper {
      display: flex;
      align-items: center;
      margin-top: 5px;
      font-size: 12px;
    }
    
    .toggle-checkbox {
      width: 15px;
      height: 15px;
      cursor: pointer;
      margin-right: 5px;
      accent-color: #3C50B2;
    }
    
    .toggle-label {
      cursor: pointer;
      user-select: none;
      color: #666;
      font-size: 12px;
    }
    
    .password-strength {
      margin-top: 5px;
      font-size: 12px;
      text-align: left;
    }
    
    .password-requirements {
      margin-top: 8px;
      padding: 10px;
      background: #f5f5f5;
      border-radius: 4px;
      font-size: 11px;
      text-align: left;
      display: none;
    }
    
    .password-requirements.show {
      display: block;
    }
    
    .requirement {
      margin: 4px 0;
      color: #666;
    }
    
    .requirement.met {
      color: #4CAF50;
    }
    
    .requirement::before {
      content: '✗ ';
      color: #f44336;
      font-weight: bold;
    }
    
    .requirement.met::before {
      content: '✓ ';
      color: #4CAF50;
    }
    
    .strength-bar {
      height: 4px;
      background: #e0e0e0;
      border-radius: 2px;
      margin-top: 5px;
      overflow: hidden;
    }
    
    .strength-bar-fill {
      height: 100%;
      width: 0%;
      transition: all 0.3s ease;
      background: #f44336;
    }
    
    .strength-bar-fill.weak {
      width: 33%;
      background: #f44336;
    }
    
    .strength-bar-fill.medium {
      width: 66%;
      background: #ff9800;
    }
    
    .strength-bar-fill.strong {
      width: 100%;
      background: #4CAF50;
    }
    
    .password-match-error {
      color: #f44336;
      font-size: 12px;
      margin-top: 5px;
      display: none;
    }
    
    .password-match-error.show {
      display: block;
    }
  </style>
</head>
<body>
  <div class="container" id="container">
    <!-- Sign Up Form -->
    <div class="form-container sign-up-container">
      <form action="signup.php" method="POST" id="signup-form" novalidate>
        <h1>Create Account</h1>
        <input type="text" name="firstName" placeholder="First Name" required>
        <input type="text" name="lastName" placeholder="Last Name (Optional)">
        <input type="text" name="fullName" placeholder="Display Name / Nickname" required>
        <div class="email-input-wrapper">
          <input type="email" name="email" placeholder="Email" required id="email">
          <small id="email-help"></small>
        </div>
        
        <!-- Password with strength indicator -->
        <div class="password-wrapper">
          <div class="password-input-container">
            <input type="password" name="password" placeholder="Password" required id="password">
          </div>
          <div class="password-toggle-wrapper">
            <input type="checkbox" id="toggle-password" class="toggle-checkbox">
            <label for="toggle-password" class="toggle-label">Show password</label>
          </div>
          <div class="strength-bar">
            <div class="strength-bar-fill" id="strength-bar"></div>
          </div>
          <div class="password-requirements" id="password-requirements">
            <div class="requirement" id="req-length">At least 8 characters</div>
            <div class="requirement" id="req-uppercase">One uppercase letter</div>
            <div class="requirement" id="req-lowercase">One lowercase letter</div>
            <div class="requirement" id="req-number">One number</div>
            <div class="requirement" id="req-special">One special character (!@#$%^&*)</div>
          </div>
        </div>
        
        <!-- Confirm Password -->
        <div class="password-wrapper">
          <div class="password-input-container">
            <input type="password" name="confirmPassword" placeholder="Confirm Password" required id="confirmPassword">
          </div>
          <div class="password-toggle-wrapper">
            <input type="checkbox" id="toggle-confirm-password" class="toggle-checkbox">
            <label for="toggle-confirm-password" class="toggle-label">Show password</label>
          </div>
          <div class="password-match-error" id="password-match-error">Passwords do not match</div>
        </div>
        
        <input type="tel" name="contact" placeholder="Contact Number" pattern="[0-9]{11}" maxlength="11" required>
        <button type="submit" id="signup-btn">Sign Up</button>
        <p class="mobile-toggle" id="mobileSignIn">Already have an account? <strong>Sign In</strong></p>
      </form>
    </div>

    <!-- Sign In Form -->
    <div class="form-container sign-in-container">
      <form action="login.php" method="POST">
        <h1>Sign In</h1>
        <?php if (isset($error_message)): ?>
          <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>
        <input type="email" name="email" placeholder="Email" required>
        <div class="password-wrapper">
          <div class="password-input-container">
            <input type="password" name="password" placeholder="Password" required id="login-password">
          </div>
          <div class="password-toggle-wrapper">
            <input type="checkbox" id="toggle-login-password" class="toggle-checkbox">
            <label for="toggle-login-password" class="toggle-label">Show password</label>
          </div>
        </div>
        <button type="submit">Login</button>
        <a href="forgotpassword.php">Forgot Password?</a>
        <p class="mobile-toggle" id="mobileSignUp">Don't have an account? <strong>Sign Up</strong></p>
      </form>
    </div>

    <!-- Overlay -->
    <div class="overlay-container">
      <div class="overlay">
        <div class="overlay-panel overlay-left">
          <h1>Welcome Back!</h1>
          <p>Please login your info!</p>
          <button class="ghost" id="signIn">Sign In</button>
        </div>
        <div class="overlay-panel overlay-right">
          <h1>Hello, Friend!</h1>
          <p>Register and start your Ticketix journey</p>
          <button class="ghost" id="signUp">Sign Up</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    const signUpButton = document.getElementById('signUp');
    const signInButton = document.getElementById('signIn');
    const container = document.getElementById('container');

    signUpButton.addEventListener('click', () => {
      container.classList.add("right-panel-active");
    });

    signInButton.addEventListener('click', () => {
      container.classList.remove("right-panel-active");
    });

    // Mobile toggle links
    const mobileSignUp = document.getElementById('mobileSignUp');
    const mobileSignIn = document.getElementById('mobileSignIn');

    if (mobileSignUp) {
      mobileSignUp.addEventListener('click', () => {
        container.classList.add('right-panel-active');
      });
    }

    if (mobileSignIn) {
      mobileSignIn.addEventListener('click', () => {
        container.classList.remove('right-panel-active');
      });
    }

    // Email validation
    (function() {
      const form = document.getElementById('signup-form');
      if (!form) return;
      
      const emailInput = document.getElementById('email');
      const help = document.getElementById('email-help');
      if (!emailInput || !help) return;
      
      let lastChecked = '';
      let inflight = 0;

      function show(msg) {
        help.textContent = msg;
        help.style.display = msg ? 'block' : 'none';
      }

      async function checkEmailAvailability(email) {
        try {
          const resp = await fetch('email-validation.php?email=' + encodeURIComponent(email), { cache: 'no-store' });
          const data = await resp.json();
          return data;
        } catch (e) {
          return { ok: false, reason: 'network' };
        }
      }

      async function validateEmail() {
        const value = emailInput.value.trim();
        if (!value) {
          show('Email is required.');
          return false;
        }

        const basic = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!basic.test(value)) {
          show('Please enter a valid email address.');
          return false;
        }

        if (value === lastChecked) {
          return help.style.display !== 'block';
        }

        lastChecked = value;
        inflight++;
        const res = await checkEmailAvailability(value);
        inflight--;

        if (!res.ok && res.reason === 'invalid_format') {
          show('Please enter a valid email address.');
          return false;
        }

        if (!res.ok) {
          show('Could not validate email right now. Try again.');
          return false;
        }

        if (res.available === false) {
          show('Email is already registered.');
          return false;
        }

        show('');
        return true;
      }

      emailInput.addEventListener('blur', validateEmail);
      emailInput.addEventListener('input', function() {
        show('');
      });
    })();

    // Password strength validation
    (function() {
      const passwordInput = document.getElementById('password');
      const confirmPasswordInput = document.getElementById('confirmPassword');
      const requirements = document.getElementById('password-requirements');
      const strengthBar = document.getElementById('strength-bar');
      const matchError = document.getElementById('password-match-error');
      const form = document.getElementById('signup-form');
      
      if (!passwordInput || !confirmPasswordInput) return;

      const reqElements = {
        length: document.getElementById('req-length'),
        uppercase: document.getElementById('req-uppercase'),
        lowercase: document.getElementById('req-lowercase'),
        number: document.getElementById('req-number'),
        special: document.getElementById('req-special')
      };

      function checkRequirements(password) {
        const checks = {
          length: password.length >= 8,
          uppercase: /[A-Z]/.test(password),
          lowercase: /[a-z]/.test(password),
          number: /[0-9]/.test(password),
          special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
        };

        // Update UI for each requirement
        Object.keys(checks).forEach(key => {
          if (checks[key]) {
            reqElements[key].classList.add('met');
          } else {
            reqElements[key].classList.remove('met');
          }
        });

        // Calculate strength
        const metCount = Object.values(checks).filter(v => v).length;
        strengthBar.className = 'strength-bar-fill';
        
        if (metCount <= 2) {
          strengthBar.classList.add('weak');
        } else if (metCount <= 4) {
          strengthBar.classList.add('medium');
        } else {
          strengthBar.classList.add('strong');
        }

        return Object.values(checks).every(v => v);
      }

      function checkPasswordMatch() {
        if (confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value) {
          matchError.classList.add('show');
          return false;
        } else {
          matchError.classList.remove('show');
          return true;
        }
      }

      // Show requirements when user starts typing or focuses
      passwordInput.addEventListener('focus', () => {
        requirements.classList.add('show');
      });

      passwordInput.addEventListener('input', () => {
        // Show requirements as soon as user starts typing
        if (passwordInput.value.length > 0) {
          requirements.classList.add('show');
        }
        const allRequirementsMet = checkRequirements(passwordInput.value);
        
        // Hide requirements if all are met
        if (allRequirementsMet) {
          requirements.classList.remove('show');
        }
        
        if (confirmPasswordInput.value) {
          checkPasswordMatch();
        }
      });

      // Optional: hide requirements when field is empty and loses focus
      passwordInput.addEventListener('blur', () => {
        if (passwordInput.value.length === 0) {
          requirements.classList.remove('show');
        }
      });

      // Optional: hide requirements when field is empty and loses focus
      passwordInput.addEventListener('blur', () => {
        if (passwordInput.value.length === 0) {
          requirements.classList.remove('show');
        }
      });

      confirmPasswordInput.addEventListener('input', checkPasswordMatch);

      form.addEventListener('submit', async function(e) {
        const passwordValid = checkRequirements(passwordInput.value);
        const passwordsMatch = checkPasswordMatch();
        
        if (!passwordValid || !passwordsMatch) {
          e.preventDefault();
          alert('Please ensure your password meets all requirements and passwords match.');
        }
      });
    })();

    // Password visibility toggle - Checkbox version
    (function() {
      // Toggle for signup password
      const togglePassword = document.getElementById('toggle-password');
      const passwordInput = document.getElementById('password');
      
      if (togglePassword && passwordInput) {
        togglePassword.addEventListener('change', function() {
          passwordInput.type = this.checked ? 'text' : 'password';
        });
      }

      // Toggle for confirm password
      const toggleConfirmPassword = document.getElementById('toggle-confirm-password');
      const confirmPasswordInput = document.getElementById('confirmPassword');
      
      if (toggleConfirmPassword && confirmPasswordInput) {
        toggleConfirmPassword.addEventListener('change', function() {
          confirmPasswordInput.type = this.checked ? 'text' : 'password';
        });
      }

      // Toggle for login password
      const toggleLoginPassword = document.getElementById('toggle-login-password');
      const loginPasswordInput = document.getElementById('login-password');
      
      if (toggleLoginPassword && loginPasswordInput) {
        toggleLoginPassword.addEventListener('change', function() {
          loginPasswordInput.type = this.checked ? 'text' : 'password';
        });
      }
    })();
  </script>
</body>
</html>