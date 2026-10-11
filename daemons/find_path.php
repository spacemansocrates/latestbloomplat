<?php
/**
 * Path Finder Script
 * 
 * Run this script to find the correct absolute path for your cron job.
 * 
 * Usage: php find_path.php
 */

echo "========================================\n";
echo "TutSys Daemon - Path Finder\n";
echo "========================================\n\n";

// Get the absolute path to this script
$daemonsDir = __DIR__;
$projectRoot = dirname($daemonsDir);

echo "Your project paths:\n";
echo "-------------------\n";
echo "Project Root:  $projectRoot\n";
echo "Daemons Dir:  $daemonsDir\n";
echo "Monitor Script: $daemonsDir/cron_monitor.sh\n";
echo "\n";

// Try to detect common hosting patterns
$homeDir = getenv('HOME');
if (!$homeDir) {
    $homeDir = getenv('USERPROFILE'); // Windows fallback
}

echo "Common hosting paths:\n";
echo "--------------------\n";

// Check if it's in public_html
if (strpos($projectRoot, 'public_html') !== false) {
    echo "✓ Detected: public_html structure\n";
    $username = basename(dirname($projectRoot));
    $suggestedPath = "/home/$username/public_html/tutsys3.0/daemons/cron_monitor.sh";
    echo "Suggested cron path:\n";
    echo "  $suggestedPath\n";
    echo "\n";
    echo "If this looks correct, use it in your crontab.\n";
    echo "If not, use the absolute path shown above.\n";
} else {
    echo "Use this EXACT path in your crontab:\n";
    echo "  $daemonsDir/cron_monitor.sh\n";
}

echo "\n";
echo "To add to crontab:\n";
echo "------------------\n";
echo "1. Run: crontab -e\n";
echo "2. Add this line (runs every 5 minutes):\n";
echo "   */5 * * * * $daemonsDir/cron_monitor.sh\n";
echo "\n";

// Check if PHP CLI is available
$phpPath = exec('which php 2>/dev/null');
if ($phpPath) {
    echo "PHP CLI found at: $phpPath\n";
} else {
    echo "WARNING: PHP CLI path not found. You may need to use full path to PHP.\n";
    echo "Example: /usr/bin/php $daemonsDir/lesson_notification_daemon.php\n";
}

echo "\n";
echo "To verify the path exists:\n";
echo "--------------------------\n";
echo "Run: ls -la $daemonsDir/cron_monitor.sh\n";
echo "If you see the file, the path is correct!\n";
echo "\n";
