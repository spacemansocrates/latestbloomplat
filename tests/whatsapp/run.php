<?php
/**
 * tests/whatsapp/run.php
 * Run the WhatsApp suite: build the disposable app and database, start the app and
 * a Twilio stub, run the unit and HTTP tests, and report one result.
 *
 * Prerequisites: the throwaway MariaDB on 127.0.0.1:33316 (see
 * docs/classroom-workspaces.md), and ports 8098/8099 free.
 *
 * Run: php tests/whatsapp/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/fixtures.php';

$php = PHP_BINARY;
$failures = [];
foreach ([8099, WA_STUB_PORT] as $port) {
    $occupied = @fsockopen('127.0.0.1', $port, $portError, $portMessage, 0.2);
    if ($occupied) { fclose($occupied); fwrite(STDERR, "Port $port is occupied. Stop the old test server first.\n"); exit(1); }
}

function run_cmd(string $cmd): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
function wait_port(int $port): bool {
    for ($i = 0; $i < 40; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.5);
        if ($fp) { fclose($fp); return true; }
        usleep(250000);
    }
    return false;
}
function banner(string $s): void { echo "\n" . str_repeat('=', 70) . "\n== $s\n" . str_repeat('=', 70) . "\n"; }

banner('build the disposable app copy');
[$code, $out, $err] = run_cmd("\"$php\" \"" . __DIR__ . "/../classroom/prepare_app.php\"");
$app = trim($out);
if ($code !== 0 || !is_dir($app)) { echo $err; exit(1); }
// Test-only settings: an encryption key, and Twilio pointed at the local stub.
file_put_contents($app . '/.env', "APP_ENCRYPTION_KEY=" . WA_TEST_KEY . "\nTWILIO_API_BASE=http://127.0.0.1:" . WA_STUB_PORT . "\n", FILE_APPEND);
echo "app: $app\n";

banner('build the database');
[$code, $out, $err] = run_cmd("\"$php\" \"" . __DIR__ . "/prepare.php\"");
echo $out . $err;
if ($code !== 0) exit(1);

banner('unit');
[$code, $out, $err] = run_cmd("\"$php\" \"" . __DIR__ . "/test_unit.php\" \"$app\"");
echo $out . $err;
if ($code !== 0) $failures[] = 'test_unit.php';

banner('HTTP (app on 8099, Twilio stub on ' . WA_STUB_PORT . ')');
@unlink(wa_stub_log());
[$code, $out, $err] = run_cmd("\"$php\" \"" . __DIR__ . "/prepare.php\"");   // fresh data after the unit tests
$log = wa_tmp() . '/server.log';
// Array commands bypass the shell. With a string command Windows starts cmd.exe,
// proc_terminate kills only that wrapper, and the PHP server underneath is left
// running, holding this script's output pipe open forever.
$sessionDir = wa_tmp() . '/sessions';
if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);
$appSrv  = proc_open([$php, '-d', 'session.save_path="' . str_replace('\\', '/', realpath($sessionDir)) . '"', '-S', '127.0.0.1:8099', '-t', $app], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $p1);
$stubSrv = proc_open([$php, '-S', '127.0.0.1:' . WA_STUB_PORT, __DIR__ . '/stub_twilio.php'],
                     [1 => ['file', wa_tmp() . '/stub.log', 'w'], 2 => ['file', wa_tmp() . '/stub.log', 'a']], $p2);
if (!wait_port(8099) || !wait_port(WA_STUB_PORT)) {
    $failures[] = 'servers did not start';
} else {
    [$code, $out, $err] = run_cmd("\"$php\" \"" . __DIR__ . "/test_http.php\" \"$app\"");
    echo $out . $err;
    if ($code !== 0) $failures[] = 'test_http.php';
}
foreach ([$appSrv, $stubSrv] as $srv) if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }

// PHP errors the pages logged while the tests ran are failures too, even when the
// page still rendered well enough to pass.
$serverLog = (string)@file_get_contents($log);
if (preg_match_all('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $serverLog, $m)) {
    echo "\nPHP messages in the app server log:\n  " . implode("\n  ", array_unique($m[0])) . "\n";
    $failures[] = 'PHP warnings/errors in server log';
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures) { echo "FAILED:\n  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "ALL WHATSAPP CHECKS PASSED\n";
