<?php
session_start();
require_once 'config.php';
require_once 'permissions.php';
require_once 'utils.php';

// Load settings (do not overwrite)
$settings = [
    'site_name' => SITE_NAME,
    'session_timeout' => SESSION_TIMEOUT
];
try {
    $stmt = $db->query("SELECT site_name, session_timeout FROM settings LIMIT 1");
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbSettings) {
        $settings['site_name'] = $dbSettings['site_name'];
        $settings['session_timeout'] = (int)$dbSettings['session_timeout'];
    }
} catch (PDOException $e) {
    // Fallback to defaults from config.php
}

// Check if the user is already logged in
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    header("Location: admin.php");
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = $db->prepare("SELECT * FROM admins WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password'])) {
            if ($admin['status'] === 'pending') {
                $error = "Your account is pending approval by a super admin.";
            } else {
                // Set session variables
                $_SESSION['admin'] = true;
                $_SESSION['admin_id'] = $admin['id']; // Store admin_id for permission lookups
                $_SESSION['username'] = $admin['username'];
                $_SESSION['access_level'] = $admin['access_level'];
                $_SESSION['last_activity'] = time(); // For session timeout

                // Load custom permissions
                $userPermissions = $permissions[$admin['access_level']]; // Fixed typo: $Permissions to $permissions
                try {
                    $stmt = $db->prepare("SELECT permission_key, permission_value FROM admin_permissions WHERE admin_id = :admin_id");
                    $stmt->execute([':admin_id' => $admin['id']]);
                    $customPermissions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach ($customPermissions as $key => $value) {
                        $userPermissions[$key] = (bool)$value;
                    }
                } catch (PDOException $e) {
                    // Fallback to default permissions if query fails
                }

                // Store permissions in session for use across pages
                $_SESSION['permissions'] = $userPermissions;

                // Log the login action to the logs table
                $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
                $stmt->execute([
                    ':username' => $admin['username'],
                    ':action' => "Admin {$admin['username']} logged in"
                ]);

                header("Location: admin.php");
                exit;
            }
        } else {
            $error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars($settings['site_name']); ?></title>
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
        <h2>Admin Login</h2>
        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
        <p>Don't have an account? <a href="register.php">Register here</a>.</p>
        <p>Forgot your password? <a href="reset-password.php">Reset here</a>.</p>
    </div>
</body>
</html>