<?php
/**
 * utils/assessment_ring.php
 *
 * The score dial at the top of the parent report email, modelled on the health-score
 * dashboard Paul chose: thick pastel arcs around the score, no tracks, each arc
 * carrying its skill's line icon. An arc's length is that skill's score within its
 * slot, so a weak skill shrinks to a single round badge and a strong one sweeps round.
 *
 * Email clients cannot draw this (no SVG or canvas in Gmail or Outlook), so it is a
 * PNG sent as an inline (cid:) image. GD has no anti-aliasing for thick shapes, so it
 * is drawn at twice the size and scaled down. Transparent background.
 *
 * Icons are the PNGs in assets/assessment-icons/, rendered from
 * tools/render_assessment_icons.html (the source of truth for their shapes).
 *
 * Font: DejaVu Sans (free licence, on the server) or Segoe UI / Arial on a Windows dev
 * machine. The site's Duplet Open is "free for personal use" only: never use it here.
 */

declare(strict_types=1);

/** The dashboard's four pastels, repeated in order for more skills. */
const ASM_RING_COLOURS = ['#F6DC73', '#9DAF73', '#F3BBDF', '#B9CDE9'];
const ASM_ICON_DIR = __DIR__ . '/../assets/assessment-icons';

/** Skill id -> icon name. A bank file can override with an "icon" field on the skill. */
const ASM_SKILL_ICONS = [
    'number' => 'hash', 'place_value' => 'hash', 'fdp' => 'pie', 'fdp_ratio' => 'pie', 'ratio' => 'scale',
    'algebra' => 'xsquared', 'geometry' => 'triangle', 'stats' => 'chart', 'data' => 'chart',
    'add_sub' => 'plusminus', 'mult_div' => 'times', 'arithmetic' => 'times', 'measure' => 'ruler',
    'reasoning' => 'bulb',
    'biology' => 'dna', 'chemistry' => 'flask', 'physics' => 'atom', 'working_sci' => 'clipboard',
    'retrieval' => 'search', 'inference' => 'bulb', 'language' => 'quote', 'writing' => 'pencil',
    'spelling' => 'abc', 'grammar' => 'punctuation',
];

function asm_skill_icon(string $skillId, array $def = []): string
{
    $name = (string)($def['icon'] ?? ASM_SKILL_ICONS[$skillId] ?? 'book');
    return preg_match('/^[a-z-]+$/', $name) && is_file(ASM_ICON_DIR . "/$name.png") ? $name : 'book';
}

function asm_icon_path(string $name): string
{
    return ASM_ICON_DIR . '/' . $name . '.png';
}

function asm_ring_font(bool $score): ?string
{
    $candidates = $score
        ? ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'C:/Windows/Fonts/seguisb.ttf', 'C:/Windows/Fonts/arial.ttf']
        : ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'C:/Windows/Fonts/segoeui.ttf', 'C:/Windows/Fonts/arial.ttf'];
    foreach ($candidates as $f) if (is_file($f)) return $f;
    return null;
}

/** "71" -> "7.1", "100" -> "10". The score out of 10 used across the email. */
function asm_score10(int|float $pct): string
{
    $v = round(max(0, min(100, (float)$pct)) / 10, 1);
    return $v == 10.0 ? '10' : number_format($v, 1);
}

function asm_hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Draw text centred on $cx at the given baseline, with the font's own spacing. */
function asm_ring_text($im, float $size, string $font, string $text, float $cx, float $baseline, $colour): void
{
    $bb = imagettfbbox($size, 0, $font, $text);
    imagettftext($im, $size, 0, (int)round($cx - ($bb[2] - $bb[0]) / 2 - $bb[0]), (int)round($baseline), $colour, $font, $text);
}

function asm_paste_icon($im, string $name, float $x, float $y, int $size): void
{
    $file = asm_icon_path($name);
    if (!is_file($file) || !($ic = @imagecreatefrompng($file))) return;
    imagecopyresampled($im, $ic, (int)round($x), (int)round($y), 0, 0, $size, $size, imagesx($ic), imagesy($ic));
    imagedestroy($ic);
}

/**
 * @param array $skills results['skills'] (each with 'pct'), in display order
 * @param array $icons  icon name per skill, same order
 * @return string PNG bytes, $size x $size pixels
 */
