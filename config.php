<?php
// config.php - centralized configuration for DB & sessions
// Edit these constants to match your MySQL host/database/user/password
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name'); // replace with your DB name
define('DB_USER', 'your_db_user');       // replace with your DB user
define('DB_PASS', 'your_db_password');   // replace with your DB password

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

// Session settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_name('group_erp_session');
    session_start();
}
