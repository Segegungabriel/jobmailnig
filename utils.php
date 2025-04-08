<?php
// Start session only if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the config file
require_once 'config.php';

// Define constants for better maintainability
const BASE_URL = "https://jobmailnig.ng";
const DEFAULT_EXCHANGE_RATE = 1500;
const EXCHANGE_RATE_API = 'https://api.exchangerate-api.com/v4/latest/USD';

// Function to fetch exchange rate (USD to NGN)
function getExchangeRate($apiUrl, $defaultRate) {
    $exchangeData = @file_get_contents($apiUrl);
    if ($exchangeData === false) {
        return $defaultRate;
    }
    $exchangeJson = json_decode($exchangeData, true);
    return $exchangeJson['rates']['NGN'] ?? $defaultRate;
}

// Function to merge similar categories and ensure all possible categories are listed
function mergeSimilarCategories($rawCategories) {
    // Manually define all possible categories and their similar variations
    $categoryMappings = [
        'Tech' => ['technology', 'it', 'information technology', 'software', 'software development', 'software engineering', 'coding', 'programming', 'web development', 'app development', 'cybersecurity', 'data science', 'machine learning', 'ai', 'artificial intelligence', 'devops', 'cloud computing', 'networking', 'systems administration', 'tech industry'],
        'Finance' => ['financial services', 'accounting', 'fintech', 'banking', 'investment', 'auditing', 'taxation', 'financial analysis', 'budgeting', 'payroll', 'insurance'],
        'Marketing' => ['digital marketing', 'advertising', 'sales', 'brand management', 'market research', 'public relations', 'pr', 'content marketing', 'social media marketing', 'seo', 'search engine optimization', 'email marketing', 'event marketing'],
        'Engineering' => ['mechanical engineering', 'civil engineering', 'electrical engineering', 'chemical engineering', 'aerospace engineering', 'petroleum engineering', 'structural engineering', 'industrial engineering', 'manufacturing engineering', 'automotive engineering'],
        'Healthcare' => ['medical', 'nursing', 'pharmacy', 'health', 'public health', 'dentistry', 'physiotherapy', 'radiology', 'laboratory services', 'mental health', 'clinical research', 'healthcare administration'],
        'Education' => ['teaching', 'academia', 'e-learning', 'instructional design', 'education administration', 'tutoring', 'curriculum development', 'special education'],
        'Design' => ['graphic design', 'ui design', 'ux design', 'ui/ux', 'product design', 'interior design', 'fashion design', 'animation', 'motion graphics', 'visual design'],
        'Management' => ['project management', 'operations', 'business management', 'general management', 'strategic management', 'team leadership', 'executive management', 'program management'],
        'Human Resources' => ['hr', 'recruitment', 'talent acquisition', 'employee relations', 'training and development', 'organizational development', 'payroll management', 'hr consulting'],
        'Legal' => ['law', 'compliance', 'legal services', 'corporate law', 'litigation', 'contract management', 'intellectual property', 'legal consulting'],
        'Customer Service' => ['support', 'client service', 'customer support', 'call center', 'technical support', 'help desk', 'customer experience'],
        'Logistics' => ['supply chain', 'transportation', 'warehousing', 'inventory management', 'freight forwarding', 'logistics management', 'distribution'],
        'Sales' => ['retail sales', 'business development', 'account management', 'sales management', 'field sales', 'inside sales', 'telemarketing'],
        'Administrative' => ['office management', 'clerical', 'data entry', 'executive assistant', 'receptionist', 'administrative support', 'secretarial'],
        'Creative' => ['writing', 'content creation', 'copywriting', 'editing', 'journalism', 'photography', 'videography', 'film production', 'music production'],
        'Construction' => ['architecture', 'building', 'surveying', 'quantity surveying', 'construction management', 'site management', 'carpentry', 'plumbing', 'electrical work'],
        'Hospitality' => ['hotel management', 'tourism', 'event planning', 'catering', 'food and beverage', 'restaurant management', 'travel management'],
        'Agriculture' => ['farming', 'agribusiness', 'agronomy', 'horticulture', 'animal husbandry', 'forestry', 'fisheries'],
        'Energy' => ['oil and gas', 'renewable energy', 'solar energy', 'wind energy', 'power generation', 'energy management'],
        'Manufacturing' => ['production', 'factory work', 'quality control', 'assembly', 'industrial production', 'process engineering'],
        'Retail' => ['store management', 'merchandising', 'cashier', 'retail operations', 'visual merchandising'],
        'Telecommunications' => ['telecom', 'network engineering', 'communications', 'wireless technology', 'telecom engineering'],
        'Real Estate' => ['property management', 'real estate development', 'real estate sales', 'property valuation', 'leasing'],
        'Non-Profit' => ['ngo', 'charity', 'social work', 'community development', 'advocacy', 'fundraising'],
        'Security' => ['cybersecurity', 'physical security', 'safety management', 'risk management', 'surveillance'],
        'Other' => ['miscellaneous', 'general', 'freelance', 'consulting', 'entrepreneurship', 'startup']
    ];

    $mergedCategories = [];
    $categoryMap = [];

    // Build a reverse mapping for quick lookup
    foreach ($categoryMappings as $mainCategory => $similarCategories) {
        $categoryMap[strtolower($mainCategory)] = $mainCategory;
        foreach ($similarCategories as $similar) {
            $categoryMap[strtolower($similar)] = $mainCategory;
        }
    }

    // Merge categories from raw data with the predefined list
    foreach ($rawCategories as $category) {
        $lowerCategory = strtolower($category);
        $mainCategory = $categoryMap[$lowerCategory] ?? 'Other';
        $mergedCategories[$mainCategory] = $mainCategory;
    }

    // Ensure all main categories are included, even if they have no jobs
    foreach ($categoryMappings as $mainCategory => $similarCategories) {
        $mergedCategories[$mainCategory] = $mainCategory;
    }

    // Convert to array, sort alphabetically, and ensure unique values
    $mergedCategories = array_values($mergedCategories);
    sort($mergedCategories);

    return $mergedCategories;
}

