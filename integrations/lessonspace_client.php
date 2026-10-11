<?php
// integrations/lessonspace_client.php

function lessonspace_get_api_key(?PDO $pdo = null): ?string {
    // 1) ENV
    $k = getenv('LESSONSPACE_API_KEY');
    if ($k && trim($k) !== '') return trim($k);

    // 2) Constant
    if (defined('LESSONSPACE_API_KEY') && LESSONSPACE_API_KEY) {
        return trim((string)LESSONSPACE_API_KEY);
    }

    // 3) DB app_settings
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'lessonspace_api_key' LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v && trim($v) !== '') return trim($v);
        } catch (Throwable $e) {}
    }

    // 4) Local fallback (DEV only). Replace with empty string in prod.
    $HARDCODED = '4a006f89-4218-4b78-98f0-64ef74c1e255';
    if (trim($HARDCODED) !== '') return trim($HARDCODED);

    return null;
}

// -------------------------------
// HTTP call helper
// -------------------------------
function lessonspace_post(string $endpoint, array $body, string $apiKey, int $timeout = 20): array {
    // TLS config: allow env overrides for CA bundle or (dev-only) skipping verification.
    $caBundle = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE') ?: null;
    $verify   = getenv('LESSONSPACE_STRICT_SSL');
    $verify   = $verify === false ? true : !in_array(strtolower($verify), ['0','false','no'], true);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Organisation ' . $apiKey, // NOTE: "Organisation" (UK spelling)
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    if ($verify && $caBundle && is_file($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }
    $raw      = curl_exec($ch);
    $errNo    = curl_errno($ch);
    $errMsg   = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'        => !$errNo && $httpCode >= 200 && $httpCode < 300,
        'http'      => $httpCode,
        'errNo'     => $errNo,
        'errMsg'    => $errMsg,
        'raw'       => $raw,
        'json'      => $raw !== false ? json_decode($raw, true) : null,
    ];
}

// -------------------------------
// Public API: Launch Space
// -------------------------------
/**
 * Launches (creates/joins) a Lessonspace and returns the launch payload (including client_url).
 *
 * @param PDO|null $pdo For key resolution via DB if needed.
 * @param array $params {
 *   @type string  id             Unique space id (e.g., "lesson-123").
 *   @type string  name           Human-friendly name.
 *   @type array   user           ['name'=>..., 'id'=>..., 'email'=>..., 'leader'=>bool]
 *   @type bool    allow_guests   Default true.
 *   @type bool    record_av      Default true.
 *   @type bool    record_content Default true.
 *   @type array   timeouts       Optional ['not_before'=>ISO8601, 'not_after'=>ISO8601]
 * }
 * @return array  The parsed JSON response from Lessonspace (must include client_url for embedding).
 * @throws RuntimeException If the call fails or no client_url is returned.
 */
function lessonspace_launch(?PDO $pdo, array $params): array {
    $apiKey = lessonspace_get_api_key($pdo);
    if (!$apiKey) {
        throw new RuntimeException('Missing Lessonspace API key');
    }

    // Minimal required fields
    $payload = [
        'id'            => (string)($params['id']   ?? ''),
        'name'          => (string)($params['name'] ?? ''),
        'user'          => (array) ($params['user'] ?? []),
        // sensible defaults, overridable:
        'allow_guests'  => array_key_exists('allow_guests', $params)   ? (bool)$params['allow_guests']   : true,
        'record_av'     => array_key_exists('record_av', $params)      ? (bool)$params['record_av']      : true,
        'record_content'=> array_key_exists('record_content', $params) ? (bool)$params['record_content'] : true,
    ];

    // -------------------------------------------------------------------------
    // WEBHOOK CONFIGURATION (Required to be set on First Launch)
    // -------------------------------------------------------------------------
    
    // 1. Determine your website's base URL automatically
    // Ideally, define a constant APP_URL in your config, otherwise we guess:
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; 
    $baseUrl = "$protocol://$host";
    
    // If you have a global config for site URL, use it here:
    // if (defined('SITE_URL')) { $baseUrl = SITE_URL; }

    // 2. Construct the full URL to the webhook file
    // Assumes lessonspace_webhook.php is in the /integrations/ folder relative to root
    $webhookEndpoint = trim($baseUrl, '/') . '/integrations/lessonspace_webhook.php';

    // 3. Add to Payload
    // Note: Lessonspace ignores this if the Room ID has already been created previously.
    // It only applies to NEW rooms.
    $payload['webhooks'] = [
        'recording' => [
            'ready' => $webhookEndpoint
        ],
        'session' => [
            'end' => $webhookEndpoint
        ]
    ];
    // -------------------------------------------------------------------------

    if (!empty($params['timeouts']) && is_array($params['timeouts'])) {
        $payload['timeouts'] = $params['timeouts'];
    }

    if ($payload['id'] === '' || empty($payload['user']) || empty($payload['user']['name']) || empty($payload['user']['id'])) {
        throw new RuntimeException('Invalid launch params (need id and user{name,id})');
    }

    $endpoint = 'https://api.thelessonspace.com/v2/spaces/launch/'; // trailing slash required
    $res = lessonspace_post($endpoint, $payload, $apiKey);

    if ($res['errNo']) {
        throw new RuntimeException('Network error: '.$res['errMsg']);
    }

    $json = is_array($res['json']) ? $res['json'] : [];
    if ($res['http'] < 200 || $res['http'] >= 300) {
        $detail = $json['detail'] ?? $json['error'] ?? 'Unexpected response';
        throw new RuntimeException("HTTP {$res['http']}: {$detail}");
    }

    if (empty($json['client_url'])) {
        throw new RuntimeException('Launch succeeded but client_url is missing from response');
    }

    return $json; // contains client_url, room_id, session_id, api_base, etc.
}

// -------------------------------
// Helper: deterministic space id per lesson
// -------------------------------
function lessonspace_space_id_for_lesson(int $lessonId): string {
    return "lesson-{$lessonId}";
}
function lessonspace_lesson_id_from_space(string $spaceId): ?int {
    if (preg_match('/^lesson-(\d+)$/', $spaceId, $m)) {
        return (int)$m[1];
    }
    return null;
}
?>