function asm_ring_png(array $skills, int|float $overallPct, array $icons = [], string $label = 'overall score', int $size = 720): string
{
    $k = 2;                                // draw at 2x, scale down for smooth edges
    $S = $size * $k;
    $im = imagecreatetruecolor($S, $S);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    $cx = $cy = $S / 2;
    $R = $S * 0.37;                        // centre line of the ring
    $T = $S * 0.122;                       // arc thickness (35 of 290 in the reference)
    $skills = array_values($skills);
    $icons = array_values($icons);
    $n = max(1, count($skills));
    $gap = $n > 1 ? 10.0 : 0.0;            // degrees between slots
    $slot = (360.0 - $n * $gap) / $n;
    $start = -90.0 - $slot / 2;            // first slot centred on the top

    $ink = imagecolorallocate($im, 0x19, 0x1B, 0x18);
    $muted = imagecolorallocate($im, 0x7B, 0x7C, 0x72);

    $pt = fn(float $deg, float $r) => [$cx + $r * cos(deg2rad($deg)), $cy + $r * sin(deg2rad($deg))];
    $arc = function (float $a0, float $a1, $col) use ($im, $pt, $R, $T) {
        if ($a1 - $a0 > 0.2) {
            $steps = max(8, (int)ceil(($a1 - $a0) / 1.5));
            $poly = [];
            for ($i = 0; $i <= $steps; $i++) { [$x, $y] = $pt($a0 + ($a1 - $a0) * $i / $steps, $R + $T / 2); $poly[] = $x; $poly[] = $y; }
            for ($i = $steps; $i >= 0; $i--) { [$x, $y] = $pt($a0 + ($a1 - $a0) * $i / $steps, $R - $T / 2); $poly[] = $x; $poly[] = $y; }
            imagefilledpolygon($im, array_map('intval', $poly), $col);
        }
        foreach ([$a0, $a1] as $a) { [$x, $y] = $pt($a, $R); imagefilledellipse($im, (int)$x, (int)$y, (int)$T, (int)$T, $col); }
    };

    // Round caps overhang an arc's end by half the thickness; keep them inside the slot.
    $cap = rad2deg(($T / 2) / $R);
    $iconSize = (int)round($T * 0.6);

    foreach ($skills as $i => $s) {
        $a0 = $start + $i * ($slot + $gap) + $cap;
        $full = max(0.0, $slot - 2 * $cap);
        [$r, $g, $b] = asm_hex_rgb(ASM_RING_COLOURS[$i % count(ASM_RING_COLOURS)]);
        $col = imagecolorallocate($im, $r, $g, $b);
        $pct = max(0, min(100, (int)($s['pct'] ?? 0)));
        $arc($a0, $a0 + $full * $pct / 100, $col);
        // the skill's icon sits on the start of its arc, like the reference
        [$bx, $by] = $pt($a0, $R);
        asm_paste_icon($im, $icons[$i] ?? 'book', $bx - $iconSize / 2, $by - $iconSize / 2, $iconSize);
    }

    // centre: the score out of 10, and its caption with an info mark
    $score = asm_score10($overallPct);
    $font = asm_ring_font(true);
    if ($font) {
        $fs = $S * 0.15;
        asm_ring_text($im, $fs, $font, $score, $cx, $cy + $S * 0.045, $ink);
    } else {
        imagestring($im, 5, (int)$cx - 12, (int)$cy - 8, $score, $ink);
    }
    $regular = asm_ring_font(false);
    if ($regular) {
        $fs = $S * 0.028;
        $bb = imagettfbbox($fs, 0, $regular, $label);
        $w = $bb[2] - $bb[0];
        $info = (int)round($fs * 1.25);
        $gapPx = $fs * 0.5;
        $x0 = $cx - ($w + $gapPx + $info) / 2;
        $base = $cy + $S * 0.108;
        imagettftext($im, $fs, 0, (int)round($x0 - $bb[0]), (int)round($base), $muted, $regular, $label);
        asm_paste_icon($im, 'info', $x0 + $w + $gapPx, $base - $fs * 1.05, $info);
    }

    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    imagedestroy($im);

    ob_start();
    imagepng($out, null, 6);
    imagedestroy($out);
    return (string)ob_get_clean();
}
