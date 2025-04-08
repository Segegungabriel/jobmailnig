<?php
require_once 'config.php'; // Assumes $db is defined here as a PDO connection

// Load settings from MySQL (consistent with other pages)
$settings = [
    'site_name' => SITE_NAME, // Default from config.php
    'site_url' => SITE_URL
];
try {
    $stmt = $db->query("SELECT site_name, site_url FROM settings LIMIT 1");
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbSettings) {
        $settings['site_name'] = $dbSettings['site_name'];
        $settings['site_url'] = $dbSettings['site_url'];
    }
} catch (PDOException $e) {
    error_log("Error fetching settings: " . $e->getMessage());
}

// Fetch all categories from the categories table
try {
    $stmt = $db->prepare("SELECT name FROM categories ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($categories)) {
        $categories = []; // Fallback to empty array if no categories
    }
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Explore job categories on JobMailNig. Find opportunities in various industries across Nigeria.">
    <title>Categories - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="logo-container">
                <div class="logo">
                    <a href="index.php"><img src="jobm.jpg" alt="JobMailNig Logo"></a>
                </div>
                <div class="site-name">JobMailNig</div>
            </div>
            <nav class="nav-menu">
                <a href="index.php">Home</a>
                <a href="categories.php" class="active">Categories</a>
                <a href="contact.php">Contact</a>
                <a href="contact.php" class="post-job-btn">Post a Job</a>
                <a href="login.php" class="login-btn">Admin Login</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <section id="categories" class="categories-section">
            <h2>Explore Job Categories</h2>
            <ul class="category-list">
                <li class="category-item"><a href="index.php">All Categories</a></li>
                <?php foreach ($categories as $category): ?>
                    <li class="category-item"><a href="index.php?category=<?php echo urlencode($category); ?>"><?php echo htmlspecialchars($category); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <footer class="footer">
        <div class="footer-container">
            <p>© <?php echo date('Y'); ?> JobMailNig. All rights reserved.</p>
            <div class="footer-links">
                <a href="contact.php">Contact Us</a>
                <a href="privacy.php">Privacy Policy</a>
                <a href="terms.php">Terms of Service</a>
            </div>
        </div>
    </footer>
</body>
</html>