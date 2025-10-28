<?php
// login.php - login handler that creates a session (adapted to create_db.sql User table)
require_once 'config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username and password required';
    header('Location: index.php');
    exit;
}

$pdo = getPDO();
$sql = "SELECT UserID, FullName, Username, Password, Role FROM `User` WHERE Username = :username LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

if ($user) {
    $stored = $user['Password'];
    $ok = false;
    // Prefer password hashes (password_hash), but allow plaintext fallback if used
    if (password_needs_rehash($stored, PASSWORD_DEFAULT) || strlen($stored) >= 60) {
        // If it looks like a hash, try password_verify
        $ok = password_verify($password, $stored);
    } else {
        // Fallback plaintext comparison (only if dataset uses plaintext)
        $ok = ($password === $stored);
    }

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['UserID'] = $user['UserID'];
        $_SESSION['FullName'] = $user['FullName'];
        $_SESSION['Username'] = $user['Username'];
        $_SESSION['Role'] = $user['Role']; // 'SupplyChainManager' or 'SeniorManager'
        // Redirect based on role
        if ($_SESSION['Role'] === 'SeniorManager') {
            header('Location: dashboard_erp.php');
        } else {
            header('Location: dashboard_scm.php');
        }
        exit;
    }
}

// failed login
$_SESSION['login_error'] = 'Invalid username or password';
header('Location: index.php');
exit;
