<?php
/**
 * utils/packages.php
 * What a student package is worth over its life: when its term runs, what a payment
 * does to it, which lessons it pays for, and what happens when the term is over.
 *
 * Every path that confirms money or books lessons calls into here, so the rules exist
 * once. Before this file each path did its own partial version:
 *   - nothing ever set a package to Expired, except a Stripe subscription's next
 *     invoice expiring the previous period; an admin-assigned or one-off package
 *     stayed Active forever;
 *   - a payment on an already-Active package (the "Pay Now" renewal pay_start.php
 *     allows) was marked Completed and bought nothing: no new term was created;
 *   - lessons.student_package_id was written NULL by every booking path, so
 *     trg_after_lesson_completed never decremented lessons_remaining and nothing
 *     stopped a student booking past their term or their lesson count.
 *
 * Lifecycle (student_packages.status):
 *   Pending   created by an admin assignment or a student checkout; not usable yet
 *   Active    paid; pays for lessons dated start_date..end_date while lessons remain
 *   Expired   end_date has passed (pkg_expire_ended, run by cron/expire_packages.php)
 *   Cancelled set by an admin on admin_revenue.php
 *
 * Package dates are calendar dates on the school's reference clock
 * (DEFAULT_TIMEZONE, Europe/London), the same clock lessons are scheduled against.
 *
 * lessons_remaining keeps the meaning the database trigger gives it: lessons not yet
 * delivered (total minus Completed and NoShow). Whether another lesson can be BOOKED
 * also counts the ones already scheduled; see pkg_free_lessons().
 */

declare(strict_types=1);

require_once __DIR__ . '/timezone_helper.php';

/** Statuses that hold a slot in a package: booked, running or delivered. */
const PKG_BOOKED_STATUSES = ['Scheduled', 'InProgress', 'Completed', 'NoShow'];
/** Statuses that used a lesson up. A student no-show forfeits the lesson (no catch-up is raised). */
const PKG_USED_STATUSES = ['Completed', 'NoShow'];
/** app_settings key: when '1', booking a student lesson needs a paid package covering its date. */
const PKG_REQUIRE_SETTING = 'packages.require_for_booking';

function pkg_in(array $statuses): string
{
    return "'" . implode("','", $statuses) . "'";
}

/** Today on the school's reference clock. */
function pkg_today(): string
{
    return (new DateTime('now', new DateTimeZone(DEFAULT_TIMEZONE)))->format('Y-m-d');
}

/** The reference-clock date of a UTC lesson start. */
function pkg_lesson_date(string $utcStart): string
{
    $dt = new DateTime($utcStart, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(DEFAULT_TIMEZONE))->format('Y-m-d');
}

/** One term of a package starting on $start: [start, end (inclusive), total_lessons]. */
function pkg_term(int $durationDays, int $lessonsPerWeek, string $start): array
{
    $durationDays = max(1, $durationDays);
    $end = (new DateTime($start))->modify('+' . ($durationDays - 1) . ' days')->format('Y-m-d');
    return [$start, $end, max(1, $lessonsPerWeek * (int)ceil($durationDays / 7))];
}

/** First day a new term can start for this student: today, or the day after their last Active term ends. */
function pkg_next_start(PDO $pdo, int $studentId, int $excludeSpId = 0): string
{
    $today = pkg_today();
    $s = $pdo->prepare("SELECT MAX(end_date) FROM student_packages WHERE student_id = ? AND status = 'Active' AND id <> ?");
    $s->execute([$studentId, $excludeSpId]);
    $last = $s->fetchColumn();
    if ($last && $last >= $today) {
        return (new DateTime((string)$last))->modify('+1 day')->format('Y-m-d');
    }
    return $today;
}

