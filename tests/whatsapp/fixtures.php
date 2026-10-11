<?php
/**
 * tests/whatsapp/fixtures.php
 * Shared constants and helpers for the WhatsApp suite.
 *
 * Nothing here can reach the real Twilio: the disposable app copy has
 * TWILIO_API_BASE pointed at tests/whatsapp/stub_twilio.php on port 8098, and the
 * credentials below are fixed strings the stub recognises, not a real account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../classroom/config.php';

const WA_BASE      = 'http://127.0.0.1:8099';
const WA_STUB_PORT = 8098;
const WA_PASSWORD  = 'ClassroomTest123!';

const WA_SID     = 'AC' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WA_TOKEN   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbb1234';
const WA_BADTOK  = 'cccccccccccccccccccccccccccc9999';
const WA_FROM    = '+14155238886';
const WA_TPL_REM = 'HX' . '11111111111111111111111111111111';
const WA_TPL_DIG = 'HX' . '22222222222222222222222222222222';
const WA_INVALID_TO = '+15005550009';   // the stub answers this one with Twilio error 21211

/** 32 fixed bytes so a test run can decrypt what the app encrypted. */
const WA_TEST_KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

function wa_tmp(): string {
    $d = sys_get_temp_dir() . '/tutsys-whatsapp-test';
    if (!is_dir($d)) mkdir($d, 0777, true);
    return $d;
}
function wa_stub_log(): string { return wa_tmp() . '/stub.jsonl'; }

/** Every request the stub has received, oldest first. */
function wa_stub_requests(): array {
    if (!is_file(wa_stub_log())) return [];
    $out = [];
    foreach (file(wa_stub_log(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) $out[] = json_decode($l, true);
    return $out;
}
function wa_stub_messages(): array {
    return array_values(array_filter(wa_stub_requests(), static fn($r) => ($r['type'] ?? '') === 'message'));
}

$GLOBALS['wa_passed'] = 0;
$GLOBALS['wa_failed'] = [];
function check(bool $cond, string $label): void {
    if ($cond) { $GLOBALS['wa_passed']++; echo "PASS  $label\n"; }
    else       { $GLOBALS['wa_failed'][] = $label; echo "FAIL  $label\n"; }
}
function finish(string $suite): void {
    $f = $GLOBALS['wa_failed'];
    echo "\n$suite: {$GLOBALS['wa_passed']} passed, " . count($f) . " failed\n";
    exit($f ? 1 : 0);
}

/** @return array{0:int,1:string,2:array} status, body, headers (lower-cased names) */
function http(string $jar, string $method, string $path, ?array $form = null, array $headers = []): array {
    $ch = curl_init(WA_BASE . $path);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$respHeaders) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ]);
    if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body, $respHeaders];
}

/** POST a form and follow the post/redirect/get bounce. Returns [first status, final body]. */
function post_follow(string $jar, string $path, array $form): array {
    [$s, $b, $h] = http($jar, 'POST', $path, $form);
    if ($s === 302 && !empty($h['location'])) {
        $loc = $h['location'];
        if (!str_starts_with($loc, '/') && !str_starts_with($loc, 'http')) {
            $loc = rtrim(dirname(parse_url($path, PHP_URL_PATH)), '/') . '/' . $loc;
        }
        $loc = preg_replace('/#.*$/', '', $loc);
        [, $b] = http($jar, 'GET', $loc);
    }
    return [$s, $b];
}

function login(string $email): string {
    $jar = tempnam(sys_get_temp_dir(), 'wajar');
    [$s] = http($jar, 'POST', '/authenticate.php', ['email' => $email, 'password' => WA_PASSWORD]);
    if ($s !== 302) { fwrite(STDERR, "login failed for $email (HTTP $s)\n"); exit(1); }
    return $jar;
}

/** The value of the first hidden input with this name in a page. */
function scrape(string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? html_entity_decode($m[1], ENT_QUOTES) : '';
}

/** Sign a status callback exactly as Twilio does. */
function twilio_sign(string $token, string $url, array $params): string {
    ksort($params, SORT_STRING);
    $data = $url;
    foreach ($params as $k => $v) $data .= $k . $v;
    return base64_encode(hash_hmac('sha1', $data, $token, true));
}
