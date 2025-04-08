<?php
require_once 'config.php'; // Ensure this path is correct

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Successfully connected to the database!<br><br>";

    // Attempt to fetch all usernames and passwords from the admins table
    $stmt = $pdo->query("SELECT username, password FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($admins) {
        echo "<h2>Admin Users Found:</h2>";
        echo "<ul>";
        foreach ($admins as $admin) {
            echo "<li><strong>Username:</strong> " . htmlspecialchars($admin['username']) . "</li>";
            echo "<li><strong>Password Hash:</strong> " . htmlspecialchars($admin['password']) . "</li><br>";
        }
        echo "</ul>";
    } else {
        echo "No admin users found in the 'admins' table.";
    }

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>