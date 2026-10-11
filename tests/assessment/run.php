<?php
/**
 * tests/assessment/run.php
 * Run the assessment suite: validator, unit tests, then the HTTP flow against a
 * disposable copy of the app with the fixture bank installed.
 *
 * Prerequisites: the throwaway MariaDB on 127.0.0.1:33316 (see
 * docs/classroom-workspaces.md) and port 8099 free.
 *
 * Run: php tests/assessment/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../whatsapp/fixtures.php';

$php = PHP_BINARY;
$root = dirname(__DIR__, 2);
$failures = [];

$occupied = @fsockopen('127.0.0.1', 8099, $e, $m, 0.2);
if ($occupied) { fclose($occupied); fwrite(STDERR, "Port 8099 is occupied. Stop the old test server first.\n"); exit(1); }

function run_cmd(array $cmd): array {
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

banner('validator');
[$code, $out, $err] = run_cmd([$php, $root . '/tools/validate_assessment.php', __DIR__ . '/fixtures/ks1-maths-v1.json']);
echo $out . $err;
if ($code !== 0) $failures[] = 'fixture fails the validator';
$bank = glob($root . '/assessments/bank/*.json') ?: [];
if ($bank) {
    [$code, $out, $err] = run_cmd(array_merge([$php, $root . '/tools/validate_assessment.php'], $bank));
    echo $out . $err;
    if ($code !== 0) $failures[] = 'a bank file fails the validator';
}

banner('unit');
[$code, $out, $err] = run_cmd([$php, __DIR__ . '/test_unit.php']);
echo $out . $err;
if ($code !== 0) $failures[] = 'test_unit.php';

banner('build the disposable app copy');
[$code, $out, $err] = run_cmd([$php, $root . '/tests/classroom/prepare_app.php']);
$app = trim($out);
if ($code !== 0 || !is_dir($app)) { echo $err; exit(1); }
// The real bank is replaced by the fixture, so the HTTP checks do not depend on content.
foreach (glob($app . '/assessments/bank/*.json') ?: [] as $f) unlink($f);
if (!is_dir($app . '/assessments/bank')) mkdir($app . '/assessments/bank', 0777, true);
copy(__DIR__ . '/fixtures/ks1-maths-v1.json', $app . '/assessments/bank/ks1-maths-v1.json');
// The Stripe signing secret is stored encrypted, so the copy needs an encryption key.
file_put_contents($app . '/.env', "APP_ENCRYPTION_KEY=" . WA_TEST_KEY . "\n", FILE_APPEND);
echo "app: $app\n";

banner('build the database');
[$code, $out, $err] = run_cmd([$php, __DIR__ . '/prepare.php']);
echo $out . $err;
if ($code !== 0) exit(1);

banner('HTTP (app on 8099)');
$tmp = sys_get_temp_dir() . '/tutsys-assessment-test';
if (!is_dir($tmp . '/sessions')) mkdir($tmp . '/sessions', 0777, true);
$log = $tmp . '/server.log';
$srv = proc_open([$php, '-d', 'session.save_path="' . str_replace('\\', '/', realpath($tmp . '/sessions')) . '"', '-S', '127.0.0.1:8099', '-t', $app],
                 [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
if (!wait_port(8099)) {
    $failures[] = 'server did not start';
} else {
    foreach (['test_http.php', 'test_admin.php', 'test_stripe.php'] as $t) {
        [$code, $out, $err] = run_cmd([$php, __DIR__ . '/' . $t]);
        echo $out . $err;
        if ($code !== 0) $failures[] = $t;
    }
}
if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }

$serverLog = (string)@file_get_contents($log);
if (preg_match_all('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $serverLog, $mm)) {
    echo "\nPHP messages in the app server log:\n  " . implode("\n  ", array_unique($mm[0])) . "\n";
    $failures[] = 'PHP warnings/errors in server log';
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures) { echo "FAILED:\n  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "ALL ASSESSMENT CHECKS PASSED\n";
