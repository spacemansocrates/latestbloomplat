<?php
// integrations/payments.php
// Provider-agnostic core for confirming payments and activating packages.
// Both confirmation paths — PayChangu (student/api/payment_callback.php) and
// Stripe (student/api/stripe_webhook.php) — funnel through here so activation
// logic lives in exactly one place: idempotent, amount-checked, single source of truth.

declare(strict_types=1);

/** Resolve a payment setting: env first (UPPER_SNAKE), then app_settings. */
function payments_setting(PDO $pdo, string $key): ?string {
    $v = getenv(strtoupper($key));
    if ($v !== false && trim($v) !== '') return trim($v);
    try {
        $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return null;
}

/** The price the student actually owes for a student_package (server-side truth). */
function payments_expected_amount(PDO $pdo, int $sp_id): ?float {
    $s = $pdo->prepare("
        SELECT p.price
        FROM student_packages sp
        JOIN packages p ON p.id = sp.package_id
        WHERE sp.id = ?
        LIMIT 1
    ");
    $s->execute([$sp_id]);
    $v = $s->fetchColumn();
    return $v === false ? null : (float)$v;
}

/** Mark this package's matching/pending payment as Failed. Never activates the package. */
function payments_mark_failed(PDO $pdo, int $sp_id, string $txn): void {
    try {
        if ($txn !== '') {
            $pdo->prepare("UPDATE payments SET status='Failed'
                           WHERE student_package_id=? AND transaction_id=? AND status<>'Completed'")
                ->execute([$sp_id, $txn]);
        } else {
            $pdo->prepare("UPDATE payments SET status='Failed'
                           WHERE student_package_id=? AND status='Pending'")
                ->execute([$sp_id]);
        }
    } catch (Throwable $e) {
        error_log('payments_mark_failed: ' . $e->getMessage());
    }
}

/**
 * Activate a package on a verified successful payment. Idempotent.
 *
 * @param float|null $paid_amount Amount the provider confirms was paid, in MAJOR units
 *                                (e.g. 50.00 — Stripe cents must be /100 before calling).
 *                                Pass null when the amount can't be independently confirmed.
 * @return string one of: 'activated', 'already', 'not_found', 'amount_mismatch'
 */
function payments_activate_success(PDO $pdo, int $sp_id, string $txn, ?float $paid_amount = null): string {
    $expected = payments_expected_amount($pdo, $sp_id);
    if ($expected === null) return 'not_found';

    // Reject underpayment when we have an authoritative amount.
    if ($paid_amount !== null && ($paid_amount + 0.01) < $expected) {
        payments_mark_failed($pdo, $sp_id, $txn);
        return 'amount_mismatch';
    }

    $pdo->beginTransaction();
    try {
        // Locate the payment row: by txn first, else the latest pending for this package.
        $row = null;
        if ($txn !== '') {
            $s = $pdo->prepare("SELECT id, status FROM payments WHERE transaction_id=? AND student_package_id=? LIMIT 1");
            $s->execute([$txn, $sp_id]);
            $row = $s->fetch();
        }
        if (!$row) {
            $s = $pdo->prepare("SELECT id, status FROM payments WHERE student_package_id=? AND status='Pending' ORDER BY payment_date DESC, id DESC LIMIT 1");
            $s->execute([$sp_id]);
            $row = $s->fetch();
        }

        $alreadyDone = $row && $row['status'] === 'Completed';
        if ($row && !$alreadyDone) {
            // Record the DB-derived amount + provider reference — never the caller's number.
            $pdo->prepare("UPDATE payments SET status='Completed', amount=?, transaction_id=? WHERE id=?")
                ->execute([$expected, ($txn !== '' ? $txn : null), (int)$row['id']]);
        }

        $pdo->prepare("UPDATE student_packages SET status='Active' WHERE id=? AND status='Pending'")
            ->execute([$sp_id]);

        $pdo->commit();
        return $alreadyDone ? 'already' : 'activated';
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('payments_activate_success: ' . $e->getMessage());
        throw $e;
    }
}
