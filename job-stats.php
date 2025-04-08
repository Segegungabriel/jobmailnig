<?php
session_start();

// Include the config file
require_once 'config.php';

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
    header("Location: login.php?error=Session timed out. Please log in again.");
    exit;
}
$_SESSION['last_activity'] = time();

// Check if the user is logged in as an admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch access level from session or database
try {
    $stmt = $db->prepare("SELECT access_level FROM admins WHERE username = :username");
    $stmt->execute([':username' => $_SESSION['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $accessLevel = $user['access_level'] ?? 'viewer';
} catch (PDOException $e) {
    header("Location: admin.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}


// Redirect if user doesn't have permission to view stats
if (!$_SESSION['permissions']['canViewStats']) {
    header("Location: admin.php?error=You do not have permission to view job statistics.");
    exit;
}

// Calculate job statistics using SQL
try {
    // Total jobs
    $stmt = $db->query("SELECT COUNT(*) as count FROM jobs");
    $totalJobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Active jobs (not expired)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE expiration_date >= CURDATE()");
    $stmt->execute();
    $activeJobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Expired jobs
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE expiration_date < CURDATE()");
    $stmt->execute();
    $expiredJobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Jobs posted today
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE DATE(date) = CURDATE()");
    $stmt->execute();
    $jobsToday = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Jobs posted this week (Monday to Sunday)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE DATE(date) >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE(date) <= CURDATE()");
    $stmt->execute();
    $jobsThisWeek = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Jobs last week (for trend comparison)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE DATE(date) >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY) AND DATE(date) < DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)");
    $stmt->execute();
    $jobsLastWeek = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $weekTrend = $jobsLastWeek > 0 ? round((($jobsThisWeek - $jobsLastWeek) / $jobsLastWeek) * 100, 1) : 0;

    // Jobs this year
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE YEAR(date) = YEAR(CURDATE())");
    $stmt->execute();
    $jobsThisYear = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Top 3 categories
    $stmt = $db->query("SELECT category, COUNT(*) as count FROM jobs GROUP BY category ORDER BY count DESC LIMIT 3");
    $topCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 3 locations
    $stmt = $db->query("SELECT location, COUNT(*) as count FROM jobs GROUP BY location ORDER BY count DESC LIMIT 3");
    $topLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    header("Location: admin.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Insights Dashboard - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff, #f9f9f9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card i {
            font-size: 32px;
            color: #007bff;
            margin-bottom: 10px;
        }
        .stat-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
            color: #555;
        }
        .stat-card p {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            color: #333;
        }
        .stat-card .trend {
            font-size: 14px;
            color: <?php echo $weekTrend >= 0 ? '#28a745' : '#dc3545'; ?>;
            margin-top: 5px;
        }
        .top-list {
            margin-top: 20px;
            padding: 15px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .top-list h3 {
            margin: 0 0 10px;
            font-size: 18px;
            color: #333;
        }
        .top-list ul {
            list-style: none;
            padding: 0;
        }
        .top-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }
        .top-list li:last-child {
            border-bottom: none;
        }
        .chart-container {
            margin-top: 30px;
            max-width: 800px;
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Job Insights Dashboard</h1>
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <!-- Job Statistics Section -->
            <section id="job-stats">
                <h2>At a Glance</h2>
                <div class="dashboard-stats">
                    <div class="stat-card">
                        <i class="fas fa-briefcase"></i>
                        <h3>Total Jobs</h3>
                        <p><?php echo $totalJobs; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-check-circle"></i>
                        <h3>Active Jobs</h3>
                        <p><?php echo $activeJobs; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-times-circle"></i>
                        <h3>Expired Jobs</h3>
                        <p><?php echo $expiredJobs; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-clock"></i>
                        <h3>Jobs Today</h3>
                        <p><?php echo $jobsToday; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-calendar-week"></i>
                        <h3>Jobs This Week</h3>
                        <p><?php echo $jobsThisWeek; ?></p>
                        <span class="trend"><?php echo $weekTrend >= 0 ? "Up $weekTrend%" : "Down " . abs($weekTrend) . "%"; ?> from last week</span>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-calendar-alt"></i>
                        <h3>Jobs This Year</h3>
                        <p><?php echo $jobsThisYear; ?></p>
                    </div>
                </div>

                <!-- Top Categories and Locations -->
                <div class="dashboard-stats">
                    <div class="top-list">
                        <h3>Top Categories</h3>
                        <ul>
                            <?php foreach ($topCategories as $cat): ?>
                                <li><?php echo htmlspecialchars($cat['category']); ?> <span><?php echo $cat['count']; ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="top-list">
                        <h3>Top Locations</h3>
                        <ul>
                            <?php foreach ($topLocations as $loc): ?>
                                <li><?php echo htmlspecialchars($loc['location']); ?> <span><?php echo $loc['count']; ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Chart Placeholder -->
                <div class="chart-container">
                    <h3>Job Postings Over Time</h3>
                    <canvas id="jobChart"></canvas>
                </div>
            </section>
        </main>
    </div>

    <!-- Chart.js Script -->
    <script>
        // Placeholder data (replace with actual SQL queries for production)
        const ctx = document.getElementById('jobChart').getContext('2d');
        const jobChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Jobs Posted',
                    data: [12, 19, 3, 5, 2, 3, 10, 15, 20, 25, 18, 22], // Replace with real data
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Number of Jobs' }
                    },
                    x: { title: { display: true, text: 'Month' } }
                }
            }
        });
    </script>
</body>
</html>