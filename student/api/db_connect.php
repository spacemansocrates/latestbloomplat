<?php
// This file now uses the centralized database configuration
// from /config/db_connect.php
require_once __DIR__ . '/../../config/db_connect.php';

// Set PDO instance to global $pdo for backward compatibility
if (!isset($GLOBALS['pdo']) && function_exists('getDbConnection')) {
    try {
        $GLOBALS['pdo'] = getDbConnection();
        
        // Add tableExists function if it doesn't exist
        if (!function_exists('tableExists')) {
            function tableExists(PDO $pdo, string $name): bool {
                try { 
                    return (bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($name))->fetchColumn(); 
                } catch(Throwable $e) { 
                    return false; 
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    }
}