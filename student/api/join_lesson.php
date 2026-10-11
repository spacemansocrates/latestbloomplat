<?php
/**
 * students/join_lesson.php  → Compatibility router to embedded room
 *
 * Why this exists:
 * - Your dashboard and older emails/buttons may still link to join_lesson.php.
 * - We now run the class *inside your app* (iframe) at students/lesson_room.php.
 * - This file validates auth + input and then forwards there.
 *
 * Behavior:
 * - Requires logged-in Student.
 * - Validates ?lesson_id.
 * - Optional soft check: verify the lesson belongs to the student (404 if not).
 * - 302 redirect to lesson_room.php?lesson_id=...
 *
 * Notes:
 * - The *actual* join window enforcement, status flips, and Lessonspace Launch
 *   are handled by students/lesson_room.php (the embedded page).
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$lesson_id  = (isset($_GET['lesson_id']) && ctype_digit($_GET['lesson_id'])) ? (int)$_GET['lesson_id'] : 0;

if ($lesson_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Invalid request (missing/invalid lesson_id).";
    exit();
}

// DB check (optional but safer): ensure the lesson belongs to this student
require_once __DIR__ . '/../../config/db_connect.php';

try {
    $st = $pdo->prepare("SELECT id FROM lessons WHERE id = ? AND student_id = ? LIMIT 1");
    $st->execute([$lesson_id, $student_id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Lesson not found for your account.";
        exit();
    }
} catch (Throwable $e) {
    // If DB check fails unexpectedly, still avoid leaking details
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Unexpected error. Please try again.";
    exit();
}

// Forward into the embedded room page (single source of truth)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ../lesson_room_live.php?lesson_id=' . $lesson_id, true, 302);
exit();