// Function to get a list of locations (Nigerian states and FCT)
function getLocations() {
    $locations = [
        'Abia',
        'Adamawa',
        'Akwa Ibom',
        'Anambra',
        'Bauchi',
        'Bayelsa',
        'Benue',
        'Borno',
        'Cross River',
        'Delta',
        'Ebonyi',
        'Edo',
        'Ekiti',
        'Enugu',
        'Gombe',
        'Imo',
        'Jigawa',
        'Kaduna',
        'Kano',
        'Katsina',
        'Kebbi',
        'Kogi',
        'Kwara',
        'Lagos',
        'Nasarawa',
        'Niger',
        'Ogun',
        'Ondo',
        'Osun',
        'Oyo',
        'Plateau',
        'Rivers',
        'Sokoto',
        'Taraba',
        'Yobe',
        'Zamfara',
        'Abuja (FCT)',
        'all'
    ];
    sort($locations); // Sort alphabetically
    return $locations;
}

// Function to load settings from the database
function loadSettings($db) {
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
        // Log error to logs table
        $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
        $stmt->execute([
            ':username' => $_SESSION['username'] ?? 'system',
            ':action' => "Error loading settings: " . $e->getMessage()
        ]);
    }
    return $settings;
}

// Function to check session and handle timeout
function checkSession($settings) {
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
}

// Function to load user permissions (if not already in session)
function loadUserPermissions($db) {
    if (isset($_SESSION['permissions'])) {
        return $_SESSION['permissions'];
    }

    // Define default permissions for each role (fallback)
    $defaultPermissions = [
        'super_admin' => [
            'canPostJobs' => true,
            'canEditJobs' => true,
            'canDeleteJobs' => true,
            'canManageAdmins' => true,
            'canGenerateRSS' => true,
            'canViewStats' => true,
            'canChangePassword' => true,
            'canViewActivityLog' => true,
            'canManageSettings' => true,
            'canManagePermissions' => true,
            'canGenerateTokens' => true
        ],
        'editor' => [
            'canPostJobs' => true,
            'canEditJobs' => true,
            'canDeleteJobs' => true,
            'canManageAdmins' => true,
            'canGenerateRSS' => true,
            'canViewStats' => true,
            'canChangePassword' => true,
            'canViewActivityLog' => true,
            'canManageSettings' => false,
            'canManagePermissions' => false,
            'canGenerateTokens' => false
        ],
        'moderator' => [
            'canPostJobs' => true,
            'canEditJobs' => true,
            'canDeleteJobs' => true,
            'canManageAdmins' => false,
            'canGenerateRSS' => true,
            'canViewStats' => true,
            'canChangePassword' => true,
            'canViewActivityLog' => true,
            'canManageSettings' => false,
            'canManagePermissions' => false,
            'canGenerateTokens' => false
        ],
        'data_entry' => [
            'canPostJobs' => true,
            'canEditJobs' => true,
            'canDeleteJobs' => true,
            'canManageAdmins' => false,
            'canGenerateRSS' => true,
            'canViewStats' => true,
            'canChangePassword' => true,
            'canViewActivityLog' => false,
            'canManageSettings' => false,
            'canManagePermissions' => false,
            'canGenerateTokens' => false
        ]
    ];

    $accessLevel = $_SESSION['access_level'] ?? 'data_entry';
    $adminId = $_SESSION['admin_id'] ?? 0;
    $userPermissions = $defaultPermissions[$accessLevel]; // Start with defaults

    try {
        $stmt = $db->prepare("SELECT permission_key, permission_value FROM admin_permissions WHERE admin_id = :admin_id");
        $stmt->execute([':admin_id' => $adminId]);
        $customPermissions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($customPermissions as $key => $value) {
            $userPermissions[$key] = (bool)$value;
        }
    } catch (PDOException $e) {
        // Log error to logs table
        $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
        $stmt->execute([
            ':username' => $_SESSION['username'] ?? 'system',
            ':action' => "Error loading permissions: " . $e->getMessage()
        ]);
    }

    $_SESSION['permissions'] = $userPermissions;
    return $userPermissions;
}

// Function to generate CSRF token
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
?>