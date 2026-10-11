<?php
// student/lesson_room.php
// The old external classroom is retired — the lesson runs in the LiveKit room.
// This stub forwards any stale link (old emails, bookmarks, cached dashboards)
// to the current room. Remove this file only once no old links remain in the wild.

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php'); exit();
}

$lesson_id = (isset($_GET['lesson_id']) && ctype_digit($_GET['lesson_id'])) ? (int)$_GET['lesson_id'] : 0;
if ($lesson_id <= 0) { http_response_code(400); echo 'Invalid request'; exit(); }

header('Cache-Control: no-store');
header('Location: lesson_room_live.php?lesson_id=' . $lesson_id, true, 302);
exit();
