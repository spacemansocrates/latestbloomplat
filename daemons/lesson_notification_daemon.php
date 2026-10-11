<?php
/**
 * RETIRED - do not start this. Superseded by cron/send_lesson_reminders.php.
 *
 * This daemon was never scheduled on production (no cron entry, no systemd unit,
 * no supervisor), and it could not have been switched on safely as written:
 *
 *   1. Its INSERT into `notifications` omitted `type`, which is NOT NULL with no
 *      default. That passes on the non-strict staging database and throws on
 *      production (STRICT_TRANS_TABLES).
 *   2. Its duplicate check depended on that row existing, so with the INSERT
 *      failing it would have re-sent the same reminder every 60 seconds for the
 *      whole 10-minute window - roughly 10 duplicate emails per lesson, to both
 *      the student and the tutor.
 *   3. The check itself was `message LIKE '%#<id>%'`, which matches lesson #1
 *      against #10, #100 and #123.
 *   4. It hard-coded Africa/Blantyre instead of using each user's timezone, and
 *      ran as a while(true) loop on a box with no process supervisor.
 *
 * The replacement is a plain cron job with a dedicated dispatch ledger:
 *     cron/send_lesson_reminders.php   (every minute)
 *     cron/send_daily_digest.php       (07:00 Africa/Blantyre)
 *
 * The original body is left below, unreachable, for reference only.
 */

fwrite(STDERR,
    "lesson_notification_daemon.php is retired and does nothing.
" .
    "Use cron/send_lesson_reminders.php (every minute) and cron/send_daily_digest.php (daily).
" .
    "See migrations/2026_09_07_notification_dispatch_log.sql for the ledger it relies on.
");
exit(1);

/**
 * ---- original implementation below, no longer executed ----
 *
 * Lesson Notification Daemon
 * 
 * Continuously running background process that checks for upcoming lessons
 * and sends email notifications to students 15 minutes before their lessons start.
 * 
 * Usage:
 *   php lesson_notification_daemon.php
 * 
 * Or use the start_daemon.bat script on Windows.
 */

// Prevent script timeout
set_time_limit(0);
ini_set('max_execution_time', 0);

// Set timezone (should match your application timezone)
date_default_timezone_set('Africa/Blantyre');

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/daemon_error.log');

// Load dependencies
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/email_helper.php';

// Log file
$logFile = __DIR__ . '/daemon.log';

/**
 * Write to log file and output to terminal
 */
function log_message($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] $message";
    
    // Write to file
    file_put_contents($logFile, $formattedMessage . "\n", FILE_APPEND);
    
    // Output to terminal
    echo $formattedMessage . "\n";
}

/**
 * Get a fresh database connection (forces reconnection)
 */
function getFreshDbConnection() {
    require_once __DIR__ . '/../config/db_connect.php';
    
    if (function_exists('getDbConnection')) {
        // Force reconnection on each check to avoid "MySQL server has gone away"
        return getDbConnection(true);
    }
    
    return null;
}

/**
 * Check for upcoming lessons and send notifications
 */
