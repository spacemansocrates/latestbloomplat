<?php
/**
 * tests/classroom/run.php
 *
 * Run the whole classroom suite in the right order and report a single result.
 *
 * Everything runs against the disposable MariaDB on 127.0.0.1:33316 and a disposable
 * copy of the app. The project .env -- which points at the production Hostinger
 * database -- is never read and never copied.
 *
 * Each suite gets a freshly built database. The suites are deliberately stateful --
 * they save boards, add pages, delete images -- and several act on the same fixture
 * workspace, so sharing one database makes later suites fail on earlier suites'
 * leftovers rather than on anything real. (test_workspace.php adds a page to the
 * Tara+Sam workspace; test_migration.php then counted three pages where the migration
 * had created two, and reported five failures that were nothing to do with the
 * migration.) Rebuilding takes well under a second and removes the whole class of
 * false failure.
 *
 * Prerequisites:
 *   1. The throwaway MariaDB is running on port 33316. To start it:
 *        mysqld --no-defaults --datadir=<a scratch dir> --port=33316 --bind-address=127.0.0.1
 *   2. No other service is using port 8099.
 *
 * Run: php tests/classroom/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$php  = PHP_BINARY;
$here = __DIR__;
$occupied = @fsockopen('127.0.0.1', 8099, $portError, $portMessage, 0.2);
if ($occupied) { fclose($occupied); fwrite(STDERR, "Port 8099 is occupied. Stop the old test server first.\n"); exit(1); }

function step(string $label): void {
    echo "\n" . str_repeat('=', 70) . "\n== $label\n" . str_repeat('=', 70) . "\n";
}

/**
 * @return array{0:int,1:string,2:string} exit code, stdout, stderr
 *
 * stdout and stderr are kept apart because prepare_app.php prints the app directory
 * on stdout and its progress on stderr; merging them made the directory unusable.
 */
function run(string $cmd, ?string $cwd = null): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

$failures = [];
$appDir = '';

/** Rebuild the fixture database, optionally importing the historic boards too. */
function freshDb(bool $withMigration = false): void {
    global $php, $here, $appDir, $failures;
    [$code, $out, $err] = run("\"$php\" \"$here/prepare.php\"");
    if ($code !== 0) { echo $out . $err; $failures[] = 'prepare.php'; return; }
    if (!$withMigration) return;
    [$code, $out, $err] = run("\"$php\" \"$appDir/migrations/2026_09_13_migrate_lesson_whiteboards.php\" --apply");
    if ($code !== 0) { echo $out . $err; $failures[] = 'migration apply'; }
}

step('1/6  build the disposable app copy');
[$code, $out, $err] = run("\"$php\" \"$here/prepare_app.php\"");
$appDir = trim($out);
echo $err;
echo "app: $appDir\n";
if ($code !== 0 || !is_dir($appDir)) { echo "prepare_app failed\n"; exit(1); }

step('2/6  migration: dry run, apply, and re-apply for idempotency');
freshDb(false);
[$code, $out, $err] = run("\"$php\" \"$appDir/migrations/2026_09_13_migrate_lesson_whiteboards.php\"");
echo $out . $err;
if ($code !== 0) $failures[] = 'migration dry run';

[$code, $out, $err] = run("\"$php\" \"$appDir/migrations/2026_09_13_migrate_lesson_whiteboards.php\" --apply");
echo $out . $err;
if ($code !== 0) $failures[] = 'migration apply';

[$code, $reapply, $err] = run("\"$php\" \"$appDir/migrations/2026_09_13_migrate_lesson_whiteboards.php\" --apply");
echo $reapply . $err;
if (strpos($reapply, 'pages imported     : 0') === false) {
    $failures[] = 'migration is NOT idempotent (a second --apply imported pages)';
}

step('3/6  migration outcome');
// Deliberately runs on the database the step above just migrated, before any other
// suite has had a chance to add pages to it.
[$code, $out, $err] = run("\"$php\" \"$here/test_migration.php\"");
echo $out . $err;
if ($code !== 0) $failures[] = 'test_migration.php';

step('4/6  workspace behaviour');
freshDb(false);
[$code, $out, $err] = run("\"$php\" \"$here/test_workspace.php\"");
echo $out . $err;
if ($code !== 0) $failures[] = 'test_workspace.php';

step('5/6  HTTP tests (starting the server on 8099)');
$serverLog = sys_get_temp_dir() . '/tutsys-classroom-test/server.log';
$sessionDir = sys_get_temp_dir() . '/tutsys-classroom-test/sessions';
if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);
$cmd = [$php, '-d', 'session.save_path="' . str_replace('\\', '/', realpath($sessionDir)) . '"', '-S', '127.0.0.1:8099', '-t', $appDir];
$descriptors = [1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']];
$server = proc_open($cmd, $descriptors, $serverPipes);

// Wait for it to answer rather than sleeping a fixed amount.
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.5);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(250000);
}

if (!$up) {
    echo "the test server did not come up on 8099\n";
    $failures[] = 'server startup';
} else {
    foreach (['test_api_http.php', 'test_file_gateway.php', 'test_books_http.php'] as $t) {
        freshDb(true);
        [$code, $out, $err] = run("\"$php\" \"$here/$t\"");
        echo $out . $err;
        if ($code !== 0) $failures[] = $t;
    }

    step('6/6  browser tests (Playwright)');
    [$code, $out, $err] = run('node "' . $here . '/test_book_sync.mjs" "' . dirname($here, 2) . '/assets/livekit/book_sync.js"');
    echo $out . $err;
    if ($code !== 0) $failures[] = 'test_book_sync.mjs';
    $pwDir = sys_get_temp_dir() . '/tutsys-classroom-test';
    if (!is_dir($pwDir . '/node_modules/playwright')) {
        echo "SKIPPED: Playwright is not installed in $pwDir\n";
        echo "  cd $pwDir && npm install playwright && npx playwright install chromium\n";
        $failures[] = 'browser tests NOT RUN (Playwright missing)';
    } else {
        foreach (['browser_board.mjs', 'browser_books.mjs'] as $browserTest) {
            freshDb(true);
            copy($here . '/' . $browserTest, $pwDir . '/' . $browserTest);
            [$code, $out, $err] = run('node ' . $browserTest, $pwDir);
            echo $out . $err;
            if ($code !== 0) $failures[] = $browserTest;
        }
    }
}

if (is_resource($server)) { proc_terminate($server); proc_close($server); }

$serverMessages = (string)@file_get_contents($serverLog);
if (preg_match_all('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $serverMessages, $matches)) {
    echo "\nPHP server messages:\n" . implode("\n", array_unique($matches[0])) . "\n";
    $failures[] = 'PHP warnings/errors in disposable server log';
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures) {
    echo "FAILED:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL CLASSROOM CHECKS PASSED\n";
