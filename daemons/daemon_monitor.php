<?php
/**
 * Daemon Monitor Script
 * 
 * Checks if the lesson notification daemon is running and restarts it if needed.
 * Can be run periodically via Windows Task Scheduler (e.g., every 5 minutes).
 * 
 * Usage:
 *   php daemon_monitor.php
 */

$logFile = __DIR__ . '/monitor.log';

/**
 * Write to monitor log
 */
function log_monitor($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Check if daemon is running
 */
function is_daemon_running() {
    $daemonScript = __DIR__ . '/lesson_notification_daemon.php';
    
    // Check for PHP processes running the specific daemon script
    exec('wmic process where "name=\'php.exe\'" get commandline 2>nul', $output, $returnCode);
    
    foreach ($output as $line) {
        if (stripos($line, 'lesson_notification_daemon.php') !== false) {
            return true;
        }
    }
    
    return false;
}

/** Start the daemon */
function start_daemon() {
    $daemonScript = __DIR__ . '/lesson_notification_daemon.php';
    $logFile = __DIR__ . '/daemon.log';
    
    // Start daemon in background
    $command = "start /B php \"$daemonScript\" > \"$logFile\" 2>&1";
    pclose(popen($command, 'r'));
    
    sleep(2); // Give it time to start
    
    return is_daemon_running();
}

// Main monitoring logic
log_monitor("Monitor check started");

if (is_daemon_running()) {
    log_monitor("Daemon is running - OK");
} else {
    log_monitor("Daemon is NOT running - attempting to restart...");
    
    if (start_daemon()) {
        log_monitor("Daemon restarted successfully");
    } else {
        log_monitor("ERROR: Failed to restart daemon");
    }
}
