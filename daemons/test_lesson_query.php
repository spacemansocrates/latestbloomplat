<?php
/**
 * Test Lesson Query
 * 
 * This script helps debug why lesson notifications aren't being sent.
 * It shows what lessons the daemon would find and why.
 * 
 * Usage: php test_lesson_query.php
 */

// Set timezone to match application
date_default_timezone_set('Africa/Blantyre');

require_once __DIR__ . '/../config/db_connect.php';

echo "========================================\n";
echo "Lesson Notification Query Test\n";
echo "========================================\n\n";

// Get database connection
try {
    $pdo = getDbConnection(true); // Force fresh connection
    echo "✓ Database connection successful\n\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Show current server time
echo "1. Current Time Information:\n";
$nowLocal = new DateTime('now', new DateTimeZone('Africa/Blantyre'));
$nowUTC = new DateTime('now', new DateTimeZone('UTC'));
echo "   Local Time (Africa/Blantyre): " . $nowLocal->format('Y-m-d H:i:s T') . "\n";
echo "   UTC Time: " . $nowUTC->format('Y-m-d H:i:s T') . "\n";
echo "   Unix timestamp: " . time() . "\n\n";

// Show time ranges the daemon checks (in UTC since DB stores UTC)
$now = new DateTime('now', new DateTimeZone('UTC'));
$tenMinutes = clone $now;
$tenMinutes->modify('+10 minutes');
$twentyMinutes = clone $now;
$twentyMinutes->modify('+20 minutes');

// Convert to local time for display
$tenMinutesLocal = clone $tenMinutes;
$tenMinutesLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
$twentyMinutesLocal = clone $twentyMinutes;
$twentyMinutesLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));

echo "2. Daemon Checks for Lessons Between (in UTC - database time):\n";
echo "   UTC Start: " . $tenMinutes->format('Y-m-d H:i:s') . " UTC\n";
echo "   UTC End:   " . $twentyMinutes->format('Y-m-d H:i:s') . " UTC\n";
echo "   Local Start: " . $tenMinutesLocal->format('Y-m-d H:i:s T') . "\n";
echo "   Local End:   " . $twentyMinutesLocal->format('Y-m-d H:i:s T') . "\n";
echo "   (10-20 minutes from now)\n\n";

// Check all scheduled lessons
echo "3. All Scheduled Lessons:\n";
try {
    $stmt = $pdo->prepare("
        SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status,
               s.name AS subject_name,
               u.first_name AS tutor_first_name, u.last_name AS tutor_last_name,
               student.first_name AS student_first_name
        FROM lessons l
        LEFT JOIN subjects s ON s.id = l.subject_id
        LEFT JOIN users u ON u.id = l.tutor_id
        LEFT JOIN users student ON student.id = l.student_id
        WHERE l.status = 'Scheduled'
        ORDER BY l.scheduled_start_time ASC
        LIMIT 20
    ");
    
    $stmt->execute();
    $allLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($allLessons)) {
        echo "   ✗ No scheduled lessons found in database\n";
    } else {
        echo "   Found " . count($allLessons) . " scheduled lesson(s):\n\n";
        foreach ($allLessons as $lesson) {
            // Lesson time is stored in UTC in database
            $lessonTimeUTC = new DateTime($lesson['scheduled_start_time'], new DateTimeZone('UTC'));
            $lessonTimeLocal = clone $lessonTimeUTC;
            $lessonTimeLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
            
            $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
            $timeDiff = $nowUTC->diff($lessonTimeUTC);
            $minutesUntil = ($timeDiff->days * 24 * 60) + ($timeDiff->h * 60) + $timeDiff->i;
            
            echo "   Lesson #{$lesson['id']}:\n";
            echo "     Start Time (UTC): " . $lesson['scheduled_start_time'] . " UTC\n";
            echo "     Start Time (Local): " . $lessonTimeLocal->format('Y-m-d H:i:s T') . "\n";
            echo "     Subject: " . ($lesson['subject_name'] ?? 'N/A') . "\n";
            echo "     Tutor: " . ($lesson['tutor_first_name'] ?? 'N/A') . " " . ($lesson['tutor_last_name'] ?? '') . "\n";
            echo "     Student: " . ($lesson['student_first_name'] ?? 'N/A') . "\n";
            echo "     Status: " . $lesson['status'] . "\n";
            echo "     Minutes until start: " . $minutesUntil . "\n";
            
            // Check if this lesson would be found by daemon
            if ($minutesUntil >= 10 && $minutesUntil <= 20) {
                echo "     ✓ WOULD BE FOUND by daemon (within 10-20 minute window)\n";
            } elseif ($minutesUntil < 0) {
                echo "     ✗ Already passed (" . abs($minutesUntil) . " minutes ago)\n";
            } elseif ($minutesUntil < 10) {
                echo "     ✗ Too soon (less than 10 minutes away)\n";
            } elseif ($minutesUntil > 20) {
                echo "     ✗ Too far (more than 20 minutes away)\n";
            }
            echo "";
        }
    }
} catch (PDOException $e) {
    echo "   ✗ Error querying lessons: " . $e->getMessage() . "\n";
}
echo "\n";

