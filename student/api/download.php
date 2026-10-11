<?php
// students/download.php
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('Unauthorized'); }
$role    = $_SESSION['user_role'] ?? '';
$user_id = (int)$_SESSION['user_id'];

// PHP holds an exclusive lock on the session file for the whole request,
// so concurrent AJAX from one page queues up instead of running in
// parallel. Nothing below writes $_SESSION (reads still work), so the
// lock can go now. Same reasoning as student/availability.php.
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
$disp = (($_GET['disposition'] ?? 'attachment') === 'inline') ? 'inline' : 'attachment';

if (!in_array($type, ['homework','submission'], true) || $id <= 0) { http_response_code(400); exit('Bad request'); }

try {
// ---- Resolve record + permissions ----
if ($type === 'homework') {
  // attachment belongs to a homework
  $sql = "
    SELECT
      ha.file_name, ha.file_url,
      h.student_id, h.tutor_id
    FROM homework_attachments ha
    JOIN homework h ON h.id = ha.homework_id
    WHERE ha.id = ?
    LIMIT 1
  ";
} else { // submission
  // attachment belongs to a student's submission for some homework
  $sql = "
    SELECT
      sa.file_name, sa.file_url,
      hs.student_id, h.tutor_id
    FROM submission_attachments sa
    JOIN homework_submissions hs ON hs.id = sa.submission_id
    JOIN homework h ON h.id = hs.homework_id AND h.student_id = hs.student_id
    WHERE sa.id = ?
    LIMIT 1
  ";
}

$st = $pdo->prepare($sql);
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('File not found'); }

// Role-based access:
// - Student: must match student_id
// - Tutor: must match tutor_id
// - Administrator: always allowed
$allowed = false;
if ($role === 'Student'       && (int)$row['student_id'] === $user_id) $allowed = true;
if ($role === 'Tutor'         && (int)$row['tutor_id']   === $user_id) $allowed = true;
if ($role === 'Administrator') $allowed = true;

if (!$allowed) { http_response_code(404); exit('File not found'); }

// ---- Cloud storage first: redirect to a short-lived presigned URL ----
require_once __DIR__ . '/../../integrations/file_storage.php';
// Strip header control characters from user-provided display filenames.
$downloadName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '_', basename($row['file_name'] ?: 'file'));
if (!in_array(strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg'], true)) $disp = 'attachment';
try {
$s3key = storage_key_from_file_url((string)$row['file_url']);
if ($s3key && s3_configured() && s3_head_object($s3key)) {
  $dlName = $downloadName;
  $url = s3_presign_get($s3key, 900, [
    'response-content-disposition' => $disp . '; filename="' . str_replace('"', '', $dlName) . '"',
  ]);
  if ($url) {
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
  }
}

} catch (Throwable $e) {
    error_log('[attachment cloud] type=' . $type . ' id=' . $id . ' code=' . $e->getCode() . '; trying local');
}
$path = storage_find_local((string)$row['file_url']);
if (!$path || !is_readable($path)) {
    error_log('[attachment missing] type=' . $type . ' id=' . $id . ' user=' . $user_id . '; no usable cloud or local copy');
    http_response_code(404); exit('This attachment is unavailable. Please ask the uploader to provide it again.');
}

// ---- Stream ----
$size = filesize($path);
if ($size === false) { http_response_code(404); exit('File not found'); }

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
  $f = finfo_open(FILEINFO_MIME_TYPE);
  if ($f) { $mime = finfo_file($f, $path) ?: $mime; finfo_close($f); }
}

while (ob_get_level()) { ob_end_clean(); }
set_time_limit(0);

$basename = $downloadName;
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"','',$basename) . '"; filename*=UTF-8\'\'' . rawurlencode($basename));
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox");
header('Cache-Control: private, max-age=0, must-revalidate');
header('Content-Transfer-Encoding: binary');

$fh = fopen($path, 'rb');
if (!$fh) { http_response_code(500); exit('Failed to open file'); }
$chunk = 8192;
while (!feof($fh)) { echo fread($fh, $chunk); flush(); }
fclose($fh);

} catch (Throwable $e) {
    error_log('[attachment download] type=' . $type . ' id=' . $id . ' code=' . $e->getCode());
    http_response_code(500); exit('Could not load this attachment. Please try again.');
}
