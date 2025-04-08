<?php
session_start();
require_once 'config.php';
require_once 'permissions.php';
require_once 'utils.php';

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);

// Ensure permissions are loaded from session
if (!isset($_SESSION['permissions'])) {
    $accessLevel = $_SESSION['access_level'] ?? 'viewer';
    $_SESSION['permissions'] = $permissions[$accessLevel];
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

// Redirect if user doesn't have permission to manage permissions
if (!$_SESSION['permissions']['canManagePermissions']) {
    header("Location: admin.php?error=You do not have permission to manage permissions.");
    exit;
}

// Load admins from MySQL
$admins = [];
try {
    $stmt = $db->query("SELECT * FROM admins ORDER BY id ASC");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $admins = [];
}

// Load custom permissions for all admins
$allAdminPermissions = [];
foreach ($admins as $admin) {
    $adminId = $admin['id'];
    $adminPerms = $permissions[$admin['access_level']]; // Start with defaults
    try {
        $stmt = $db->prepare("SELECT permission_key, permission_value FROM admin_permissions WHERE admin_id = :admin_id");
        $stmt->execute([':admin_id' => $adminId]);
        $custom = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($custom as $key => $value) {
            $adminPerms[$key] = (bool)$value;
        }
    } catch (PDOException $e) {
        // Fallback to defaults
    }
    $allAdminPermissions[$adminId] = $adminPerms;
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }

    $adminId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : -1;
    if ($adminId > 0) {
        try {
            // Fetch current admin details
            $stmt = $db->prepare("SELECT username, access_level, status FROM admins WHERE id = :id");
            $stmt->execute([':id' => $adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin) {
                // Prevent modifying the primary super admin's permissions
                if ($admin['username'] === 'superadmin' && $admin['access_level'] === 'super_admin') {
                    header("Location: manage-permissions.php?error=Cannot modify permissions of the primary super admin.");
                    exit;
                }

                // Prevent demoting the last super admin
                $stmt = $db->query("SELECT COUNT(*) FROM admins WHERE access_level = 'super_admin' AND status = 'approved'");
                $superAdminCount = $stmt->fetchColumn();
                if ($admin['access_level'] === 'super_admin' && $superAdminCount <= 1 && (!isset($_POST['canManagePermissions']) || $_POST['canManagePermissions'] != 1)) {
                    header("Location: manage-permissions.php?error=Cannot remove permissions management from the last super admin.");
                    exit;
                }

                // Update access level
                $newAccessLevel = trim($_POST['access_level'] ?? '');
                if (in_array($newAccessLevel, ['super_admin', 'editor', 'moderator', 'viewer'])) {
                    $stmt = $db->prepare("UPDATE admins SET access_level = :access_level WHERE id = :id");
                    $stmt->execute([
                        ':access_level' => $newAccessLevel,
                        ':id' => $adminId
                    ]);
                }

                // Update custom permissions
                $permissionsToUpdate = [
                    'canPostJobs', 'canEditJobs', 'canDeleteJobs', 'canManageAdmins',
                    'canGenerateRSS', 'canViewStats', 'canChangePassword', 'canViewActivityLog',
                    'canManageSettings', 'canManagePermissions', 'canManageBlog'
                ];

                $updatedPermissions = $permissions[$newAccessLevel]; // Start with defaults for new access level
                foreach ($permissionsToUpdate as $perm) {
                    $value = isset($_POST[$perm]) && $_POST[$perm] == '1' ? 1 : 0;
                    $stmt = $db->prepare("
                        INSERT INTO admin_permissions (admin_id, permission_key, permission_value)
                        VALUES (:admin_id, :permission_key, :permission_value)
                        ON DUPLICATE KEY UPDATE permission_value = :permission_value
                    ");
                    $stmt->execute([
                        ':admin_id' => $adminId,
                        ':permission_key' => $perm,
                        ':permission_value' => $value
                    ]);
                    $updatedPermissions[$perm] = (bool)$value; // Update in memory
                }

                // If updating the current user, refresh session permissions
                if ($adminId === $_SESSION['admin_id']) {
                    $_SESSION['permissions'] = $updatedPermissions;
                    $_SESSION['access_level'] = $newAccessLevel;
                }

                // Log the action
                $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
                $stmt->execute([
                    ':username' => $_SESSION['username'],
                    ':action' => "Admin {$_SESSION['username']} updated permissions for admin: {$admin['username']}"
                ]);

                header("Location: manage-permissions.php?success=Permissions updated successfully");
                exit;
            }
        } catch (PDOException $e) {
            header("Location: manage-permissions.php?error=Database error: " . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header("Location: manage-permissions.php?error=Invalid admin ID.");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .admin-table th, .admin-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .admin-table th {
            background-color: #f4f4f4;
        }
        .btn {
            display: inline-block;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn {
            background-color: #007bff;
            color: #fff;
        }
        .btn:hover {
            background-color: #0056b3;
        }
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        .btn-danger {
            background-color: #dc3545;
            color: #fff;
        }
        .btn-danger:hover {
            background-color: #b02a37;
        }
        .success-message, .error-message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .success-message {
            background-color: #d4edda;
            color: #155724;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }
        .permission-form label {
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Admin Dashboard</h2>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="admin.php" <?php echo basename($_SERVER['PHP_SELF']) === 'admin.php' ? 'class="active"' : ''; ?>><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <?php if ($_SESSION['permissions']['canPostJobs']): ?>
                        <li><a href="post-job.php" <?php echo basename($_SERVER['PHP_SELF']) === 'post-job.php' ? 'class="active"' : ''; ?>><i class="fas fa-plus-circle"></i> Post Job</a></li>
                    <?php endif; ?>
                    <li><a href="manage-jobs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-jobs.php' ? 'class="active"' : ''; ?>><i class="fas fa-briefcase"></i> Manage Jobs</a></li>
                    <?php if ($_SESSION['permissions']['canManageAdmins']): ?>
                        <li><a href="manage-admins.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-admins.php' ? 'class="active"' : ''; ?>><i class="fas fa-users"></i> Manage Admins</a></li>
                        <li><a href="manage-permissions.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-permissions.php' ? 'class="active"' : ''; ?>><i class="fas fa-user-shield"></i> Manage Permissions</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['permissions']['canGenerateRSS']): ?>
                        <li><a href="generate-rss.php" <?php echo basename($_SERVER['PHP_SELF']) === 'generate-rss.php' ? 'class="active"' : ''; ?>><i class="fas fa-rss"></i> Generate RSS</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['permissions']['canViewStats']): ?>
                        <li><a href="job-stats.php" <?php echo basename($_SERVER['PHP_SELF']) === 'job-stats.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-bar"></i> Job Statistics</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['permissions']['canViewActivityLog']): ?>
                        <li><a href="activity-log.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity-log.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Log</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['permissions']['canChangePassword']): ?>
                        <li><a href="change-password.php" <?php echo basename($_SERVER['PHP_SELF']) === 'change-password.php' ? 'class="active"' : ''; ?>><i class="fas fa-lock"></i> Change Password</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['permissions']['canManageSettings']): ?>
                        <li><a href="settings.php" <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'class="active"' : ''; ?>><i class="fas fa-cog"></i> Settings</a></li>
                    <?php endif; ?>
                    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <header class="main-header">
                <h1>Manage Permissions</h1>
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <section id="manage-permissions">
                <h2>Manage Admin Permissions</h2>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Access Level</th>
                            <th>Permissions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr>
                                <td colspan="4">No admins found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $admin['access_level']))); ?></td>
                                    <td>
                                        <form method="POST" class="permission-form">
                                            <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <div class="form-group">
                                                <label>Access Level:</label>
                                                <select name="access_level" required>
                                                    <option value="super_admin" <?php echo $admin['access_level'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                                    <option value="editor" <?php echo $admin['access_level'] === 'editor' ? 'selected' : ''; ?>>Editor</option>
                                                    <option value="moderator" <?php echo $admin['access_level'] === 'moderator' ? 'selected' : ''; ?>>Moderator</option>
                                                    <option value="viewer" <?php echo $admin['access_level'] === 'viewer' ? 'selected' : ''; ?>>Viewer</option>
                                                </select>
                                            </div>
                                            <?php
                                            $adminPerms = $allAdminPermissions[$admin['id']];
                                            $permissionLabels = [
                                                'canPostJobs' => 'Post Jobs',
                                                'canEditJobs' => 'Edit Jobs',
                                                'canDeleteJobs' => 'Delete Jobs',
                                                'canManageAdmins' => 'Manage Admins',
                                                'canGenerateRSS' => 'Generate RSS',
                                                'canViewStats' => 'View Stats',
                                                'canChangePassword' => 'Change Password',
                                                'canViewActivityLog' => 'View Activity Log',
                                                'canManageSettings' => 'Manage Settings',
                                                'canManagePermissions' => 'Manage Permissions',
                                                'canManageBlog' => 'Manage Blog'
                                            ];
                                            foreach ($permissionLabels as $permKey => $label): ?>
                                                <label>
                                                    <input type="checkbox" name="<?php echo $permKey; ?>" value="1" <?php echo $adminPerms[$permKey] ? 'checked' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </label>
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-small">Update</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($admin['status'] === 'pending'): ?>
                                            <span>Pending Approval</span>
                                        <?php else: ?>
                                            <a href="delete-admin.php?id=<?php echo $admin['id']; ?>" class="btn btn-small btn-danger" onclick="return confirm('Are you sure you want to delete this admin?');">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>