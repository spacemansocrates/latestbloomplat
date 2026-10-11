<?php
// Lesson-scoped published PDF books. No client-supplied path ever reaches storage.
// GET: list + tutor resume; &open=ID: direct URL; &stream=ID: local ranged bytes.
// POST: {material_id, page, zoom}, tutor only. Closing retains the saved position.
declare(strict_types=1);

session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lb_json(array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function lb_id($value): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) return 0;
    return (int)$value <= 2147483647 ? (int)$value : 0;
}
function lb_book(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT id, title, file_url FROM learning_materials
        WHERE id = ? AND is_published = 1 AND material_type = 'Book'
        AND LOWER(file_url) LIKE '%.pdf' LIMIT 1");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$lessonId = lb_id($_GET['lesson_id'] ?? null);
if (!$userId || !$lessonId || !in_array($role, ['Tutor', 'Student'], true)) {
    lb_json(['error' => 'Forbidden'], 403);
}
session_write_close(); // Lazy range requests must not serialize behind the session lock.

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/classroom_workspace.php';
require_once __DIR__ . '/../integrations/file_storage.php';

try {
    $lesson = cw_authorize_lesson($pdo, $lessonId, $userId, $role);
    if (!$lesson) lb_json(['error' => 'Forbidden'], 403);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        lb_json(['error' => 'Method not allowed'], 405);
    }

    if ($method === 'POST') {
        if ($lesson['viewer_role'] !== 'tutor') lb_json(['error' => 'Forbidden'], 403);
        // Forms cannot send application/json; no cross-origin CORS permission is granted.
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json' || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            lb_json(['error' => 'JSON request required'], 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) lb_json(['error' => 'Invalid book state'], 400);
        $body = json_decode($raw, true);
        if (!is_array($body) || !is_int($body['material_id'] ?? null) || !lb_id($body['material_id'])
            || !is_int($body['page'] ?? null) || $body['page'] < 1 || $body['page'] > 1000000
            || !is_numeric($body['zoom'] ?? null) || !is_finite((float)$body['zoom'])
            || (float)$body['zoom'] < 0.25 || (float)$body['zoom'] > 3) {
            lb_json(['error' => 'Invalid book state'], 400);
        }
        if (!lb_book($pdo, $body['material_id'])) lb_json(['error' => 'Book not found'], 404);
        $workspace = cw_get_or_create_workspace($pdo, $lesson);
        $pdo->prepare('INSERT INTO classroom_book_state (workspace_id, material_id, page, zoom, updated_by)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE material_id=VALUES(material_id),
            page=VALUES(page), zoom=VALUES(zoom), updated_by=VALUES(updated_by)')
            ->execute([(int)$workspace['id'], $body['material_id'], $body['page'], round((float)$body['zoom'], 2), $userId]);
        lb_json(['saved' => true]);
    }

    if (isset($_GET['open']) || isset($_GET['stream'])) {
        $stream = isset($_GET['stream']);
        $id = lb_id($_GET[$stream ? 'stream' : 'open']);
        $book = $id ? lb_book($pdo, $id) : null;
        if (!$book) lb_json(['error' => 'Book not found'], 404);
        $key = storage_key_from_file_url($book['file_url']);
        if (!$key || !preg_match('~^uploads/materials/[^/]+\.pdf$~iD', $key)) {
            lb_json(['error' => "This book's file is unavailable"], 404);
        }
        if (!$stream && s3_configured() && s3_head_object($key)) {
            // At least two hours, or the planned remaining lesson + 30 minutes,
            // capped at three hours. The viewer also refreshes expired URLs.
            $q = $pdo->prepare('SELECT scheduled_end_time FROM lessons WHERE id = ?');
            $q->execute([$lessonId]);
            $end = strtotime((string)$q->fetchColumn()) ?: time();
            $ttl = min(10800, max(7200, $end - time() + 1800));
            $url = s3_presign_get($key, $ttl);
            if ($url) lb_json(['url' => $url, 'expires_at' => time() + $ttl, 'id' => $id, 'title' => $book['title']]);
        }
        $local = storage_find_local($book['file_url']);
        if (!$local) lb_json(['error' => "This book's file is unavailable"], 404);
        if ($stream) storage_stream_local($local, basename($local), 'inline', true);
        // Relative to the endpoint itself, so subdirectory installs work too.
        lb_json(['url' => 'lesson_books.php?lesson_id=' . $lessonId . '&stream=' . $id,
            'expires_at' => null, 'id' => $id, 'title' => $book['title']]);
    }

    $q = $pdo->prepare('SELECT subject_id FROM lessons WHERE id = ?');
    $q->execute([$lessonId]);
    $subjectId = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT m.id, m.title, m.description, m.grade_level, s.name AS subject_name,
        CASE WHEN m.subject_id = ? AND m.subject_id IS NOT NULL THEN 1 ELSE 0 END AS matches_subject
        FROM learning_materials m LEFT JOIN subjects s ON s.id = m.subject_id
        WHERE m.is_published = 1 AND m.material_type = 'Book' AND LOWER(m.file_url) LIKE '%.pdf'
        ORDER BY matches_subject DESC, m.title ASC, m.id ASC");
    $q->execute([$subjectId]);
    $books = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($books as &$book) {
        $book['id'] = (int)$book['id'];
        $book['matches_subject'] = (bool)$book['matches_subject'];
    }
    unset($book);
    $resume = null;
    if ($lesson['viewer_role'] === 'tutor') {
        $q = $pdo->prepare('SELECT bs.material_id, bs.page, bs.zoom FROM classroom_book_state bs
            JOIN classroom_workspaces w ON w.id = bs.workspace_id WHERE w.workspace_key = ?');
        $q->execute([cw_workspace_key($lesson)]);
        $saved = $q->fetch(PDO::FETCH_ASSOC);
        if ($saved) foreach ($books as $book) {
            if ($book['id'] === (int)$saved['material_id']) {
                $resume = ['materialId' => $book['id'], 'title' => $book['title'],
                    'page' => (int)$saved['page'], 'zoom' => (float)$saved['zoom']];
                break;
            }
        }
    }
    lb_json(['books' => $books, 'resume' => $resume]);
} catch (Throwable $e) {
    // No URLs, credentials, SQL parameters or customer-entered strings in logs.
    error_log('lesson_books.php request failed');
    lb_json(['error' => 'Could not load books. Please retry.'], 500);
}
