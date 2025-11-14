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
$sql = "SELECT UserID, FullName, Username, password_hash, Role FROM `User` WHERE Username = :username LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if a user was found AND the submitted password matches the stored hash
if ($user && password_verify($password, $user['password_hash'])) {

    // 1. Successful Login: Set up session variables
    session_regenerate_id(true);
    $_SESSION['UserID'] = $user['UserID'];
    $_SESSION['FullName'] = $user['FullName'];
    $_SESSION['Username'] = $user['Username'];
    $_SESSION['Role'] = $user['Role']; // 'SupplyChainManager' or 'SeniorManager'

    // 2. Redirect based on role
    if ($_SESSION['Role'] === 'SeniorManager') {
        header('Location: dashboard_erp.php');
    } else {
        header('Location: dashboard_scm.php');
    }
    exit;
}
// failed login
$_SESSION['login_error'] = 'Invalid username or password';
header('Location: index.php');
exit;