// Check what the daemon query would find
echo "4. Lessons That Daemon Would Find (10-20 minutes from now):\n";
try {
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
          AND l.scheduled_start_time BETWEEN NOW() + INTERVAL 10 MINUTE 
                                         AND NOW() + INTERVAL 20 MINUTE
          AND NOT EXISTS (
            SELECT 1 FROM notifications n
            WHERE n.user_id = l.student_id
              AND n.message LIKE CONCAT('Your%lesson%#', l.id, '%')
          )
    ");
    
    $stmt->execute();
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($lessons)) {
        echo "   ✗ No lessons found matching daemon criteria\n";
        echo "\n   Possible reasons:\n";
        echo "   - No lessons scheduled in the 10-20 minute window\n";
        echo "   - Lessons are outside the time window (too soon or too far)\n";
        echo "   - Notifications already sent (check notifications table)\n";
        echo "   - Missing required data (subject, tutor, student)\n";
    } else {
        echo "   ✓ Found " . count($lessons) . " lesson(s) that would trigger notifications:\n\n";
        foreach ($lessons as $lesson) {
            echo "   Lesson #{$lesson['id']}:\n";
            echo "     Start: " . $lesson['scheduled_start_time'] . "\n";
            echo "     Subject: " . $lesson['subject_name'] . "\n";
            echo "     Student: " . $lesson['student_first_name'] . " (" . $lesson['student_email'] . ")\n";
            echo "     Tutor: " . $lesson['tutor_first_name'] . " " . $lesson['tutor_last_name'] . " (" . $lesson['tutor_email'] . ")\n";
            echo "";
        }
    }
} catch (PDOException $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Check notifications table
echo "5. Recent Notifications:\n";
try {
    $stmt = $pdo->prepare("
        SELECT id, user_id, message, created_at
        FROM notifications
        WHERE message LIKE '%lesson%'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($notifications)) {
        echo "   No lesson-related notifications found\n";
    } else {
        echo "   Found " . count($notifications) . " recent notification(s):\n";
        foreach ($notifications as $notif) {
            echo "   - [" . $notif['created_at'] . "] User #{$notif['user_id']}: " . substr($notif['message'], 0, 60) . "...\n";
        }
    }
} catch (PDOException $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Check timezone settings
echo "6. Timezone Information:\n";
echo "   PHP timezone: " . date_default_timezone_get() . "\n";
echo "   Server time: " . date('Y-m-d H:i:s T') . "\n";

// Check MySQL timezone
try {
    $stmt = $pdo->query("SELECT @@global.time_zone, @@session.time_zone, NOW() as mysql_now");
    $tz = $stmt->fetch();
    echo "   MySQL global timezone: " . $tz['@@global.time_zone'] . "\n";
    echo "   MySQL session timezone: " . $tz['@@session.time_zone'] . "\n";
    echo "   MySQL NOW(): " . $tz['mysql_now'] . "\n";
} catch (PDOException $e) {
    echo "   Could not check MySQL timezone\n";
}
echo "\n";

echo "========================================\n";
echo "Recommendations\n";
echo "========================================\n";
echo "\n";
echo "If your lesson is at 17:05 but not being found:\n";
echo "1. Check if server timezone matches your local timezone\n";
echo "2. Verify the lesson is stored in database with correct time\n";
echo "3. Check if the lesson time is within 10-20 minutes from NOW()\n";
echo "4. Verify lesson status is 'Scheduled'\n";
echo "5. Check if notification was already sent (see notifications table)\n";
echo "\n";
