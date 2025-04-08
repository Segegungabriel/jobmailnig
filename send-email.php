<?php
session_start();

// Include the config file
require_once 'config.php';

// Load settings from JSON file
$settingsFile = 'settings.json';
$settings = [
    'site_name' => SITE_NAME,
    'site_url' => SITE_URL
];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?? $settings;
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }

    $jobId = $_POST['job_id'] ?? '';
    $toEmail = $_POST['to_email'] ?? '';
    $applicantName = $_POST['applicant_name'] ?? '';
    $applicantEmail = $_POST['applicant_email'] ?? '';
    $message = $_POST['message'] ?? '';
    $resume = $_FILES['resume'] ?? null;

    // Validate inputs
    if (empty($applicantName) || empty($applicantEmail) || empty($message) || empty($toEmail)) {
        header("Location: job.php?id=$jobId&error=All fields are required.");
        exit;
    }

    if (!filter_var($applicantEmail, FILTER_VALIDATE_EMAIL) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: job.php?id=$jobId&error=Invalid email address.");
        exit;
    }

    if ($resume && $resume['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($resume['type'], $allowedTypes)) {
            header("Location: job.php?id=$jobId&error=Only PDF, DOC, and DOCX files are allowed for the resume.");
            exit;
        }
        if ($resume['size'] > 5 * 1024 * 1024) { // 5MB limit
            header("Location: job.php?id=$jobId&error=Resume file size must be less than 5MB.");
            exit;
        }
    }

    // Send email using PHP's mail function (or use a library like PHPMailer for better reliability)
    $subject = "Job Application: {$applicantName}";
    $body = "Applicant Name: $applicantName\n";
    $body .= "Applicant Email: $applicantEmail\n\n";
    $body .= "Message:\n$message\n\n";
    $body .= "Sent via {$settings['site_name']}";

    $headers = "From: no-reply@{$_SERVER['HTTP_HOST']}\r\n";
    $headers .= "Reply-To: $applicantEmail\r\n";

    if ($resume && $resume['error'] === UPLOAD_ERR_OK) {
        // Handle file attachment (simplified for this example; use PHPMailer for production)
        $filePath = $resume['tmp_name'];
        $fileName = $resume['name'];
        $fileContent = chunk_split(base64_encode(file_get_contents($filePath)));
        $boundary = md5(time());

        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= "Applicant Name: $applicantName\n";
        $body .= "Applicant Email: $applicantEmail\n\n";
        $body .= "Message:\n$message\n\n";
        $body .= "Sent via {$settings['site_name']}\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: {$resume['type']}; name=\"$fileName\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n";
        $body .= "$fileContent\r\n";
        $body .= "--$boundary--";
    }

    if (mail($toEmail, $subject, $body, $headers)) {
        header("Location: job.php?id=$jobId&success=Application sent successfully.");
    } else {
        header("Location: job.php?id=$jobId&error=Failed to send application. Please try again.");
    }
    exit;
}