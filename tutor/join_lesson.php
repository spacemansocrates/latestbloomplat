<?php
// tutor/join_lesson.php
// The old external classroom is retired — the lesson runs in the LiveKit room.
// This stub forwards any stale link (old bookmarks, cached pages) to the current
// room. Remove this file only once no old links remain in the wild.
require_once __DIR__ . '/auth_tutor_only.php';

$lesson_id = 0;
if (isset($_POST['lesson_id']) && ctype_digit((string)$_POST['lesson_id'])) {
    $lesson_id = (int)$_POST['lesson_id'];
} elseif (isset($_GET['lesson_id']) && ctype_digit((string)$_GET['lesson_id'])) {
    $lesson_id = (int)$_GET['lesson_id'];
}
if ($lesson_id <= 0) { http_response_code(400); echo 'Invalid request'; exit(); }

header('Cache-Control: no-store');
header('Location: join_lesson_live.php?lesson_id=' . $lesson_id, true, 302);
exit();
