<?php
// Absolute filesystem path to /uploads (unchanged)
if (!defined('UPLOADS_DIR')) {
    // If app_bootstrap.php is in the project root alongside /uploads:
    define('UPLOADS_DIR', realpath(__DIR__ . '/uploads'));
}

/**
 * Compute the web path for a directory under the DOC ROOT.
 * Example: if DOC ROOT = C:\xampp\htdocs and UPLOADS_DIR = C:\xampp\htdocs\tutsys2\uploads
 * returns '/tutsys2/uploads'
 */
function web_path_for_dir($absDir) {
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
    if (!$doc) return '/uploads'; // fallback
    $doc = rtrim(str_replace('\\','/',$doc), '/');
    $abs = rtrim(str_replace('\\','/', realpath($absDir)), '/');
    if ($abs && strpos($abs, $doc) === 0) {
        $rel = substr($abs, strlen($doc));         // -> '/tutsys2/uploads'
        return $rel !== '' ? $rel : '/uploads';
    }
    // If not under doc root for some reason, fallback
    return '/uploads';
}

if (!defined('UPLOADS_BASE_URL')) {
    define('UPLOADS_BASE_URL', web_path_for_dir(UPLOADS_DIR));
}

/**
 * Turn a DB-stored relative path like "banners/banner2.jpg" into a public URL.
 * Ensures no traversal and always prefixes with the correct subfolder path.
 */
function uploads_url(?string $relative) : string {
    if (!$relative) return '';
    $relative = ltrim(str_replace('\\','/',$relative), '/');
    if ($relative === '' || strpos($relative, '..') !== false) return '';
    // Optional existence check:
    // if (!is_file(UPLOADS_DIR . '/' . $relative)) return '';
    return rtrim(UPLOADS_BASE_URL, '/') . '/' . $relative;
}
