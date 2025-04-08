<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings from MySQL
$settings = [
    'session_timeout' => SESSION_TIMEOUT,
    'site_name' => SITE_NAME,
    'site_url' => SITE_URL
];
try {
    $stmt = $db->query("SELECT session_timeout, site_name, site_url FROM settings LIMIT 1");
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbSettings) {
        $settings['session_timeout'] = (int)$dbSettings['session_timeout'];
        $settings['site_name'] = $dbSettings['site_name'];
        $settings['site_url'] = $dbSettings['site_url'];
    }
} catch (PDOException $e) {
    // Fallback to config.php defaults
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

// Define access levels and permissions (simplified for this script)
$accessLevel = $_SESSION['access_level'] ?? 'viewer';
$permissions = [
    'super_admin' => ['canDeleteAdmins' => true],
    'editor' => ['canDeleteAdmins' => false],
    'moderator' => ['canDeleteAdmins' => false],
    'viewer' => ['canDeleteAdmins' => false]
];
// Check if the user has permission to delete admins
if (!$_SESSION['permissions']['canDeleteAdmins']) {
    header("Location: admin.php?error=You do not have permission to delete admins.");
    exit;
}

// Get the admin ID from the query string
$adminId = isset($_GET['id']) ? (int)$_GET['id'] : -1;

if ($adminId > 0) {
    try {
        // Fetch the admin's username for logging before deletion
        $stmt = $db->prepare("SELECT username, access_level FROM admins WHERE id = :id");
        $stmt->execute([':id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            // Prevent deleting the last super admin
            $stmt = $db->query("SELECT COUNT(*) FROM admins WHERE access_level = 'super_admin' AND status = 'approved'");
            $superAdminCount = $stmt->fetchColumn();
            if ($admin['access_level'] === 'super_admin' && $superAdminCount <= 1) {
                header("Location: admin.php?error=Cannot delete the last super admin.");
                exit;
            }

            // Prevent deleting self
            if ($admin['username'] === $_SESSION['username']) {
                header("Location: admin.php?error=You cannot delete yourself.");
                exit;
            }

            // Delete the admin
            $stmt = $db->prepare("DELETE FROM admins WHERE id = :id");
            $stmt->execute([':id' => $adminId]);

            // Log the action to MySQL logs table
            $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
            $stmt->execute([
                ':username' => $_SESSION['username'],
                ':action' => "Admin {$_SESSION['username']} deleted admin: {$admin['username']} (ID: $adminId)"
            ]);

            header("Location: admin.php?success=Admin deleted successfully");
            exit;
        } else {
            header("Location: admin.php?error=Admin not found.");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: admin.php?error=Failed to delete admin: " . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: admin.php?error=Invalid admin ID.");
    exit;
}
?>