<?php
/**
 * Simple .env Loader
 * 
 * Loads environment variables from a .env file into $_ENV and getenv()
 */

function load_env($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse key=value
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            
            // Remove quotes if present
            $value = trim($value, '"\'');

            if (!empty($key)) {
                // Store in original case
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
                
                // Also store in uppercase for compatibility (Windows getenv is case-sensitive)
                $upperKey = strtoupper($key);
                if ($upperKey !== $key) {
                    $_ENV[$upperKey] = $value;
                    putenv("{$upperKey}={$value}");
                }
            }
        }
    }
    return true;
}
