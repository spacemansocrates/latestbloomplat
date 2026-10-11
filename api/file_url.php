<?php
// api/file_url.php - authenticated gateway for the shared materials library.
// GET ?p=materials/<file>  ->  302 to a 1-hour presigned bucket URL, or streams the
// local copy for files that have not been migrated yet.
//
// SCOPE, and why it is this narrow. This gateway used to accept
// 'homework/<file>' and 'submissions/<file>' as well, for any signed-in session
// of any role and with no ownership check at all. That made every homework
// attachment and every student's submission readable by any other signed-in user
// who knew or guessed a filename, bypassing student/api/download.php entirely --
// that downloader is the one which resolves the attachment row and checks the
// caller is its student, its tutor, or an administrator.
//
// No caller ever needed those prefixes: storage_gateway_url() is only ever handed
// learning_materials.file_url (tutor/materials.php), and admin/materials.php
// hardcodes 'materials/'. Homework and submissions already go through
// student/api/download.php. So the prefixes are removed rather than patched.
//
// What remains is checked rather than trusted: the requested filename must belong
// to a real learning_materials row, and students may only fetch published ones.
// A path matching no material is a 404, not a bucket lookup.
//
// Note on quoting: paths are validated without backslash escapes on purpose.
// chr(92) is a literal backslash; writing it that way keeps the check readable
// and avoids the escaping mistakes that make a pattern silently fail to compile.

session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    http_response_code(403);
    exit('Not signed in');
}
$role = (string)$_SESSION['user_role'];

require_once __DIR__ . '/../integrations/file_storage.php';
require_once __DIR__ . '/../config/db_connect.php';

$BACKSLASH = chr(92);

$p = (string)($_GET['p'] ?? '');
// Materials only: one path segment, no traversal, no nested paths, no backslashes.
if (!preg_match('#^materials/[^/]+$#', $p)
    || strpos($p, '..') !== false
    || strpos($p, $BACKSLASH) !== false) {
    http_response_code(400);
    exit('Bad path');
}
$fileUrl = '/uploads/' . $p;
$name    = substr($p, strlen('materials/'));

// The file must be a material we actually serve. Stored file_url values vary in
// shape ('/uploads/materials/x.pdf', '../uploads/materials/x.pdf'), so narrow with
// a LIKE on the trailing name and then confirm the basename exactly in PHP -- doing
// the final comparison here rather than in SQL keeps path separators out of the
// query and makes the match exact rather than a suffix.
$material = null;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare(
            "SELECT id, is_published, file_url FROM learning_materials
             WHERE file_url IS NOT NULL AND file_url <> '' AND file_url LIKE ?
             LIMIT 25"
        );
        $st->execute(['%' . $name]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored = str_replace($BACKSLASH, '/', (string)$row['file_url']);
            if (basename($stored) === $name) { $material = $row; break; }
        }
    }
} catch (Throwable $e) {
    error_log('file_url.php material lookup failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not check that file');
}

if (!$material) {
    http_response_code(404);
    exit('File not found');
}
// Unpublished material is staff-only. Students get the same 404 as a missing file,
// so the gateway never confirms that an unpublished title exists.
if (!(int)$material['is_published'] && !in_array($role, ['Tutor', 'Administrator'], true)) {
    http_response_code(404);
    exit('File not found');
}

header('Cache-Control: no-store');

// bucket first
if (s3_configured()) {
    $key = storage_key_from_file_url($fileUrl);
    // only redirect when the object actually exists; otherwise fall through to disk
    if ($key && s3_head_object($key)) {
        $url = s3_presign_get($key, 3600);
        if ($url) {
            header('Location: ' . $url, true, 302);
            exit;
        }
    }
}

// local fallback (not yet migrated)
$local = storage_find_local($fileUrl);
if ($local) {
    storage_stream_local($local, basename($local), 'inline');
}

http_response_code(404);
exit('File not found');
