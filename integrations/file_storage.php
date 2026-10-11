<?php
// integrations/file_storage.php
// Central storage for user-uploaded content (materials, homework, submissions).
// Files are pushed to the S3-compatible bucket (Cloudflare R2) under uploads/<type>/<file>
// and served through api/file_url.php (or student/api/download.php for homework),
// which redirect to short-lived presigned URLs. Falls back to local disk when the
// bucket is not configured or an upload fails, so nothing ever breaks outright.

declare(strict_types=1);

require_once __DIR__ . '/s3_client.php';

/** Upload types that live in the bucket. Everything else (avatars, banners) stays local. */
function storage_prefixes(): array {
    return ['materials', 'homework', 'submissions'];
}

/**
 * Normalize any stored file_url ('/uploads/materials/x.pdf', '../uploads/homework/y.pdf',
 * 'tutsys/uploads/submissions/z.zip') into a bucket key 'uploads/<type>/<file>'.
 * Returns null when the path is not one of the managed types.
 */
function storage_key_from_file_url(?string $fileUrl): ?string {
    if (!$fileUrl || preg_match('~^https?://~i', $fileUrl)) return null;
    $s = str_replace('\\', '/', trim($fileUrl));
    $pos = stripos($s, 'uploads/');
    if ($pos === false) return null;
    $rel = substr($s, $pos + strlen('uploads/'));
    if (preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f:]~', $rel)) return null;
    $parts = explode('/', $rel, 2);
    if (count($parts) !== 2 || !in_array($parts[0], storage_prefixes(), true) || $parts[1] === '') return null;
    return 'uploads/' . $rel;
}

function storage_mime(string $path): string {
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) { $mime = finfo_file($f, $path) ?: $mime; finfo_close($f); }
    }
    return $mime;
}

/**
 * Push a just-uploaded local file to the bucket and remove the local copy.
 * Best-effort: returns false (file stays local) when the bucket is not
 * configured or the transfer fails — serving falls back to disk.
 */
function storage_offload(string $absPath, string $fileUrl): bool {
    $key = storage_key_from_file_url($fileUrl);
    if (!$key || !is_file($absPath) || !s3_configured()) return false;
    if (!s3_put_object($key, $absPath, storage_mime($absPath))) {
        error_log("storage_offload failed for $key (kept local)");
        return false;
    }
    @unlink($absPath);
    return true;
}

/** Delete a managed file from the bucket (used when a material is replaced/removed). */
function storage_delete(string $fileUrl): void {
    $key = storage_key_from_file_url($fileUrl);
    if ($key && s3_configured()) s3_delete_object($key);
}

/**
 * Gateway URL for a managed file, for hrefs on pages one level below the app
 * root (admin/, tutor/, student/, qc/).
 */
function storage_gateway_url(?string $fileUrl): ?string {
    $key = storage_key_from_file_url($fileUrl);
    if (!$key) return null;
    return '../api/file_url.php?p=' . rawurlencode(substr($key, strlen('uploads/')));
}

/**
 * Find a managed file on local disk. Historic uploads are spread across three
 * roots (uploads/, tutor/uploads/, student/uploads/) — search all of them.
 */
function storage_find_local(?string $fileUrl): ?string {
    $key = storage_key_from_file_url($fileUrl);
    if (!$key) return null;
    $rel = substr($key, strlen('uploads/'));
    $roots = [
        __DIR__ . '/../uploads',
        __DIR__ . '/../tutor/uploads',
        __DIR__ . '/../student/uploads',
    ];
    foreach ($roots as $root) {
        $p = $root . '/' . $rel;
        if (is_file($p)) {
            $real = realpath($p);
            $rootReal = realpath($root);
            if ($real && $rootReal && str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $rootReal), '/') . '/')) return $real;
        }
    }
    return null;
}

/** Stream a local file to the browser (transitional fallback before migration). */
function storage_stream_local(string $path, string $downloadName, string $disposition = 'inline', bool $allowRange = false): void {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);
    header('Content-Type: ' . storage_mime($path));
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    if ($allowRange) {
        header('Accept-Ranges: bytes');
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        // Single byte ranges suffice for pdf.js. Ignore Range on HEAD and when
        // If-Range cannot be validated, returning the complete representation.
        if ($range !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_SERVER['HTTP_IF_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) || ($m[1] === '' && $m[2] === '') || $size === 0) {
                header('Content-Range: bytes */' . $size); http_response_code(416); exit;
            }
            if ($m[1] === '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') $end = min($end, (int)$m[2]);
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size); http_response_code(416); exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
    }
    header('Content-Length: ' . max(0, $end - $start + 1));
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    $handle = fopen($path, 'rb');
    if ($handle) {
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
    }
    exit;
}
