<?php
/**
 * Initials avatar, drawn here instead of fetched from placehold.co.
 *
 * Wherever a person had no profile photo, the portal asked a third-party host
 * for a picture with that person's initials in the URL, on every page load.
 * That sent student and tutor initials off-site and left holes in the page
 * whenever the host was slow or blocked. The query string keeps the same shape
 * placehold.co used, so the call sites only had to change the host.
 *
 *   assets/avatar.php?s=48&bg=312e81&fg=ffffff&text=PM
 */

const AV_DEFAULT_BG = '312e81';
const AV_DEFAULT_FG = 'ffffff';

/** A six-digit hex colour, or the fallback if the input is anything else. */
function av_hex($value, string $fallback): string
{
    $v = ltrim((string) $value, '#');
    if (preg_match('/^[0-9a-fA-F]{3}$/', $v)) {
        $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
    }
    return preg_match('/^[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
}

// placehold.co took the colours as bg/fg path segments, and a couple of callers
// still hold the pair as one string, so accept either form.
$bgRaw = (string) ($_GET['bg'] ?? '');
$fgRaw = (string) ($_GET['fg'] ?? '');
if (strpos($bgRaw, '/') !== false) {
    [$bgRaw, $pairFg] = explode('/', $bgRaw, 2);
    if ($fgRaw === '') {
        $fgRaw = $pairFg;
    }
}
$bg = av_hex($bgRaw, AV_DEFAULT_BG);
$fg = av_hex($fgRaw, AV_DEFAULT_FG);

$size = (int) ($_GET['s'] ?? 80);
if ($size < 16 || $size > 512) {
    $size = 80;
}

// Letters and digits only. This string is written straight into the SVG, so it
// is filtered rather than escaped: nothing that needs escaping can survive.
$text = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['text'] ?? '')));
$text = $text === '' ? '?' : substr($text, 0, 3);

// Three characters need to sit smaller to stay inside the square.
$fontSize = strlen($text) >= 3 ? 32 : 40;

// dy rather than dominant-baseline, which older Safari does not centre correctly.
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
     . ' viewBox="0 0 100 100" role="img" aria-label="' . $text . '">'
     . '<rect width="100" height="100" fill="#' . $bg . '"/>'
     . '<text x="50" y="50" dy="0.35em" text-anchor="middle" fill="#' . $fg . '"'
     . ' font-family="system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif"'
     . ' font-size="' . $fontSize . '" font-weight="600" letter-spacing="1">' . $text . '</text>'
     . '</svg>';

// The query string fully determines the picture, so it can be cached hard.
$etag = '"' . md5($svg) . '"';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . strlen($svg));
echo $svg;
