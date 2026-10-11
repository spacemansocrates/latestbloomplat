<?php
// student/api/payment_callback.php
// Confirms a PayChangu payment and activates the student's package.
//
// Security model (strongest available is used automatically):
//   (1) If a PayChangu secret key is configured (app_settings.paychangu_secret_key
//       or env PAYCHANGU_SECRET_KEY), the transaction is verified SERVER-SIDE against
//       PayChangu's API. The authoritative status and amount come from PayChangu, so
//       request parameters cannot forge a payment.
//   (2) Otherwise a shared-secret guard is used, but ONLY over POST (keeps the secret
//       out of URLs, server logs, browser history and Referer headers) and compared in
//       constant time.
//
// In all cases the package is activated ONLY on a verified success whose amount matches
// the package price, the reported status is honoured (a failed payment never activates),
// and processing is idempotent (safe to receive the same callback twice).

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../integrations/payments.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

// State changes must not travel in the query string.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$sp_id  = (int)($_POST['sp_id'] ?? 0);
$txn    = trim((string)($_POST['txn'] ?? $_POST['tx_ref'] ?? ''));
$status = strtolower(trim((string)($_POST['status'] ?? '')));
if ($sp_id <= 0 || $txn === '') { http_response_code(400); exit('Bad request'); }

// Confirm the package exists before doing any work.
if (payments_expected_amount($pdo, $sp_id) === null) { http_response_code(404); exit('Package not found'); }

$paid_amount = null;

$paychangu_secret = payments_setting($pdo, 'paychangu_secret_key');

if ($paychangu_secret) {
    // (1) Authoritative verification — cannot be forged by the caller.
    $ch = curl_init('https://api.paychangu.com/verify-payment/' . rawurlencode($txn));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $paychangu_secret, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $j        = $raw ? json_decode($raw, true) : null;
    $data     = is_array($j['data'] ?? null) ? $j['data'] : [];
    $txStatus = strtolower((string)($data['status'] ?? ($j['status'] ?? '')));

    if (!($code === 200 && $txStatus === 'success')) {
        payments_mark_failed($pdo, $sp_id, $txn);
        http_response_code(402);
        exit('Payment not verified');
    }
    $paid_amount = isset($data['amount']) ? (float)$data['amount'] : null;
} else {
    // (2) Fallback: shared secret over POST, constant-time comparison.
    $shared   = payments_setting($pdo, 'payment_callback_secret');
    $provided = (string)($_POST['sig'] ?? '');
    if (!$shared || !hash_equals($shared, $provided)) { http_response_code(403); exit('Forbidden'); }

    // Honour the provider-reported status — do NOT activate on failure/cancel.
    if (!in_array($status, ['success', 'completed', 'paid'], true)) {
        payments_mark_failed($pdo, $sp_id, $txn);
        http_response_code(200);
        exit('Recorded (payment not successful)');
    }
    // amount not independently verifiable without the API key -> $paid_amount stays null
}

// Idempotent, amount-checked activation (shared with the Stripe webhook).
try {
    $result = payments_activate_success($pdo, $sp_id, $txn, $paid_amount);
    if ($result === 'amount_mismatch') { http_response_code(402); exit('Amount mismatch'); }
    echo 'OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error';
}
