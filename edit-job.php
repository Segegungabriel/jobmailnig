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

// Check if the user has permission to edit jobs
if (!$_SESSION['permissions']['canEditJobs']) {
    header("Location: admin.php?error=You do not have permission to edit jobs.");
    exit;
}

// Load categories and locations from MySQL with fallback
try {
    $categories = $db->query("SELECT DISTINCT category FROM jobs")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}
$categories = mergeSimilarCategories($categories ?: []);
$locations = getLocations();

// Generate CSRF token for the form
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Get job ID from URL
$jobId = $_GET['id'] ?? null;
if (!$jobId) {
    header("Location: manage-jobs.php?error=No job ID provided.");
    exit;
}

// Fetch job data
try {
    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        header("Location: manage-jobs.php?error=Job not found.");
        exit;
    }
} catch (PDOException $e) {
    header("Location: manage-jobs.php?error=Database error: " . urlencode($e->getMessage()));
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: edit-job.php?id=$jobId&error=CSRF token validation failed.");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $applyInstructions = $_POST['apply_instructions'] ?? ''; // Optional
    $description = $_POST['description'] ?? '';
    $salary = trim($_POST['salary'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $remoteType = trim($_POST['remoteType'] ?? '');
    $hours = trim($_POST['hours'] ?? '');
    $status = trim($_POST['status'] ?? 'Draft');
    $applyLink = trim($_POST['apply_link'] ?? '');
    $applyEmail = trim($_POST['apply_email'] ?? '');

    if ($category === 'Other') {
        $category = trim($_POST['custom_category'] ?? '');
        if (empty($category)) {
            $errors[] = "Custom category is required when 'Other' is selected.";
        }
    }

    $errors = [];
    if (empty($title)) $errors[] = "Job title is required.";
    if (empty($description)) $errors[] = "Description is required.";
    if (!empty($salary) && (!is_numeric($salary) || $salary < 0)) $errors[] = "Salary, if provided, must be a valid non-negative number.";
    if (empty($currency)) $errors[] = "Currency is required.";
    if (empty($location)) $errors[] = "Location is required.";
    if (empty($category)) $errors[] = "Category is required.";
    if (empty($remoteType)) $errors[] = "Work type is required.";
    if (empty($hours)) $errors[] = "Work hours are required.";
    if (!in_array($status, ['Draft', 'Published'])) $errors[] = "Invalid status.";
    if (empty($applyLink) && empty($applyEmail)) {
        $errors[] = "At least one of Application Link or Application Email is required.";
    }
    if (!empty($applyLink) && !filter_var($applyLink, FILTER_VALIDATE_URL)) {
        $errors[] = "Application Link must be a valid URL.";
    }
    if (!empty($applyEmail) && !filter_var($applyEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Application Email must be a valid email address.";
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE jobs 
            SET title = :title, 
                apply_instructions = :apply_instructions, 
                description = :description, 
                salary = :salary, 
                currency = :currency, 
                location = :location, 
                category = :category, 
                remoteType = :remoteType, 
                hours = :hours, 
                status = :status, 
                apply_link = :apply_link, 
                apply_email = :apply_email
            WHERE id = :id
        ");
        $stmt->execute([
            ':title' => $title,
            ':apply_instructions' => $applyInstructions ?: null,
            ':description' => $description,
            ':salary' => empty($salary) ? null : (float)$salary,
            ':currency' => $currency,
            ':location' => $location,
            ':category' => $category,
            ':remoteType' => $remoteType,
            ':hours' => $hours,
            ':status' => $status,
            ':apply_link' => $applyLink ?: null,
            ':apply_email' => $applyEmail ?: null,
            ':id' => $jobId
        ]);

        $logMessage = date('Y-m-d H:i:s') . " - Admin {$_SESSION['username']} edited job: $title (ID: $jobId)\n";
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);

        header("Location: manage-jobs.php?success=Job updated successfully.");
        exit;
    } else {
        $errorMessage = implode(' ', $errors);
        header("Location: edit-job.php?id=$jobId&error=" . urlencode($errorMessage));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Job - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
    <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize CKEditor for Application Instructions (optional)
            ClassicEditor
                .create(document.querySelector('#apply_instructions'), {
                    toolbar: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', 'outdent', 'indent', '|', 'link', 'blockQuote', '|', 'fontColor', 'fontBackgroundColor'],
                    placeholder: 'Enter application instructions (optional)...',
                    height: 300
                })
                .then(editor => {
                    window.applyInstructionsEditor = editor;
                    <?php if (!empty($job['apply_instructions'])): ?>
                        editor.setData(<?php echo json_encode($job['apply_instructions']); ?>);
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('CKEditor (apply_instructions) error:', error);
                });

            // Initialize CKEditor for Description (required)
            ClassicEditor
                .create(document.querySelector('#description'), {
                    toolbar: ['undo', 'redo', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', 'outdent', 'indent', '|', 'link', 'blockQuote', '|', 'fontColor', 'fontBackgroundColor'],
                    placeholder: 'Enter job description...',
                    height: 300
                })
                .then(editor => {
                    window.descriptionEditor = editor;
                    <?php if (!empty($job['description'])): ?>
                        editor.setData(<?php echo json_encode($job['description']); ?>);
                    <?php endif; ?>
                })
                .catch(error => {
                    console.error('CKEditor (description) error:', error);
                });
        });
    </script>
    <style>
        .btn-uniform {
            display: inline-block;
            width: 120px;
            height: 40px;
            line-height: 40px;
            padding: 0 15px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            box-sizing: border-box;
            background-color: #007bff;
            color: #fff;
        }
        .btn-uniform:hover {
            background-color: #0056b3;
        }
        .job-form {
            max-width: 800px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="url"],
        .form-group input[type="email"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .form-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }
        .form-row .form-group {
            flex: 1;
            min-width: 0;
        }
        .salary-input {
            display: flex;
            gap: 10px;
        }
        .salary-input select {
            width: 100%;
        }
        .salary-input input {
            flex: 1;
        }
        #custom_category {
            margin-top: 10px;
            display: none;
        }
        .error-message {
            color: #dc3545;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8d7da;
            border-radius: 4px;
        }
        .ck-editor__editable {
            min-height: 300px;
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <header class="main-header">
                <h1>Edit Job</h1>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <section id="edit-job">
                <form method="POST" class="job-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <!-- Full-width fields at the top -->
                    <div class="form-group">
                        <label for="title">Job Title:</label>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($job['title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="apply_instructions">Application Instructions (Optional):</label>
                        <textarea id="apply_instructions" name="apply_instructions"><?php echo htmlspecialchars($job['apply_instructions'] ?? ''); ?></textarea>
                    </div>
                    <!-- Two-column rows -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="currency">Currency:</label>
                            <select id="currency" name="currency" required>
                                <option value="">Select Currency</option>
                                <option value="NGN" <?php echo $job['currency'] === 'NGN' ? 'selected' : ''; ?>>₦ (Naira)</option>
                                <option value="USD" <?php echo $job['currency'] === 'USD' ? 'selected' : ''; ?>>$ (USD)</option>
                                <option value="EUR" <?php echo $job['currency'] === 'EUR' ? 'selected' : ''; ?>>€ (Euro)</option>
                                <option value="GBP" <?php echo $job['currency'] === 'GBP' ? 'selected' : ''; ?>>£ (Pound)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="salary">Salary (Optional):</label>
                            <input type="number" id="salary" name="salary" step="0.01" value="<?php echo htmlspecialchars($job['salary'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="location">Location:</label>
                            <select id="location" name="location" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo $job['location'] === $loc ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="category">Category:</label>
                            <select id="category" name="category" required onchange="document.getElementById('custom_category').style.display = this.value === 'Other' ? 'block' : 'none';">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $job['category'] === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                                <option value="Other" <?php echo !in_array($job['category'], $categories) ? 'selected' : ''; ?>>Other (Specify)</option>
                            </select>
                            <input type="text" id="custom_category" name="custom_category" placeholder="Enter custom category" value="<?php echo !in_array($job['category'], $categories) ? htmlspecialchars($job['category']) : ''; ?>" <?php echo !in_array($job['category'], $categories) ? '' : 'style="display: none;"'; ?>>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="remoteType">Work Type:</label>
                            <select id="remoteType" name="remoteType" required>
                                <option value="">Select Work Type</option>
                                <option value="On-Site" <?php echo $job['remoteType'] === 'On-Site' ? 'selected' : ''; ?>>On-Site</option>
                                <option value="Fully Remote" <?php echo $job['remoteType'] === 'Fully Remote' ? 'selected' : ''; ?>>Fully Remote</option>
                                <option value="Hybrid" <?php echo $job['remoteType'] === 'Hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="hours">Work Hours:</label>
                            <select id="hours" name="hours" required>
                                <option value="">Select Work Hours</option>
                                <option value="Full-Time" <?php echo $job['hours'] === 'Full-Time' ? 'selected' : ''; ?>>Full-Time</option>
                                <option value="Part-Time" <?php echo $job['hours'] === 'Part-Time' ? 'selected' : ''; ?>>Part-Time</option>
                                <option value="Contract" <?php echo $job['hours'] === 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                <option value="Freelance" <?php echo $job['hours'] === 'Freelance' ? 'selected' : ''; ?>>Freelance</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select id="status" name="status" required>
                                <option value="Draft" <?php echo $job['status'] === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="Published" <?php echo $job['status'] === 'Published' ? 'selected' : ''; ?>>Published</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <!-- Empty placeholder to maintain two-column layout -->
                        </div>
                    </div>
                    <!-- Description moved here -->
                    <div class="form-group">
                        <label for="description">Job Description:</label>
                        <textarea id="description" name="description" required><?php echo htmlspecialchars($job['description']); ?></textarea>
                    </div>
                    <!-- Application methods -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="apply_link">Application Link (Optional):</label>
                            <input type="url" id="apply_link" name="apply_link" placeholder="https://example.com/apply" value="<?php echo htmlspecialchars($job['apply_link'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="apply_email">Application Email (Optional):</label>
                            <input type="email" id="apply_email" name="apply_email" placeholder="example@domain.com" value="<?php echo htmlspecialchars($job['apply_email'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn-uniform">Update</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>