<?php
// config/database.php

// In config/db_connect.php

// Load environment variables.
// parse_ini_file() was used here but cannot read this .env: PHP's INI parser does not treat
// '#' as a comment, so the LiveKit comment line (which contains parentheses, reserved
// characters in INI) made it return false. Every setting below then fell through to its
// hardcoded default, and the app silently tried localhost/tutsys/root instead of the real
// database. utils/env_loader.php is the loader the rest of the codebase already uses
// (livekit_client, s3_client, email_helper) and it skips '#' comments correctly.
require_once __DIR__ . '/../utils/env_loader.php';
load_env(__DIR__ . '/../.env');
$dotenv = $_ENV;

// Database configuration
define('DB_HOST', $dotenv['DB_HOST'] ?? 'localhost');
define('DB_PORT', $dotenv['DB_PORT'] ?? '3306');
define('DB_NAME', $dotenv['DB_NAME'] ?? 'tutsys');
define('DB_USER', $dotenv['DB_USER'] ?? 'root');
define('DB_PASSWORD', $dotenv['DB_PASSWORD'] ?? '');
define('DB_CHARSET', $dotenv['DB_CHARSET'] ?? 'utf8mb4');

// Create PDO connection
function getDbConnection($forceReconnect = false) {
    static $pdo = null;
    
    // Force reconnection if requested (useful for long-running scripts)
    if ($forceReconnect && $pdo !== null) {
        $pdo = null;
    }
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . 
               ";port=" . DB_PORT . 
               ";dbname=" . DB_NAME . 
               ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Add timeout settings to prevent "MySQL server has gone away"
            PDO::ATTR_TIMEOUT            => 5,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            // Set wait_timeout to prevent connection timeout
            $pdo->exec("SET SESSION wait_timeout=28800");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please try again later.');
        }
    }
    
    // Test connection and reconnect if needed
    try {
        $pdo->query("SELECT 1");
    } catch (PDOException $e) {
        // Connection is dead, force reconnect
        if (strpos($e->getMessage(), '2006') !== false || 
            strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
            $pdo = null;
            return getDbConnection(true); // Recursively reconnect
        }
        throw $e;
    }
    
    return $pdo;
}

// Initialize PDO connection by default (only if connection succeeds)
// Don't initialize if .env file doesn't exist or connection fails
$pdo = null;
try {
    if (file_exists(__DIR__ . '/../.env')) {
        $pdo = getDbConnection();
    }
} catch (Exception $e) {
    // Connection failed, $pdo remains null
    // This allows the daemon to retry later
    $pdo = null;
}

// Function to execute a query with parameters
function dbQuery($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Function to fetch a single row
function dbFetch($sql, $params = []) {
    return dbQuery($sql, $params)->fetch();
}

// Function to fetch all rows
function dbFetchAll($sql, $params = []) {
    return dbQuery($sql, $params)->fetchAll();
}

// Function to get the last insert ID
function dbLastInsertId() {
    return getDbConnection()->lastInsertId();
}

/**
 * Check if a table exists in the current database.
 *
 * @param PDO $pdo PDO instance connected to the database
 * @param string $table Table to search for
 * @return bool TRUE if table exists, FALSE if no table found
 */
function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Error checking if table exists: ' . $e->getMessage());
        return false;
    }
}