function pkg_load(PDO $pdo, int $spId, bool $forUpdate = false): ?array
{
    $s = $pdo->prepare("SELECT sp.*, p.duration_days, p.lessons_per_week, p.name AS package_name
                        FROM student_packages sp JOIN packages p ON p.id = sp.package_id
                        WHERE sp.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''));
    $s->execute([$spId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * What a Completed payment does to its package. Call inside the transaction that
 * marked the payment Completed, and only when it was not Completed before.
 *
 *   Pending package          -> Active. With $fitTerm (a student paying online) a term
 *                               whose start has slipped into the past, or that overlaps
 *                               a term already paid for, is moved to the next free day,
 *                               so the student gets the full term they paid for. An
 *                               admin confirming keeps the dates the admin chose.
 *   Active/Expired package   -> a renewal: a new term of the same package, starting the
 *                               day after the current one ends (today if it has ended),
 *                               and the payment moves to it. Pass $renew = false to
 *                               record the money against the current term instead
 *                               (an instalment).
 *   Cancelled                -> nothing.
 *
 * @return array{0:string,1:int} [activated|renewed|recorded, student_package id the payment now belongs to]
 */
function pkg_apply_payment(PDO $pdo, int $paymentId, int $spId, bool $fitTerm, bool $renew = true): array
{
    $sp = pkg_load($pdo, $spId, true);
    if (!$sp) return ['recorded', $spId];

    if ($sp['status'] === 'Pending') {
        $start = (string)$sp['start_date'];
        $end = (string)$sp['end_date'];
        $total = (int)$sp['total_lessons'];
        if ($fitTerm) {
            $free = pkg_next_start($pdo, (int)$sp['student_id'], $spId);
            if ($start === '' || $start < $free) {
                [$start, $end, $termTotal] = pkg_term((int)$sp['duration_days'], (int)$sp['lessons_per_week'], $free);
                if ($total <= 0) $total = $termTotal;
            }
        }
        $pdo->prepare("UPDATE student_packages SET status = 'Active', start_date = ?, end_date = ?, total_lessons = ? WHERE id = ?")
            ->execute([$start, $end, $total, $spId]);
        pkg_recount($pdo, $spId);
        return ['activated', $spId];
    }

    if ($renew && in_array($sp['status'], ['Active', 'Expired'], true)) {
        $start = pkg_next_start($pdo, (int)$sp['student_id']);
        if ($sp['end_date'] && (string)$sp['end_date'] >= $start) {
            $start = (new DateTime((string)$sp['end_date']))->modify('+1 day')->format('Y-m-d');
        }
        [$start, $end, $total] = pkg_term((int)$sp['duration_days'], (int)$sp['lessons_per_week'], $start);
        $pdo->prepare("INSERT INTO student_packages (student_id, package_id, start_date, end_date, total_lessons, lessons_remaining, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'Active', UTC_TIMESTAMP())")
            ->execute([(int)$sp['student_id'], (int)$sp['package_id'], $start, $end, $total, $total]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE payments SET student_package_id = ? WHERE id = ?")->execute([$newId, $paymentId]);
        return ['renewed', $newId];
    }

    return ['recorded', $spId];
}

// ------------------------------------------------------------ lessons <-> packages

/** Lessons left to BOOK in a package: total minus everything booked, running or delivered. */
function pkg_free_lessons(PDO $pdo, int $spId, int $excludeLessonId = 0): int
{
    $s = $pdo->prepare("SELECT sp.total_lessons - (SELECT COUNT(*) FROM lessons l
                                                   WHERE l.student_package_id = sp.id AND l.id <> ?
                                                     AND l.status IN (" . pkg_in(PKG_BOOKED_STATUSES) . "))
                        FROM student_packages sp WHERE sp.id = ?");
    $s->execute([$excludeLessonId, $spId]);
    return (int)$s->fetchColumn();
}

/**
 * The Active package covering $date (reference-clock Y-m-d) with a lesson free to book,
 * earliest term first. $includeExpired is for back-filling past lessons only.
 */
function pkg_find_for_date(PDO $pdo, int $studentId, string $date, int $excludeLessonId = 0, bool $includeExpired = false): ?int
{
    $s = $pdo->prepare("SELECT id FROM student_packages
                        WHERE student_id = ? AND status IN (" . ($includeExpired ? "'Active','Expired'" : "'Active'") . ")
                          AND start_date <= ? AND end_date >= ?
                        ORDER BY start_date, id");
    $s->execute([$studentId, $date, $date]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (pkg_free_lessons($pdo, (int)$id, $excludeLessonId) > 0) return (int)$id;
    }
    return null;
}

/** Recompute lessons_remaining (lessons not yet delivered) from the linked lessons. */
function pkg_recount(PDO $pdo, ?int $spId): void
{
    if (!$spId) return;
    $pdo->prepare("UPDATE student_packages sp
                   SET lessons_remaining = GREATEST(sp.total_lessons - (SELECT COUNT(*) FROM lessons l
                       WHERE l.student_package_id = sp.id AND l.status IN (" . pkg_in(PKG_USED_STATUSES) . ")), 0)
                   WHERE sp.id = ?")->execute([$spId]);
}

/**
 * Point a lesson at the package that pays for it, after it is created or edited.
 * A link is kept while it still fits (same student, date inside the term, even if
 * the term has since expired); otherwise the earliest covering package with a free
 * lesson is chosen, or none. Demos (no student) are never linked. Both the old and
 * the new package are recounted.
 *
 * @return int|null the linked student_package id
 */
function pkg_link_lesson(PDO $pdo, int $lessonId, bool $includeExpired = false): ?int
{
    $s = $pdo->prepare("SELECT student_id, student_package_id, scheduled_start_time FROM lessons WHERE id = ?");
    $s->execute([$lessonId]);
    $l = $s->fetch(PDO::FETCH_ASSOC);
    if (!$l) return null;

    $old = $l['student_package_id'] ? (int)$l['student_package_id'] : null;
    $new = null;
    if ($l['student_id']) {
        $date = pkg_lesson_date((string)$l['scheduled_start_time']);
        if ($old) {
            $k = $pdo->prepare("SELECT 1 FROM student_packages WHERE id = ? AND student_id = ? AND start_date <= ? AND end_date >= ?
                                AND status IN ('Active','Expired')");
            $k->execute([$old, (int)$l['student_id'], $date, $date]);
            if ($k->fetchColumn()) $new = $old;
        }
        if (!$new) $new = pkg_find_for_date($pdo, (int)$l['student_id'], $date, $lessonId, $includeExpired);
    }

    if ($new !== $old) {
        $pdo->prepare("UPDATE lessons SET student_package_id = ? WHERE id = ?")->execute([$new, $lessonId]);
        pkg_recount($pdo, $old);
    }
    pkg_recount($pdo, $new);
    return $new;
}

/** After a lesson's status changes: recount the package it belongs to. */
function pkg_recount_for_lesson(PDO $pdo, int $lessonId): void
{
    $s = $pdo->prepare("SELECT student_package_id FROM lessons WHERE id = ?");
    $s->execute([$lessonId]);
    $sp = $s->fetchColumn();
    if ($sp) pkg_recount($pdo, (int)$sp);
}

/** Must a student lesson have a paid package before it can be booked? Off until an admin turns it on. */
function pkg_booking_requires_package(PDO $pdo): bool
{
    try {
        $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ?");
        $s->execute([PKG_REQUIRE_SETTING]);
        return $s->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false;
    }
}

// ------------------------------------------------------------------- term end

/** Active packages whose last day has passed become Expired. @return int how many */
function pkg_expire_ended(PDO $pdo): int
{
    $s = $pdo->prepare("UPDATE student_packages SET status = 'Expired' WHERE status = 'Active' AND end_date < ?");
    $s->execute([pkg_today()]);
    return $s->rowCount();
}

/** Recount every Active/Expired package (lessons can change status on paths that do not recount). */
function pkg_recount_all(PDO $pdo): void
{
    $pdo->exec("UPDATE student_packages sp
                SET lessons_remaining = GREATEST(sp.total_lessons - (SELECT COUNT(*) FROM lessons l
                    WHERE l.student_package_id = sp.id AND l.status IN (" . pkg_in(PKG_USED_STATUSES) . ")), 0)
                WHERE sp.status IN ('Active','Expired')");
}

/**
 * One-off back-fill: link student lessons that have no package yet to the package
 * (Active or Expired) covering their date. Oldest lessons first, so each term's
 * lessons fill it in date order.
 * @return array{0:int,1:int} [linked, still unlinked]
 */
function pkg_link_unlinked(PDO $pdo): array
{
    $ids = $pdo->query("SELECT id FROM lessons
                        WHERE student_package_id IS NULL AND student_id IS NOT NULL
                          AND status IN (" . pkg_in(PKG_BOOKED_STATUSES) . ")
                        ORDER BY scheduled_start_time, id")->fetchAll(PDO::FETCH_COLUMN);
    $linked = 0;
    foreach ($ids as $id) {
        if (pkg_link_lesson($pdo, (int)$id, true)) $linked++;
    }
    return [$linked, count($ids) - $linked];
}
