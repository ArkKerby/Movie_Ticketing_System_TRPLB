<?php
session_start();
require_once 'config.php';

// Connect sa MySQL database
$conn = getDBConnection();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate required fields (address removed)
    if (empty($_POST['firstName']) || empty($_POST['fullName']) || empty($_POST['email']) || empty($_POST['password']) || empty($_POST['contact'])) {
        die("Error: First name, display name, email, password, and contact are required!");
    }

    // Get user data
    $firstName = trim($_POST['firstName']);
    $lastName = trim($_POST['lastName'] ?? '');
    $displayName = trim($_POST['fullName'] ?? '');
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $contact = trim($_POST['contact']);

    // Validate display name
    if (empty($displayName)) die("Error: Display name is required!");
    if (mb_strlen($displayName) > 50) die("Error: Display name must be 50 characters or fewer.");

    // Validate first + last name
    if (mb_strlen($firstName) > 50) die("Error: First name must be 50 characters or fewer.");
    if (mb_strlen($firstName) == 0) die("Error: First name cannot be empty.");
    if (mb_strlen($lastName) > 50) die("Error: Last name must be 50 characters or fewer.");

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) die("Error: Invalid email format!");

    // Password validations
    if (strlen($password) < 8) die("Error: Password must be at least 8 characters long!");
    if (!preg_match('/[A-Z]/', $password)) die("Error: Password must contain at least one uppercase letter!");
    if (!preg_match('/[a-z]/', $password)) die("Error: Password must contain at least one lowercase letter!");
    if (!preg_match('/[0-9]/', $password)) die("Error: Password must contain at least one number!");
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) die("Error: Password must contain at least one special character!");

    // Confirm password
    if ($password !== $confirmPassword) die("Error: Passwords do not match!");

    // Contact number validation
    if (!preg_match('/^[0-9]{11}$/', $contact)) die("Error: Contact number must be exactly 11 digits!");

    // Length validations
    if (mb_strlen($email) > 50) die("Error: Email must be 50 characters or fewer.");

    // Check if email already exists
    $check = $conn->prepare("SELECT acc_id FROM USER_ACCOUNT WHERE email = ? LIMIT 1");
    if (!$check) die("Prepare failed: " . $conn->error);

    $check->bind_param("s", $email);
    $check->execute();
    $checkResult = $check->get_result();

    if ($checkResult && $checkResult->num_rows > 0) {
        $check->close();
        die("Error: Email already registered. Please use a different email or log in.");
    }
    $check->close();

    // Hash password
    $hashed_password = $password;

    // Check if fullName column exists
    $checkFullName = $conn->query("SHOW COLUMNS FROM USER_ACCOUNT LIKE 'fullName'");
    $hasFullName = $checkFullName && $checkFullName->num_rows > 0;

    // Insert without address
    if ($hasFullName) {
        $stmt = $conn->prepare("
            INSERT INTO USER_ACCOUNT (firstName, lastName, fullName, email, user_password, contNo, time_created, user_status)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'online')
        ");

        if (!$stmt) die("Prepare failed: " . $conn->error);

        $stmt->bind_param("ssssss", $firstName, $lastName, $displayName, $email, $hashed_password, $contact);

    } else {
        $stmt = $conn->prepare("
            INSERT INTO USER_ACCOUNT (firstName, lastName, email, user_password, contNo, time_created, user_status)
            VALUES (?, ?, ?, ?, ?, NOW(), 'online')
        ");

        if (!$stmt) die("Prepare failed: " . $conn->error);

        $stmt->bind_param("sssss", $firstName, $lastName, $email, $hashed_password, $contact);
    }

    try {
        $executed = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            $stmt->close();
            die("Error: Email already registered. Please use a different email or log in.");
        }
        throw $e;
    }

    if ($executed) {
        $user_id = $stmt->insert_id;
        $stmt->close();

        // Create EMAIL_VERIFICATION table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS EMAIL_VERIFICATION (
            id INT AUTO_INCREMENT PRIMARY KEY,
            acc_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (acc_id),
            UNIQUE KEY token_unique (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Generate token
        $raw = bin2hex(random_bytes(32));
        $token = hash('sha256', $raw . uniqid('', true));
        $expires = date('Y-m-d H:i:s', time() + 24 * 60 * 60);

        $ins = $conn->prepare("INSERT INTO EMAIL_VERIFICATION (acc_id, token, expires_at) VALUES (?, ?, ?)");
        if ($ins) {
            $ins->bind_param("iss", $user_id, $token, $expires);
            $ins->execute();
            $ins->close();
        }

        // Send verification email
        require_once __DIR__ . '/mailer.php';
        $appUrl = getenv('APP_URL');

        if ($appUrl) {
            $link = rtrim($appUrl, "/") . "/verify-email.php?token=" . urlencode($token);
        } else {
            $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\') .
                "/verify-email.php?token=" . urlencode($token);
        }

        try {
            $fromEmail = getenv('SMTP_FROM_EMAIL');
            $fromName = getenv('SMTP_FROM_NAME') ?: 'Ticketix';

            if ($fromEmail) {
                $mail->setFrom($fromEmail, $fromName);
            } elseif (!empty($mail->Username)) {
                $mail->setFrom($mail->Username, $fromName);
            }

            $displayNameForEmail = !empty($displayName) ? $displayName : ($firstName . ' ' . $lastName);
            $mail->addAddress($email, $displayNameForEmail);
            $mail->Subject = 'Verify your Ticketix email address';
            $mail->Body = '<p>Hi ' . htmlspecialchars($displayNameForEmail, ENT_QUOTES, 'UTF-8') . ',</p>' .
                '<p>Thanks for signing up. Please verify your email:</p>' .
                '<p><a href="' . $link . '">Verify Email</a></p>' .
                '<p>This link expires in 24 hours.</p>';
            $mail->AltBody = "Hi $displayNameForEmail,\n\nVerify your email: $link\n\nThis link expires in 24 hours.";
            $mail->send();
        } catch (Exception $e) {
            echo "Registration successful, but verification email failed to send: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit();
        }

        echo "Registration successful! Please check your email to verify your account.";
        exit();
    } else {
        echo "Error inserting record: " . $stmt->error;
    }

    $stmt->close();

} else {
    header("Location: signup.html");
    exit();
}

$conn->close();
?>
