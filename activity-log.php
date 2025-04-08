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

// Redirect if user doesn't have permission to view activity log
if (!$_SESSION['permissions']['canViewActivityLog']) {
    header("Location: admin.php?error=You do not have permission to view the activity log.");
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Pagination settings
$logsPerPage = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $logsPerPage;

// Filter settings
$actionFilter = isset($_GET['action']) ? trim($_GET['action']) : 'all';
$usernameFilter = isset($_GET['username']) ? trim($_GET['username']) : '';

// Define important actions to display (you can adjust this list)
$importantActions = [
    'updated permissions for admin',
    'created admin account',
    'deleted admin account',
    'deleted job',
    'updated job',
    'changed settings'
];

// Fetch unique usernames for filter dropdown
$usernames = [];
try {
    $stmt = $db->query("SELECT DISTINCT username FROM logs ORDER BY username");
    $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching usernames: " . $e->getMessage());
}

// Fetch total number of logs for pagination with filters
try {
    $query = "SELECT COUNT(*) FROM logs WHERE 1=1";
    $params = [];
    if ($actionFilter !== 'all') {
        $query .= " AND action LIKE :action";
        $params[':action'] = "%$actionFilter%";
    }
    if (!empty($usernameFilter)) {
        $query .= " AND username = :username";
        $params[':username'] = $usernameFilter;
    }
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $totalLogs = $stmt->fetchColumn();
    $totalPages = ceil($totalLogs / $logsPerPage);
} catch (PDOException $e) {
    error_log("Error fetching total logs: " . $e->getMessage());
    $error = "Error fetching total logs.";
}

// Fetch logs for the current page with filters
$logs = [];
try {
    $query = "SELECT * FROM logs WHERE 1=1";
    $params = [];
    if ($actionFilter !== 'all') {
        $query .= " AND action LIKE :action";
        $params[':action'] = "%$actionFilter%";
    }
    if (!empty($usernameFilter)) {
        $query .= " AND username = :username";
        $params[':username'] = $usernameFilter;
    }
    $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $logsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching logs: " . $e->getMessage());
    $error = "Error fetching logs.";
}

// Clean up old logs (e.g., older than 30 days)
try {
    $stmt = $db->prepare("DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
} catch (PDOException $e) {
    error_log("Error cleaning up old logs: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .log-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .log-table th, .log-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .log-table th {
            background-color: #f4f4f4;
        }
        .pagination {
            margin-top: 20px;
            text-align: center;
        }
        .pagination a {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 5px;
            background-color: #f4f4f4;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }
        .pagination a:hover {
            background-color: #ddd;
        }
        .pagination .active {
            background-color: #007bff;
            color: #fff;
        }
        .error-message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            background-color: #f8d7da;
            color: #721c24;
        }
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .filter-bar select {
            padding: 8px;
            font-size: 14px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>

        <main class="main-content">
            <header class="main-header">
                <h1>Activity Log</h1>
                <?php if (isset($error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </header>

            <section id="activity-log">
                <h2>Activity Log</h2>
                <div class="filter-bar">
                    <select name="action" onchange="updateFilter()">
                        <option value="all" <?php echo $actionFilter === 'all' ? 'selected' : ''; ?>>All Actions</option>
                        <?php foreach ($importantActions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $actionFilter === $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace(' for admin', '', $action))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="username" onchange="updateFilter()">
                        <option value="">All Users</option>
                        <?php foreach ($usernames as $username): ?>
                            <option value="<?php echo htmlspecialchars($username); ?>" <?php echo $usernameFilter === $username ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($username); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="log-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Action</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="3">No logs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['username']); ?></td>
                                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="activity-log.php?page=<?php echo $page - 1; ?>&action=<?php echo urlencode($actionFilter); ?>&username=<?php echo urlencode($usernameFilter); ?>">« Previous</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="activity-log.php?page=<?php echo $i; ?>&action=<?php echo urlencode($actionFilter); ?>&username=<?php echo urlencode($usernameFilter); ?>" <?php echo $i === $page ? 'class="active"' : ''; ?>><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="activity-log.php?page=<?php echo $page + 1; ?>&action=<?php echo urlencode($actionFilter); ?>&username=<?php echo urlencode($usernameFilter); ?>">Next »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        function updateFilter() {
            const action = document.querySelector('select[name="action"]').value;
            const username = document.querySelector('select[name="username"]').value;
            window.location.href = `activity-log.php?page=1&action=${encodeURIComponent(action)}&username=${encodeURIComponent(username)}`;
        }
    </script>
</body>
</html>