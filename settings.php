<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);


// Session timeout (default from config until loaded from DB)
$timeout_duration = SESSION_TIMEOUT;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login?error=Session timed out. Please log in again.");
    exit;
}
$_SESSION['last_activity'] = time();

// Check if the user is logged in as an admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login");
    exit;
}

// Get permissions for the current user

if (!$_SESSION['permissions']['canGenerateRSS']) {
    header("Location: admin.php?error=You do not have permission to generate RSS feeds.");
    exit;
}

// Load settings from MySQL (with fallback defaults)
$settings = [
    'enable_rss_feed' => true,
    'session_timeout' => SESSION_TIMEOUT,
    'site_name' => SITE_NAME,
    'site_url' => SITE_URL,
    'min_password_length' => MIN_PASSWORD_LENGTH,
    'require_special_char' => REQUIRE_SPECIAL_CHAR,
    'require_number' => REQUIRE_NUMBER
];
try {
    $stmt = $db->query("SELECT * FROM settings LIMIT 1");
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbSettings) {
        $settings = [
            'enable_rss_feed' => (bool)$dbSettings['enable_rss_feed'],
            'session_timeout' => (int)$dbSettings['session_timeout'],
            'site_name' => $dbSettings['site_name'],
            'site_url' => $dbSettings['site_url'],
            'min_password_length' => (int)$dbSettings['min_password_length'],
            'require_special_char' => (bool)$dbSettings['require_special_char'],
            'require_number' => (bool)$dbSettings['require_number']
        ];
    }
} catch (PDOException $e) {
    // Fallback to defaults if table doesn’t exist yet
}

// Update session timeout with loaded value
$timeout_duration = $settings['session_timeout'];

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }

    $updatedSettings = [
        'enable_rss_feed' => isset($_POST['enable_rss_feed']) ? 1 : 0,
        'session_timeout' => (int)$_POST['session_timeout'],
        'site_name' => trim($_POST['site_name']),
        'site_url' => trim($_POST['site_url']),
        'min_password_length' => (int)$_POST['min_password_length'],
        'require_special_char' => isset($_POST['require_special_char']) ? 1 : 0,
        'require_number' => isset($_POST['require_number']) ? 1 : 0
    ];

    try {
        // Check if settings row exists
        $stmt = $db->query("SELECT COUNT(*) FROM settings");
        $rowCount = $stmt->fetchColumn();

        if ($rowCount > 0) {
            // Update existing settings
            $stmt = $db->prepare("
                UPDATE settings SET
                    enable_rss_feed = :enable_rss_feed,
                    session_timeout = :session_timeout,
                    site_name = :site_name,
                    site_url = :site_url,
                    min_password_length = :min_password_length,
                    require_special_char = :require_special_char,
                    require_number = :require_number
            ");
            $stmt->execute($updatedSettings);
        } else {
            // Insert new settings
            $stmt = $db->prepare("
                INSERT INTO settings (enable_rss_feed, session_timeout, site_name, site_url, min_password_length, require_special_char, require_number)
                VALUES (:enable_rss_feed, :session_timeout, :site_name, :site_url, :min_password_length, :require_special_char, :require_number)
            ");
            $stmt->execute($updatedSettings);
        }

        // Log the action
        $logFile = LOG_FILE;
        $logMessage = date('Y-m-d H:i:s') . " - Super admin {$_SESSION['username']} updated site settings\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        header("Location: settings.php?success=Settings updated successfully");
        exit;
    } catch (PDOException $e) {
        header("Location: settings.php?error=Failed to update settings: " . urlencode($e->getMessage()));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="url"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .form-group input[type="checkbox"] {
            margin-left: 10px;
        }
        button {
            display: inline-block;
            width: 120px;
            height: 40px;
            line-height: 40px;
            padding: 0 15px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #0056b3;
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
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Settings</h1>
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <!-- Settings Section -->
            <section id="settings">
                <h2>Site Settings</h2>
                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-group">
                        <label for="enable_rss_feed">Enable RSS Feed:</label>
                        <input type="checkbox" id="enable_rss_feed" name="enable_rss_feed" <?php echo $settings['enable_rss_feed'] ? 'checked' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label for="session_timeout">Session Timeout (seconds):</label>
                        <input type="number" id="session_timeout" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="site_name">Site Name:</label>
                        <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="site_url">Site URL:</label>
                        <input type="url" id="site_url" name="site_url" value="<?php echo htmlspecialchars($settings['site_url']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="min_password_length">Minimum Password Length:</label>
                        <input type="number" id="min_password_length" name="min_password_length" value="<?php echo htmlspecialchars($settings['min_password_length']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="require_special_char">Require Special Character in Password:</label>
                        <input type="checkbox" id="require_special_char" name="require_special_char" <?php echo $settings['require_special_char'] ? 'checked' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label for="require_number">Require Number in Password:</label>
                        <input type="checkbox" id="require_number" name="require_number" <?php echo $settings['require_number'] ? 'checked' : ''; ?>>
                    </div>
                    <button type="submit">Save Settings</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>