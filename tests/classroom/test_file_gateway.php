<?php
/**
 * tests/classroom/test_file_gateway.php
 *
 * Regression tests for api/file_url.php.
 *
 * The bug: the gateway accepted 'homework/<file>' and 'submissions/<file>' for ANY
 * signed-in session, with no ownership check whatsoever. Any student could read any
 * other student's submitted work by guessing or learning a filename, bypassing
 * student/api/download.php -- the downloader that actually checks whose file it is.
 *
 * These tests fail against the old version of that file and pass against the new one.
 *
 * Run after prepare.php + prepare_app.php with the server on 127.0.0.1:8099:
 *   php tests/classroom/test_file_gateway.php
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

function login(string $email): string {
    $jar = tempnam(sys_get_temp_dir(), 'fgjar');
    $ch = curl_init(BASE . '/authenticate.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => $email, 'password' => PASSWORD]),
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 302) { fwrite(STDERR, "login failed for $email\n"); exit(1); }
    return $jar;
}

/** @return array{0:int,1:string} status and body */
function get(?string $jar, string $path): array {
    $ch = curl_init(BASE . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false];
    if ($jar !== null) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

$tutor   = login('tara@example.test');
$student = login('sam@example.test');   // owns submission 1
$other   = login('pia@example.test');   // owns nothing here

echo "--- the hole that was open: other people's files ---\n";

[$s, $b] = get($other, '/api/file_url.php?p=submissions/private.pdf');
check($s === 400, "an unrelated student cannot fetch another student's submission (got $s)");

[$s, $b] = get($student, '/api/file_url.php?p=submissions/private.pdf');
check($s === 400, 'not even the owner goes through this gateway any more (download.php does that)');

[$s, $b] = get($other, '/api/file_url.php?p=homework/assignment.pdf');
check($s === 400, 'homework attachments are no longer served by the generic gateway');

[$s, $b] = get($tutor, '/api/file_url.php?p=homework/assignment.pdf');
check($s === 400, 'nor for a tutor -- the prefix is gone entirely, not role-gated');

echo "\n--- materials still work ---\n";

[$s, $b] = get($tutor, '/api/file_url.php?p=materials/published.pdf');
check($s === 200 && strpos($b, 'published material') !== false,
    'a tutor can still open a published material');

[$s, $b] = get($student, '/api/file_url.php?p=materials/published.pdf');
check($s === 200, 'a student can open a published material');

echo "\n--- unpublished material is staff-only ---\n";

[$s, $b] = get($tutor, '/api/file_url.php?p=materials/unpublished.pdf');
check($s === 200, 'a tutor can open an unpublished draft');

[$s, $b] = get($student, '/api/file_url.php?p=materials/unpublished.pdf');
check($s === 404, 'a student cannot, and gets a plain 404 that does not confirm it exists');

echo "\n--- unknown and malformed paths ---\n";

[$s, $b] = get($tutor, '/api/file_url.php?p=materials/not-a-real-material.pdf');
check($s === 404, 'a filename with no learning_materials row is refused');

[$s, $b] = get($tutor, '/api/file_url.php?p=materials/../../.env');
check($s === 400, 'traversal is rejected');

[$s, $b] = get($tutor, '/api/file_url.php?p=materials/sub/dir.pdf');
check($s === 400, 'nested paths are rejected');

[$s, $b] = get($tutor, '/api/file_url.php');
check($s === 400, 'a missing path is rejected');

echo "\n--- authentication is still required ---\n";

[$s, $b] = get(null, '/api/file_url.php?p=materials/published.pdf');
check($s === 403, 'an anonymous request is refused');

echo "\n";
echo "passed: $passed\n";
if ($failed) {
    echo "FAILED: " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
echo "all green\n";
