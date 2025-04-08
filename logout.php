<?php
session_start();

// Include the config file
require_once 'config.php';

// Log the logout action
if (isset($_SESSION['username'])) {
    $logFile = LOG_FILE;
    $logMessage = date('Y-m-d H:i:s') . " - Admin {$_SESSION['username']} logged out\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php?success=You have been logged out successfully.");
exit;
?>