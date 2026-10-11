<?php
declare(strict_types=1);

// integrations/lessonspace_webhook.php
// Handles Lessonspace recording + session webhooks and logs them reliably.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

require_once __DIR__ . '/../student/db_connect.php';

// ------------------------------
// 1. Verify Secret (optional)
// ------------------------------
$expectedSecret = getenv('LESSONSPACE_WEBHOOK_SECRET') ?: '';
$providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

if ($expectedSecret !== '' && !hash_equals($expectedSecret, (string)$providedSecret)) {
    http_response_code(401);
    exit('Invalid Secret');
}

// ------------------------------
// 2. Read & Parse Payload
// ------------------------------
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload) {
    file_put_contents(__DIR__.'/webhook_error.log', date('c')." - Invalid JSON\n$raw\n\n", FILE_APPEND);
    http_response_code(400);
    exit('Bad JSON');
}

// Helper to get nested data safely
function g($arr, $key) {
    return $arr['data'][$key] ?? $arr[$key] ?? null;
}

// Extract common fields
$spaceId    = g($payload, 'space')['id'] ?? g($payload, 'space_id') ?? null;
$sessionId  = g($payload, 'session')['id'] ?? g($payload, 'session_id') ?? null;
$eventType  = $payload['type'] ?? $payload['event'] ?? 'unknown';

// Try to get recording info
$recording  = g($payload, 'recording') ?? [];
$fileUrl    = g($recording, 'url') ?? g($payload, 'recording_url');
$playback   = g($recording, 'playback_url') ?? g($payload, 'playback_url');
$duration   = (int)(g($recording, 'duration_seconds') ?? 0);
$recordId   = g($recording, 'id') ?? uniqid('rec_', true);
$createdAt  = g($recording, 'created_at') ? date('Y-m-d H:i:s', strtotime(g($recording, 'created_at'))) : date('Y-m-d H:i:s');

// ------------------------------
// 3. Resolve Lesson ID
// ------------------------------
$lesson_id = null;

// A. Metadata (best)
if (!empty($payload['data']['metadata']['lesson_id'])) {
    $lesson_id = (int)$payload['data']['metadata']['lesson_id'];
} elseif (!empty($payload['metadata']['lesson_id'])) {
    $lesson_id = (int)$payload['metadata']['lesson_id'];
}

// B. Database lookup (by external_room_id)
if (!$lesson_id && $spaceId) {
    $stmt = $pdo->prepare("SELECT id FROM lessons WHERE external_room_id = ? LIMIT 1");
    $stmt->execute([$spaceId]);
    $lesson_id = $stmt->fetchColumn();
}

// C. Regex fallback
if (!$lesson_id && $spaceId && preg_match('/^lesson-(\d+)$/', $spaceId, $m)) {
    $lesson_id = (int)$m[1];
}

// ------------------------------
// 4. If lesson not found
// ------------------------------
if (!$lesson_id) {
    file_put_contents(__DIR__.'/webhook_error.log', date('c')." - Could not map SpaceID: {$spaceId}\nPayload: {$raw}\n\n", FILE_APPEND);
    echo json_encode(['ok' => false, 'error' => 'unmapped_lesson']);
    exit;
}

// ------------------------------
// 5. Save to Database
// ------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO lesson_recordings
            (lesson_id, lessonspace_session_id, external_recording_id, file_url, playback_url,
             duration_seconds, recorded_at, provider, status)
        VALUES
            (:lesson_id, :session, :record_id, :file, :playback, :dur, :recDate, 'Lessonspace', 'Ready')
        ON DUPLICATE KEY UPDATE
            file_url = VALUES(file_url),
            playback_url = VALUES(playback_url),
            lessonspace_session_id = VALUES(lessonspace_session_id),
            duration_seconds = VALUES(duration_seconds),
            recorded_at = VALUES(recorded_at),
            status = 'Ready'
    ");
    $stmt->execute([
        'lesson_id' => $lesson_id,
        'session'   => $sessionId,
        'record_id' => $recordId,
        'file'      => $fileUrl ?: $playback,
        'playback'  => $playback ?: $fileUrl,
        'dur'       => $duration,
        'recDate'   => $createdAt,
    ]);

    echo json_encode(['ok' => true, 'lesson_id' => $lesson_id]);
} catch (Throwable $e) {
    file_put_contents(__DIR__.'/webhook_error.log', date('c')." - DB Error: {$e->getMessage()}\nPayload: {$raw}\n\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
