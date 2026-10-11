<?php
/**
 * tests/classroom/test_api_http.php
 *
 * End-to-end tests of api/whiteboard.php over real HTTP with real sessions,
 * against the disposable app copy (tests/classroom/prepare_app.php) and the
 * disposable database (tests/classroom/prepare.php).
 *
 * These go through authenticate.php rather than forging $_SESSION, so the
 * authorization they prove is the authorization the browser actually gets.
 *
 * Run (see docs/classroom-workspaces.md for the full sequence):
 *   php tests/classroom/prepare.php
 *   php tests/classroom/prepare_app.php
 *   php -S 127.0.0.1:8099 -t <app dir>   &
 *   php tests/classroom/test_api_http.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const BASE = 'http://127.0.0.1:8099';
const PASSWORD = 'ClassroomTest123!';

$passed = 0;
$failed = [];

function check(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed[] = $label; echo "FAIL  $label\n"; }
}

/** One cURL request carrying a cookie jar, returning [status, decoded body, raw]. */
function req(string $jar, string $method, string $url, $jsonBody = null): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$raw, true), $raw];
}

/** Sign in through the real login form and return the cookie jar path. */
function login(string $email): string {
    $jar = tempnam(sys_get_temp_dir(), 'ctjar');
    $ch = curl_init(BASE . '/authenticate.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['email' => $email, 'password' => PASSWORD]),
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 302) {
        fwrite(STDERR, "login failed for $email (HTTP $status)\n");
        exit(1);
    }
    return $jar;
}

$tara = login('tara@example.test');   // tutor of lessons 101/102/103, 201, demos
$sam  = login('sam@example.test');    // student of 101/102/103
$otto = login('otto@example.test');   // unrelated tutor
$pia  = login('pia@example.test');    // unrelated student

echo "--- access control ---\n";

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101');
check($s === 200 && $b['success'] === true, 'the lesson tutor can load the board');

[$s, $b] = req($sam, 'GET', '/api/whiteboard.php?lesson_id=101');
check($s === 200 && $b['success'] === true, 'the lesson student can load the board');

[$s, $b] = req($otto, 'GET', '/api/whiteboard.php?lesson_id=101');
check($s === 404, 'an unrelated tutor is refused (404, not a different error)');

[$s, $b] = req($pia, 'GET', '/api/whiteboard.php?lesson_id=101');
check($s === 404, 'an unrelated student is refused');

[$s, $b] = req($pia, 'GET', '/api/whiteboard.php?lesson_id=999999');
check($s === 404, 'a non-existent lesson gives the same 404 (no id probing)');

echo "\n--- continuity across two different lessons ---\n";

