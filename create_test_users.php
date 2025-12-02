<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// ==========================================
// COMPATIBILITY FIX FOR OLD PHP VERSIONS
// ==========================================
if (!function_exists('password_hash')) {
    define('PASSWORD_DEFAULT', 1);
    
    function password_hash($password, $algo, $options = array()) {
        // Generate a random salt "good enough" for legacy PHP
        // (Note: Modern PHP handles this much better, but this works for class assignments)
        $salt = substr(str_replace('+', '.', base64_encode(pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()))), 0, 22);
        // Use Blowfish algorithm ($2y$ or $2a$)
        return crypt($password, '$2a$10$' . $salt);
    }
}
// ==========================================

echo "<h1>Creating Test Users</h1>";

$users = [
    [
        'full_name' => 'Supply Chain Manager',
        'username' => 'scm_user',
        'password' => 'scm123',
        'role' => 'SupplyChainManager'
    ],
    [
        'full_name' => 'Senior Manager',
        'username' => 'sm_user',
        'password' => 'sm123',
        'role' => 'SeniorManager'
    ]
];

try {
    $pdo = getPDO();
    echo "<p style='color:green;'>✓ Database connected</p>";
    
    echo "<h2>Creating Users:</h2><ul>";
    
    foreach ($users as $user) {
        // 1. Using the polyfill if needed, and saving to $Password
        $Password = password_hash($user['password'], PASSWORD_DEFAULT);
        
        echo "<li>Attempting to create: <strong>{$user['username']}</strong><br>";
        echo "Password (plaintext): {$user['password']}<br>";
        echo "Password (hashed): " . substr($Password, 0, 40) . "...<br>";
        
        $sql = "INSERT INTO `User` (FullName, Username, Password, Role) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute([
                $user['full_name'],
                $user['username'],
                $Password, // Inserting the hashed password
                $user['role']
            ]);
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
