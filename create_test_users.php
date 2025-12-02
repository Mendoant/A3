<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "<h1>Creating Test Users</h1>";

$users = array(
    array(
        'full_name' => 'Supply Chain Manager',
        'username' => 'scm_user',
        'password' => 'scm123',
        'role' => 'SupplyChainManager'
    ),
    array(
        'full_name' => 'Senior Manager',
        'username' => 'sm_user',
        'password' => 'sm123',
        'role' => 'SeniorManager'
    )
);

try {
    $pdo = getPDO();
    echo "<p style='color:green;'>✓ Database connected</p>";
    
    echo "<h2>Creating Users:</h2><ul>";
    
    foreach ($users as $user) {
        // Hash the password using SHA-256 (PHP 5.4 compatible)
        $password_hash = hash('sha256', $user['password']);
        
        echo "<li>Attempting to create: <strong>{$user['username']}</strong><br>";
        echo "Password (plaintext): {$user['password']}<br>";
        echo "Password (hashed): " . substr($password_hash, 0, 40) . "...<br>";
        
        // NOTE: Column name is 'password_hash' (lowercase with underscore)
        $sql = "INSERT INTO `User` (FullName, Username, password_hash, Role) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute(array(
                $user['full_name'],
                $user['username'],
                $password_hash,
                $user['role']
            ));
            echo "<span style='color:green;font-weight:bold;'>✓ SUCCESS!</span></li>";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo "<span style='color:orange;'>⚠ User already exists (duplicate username)</span></li>";
            } else {
                echo "<span style='color:red;'>✗ Error: {$e->getMessage()}</span></li>";
            }
        }
    }
    
    echo "</ul>";
    
    // Verify users were created
    echo "<h2>Verification - Users in Database:</h2>";
    $stmt = $pdo->query("SELECT UserID, Username, FullName, Role FROM `User`");
    $existing_users = $stmt->fetchAll();
    
    if (count($existing_users) > 0) {
        echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
        echo "<tr style='background:#eee;'><th>UserID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
        foreach ($existing_users as $u) {
            echo "<tr>";
            echo "<td>{$u['UserID']}</td>";
            echo "<td><strong>{$u['Username']}</strong></td>";
            echo "<td>{$u['FullName']}</td>";
            echo "<td>{$u['Role']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h2>Test Credentials:</h2>";
    echo "<div style='background:#f0f0f0;padding:20px;border:2px solid #333;'>";
    echo "<p><strong>Supply Chain Manager:</strong></p>";
    echo "<p>Username: <code>scm_user</code><br>Password: <code>scm123</code></p>";
    echo "<p><strong>Senior Manager:</strong></p>";
    echo "<p>Username: <code>sm_user</code><br>Password: <code>sm123</code></p>";
    echo "</div>";
    
    echo "<hr>";
    echo "<p style='color:red;font-weight:bold;font-size:18px;'>⚠️ DELETE THIS FILE NOW! ⚠️</p>";
    echo "<p><a href='index.php' style='font-size:18px;'>Go to Login Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;font-weight:bold;'>Error: " . $e->getMessage() . "</p>";
}
?>