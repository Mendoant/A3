<?php
// config.php - centralized configuration for DB & sessions
// Edit these constants to match your MySQL host/database/user/password
define('DB_HOST', 'localhost');
define('DB_NAME', '332_A3'); // replace with your DB name MAKE SURE TO CHANGE WHEN NO LONGER LOCALLY TESTING
define('DB_USER', 'root');       // replace with your DB user
define('DB_PASS', '');   // replace with your DB password

// PDO connection helper
function getPDO() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        // In production do not expose error details
        die("Database connection error. Please check configuration.");
    }
    return $pdo;
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['UserID']) && isset($_SESSION['Role']);
}

/**
 * Require login - redirect to index if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Check if user has specific role
 * @param string $role Role to check
 * @return bool
 */
function hasRole($role) {
    return isset($_SESSION['Role']) && $_SESSION['Role'] === $role;
}

/**
 * Require specific role - redirect if user doesn't have it
 * @param string $role Required role
 * @param string $redirectTo Where to redirect if role check fails
 */
function requireRole($role, $redirectTo = 'index.php') {
    if (!hasRole($role)) {
        header("Location: $redirectTo");
        exit;
    }
}

// Session settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_name('group_erp_session');
    session_start();
}
