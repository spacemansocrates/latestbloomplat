<?php
// api/whiteboard.php - load/save the collaborative whiteboard for a lesson.
//
// The board no longer belongs to the lesson. It belongs to the tutor-student
// relationship (see utils/classroom_workspace.php), so lesson B for the same pair
// opens the board they were working on in lesson A. The lesson id is still how a
// request identifies itself, because that is what proves the caller is party to
// the relationship -- but it is no longer the storage key.
//
//   GET  ?lesson_id=N [&page_id=M]
//        -> { workspace_id, page, pages, scene, files }
//   POST ?lesson_id=N            body {page_id, revision, scene, files}
//        -> { saved: true, revision }            200
//        -> { reason: 'conflict', revision, scene, files }   409
//   POST ?lesson_id=N&action=new_page      body {title?}    -> { page, pages }
//   POST ?lesson_id=N&action=set_active    body {page_id}   -> { ok: true }
//
// Accessible only to the student or tutor the lesson belongs to. A page_id is always
// re-checked against the resolved workspace; being able to name a page is never
// treated as permission to read or write it.
//
// CSRF: these are JSON requests (Content-Type: application/json) carrying no
// ambient-credential-friendly shape -- an HTML form cannot produce that content type,
// so a cross-site POST is blocked by preflight rather than by a token. The unload
// beacon uses the same JSON content type for the same reason.

header('Content-Type: application/json');
session_start();

// Biggest accepted save. Generous because a board holding imported PDF pages still
// carries them as base64 in `files`; the real fix is moving those into managed
// storage and sending references, which is tracked separately. Until then this is a
// ceiling that produces a clear 413 instead of a truncated write or an out-of-memory.
const WB_MAX_BYTES = 32 * 1024 * 1024;

function sendJsonResponse($success, $message, $statusCode = 200, $data = []) {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role    = (string)($_SESSION['user_role'] ?? '');
if ($user_id <= 0 || !in_array($role, ['Student', 'Tutor'], true)) {
    sendJsonResponse(false, 'Unauthorized', 403);
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/classroom_workspace.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    sendJsonResponse(false, 'Database connection failed', 500);
}

$lesson_id = 0;
if (isset($_GET['lesson_id']) && ctype_digit((string)$_GET['lesson_id'])) {
    $lesson_id = (int)$_GET['lesson_id'];
}
if ($lesson_id <= 0) {
    sendJsonResponse(false, 'Invalid lesson_id', 400);
}

try {
    $lesson = cw_authorize_lesson($pdo, $lesson_id, $user_id, $role);
    if (!$lesson) {
        // Same answer for "no such lesson" and "not yours", so this cannot be used
        // to discover which lesson ids exist.
        sendJsonResponse(false, 'Lesson not found', 404);
    }

    $workspace   = cw_get_or_create_workspace($pdo, $lesson);
    $workspaceId = (int)$workspace['id'];

    // ---------------------------------------------------------------- writes
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            sendJsonResponse(false, 'Could not read the request body', 400);
        }
        if (strlen($raw) > WB_MAX_BYTES) {
            sendJsonResponse(false, 'This board is too large to save. Remove some imported pages or images.', 413);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            sendJsonResponse(false, 'Invalid JSON body', 400);
        }

        $action = (string)($_GET['action'] ?? 'save');

        if ($action === 'new_page') {
            $page = cw_create_page($pdo, $workspaceId, isset($body['title']) ? (string)$body['title'] : null);
            sendJsonResponse(true, 'Page created', 200, [
                'page'  => ['id' => (int)$page['id'], 'title' => $page['title'],
                            'revision' => (int)$page['revision'], 'position' => (int)$page['position']],
                'pages' => cw_list_pages($pdo, $workspaceId),
            ]);
        }

        if ($action === 'set_active') {
            $pageId = (int)($body['page_id'] ?? 0);
            if (!cw_page_in_workspace($pdo, $pageId, $workspaceId)) {
                sendJsonResponse(false, 'Page not found', 404);
            }
            $pdo->prepare("UPDATE classroom_workspaces SET active_page_id = ? WHERE id = ?")
                ->execute([$pageId, $workspaceId]);
            sendJsonResponse(true, 'Active page set', 200, ['ok' => true]);
        }

        // --- normal save ---
        //
        // page_id and revision are mandatory. The previous endpoint accepted a bare
        // {scene, files} and wrote it unconditionally, which is exactly how a stale
        // tab -- or a client whose initial load had failed and defaulted to an empty
        // scene -- could overwrite good work. A request that cannot say what it was
        // based on is refused and told to reload rather than guessed at.
        if (!isset($body['page_id']) || !isset($body['revision'])) {
            sendJsonResponse(false, 'This board session is out of date. Reload the lesson page to continue.', 409,
                ['reason' => 'stale_client']);
        }

        $pageId   = (int)$body['page_id'];
        $revision = (int)$body['revision'];

        $page = cw_page_in_workspace($pdo, $pageId, $workspaceId);
        if (!$page) {
            sendJsonResponse(false, 'Page not found', 404);
        }

        $scene = array_key_exists('scene', $body) && is_array($body['scene'])
            ? json_encode($body['scene']) : null;
        $files = array_key_exists('files', $body) && is_array($body['files'])
            ? json_encode($body['files']) : null;

        if ($scene === null) {
            sendJsonResponse(false, 'A save must include a scene', 400);
        }

        $result = cw_save_page($pdo, $pageId, $workspaceId, $revision, $scene, $files, $user_id);

        if ($result['ok']) {
            $pdo->prepare("UPDATE classroom_workspaces SET active_page_id = ? WHERE id = ?")
                ->execute([$pageId, $workspaceId]);
            sendJsonResponse(true, 'Saved', 200, ['saved' => true, 'revision' => $result['revision']]);
        }

        if (($result['reason'] ?? '') === 'conflict') {
            // Hand back the newer state so the client can merge rather than retry blind.
            sendJsonResponse(false, 'The board changed elsewhere', 409, [
                'reason'   => 'conflict',
                'revision' => $result['revision'],
                'scene'    => $result['scene'],
                'files'    => $result['files'],
            ]);
        }
        sendJsonResponse(false, 'Page not found', 404);
    }

    // ---------------------------------------------------------------- reads
    $requestedPageId = (isset($_GET['page_id']) && ctype_digit((string)$_GET['page_id']))
        ? (int)$_GET['page_id'] : 0;

    if ($requestedPageId > 0) {
        $page = cw_page_in_workspace($pdo, $requestedPageId, $workspaceId);
        if (!$page) {
            sendJsonResponse(false, 'Page not found', 404);
        }
    } else {
        $page = cw_resolve_active_page($pdo, $workspace);
    }

    sendJsonResponse(true, 'OK', 200, [
        'workspace_id' => $workspaceId,
        'page'         => [
            'id'       => (int)$page['id'],
            'title'    => $page['title'],
            'revision' => (int)$page['revision'],
            'position' => (int)$page['position'],
        ],
        'pages'      => cw_list_pages($pdo, $workspaceId),
        'scene'      => $page['scene'] !== null ? json_decode($page['scene'], true) : null,
        'files'      => $page['files'] !== null ? json_decode($page['files'], true) : null,
        'updated_at' => $page['updated_at'] ?? null,
    ]);

} catch (Throwable $e) {
    error_log('whiteboard.php error: ' . $e->getMessage());
    sendJsonResponse(false, 'An unexpected error occurred', 500);
}
