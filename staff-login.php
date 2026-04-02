<?php
session_start();
require_once 'config.php';

// Already logged in as staff
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] && isset($_SESSION['role']) && in_array($_SESSION['role'], ['staff', 'mall_admin'])) {
    header("Location: staff/dashboard.php");
    exit();
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error_message = "Please enter your email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM USER_ACCOUNT WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if (!in_array($row['role'] ?? 'user', ['staff', 'mall_admin'])) {
                $error_message = "Access denied. This portal is for staff only.";
            } elseif (!password_verify($password, $row['user_password'])) {
                $error_message = "Invalid password.";
            } else {
                // Check email verified
                $vStmt = $conn->prepare("SELECT 1 FROM email_verifications WHERE acc_id = ? AND used_at IS NOT NULL LIMIT 1");
                $vStmt->bind_param("i", $row['acc_id']);
                $vStmt->execute();
                $verified = $vStmt->get_result()->num_rows > 0;
                $vStmt->close();

                if (!$verified) {
                    $error_message = "Account not verified. Please contact the administrator.";
                } else {
                    $_SESSION = [];
                    session_regenerate_id(false);

                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id']   = $row['acc_id'];
                    $_SESSION['acc_id']    = $row['acc_id'];
                    $_SESSION['role']      = $row['role'];
                    $_SESSION['user_name'] = $row['firstName'] . ' ' . $row['lastName'];
                    $_SESSION['staff_email'] = $row['email'];

                    $conn->query("UPDATE USER_ACCOUNT SET user_status = 'online' WHERE acc_id = " . (int)$row['acc_id']);

                    header("Location: staff/dashboard.php");
                    exit();
                }
            }
        } else {
            $error_message = "No staff account found with that email.";
        }

        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Portal – Ticketix</title>
  <link rel="icon" type="image/png" href="images/brand x.png">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap');

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Montserrat', sans-serif;
      background: url('css/loginbg/Main.png') center center / cover no-repeat;
      background-color: #0a1628;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;
    }
    body::before {
      content: '';
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.6);
      z-index: 0;
    }

    .login-wrapper {
      position: relative; z-index: 1;
      width: 100%; max-width: 440px;
      padding: 20px;
    }

    /* Back link */
    .back-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: rgba(255,255,255,0.6);
      text-decoration: none; font-size: 13px; font-weight: 500;
      margin-bottom: 20px;
      transition: color 0.2s;
    }
    .back-link:hover { color: #fff; }

    /* Card */
    .login-card {
      background: rgba(5, 15, 35, 0.72);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid rgba(85,138,206,0.35);
      border-radius: 18px;
      padding: 40px 36px;
      box-shadow: 0 16px 48px rgba(0,0,0,0.55), 0 0 40px rgba(85,138,206,0.15);
    }

    /* Header */
    .login-header {
      text-align: center;
      margin-bottom: 32px;
    }
    .login-header img {
      height: 48px; width: auto;
      margin-bottom: 16px;
    }
    .login-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(85,138,206,0.15);
      border: 1px solid rgba(85,138,206,0.4);
      border-radius: 20px; padding: 5px 14px;
      font-size: 11px; font-weight: 700; letter-spacing: 1px;
      color: #89bbf3;
      text-transform: uppercase;
      margin-bottom: 12px;
    }
    .login-title {
      font-size: 26px; font-weight: 800;
      color: #ffffff;
      text-shadow: 0 2px 8px rgba(0,0,0,0.5);
      margin-bottom: 4px;
    }
    .login-subtitle {
      font-size: 13px; color: rgba(176,212,241,0.8);
      font-weight: 400;
    }

    /* Error */
    .error-box {
      background: rgba(231,76,60,0.15);
      border: 1px solid rgba(231,76,60,0.4);
      border-radius: 8px; padding: 11px 14px;
      color: #ff8a80; font-size: 13px; font-weight: 500;
      margin-bottom: 18px; text-align: center;
    }

    /* Form */
    .form-group { margin-bottom: 16px; }
    .form-group label {
      display: block; font-size: 12px; font-weight: 600;
      color: rgba(176,212,241,0.8);
      margin-bottom: 7px;
      text-transform: uppercase; letter-spacing: 0.6px;
    }
    .form-control {
      width: 100%; padding: 13px 15px;
      background: rgba(255,255,255,0.09);
      border: 1px solid rgba(85,138,206,0.45);
      border-radius: 8px;
      color: #ffffff; font-family: 'Montserrat', sans-serif;
      font-size: 14px;
      transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .form-control::placeholder { color: rgba(255,255,255,0.35); }
    .form-control:focus {
      outline: none;
      border-color: #558ace;
      background: rgba(255,255,255,0.13);
      box-shadow: 0 0 0 3px rgba(85,138,206,0.2);
    }

    /* Password field */
    .password-wrap { position: relative; }
    .toggle-pw {
      position: absolute; right: 12px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: rgba(255,255,255,0.4); font-size: 18px; padding: 4px;
      line-height: 1;
    }
    .toggle-pw:hover { color: rgba(255,255,255,0.7); }

    /* Submit */
    .btn-login {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg, #558ace, #3a6aaa);
      border: none; border-radius: 8px;
      color: #fff; font-family: 'Montserrat', sans-serif;
      font-size: 15px; font-weight: 700;
      cursor: pointer; margin-top: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 16px rgba(85,138,206,0.35);
    }
    .btn-login:hover {
      background: linear-gradient(135deg, #6a9fd8, #4a7ac0);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(85,138,206,0.5);
    }
    .btn-login:active { transform: translateY(0); }

    /* Footer note */
    .login-note {
      text-align: center; margin-top: 20px;
      font-size: 12px; color: rgba(255,255,255,0.3);
    }

    /* Floating particles (decorative) */
    .particles { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
    .particle {
      position: absolute; border-radius: 50%;
      background: rgba(85,138,206,0.15);
      animation: float-up linear infinite;
    }
    @keyframes float-up {
      0% { transform: translateY(110vh) scale(0); opacity: 0; }
      10% { opacity: 1; }
      90% { opacity: 0.6; }
      100% { transform: translateY(-10vh) scale(1); opacity: 0; }
    }
  </style>
</head>
<body>

  <!-- Decorative particles -->
  <div class="particles">
    <?php for ($p = 0; $p < 12; $p++): 
      $sz = rand(6, 20);
      $left = rand(2, 98);
      $delay = rand(0, 8000) / 1000;
      $dur = rand(8, 18);
    ?>
    <div class="particle" style="
      width:<?= $sz ?>px; height:<?= $sz ?>px;
      left:<?= $left ?>%;
      animation-duration:<?= $dur ?>s;
      animation-delay:<?= $delay ?>s;
    "></div>
    <?php endfor; ?>
  </div>

  <div class="login-wrapper">
    <a href="TICKETIX NI CLAIRE.php" class="back-link">
      ← Back to Website
    </a>

    <div class="login-card">
      <div class="login-header">
        <img src="images/brand x.png" alt="Ticketix Logo">
        <div class="login-badge"> Staff Portal</div>
        <h1 class="login-title">Welcome Back</h1>
        <p class="login-subtitle">Sign in to manage walk-in bookings</p>
      </div>

      <?php if ($error_message): ?>
      <div class="error-box"> <?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <form method="POST" action="staff-login.php" autocomplete="off">
        <div class="form-group">
          <label for="email">Staff Email</label>
          <input type="email" id="email" name="email" class="form-control"
                 placeholder="staffemail@example.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 required autofocus>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-wrap">
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="••••••••" required>
            <button type="button" class="toggle-pw" id="togglePw" title="Show/hide password">👁</button>
          </div>
        </div>

        <button type="submit" class="btn-login">Sign In to Staff Portal</button>
      </form>

      <p class="login-note"> Authorized personnel only</p>
    </div>
  </div>

  <script>
    const toggleBtn = document.getElementById('togglePw');
    const pwInput = document.getElementById('password');
    if (toggleBtn && pwInput) {
      toggleBtn.addEventListener('click', () => {
        if (pwInput.type === 'password') {
          pwInput.type = 'text';
          toggleBtn.textContent = '';
        } else {
          pwInput.type = 'password';
          toggleBtn.textContent = '👁';
        }
      });
    }
  </script>
</body>
</html>
