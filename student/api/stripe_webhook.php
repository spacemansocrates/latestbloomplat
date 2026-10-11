<?php
// student/api/stripe_webhook.php
// Stripe webhook receiver. Dormant until a signing secret is configured (server .env
// STRIPE_WEBHOOK_SECRET, or saved encrypted on admin/subscriptions.php).
//
// Handles two kinds of payment:
//   - 30-day plan subscriptions (Payment Links): checkout.session.completed with mode
//     "subscription", invoice.paid, invoice.payment_failed, customer.subscription.updated
//     and .deleted, all handled in integrations/stripe_subscriptions.php. The setup
//     steps are shown on admin/subscriptions.php.
//   - one-off package checkouts (the original path, described below).
//
// To wire up later:
//   1. In the Stripe Dashboard -> Developers -> Webhooks, add an endpoint pointing at
//      https://<your-domain>/student/api/stripe_webhook.php and subscribe to
//      "checkout.session.completed".
//   2. Copy that endpoint's Signing secret (whsec_...) into .env as STRIPE_WEBHOOK_SECRET
//      (or app_settings key 'stripe_webhook_secret').
//   3. Ensure the Checkout Session / Payment Link carries the student_package id in
//      client_reference_id (pay_start.php already appends ?client_reference_id=<sp_id>
//      to Stripe payment links).
// No other change is needed — activation then happens automatically and securely.

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../integrations/payments.php';
require_once __DIR__ . '/../../integrations/stripe_client.php';
require_once __DIR__ . '/../../integrations/stripe_subscriptions.php';

header('Content-Type: text/plain; charset=UTF-8');

// .env STRIPE_WEBHOOK_SECRET, else the encrypted secret saved on admin/subscriptions.php,
// else the older plain app_settings key.
$secret = stripe_sub_webhook_secret($pdo);
if (!$secret) { http_response_code(503); exit('Stripe webhook not configured'); }

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook((string)$payload, (string)$sigHeader, $secret)) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode((string)$payload, true);
if (!is_array($event)) { http_response_code(400); exit('Bad payload'); }

// Subscriptions (the 30-day plans sold through Payment Links) go to their own handler:
// every subscription event, and any checkout whose mode is "subscription". One-off
// package checkouts (mode "payment", client_reference_id = student_package id) keep
// the original path below, unchanged.
$type = (string)($event['type'] ?? '');
$isSubscriptionCheckout = $type === 'checkout.session.completed'
    && (($event['data']['object']['mode'] ?? '') === 'subscription');
if ($isSubscriptionCheckout || in_array($type, ['invoice.paid', 'invoice.payment_failed',
        'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
    try {
        [$code, $result] = stripe_handle_subscription_event($pdo, $event);
        http_response_code($code);
        echo $result;
    } catch (Throwable $e) {
        // 500 makes Stripe retry later; nothing was recorded as handled.
        error_log('stripe_webhook subscription error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Error';
    }
    exit;
}

// We only act on a completed, paid checkout.
if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    exit('Ignored');
}

$session = $event['data']['object'] ?? [];
$sp_id   = (int)($session['client_reference_id'] ?? ($session['metadata']['sp_id'] ?? 0));
$txn     = (string)($session['payment_intent'] ?? ($session['id'] ?? ''));
$paid    = strtolower((string)($session['payment_status'] ?? '')); // 'paid' when settled
$amount  = isset($session['amount_total']) ? ((float)$session['amount_total']) / 100.0 : null; // cents -> major

if ($sp_id <= 0) { http_response_code(200); exit('No package reference'); }

if ($paid !== 'paid') {
    payments_mark_failed($pdo, $sp_id, $txn);
    http_response_code(200);
    exit('Not paid');
}

try {
    $result = payments_activate_success($pdo, $sp_id, $txn, $amount);
    http_response_code(200);
    echo $result; // activated | already | amount_mismatch | not_found
} catch (Throwable $e) {
    error_log('stripe_webhook activate error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
