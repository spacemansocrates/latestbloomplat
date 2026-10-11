<?php
/**
 * Check Lessons Right Now
 * 
 * This script shows what lessons the daemon would find RIGHT NOW
 * and why your new lessons might not be appearing.
 */

date_default_timezone_set('Africa/Blantyre');
require_once __DIR__ . '/../config/db_connect.php';

echo "========================================\n";
echo "Checking Lessons RIGHT NOW\n";
echo "========================================\n\n";

try {
    $pdo = getDbConnection(true);
    echo "✓ Database connected\n\n";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Current time
$nowUTC = new DateTime('now', new DateTimeZone('UTC'));
$nowLocal = new DateTime('now', new DateTimeZone('Africa/Blantyre'));

echo "Current Time:\n";
echo "  UTC:   " . $nowUTC->format('Y-m-d H:i:s') . "\n";
echo "  Local: " . $nowLocal->format('Y-m-d H:i:s T') . "\n\n";

// What the daemon is looking for
$tenMin = clone $nowUTC;
$tenMin->modify('+10 minutes');
$twentyMin = clone $nowUTC;
$twentyMin->modify('+20 minutes');

echo "Daemon checks for lessons starting between:\n";
echo "  " . $tenMin->format('Y-m-d H:i:s') . " UTC (" . $tenMin->setTimezone(new DateTimeZone('Africa/Blantyre'))->format('H:i T') . " local)\n";
echo "  " . $twentyMin->format('Y-m-d H:i:s') . " UTC (" . $twentyMin->setTimezone(new DateTimeZone('Africa/Blantyre'))->format('H:i T') . " local)\n\n";

// Get ALL lessons scheduled for today and tomorrow
echo "========================================\n";
echo "All Lessons Scheduled (Today & Tomorrow)\n";
echo "========================================\n\n";

$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.status,
           s.name AS subject_name,
           u.first_name AS tutor_first_name, u.last_name AS tutor_last_name,
           student.first_name AS student_first_name, student.id AS student_id
    FROM lessons l
    LEFT JOIN subjects s ON s.id = l.subject_id
    LEFT JOIN users u ON u.id = l.tutor_id
    LEFT JOIN users student ON student.id = l.student_id
    WHERE l.scheduled_start_time >= CURDATE()
      AND l.scheduled_start_time < DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    ORDER BY l.scheduled_start_time ASC
");

$stmt->execute();
$allLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allLessons)) {
    echo "✗ NO LESSONS FOUND for today or tomorrow!\n";
    echo "\nThis means:\n";
    echo "  - No lessons were created in the database\n";
    echo "  - Or lessons are scheduled for a different date\n";
} else {
    echo "Found " . count($allLessons) . " lesson(s):\n\n";
    
    foreach ($allLessons as $lesson) {
        $lessonTimeUTC = new DateTime($lesson['scheduled_start_time'], new DateTimeZone('UTC'));
        $lessonTimeLocal = clone $lessonTimeUTC;
        $lessonTimeLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
        
        $diff = $nowUTC->diff($lessonTimeUTC);
        $minutesUntil = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        if ($diff->invert) {
            $minutesUntil = -$minutesUntil; // Negative if in the past
        }
        
        echo "Lesson #{$lesson['id']}:\n";
        echo "  Status: " . $lesson['status'] . "\n";
        echo "  Start Time: " . $lessonTimeLocal->format('Y-m-d H:i:s T') . " (local)\n";
        echo "  Start Time: " . $lesson['scheduled_start_time'] . " (UTC)\n";
        echo "  Subject: " . ($lesson['subject_name'] ?? 'MISSING') . "\n";
        echo "  Tutor: " . ($lesson['tutor_first_name'] ?? 'MISSING') . " " . ($lesson['tutor_last_name'] ?? '') . "\n";
        echo "  Student: " . ($lesson['student_first_name'] ?? 'MISSING') . " (ID: " . ($lesson['student_id'] ?? 'MISSING') . ")\n";
        echo "  Minutes from now: " . $minutesUntil . "\n";
        
        // Check why it might not be found
        $issues = [];
        
        if ($lesson['status'] !== 'Scheduled') {
            $issues[] = "✗ Status is '{$lesson['status']}' (needs to be 'Scheduled')";
        }
        
        if (empty($lesson['subject_name'])) {
            $issues[] = "✗ Missing subject";
        }
        
        if (empty($lesson['tutor_first_name'])) {
            $issues[] = "✗ Missing tutor";
        }
        
        if (empty($lesson['student_first_name'])) {
            $issues[] = "✗ Missing student";
        }
        
        if ($minutesUntil < 0) {
            $issues[] = "✗ Already passed (" . abs($minutesUntil) . " minutes ago)";
        } elseif ($minutesUntil < 10) {
            $issues[] = "⚠ Too soon (less than 10 minutes away)";
        } elseif ($minutesUntil > 20) {
            $issues[] = "⚠ Too far (more than 20 minutes away)";
        } elseif ($minutesUntil >= 10 && $minutesUntil <= 20) {
            // Check if notification already sent
            $notifStmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM notifications
                WHERE user_id = ?
                  AND message LIKE CONCAT('Your%lesson%#', ?, '%')
            ");
            $notifStmt->execute([$lesson['student_id'], $lesson['id']]);
            $notifCount = $notifStmt->fetch()['count'];
            
            if ($notifCount > 0) {
                $issues[] = "⚠ Notification already sent";
            } else {
                echo "  ✓ SHOULD BE FOUND by daemon!\n";
            }
        }
        
        if (!empty($issues)) {
            echo "  Issues:\n";
            foreach ($issues as $issue) {
                echo "    $issue\n";
            }
        }
        
        echo "\n";
    }
}

// Now check what the daemon query would actually find
echo "========================================\n";
echo "What Daemon Would Find RIGHT NOW\n";
echo "========================================\n\n";

$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time,
           s.name AS subject_name,
           u.first_name AS tutor_first_name, u.last_name AS tutor_last_name,
           student.first_name AS student_first_name, student.email AS student_email
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
$foundLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($foundLessons)) {
    echo "✗ Daemon would find ZERO lessons right now\n\n";
    echo "Reasons why:\n";
    echo "  1. No lessons in the 10-20 minute window\n";
    echo "  2. Lessons have wrong status (not 'Scheduled')\n";
    echo "  3. Notifications already sent\n";
    echo "  4. Missing required data (subject, tutor, student)\n";
} else {
    echo "✓ Daemon would find " . count($foundLessons) . " lesson(s):\n\n";
    foreach ($foundLessons as $lesson) {
        $lessonTimeUTC = new DateTime($lesson['scheduled_start_time'], new DateTimeZone('UTC'));
        $lessonTimeLocal = clone $lessonTimeUTC;
        $lessonTimeLocal->setTimezone(new DateTimeZone('Africa/Blantyre'));
        
        echo "  Lesson #{$lesson['id']}:\n";
        echo "    Subject: " . $lesson['subject_name'] . "\n";
        echo "    Time: " . $lessonTimeLocal->format('Y-m-d H:i:s T') . "\n";
        echo "    Student: " . $lesson['student_first_name'] . " (" . $lesson['student_email'] . ")\n";
        echo "    Tutor: " . $lesson['tutor_first_name'] . " " . $lesson['tutor_last_name'] . "\n";
        echo "\n";
    }
}

echo "\n";
