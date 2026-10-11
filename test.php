<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "🔄 Loading config file...\n";
    require_once __DIR__ . '/config/db_connect.php';
    
    echo "🔍 Checking .env file...\n";
    if (!file_exists(__DIR__ . '/.env')) {
        throw new Exception(".env file not found in: " . __DIR__);
    }
    
    echo "🔌 Attempting database connection...\n";
    echo "   Host: " . DB_HOST . "\n";
    echo "   Database: " . DB_NAME . "\n";
    echo "   Username: " . DB_USER . "\n";
    
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT VERSION() AS version");
    $result = $stmt->fetch();
    
    echo "\n✅ Connection successful!\n";
    echo "   MySQL Version: " . $result['version'] . "\n";
    
} catch (Exception $e) {
    echo "\n❌ Error Details:\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    
    // Check if PDO is available
    if (extension_loaded('pdo')) {
        echo "   PDO is loaded\n";
        echo "   PDO Drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n";
    } else {
        echo "   ❗ PDO extension is not loaded\n";
    }
    
    // Check if we can resolve the host
    $host = parse_url('mysql:host=' . DB_HOST, PHP_URL_HOST);
    if ($host) {
        echo "   🔍 Host resolution for '$host': ";
        $ip = gethostbyname($host);
        echo ($ip === $host ? "Failed to resolve" : "Resolved to $ip") . "\n";
    }
}