<?php
/**
 * tests/assessment/test_stripe.php
 * Stripe subscriptions -> portal packages, over HTTP against the disposable app:
 * signed webhook events exactly as Stripe sends them, the admin Subscriptions page,
 * and every matching rule. Runs after test_admin.php (attempts 1 and 3 are Sam's and
 * approved; Lucy and Max Park share the parent email jo.park@example.test).
 */

declare(strict_types=1);

require_once __DIR__ . '/../whatsapp/fixtures.php';

$pdo = ct_pdo();
$one = static function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$n = static fn(string $sql, array $args = []): int => (int)$one("SELECT COUNT(*) AS n FROM ($sql) x", $args)['n'];

const WH_SECRET = 'whsec_testsecret0123456789abcdefABCDEF';
$seq = 0;
/** POST one event to the webhook, signed like Stripe does. */
$send = static function (string $type, array $object, ?string $secret = WH_SECRET, ?string $id = null) use (&$seq): array {
    $seq++;
    $body = json_encode(['id' => $id ?? 'evt_test_' . $seq . '_' . bin2hex(random_bytes(4)), 'type' => $type, 'data' => ['object' => $object]]);
    $t = time();
    $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, (string)$secret);
    $ch = curl_init(WA_BASE . '/student/api/stripe_webhook.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: ' . $sig]]);
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $out, json_decode($body, true)['id']];
};
$checkout = static fn(string $sub, ?string $ref, string $email, int $subtotal, int $total, string $invoice, string $name = 'Pat Parent'): array => [
    'id' => 'cs_' . $sub, 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid',
    'subscription' => $sub, 'customer' => 'cus_' . $sub, 'client_reference_id' => $ref, 'invoice' => $invoice,
    'amount_subtotal' => $subtotal, 'amount_total' => $total,
    'customer_details' => ['email' => $email, 'name' => $name],
];
$packages = static fn(int $studentId) => $n("SELECT id FROM student_packages WHERE student_id = ?", [$studentId]);

$ada  = login('ada@example.test');
$mo   = login('mo@example.test');
$tara = login('tara@example.test');

// ---- access, setup
[$s] = http($mo, 'GET', '/admin/subscriptions.php');
check($s === 403, 'a Mini Admin cannot open Subscriptions (money)');
[$s] = http($tara, 'GET', '/admin/subscriptions.php');
check($s === 403, 'a tutor cannot open Subscriptions');
[$s, $page] = http($ada, 'GET', '/admin/subscriptions.php');
check($s === 200 && str_contains($page, '/student/api/stripe_webhook.php') && str_contains($page, 'invoice.payment_failed'), 'the page shows the webhook URL and the events to select');
check(str_contains($page, 'not set yet'), 'no signing secret yet');
check(str_contains($page, 'href="subscriptions.php"'), 'a full admin sees the Subscriptions link');
[, $moPage] = http($mo, 'GET', '/admin/assessments.php');
check(!str_contains($moPage, 'href="subscriptions.php"'), 'a Mini Admin does not');
$csrf = scrape($page, 'csrf');

[$code, $out] = $send('invoice.paid', ['id' => 'in_x', 'subscription' => 'sub_x']);
check($code === 503, 'with no secret saved, the webhook refuses everything (503)');

[, $b] = post_follow($ada, '/admin/subscriptions.php', ['csrf' => $csrf, 'action' => 'secret', 'secret' => 'sk_live_oops']);
check(str_contains($b, 'starts with whsec_'), 'a value that is not a signing secret is refused');
[, $b] = post_follow($ada, '/admin/subscriptions.php', ['csrf' => $csrf, 'action' => 'secret', 'secret' => WH_SECRET]);
$stored = $one("SELECT `value` AS v FROM app_settings WHERE `key` = 'stripe.webhook_secret'")['v'] ?? '';
check(str_contains($b, 'Signing secret saved') && str_starts_with($stored, 'enc:v1:') && !str_contains($stored, 'testsecret'), 'the secret is stored encrypted');
check(str_contains($b, 'ending in') && str_contains($b, 'CDEF') && !str_contains($b, WH_SECRET), 'the page shows only its last four characters');

[$code] = $send('invoice.paid', ['id' => 'in_x', 'subscription' => 'sub_x'], 'whsec_wrong');
check($code === 400, 'a wrongly signed event is rejected (400)');
[$code, $out] = $send('checkout.session.completed', ['id' => 'cs_oneoff', 'mode' => 'payment', 'payment_status' => 'paid']);
check($code === 200 && str_contains($out, 'No package reference'), 'one-off package checkouts still take the original path');

