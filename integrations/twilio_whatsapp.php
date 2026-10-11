<?php
/**
 * integrations/twilio_whatsapp.php
 * Sending WhatsApp messages through Twilio's REST API, and tracking what happens
 * to them afterwards.
 *
 * Hand-rolled over cURL rather than the Twilio SDK, for the same reason
 * livekit_client.php mints its own JWTs: composer is not available on the
 * deployment box. Two endpoints are the whole API surface we need: POST
 * /Messages.json to send, GET /Accounts/<sid>.json to check credentials.
 *
 * Configuration resolves ENV first, then the app_settings table (same order as
 * livekit_get_setting), so a value in the server .env always wins and an admin can
 * still manage everything else from admin/whatsapp.php without a deploy. The auth
 * token is stored encrypted there (utils/secret_box.php); every other value is an
 * identifier, not a secret.
 *
 * IMPORTANT: templates. A message a business sends first (which every reminder is)
 * has to be a template approved by Meta through the Twilio console; free-form text
 * is only allowed inside the 24 hours after the person last messaged you. So the
 * reminder/digest paths send a ContentSid + variables, never a body. Only the
 * admin's test can send plain text. See docs/whatsapp_templates.md.
 *
 * Delivery tracking: every send asks Twilio to POST status changes to
 * api/twilio_status.php, and every send is recorded in whatsapp_messages. Twilio's
 * "queued"/"accepted" on the send itself only means Twilio took it.
 */

declare(strict_types=1);

if (!function_exists('load_env')) {
    $envLoader = __DIR__ . '/../utils/env_loader.php';
    if (is_file($envLoader)) require_once $envLoader;
}
if (function_exists('load_env')) {
    load_env(__DIR__ . '/../.env');
}
require_once __DIR__ . '/../utils/secret_box.php';

/** Settings (by app_settings key) whose values are encrypted at rest. */
const TWILIO_SECRET_SETTINGS = ['twilio_auth_token'];

/** Every setting the admin screen manages: app_settings key => .env key. */
const TWILIO_SETTINGS = [
    'twilio_account_sid'             => 'TWILIO_ACCOUNT_SID',
    'twilio_auth_token'              => 'TWILIO_AUTH_TOKEN',
    'twilio_whatsapp_from'           => 'TWILIO_WHATSAPP_FROM',
    'whatsapp_template_digest_sid'   => 'WHATSAPP_TEMPLATE_DIGEST_SID',
    'whatsapp_template_reminder_sid' => 'WHATSAPP_TEMPLATE_REMINDER_SID',
    'whatsapp_enabled'               => 'WHATSAPP_ENABLED',
];

