<?php
// Real sessions and HTTP against the disposable app only.
declare(strict_types=1);
require_once __DIR__ . '/config.php';
const BASE = 'http://127.0.0.1:8099';
$passed = 0;
$failed = [];
function check(bool $ok, string $label): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  $label\n"; }
    else { $failed[] = $label; echo "FAIL  $label\n"; }
}
function req(?string $jar, string $path, string $method = 'GET', ?string $body = null, array $headers = []): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $raw = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, substr($raw, $size), substr($raw, 0, $size)];
}
function login(string $email): string {
    $jar = tempnam(sys_get_temp_dir(), 'book');
    [$s] = req($jar, '/authenticate.php', 'POST', http_build_query(['email' => $email, 'password' => 'ClassroomTest123!']),
        ['Content-Type: application/x-www-form-urlencoded']);
    check($s === 302, 'login ' . $email);
    return $jar;
}
$tutor = login('tara@example.test');
$student = login('sam@example.test');
$otherTutor = login('otto@example.test');
$otherStudent = login('pia@example.test');
$endpoint = '/api/lesson_books.php?lesson_id=101';

foreach ([$otherTutor, $otherStudent, null] as $i => $jar) {
    foreach (['', '&open=10', '&stream=10'] as $action) {
        [$s, $b] = req($jar, $endpoint . $action);
        [$missingStatus, $missingBody] = req($jar, '/api/lesson_books.php?lesson_id=999999' . $action);
        check($s === 403 && $missingStatus === 403 && $b === $missingBody && $b === '{"error":"Forbidden"}', "outsider $i: identical denial for $action and absent lesson");
    }
}
foreach ([$tutor, $student] as $i => $jar) {
    [$s, $b] = req($jar, $endpoint);
    $data = json_decode($b, true);
    $books = $data['books'] ?? [];
    check($s === 200 && array_column($books, 'id') === [10, 13, 11], "party $i: published PDF books only, subject first");
    check(($books[0]['matches_subject'] ?? false) === true && ($books[2]['matches_subject'] ?? null) === false, "party $i: subject flags are booleans");
    check(str_contains($books[0]['title'] ?? '', "<img src=x onerror=alert(1)> tutor's"), "party $i: title safely travels as JSON text");
    foreach ([12, 14, 15, 999999, '10.0', '10x', '../.env', '0', '2147483648', '10&open[]=11'] as $id) {
        [$s] = req($jar, $endpoint . '&open=' . $id);
        check($s === 404, "party $i: invalid/unpublished/non-book material $id refused");
    }
    [$s, $b] = req($jar, $endpoint . '&open=13');
    check($s === 404 && str_contains($b, "This book's file is unavailable"), "party $i: missing file is explicit");
    [$s, $b] = req($jar, $endpoint . '&open=10');
    $opened = json_decode($b, true);
    check($s === 200 && isset($opened['url']) && array_key_exists('expires_at', $opened), "party $i: direct local URL resolves");
    $path = '/api/' . ($opened['url'] ?? 'missing');
    [$s, $b, $headers] = req($jar, $path, 'GET', null, ['Range: bytes=0-15']);
    check($s === 206 && strlen($b) === 16 && str_starts_with($b, '%PDF-1.4') && str_contains($headers, 'Content-Range: bytes 0-15/'), "party $i: local PDF ranged GET is 206 with exact bytes");
    [$s, $b, $headers] = req($jar, $path, 'HEAD');
    preg_match('/Content-Length: (\d+)/i', $headers, $m);
    $total = (int)($m[1] ?? 0);
    check($s === 200 && $b === '' && $total > 4194304 && str_contains($headers, 'Accept-Ranges: bytes'), "party $i: HEAD advertises size and ranges");
    [$s, $b] = req($jar, $path, 'GET', null, ['Range: bytes=-6']);
    check($s === 206 && $b === "%%EOF\n", "party $i: suffix range");
    [$s, $b] = req($jar, $path, 'GET', null, ['Range: bytes=' . ($total - 6) . '-']);
    check($s === 206 && $b === "%%EOF\n", "party $i: open-ended range");
    foreach (['bytes=999999999-', 'bytes=30-10', 'bytes=0-1,3-4', 'bytes=-0', 'bytes=-'] as $range) {
        [$s, $b, $headers] = req($jar, $path, 'GET', null, ['Range: ' . $range]);
        check($s === 416 && str_contains($headers, 'Content-Range: bytes */'), "party $i: invalid range $range refused");
    }
}
foreach ([104, 105] as $lesson) {
    [$s, $b] = req($tutor, '/api/lesson_books.php?lesson_id=' . $lesson);
    $books = json_decode($b, true)['books'] ?? [];
    check($s === 200 && count($books) === 3 && !array_filter($books, fn($b) => $b['matches_subject']), "lesson $lesson: full library when no subject match or null subject");
}
$state = json_encode(['material_id' => 10, 'page' => 3, 'zoom' => 1.25]);
foreach ([$student, $otherTutor, $otherStudent, null] as $i => $jar) {
    [$s] = req($jar, $endpoint, 'POST', $state, ['Content-Type: application/json']);
    check($s === 403, "writer $i: only lesson tutor can save");
}
[$s] = req($tutor, $endpoint, 'POST', $state, ['Content-Type: text/plain']);
check($s === 415, 'form-compatible content type refused');
[$s] = req($tutor, $endpoint, 'POST', $state, ['Content-Type: application/json', 'Sec-Fetch-Site: cross-site']);
check($s === 415, 'cross-site save refused');
foreach ([['material_id' => '10', 'page' => 1, 'zoom' => 1], ['material_id' => 10, 'page' => -1, 'zoom' => 1],
    ['material_id' => 10, 'page' => 1.2, 'zoom' => 1], ['material_id' => 10, 'page' => 1, 'zoom' => 4]] as $bad) {
    [$s] = req($tutor, $endpoint, 'POST', json_encode($bad), ['Content-Type: application/json']);
    check($s === 400, 'malformed state refused: ' . json_encode($bad));
}
[$s] = req($tutor, $endpoint, 'POST', str_replace('10', '12', $state), ['Content-Type: application/json']);
check($s === 404, 'cannot remember unpublished book');
[$s, $b] = req($tutor, $endpoint, 'POST', $state, ['Content-Type: application/json']);
check($s === 200 && (json_decode($b, true)['saved'] ?? false), 'tutor saves last book');
[$s, $b] = req($tutor, '/api/lesson_books.php?lesson_id=103');
$resume = json_decode($b, true)['resume'] ?? [];
check(($resume['materialId'] ?? 0) === 10 && ($resume['page'] ?? 0) === 3 && ($resume['zoom'] ?? 0) === 1.25, 'next lesson for pair remembers position');
[$s, $b] = req($tutor, '/api/lesson_books.php?lesson_id=201');
check((json_decode($b, true)['resume'] ?? null) === null, 'other pair has no saved book');
[$s, $b] = req($student, $endpoint);
check((json_decode($b, true)['resume'] ?? null) === null, 'student does not auto-resume historic tutor state');
req($tutor, '/api/lesson_books.php?lesson_id=401', 'POST', $state, ['Content-Type: application/json']);
[$s, $b] = req($tutor, '/api/lesson_books.php?lesson_id=402');
check((json_decode($b, true)['resume'] ?? null) === null, 'demo book memory is isolated by lesson');
ct_pdo()->exec('UPDATE learning_materials SET is_published=0 WHERE id=10');
[$s, $b] = req($tutor, $endpoint);
check((json_decode($b, true)['resume'] ?? null) === null, 'unpublished saved book no longer offered');
[$s] = req($student, $endpoint . '&stream=10');
check($s === 404, 'every range request rechecks publication');
ct_pdo()->exec('UPDATE learning_materials SET is_published=1 WHERE id=10');
foreach ([$tutor, $student, $otherTutor, $otherStudent] as $jar) unlink($jar);
echo "\nBook HTTP checks: $passed passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
