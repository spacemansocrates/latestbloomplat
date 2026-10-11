<?php
// tutor/api/dashboard_live.php
// Lightweight polling endpoint for the tutor dashboard. Returns the few values that
// change while the page is open, so the UI can auto-update without a manual reload:
//   - unread notification count (badge)
//   - the current "next lesson" id + status (so the page can soft-reload when a
//     booking is added/cancelled or a lesson flips to InProgress/joinable)
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Tutor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../../config/db_connect.php';
$tutor_id = (int)$_SESSION['user_id'];

try {
    // Unread notifications
    $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $n->execute([$tutor_id]);
    $notif = (int)$n->fetchColumn();

    // Next upcoming/in-progress lesson (mirrors the dashboard's "next lesson" pick:
    // scheduled_start_time is stored UTC, so compare against UTC now).
    $l = $pdo->prepare("
        SELECT id, status
        FROM lessons
        WHERE tutor_id = ?
          AND status IN ('Scheduled','InProgress')
          AND scheduled_end_time >= UTC_TIMESTAMP()
        ORDER BY scheduled_start_time ASC
        LIMIT 1
    ");
    $l->execute([$tutor_id]);
    $row = $l->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'         => true,
        'notif_count'     => $notif,
        'lesson_id'       => $row ? (int)$row['id'] : 0,
        'lesson_status'   => $row ? (string)$row['status'] : '',
    ]);
} catch (Throwable $e) {
    error_log('dashboard_live error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
