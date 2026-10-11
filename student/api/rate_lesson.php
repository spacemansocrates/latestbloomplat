<?php
// students/rate_lesson.php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

// ---------- Auth ----------
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get raw POST data for debugging
$input = file_get_contents('php://input');
$data = $_POST;

// Log the received data for debugging
error_log("Rate Lesson - Received data: " . print_r($data, true));

$student_id = (int)$_SESSION['user_id'];
$lesson_id  = isset($data['lesson_id']) ? (int)$data['lesson_id'] : 0;
$rating     = isset($data['rating']) ? (int)$data['rating'] : 0;
// Get comment from the form data
$feedback   = trim((string)($data['comment'] ?? ''));

// Basic input validation
if ($lesson_id <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid lesson ID',
        'debug' => [
            'lesson_id' => $lesson_id,
            'rating' => $rating,
            'feedback_length' => strlen($feedback)
        ]
    ]);
    exit;
}

if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode([
        'success' => false, 
        'message' => 'Please provide a rating between 1 and 5',
        'debug' => [
            'lesson_id' => $lesson_id,
            'rating' => $rating
        ]
    ]);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

try {
    // ---------- 1) Verify ownership + eligibility ----------
    $stmt = $pdo->prepare("
        SELECT 
            l.id, l.student_id, l.tutor_id, l.status, l.student_join_time,
            l.scheduled_start_time, l.scheduled_end_time,
            s.name AS subject_name,
            u.first_name AS tutor_first, u.last_name AS tutor_last
        FROM lessons l
        JOIN subjects s ON s.id = l.subject_id
        JOIN users u    ON u.id = l.tutor_id
        WHERE l.id = ? AND l.student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$lesson_id, $student_id]);
    $L = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$L) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lesson not found']);
        exit;
    }

    // Must have joined
    if ($L['student_join_time'] === null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You can only rate lessons you joined.']);
        exit;
    }

    // Must be over (Completed OR end time has passed)
    $ended = false;
    if (!empty($L['scheduled_end_time'])) {
        $ended = (new DateTime($L['scheduled_end_time'])) <= new DateTime();
    }
    if ($L['status'] === 'Completed') {
        $ended = true;
    }
    if (!$ended) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You can rate after the lesson ends.']);
        exit;
    }

    $tutor_id = (int)$L['tutor_id'];

    // ---------- 2) Upsert rating (1 rating per lesson per schema) ----------
    $sql = "
        INSERT INTO lesson_ratings (lesson_id, student_id, tutor_id, rating, feedback, created_at)
        VALUES (:lesson_id, :student_id, :tutor_id, :rating, :feedback, NOW())
        ON DUPLICATE KEY UPDATE
            rating   = VALUES(rating),
            feedback = VALUES(feedback),
            tutor_id = VALUES(tutor_id)
    ";
    $ins = $pdo->prepare($sql);
    $ins->execute([
        ':lesson_id'  => $lesson_id,
        ':student_id' => $student_id,
        ':tutor_id'   => $tutor_id,
        ':rating'     => $rating,
        ':feedback'   => ($feedback !== '' ? $feedback : null),
    ]);

    // ---------- 3) Admin notification on low rating ----------
    // Adjust threshold if you want 3 and below to notify
    $LOW_RATING_THRESHOLD = 2;

    if ($rating <= $LOW_RATING_THRESHOLD) {
        // Detect if 'LowRating' exists in notifications.type enum; fallback if not.
        $type = 'LowRating';
        $ctStmt = $pdo->prepare("
            SELECT COLUMN_TYPE 
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notifications'
              AND COLUMN_NAME = 'type'
            LIMIT 1
        ");
        $ctStmt->execute();
        $ctype = (string)$ctStmt->fetchColumn();
        if (stripos($ctype, "'LowRating'") === false) {
            // Fallback to an existing type if LowRating isn't in the enum
            $type = 'ScheduleChange';
        }

        // Who to notify: admins via admin_details (fallback to users.role='Administrator')
        $adminIds = [];
        $rs = $pdo->query("SELECT user_id FROM admin_details");
        foreach ($rs ?: [] as $r) { $adminIds[] = (int)$r['user_id']; }
        if (!$adminIds) {
            $rs = $pdo->query("SELECT id FROM users WHERE role='Administrator'");
            foreach ($rs ?: [] as $r) { $adminIds[] = (int)$r['id']; }
        }

        if ($adminIds) {
            $when = $L['scheduled_start_time'] ? (new DateTime($L['scheduled_start_time']))->format('M j, Y') : 'N/A';
            $msg = sprintf(
                'Low lesson rating %d★ by Student #%d for %s with %s %s on %s (Lesson #%d).',
                $rating,
                $student_id,
                ($L['subject_name'] ?? 'Lesson'),
                ($L['tutor_first'] ?? 'Tutor'),
                ($L['tutor_last'] ?? ''),
                $when,
                $lesson_id
            );

            $ni = $pdo->prepare("INSERT INTO notifications (user_id, type, message, related_entity_id, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            foreach ($adminIds as $aid) {
                $ni->execute([$aid, $type, $msg, $lesson_id]);
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
}