function check_and_notify() {
    // Get a fresh database connection on each check to avoid "MySQL server has gone away" error
    try {
        $pdo = getFreshDbConnection();
        
        if ($pdo === null) {
            log_message("✗ Database connection not available");
            return;
        }
    } catch (Exception $e) {
        log_message("✗ Database connection error: " . $e->getMessage());
        return;
    }
    
    try {
        // Calculate time window in UTC (since lessons are stored in UTC)
        $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
        $tenMinutesUTC = clone $nowUTC;
        $tenMinutesUTC->modify('+10 minutes');
        $twentyMinutesUTC = clone $nowUTC;
        $twentyMinutesUTC->modify('+20 minutes');
        
        // Query for lessons starting in 10-20 minutes that haven't been notified
        // Using UTC timestamps to match database storage
        $stmt = $pdo->prepare("
            SELECT l.id, l.scheduled_start_time, l.scheduled_end_time,
                   s.name AS subject_name,
                   u.first_name AS tutor_first_name, u.last_name AS tutor_last_name,
                   u.email AS tutor_email,
                   student.id AS student_id,
                   student.email AS student_email, 
                   student.first_name AS student_first_name
            FROM lessons l
            JOIN subjects s ON s.id = l.subject_id
            JOIN users u ON u.id = l.tutor_id
            JOIN users student ON student.id = l.student_id
            WHERE l.status = 'Scheduled'
              AND l.scheduled_start_time >= :start_time
              AND l.scheduled_start_time <= :end_time
              AND NOT EXISTS (
                SELECT 1 FROM notifications n
                WHERE n.user_id = l.student_id
                  AND n.message LIKE CONCAT('Your%lesson%#', l.id, '%')
              )
        ");
        
        $stmt->execute([
            ':start_time' => $tenMinutesUTC->format('Y-m-d H:i:s'),
            ':end_time' => $twentyMinutesUTC->format('Y-m-d H:i:s')
        ]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log the time window being checked (for debugging)
        if (!empty($lessons) || (rand(1, 10) == 1)) { // Log occasionally
            $startLocal = clone $tenMinutesUTC;
            $startLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
            $endLocal = clone $twentyMinutesUTC;
            $endLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
            log_message("Checking window: " . $startLocal->format('H:i') . " - " . $endLocal->format('H:i') . " local (" . 
                       $tenMinutesUTC->format('H:i') . " - " . $twentyMinutesUTC->format('H:i') . " UTC)");
        }
        
        if (empty($lessons)) {
            // Don't log "no lessons" every minute to reduce log noise
            // Only log occasionally (every 10th check or so)
            static $checkCount = 0;
            $checkCount++;
            if ($checkCount % 10 == 0) {
                log_message("No upcoming lessons requiring notification. (Check #$checkCount)");
            }
            return;
        }
        
        log_message("Found " . count($lessons) . " lesson(s) requiring notification.");
        
        foreach ($lessons as $lesson) {
            try {
                // Get fresh connection for each lesson to avoid timeout issues
                $pdo = getFreshDbConnection();
                if ($pdo === null) {
                    log_message("✗ Database connection lost while processing lesson #{$lesson['id']}");
                    continue;
                }
                // Format start time (convert from UTC to local timezone for display)
                $startTime = new DateTime($lesson['scheduled_start_time'], new DateTimeZone('UTC'));
                $startTime->setTimezone(new DateTimeZone('Africa/Blantyre'));
                $formattedTime = $startTime->format('l, F j, Y \a\t g:i A');
                
                // Prepare email data
                $emailData = [
                    'student_name' => $lesson['student_first_name'],
                    'subject_name' => $lesson['subject_name'],
                    'tutor_name' => $lesson['tutor_first_name'] . ' ' . $lesson['tutor_last_name'],
                    'start_time' => $formattedTime,
                    'lesson_id' => $lesson['id']
                ];
                
                // Build email
                $htmlBody = build_lesson_notification_html($emailData);
                $plainBody = build_lesson_notification_plain($emailData);
                
                // 1. Send email to Student
                $studentSubject = "Reminder: Your {$lesson['subject_name']} lesson starts in 15 minutes!";
                $studentResult = send_email(
                    $lesson['student_email'],
                    $lesson['student_first_name'],
                    $studentSubject,
                    $htmlBody,
                    $plainBody
                );
                
                if ($studentResult['success']) {
                    // Record notification in database for student
                    $notificationMessage = "Your {$lesson['subject_name']} lesson with {$emailData['tutor_name']} starts in 15 minutes! #{$lesson['id']}";
                    
                    $insertStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, is_read, created_at)
                        VALUES (?, ?, 0, NOW())
                    ");
                    $insertStmt->execute([$lesson['student_id'], $notificationMessage]);
                    
                    log_message("✓ Sent student notification for lesson #{$lesson['id']} to {$lesson['student_email']}");
                } else {
                    log_message("✗ Failed to send student email for lesson #{$lesson['id']}: {$studentResult['message']}");
                }

                // 2. Send email to Tutor
                $tutorSubject = "Reminder: Your {$lesson['subject_name']} lesson with {$lesson['student_first_name']} starts in 15 minutes!";
                $tutorResult = send_email(
                    $lesson['tutor_email'],
                    $lesson['tutor_first_name'],
                    $tutorSubject,
                    $htmlBody,
                    $plainBody
                );

                if ($tutorResult['success']) {
                    log_message("✓ Sent tutor notification for lesson #{$lesson['id']} to {$lesson['tutor_email']}");
                } else {
                    log_message("✗ Failed to send tutor email for lesson #{$lesson['id']}: {$tutorResult['message']}");
                }
                
            } catch (Exception $e) {
                log_message("✗ Error processing lesson #{$lesson['id']}: " . $e->getMessage());
            }
        }
        
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        
        // Handle "MySQL server has gone away" error specifically
        if (strpos($errorMsg, '2006') !== false || 
            strpos($errorMsg, 'MySQL server has gone away') !== false) {
            log_message("✗ Database connection lost (MySQL server has gone away). Will retry on next check.");
        } else {
            log_message("✗ Database error: " . $errorMsg);
        }
    } catch (Exception $e) {
        log_message("✗ Unexpected error: " . $e->getMessage());
    }
}

// Main daemon loop
log_message("=== Lesson Notification Daemon Started ===");
log_message("Checking for upcoming lessons every 60 seconds...");
log_message("Log file will be auto-cleared every 10 minutes.");

$lastLogClearTime = time();
$logClearInterval = 600; // 10 minutes in seconds

while (true) {
    // Check if it's time to clear the log
    if (time() - $lastLogClearTime >= $logClearInterval) {
        file_put_contents($logFile, ""); // Clear the log file
        log_message("--- Log auto-cleared (10 minute interval) ---");
        $lastLogClearTime = time();
    }

    try {
        check_and_notify();
    } catch (Exception $e) {
        log_message("✗ Unexpected error: " . $e->getMessage());
    }
    
    // Sleep for 60 seconds before next check
    sleep(60);
}
