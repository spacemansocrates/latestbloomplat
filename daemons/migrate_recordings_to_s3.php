<?php
// daemons/migrate_recordings_to_s3.php
// Moves lesson recordings from the local uploads folder to the configured
// S3-compatible bucket and points their DB rows at the recording gateway.
// Safe to run repeatedly (skips already-migrated rows); local file is deleted
// only after a successful upload.
//
// Run:  php daemons/migrate_recordings_to_s3.php
// Or via cron/hPanel scheduled task to sweep up anything the inline upload missed.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../integrations/s3_client.php';

if (!s3_configured()) {
    exit("S3 storage is not configured in .env (S3_ENDPOINT / S3_BUCKET / keys) - nothing to do.\n");
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit("Database not reachable.\n");
}

$dir = realpath(__DIR__ . '/../uploads/lesson_recordings');
if (!$dir) exit("No local recordings folder - nothing to migrate.\n");

$rows = $pdo->query("SELECT lesson_id, file_url, external_recording_id FROM lesson_recordings
                     WHERE (external_recording_id IS NULL OR external_recording_id = '')")->fetchAll(PDO::FETCH_ASSOC);

$done = 0; $skipped = 0; $failed = 0;
foreach ($rows as $row) {
    $lesson_id = (int)$row['lesson_id'];
    // trust the row's file_url: legacy imports have filenames that don't match lesson_id
    $fname = basename((string)$row['file_url']);
    if ($fname === '' || !preg_match('/^[A-Za-z0-9._-]+\.webm$/', $fname)) { $skipped++; continue; }
    $path = "$dir/$fname";
    if (!is_file($path)) { $skipped++; continue; }

    $key = "recordings/$fname";
    echo "lesson $lesson_id: uploading " . round(filesize($path) / 1048576, 1) . " MB ... ";
    if (s3_put_object($key, $path, 'video/webm')) {
        $pdo->prepare("UPDATE lesson_recordings
                       SET external_recording_id = ?, file_url = ?
                       WHERE lesson_id = ?")
            ->execute([$key, "../api/recording_url.php?lesson_id=$lesson_id", $lesson_id]);
        unlink($path);
        echo "OK (local copy removed)\n";
        $done++;
    } else {
        echo "FAILED (kept local, see error log)\n";
        $failed++;
    }
}
echo "Migrated: $done, no local file: $skipped, failed: $failed\n";
