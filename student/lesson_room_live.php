<?php
// student/lesson_room_live.php - Self-hosted lesson room (LiveKit video + collaborative whiteboard).
// lesson_room.php is a stub that forwards stale links here.

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php'); exit();
}

$student_id = (int)$_SESSION['user_id'];
$lesson_id  = (isset($_GET['lesson_id']) && ctype_digit($_GET['lesson_id'])) ? (int)$_GET['lesson_id'] : 0;
if ($lesson_id <= 0) { http_response_code(400); echo "Invalid request"; exit(); }

require_once __DIR__ . '/../config/db_connect.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function deny_card($title, $html){
    http_response_code(403);
    ?><!doctype html><html lang="en"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= h($title) ?></title>
      <link rel="icon" type="image/png" href="../favicon.png" />
      <script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="css/theme.css">
    </head><body class="min-h-screen flex items-center justify-center p-6">
      <div class="max-w-lg w-full bg-gray-900 border border-gray-700 rounded-xl p-6">
        <h1 class="text-xl font-bold text-white mb-2"><?= h($title) ?></h1>
        <div class="text-sm text-gray-300 space-y-3"><?= $html ?></div>
        <div class="mt-5">
          <a href="dashboard.php" class="inline-block bg-gray-700 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded-lg">Back to dashboard</a>
        </div>
      </div>
    </body></html><?php
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT l.id, l.student_id, l.tutor_id, l.status,
               s.name AS subject_name,
               tu.first_name AS tutor_first, tu.last_name AS tutor_last
        FROM lessons l
        JOIN subjects s ON s.id = l.subject_id
        JOIN users tu   ON tu.id = l.tutor_id
        WHERE l.id = ? AND l.student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$lesson_id, $student_id]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) {
        deny_card('Lesson not found', '<p>We could not find that lesson for your account.</p>');
    }

    $blocked = ['CancelledByTutor','CancelledByStudent','Completed','NoShow'];
    if (in_array($lesson['status'], $blocked, true)) {
        deny_card('Lesson is not joinable',
          '<p>Status is <span class="text-red-300 font-semibold">'.h($lesson['status']).'</span>.</p>');
    }
} catch (Throwable $e) {
    deny_card('Unexpected error', '<p>Failed to load lesson.</p><p class="text-xs text-gray-400 mt-2">'.h($e->getMessage()).'</p>');
}

$ROOM_ROLE  = 'student';
$PEER_LABEL = 'Tutor: ' . trim(($lesson['tutor_first'] ?? '').' '.($lesson['tutor_last'] ?? ''));
require __DIR__ . '/../components/lesson_room_live_shell.php';
