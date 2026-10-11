<?php
session_start(); 
require_once '../auth_tutor_only.php'; 
require_once __DIR__ . '/../../config/db_connect.php';
$tutor_id = $_SESSION['user_id'];
$lesson_id = (int)($_POST['lesson_id'] ?? 0);
$is_last = (int)($_POST['is_last'] ?? 0);
$duration = (int)($_POST['duration_seconds'] ?? 0);

// ownership
$stmt = $pdo->prepare("SELECT 1 FROM lessons WHERE id=? AND tutor_id=?");
$stmt->execute([$lesson_id, $tutor_id]);
if (!$stmt->fetch()) { http_response_code(403); exit; }

// prepare file path
$dir = __DIR__ . '/../../uploads/lesson_recordings';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$fname = "lesson_{$lesson_id}.webm";
$path = "$dir/$fname";

// starting a fresh recording: discard any previous file for this lesson
// (webm chunks appended to an old file produce an unplayable video)
if (!empty($_POST['reset']) && file_exists($path)) {
  unlink($path);
}

// append chunk if present
if (!empty($_FILES['chunk']['tmp_name'])) {
  $in = fopen($_FILES['chunk']['tmp_name'], 'rb');
  $out = fopen($path, file_exists($path)? 'ab' : 'wb');
  stream_copy_to_stream($in, $out);
  fclose($in); fclose($out);
}

// finalize DB row
if ($is_last) {
  $url = "../uploads/lesson_recordings/$fname";
  $size = file_exists($path) ? filesize($path) : null;
  $objectKey = null;

  // Ship to cloud storage (R2/B2) when configured; server keeps no copy.
  require_once __DIR__ . '/../../integrations/s3_client.php';
  if ($size && s3_configured()) {
    set_time_limit(300);
    $key = "recordings/$fname";
    if (s3_put_object($key, $path, 'video/webm')) {
      $objectKey = $key;
      $url = "../api/recording_url.php?lesson_id=$lesson_id";
      @unlink($path);
    } else {
      error_log("upload_recording: cloud upload failed for lesson $lesson_id, keeping local file");
    }
  }

  // Upsert: one recording per lesson (UNIQUE lesson_id)
  $pdo->prepare("
    INSERT INTO lesson_recordings (lesson_id, file_url, file_size_bytes, duration_seconds, provider, status, external_recording_id)
    VALUES (?,?,?,?,'LiveKit','Ready',?)
    ON DUPLICATE KEY UPDATE file_url=VALUES(file_url), file_size_bytes=VALUES(file_size_bytes), duration_seconds=VALUES(duration_seconds), provider=VALUES(provider), status=VALUES(status), external_recording_id=VALUES(external_recording_id), recorded_at=CURRENT_TIMESTAMP
  ")->execute([$lesson_id, $url, $size, $duration, $objectKey]);
}
echo 'OK';
