<?php
session_start();
require_once 'config.php';
require_once 'permissions.php';
require_once 'utils.php';

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);

// Load user permissions
$userPermissions = loadUserPermissions($db);

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Fetch dashboard metrics
$metrics = [
    'total_jobs' => 0,
    'total_admins' => 0,
    'top_categories' => [],
    'jobs_by_location' => [],
    'recent_logs' => []
];
try {
    $stmt = $db->query("SELECT COUNT(*) FROM jobs");
    $metrics['total_jobs'] = $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM admins WHERE status = 'approved'");
    $metrics['total_admins'] = $stmt->fetchColumn();

    $stmt = $db->query("SELECT category FROM jobs");
    $rawCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $mergedCategories = mergeSimilarCategories($rawCategories);

    $stmt = $db->query("SELECT category, COUNT(*) as count FROM jobs GROUP BY category");
    $categoryCountsRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $categoryCounts = [];
    foreach ($mergedCategories as $category) {
        $count = 0;
        foreach ($categoryCountsRaw as $rawCategory => $rawCount) {
            $normalizedCategory = mergeSimilarCategories([$rawCategory])[0];
            if ($normalizedCategory === $category) {
                $count += $rawCount;
            }
        }
        $categoryCounts[$category] = $count;
    }
    arsort($categoryCounts);
    $metrics['top_categories'] = array_slice($categoryCounts, 0, 5, true);

    $locations = getLocations();
    $locationCounts = [];
    foreach ($locations as $location) {
        if ($location === 'all') continue;
        $stmt = $db->prepare("SELECT COUNT(*) FROM jobs WHERE location = :location");
        $stmt->execute([':location' => $location]);
        $count = $stmt->fetchColumn();
        $locationCounts[$location] = $count;
    }
    arsort($locationCounts);
    $metrics['jobs_by_location'] = array_slice($locationCounts, 0, 5, true);

    if ($userPermissions['canViewActivityLog']) {
        $stmt = $db->query("SELECT * FROM logs ORDER BY created_at DESC LIMIT 5");
        $metrics['recent_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = "Error fetching dashboard metrics: " . $e->getMessage();
    $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
    $stmt->execute([
        ':username' => $_SESSION['username'],
        ':action' => $error
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        :root {
            --primary: #4a90e2;
            --secondary: #2ecc71;
            --dark: #2c3e50;
            --light: #ecf0f1;
            --white: #ffffff;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --gradient: linear-gradient(135deg, #4a90e2, #2ecc71);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--light);
            margin: 0;
            padding: 0;
            color: var(--dark);
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            background: var(--white);
            transition: margin-left 0.3s ease;
        }

        .main-header {
            background: var(--gradient);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            color: var(--white);
        }

        .main-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card h3 {
            margin: 0 0 10px;
            font-size: 1.1rem;
            color: var(--dark);
            font-weight: 400;
        }

        .stat-card p {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .metrics-table, .recent-logs {
            background: var(--white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .metrics-table h3, .recent-logs h3 {
            margin: 0 0 15px;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .metrics-table table, .recent-logs table {
            width: 100%;
            border-collapse: collapse;
        }

        .metrics-table th, .metrics-table td, .recent-logs th, .recent-logs td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .metrics-table th, .recent-logs th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
        }

        .metrics-table td, .recent-logs td {
            color: #555;
        }

        .metrics-table tr:hover, .recent-logs tr:hover {
            background: #f9f9f9;
        }

        .error-message, .success-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }

        .error-message {
            background: #ffe6e6;
            color: #d32f2f;
            border-left: 4px solid #d32f2f;
        }

        .success-message {
            background: #e6ffe6;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .main-header h1 {
                font-size: 1.5rem;
            }

            .stat-card p {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <header class="main-header">
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
                <?php if (isset($error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
            </header>

            <section id="dashboard">
                <div class="dashboard-stats">
                    <div class="stat-card">
                        <h3>Total Jobs</h3>
                        <p><?php echo $metrics['total_jobs']; ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Admins</h3>
                        <p><?php echo $metrics['total_admins']; ?></p>
                    </div>
                </div>

                <div class="metrics-table">
                    <h3>Top Job Categories</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Job Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($metrics['top_categories'])): ?>
                                <tr>
                                    <td colspan="2">No jobs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($metrics['top_categories'] as $category => $count): ?>
                                    <?php if ($count > 0): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($category); ?></td>
                                            <td><?php echo $count; ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="metrics-table">
                    <h3>Jobs by Location (Top 5)</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Job Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($metrics['jobs_by_location'])): ?>
                                <tr>
                                    <td colspan="2">No jobs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($metrics['jobs_by_location'] as $location => $count): ?>
                                    <?php if ($count > 0): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($location); ?></td>
                                            <td><?php echo $count; ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($userPermissions['canViewActivityLog'] && !empty($metrics['recent_logs'])): ?>
                    <div class="recent-logs">
                        <h3>Recent Activity</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Action</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($metrics['recent_logs'] as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['username']); ?></td>
                                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>