function twilio_env(string $envKey): ?string {
    $v = getenv($envKey);
    if ($v === false) $v = $_ENV[$envKey] ?? '';
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/** The raw app_settings value, or null. */
function twilio_db_value(?PDO $pdo, string $dbKey): ?string {
    if (!$pdo instanceof PDO) return null;
    try {
        $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $st->execute([$dbKey]);
        $v = $st->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) { /* settings table unreadable: treat as unset */ }
    return null;
}

/** ENV first, then app_settings (decrypting where the value is encrypted). */
function twilio_get_setting(?PDO $pdo, string $envKey, string $dbKey): ?string {
    $v = twilio_env($envKey);
    if ($v !== null) return $v;

    $v = twilio_db_value($pdo, $dbKey);
    if ($v === null) return null;
    if (secret_is_encrypted($v)) {
        $pt = secret_decrypt($v, $dbKey);
        return ($pt === null || $pt === '') ? null : $pt;
    }
    // A plain value in a secret setting predates encryption. Still honoured so an
    // existing install keeps sending; the admin screen flags it for re-saving.
    return $v;
}

/**
 * Where a setting's effective value comes from, for the admin screen:
 * 'env' (server .env, cannot be changed from the screen), 'db', 'db_plain' (a
 * secret stored before encryption), 'db_unreadable' (encrypted, but the key is
 * missing or different), or null (not set anywhere).
 */
function twilio_setting_source(?PDO $pdo, string $dbKey): ?string {
    $envKey = TWILIO_SETTINGS[$dbKey] ?? null;
    if ($envKey !== null && twilio_env($envKey) !== null) return 'env';
    $v = twilio_db_value($pdo, $dbKey);
    if ($v === null) return null;
    if (secret_is_encrypted($v)) return secret_decrypt($v, $dbKey) === null ? 'db_unreadable' : 'db';
    return in_array($dbKey, TWILIO_SECRET_SETTINGS, true) ? 'db_plain' : 'db';
}

/**
 * Twilio's API host. TWILIO_API_BASE exists so the test suite can point sends at
 * a local stub; it is never set on a real server.
 */
function twilio_api_base(): string {
    $b = twilio_env('TWILIO_API_BASE');
    return rtrim($b ?? 'https://api.twilio.com', '/');
}

/** Where Twilio should POST delivery updates. Null when the site URL is unknown. */
function whatsapp_status_callback_url(): ?string {
    $base = twilio_env('SITE_URL');
    if ($base === null) {
        $cfgFile = __DIR__ . '/../config/email_config.php';
        if (is_file($cfgFile)) {
            $cfg = require $cfgFile;
            $base = trim((string)($cfg['site_url'] ?? '')) ?: null;
        }
    }
    return $base === null ? null : rtrim($base, '/') . '/api/twilio_status.php';
}

/**
 * Is WhatsApp sending switched on AND fully configured?
 *
 * Deliberately defaults to OFF. The number data in this database is mostly unusable
 * (most students have no dialable number), so an accidental enable would produce a
 * wave of Twilio delivery errors rather than messages. Email is the channel that works.
 */
function whatsapp_enabled(?PDO $pdo = null): bool {
    $flag = strtolower((string)(twilio_get_setting($pdo, 'WHATSAPP_ENABLED', 'whatsapp_enabled') ?? ''));
    if (!in_array($flag, ['1', 'true', 'yes', 'on'], true)) return false;
    return twilio_config($pdo) !== null;
}

/**
 * @return array{sid:string, token:string, from:string}|null  null when incomplete.
 */
function twilio_config(?PDO $pdo = null): ?array {
    $sid   = twilio_get_setting($pdo, 'TWILIO_ACCOUNT_SID',   'twilio_account_sid');
    $token = twilio_get_setting($pdo, 'TWILIO_AUTH_TOKEN',    'twilio_auth_token');
    $from  = twilio_get_setting($pdo, 'TWILIO_WHATSAPP_FROM', 'twilio_whatsapp_from');

    if (!$sid || !$token || !$from) return null;
    if (!str_starts_with($sid, 'AC')) return null;   // an API Key (SK...) will not work here

    return ['sid' => $sid, 'token' => $token, 'from' => whatsapp_normalise_from($from)];
}

/** Accept "+265...", "whatsapp:+265..." or a Messaging Service (MG...). */
function whatsapp_normalise_from(string $from): string {
    $from = preg_replace('/\s+/', '', $from);
    if (!str_starts_with($from, 'MG') && !str_starts_with($from, 'whatsapp:')) {
        $from = 'whatsapp:' . $from;
    }
    return $from;
}

/** Content SID (HX...) for one of our templates, or null if not configured yet. */
function whatsapp_template_sid(?PDO $pdo, string $which): ?string {
    $map = [
        'digest'   => ['WHATSAPP_TEMPLATE_DIGEST_SID',   'whatsapp_template_digest_sid'],
        'reminder' => ['WHATSAPP_TEMPLATE_REMINDER_SID', 'whatsapp_template_reminder_sid'],
    ];
    if (!isset($map[$which])) return null;
    $sid = twilio_get_setting($pdo, $map[$which][0], $map[$which][1]);
    return ($sid && str_starts_with($sid, 'HX')) ? $sid : null;
}

/**
 * One call to Twilio. Returns [http status, decoded json|null, curl error].
 * @param array|null $fields  form fields for a POST; null for a GET
 */
function twilio_request(string $sid, string $token, string $path, ?array $fields = null): array {
    $ch = curl_init(twilio_api_base() . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($fields !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [$http, null, $cerr ?: 'no response'];
    $json = json_decode((string)$body, true);
    return [$http, is_array($json) ? $json : null, ''];
}

/**
 * Check a SID and token against Twilio without sending anything.
 * @return array{ok:bool, message:string}
 */
function twilio_verify_credentials(string $sid, string $token): array {
    if (!preg_match('/^AC[0-9a-f]{32}$/i', $sid)) {
        return ['ok' => false, 'message' => 'The Account SID should be "AC" followed by 32 letters and numbers.'];
    }
    [$http, $json, $cerr] = twilio_request($sid, $token, '/2010-04-01/Accounts/' . rawurlencode($sid) . '.json');
    if ($cerr !== '') return ['ok' => false, 'message' => 'Could not reach Twilio: ' . $cerr];
    if ($http === 200 && is_array($json)) {
        $status = (string)($json['status'] ?? '');
        if ($status !== '' && $status !== 'active') {
            return ['ok' => false, 'message' => 'Twilio says this account is ' . $status . '.'];
        }
        $name = (string)($json['friendly_name'] ?? '');
        return ['ok' => true, 'message' => 'Connected to Twilio' . ($name !== '' ? ' as "' . $name . '"' : '') . '.'];
    }
    if ($http === 401 || $http === 403) {
        return ['ok' => false, 'message' => 'Twilio rejected the Account SID and Auth Token. Check both, and that the token has not been rotated.'];
    }
    return ['ok' => false, 'message' => 'Unexpected answer from Twilio (HTTP ' . $http . ').'];
}

/**
 * Send one templated WhatsApp message.
 *
 * @param string $toE164      recipient, '+...' (phone_to_e164 output)
 * @param string $contentSid  approved template, 'HX...'
 * @param array  $vars        template variables, keyed '1','2',... as Twilio expects
 * @param array  $meta        for the delivery log: user_id, kind, dispatch_key, created_by
 * @return array{success:bool, sid:?string, status:?string, error:?string, error_code:?string, http:?int}
 */
function whatsapp_send_template(?PDO $pdo, string $toE164, string $contentSid, array $vars, array $meta = []): array {
    // Twilio wants the variables as a JSON object of string->string.
    $vars = array_map(static fn($v) => (string)$v, $vars);
    return whatsapp_send($pdo, $toE164, [
        'ContentSid'       => $contentSid,
        'ContentVariables' => json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], $meta + ['template_sid' => $contentSid]);
}

/**
 * Send free text. Only delivered inside the 24 hours after the recipient last
 * messaged the sender (the sandbox join message counts), which is why nothing but
 * the admin test uses it.
 */
function whatsapp_send_text(?PDO $pdo, string $toE164, string $body, array $meta = []): array {
    return whatsapp_send($pdo, $toE164, ['Body' => $body], $meta + ['template_sid' => null]);
}

function whatsapp_send(?PDO $pdo, string $toE164, array $content, array $meta): array {
    $fail = static fn(string $err, ?int $http = null, ?string $code = null) =>
        ['success' => false, 'sid' => null, 'status' => null, 'http' => $http, 'error' => $err, 'error_code' => $code];

    $cfg = twilio_config($pdo);
    if ($cfg === null) {
        return $fail('Twilio is not configured (need Account SID, Auth Token and a sender).');
    }
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $toE164)) {
        return $fail('Recipient is not in E.164 form: ' . $toE164);
    }

    $fields = ['To' => 'whatsapp:' . $toE164] + $content;
    // A Messaging Service is addressed differently from a plain sender number.
    if (str_starts_with($cfg['from'], 'MG')) $fields['MessagingServiceSid'] = $cfg['from'];
    else                                     $fields['From'] = $cfg['from'];
    $cb = whatsapp_status_callback_url();
    if ($cb !== null) $fields['StatusCallback'] = $cb;

    [$http, $json, $cerr] = twilio_request($cfg['sid'], $cfg['token'],
        '/2010-04-01/Accounts/' . rawurlencode($cfg['sid']) . '/Messages.json', $fields);

    if ($cerr !== '') {
        $res = $fail('Network error talking to Twilio: ' . $cerr, $http);
    } elseif (!is_array($json)) {
        $res = $fail('Unreadable response from Twilio (HTTP ' . $http . ')', $http);
    } elseif ($http >= 200 && $http < 300 && !empty($json['sid'])) {
        // 'queued'/'accepted' here means Twilio took it, not that it was delivered.
        $res = ['success' => true, 'sid' => (string)$json['sid'], 'status' => $json['status'] ?? 'queued',
                'http' => $http, 'error' => null, 'error_code' => null];
    } else {
        $code = isset($json['code']) ? (string)$json['code'] : null;
        $msg  = (string)($json['message'] ?? ('HTTP ' . $http));
        if ($code !== null) $msg .= ' (Twilio code ' . $code . ')';
        $res = $fail($msg, $http, $code);
    }

    if ($pdo instanceof PDO) wa_record($pdo, $toE164, $res, $meta);
    return $res;
}

/** Add a row to the delivery log. Never allowed to break a send. */
function wa_record(PDO $pdo, string $toE164, array $res, array $meta): void {
    try {
        $pdo->prepare("INSERT INTO whatsapp_messages
              (provider_sid, user_id, to_e164, kind, template_sid, dispatch_key, status,
               error_code, error_message, created_by, failed_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NULL, NOW()))")
            ->execute([
                $res['sid'],
                isset($meta['user_id']) ? (int)$meta['user_id'] : null,
                $toE164,
                (string)($meta['kind'] ?? 'unknown'),
                $meta['template_sid'] ?? null,
                $meta['dispatch_key'] ?? null,
                $res['success'] ? strtolower((string)($res['status'] ?? 'queued')) : 'failed',
                $res['error_code'],
                $res['success'] ? null : mb_substr((string)$res['error'], 0, 255),
                isset($meta['created_by']) ? (int)$meta['created_by'] : null,
                // NOW() on the database clock, not PHP's: the two differ on this stack.
                $res['success'] ? 1 : 0,
            ]);
    } catch (Throwable $e) {
        error_log('[whatsapp] could not record message: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- status callbacks

/**
 * Validate X-Twilio-Signature: base64(HMAC-SHA1(url . each sorted param name+value,
 * auth token)). This is the only thing standing between the public callback URL
 * and anyone who wants to mark messages as delivered, so it is never skipped.
 */
function twilio_signature_valid(string $token, string $url, array $params, string $signature): bool {
    if ($token === '' || $signature === '') return false;
    ksort($params, SORT_STRING);
    $data = $url;
    foreach ($params as $k => $v) {
        if (is_array($v)) return false;   // Twilio never sends arrays; refuse rather than guess
        $data .= $k . $v;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
    return hash_equals($expected, $signature);
}

/**
 * How far along a message got. Callbacks can arrive out of order ("sent" after
 * "delivered"), so a status only ever replaces a lower-ranked one.
 */
function wa_status_rank(string $status): int {
    return [
        'accepted' => 1, 'queued' => 1, 'scheduled' => 1, 'sending' => 2, 'sent' => 3,
        'failed' => 4, 'undelivered' => 4, 'delivered' => 5, 'read' => 6,
    ][$status] ?? 0;
}

/** Record one status callback and move the message forward if it is news. */
function wa_apply_status(PDO $pdo, string $sid, string $status, ?string $errorCode, ?string $toE164 = null): void {
    $errorCode = ($errorCode !== null && trim($errorCode) !== '') ? trim($errorCode) : null;
    $pdo->prepare("INSERT INTO whatsapp_message_events (provider_sid, status, error_code) VALUES (?, ?, ?)")
        ->execute([$sid, $status, $errorCode]);

    $st = $pdo->prepare("SELECT id, status FROM whatsapp_messages WHERE provider_sid = ? LIMIT 1");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Sent before tracking existed, or by something else on the same account.
        // Recorded anyway so the log is complete.
        $pdo->prepare("INSERT IGNORE INTO whatsapp_messages (provider_sid, to_e164, kind, status) VALUES (?, ?, 'unknown', 'queued')")
            ->execute([$sid, $toE164 ?? '']);
        $st->execute([$sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
    }
    if (wa_status_rank($status) <= wa_status_rank((string)$row['status'])) return;

    $set = ['status = ?'];
    $args = [$status];
    if ($status === 'sent')      $set[] = 'sent_at = COALESCE(sent_at, NOW())';
    if ($status === 'delivered') $set[] = 'delivered_at = COALESCE(delivered_at, NOW())';
    if ($status === 'read')      { $set[] = 'read_at = COALESCE(read_at, NOW())'; $set[] = 'delivered_at = COALESCE(delivered_at, NOW())'; }
    if ($status === 'failed' || $status === 'undelivered') $set[] = 'failed_at = COALESCE(failed_at, NOW())';
    if ($errorCode !== null) {
        $set[] = 'error_code = ?';
        $args[] = $errorCode;
        $set[] = 'error_message = ?';
        $args[] = wa_error_text($errorCode) ?? ('Twilio error ' . $errorCode);
    }
    $args[] = (int)$row['id'];
    $pdo->prepare('UPDATE whatsapp_messages SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
}

/** Plain-language reasons for the failures people actually hit. */
function wa_error_text(?string $code): ?string {
    return [
        '21211' => 'The number is not a valid phone number.',
        '21408' => 'Sending to this country is not enabled on the Twilio account.',
        '21610' => 'This person replied STOP and has opted out of messages.',
        '63003' => 'This number is not on WhatsApp.',
        '63007' => 'The sender number is not set up for WhatsApp on Twilio.',
        '63015' => 'Sandbox only: this person has not sent the join code to the sandbox number.',
        '63016' => 'Plain text is only allowed within 24 hours of the person messaging you. Use a template.',
        '63018' => 'Twilio rate limit reached. Try again shortly.',
        '63024' => 'The recipient is not a valid WhatsApp user.',
        '63049' => 'Meta chose not to deliver this message.',
        '63112' => 'The WhatsApp Business account is restricted or disabled by Meta.',
    ][(string)$code] ?? null;
}
