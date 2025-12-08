<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
//session_start();

// login.php - login handler that creates a session (PHP 5.4 compatible with legacy hashing)
require_once 'config.php';


// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username and password required';
    header('Location: index.php');
    exit;
}

$pdo = getPDO();
$sql = "SELECT UserID, FullName, Username, Password, Role FROM `User` WHERE Username = :username LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(array(':username' => $username));
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if a user was found AND the submitted password matches the stored hash
// Using SHA256 hashing for PHP 5.4 compatibility
$password_match = false;
if ($user) {
    $hashed_password = hash('sha256', $password);
    $password_match = ($hashed_password === $user['Password']);
}

if ($user && $password_match) {

    // 1. Successful Login: Set up session variables
    session_regenerate_id(true);
    $_SESSION['UserID'] = $user['UserID'];
    $_SESSION['FullName'] = $user['FullName'];
    $_SESSION['Username'] = $user['Username'];
    $_SESSION['Role'] = $user['Role']; // 'SupplyChainManager' or 'SeniorManager'

    // 2. Redirect based on role
    if ($_SESSION['Role'] === 'SeniorManager') {
        header('Location: erp/dashboard.php');
    } else {
        header('Location: scm/dashboard.php');
    }
    exit;
}else {
// failed login
$_SESSION['login_error'] = 'Invalid username or password';
header('Location: index.php');
}

exit;
