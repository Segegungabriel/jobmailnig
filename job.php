<?php
session_start();
require_once 'config.php';

// Load settings from MySQL (assuming you might have a settings table)
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

// Get job ID from URL
$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$job = null;

// Load job from MySQL
$stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id AND status = 'Published'");
$stmt->execute([':id' => $jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header("Location: index.php?error=Job not found or not published.");
    exit;
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Define exchange rate (consistent with index.php)
const DEFAULT_EXCHANGE_RATE = 1500; // Default USD to NGN rate (₦1,500/$1)
const EXCHANGE_RATE_API = 'https://api.exchangerate-api.com/v4/latest/USD';
const BASE_URL = "https://jobmailnig.ng";

// Function to fetch exchange rate (USD to NGN)
function getExchangeRate($apiUrl, $defaultRate) {
    $exchangeData = @file_get_contents($apiUrl);
    if ($exchangeData === false) {
        return $defaultRate;
    }
    $exchangeJson = json_decode($exchangeData, true);
    return $exchangeJson['rates']['NGN'] ?? $defaultRate;
}

$exchangeRate = getExchangeRate(EXCHANGE_RATE_API, DEFAULT_EXCHANGE_RATE);

// Function to truncate text for summary (used in share links)
function truncateText($text, $length) {
    $text = strip_tags($text);
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars(strip_tags(substr($job['description'], 0, 150))); ?>...">
    <title><?php echo htmlspecialchars($job['title']); ?> - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => alert('Copied to clipboard!'));
        }
    </script>
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

    <div class="container">
        <main class="job-details">
            <div class="job-header">
                <div class="job-title">
                    <h1><?php echo htmlspecialchars($job['title']); ?></h1>
                    <p class="company-name"><?php echo htmlspecialchars($job['category']); ?></p>
                </div>
                <a href="index.php" class="back-btn">Back to Jobs</a>
            </div>

            <div class="job-meta">
                <div class="meta-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?php echo htmlspecialchars($job['location']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-briefcase"></i>
                    <span><?php echo htmlspecialchars($job['remoteType']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-clock"></i>
                    <span><?php echo htmlspecialchars($job['hours']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>
                        <?php
                        $currencySymbol = $job['currency'] === 'NGN' ? '₦' : ($job['currency'] === 'USD' ? '$' : ($job['currency'] === 'EUR' ? '€' : '£'));
                        $salary = number_format($job['salary'] ?? 0);
                        echo htmlspecialchars($currencySymbol . $salary);
                        if ($job['currency'] === 'USD') {
                            echo ' (' . number_format($job['salary'] * $exchangeRate) . ' NGN)';
                        }
                        ?>
                    </span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Posted on: <?php echo htmlspecialchars($job['date']); ?></span>
                </div>
            </div>

            <div class="job-description">
                <h2>Job Description</h2>
                <?php echo $job['description']; // Already HTML from CKEditor ?>
            </div>

            <?php if (!empty($job['apply_instructions'])): ?>
                <div class="apply-instructions">
                    <h2>Application Instructions</h2>
                    <?php echo $job['apply_instructions']; // Already HTML from CKEditor ?>
                </div>
            <?php endif; ?>

            <div class="share-section">
                <h2>Share This Job</h2>
                <div class="share-job">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(BASE_URL . '/job.php?id=' . $job['id']); ?>" target="_blank" class="facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(BASE_URL . '/job.php?id=' . $job['id']); ?>&text=Check out this job: <?php echo htmlspecialchars($job['title']); ?> on JobMailNig!" target="_blank" class="twitter"><i class="fab fa-twitter"></i></a>
                    <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode(BASE_URL . '/job.php?id=' . $job['id']); ?>&title=<?php echo htmlspecialchars($job['title']); ?>&summary=<?php echo htmlspecialchars(truncateText($job['description'], 200)); ?>" target="_blank" class="linkedin"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>

            <div class="apply-method">
                <h2>Apply for This Job</h2>
                <div class="apply-links">
                    <?php if (!empty($job['apply_link'])): ?>
                        <div class="apply-link">
                            <strong>Application Link:</strong>
                            <a href="<?php echo htmlspecialchars($job['apply_link']); ?>" target="_blank"><?php echo htmlspecialchars($job['apply_link']); ?></a>
                            <span class="copy-link" onclick="copyToClipboard('<?php echo htmlspecialchars($job['apply_link']); ?>')">Copy Link</span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($job['apply_email'])): ?>
                        <div class="apply-email">
                            <strong>Application Email:</strong>
                            <a href="mailto:<?php echo htmlspecialchars($job['apply_email']); ?>"><?php echo htmlspecialchars($job['apply_email']); ?></a>
                            <span class="copy-link" onclick="copyToClipboard('<?php echo htmlspecialchars($job['apply_email']); ?>')">Copy Email</span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($job['apply_email'])): ?>
                    <form method="POST" action="send-email.php" enctype="multipart/form-data" class="email-form">
                        <input type="hidden" name="job_id" value="<?php echo $jobId; ?>">
                        <input type="hidden" name="to_email" value="<?php echo htmlspecialchars($job['apply_email']); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="form-group">
                            <label for="applicant_name">Your Name:</label>
                            <input type="text" id="applicant_name" name="applicant_name" required>
                        </div>
                        <div class="form-group">
                            <label for="applicant_email">Your Email:</label>
                            <input type="email" id="applicant_email" name="applicant_email" required>
                        </div>
                        <div class="form-group">
                            <label for="message">Cover Letter:</label>
                            <textarea id="message" name="message" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="resume">Upload Resume (PDF, DOC, DOCX):</label>
                            <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx">
                        </div>
                        <button type="submit" name="send_email">Send Application</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="message success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="message error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>
        </main>
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