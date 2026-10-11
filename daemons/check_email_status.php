<?php
/**
 * Check Email Status for Recent Lesson Notifications
 * 
 * This script checks if emails were sent and what the status was.
 */

date_default_timezone_set('Africa/Blantyre');
require_once __DIR__ . '/../config/db_connect.php';

echo "========================================\n";
echo "Email Status Check\n";
echo "========================================\n\n";

try {
    $pdo = getDbConnection(true);
    echo "✓ Database connected\n\n";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Check recent notifications
echo "1. Recent Lesson Notifications (Last 10):\n";
$stmt = $pdo->prepare("
    SELECT id, user_id, message, created_at
    FROM notifications
    WHERE message LIKE '%lesson%' AND message LIKE '%15 minutes%'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($notifications)) {
    echo "   ✗ No lesson notifications found in database\n";
    echo "   This means emails were NOT sent or notifications were NOT recorded.\n";
} else {
    echo "   ✓ Found " . count($notifications) . " notification(s):\n\n";
    foreach ($notifications as $notif) {
        echo "   [" . $notif['created_at'] . "] User #{$notif['user_id']}\n";
        echo "   " . $notif['message'] . "\n\n";
    }
}

echo "\n";

// Check daemon log for email sending status
echo "2. Checking daemon log for email status...\n";
$logFile = __DIR__ . '/daemon.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    
    // Look for email sending messages
    $sentCount = substr_count($logContent, 'Sent student notification');
    $sentTutorCount = substr_count($logContent, 'Sent tutor notification');
    $failedCount = substr_count($logContent, 'Failed to send');
    
    echo "   Email sending status from log:\n";
    echo "   - Student emails sent: $sentCount\n";
    echo "   - Tutor emails sent: $sentTutorCount\n";
    echo "   - Failed sends: $failedCount\n\n";
    
    // Show recent email-related log entries
    $lines = explode("\n", $logContent);
    $emailLines = array_filter($lines, function($line) {
        return stripos($line, 'sent') !== false || 
               stripos($line, 'failed') !== false || 
               stripos($line, 'email') !== false ||
               stripos($line, 'notification') !== false;
    });
    
    if (!empty($emailLines)) {
        echo "   Recent email-related log entries:\n";
        $recent = array_slice($emailLines, -10);
        foreach ($recent as $line) {
            echo "   " . trim($line) . "\n";
        }
    }
} else {
    echo "   ✗ Daemon log file not found\n";
}

echo "\n";

// Check email configuration
echo "3. Email Configuration Check:\n";
$emailConfig = require __DIR__ . '/../config/email_config.php';

if (empty($emailConfig['smtp_username']) || empty($emailConfig['smtp_password'])) {
    echo "   ✗ Email configuration is incomplete!\n";
    echo "   SMTP username or password is missing.\n";
    echo "   Check: config/email_config.php or .env file\n";
} else {
    echo "   ✓ SMTP username: " . $emailConfig['smtp_username'] . "\n";
    echo "   ✓ SMTP host: " . $emailConfig['smtp_host'] . "\n";
    echo "   ✓ SMTP port: " . $emailConfig['smtp_port'] . "\n";
    echo "   ✓ From email: " . $emailConfig['from_email'] . "\n";
}

echo "\n";

// Check error log
echo "4. Checking error log...\n";
$errorLog = __DIR__ . '/daemon_error.log';
if (file_exists($errorLog)) {
    $errorContent = file_get_contents($errorLog);
    if (!empty(trim($errorContent))) {
        echo "   Recent errors:\n";
        $errorLines = explode("\n", $errorContent);
        $recentErrors = array_slice($errorLines, -5);
        foreach ($recentErrors as $line) {
            if (!empty(trim($line))) {
                echo "   " . trim($line) . "\n";
            }
        }
    } else {
        echo "   ✓ No errors in error log\n";
    }
} else {
    echo "   ⚠ Error log file not found\n";
}

echo "\n";

// Check for lesson #315 specifically
echo "5. Checking lesson #315 status:\n";
$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.status,
           student.id AS student_id, student.email AS student_email,
           u.email AS tutor_email
    FROM lessons l
    JOIN users student ON student.id = l.student_id
    JOIN users u ON u.id = l.tutor_id
    WHERE l.id = 315
");
$stmt->execute();
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);

if ($lesson) {
    echo "   Lesson #315:\n";
    echo "   - Student email: " . $lesson['student_email'] . "\n";
    echo "   - Tutor email: " . $lesson['tutor_email'] . "\n";
    echo "   - Start time: " . $lesson['scheduled_start_time'] . " UTC\n";
    
    // Check if notification was created
    $notifStmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM notifications
        WHERE user_id = ? AND message LIKE CONCAT('Your%lesson%#', ?, '%')
    ");
    $notifStmt->execute([$lesson['student_id'], $lesson['id']]);
    $notifCount = $notifStmt->fetch()['count'];
    
    if ($notifCount > 0) {
        echo "   ✓ Notification was created in database\n";
    } else {
        echo "   ✗ Notification was NOT created in database\n";
        echo "   This suggests the email sending failed.\n";
    }
} else {
    echo "   ✗ Lesson #315 not found\n";
}

echo "\n";
echo "========================================\n";
echo "Recommendations\n";
echo "========================================\n";
echo "\n";
echo "If emails are not being sent:\n";
echo "1. Check SMTP credentials in config/email_config.php or .env\n";
echo "2. Verify email server is accessible from your hosting\n";
echo "3. Check spam/junk folder\n";
echo "4. Review daemon_error.log for email sending errors\n";
echo "5. Test email sending manually with a test script\n";
echo "\n";
