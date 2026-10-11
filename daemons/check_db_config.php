<?php
/**
 * Check Database Configuration
 * Run: php check_db_config.php
 */

echo "========================================\n";
echo "Database Configuration Check\n";
echo "========================================\n\n";

$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . '/.env';

echo "1. Checking .env file...\n";
if (file_exists($envFile)) {
    echo "   ✓ .env file exists: $envFile\n";
    $dotenv = parse_ini_file($envFile);
    
    echo "\n   Current configuration:\n";
    echo "   DB_HOST: " . ($dotenv['DB_HOST'] ?? 'NOT SET') . "\n";
    echo "   DB_PORT: " . ($dotenv['DB_PORT'] ?? 'NOT SET') . "\n";
    echo "   DB_NAME: " . ($dotenv['DB_NAME'] ?? 'NOT SET') . "\n";
    echo "   DB_USER: " . ($dotenv['DB_USER'] ?? 'NOT SET') . "\n";
    echo "   DB_PASSWORD: " . (isset($dotenv['DB_PASSWORD']) ? '***SET***' : 'NOT SET') . "\n";
} else {
    echo "   ✗ .env file NOT found: $envFile\n";
    echo "\n   You need to create a .env file with your database settings.\n";
}
echo "\n";

// Check other db_connect files for reference
echo "2. Checking other database config files for reference...\n";
$otherConfigs = [
    $projectRoot . '/db_connect.php',
    $projectRoot . '/tutor/db_connect.php',
    $projectRoot . '/admin/db_connect.php',
];

$foundConfig = false;
foreach ($otherConfigs as $configFile) {
    if (file_exists($configFile)) {
        $foundConfig = true;
        echo "   Found: $configFile\n";
        $content = file_get_contents($configFile);
        
        // Try to extract database info
        if (preg_match("/host\s*=\s*['\"]([^'\"]+)['\"]/", $content, $hostMatch)) {
            echo "     Host: " . $hostMatch[1] . "\n";
        }
        if (preg_match("/db\s*=\s*['\"]([^'\"]+)['\"]/", $content, $dbMatch)) {
            echo "     Database: " . $dbMatch[1] . "\n";
        }
        if (preg_match("/user\s*=\s*['\"]([^'\"]+)['\"]/", $content, $userMatch)) {
            echo "     User: " . $userMatch[1] . "\n";
        }
        break;
    }
}

if (!$foundConfig) {
    echo "   No other config files found for reference.\n";
}
echo "\n";

// Test connection
echo "3. Testing database connection...\n";
try {
    require_once $projectRoot . '/config/db_connect.php';
    
    if (function_exists('getDbConnection')) {
        try {
            $pdo = getDbConnection();
            echo "   ✓ Database connection successful!\n";
            
            // Test a simple query
            $stmt = $pdo->query("SELECT 1");
            echo "   ✓ Database query test successful!\n";
        } catch (Exception $e) {
            echo "   ✗ Database connection failed: " . $e->getMessage() . "\n";
            echo "\n   Possible issues:\n";
            echo "   - Wrong database host\n";
            echo "   - Wrong database name\n";
            echo "   - Wrong username/password\n";
            echo "   - Database server not accessible\n";
        }
    } else {
        echo "   ⚠ getDbConnection() function not found\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error loading config: " . $e->getMessage() . "\n";
}
echo "\n";

// Suggest .env file content
echo "========================================\n";
echo "Recommendation\n";
echo "========================================\n";

if (!file_exists($envFile)) {
    echo "Create a .env file in your project root with:\n\n";
    echo "DB_HOST=localhost\n";
    echo "DB_PORT=3306\n";
    echo "DB_NAME=your_database_name\n";
    echo "DB_USER=your_database_user\n";
    echo "DB_PASSWORD=your_database_password\n";
    echo "DB_CHARSET=utf8mb4\n";
    echo "\n";
    echo "For Hostinger, try:\n";
    echo "DB_HOST=localhost\n";
    echo "(or check your hosting control panel for the correct database host)\n";
}

echo "\n";
