<?php
// student/api/dashboard_live.php
// Lightweight polling endpoint for the student dashboard: unread notification count
// and the current "next lesson" id/status, so the UI can live-update the badge and
// soft-reload when a lesson is added/cancelled or flips to InProgress/joinable.
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// PHP holds an exclusive lock on the session file for the whole request,
// so concurrent AJAX from one page queues up instead of running in
// parallel. Nothing below writes $_SESSION (reads still work), so the
// lock can go now. Same reasoning as student/availability.php.
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';
$student_id = (int)$_SESSION['user_id'];

try {
    $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $n->execute([$student_id]);
    $notif = (int)$n->fetchColumn();

    // scheduled_start_time is stored UTC, compare against UTC now.
    $l = $pdo->prepare("
        SELECT id, status
        FROM lessons
        WHERE student_id = ?
          AND status IN ('Scheduled','InProgress')
          AND scheduled_end_time >= UTC_TIMESTAMP()
        ORDER BY scheduled_start_time ASC
        LIMIT 1
    ");
    $l->execute([$student_id]);
    $row = $l->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'notif_count'   => $notif,
        'lesson_id'     => $row ? (int)$row['id'] : 0,
        'lesson_status' => $row ? (string)$row['status'] : '',
    ]);
} catch (Throwable $e) {
    error_log('student dashboard_live error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
