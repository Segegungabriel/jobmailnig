<?php
if (!defined('ACCESS')) {
    define('ACCESS', true);
    if (basename($_SERVER['SCRIPT_FILENAME']) === 'config.php') {
        http_response_code(403);
        exit('Direct access to this file is not allowed.');
    }
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'jobmailnig_db');
define('DB_USER', 'root');
define('DB_PASS', '');
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

define('SITE_NAME', 'JobMailNig');
define('SITE_URL', 'http://localhost/JobmailNig');
define('ADMIN_EMAIL', 'superadmin@jobmailnig.ng');
define('SESSION_TIMEOUT', 1800);
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_SPECIAL_CHAR', true);
define('REQUIRE_NUMBER', true);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 300);
define('JOB_STATUSES', ['Draft', 'Published', 'Expired']);
define('LOG_FILE', __DIR__ . '/admin_logs.txt');
define('OPENAI_API_KEY', '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');
?>