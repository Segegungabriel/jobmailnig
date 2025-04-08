<?php
require_once 'config.php';

try {
    $stmt = $db->query("SELECT id, username, password FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($admins as $admin) {
        $plainPassword = $admin['password'];
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $updateStmt = $db->prepare("UPDATE admins SET password = :password WHERE id = :id");
        $updateStmt->execute([
            ':password' => $hashedPassword,
            ':id' => $admin['id']
        ]);

        echo "Password hashed for user: " . htmlspecialchars($admin['username']) . "<br>";
    }

    echo "All admin passwords have been hashed.";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}
?>