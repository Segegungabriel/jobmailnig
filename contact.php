<?php
require_once 'config.php'; // Assumes $db is defined here as a PDO connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $message = trim($_POST['message']);
    $requestVerification = isset($_POST['request_verification']) ? 'Yes' : 'No';

    // Validate inputs
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        // Prepare and execute SQL insert
        try {
            $stmt = $db->prepare("
                INSERT INTO contacts (name, email, message, request_verification)
                VALUES (:name, :email, :message, :request_verification)
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':message' => $message,
                ':request_verification' => $requestVerification
            ]);

            // Send email to jobmailnig@gmail.com
            $to = 'jobmailnig@gmail.com';
            $subject = 'New Contact Form Submission from ' . $name;
            $body = "Name: $name\nEmail: $email\nRequest Verification: $requestVerification\n\nMessage:\n$message";
            $headers = "From: $email\r\nReply-To: $email\r\n";

            if (mail($to, $subject, $body, $headers)) {
                $success = 'Message submitted successfully! We will get back to you soon.';
            } else {
                $error = 'Failed to send email. Please try again later.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to submit message. Please try again. Error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Contact JobMailNig to submit a job posting or request verification. Reach out to us at jobmailnig@gmail.com.">
    <title>Contact - JobMailNig</title>
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

    <div class="container">
        <section id="contact" class="contact-section">
            <h2>Contact Us</h2>
            <p>Employers: Submit a job posting or request verification. Reach out to us at <a href="mailto:jobmailnig@gmail.com">jobmailnig@gmail.com</a>.</p>
            <?php if (isset($success)): ?>
                <p class="message success-message"><?php echo htmlspecialchars($success); ?></p>
            <?php elseif (isset($error)): ?>
                <p class="message error-message"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="name">Your Name:</label>
                    <input type="text" id="name" name="name" placeholder="Your Name" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Your Email:</label>
                    <input type="email" id="email" name="email" placeholder="Your Email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="message">Your Message:</label>
                    <textarea id="message" name="message" placeholder="Your Message (e.g., Job Details)" required><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="request_verification" <?php echo isset($_POST['request_verification']) ? 'checked' : ''; ?>> Request Verification
                    </label>
                </div>
                <button type="submit">Submit</button>
            </form>
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