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
    // Fallback: Load default permissions if session is missing (shouldn’t happen after login)
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

// Check admin login
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login.php");
    exit;
}

// Load and filter jobs from MySQL
$searchTerm = strtolower($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$categoryFilter = $_GET['category'] ?? '';
$filteredJobs = [];

try {
    $query = "SELECT * FROM jobs WHERE 1=1";
    $params = [];

    if (!empty($searchTerm)) {
        $query .= " AND (LOWER(title) LIKE :search OR LOWER(description) LIKE :search)";
        $params[':search'] = "%$searchTerm%";
    }
    if ($statusFilter !== 'all') {
        $query .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }
    if (!empty($categoryFilter)) {
        $query .= " AND category = :category";
        $params[':category'] = $categoryFilter;
    }
    $query .= " ORDER BY date DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $filteredJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert apply_method from JSON string to array
    foreach ($filteredJobs as &$job) {
        if (isset($job['apply_method_type']) && isset($job['apply_method_value'])) {
            $job['apply_method'] = [
                'type' => $job['apply_method_type'],
                'value' => $job['apply_method_value']
            ];
        }
    }
    unset($job);
} catch (PDOException $e) {
    $filteredJobs = []; // Fallback to empty array if table doesn’t exist
}

// Get unique categories for filter
$categories = [];
try {
    $stmt = $db->query("SELECT DISTINCT category FROM jobs ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Jobs - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .main-content { padding: 20px; }
        .filter-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 10px; }
        .filter-bar input, .filter-bar select { padding: 8px; font-size: 14px; flex: 1; max-width: 200px; }
        .btn { padding: 8px 16px; font-size: 14px; border: none; border-radius: 5px; cursor: pointer; transition: background-color 0.3s; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-danger { background-color: #dc3545; color: white; }
        .btn-danger:hover { background-color: #b02a37; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f4f4f4; }
        .apply-method { display: inline-flex; align-items: center; }
        .copy-link { margin-left: 5px; color: #007bff; text-decoration: underline; cursor: pointer; font-size: 0.9em; }
        .success-message, .error-message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success-message { background-color: #d4edda; color: #155724; }
        .error-message { background-color: #f8d7da; color: #721c24; }
    </style>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => alert('Copied to clipboard!'));
        }
    </script>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <h1>Manage Jobs</h1>
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <div class="filter-bar">
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by title or description" onchange="updateFilter()">
                <select name="status" onchange="updateFilter()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="Published" <?php echo $statusFilter === 'Published' ? 'selected' : ''; ?>>Published</option>
                </select>
                <select name="category" onchange="updateFilter()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($_SESSION['permissions']['canPostJobs']): ?>
                    <a href="post-job.php" class="btn btn-primary">Add New Job</a>
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Apply Method</th>
                        <th>Status</th>
                        <th>Posted On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filteredJobs)): ?>
                        <tr><td colspan="8">No jobs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filteredJobs as $job): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($job['id']); ?></td>
                                <td><?php echo htmlspecialchars($job['title']); ?></td>
                                <td><?php echo htmlspecialchars($job['category']); ?></td>
                                <td><?php echo htmlspecialchars($job['location']); ?></td>
                                <td>
                                    <?php if (isset($job['apply_method'])): ?>
                                        <div class="apply-method">
                                            <?php if ($job['apply_method']['type'] === 'link'): ?>
                                                <a href="<?php echo htmlspecialchars($job['apply_method']['value']); ?>" target="_blank">Link</a>
                                                <span class="copy-link" onclick="copyToClipboard('<?php echo htmlspecialchars($job['apply_method']['value']); ?>')">Copy</span>
                                            <?php else: ?>
                                                <span><?php echo htmlspecialchars($job['apply_method']['value']); ?></span>
                                                <span class="copy-link" onclick="copyToClipboard('<?php echo htmlspecialchars($job['apply_method']['value']); ?>')">Copy</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($job['status']); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($job['date']))); ?></td>
                                <td>
                                    <?php if ($_SESSION['permissions']['canEditJobs']): ?>
                                        <a href="edit-job.php?id=<?php echo $job['id']; ?>" class="btn btn-primary">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($_SESSION['permissions']['canDeleteJobs']): ?>
                                        <a href="delete-job.php?id=<?php echo $job['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this job?');">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>
    <script>
        function updateFilter() {
            const search = document.querySelector('input[name="search"]').value;
            const status = document.querySelector('select[name="status"]').value;
            const category = document.querySelector('select[name="category"]').value;
            window.location.href = `manage-jobs.php?search=${encodeURIComponent(search)}&status=${status}&category=${encodeURIComponent(category)}`;
        }
    </script>
</body>
</html>