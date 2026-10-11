<?php
// integrations/stripe_subscriptions.php
//
// Stripe subscriptions (the 30-day plans sold through Payment Links) -> portal packages.
//
// Every paid Stripe invoice becomes one Active student_packages row (the plan's
// lessons, valid for 30 days) plus one Completed payments row (method Stripe,
// transaction_id = the invoice id, provider_session_id = the subscription id). That is
// the same shape as a package an admin assigns by hand, so every existing page that
// shows lessons and payments works unchanged. When a new period starts, the previous
// period's package for that subscription is marked Expired: unused lessons do not
// carry over (the published lesson policy).
//
// Matching a payment to a student, in order:
//   1. client_reference_id "asm-<attempt id>", which the report email's button adds;
//   2. the payer's email, if it is the parent email on exactly ONE child's assessment
//      (siblings share a parent email, so two children means "ask the admin");
//   3. the payer's email, if it is a student's own login email.
// Otherwise the subscription is stored unmatched and its payment waits
// (pending_invoice_id) until an admin links it on admin/subscriptions.php.
//
// The plan comes from the amount BEFORE discounts (so the 10% sibling coupon still
// matches), compared against ASM_PLANS prices. Stripe event ids are recorded in
// stripe_events, so a redelivered event is never applied twice, and each invoice id
// can create at most one package even if two events race (row lock + dedupe check).
//
// Handles both invoice shapes: the older top-level invoice.subscription, and the
// 2025+ API's invoice.parent.subscription_details.subscription.

declare(strict_types=1);

require_once __DIR__ . '/../utils/assessment.php';
require_once __DIR__ . '/../utils/secret_box.php';
require_once __DIR__ . '/payments.php';

const STRIPE_SECRET_SETTING = 'stripe.webhook_secret';

/** Webhook signing secret: server .env first, then the encrypted admin setting, then the legacy plain setting. */
function stripe_sub_webhook_secret(PDO $pdo): ?string
{
    $env = getenv('STRIPE_WEBHOOK_SECRET');
    if ($env !== false && trim($env) !== '') return trim($env);
    try {
        $st = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ?');
        $st->execute([STRIPE_SECRET_SETTING]);
        $v = $st->fetchColumn();
        if (is_string($v) && $v !== '' && secret_is_encrypted($v)) {
            $plain = secret_decrypt($v, STRIPE_SECRET_SETTING);
            if ($plain !== null && $plain !== '') return $plain;
        }
    } catch (Throwable $e) {
        error_log('[stripe] reading webhook secret: ' . $e->getMessage());
    }
    return payments_setting($pdo, 'stripe_webhook_secret');
}

/** Plan key for an amount in pence, before discounts. */
function stripe_plan_for_amount(?int $pence): ?string
{
    if ($pence === null) return null;
    foreach (ASM_PLANS as $key => $p) {
        if ((int)round($p['price'] * 100) === $pence) return $key;
    }
    return null;
}

/** The packages row for a plan, created on first use with the same columns admin_revenue.php writes. */
function stripe_plan_package_id(PDO $pdo, string $plan): int
{
    $p = ASM_PLANS[$plan];
    $name = 'Bloom ' . $p['name'] . ' plan';
    $st = $pdo->prepare('SELECT id FROM packages WHERE name = ? ORDER BY id LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare('INSERT INTO packages (name, description, lessons_per_week, price, duration_days, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$name, $p['lessons'] . ' one-hour 1-to-1 lessons every 30 days (Stripe subscription)', $p['per_week'], $p['price'], 30]);
    return (int)$pdo->lastInsertId();
}

