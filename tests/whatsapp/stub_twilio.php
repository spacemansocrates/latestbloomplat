<?php
/**
 * tests/whatsapp/stub_twilio.php
 * A stand-in for api.twilio.com, run as a router: php -S 127.0.0.1:8098 stub_twilio.php
 *
 * Answers the two endpoints the app uses, with the shapes Twilio returns, and
 * records every request to a JSONL file so tests can assert on exactly what the
 * app sent. Recognises only the fixed test credentials in fixtures.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/fixtures.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Basic auth, parsed by hand so the answer does not depend on how the CLI server
// populates PHP_AUTH_USER.
$auth = '';
foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
    if (strtolower($k) === 'authorization') $auth = $v;
}
[$user, $pass] = array_pad(explode(':', (string)base64_decode(preg_replace('/^Basic\s+/i', '', $auth)), 2), 2, '');
$authOk = hash_equals(WA_SID, $user) && hash_equals(WA_TOKEN, $pass);

function out(int $status, array $json): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($json);
    exit;
}
function record(array $entry): void {
    file_put_contents(wa_stub_log(), json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

if ($method === 'GET' && preg_match('#^/2010-04-01/Accounts/([^/]+)\.json$#', $path, $m)) {
    record(['type' => 'verify', 'auth_ok' => $authOk]);
    if (!$authOk || $m[1] !== WA_SID) out(401, ['code' => 20003, 'message' => 'Authenticate', 'status' => 401]);
    out(200, ['sid' => WA_SID, 'status' => 'active', 'friendly_name' => 'Bloom Test Account']);
}

if ($method === 'POST' && preg_match('#^/2010-04-01/Accounts/([^/]+)/Messages\.json$#', $path, $m)) {
    parse_str(file_get_contents('php://input'), $f);
    $entry = ['type' => 'message', 'auth_ok' => $authOk] + $f;
    if (!$authOk) { record($entry); out(401, ['code' => 20003, 'message' => 'Authenticate', 'status' => 401]); }
    if (($f['To'] ?? '') === 'whatsapp:' . WA_INVALID_TO) {
        record($entry + ['result' => 'rejected']);
        out(400, ['code' => 21211, 'message' => "The 'To' number is not a valid phone number.", 'status' => 400]);
    }
    $sid = 'SM' . bin2hex(random_bytes(16));
    record($entry + ['result' => 'accepted', 'sid' => $sid]);
    out(201, ['sid' => $sid, 'status' => 'queued', 'to' => $f['To'] ?? null]);
}

out(404, ['code' => 20404, 'message' => 'Not found']);
