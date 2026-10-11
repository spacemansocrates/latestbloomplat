<?php
// integrations/stripe_client.php
// Stripe webhook signature verification (and, for later, Checkout Session creation).
// No SDK/composer dependency — the signature scheme is a documented HMAC.

declare(strict_types=1);

/**
 * Verify a Stripe webhook signature.
 * Implements Stripe's scheme: the `Stripe-Signature` header looks like
 *   t=1614556800,v1=hex_signature[,v1=...]
 * where the signed payload is  "{t}.{raw_body}"  HMAC-SHA256'd with the
 * endpoint's webhook signing secret (whsec_...). Constant-time comparison,
 * plus a timestamp tolerance to reject replays.
 *
 * @param string $payload   RAW request body (must not be re-encoded)
 * @param string $sigHeader Value of the Stripe-Signature header
 * @param string $secret    Endpoint signing secret (whsec_...)
 * @param int    $tolerance Max age in seconds (default 5 min)
 */
function stripe_verify_webhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool {
    if ($payload === '' || $sigHeader === '' || $secret === '') return false;

    $t = null;
    $v1 = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $t = $kv[1];
        elseif ($kv[0] === 'v1') $v1[] = $kv[1];
    }
    if ($t === null || !ctype_digit($t) || !$v1) return false;

    // Reject stale timestamps (replay protection).
    if (abs(time() - (int)$t) > $tolerance) return false;

    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($v1 as $candidate) {
        if (hash_equals($expected, $candidate)) return true;
    }
    return false;
}

/**
 * Create a Stripe Checkout Session (for later dynamic checkout — dormant until
 * STRIPE_SECRET_KEY is configured). Returns the hosted checkout URL, or null.
 * Static Payment Links keep working without this; wire it in when going live.
 *
 * @param array $opts amount_cents, currency, sp_id, success_url, cancel_url, name
 */
function stripe_create_checkout_session(PDO $pdo, string $secretKey, array $opts): ?string {
    $fields = [
        'mode'                                 => 'payment',
        'success_url'                          => (string)($opts['success_url'] ?? ''),
        'cancel_url'                           => (string)($opts['cancel_url'] ?? ''),
        'client_reference_id'                  => (string)($opts['sp_id'] ?? ''),
        'line_items[0][quantity]'              => '1',
        'line_items[0][price_data][currency]'  => strtolower((string)($opts['currency'] ?? 'usd')),
        'line_items[0][price_data][unit_amount]' => (string)(int)($opts['amount_cents'] ?? 0),
        'line_items[0][price_data][product_data][name]' => (string)($opts['name'] ?? 'Tutoring package'),
        'metadata[sp_id]'                      => (string)($opts['sp_id'] ?? ''),
    ];
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { error_log("stripe_create_checkout_session HTTP $code: " . substr((string)$raw, 0, 300)); return null; }
    $j = json_decode((string)$raw, true);
    return $j['url'] ?? null;
}