// ---- A: paid from the report email link (client_reference_id), Core, full price
$sam = 2;
$before = $packages($sam);
[$code, $out, $evA] = $send('checkout.session.completed', $checkout('sub_A', 'asm-1', 'pat@example.test', 11900, 11900, 'in_A1'));
$sub = $one("SELECT * FROM stripe_subscriptions WHERE subscription_id = 'sub_A'");
check($code === 200 && str_contains($out, 'granted') && str_contains($out, 'assessment link'), 'checkout from the report link is matched by the assessment');
check($sub && (int)$sub['student_id'] === $sam && (int)$sub['attempt_id'] === 1 && $sub['plan'] === 'core' && $sub['customer_id'] === 'cus_sub_A',
      'the subscription is linked to Sam, attempt 1, Core');
$sp = $one("SELECT sp.*, pk.name AS pkname, p.amount, p.status AS pstatus, p.payment_method, p.provider_session_id
            FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id JOIN packages pk ON pk.id = sp.package_id
            WHERE p.transaction_id = 'in_A1'");
check($packages($sam) === $before + 1 && $sp && $sp['status'] === 'Active' && (int)$sp['total_lessons'] === 8 && (int)$sp['lessons_remaining'] === 8,
      'an Active package of 8 lessons is created');
check($sp && $sp['pkname'] === 'Bloom Core plan' && (float)$sp['amount'] === 119.0 && $sp['pstatus'] === 'Completed'
      && $sp['payment_method'] === 'Stripe' && $sp['provider_session_id'] === 'sub_A', 'with a Completed £119 Stripe payment against it');
check($sp && (strtotime($sp['end_date']) - strtotime($sp['start_date'])) / 86400 == 29, 'the package runs for 30 days');

[$code, $out] = $send('checkout.session.completed', $checkout('sub_A', 'asm-1', 'pat@example.test', 11900, 11900, 'in_A1'), WH_SECRET, $evA);
check($code === 200 && str_contains($out, 'duplicate') && $packages($sam) === $before + 1, 'a redelivered event changes nothing');
[$code, $out] = $send('invoice.paid', ['id' => 'in_A1', 'parent' => ['subscription_details' => ['subscription' => 'sub_A']],
    'billing_reason' => 'subscription_create', 'subtotal' => 11900, 'amount_paid' => 11900, 'customer_email' => 'pat@example.test']);
check($code === 200 && str_contains($out, 'already') && $packages($sam) === $before + 1, "the first invoice's own invoice.paid (new API shape) does not double up");

// renewal
$end = time() + 30 * 86400;
[$code, $out] = $send('invoice.paid', ['id' => 'in_A2', 'subscription' => 'sub_A', 'billing_reason' => 'subscription_cycle',
    'subtotal' => 11900, 'amount_paid' => 11900, 'lines' => ['data' => [['period' => ['start' => time(), 'end' => $end]]]]]);
check($code === 200 && str_contains($out, 'granted') && $packages($sam) === $before + 2, 'a renewal (old API shape) adds the next 30 days');
check($one("SELECT sp.status FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id WHERE p.transaction_id = 'in_A1'")['status'] === 'Expired'
      && $one("SELECT sp.status FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id WHERE p.transaction_id = 'in_A2'")['status'] === 'Active',
      "last period's package expires (unused lessons do not carry over)");
check($one("SELECT current_period_end FROM stripe_subscriptions WHERE subscription_id = 'sub_A'")['current_period_end'] === gmdate('Y-m-d H:i:s', $end), 'the period end is recorded');

[$code, $out] = $send('invoice.payment_failed', ['id' => 'in_A3', 'subscription' => 'sub_A', 'attempt_count' => 2]);
$sub = $one("SELECT * FROM stripe_subscriptions WHERE subscription_id = 'sub_A'");
check($code === 200 && $sub['status'] === 'past_due' && str_contains((string)$sub['last_error'], 'attempt 2') && $packages($sam) === $before + 2,
      'a failed payment marks it past due and adds no lessons');
[$code] = $send('customer.subscription.updated', ['id' => 'sub_A', 'status' => 'active', 'cancel_at_period_end' => true,
    'items' => ['data' => [['current_period_end' => $end]]]]);
$sub = $one("SELECT * FROM stripe_subscriptions WHERE subscription_id = 'sub_A'");
check($code === 200 && $sub['status'] === 'active' && (int)$sub['cancel_at_period_end'] === 1, 'a parent cancelling in the Stripe portal is recorded as "cancels at period end"');
[, $page] = http($ada, 'GET', '/admin/subscriptions.php');
check(str_contains($page, 'Cancels ' . gmdate('j M', $end)) && str_contains($page, 'Sam Student'), 'the admin page shows when it ends');
[$code] = $send('customer.subscription.deleted', ['id' => 'sub_A', 'status' => 'canceled', 'cancel_at_period_end' => false]);
check($code === 200 && $one("SELECT status FROM stripe_subscriptions WHERE subscription_id = 'sub_A'")['status'] === 'canceled'
      && $one("SELECT sp.status FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id WHERE p.transaction_id = 'in_A2'")['status'] === 'Active',
      'when it finally ends, the lessons already paid for stay usable');

// ---- B: siblings share the parent email, no link reference, sibling discount
$lucy = (int)$one("SELECT id FROM users WHERE email = 'jo.park@example.test'")['id'];
[$code, $out] = $send('checkout.session.completed', $checkout('sub_B', null, 'Jo.Park@example.test', 6600, 5940, 'in_B1', 'Jo Park'));
$sub = $one("SELECT * FROM stripe_subscriptions WHERE subscription_id = 'sub_B'");
check($code === 200 && str_contains($out, 'pending') && str_contains($out, 'more than one child') && $sub['student_id'] === null,
      'an email shared by two children is NOT guessed: the payment waits for an admin');
check($sub['plan'] === 'starter' && $sub['pending_invoice_id'] === 'in_B1' && (float)$sub['pending_amount'] === 59.4,
      'the plan is read from the price before the 10% sibling discount; £59.40 is waiting');
[, $page] = http($ada, 'GET', '/admin/subscriptions.php');
check(str_contains($page, 'Not matched to a student') && str_contains($page, 'Paid &pound;59.40, waiting to be linked'), 'the admin page flags it');
[, $b] = post_follow($ada, '/admin/subscriptions.php', ['csrf' => $csrf, 'action' => 'link', 'row_id' => $sub['id'], 'student_id' => '1']);
check(str_contains($b, 'Choose a student account'), 'it cannot be linked to a tutor');
[, $b] = post_follow($ada, '/admin/subscriptions.php', ['csrf' => $csrf, 'action' => 'link', 'row_id' => $sub['id'], 'student_id' => (string)$lucy]);
$pay = $one("SELECT p.amount, sp.total_lessons, sp.student_id FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id WHERE p.transaction_id = 'in_B1'");
check(str_contains($b, 'waiting payment was added as 4 lessons') && $pay && (int)$pay['student_id'] === $lucy && (int)$pay['total_lessons'] === 4 && (float)$pay['amount'] === 59.4,
      'the admin links it to Lucy: 4 lessons, £59.40 recorded');
check($one("SELECT pending_invoice_id FROM stripe_subscriptions WHERE subscription_id = 'sub_B'")['pending_invoice_id'] === null, 'nothing is left waiting');

// ---- C: invoice.paid arrives BEFORE checkout.session.completed
[$code, $out] = $send('invoice.paid', ['id' => 'in_C1', 'subscription' => 'sub_C', 'billing_reason' => 'subscription_create',
    'subtotal' => 21100, 'amount_paid' => 21100, 'customer_email' => 'someone.else@example.test']);
check($code === 200 && str_contains($out, 'pending'), 'an early invoice with no match waits');
[$code, $out] = $send('checkout.session.completed', $checkout('sub_C', 'asm-3', 'someone.else@example.test', 21100, 21100, 'in_C1'));
check($code === 200 && str_contains($out, 'granted') && $n("SELECT id FROM payments WHERE transaction_id = 'in_C1'") === 1
      && (int)$one("SELECT sp.total_lessons FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id WHERE p.transaction_id = 'in_C1'")['total_lessons'] === 16,
      'the checkout then links it (attempt 3) and applies that payment exactly once: Intensive+, 16 lessons');

// ---- D: a price that is not one of the plans
[$code, $out] = $send('checkout.session.completed', $checkout('sub_D', 'asm-1', 'pat@example.test', 5000, 5000, 'in_D1'));
$sub = $one("SELECT * FROM stripe_subscriptions WHERE subscription_id = 'sub_D'");
check($code === 200 && $sub['plan'] === null && str_contains((string)$sub['last_error'], 'does not match any plan') && $sub['pending_invoice_id'] === 'in_D1',
      'an unknown amount is not turned into a guessed number of lessons');
[, $b] = post_follow($ada, '/admin/subscriptions.php', ['csrf' => $csrf, 'action' => 'link', 'row_id' => $sub['id'], 'student_id' => (string)$sam, 'plan' => 'core']);
check(str_contains($b, 'added as 8 lessons'), 'the admin chooses the plan and the lessons are added');

// ---- events that are not ours
[$code, $out] = $send('invoice.paid', ['id' => 'in_E1', 'subtotal' => 1000, 'amount_paid' => 1000]);
check($code === 200 && str_contains($out, 'not a subscription invoice'), 'a one-off invoice is ignored');
[, $page] = http($ada, 'GET', '/admin/subscriptions.php');
check(str_contains($page, 'Recent messages from Stripe') && str_contains($page, 'customer.subscription.deleted'), 'the admin page lists recent events');

finish('assessment stripe');
