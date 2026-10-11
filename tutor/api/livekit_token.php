<?php
// tutor/api/livekit_token.php - mints a LiveKit room token for this tutor's lesson

header('Content-Type: application/json');
session_start();

function sendJsonResponse($success, $message, $statusCode = 200, $data = []) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Tutor') {
        sendJsonResponse(false, 'Unauthorized', 403);
    }

    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../integrations/livekit_client.php';
    require_once __DIR__ . '/../../utils/timezone_helper.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection failed');
    }
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    sendJsonResponse(false, 'Database connection failed', 500);
}

$lesson_id = 0;
if (isset($_GET['lesson_id']) && ctype_digit((string)$_GET['lesson_id'])) {
    $lesson_id = (int)$_GET['lesson_id'];
} elseif (isset($_POST['lesson_id']) && ctype_digit((string)$_POST['lesson_id'])) {
    $lesson_id = (int)$_POST['lesson_id'];
}
if ($lesson_id <= 0) {
    sendJsonResponse(false, 'Invalid lesson_id', 400);
}

$tutor_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $stmt = $pdo->prepare("SELECT l.id, l.student_id, l.tutor_id, l.status,
                                  l.scheduled_start_time, l.scheduled_end_time,
                                  s.name AS subject_name,
                                  st.first_name AS student_first, st.last_name AS student_last,
                                  tu.first_name AS tutor_first, tu.last_name AS tutor_last
                           FROM lessons l
                           JOIN subjects s ON s.id = l.subject_id
                           JOIN users st ON st.id = l.student_id
                           JOIN users tu ON tu.id = l.tutor_id
                           WHERE l.id = ? AND l.tutor_id = ?
                           LIMIT 1");
    $stmt->execute([$lesson_id, $tutor_id]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lesson) {
        sendJsonResponse(false, 'Lesson not found', 404);
    }

    $blocked = ['CancelledByTutor','CancelledByStudent','Completed','NoShow'];
    if (in_array($lesson['status'], $blocked, true)) {
        sendJsonResponse(false, 'This lesson is not joinable (status: ' . $lesson['status'] . ')', 403);
    }

    // Same window rule as the student side. tutor_join_time is the flag that
    // stops sweep_absent_tutors raising a TutorNoShow, so stamping it for a
    // room opened days early would mask a tutor who never actually turned up.
    $inWindow   = isWithinAttendanceWindow($lesson['scheduled_start_time'], $lesson['scheduled_end_time']);
    $registerIn = $inWindow ? 0 : msUntilAttendanceWindow($lesson['scheduled_start_time']);
    if ($inWindow) {
        try {
            $pdo->prepare("UPDATE lessons SET tutor_join_time = COALESCE(tutor_join_time, NOW()) WHERE id = ?")
                ->execute([$lesson_id]);
        } catch (Throwable $e) {}
        try {
            $pdo->prepare("UPDATE lessons SET status = 'InProgress' WHERE id = ? AND status = 'Scheduled'")
                ->execute([$lesson_id]);
        } catch (Throwable $e) {}
    }

    $displayName = trim(($lesson['tutor_first'] ?? '').' '.($lesson['tutor_last'] ?? '')) ?: 'Tutor';

    $token = livekit_create_token($pdo, [
        'room'     => "lesson-{$lesson_id}",
        'identity' => "tutor-{$tutor_id}",
        'name'     => $displayName,
        'metadata' => ['role' => 'tutor', 'lesson_id' => $lesson_id],
    ]);

    $cfg = livekit_config($pdo);

    sendJsonResponse(true, 'Token created', 200, [
        'url'         => $cfg['url'],
        'token'       => $token,
        'room'        => "lesson-{$lesson_id}",
        'identity'    => "tutor-{$tutor_id}",
        'displayName' => $displayName,
        'peerName'    => trim(($lesson['student_first'] ?? '').' '.($lesson['student_last'] ?? '')) ?: 'Student',
        'subject'     => $lesson['subject_name'] ?? 'Lesson',
        // Who is allowed to end this lesson for both sides. Minted from the lesson
        // row, not from the caller, and compared against the LiveKit participant
        // identity (which LiveKit signs into the token and a client cannot forge).
        'hostIdentity' => 'tutor-' . (int)$lesson['tutor_id'],
        'attendanceRegistered' => $inWindow,
        'registerInMs'         => $registerIn,
    ]);

} catch (Throwable $e) {
    error_log('Unhandled error in livekit_token (tutor): ' . $e->getMessage());
    $message = 'An unexpected error occurred';
    if (strpos($e->getMessage(), 'LiveKit is not configured') !== false) {
        $message = $e->getMessage();
    } elseif (strpos($e->getMessage(), 'SQLSTATE') !== false) {
        $message = 'Database error. Please try again later.';
    }
    sendJsonResponse(false, $message, 500);
}
