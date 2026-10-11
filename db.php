<?php
require_once __DIR__ . '/config/db_connect.php'; // unified DB credentials (.env)
/**
 * db.php — Central PDO bootstrap for u789944046_bloomsystem/Bloom
 * Place this file at the PROJECT ROOT:  /db.php
 *
 * Usage from nested folders:
 *   require_once __DIR__ . '/../db.php';  // from /student, /tutor, /admin, /api
 *   // $pdo is available after require
 */

// If you prefer environment variables, set DB_HOST, DB_NAME, DB_USER, DB_PASS in your server config.
$DB_HOST   = getenv('DB_HOST') ?: DB_HOST;
$DB_NAME   = getenv('DB_NAME') ?: DB_NAME;
$DB_USER   = getenv('DB_USER') ?: DB_USER;
$DB_PASS   = getenv('DB_PASS') ?: DB_PASSWORD;
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Always store UTC in DB (matches CONTEXT.md guidance)
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    // Uncomment if you want persistent connections (optional)
    // PDO::ATTR_PERSISTENT         => true,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Don't leak secrets to the browser; log the error server-side.
    error_log('[DB] Connection failed: ' . $e->getMessage());
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Database connection error.";
    exit();
}

/**
 * Helper accessor (optional):
 *   $db = db(); // returns PDO
 */
function db(): PDO {
    /** @var PDO $pdo */
    global $pdo;
    return $pdo;
}
