<?php
// tutor/api/schedule_lesson.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Tutor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../utils/timezone_helper.php';

$tutor_id   = (int)$_SESSION['user_id'];
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
$date       = $_POST['lesson_date'] ?? '';
$start_time = $_POST['start_time'] ?? '';
$end_time   = $_POST['end_time'] ?? '';

if ($student_id <= 0 || $subject_id <= 0 || !$date || !$start_time || !$end_time) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// The tutor types the time in THEIR OWN timezone. Interpret it in that zone and
// store the UTC instant (scheduled_start_time holds UTC, matching admin/lessona/
// availability and the utcToLocal() display convention). Everyone then sees the
// same moment converted to their own clock.
$schedTz = getSchedulerTimezone(); // tutor's session timezone (UK fallback)
try {
    $startDt = new DateTime("$date $start_time", new DateTimeZone($schedTz));
    $endDt   = new DateTime("$date $end_time",   new DateTimeZone($schedTz));
    $startDt->setTimezone(new DateTimeZone('UTC'));
    $endDt->setTimezone(new DateTimeZone('UTC'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Invalid date or time']);
    exit();
}
$start_ts = $startDt->format('Y-m-d H:i:s');
$end_ts   = $endDt->format('Y-m-d H:i:s');

if ($start_ts >= $end_ts) {
    echo json_encode(['success' => false, 'message' => 'Start time must be before end time']);
    exit();
}

try {
    // 1. Verify student is assigned to this tutor
    $stmt = $pdo->prepare("
        SELECT 1 FROM tutor_student_assignments 
        WHERE tutor_id = ? AND student_id = ? 
        AND (end_date IS NULL OR end_date >= CURDATE())
        LIMIT 1
    ");
    $stmt->execute([$tutor_id, $student_id]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Student is not assigned to you']);
        exit();
    }

    // 2. Check for overlaps for THIS tutor
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lessons 
        WHERE tutor_id = ? 
        AND status IN ('Scheduled', 'InProgress')
        AND (
            (scheduled_start_time < ? AND scheduled_end_time > ?)
        )
    ");
    $stmt->execute([$tutor_id, $end_ts, $start_ts]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a lesson scheduled during this time']);
        exit();
    }

    // 2b. And for the STUDENT: the check above only covers this tutor's own
    // diary, so two different tutors could book the same student into the
    // same slot and neither would see a clash.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lessons
        WHERE student_id = ?
        AND status IN ('Scheduled', 'InProgress')
        AND (scheduled_start_time < ? AND scheduled_end_time > ?)
    ");
    $stmt->execute([$student_id, $end_ts, $start_ts]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'That student already has a lesson booked during this time']);
        exit();
    }

    // 3. Get tutor's default meeting link (optional fallback)
    $stmt = $pdo->prepare("SELECT google_meet_link FROM tutor_details WHERE user_id = ?");
    $stmt->execute([$tutor_id]);
    $meeting_url = $stmt->fetchColumn() ?: '';

    // 4. Insert lesson
    // scheduled_start_time_utc / scheduled_end_time_utc are NOT NULL with no
    // default. Production runs STRICT_TRANS_TABLES, so omitting them made every
    // insert here fail with "doesn't have a default value" -- a 500 on every
    // attempt to schedule. (The staging copy has strict mode off, which silently
    // wrote '0000-00-00' instead, so it never showed up there.) They hold the
    // same UTC instant as scheduled_start_time. scheduled_timezone is recorded
    // properly too, rather than taking a default that named the wrong zone.
    $stmt = $pdo->prepare("
        INSERT INTO lessons (
            tutor_id, student_id, subject_id, 
            scheduled_start_time, scheduled_end_time, 
            scheduled_start_time_utc, scheduled_end_time_utc, scheduled_timezone,
            status, meeting_url, created_at
        ) VALUES (
            ?, ?, ?, 
            ?, ?, 
            ?, ?, ?,
            'Scheduled', ?, NOW()
        )
    ");
    $stmt->execute([
        $tutor_id, $student_id, $subject_id, 
        $start_ts, $end_ts, 
        $start_ts, $end_ts, $schedTz,
        $meeting_url
    ]);

    echo json_encode(['success' => true, 'lesson_id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    error_log('schedule_lesson: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not schedule the lesson. Please try again.']);
}
