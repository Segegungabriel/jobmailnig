<?php
session_start();

// Include the config file
require_once 'config.php';

// Load admins from JSON file
$adminsFile = ADMINS_FILE;
$admins = [];
if (file_exists($adminsFile)) {
    $admins = json_decode(file_get_contents($adminsFile), true) ?? [];
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';

    // Find the admin
    $adminIndex = array_search($username, array_column($admins, 'username'));

    if ($adminIndex !== false) {
        // Generate a temporary password
        $tempPassword = bin2hex(random_bytes(8)); // Generate a random 16-character password
        $admins[$adminIndex]['password'] = password_hash($tempPassword, PASSWORD_DEFAULT);
        file_put_contents($adminsFile, json_encode($admins, JSON_PRETTY_PRINT));

        // Log the action
        $logFile = LOG_FILE;
        $logMessage = date('Y-m-d H:i:s') . " - Password reset for admin: $username\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        $resetSuccess = "Your temporary password is: <strong>$tempPassword</strong>. Please log in and change your password.";
    } else {
        $resetError = "Username not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>
    <div class="login-container">
        <h2>Reset Password</h2>
        <?php if (isset($resetError)): ?>
            <div class="error-message"><?php echo htmlspecialchars($resetError); ?></div>
        <?php endif; ?>
        <?php if (isset($resetSuccess)): ?>
            <div class="success-message"><?php echo $resetSuccess; ?></div>
        <?php else: ?>
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
        <p>Remember your password? <a href="login">Login here</a>.</p>
    </div>
</body>
</html>