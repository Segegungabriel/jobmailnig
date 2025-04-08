<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);


// Session timeout
$timeout_duration = SESSION_TIMEOUT;
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

// Fetch current user's access level from the admins table
try {
    $stmt = $db->prepare("SELECT access_level FROM admins WHERE username = :username");
    $stmt->execute([':username' => $_SESSION['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $accessLevel = $user['access_level'] ?? 'viewer';
} catch (PDOException $e) {
    header("Location: admin.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}

// Get permissions for the current user
// Redirect if user doesn't have permission to generate RSS
if (!$_SESSION['permissions']['canGenerateRSS']) {
    header("Location: admin.php?error=You do not have permission to generate RSS feeds.");
    exit;
}

// Generate the RSS feed
if (isset($_GET['action']) && $_GET['action'] === 'generate') {
    try {
        // Fetch only published jobs from MySQL
        $stmt = $db->prepare("SELECT id, title, date FROM jobs WHERE status = 'Published' ORDER BY date DESC");
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log the action
        $logMessage = date('Y-m-d H:i:s') . " - Admin {$_SESSION['username']} generated RSS feed\n";
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);

        // Output RSS feed
        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        ?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
            <channel>
                <title><?php echo htmlspecialchars(SITE_NAME); ?> Job Listings</title>
                <link><?php echo htmlspecialchars(SITE_URL); ?></link>
                <description>Latest job listings from <?php echo htmlspecialchars(SITE_NAME); ?></description>
                <language>en-us</language>
                <lastBuildDate><?php echo date('r'); ?></lastBuildDate>
                <atom:link href="<?php echo htmlspecialchars(SITE_URL . '/generate-rss.php?action=generate'); ?>" rel="self" type="application/rss+xml" />
                <?php foreach ($jobs as $job): ?>
                    <item>
                        <title><?php echo htmlspecialchars($job['title']); ?></title>
                        <link><?php echo htmlspecialchars(SITE_URL . '/job.php?id=' . $job['id']); ?></link>
                        <guid><?php echo htmlspecialchars(SITE_URL . '/job.php?id=' . $job['id']); ?></guid>
                        <pubDate><?php echo date('r', strtotime($job['date'])); ?></pubDate>
                        <description><![CDATA[Click the link to view this job posting on <?php echo htmlspecialchars(SITE_NAME); ?>]]></description>
                    </item>
                <?php endforeach; ?>
            </channel>
        </rss>
        <?php
        exit;
    } catch (PDOException $e) {
        header("Location: generate-rss.php?error=Database error: " . urlencode($e->getMessage()));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate RSS Feed - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .btn { display: inline-block; padding: 10px 20px; background-color: #007bff; color: #fff; text-decoration: none; border-radius: 5px; font-weight: 500; transition: background-color 0.3s ease; }
        .btn:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <header class="main-header">
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <section id="generate-rss">
                <h2>Generate RSS Feed</h2>
                <p>Click the button below to generate an RSS feed of all published job listings. The feed will include job titles as clickable links to view each job.</p>
                <a href="generate-rss.php?action=generate" class="btn">Generate RSS Feed</a>
            </section>
        </main>
    </div>
</body>
</html>