<?php
/** Public avatar URLs are resolved against this installation, never DOCUMENT_ROOT uploads. */
function app_base_path(): string {
    $root = str_replace('\\', '/', realpath(dirname(__DIR__)));
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_starts_with($scriptFile, $root . '/')) {
        $suffix = substr($scriptFile, strlen($root));
        if (str_ends_with($script, $suffix)) return rtrim(substr($script, 0, -strlen($suffix)), '/');
    }
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $doc = $doc ? rtrim(str_replace('\\', '/', $doc), '/') : '';
    return $doc && str_starts_with($root, $doc . '/') ? substr($root, strlen($doc)) : '';
}

function initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice($parts ?: [], 0, 2)))) ?: '?';
}

function photo_fallback(string $name): string {
    $text = htmlspecialchars(initials($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
    return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="#4f46e5"/><text x="48" y="49" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="34" fill="white">' . $text . '</text></svg>');
}

function profile_photo_url(?string $stored): ?string {
    $stored = trim($stored ?? '');
    if ($stored === '' || preg_match('/[\x00-\x1f\x7f]/', $stored)) return null;
    $external = preg_match('~^https?://~i', $stored) === 1;
    if ($external && (!filter_var($stored, FILTER_VALIDATE_URL) || parse_url($stored, PHP_URL_USER) !== null)) return null;
    if (!$external && preg_match('~^[a-z][a-z0-9+.-]*:~i', $stored) && !preg_match('~^[a-z]:[\\\\/]~i', $stored)) return null;
    if (str_starts_with($stored, '//')) return null;
    $path = rawurldecode($external ? (parse_url($stored, PHP_URL_PATH) ?: '') : (explode('?', $stored, 2)[0]));
    $path = str_replace('\\', '/', $path);
    // Leading ../ is a historical page-relative prefix. Traversal inside uploads is rejected.
    $pos = strpos($path, 'uploads/');
    $relative = $pos !== false ? substr($path, $pos + 8) : preg_replace('~^(?:\./|\.\./|/)+~', '', $path);
    if (preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f:]~', $relative)) return null;
    $candidates = [$relative];
    if (!str_contains($relative, '/')) $candidates[] = 'avatars/' . $relative;
    // Only avatar images in the designated upload roots may become public photo URLs.
    foreach (['uploads', 'tutor/uploads', 'student/uploads'] as $uploadRoot) {
        $root = realpath(dirname(__DIR__) . '/' . $uploadRoot);
        if (!$root) continue;
        foreach ($candidates as $candidate) {
            if (!preg_match('~\.(?:jpe?g|png|gif|webp)$~i', $candidate)) continue;
            $real = realpath($root . '/' . $candidate);
            if (!$real || !is_file($real) || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/')) continue;
            if (!@getimagesize($real)) continue;
            $rel = substr(str_replace('\\', '/', $real), strlen(str_replace('\\', '/', $root)) + 1);
            return app_base_path() . '/' . $uploadRoot . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) . '?v=' . filemtime($real) . '-' . filesize($real);
        }
    }
    // Preserve legitimate third-party images, but missing local upload URLs fall back to initials.
    $sameHost = $external && strcasecmp((string)parse_url($stored, PHP_URL_HOST), explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) === 0;
    return $external && !$sameHost ? $stored : null;
}

function resolve_photo(?string $stored, string $name = ''): string {
    return profile_photo_url($stored) ?? photo_fallback($name);
}

function photo_img(?string $stored, string $name, string $class = 'w-10 h-10 rounded-full object-cover'): string {
    $escape = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<img src="' . $escape(resolve_photo($stored, $name)) . '" alt="' . $escape($name) . '" class="' . $escape($class) . '" data-fallback="' . $escape(photo_fallback($name)) . '" onerror="this.onerror=null;this.src=this.dataset.fallback">';
}
