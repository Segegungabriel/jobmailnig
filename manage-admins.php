<?php
session_start();
require_once 'config.php'; // Changed from '../config.php'
require_once 'permissions.php'; // Changed from '../permissions.php'
require_once 'utils.php'; // Changed from '../utils.php'

// Load settings and check session
$settings = loadSettings($db);
checkSession($settings);


// Load settings from MySQL
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
    // Fallback to defaults from config.php
}



// Session timeout
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

// Load custom permissions from the database
$accessLevel = $_SESSION['access_level'] ?? 'data_entry';
$adminId = $_SESSION['admin_id'] ?? 0; // Assuming admin_id is stored in session after login

try {
    $stmt = $db->prepare("SELECT permission_key, permission_value FROM admin_permissions WHERE admin_id = :admin_id");
    $stmt->execute([':admin_id' => $adminId]);
    $customPermissions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($customPermissions as $key => $value) {
        $userPermissions[$key] = (bool)$value;
    }
} catch (PDOException $e) {
    // Fallback to default permissions if query fails
}

// Check if the user has permission to manage admins
if (!$_SESSION['permissions']['canManageAdmins']) {
    header("Location: admin.php?error=You do not have permission to manage admins.");
    exit;
}


// Load admins from MySQL
$admins = [];
try {
    $stmt = $db->query("SELECT * FROM admins ORDER BY id ASC");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $admins = [];
}

// Load registration tokens from MySQL
$tokens = [];
try {
    $stmt = $db->query("SELECT * FROM registration_tokens ORDER BY created_at DESC");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tokens = [];
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }
}

// Handle generating a new registration token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_token']) && $userPermissions['canGenerateTokens']) {
    try {
        $newToken = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmt = $db->prepare("
            INSERT INTO registration_tokens (token, used, expires, created_by)
            VALUES (:token, 0, :expires, :created_by)
        ");
        $stmt->execute([
            ':token' => $newToken,
            ':expires' => $expires,
            ':created_by' => $_SESSION['username']
        ]);

        $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
        $stmt->execute([
            ':username' => $_SESSION['username'],
            ':action' => "Admin {$_SESSION['username']} generated registration token: $newToken"
        ]);

        header("Location: manage-admins.php?success=Registration token generated successfully.");
        exit;
    } catch (PDOException $e) {
        header("Location: manage-admins.php?error=Failed to generate token: " . urlencode($e->getMessage()));
        exit;
    }
}

// Handle adding a new admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $newAdmin = [
        'username' => trim($_POST['username'] ?? ''),
        'password' => password_hash(trim($_POST['password'] ?? ''), PASSWORD_DEFAULT),
        'access_level' => trim($_POST['access_level'] ?? 'data_entry'),
        'status' => 'pending'
    ];

    $errors = [];
    if (empty($newAdmin['username'])) {
        $errors[] = "Username is required.";
    }
    if (empty($_POST['password'])) {
        $errors[] = "Password is required.";
    }
    if (!in_array($newAdmin['access_level'], ['super_admin', 'editor', 'moderator', 'data_entry'])) {
        $errors[] = "Invalid access level.";
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                INSERT INTO admins (username, password, access_level, status)
                VALUES (:username, :password, :access_level, :status)
            ");
            $stmt->execute([
                ':username' => $newAdmin['username'],
                ':password' => $newAdmin['password'],
                ':access_level' => $newAdmin['access_level'],
                ':status' => $newAdmin['status']
            ]);

            $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
            $stmt->execute([
                ':username' => $_SESSION['username'],
                ':action' => "Admin {$_SESSION['username']} added new admin: {$newAdmin['username']} (pending approval)"
            ]);

            header("Location: manage-admins.php?success=Admin added successfully. Awaiting approval.");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $errorMessage = implode(' ', $errors);
        header("Location: manage-admins.php?error=" . urlencode($errorMessage));
        exit;
    }
}

