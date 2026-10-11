<?php
// api/recording_url.php - authenticated gateway to lesson recordings.
// Looks up where the recording lives (cloud bucket or local disk) and redirects:
//   cloud  -> time-limited presigned URL (link dies after 1h; bucket stays private)
//   local  -> the legacy uploads path
// Access: the lesson's student or tutor, plus Admin/QC roles.

session_start();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role    = (string)($_SESSION['user_role'] ?? '');
if ($user_id <= 0 || $role === '') {
    http_response_code(403);
    exit('Not signed in');
}

$lesson_id = (isset($_GET['lesson_id']) && ctype_digit((string)$_GET['lesson_id'])) ? (int)$_GET['lesson_id'] : 0;
if ($lesson_id <= 0) {
    http_response_code(400);
    exit('Invalid lesson_id');
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../integrations/s3_client.php';

try {
    $stmt = $pdo->prepare("SELECT lr.external_recording_id, lr.file_url, l.student_id, l.tutor_id
                           FROM lesson_recordings lr
                           JOIN lessons l ON l.id = lr.lesson_id
                           WHERE lr.lesson_id = ? LIMIT 1");
    $stmt->execute([$lesson_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('Recording not found');
    }

    $isParty = ((int)$row['student_id'] === $user_id) || ((int)$row['tutor_id'] === $user_id);
    $isAdmin = in_array($role, ['Administrator', 'Admin', 'SuperAdmin'], true);

    // Quality Control reviewers may only open recordings for lessons assigned to them.
    $isAssignedQc = false;
    if ($role === 'QualityControl') {
        $qc = $pdo->prepare("SELECT 1 FROM quality_control_assignments WHERE lesson_id = ? AND qc_user_id = ? LIMIT 1");
        $qc->execute([$lesson_id, $user_id]);
        $isAssignedQc = (bool)$qc->fetchColumn();
    }

    if (!$isParty && !$isAdmin && !$isAssignedQc) {
        http_response_code(403);
        exit('Not allowed');
    }

    header('Cache-Control: no-store');

    $key = (string)($row['external_recording_id'] ?? '');
    if ($key !== '' && strpos($key, 'recordings/') === 0 && s3_configured()) {
        $url = s3_presign_get($key, 3600);
        if ($url) { header('Location: ' . $url, true, 302); exit(); }
    }

    // legacy/local fallback
    $local = (string)($row['file_url'] ?? '');
    if ($local !== '' && strpos($local, 'uploads/') !== false && strpos($local, '..') !== strlen($local)) {
        header('Location: ../uploads/lesson_recordings/' . basename($local), true, 302);
        exit();
    }

    // legacy external URL, from the providers used before the built-in room.
    // These are historical and
    // may be dead, but redirect to the source rather than a blank 404.
    if ($local !== '' && preg_match('~^https?://~i', $local)) {
        header('Location: ' . $local, true, 302);
        exit();
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('This recording is not available. It was recorded on the old system and the file was not migrated.');

} catch (Throwable $e) {
    error_log('recording_url.php error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unexpected error');
}
