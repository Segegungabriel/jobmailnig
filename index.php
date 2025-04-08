<?php
require_once 'config.php'; // Assumes $db is defined here

// Fetch categories from the categories table
try {
    $categories = $db->query("SELECT name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($categories)) {
        $categories = [];
    }
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Fetch locations from the locations table
try {
    $locations = $db->query("SELECT name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($locations)) {
        $locations = [];
    }
} catch (PDOException $e) {
    error_log("Error fetching locations: " . $e->getMessage());
    $locations = [];
}

// Define constants for better maintainability
const DEFAULT_EXCHANGE_RATE = 1500; // Default USD to NGN rate (₦1,500/$1)
const EXCHANGE_RATE_API = 'https://api.exchangerate-api.com/v4/latest/USD';
const BASE_URL = "https://jobmailnig.ng";
const SUMMARY_LENGTH = 100; // Character limit for job description summary
const JOBS_PER_PAGE = 10; // For pagination

// Function to fetch exchange rate (USD to NGN)
function getExchangeRate($apiUrl, $defaultRate) {
    $exchangeData = @file_get_contents($apiUrl);
    if ($exchangeData === false) {
        return $defaultRate;
    }
    $exchangeJson = json_decode($exchangeData, true);
    return $exchangeJson['rates']['NGN'] ?? $defaultRate;
}

// Function to render a job listing
function renderJobListing($job, $exchangeRate, $baseUrl, $isSummary = true) {
    $html = '<div class="job-card">';
    $html .= '<div class="job-header">';
    if (!empty($job['image'])) {
        $html .= '<img src="' . htmlspecialchars($job['image']) . '" alt="Company Logo" class="company-logo">';
    }
    $html .= '<div class="job-title">';
    $html .= '<h3><a href="job.php?id=' . htmlspecialchars($job['id']) . '">' . htmlspecialchars($job['title']) . '</a></h3>';
    $html .= '<p class="company-name">' . htmlspecialchars($job['category']) . '</p>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="job-details">';
    if ($isSummary) {
        $html .= '<p class="description">' . htmlspecialchars(truncateText($job['description'], SUMMARY_LENGTH)) . '</p>';
    } else {
        $html .= '<p class="description">' . $job['description'] . '</p>';
    }
    $html .= '<div class="job-meta">';
    $html .= '<span><i class="fas fa-money-bill-wave"></i> $' . number_format($job['salary']) . ' (' . number_format($job['salary'] * $exchangeRate) . ' NGN)</span>';
    $html .= '<span><i class="fas fa-map-marker-alt"></i> ' . htmlspecialchars($job['location']) . '</span>';
    $html .= '<span><i class="fas fa-briefcase"></i> ' . htmlspecialchars($job['remoteType']) . '</span>';
    $html .= '<span><i class="fas fa-clock"></i> ' . htmlspecialchars($job['hours']) . '</span>';
    $html .= '</div>';
    $html .= '<small class="posted-date">Posted on: ' . htmlspecialchars($job['date']) . '</small>';
    $html .= '<div class="job-tags">';
    $html .= '<span class="actively-hiring">Actively Hiring</span>';
    if (!empty($job['topCompany'])) {
        $html .= '<span class="top-company">Top Company</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="job-actions">';
    $html .= '<a href="job.php?id=' . htmlspecialchars($job['id']) . '" class="apply-btn">View Details</a>';
    $html .= '<div class="share-job">';
    $html .= '<a href="https://www.facebook.com/sharer/sharer.php?u=' . urlencode($baseUrl . '/job.php?id=' . $job['id']) . '" target="_blank" class="facebook"><i class="fab fa-facebook-f"></i></a>';
    $html .= '<a href="https://twitter.com/intent/tweet?url=' . urlencode($baseUrl . '/job.php?id=' . $job['id']) . '&text=Check out this job: ' . htmlspecialchars($job['title']) . ' on JobMailNig!" target="_blank" class="twitter"><i class="fab fa-twitter"></i></a>';
    $html .= '<a href="https://www.linkedin.com/shareArticle?mini=true&url=' . urlencode($baseUrl . '/job.php?id=' . $job['id']) . '&title=' . htmlspecialchars($job['title']) . '&summary=' . htmlspecialchars(truncateText($job['description'], 200)) . '" target="_blank" class="linkedin"><i class="fab fa-linkedin-in"></i></a>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// Function to truncate text for summary
function truncateText($text, $length) {
    $text = strip_tags($text);
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

// Fetch exchange rate
$exchangeRate = getExchangeRate(EXCHANGE_RATE_API, DEFAULT_EXCHANGE_RATE);

// Get filter values
$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
$categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : '';
$locationFilter = isset($_GET['location']) ? trim($_GET['location']) : '';
$remoteTypeFilter = isset($_GET['remote-type']) ? trim($_GET['remote-type']) : '';
$hoursFilter = isset($_GET['hours']) ? trim($_GET['hours']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * JOBS_PER_PAGE;

// Load and filter jobs from MySQL
$query = "SELECT * FROM jobs WHERE status = 'Published'";
$params = [];
if ($search) {
    $query .= " AND (LOWER(title) LIKE :search OR LOWER(description) LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($categoryFilter) {
    $query .= " AND category = :category";
    $params[':category'] = $categoryFilter;
}
if ($locationFilter) {
    $query .= " AND location = :location";
    $params[':location'] = $locationFilter;
}
if ($remoteTypeFilter) {
    $query .= " AND remoteType = :remoteType";
    $params[':remoteType'] = $remoteTypeFilter;
}
if ($hoursFilter) {
    $query .= " AND hours = :hours";
    $params[':hours'] = $hoursFilter;
}
$query .= " ORDER BY date DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', JOBS_PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$allJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total number of jobs for pagination
$totalQuery = "SELECT COUNT(*) FROM jobs WHERE status = 'Published'";
$totalParams = [];
if ($search) {
    $totalQuery .= " AND (LOWER(title) LIKE :search OR LOWER(description) LIKE :search)";
    $totalParams[':search'] = "%$search%";
}
if ($categoryFilter) {
    $totalQuery .= " AND category = :category";
    $totalParams[':category'] = $categoryFilter;
}
if ($locationFilter) {
    $totalQuery .= " AND location = :location";
    $totalParams[':location'] = $locationFilter;
}
if ($remoteTypeFilter) {
    $totalQuery .= " AND remoteType = :remoteType";
    $totalParams[':remoteType'] = $remoteTypeFilter;
}
if ($hoursFilter) {
    $totalQuery .= " AND hours = :hours";
    $totalParams[':hours'] = $hoursFilter;
}
$stmt = $db->prepare($totalQuery);
$stmt->execute($totalParams);
$totalJobs = $stmt->fetchColumn();
$totalPages = ceil($totalJobs / JOBS_PER_PAGE);

// Get featured jobs
$featuredQuery = "SELECT * FROM jobs WHERE status = 'Published' AND featured = 1 AND remoteType != 'Fully Remote' ORDER BY topCompany DESC LIMIT 3";
$stmt = $db->prepare($featuredQuery);
$stmt->execute();
$featuredJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique values for filter dropdowns
$remoteTypes = $db->query("SELECT DISTINCT remoteType FROM jobs WHERE status = 'Published'")->fetchAll(PDO::FETCH_COLUMN);
$hours = $db->query("SELECT DISTINCT hours FROM jobs WHERE status = 'Published'")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Find verified jobs from top companies in Nigeria with JobMailNig. Explore opportunities from Dangote, MTN, Access Bank, and more!">
    <title>JobMailNig - Verified Jobs from Top Companies in Nigeria</title>
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
                <a href="categories.php">Categories</a>
                <a href="contact.php">Contact</a>
                <a href="contact.php" class="post-job-btn">Post a Job</a>
                <a href="login.php" class="login-btn">Admin Login</a>
            </nav>
        </div>
    </header>

    <section class="hero">
        <div class="hero-content">
            <h1>Discover Your Dream Job in Nigeria</h1>
            <p>Join top companies like Dangote, MTN, and Access Bank. Start your career journey today!</p>
            <a href="#filters" class="cta-button">Find Jobs Now</a>
        </div>
    </section>

    <div class="container">
        <section class="top-companies">
            <h2>Trusted by Top Companies</h2>
            <div class="company-logos">
                <a href="https://dangote.com" target="_blank" class="company-text">Dangote</a>
                <a href="https://mtn.ng" target="_blank" class="company-text">MTN</a>
                <a href="https://accessbankplc.com" target="_blank" class="company-text">Access Bank</a>
                <a href="https://gtbank.com" target="_blank" class="company-text">Guaranty Trust</a>
            </div>
        </section>

        <section class="welcome-message">
            <h2>Unlock Your Career Potential with JobMailNig!</h2>
            <p>Discover verified opportunities from Nigeria’s top employers. Start your journey to a fulfilling career today!</p>
        </section>

        <section id="filters" class="filters-section">
            <h2>Find Your Dream Job</h2>
            <form method="GET" action="index.php">
                <div class="filter-group">
                    <input type="text" id="search" name="search" placeholder="Search jobs (e.g., Accountant, Lagos)" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <select id="category-filter" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="location-filter" name="location">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $locationFilter === $location ? 'selected' : ''; ?>><?php echo htmlspecialchars($location); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="remote-type-filter" name="remote-type">
                        <option value="">All Work Types</option>
                        <?php foreach ($remoteTypes as $remoteType): ?>
                            <option value="<?php echo htmlspecialchars($remoteType); ?>" <?php echo $remoteTypeFilter === $remoteType ? 'selected' : ''; ?>><?php echo htmlspecialchars($remoteType); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="hours-filter" name="hours">
                        <option value="">All Hours</option>
                        <?php foreach ($hours as $hour): ?>
                            <option value="<?php echo htmlspecialchars($hour); ?>" <?php echo $hoursFilter === $hour ? 'selected' : ''; ?>><?php echo htmlspecialchars($hour); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Search</button>
            </form>
        </section>

        <section id="featured-jobs" class="<?php echo empty($featuredJobs) ? 'hidden' : ''; ?>">
            <h2>Featured Jobs</h2>
            <div class="job-grid">
                <?php if (empty($featuredJobs)): ?>
                    <div class="no-jobs">
                        <p>No featured jobs available for the selected filters.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($featuredJobs as $job): ?>
                        <?php echo renderJobListing($job, $exchangeRate, BASE_URL, true); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section id="job-list">
            <h2>All Jobs</h2>
            <div class="job-grid">
                <?php if (empty($allJobs)): ?>
                    <div class="no-jobs">
                        <p>No jobs available yet. Check back soon or try adjusting your filters!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($allJobs as $job): ?>
                        <?php echo renderJobListing($job, $exchangeRate, BASE_URL, true); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="index.php?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($categoryFilter); ?>&location=<?php echo urlencode($locationFilter); ?>&remote-type=<?php echo urlencode($remoteTypeFilter); ?>&hours=<?php echo urlencode($hoursFilter); ?>">« Previous</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="index.php?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($categoryFilter); ?>&location=<?php echo urlencode($locationFilter); ?>&remote-type=<?php echo urlencode($remoteTypeFilter); ?>&hours=<?php echo urlencode($hoursFilter); ?>" <?php echo $i === $page ? 'class="active"' : ''; ?>><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="index.php?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($categoryFilter); ?>&location=<?php echo urlencode($locationFilter); ?>&remote-type=<?php echo urlencode($remoteTypeFilter); ?>&hours=<?php echo urlencode($hoursFilter); ?>">Next »</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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