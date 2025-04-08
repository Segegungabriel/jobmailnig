<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);


// Load settings from JSON file
$settingsFile = 'settings.json';
$settings = [
    'enable_rss_feed' => true,
    'session_timeout' => SESSION_TIMEOUT,
    'site_name' => SITE_NAME,
    'site_url' => SITE_URL,
    'min_password_length' => MIN_PASSWORD_LENGTH,
    'require_special_char' => REQUIRE_SPECIAL_CHAR,
    'require_number' => REQUIRE_NUMBER
];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?? $settings;
}

// Session timeout
$timeout_duration = $settings['session_timeout'];
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login?error=Session timed out. Please log in again.");
    exit;
}
$_SESSION['last_activity'] = time();

// Check if the user is logged in as an admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login");
    exit;
}


// Get permissions for the current user
$userPermissions = $permissions[$accessLevel];

// Redirect if user doesn't have permission to change password
if (!$userPermissions['canChangePassword']) {
    header("Location: admin.php?error=You do not have permission to change your password.");
    exit;
}

// Load admins from JSON file
$adminsFile = ADMINS_FILE;
$admins = [];
if (file_exists($adminsFile)) {
    $admins = json_decode(file_get_contents($adminsFile), true) ?? [];
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $currentUsername = $_SESSION['username'];
    $adminIndex = array_search($currentUsername, array_column($admins, 'username'));

    if ($adminIndex !== false) {
        $admin = $admins[$adminIndex];
        if (password_verify($currentPassword, $admin['password'])) {
            if ($newPassword !== $confirmPassword) {
                $passwordChangeError = "New password and confirm password do not match.";
            } elseif (strlen($newPassword) < $settings['min_password_length']) {
                $passwordChangeError = "New password must be at least " . $settings['min_password_length'] . " characters long.";
            } elseif ($settings['require_special_char'] && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword)) {
                $passwordChangeError = "New password must contain at least one special character.";
            } elseif ($settings['require_number'] && !preg_match('/\d/', $newPassword)) {
                $passwordChangeError = "New password must contain at least one number.";
            } else {
                $admins[$adminIndex]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                file_put_contents($adminsFile, json_encode($admins, JSON_PRETTY_PRINT));

                $logFile = LOG_FILE;
                $logMessage = date('Y-m-d H:i:s') . " - Admin $currentUsername changed their password\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);

                $passwordChangeSuccess = "Password changed successfully.";
            }
        } else {
            $passwordChangeError = "Current password is incorrect.";
        }
    } else {
        $passwordChangeError = "Admin not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?php echo $settings['site_name']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Change Password</h1>
                <?php if (isset($passwordChangeSuccess)): ?>
                    <div class="success-message"><?php echo htmlspecialchars($passwordChangeSuccess); ?></div>
                <?php endif; ?>
                <?php if (isset($passwordChangeError)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($passwordChangeError); ?></div>
                <?php endif; ?>
            </header>

            <!-- Change Password Section -->
            <section id="change-password">
                <h2>Change Password</h2>
                <form method="POST" class="password-form">
                    <input type="hidden" name="change_password" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div class="form-group">
                        <label for="current_password">Current Password:</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit">Change Password</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>