<?php
// config.php — updated: exposes getDBConnection()

define('DB_HOST', 'localhost');
define('DB_NAME', 'mesmtf');   // <-- ensure this matches your actual DB name
define('DB_USER', 'root');
define('DB_PASS', '');

function getDBConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        // Hide details from users in production
        die("Connection failed. Please try again later.");
    }
}

// start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
