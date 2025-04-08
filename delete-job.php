<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);

// Load settings from JSON file (optional, for consistency with edit-job.php)
$settingsFile = 'settings.json';
$settings = [
    'session_timeout' => SESSION_TIMEOUT,
    'site_name' => SITE_NAME
];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?? $settings;
}

// Session timeout
$timeout_duration = $settings['session_timeout'];
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=Session timed out. Please log in again.");
    exit;
}
$_SESSION['last_activity'] = time();

// Check if the user is logged in as an admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login.php");
    exit;
}


// Redirect if user doesn't have permission to delete jobs
if (!$_SESSION['permissions']['canDeleteJobs']) {
    header("Location: manage-jobs.php?error=You do not have permission to delete jobs.");
    exit;
}

// Get the job ID
$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($jobId <= 0) {
    header("Location: manage-jobs.php?error=Invalid job ID.");
    exit;
}

// Fetch the job to get its title for logging
try {
    $stmt = $db->prepare("SELECT title FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        header("Location: manage-jobs.php?error=Job not found.");
        exit;
    }
    $deletedJobTitle = $job['title'];
} catch (PDOException $e) {
    header("Location: manage-jobs.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}

// Delete the job from MySQL
try {
    $stmt = $db->prepare("DELETE FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    
    // Log the action
    $logMessage = date('Y-m-d H:i:s') . " - Admin {$_SESSION['username']} deleted job: $deletedJobTitle (ID: $jobId)\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);

    header("Location: manage-jobs.php?success=Job deleted successfully");
    exit;
} catch (PDOException $e) {
    header("Location: manage-jobs.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}
?>