/** @return array{0:?int,1:?int,2:string} student id, attempt id, how it was matched */
function stripe_sub_match_student(PDO $pdo, ?string $ref, ?string $email): array
{
    if ($ref && preg_match('/^asm-(\d+)$/', $ref, $m)) {
        $st = $pdo->prepare('SELECT id, student_id FROM assessment_attempts WHERE id = ?');
        $st->execute([(int)$m[1]]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return [(int)$r['student_id'], (int)$r['id'], 'assessment link'];
    }
    $email = strtolower(trim((string)$email));
    if ($email !== '') {
        $st = $pdo->prepare('SELECT student_id, MAX(id) AS attempt_id FROM assessment_attempts WHERE LOWER(parent_email) = ? GROUP BY student_id');
        $st->execute([$email]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) return [(int)$rows[0]['student_id'], (int)$rows[0]['attempt_id'], 'parent email'];
        if (count($rows) > 1) return [null, null, 'parent email matches more than one child'];
        $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND role = 'Student' LIMIT 1");
        $st->execute([$email]);
        if ($sid = $st->fetchColumn()) return [(int)$sid, null, 'student email'];
    }
    return [null, null, 'no match'];
}

function stripe_sub_find(PDO $pdo, string $subId): ?array
{
    $st = $pdo->prepare('SELECT * FROM stripe_subscriptions WHERE subscription_id = ?');
    $st->execute([$subId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Insert or update one subscription row with the given columns; returns the fresh row. */
function stripe_sub_upsert(PDO $pdo, string $subId, array $fields): array
{
    $allowed = ['customer_id', 'student_id', 'attempt_id', 'plan', 'status', 'cancel_at_period_end', 'current_period_end',
                'customer_email', 'customer_name', 'pending_invoice_id', 'pending_amount', 'last_error'];
    $fields = array_intersect_key($fields, array_flip($allowed));
    $cols = array_keys($fields);
    $sql = 'INSERT INTO stripe_subscriptions (subscription_id' . ($cols ? ', ' . implode(', ', $cols) : '') . ') VALUES (?'
         . str_repeat(', ?', count($cols)) . ')'
         . ' ON DUPLICATE KEY UPDATE ' . ($cols ? implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)) : 'subscription_id = subscription_id');
    $pdo->prepare($sql)->execute(array_merge([$subId], array_values($fields)));
    return stripe_sub_find($pdo, $subId);
}

/**
 * Turn one paid invoice into a package, once. Needs the row to know its student and plan;
 * otherwise the payment is parked on the row for an admin to link.
 * @return string granted | already | pending
 */
function stripe_apply_payment(PDO $pdo, array $row, string $invoiceId, float $amountPaid): string
{
    if (!$row['student_id'] || !isset(ASM_PLANS[(string)$row['plan']])) {
        $st = $pdo->prepare("SELECT 1 FROM payments WHERE transaction_id = ? AND payment_method = 'Stripe' LIMIT 1");
        $st->execute([$invoiceId]);
        if ($st->fetchColumn()) return 'already';
        $pdo->prepare('UPDATE stripe_subscriptions SET pending_invoice_id = ?, pending_amount = ? WHERE id = ?')
            ->execute([$invoiceId, $amountPaid, $row['id']]);
        return 'pending';
    }

    $plan = ASM_PLANS[$row['plan']];
    $packageId = stripe_plan_package_id($pdo, (string)$row['plan']);   // outside the transaction: may insert
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM stripe_subscriptions WHERE id = ? FOR UPDATE')->execute([$row['id']]);
        $st = $pdo->prepare("SELECT 1 FROM payments WHERE transaction_id = ? AND payment_method = 'Stripe' LIMIT 1");
        $st->execute([$invoiceId]);
        if ($st->fetchColumn()) {
            $pdo->commit();
            return 'already';
        }

        // The previous period ends when a new one is paid: unused lessons do not carry over.
        $pdo->prepare("UPDATE student_packages SET status = 'Expired'
                       WHERE status = 'Active' AND id IN (SELECT student_package_id FROM payments WHERE provider_session_id = ? AND payment_method = 'Stripe')")
            ->execute([$row['subscription_id']]);

        $pdo->prepare("INSERT INTO student_packages (student_id, package_id, start_date, end_date, total_lessons, lessons_remaining, status, created_at)
                       VALUES (?, ?, UTC_DATE(), UTC_DATE() + INTERVAL 29 DAY, ?, ?, 'Active', UTC_TIMESTAMP())")
            ->execute([(int)$row['student_id'], $packageId, $plan['lessons'], $plan['lessons']]);
        $spId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO payments (student_package_id, amount, payment_date, payment_method, status, transaction_id, provider_session_id, notes, confirmed_by_admin_id)
                       VALUES (?, ?, UTC_TIMESTAMP(), 'Stripe', 'Completed', ?, ?, ?, NULL)")
            ->execute([$spId, $amountPaid, $invoiceId, $row['subscription_id'], 'Stripe subscription: ' . $plan['name'] . ' plan']);

        $pdo->prepare("UPDATE stripe_subscriptions SET pending_invoice_id = NULL, pending_amount = NULL, last_error = NULL
                       WHERE id = ? AND (pending_invoice_id IS NULL OR pending_invoice_id = ?)")
            ->execute([$row['id'], $invoiceId]);
        $pdo->commit();
        return 'granted';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function stripe_id_of($v): ?string
{
    if (is_string($v) && $v !== '') return $v;
    if (is_array($v) && !empty($v['id'])) return (string)$v['id'];
    return null;
}

function stripe_ts(?int $ts): ?string
{
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

/** The subscription id on an invoice, in either API shape. */
function stripe_invoice_subscription(array $inv): ?string
{
    return stripe_id_of($inv['subscription'] ?? null)
        ?? stripe_id_of($inv['parent']['subscription_details']['subscription'] ?? null);
}

/**
 * Handle one verified subscription-related event.
 * @return array{0:int,1:string} HTTP status for Stripe, and a short result for the log.
 *         Exceptions propagate: the caller answers 500 so Stripe retries later.
 */
function stripe_handle_subscription_event(PDO $pdo, array $event): array
{
    $eventId = (string)($event['id'] ?? '');
    $type = (string)($event['type'] ?? '');
    $obj = (array)($event['data']['object'] ?? []);

    if ($eventId !== '') {
        $st = $pdo->prepare('SELECT result FROM stripe_events WHERE event_id = ?');
        $st->execute([$eventId]);
        if (($prev = $st->fetchColumn()) !== false) return [200, 'duplicate event, already handled: ' . $prev];
    }

    $result = 'ignored';
    switch ($type) {
        case 'checkout.session.completed':
            $subId = stripe_id_of($obj['subscription'] ?? null);
            if (($obj['mode'] ?? '') !== 'subscription' || !$subId) { $result = 'ignored: not a subscription checkout'; break; }
            $email = $obj['customer_details']['email'] ?? ($obj['customer_email'] ?? null);
            $row = stripe_sub_find($pdo, $subId);
            $fields = [
                'customer_id'    => stripe_id_of($obj['customer'] ?? null),
                'customer_email' => $email,
                'customer_name'  => $obj['customer_details']['name'] ?? null,
            ];
            $how = 'already linked';
            if (!$row || !$row['student_id']) {
                [$sid, $aid, $how] = stripe_sub_match_student($pdo, $obj['client_reference_id'] ?? null, $email);
                if ($sid) { $fields['student_id'] = $sid; $fields['attempt_id'] = $aid; }
            }
            $plan = stripe_plan_for_amount(isset($obj['amount_subtotal']) ? (int)$obj['amount_subtotal'] : null);
            if ($plan) $fields['plan'] = $plan;
            elseif (!$row || !$row['plan']) $fields['last_error'] = 'Amount ' . ((int)($obj['amount_subtotal'] ?? 0) / 100) . ' does not match any plan price';
            if (!$row) $fields['status'] = 'active';
            $row = stripe_sub_upsert($pdo, $subId, $fields);

            if (($obj['payment_status'] ?? '') === 'paid') {
                $invoice = stripe_id_of($obj['invoice'] ?? null) ?? (string)$obj['id'];
                // A pending payment parked by an earlier invoice.paid is applied now that we know the student.
                if ($row['pending_invoice_id'] && $row['pending_invoice_id'] !== $invoice) {
                    stripe_apply_payment($pdo, $row, (string)$row['pending_invoice_id'], (float)$row['pending_amount']);
                    $row = stripe_sub_find($pdo, $subId);
                }
                $r = stripe_apply_payment($pdo, $row, $invoice, ((int)($obj['amount_total'] ?? 0)) / 100);
                $result = "checkout: $r (matched by $how)";
            } else {
                $result = 'checkout: not paid yet (matched by ' . $how . ')';
            }
            break;

        case 'invoice.paid':
            $subId = stripe_invoice_subscription($obj);
            if (!$subId) { $result = 'ignored: not a subscription invoice'; break; }
            $row = stripe_sub_find($pdo, $subId);
            $fields = ['status' => 'active', 'last_error' => null];
            if (!$row) {
                // Can arrive before checkout.session.completed; match by email for now.
                [$sid, $aid] = stripe_sub_match_student($pdo, null, $obj['customer_email'] ?? null);
                $fields += ['customer_id' => stripe_id_of($obj['customer'] ?? null), 'customer_email' => $obj['customer_email'] ?? null,
                            'customer_name' => $obj['customer_name'] ?? null];
                if ($sid) { $fields['student_id'] = $sid; $fields['attempt_id'] = $aid; }
            }
            if (!$row || !$row['plan']) {
                $plan = stripe_plan_for_amount(isset($obj['subtotal']) ? (int)$obj['subtotal'] : null);
                if ($plan) $fields['plan'] = $plan;
            }
            $periodEnd = $obj['lines']['data'][0]['period']['end'] ?? null;
            if ($periodEnd) $fields['current_period_end'] = stripe_ts((int)$periodEnd);
            $row = stripe_sub_upsert($pdo, $subId, $fields);
            $r = stripe_apply_payment($pdo, $row, (string)$obj['id'], ((int)($obj['amount_paid'] ?? 0)) / 100);
            $result = 'invoice paid: ' . $r;
            break;

        case 'invoice.payment_failed':
            $subId = stripe_invoice_subscription($obj);
            if (!$subId) { $result = 'ignored: not a subscription invoice'; break; }
            stripe_sub_upsert($pdo, $subId, ['status' => 'past_due',
                'last_error' => 'Payment failed' . (isset($obj['attempt_count']) ? ' (attempt ' . (int)$obj['attempt_count'] . ')' : '')]);
            $result = 'payment failed recorded';
            break;

        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $subId = (string)($obj['id'] ?? '');
            if ($subId === '') break;
            $end = $obj['current_period_end'] ?? ($obj['items']['data'][0]['current_period_end'] ?? null);
            $fields = [
                'status' => $type === 'customer.subscription.deleted' ? 'canceled' : (string)($obj['status'] ?? 'active'),
                'cancel_at_period_end' => !empty($obj['cancel_at_period_end']) ? 1 : 0,
            ];
            if ($end) $fields['current_period_end'] = stripe_ts((int)$end);
            if (!stripe_sub_find($pdo, $subId)) $fields['customer_id'] = stripe_id_of($obj['customer'] ?? null);
            stripe_sub_upsert($pdo, $subId, $fields);
            $result = 'subscription ' . $fields['status'] . ($fields['cancel_at_period_end'] ? ', cancels at period end' : '');
            break;
    }

    if ($eventId !== '') {
        $pdo->prepare('INSERT IGNORE INTO stripe_events (event_id, type, result) VALUES (?, ?, ?)')
            ->execute([$eventId, mb_substr($type, 0, 80), mb_substr($result, 0, 255)]);
    }
    return [200, $result];
}

/**
 * Admin action: link an unmatched subscription to a student (and set the plan if the
 * amount did not identify it), then apply any payment that was waiting.
 */
function stripe_sub_link(PDO $pdo, int $rowId, int $studentId, ?string $plan): string
{
    $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'Student'");
    $st->execute([$studentId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Choose a student account.');
    $st = $pdo->prepare('SELECT * FROM stripe_subscriptions WHERE id = ?');
    $st->execute([$rowId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Subscription not found.');
    if ($plan !== null && $plan !== '' && !isset(ASM_PLANS[$plan])) throw new RuntimeException('Choose a plan from the list.');

    $fields = ['student_id' => $studentId];
    if ($plan) $fields['plan'] = $plan;
    $row = stripe_sub_upsert($pdo, (string)$row['subscription_id'], $fields);
    if (!isset(ASM_PLANS[(string)$row['plan']])) return 'Linked. Choose the plan too, so the waiting payment can be turned into lessons.';
    if ($row['pending_invoice_id']) {
        $r = stripe_apply_payment($pdo, $row, (string)$row['pending_invoice_id'], (float)$row['pending_amount']);
        return $r === 'granted' ? 'Linked, and the waiting payment was added as ' . ASM_PLANS[$row['plan']]['lessons'] . ' lessons.' : 'Linked.';
    }
    return 'Linked. Future payments will be added automatically.';
}
