<?php
// config.php - centralized configuration for DB & sessions (PHP 5.4 compatible)
// Edit these constants to match your MySQL host/database/user/password
define('DB_HOST', 'mydb.ics.purdue.edu');
define('DB_NAME', 'g1151917'); // replace with your DB name
define('DB_USER', 'g1151917');       // replace with your DB user
define('DB_PASS', 'group7332!');   // replace with your DB password

// PDO connection helper
function getPDO() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $opts = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        // In production do not expose error details
        die("Database connection error. Please check configuration.");
    }
    return $pdo;
}

// Helper to check if user is logged in
function requireLogin() {
    if (!isset($_SESSION['Username']) || !isset($_SESSION['UserID'])) {
        $_SESSION['login_error'] = 'Please log in to access this page';
        header('Location: index.php');
        exit;
    }
}

// Helper to check if user has a specific role
function hasRole($role) {
    return isset($_SESSION['Role']) && $_SESSION['Role'] === $role;
}

// Session settings
if (!isset($_SESSION)) {
    ini_set('session.cookie_httponly', 1);
    session_name('group_erp_session');
    session_start();
}
