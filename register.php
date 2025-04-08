<?php
session_start();

// Include the config file
require_once 'config.php';

// Load settings from MySQL (for SITE_NAME, MIN_PASSWORD_LENGTH, etc.)
$settings = [
    'site_name' => SITE_NAME,
    'min_password_length' => MIN_PASSWORD_LENGTH,
    'require_special_char' => REQUIRE_SPECIAL_CHAR,
    'require_number' => REQUIRE_NUMBER
];
try {
    $stmt = $db->query("SELECT site_name, min_password_length, require_special_char, require_number FROM settings LIMIT 1");
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbSettings) {
        $settings['site_name'] = $dbSettings['site_name'];
        $settings['min_password_length'] = (int)$dbSettings['min_password_length'];
        $settings['require_special_char'] = (bool)$dbSettings['require_special_char'];
        $settings['require_number'] = (bool)$dbSettings['require_number'];
    }
} catch (PDOException $e) {
    // Fallback to defaults from config.php if settings table doesn’t exist
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $token = $_POST['token'] ?? '';

    // Validate token
    try {
        $stmt = $db->prepare("SELECT id, used FROM registration_tokens WHERE token = :token AND expires > NOW()");
        $stmt->execute([':token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenData) {
            $error = "Invalid or expired registration token. Please obtain a new link from a super admin.";
        } elseif ($tokenData['used']) {
            $error = "This registration token has already been used. Please obtain a new link from a super admin.";
        } else {
            // Check if username already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = :username");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists. Please choose a different username.";
            } elseif ($password !== $confirmPassword) {
                $error = "Passwords do not match.";
            } else {
                // Validate password strength
                if (strlen($password) < $settings['min_password_length']) {
                    $error = "Password must be at least " . $settings['min_password_length'] . " characters long.";
                } elseif ($settings['require_special_char'] && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                    $error = "Password must contain at least one special character.";
                } elseif ($settings['require_number'] && !preg_match('/\d/', $password)) {
                    $error = "Password must contain at least one number.";
                } else {
                    // Add new admin with pending status
                    $stmt = $db->prepare("
                        INSERT INTO admins (username, password, access_level, status)
                        VALUES (:username, :password, 'editor', 'pending')
                    ");
                    $stmt->execute([
                        ':username' => $username,
                        ':password' => password_hash($password, PASSWORD_DEFAULT)
                    ]);

                    // Mark token as used
                    $stmt = $db->prepare("UPDATE registration_tokens SET used = 1 WHERE id = :id");
                    $stmt->execute([':id' => $tokenData['id']]);

                    // Log the registration action
                    $logFile = LOG_FILE;
                    $logMessage = date('Y-m-d H:i:s') . " - New admin registered: $username (pending approval)\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);

                    header("Location: login.php?success=Registration successful. Awaiting super admin approval.");
                    exit;
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Check if a token is provided in the URL
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $error = "No registration token provided. Please use a valid registration link from a super admin.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Registration - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .login-container h2 {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            display: block;
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #0056b3;
        }
        .error-message, .success-message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }
        .success-message {
            background-color: #d4edda;
            color: #155724;
        }
        .login-container p {
            text-align: center;
            margin-top: 10px;
        }
        .login-container a {
            color: #007bff;
            text-decoration: none;
        }
        .login-container a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Registration</h2>
        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <form method="POST" class="login-form">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit">Register</button>
        </form>
        <p>Already have an account? <a href="login.php">Login here</a>.</p>
    </div>
</body>
</html>