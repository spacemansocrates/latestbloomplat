<?php
// integrations/livekit_client.php
// LiveKit Cloud access-token minting. Tokens are standard HS256 JWTs, so no
// external library is required (composer is not available in this deployment).

declare(strict_types=1);

// Load environment variables if not already loaded
if (!function_exists('load_env')) {
    $envLoader = __DIR__ . '/../utils/env_loader.php';
    if (is_file($envLoader)) {
        require_once $envLoader;
    }
}
if (function_exists('load_env')) {
    load_env(__DIR__ . '/../.env');
}

/**
 * Resolve a LiveKit setting: ENV first, then app_settings table.
 */
function livekit_get_setting(?PDO $pdo, string $envKey, string $dbKey): ?string {
    $v = getenv($envKey);
    if ($v !== false && trim($v) !== '') return trim($v);

    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
            $st->execute([$dbKey]);
            $v = $st->fetchColumn();
            if ($v && trim((string)$v) !== '') return trim((string)$v);
        } catch (Throwable $e) {}
    }
    return null;
}

/**
 * Returns ['url' => wss://..., 'api_key' => ..., 'api_secret' => ...]
 * @throws RuntimeException when configuration is missing/placeholder.
 */
function livekit_config(?PDO $pdo = null): array {
    $url    = livekit_get_setting($pdo, 'LIVEKIT_URL',        'livekit_url');
    $key    = livekit_get_setting($pdo, 'LIVEKIT_API_KEY',    'livekit_api_key');
    $secret = livekit_get_setting($pdo, 'LIVEKIT_API_SECRET', 'livekit_api_secret');

    if (!$url || !$key || !$secret || str_contains($url, 'YOUR-PROJECT')) {
        throw new RuntimeException(
            'LiveKit is not configured. Set LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET in .env '
            . '(values are on your LiveKit Cloud project settings page).'
        );
    }
    return ['url' => $url, 'api_key' => $key, 'api_secret' => $secret];
}

function livekit_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Mint a LiveKit room access token.
 *
 * @param array $opts {
 *   @type string room       Room name, e.g. "lesson-123" (required)
 *   @type string identity   Unique participant identity, e.g. "tutor-7" (required)
 *   @type string name       Display name shown to the other participant
 *   @type array  metadata   Arbitrary participant metadata (JSON-encoded into the token)
 *   @type int    ttl        Token lifetime in seconds (default 6h — covers long lessons)
 * }
 * @return string signed JWT
 */
function livekit_create_token(?PDO $pdo, array $opts): string {
    $cfg = livekit_config($pdo);

    $room     = (string)($opts['room'] ?? '');
    $identity = (string)($opts['identity'] ?? '');
    if ($room === '' || $identity === '') {
        throw new RuntimeException('livekit_create_token requires room and identity');
    }

    $now = time();
    $claims = [
        'iss'  => $cfg['api_key'],
        'sub'  => $identity,
        'jti'  => $identity,
        'nbf'  => $now - 10,          // small clock-skew allowance
        'iat'  => $now,
        'exp'  => $now + (int)($opts['ttl'] ?? 21600),
        'name' => (string)($opts['name'] ?? $identity),
        'video' => [
            'room'           => $room,
            'roomJoin'       => true,
            'canPublish'     => true,
            'canSubscribe'   => true,
            'canPublishData' => true,  // used later for whiteboard/cursor sync
        ],
    ];
    if (!empty($opts['metadata'])) {
        $claims['metadata'] = json_encode($opts['metadata']);
    }

    $header  = livekit_b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = livekit_b64url(json_encode($claims));
    $sig     = livekit_b64url(hash_hmac('sha256', $header . '.' . $payload, $cfg['api_secret'], true));

    return $header . '.' . $payload . '.' . $sig;
}