// Draw on lesson 101's board, then open a DIFFERENT lesson for the same pair.
[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101');
$pageId = (int)$b['data']['page']['id'];
$rev    = (int)$b['data']['page']['revision'];
$wsA    = (int)$b['data']['workspace_id'];

$drawing = [['id' => 'continuity-proof', 'type' => 'text', 'version' => 9, 'isDeleted' => false]];
[$s, $b] = req($tara, 'POST', '/api/whiteboard.php?lesson_id=101',
    ['page_id' => $pageId, 'revision' => $rev, 'scene' => $drawing, 'files' => []]);
check($s === 200 && $b['data']['saved'] === true, 'the tutor saves work during lesson A');

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=103');
check((int)$b['data']['workspace_id'] === $wsA,
    'a DIFFERENT lesson for the same pair resolves to the same workspace');
$ids = array_column($b['data']['scene'] ?? [], 'id');
check(in_array('continuity-proof', $ids, true),
    "lesson B opens showing lesson A's work -- the whole point of the feature");

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=201');
check((int)$b['data']['workspace_id'] !== $wsA,
    'the same tutor with a different student gets a different workspace');
$ids = array_column($b['data']['scene'] ?? [], 'id');
check(!in_array('continuity-proof', $ids, true),
    'that other student sees none of the first pair\'s work');

echo "\n--- revision conflicts over HTTP ---\n";

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101');
$pageId  = (int)$b['data']['page']['id'];
$current = (int)$b['data']['page']['revision'];

// Sam saves legitimately.
[$s, $b] = req($sam, 'POST', '/api/whiteboard.php?lesson_id=101',
    ['page_id' => $pageId, 'revision' => $current,
     'scene' => [['id' => 'sam-work', 'version' => 3, 'isDeleted' => false]], 'files' => []]);
check($s === 200, 'the student can also save to the shared board');

// Tara's tab is now stale and tries to save an empty scene on the old revision.
[$s, $b] = req($tara, 'POST', '/api/whiteboard.php?lesson_id=101',
    ['page_id' => $pageId, 'revision' => $current, 'scene' => [], 'files' => []]);
check($s === 409 && ($b['data']['reason'] ?? '') === 'conflict',
    'a stale save is rejected with 409, not silently applied');
check(!empty($b['data']['scene']), 'the 409 carries the newer scene back for merging');

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101');
$ids = array_column($b['data']['scene'] ?? [], 'id');
check(in_array('sam-work', $ids, true),
    "the stale empty save did NOT wipe the student's work");

echo "\n--- a client that cannot say what it was based on ---\n";

// This is the shape the OLD client sent, and the shape a page whose initial load
// failed would send. It must not be accepted.
[$s, $b] = req($tara, 'POST', '/api/whiteboard.php?lesson_id=101',
    ['scene' => [], 'files' => []]);
check($s === 409 && ($b['data']['reason'] ?? '') === 'stale_client',
    'a save with no page_id/revision is refused rather than applied blind');

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101');
$ids = array_column($b['data']['scene'] ?? [], 'id');
check(in_array('sam-work', $ids, true), 'and the board is still intact after that attempt');

echo "\n--- cross-workspace page ids ---\n";

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=201');
$ninaPageId = (int)$b['data']['page']['id'];

// Same tutor, but naming a page that belongs to the OTHER relationship's workspace
// while acting through lesson 101.
[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=101&page_id=' . $ninaPageId);
check($s === 404, 'a page id from another workspace cannot be read through this lesson');

[$s, $b] = req($tara, 'POST', '/api/whiteboard.php?lesson_id=101',
    ['page_id' => $ninaPageId, 'revision' => 0, 'scene' => [['id' => 'x']], 'files' => []]);
check($s === 404, 'nor written to');

echo "\n--- pages ---\n";

[$s, $b] = req($tara, 'POST', '/api/whiteboard.php?lesson_id=101&action=new_page', ['title' => 'Week 3']);
check($s === 200 && !empty($b['data']['page']['id']), 'the tutor can start a fresh page');
$newPageId = (int)$b['data']['page']['id'];
check(count($b['data']['pages']) >= 2, 'the previous page is still listed');

[$s, $b] = req($sam, 'GET', '/api/whiteboard.php?lesson_id=101');
check((int)$b['data']['page']['id'] === $newPageId, 'the student opens on the new active page');
check(empty($b['data']['scene']), 'which is blank');

[$s, $b] = req($sam, 'GET', '/api/whiteboard.php?lesson_id=101&page_id=' . $pageId);
$ids = array_column($b['data']['scene'] ?? [], 'id');
check(in_array('sam-work', $ids, true), 'and can still go back to the earlier page');

[$s, $b] = req($otto, 'POST', '/api/whiteboard.php?lesson_id=101&action=new_page', ['title' => 'Intruder']);
check($s === 404, 'an unrelated tutor cannot add pages to someone else\'s workspace');

echo "\n--- demo isolation ---\n";

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=401');
$demo1Ws = (int)$b['data']['workspace_id'];
[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=402');
$demo2Ws = (int)$b['data']['workspace_id'];
check($demo1Ws !== $demo2Ws, 'two demo lessons do not share one board');
check($demo1Ws !== $wsA && $demo2Ws !== $wsA, 'and neither is the real relationship\'s board');

echo "\n--- malformed input ---\n";

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php?lesson_id=abc');
check($s === 400, 'a non-numeric lesson_id is rejected');

[$s, $b] = req($tara, 'GET', '/api/whiteboard.php');
check($s === 400, 'a missing lesson_id is rejected');

$ch = curl_init(BASE . '/api/whiteboard.php?lesson_id=101');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $tara, CURLOPT_COOKIEFILE => $tara,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'not json at all',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
curl_exec($ch);
$s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check($s === 400, 'an unparseable body is rejected');

echo "\n";
echo "passed: $passed\n";
if ($failed) {
    echo "FAILED: " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
echo "all green\n";
