<?php
/**
 * cron/send_daily_digest.php
 * "Here are your lessons today" — one message per person, each morning.
 *
 * Runs once a day at 07:00 Africa/Blantyre (05:00 UTC). Everyone gets it on the
 * school's clock rather than their own, so there is exactly one run to reason
 * about; the times *inside* the message are still rendered in each reader's own
 * timezone, falling back to the school's for the users who have none set.
 *
 * People with no lessons today are not messaged at all.
 *
 * Usage:
 *   php cron/send_daily_digest.php                # today, for real
 *   php cron/send_daily_digest.php --dry-run      # print what it would send
 *   php cron/send_daily_digest.php --date=2026-09-08 --dry-run
 *   php cron/send_daily_digest.php --only-user=55 # restrict to one recipient
 *
 * Exit codes: 0 fine, 1 nothing to do is still 0, 2 configuration/database failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/lesson_notifications.php';

$opts     = getopt('', ['dry-run', 'date::', 'only-user::', 'quiet']);
$dryRun   = array_key_exists('dry-run', $opts);
$quiet    = array_key_exists('quiet', $opts);
$onlyUser = isset($opts['only-user']) ? (int)$opts['only-user'] : 0;

function dg_log(string $m): void {
    global $quiet;
    if (!$quiet) echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $m . "\n";
}

// One run at a time. Without this a slow SMTP server plus an overlapping cron tick
// would have two processes racing on the same claims; the unique key would save us
// from duplicates, but the second run would burn attempts for nothing.
$lockPath = sys_get_temp_dir() . '/bloom_daily_digest.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    dg_log('Another digest run is already in progress; exiting.');
    exit(0);
}

try {
    $schoolTz = ln_school_tz();
    $tz       = new DateTimeZone($schoolTz);

    $dayLocal = isset($opts['date']) && $opts['date'] !== ''
        ? new DateTimeImmutable($opts['date'] . ' 00:00:00', $tz)
        : (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);

    $fromUtc = $dayLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $toUtc   = $dayLocal->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $dayKey  = $dayLocal->format('Y-m-d');
    $dayLbl  = $dayLocal->format('l j F');

    dg_log("Digest for {$dayKey} ({$schoolTz}) — lessons between {$fromUtc} and {$toUtc} UTC"
           . ($dryRun ? '  [DRY RUN]' : ''));

    $lessons    = ln_lessons_between($pdo, $fromUtc, $toUtc);
    $recipients = ln_group_by_recipient($lessons);

    if ($onlyUser > 0) {
        $recipients = array_filter($recipients, static fn($r) => $r['user_id'] === $onlyUser);
    }

    dg_log(count($lessons) . ' lesson(s) today across ' . count($recipients) . ' recipient(s).');
    if (!$recipients) {
        dg_log('Nothing to send.');
        exit(0);
    }

    $tally = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0, 'optout' => 0, 'off' => 0];

    foreach ($recipients as $r) {
        $msg = ln_digest_email($r, $dayLbl);

        if ($dryRun) {
            dg_log(sprintf('  would email %-32s %-28s "%s" (%d lesson(s), tz %s)',
                $r['email'], $r['first_name'] . ' ' . $r['last_name'], $msg['subject'],
                count($r['lessons']), $r['timezone']));
            foreach ($r['lessons'] as $l) {
                dg_log('      ' . ln_time($l['start_utc'], $r['timezone']) . '  ' . $l['subject'] . ' with ' . $l['with_name']);
            }
            $p = phone_to_e164($r['phone'] ?? null);
            dg_log('      whatsapp: ' . ($p['ok'] ? $p['e164'] . ($p['assumed'] ? '  (country assumed)' : '')
                                                  : 'skip — ' . phone_reason_text($p['reason'])));
            continue;
        }

        $res = ln_dispatch_email($pdo, $r, 'daily_digest', null, $dayKey, $msg);
        $tally[$res] = ($tally[$res] ?? 0) + 1;
        dg_log("  email -> {$r['email']}: {$res}");

        // WhatsApp: a compact one-line version of the same information.
        $lines = [];
        foreach ($r['lessons'] as $l) {
            $lines[] = ln_time($l['start_utc'], $r['timezone']) . ' ' . $l['subject'] . ' with ' . $l['with_name'];
        }
        $waRes = ln_dispatch_whatsapp($pdo, $r, 'daily_digest', null, $dayKey, 'digest', [
            '1' => $r['first_name'],
            '2' => (string)count($r['lessons']),
            '3' => implode('; ', $lines),
            '4' => ln_site_url() . '/' . ($r['role'] === 'tutor' ? 'tutor' : 'student') . '/dashboard.php',
        ]);
        $tally[$waRes] = ($tally[$waRes] ?? 0) + 1;
        if ($waRes !== 'off') dg_log("  whatsapp -> {$r['first_name']}: {$waRes}");
    }

    if (!$dryRun) {
        dg_log(sprintf('Done. sent=%d failed=%d skipped=%d duplicate=%d opted-out=%d whatsapp-off=%d',
            $tally['sent'], $tally['failed'], $tally['skipped'], $tally['duplicate'], $tally['optout'], $tally['off']));
    }
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, '[digest] FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(2);
} finally {
    if (isset($lock) && $lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
}