// Handle approving a pending admin
if (isset($_GET['action']) && $_GET['action'] === 'approve_admin') {
    $adminId = isset($_GET['id']) ? (int)$_GET['id'] : -1;
    if ($adminId >= 0) {
        try {
            $stmt = $db->prepare("UPDATE admins SET status = 'approved' WHERE id = :id AND status = 'pending'");
            $stmt->execute([':id' => $adminId]);
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("SELECT username FROM admins WHERE id = :id");
                $stmt->execute([':id' => $adminId]);
                $username = $stmt->fetchColumn();

                $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
                $stmt->execute([
                    ':username' => $_SESSION['username'],
                    ':action' => "Admin {$_SESSION['username']} approved admin: $username"
                ]);

                header("Location: manage-admins.php?success=Admin approved successfully.");
                exit;
            }
        } catch (PDOException $e) {
            header("Location: manage-admins.php?error=Failed to approve admin: " . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Handle rejecting a pending admin
if (isset($_GET['action']) && $_GET['action'] === 'reject_admin') {
    $adminId = isset($_GET['id']) ? (int)$_GET['id'] : -1;
    if ($adminId >= 0) {
        try {
            $stmt = $db->prepare("SELECT username FROM admins WHERE id = :id AND status = 'pending'");
            $stmt->execute([':id' => $adminId]);
            $username = $stmt->fetchColumn();

            if ($username) {
                $stmt = $db->prepare("DELETE FROM admins WHERE id = :id AND status = 'pending'");
                $stmt->execute([':id' => $adminId]);

                $stmt = $db->prepare("INSERT INTO logs (username, action) VALUES (:username, :action)");
                $stmt->execute([
                    ':username' => $_SESSION['username'],
                    ':action' => "Admin {$_SESSION['username']} rejected admin: $username"
                ]);

                header("Location: manage-admins.php?success=Admin rejected and removed.");
                exit;
            }
        } catch (PDOException $e) {
            header("Location: manage-admins.php?error=Failed to reject admin: " . urlencode($e->getMessage()));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - <?php echo htmlspecialchars($settings['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="admin-style.css">
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
        }
        .btn-uniform {
            background-color: #007bff;
            color: #fff;
        }
        .btn-uniform:hover {
            background-color: #0056b3;
        }
        .btn-uniform.btn-small {
            width: 80px;
            height: 32px;
            line-height: 32px;
            font-size: 13px;
        }
        .btn-uniform.btn-danger {
            background-color: #dc3545;
            color: #fff;
        }
        .btn-uniform.btn-danger:hover {
            background-color: #b02a37;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .admin-table th, .admin-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .admin-table th {
            background-color: #f4f4f4;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
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
        .token-link {
            word-break: break-all;
            color: #007bff;
        }
        .token-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php require_once 'sidebar.php'; ?>
        <main class="main-content">
            <header class="main-header">
                <h1>Manage Admins</h1>
                <?php if (isset($_GET['success'])): ?>
                    <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>
            </header>

            <section id="manage-admins">
                <h2>Add New Admin</h2>
                <form method="POST" class="admin-form">
                    <input type="hidden" name="add_admin" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="access_level">Access Level:</label>
                        <select id="access_level" name="access_level" required>
                            <option value="super_admin">Super Admin</option>
                            <option value="editor">Editor</option>
                            <option value="moderator">Moderator</option>
                            <option value="data_entry">Data Entry Staff</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-uniform">Add Admin</button>
                </form>

                <h3>Existing Admins</h3>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Access Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr>
                                <td colspan="4">No admins found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $admin['access_level']))); ?></td>
                                    <td><?php echo htmlspecialchars($admin['status']); ?></td>
                                    <td>
                                        <?php if ($admin['status'] === 'pending'): ?>
                                            <a href="manage-admins.php?action=approve_admin&id=<?php echo $admin['id']; ?>" class="btn-uniform btn-small">Approve</a>
                                            <a href="manage-admins.php?action=reject_admin&id=<?php echo $admin['id']; ?>" class="btn-uniform btn-small btn-danger" onclick="return confirm('Are you sure you want to reject this admin?');">Reject</a>
                                        <?php else: ?>
                                            <?php if ($admin['username'] !== 'superadmin' || count(array_filter($admins, fn($a) => $a['access_level'] === 'super_admin' && $a['status'] === 'approved')) > 1): ?>
                                                <a href="delete-admin.php?id=<?php echo $admin['id']; ?>" class="btn-uniform btn-small btn-danger" onclick="return confirm('Are you sure you want to delete this admin?');">Delete</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Registration Tokens Section -->
                <?php if ($userPermissions['canGenerateTokens']): ?>
                    <h2>Generate Registration Token</h2>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="generate_token" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <p>Generate a token for new admin registration (valid for 24 hours).</p>
                        <button type="submit" class="btn-uniform">Generate Token</button>
                    </form>

                    <h3>Existing Registration Tokens</h3>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Registration Link</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th>Created By</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tokens)): ?>
                                <tr>
                                    <td colspan="6">No tokens found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tokens as $token): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($token['token']); ?></td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($settings['site_url'] . '/register.php?token=' . $token['token']); ?>" class="token-link" target="_blank">
                                                <?php echo htmlspecialchars($settings['site_url'] . '/register.php?token=' . $token['token']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo $token['used'] ? 'Used' : 'Unused'; ?></td>
                                        <td><?php echo htmlspecialchars($token['expires']); ?></td>
                                        <td><?php echo htmlspecialchars($token['created_by']); ?></td>
                                        <td><?php echo htmlspecialchars($token['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>