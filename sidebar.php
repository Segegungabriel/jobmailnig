<?php
// sidebar.php
// Assumes $_SESSION['permissions'] is set after login and available in all including files
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <h2>Admin Dashboard</h2>
    </div>
    <nav class="sidebar-nav">
        <ul>
            <li>
                <a href="admin.php" <?php echo basename($_SERVER['PHP_SELF']) === 'admin.php' ? 'class="active"' : ''; ?>>
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <?php if ($_SESSION['permissions']['canPostJobs']): ?>
                <li>
                    <a href="post-job.php" <?php echo basename($_SERVER['PHP_SELF']) === 'post-job.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-plus-circle"></i> Post Job
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="manage-jobs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-jobs.php' ? 'class="active"' : ''; ?>>
                    <i class="fas fa-briefcase"></i> Manage Jobs
                </a>
            </li>
            <?php if ($_SESSION['permissions']['canManageAdmins']): ?>
                <li>
                    <a href="manage-admins.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-admins.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-users"></i> Manage Admins
                    </a>
                </li>
                <li>
                    <a href="manage-permissions.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage-permissions.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-user-shield"></i> Manage Permissions
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($_SESSION['permissions']['canGenerateRSS']): ?>
                <li>
                    <a href="generate-rss.php" <?php echo basename($_SERVER['PHP_SELF']) === 'generate-rss.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-rss"></i> Generate RSS
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($_SESSION['permissions']['canViewStats']): ?>
                <li>
                    <a href="job-stats.php" <?php echo basename($_SERVER['PHP_SELF']) === 'job-stats.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-chart-bar"></i> Job Insights
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($_SESSION['permissions']['canViewActivityLog']): ?>
                <li>
                    <a href="activity-log.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity-log.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-history"></i> Activity Log
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($_SESSION['permissions']['canChangePassword']): ?>
                <li>
                    <a href="change-password.php" <?php echo basename($_SERVER['PHP_SELF']) === 'change-password.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-lock"></i> Change Password
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($_SESSION['permissions']['canManageSettings']): ?>
                <li>
                    <a href="settings.php" <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>

<style>
    .sidebar {
        width: 250px;
        background: #2c3e50;
        color: #ffffff;
        padding: 20px;
        position: fixed;
        height: 100%;
        overflow-y: auto;
        top: 0;
        left: 0;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
        font-family: 'Poppins', sans-serif; /* Match main font */
    }
    .sidebar-header h2 {
        margin: 0 0 20px;
        font-size: 24px;
        font-weight: 700;
        text-align: center;
    }
    .sidebar-nav ul {
        list-style: none;
        padding: 0;
    }
    .sidebar-nav li {
        margin: 10px 0;
    }
    .sidebar-nav a {
        color: #fff;
        text-decoration: none;
        display: flex;
        align-items: center;
        padding: 10px;
        border-radius: 5px;
        transition: background 0.3s ease, padding-left 0.3s ease;
    }
    .sidebar-nav a:hover, .sidebar-nav a.active {
        background: #34495e;
        padding-left: 15px;
    }
    .sidebar-nav i {
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }
</style>