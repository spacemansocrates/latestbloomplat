<?php
/**
 * cron/send_lesson_reminders.php
 * "Your lesson starts in 15 minutes" — one message per person per lesson.
 *
 * Runs every minute. The window is every Scheduled lesson starting in the next
 * $LEAD minutes that has not been reminded yet, so:
 *   - a lesson is normally caught on the tick where it is ~15 minutes away, and
 *   - if cron was down for a while, a lesson still gets a late reminder rather
 *     than none, right up until it starts.
 * The claim in notification_dispatch_log is what makes both of those safe: each
 * (lesson, person, channel) can only ever be sent once.
 *
 * This replaces daemons/lesson_notification_daemon.php, which was never scheduled
 * on production, deduplicated with a LIKE that matched lesson #1 against #10, and
 * whose INSERT into `notifications` omitted a NOT NULL column, so it would have
 * thrown on production's STRICT_TRANS_TABLES and re-sent every 60 seconds.
 *
 * Usage:
 *   php cron/send_lesson_reminders.php
 *   php cron/send_lesson_reminders.php --dry-run
 *   php cron/send_lesson_reminders.php --lead=15 --dry-run
 *   php cron/send_lesson_reminders.php --lesson=383 --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/lesson_notifications.php';

$opts    = getopt('', ['dry-run', 'lead::', 'lesson::', 'quiet']);
$dryRun  = array_key_exists('dry-run', $opts);
$quiet   = array_key_exists('quiet', $opts);
$onlyLes = isset($opts['lesson']) ? (int)$opts['lesson'] : 0;
$LEAD    = isset($opts['lead']) && $opts['lead'] !== '' ? max(1, min(180, (int)$opts['lead'])) : 15;

// --quiet exists so the every-minute cron does not write a line on each of the
// ~1400 daily ticks where there is nothing to do. It must never hide an actual
// send: a log that stays empty even while mail is going out is no log at all.
function rm_log(string $m): void {        // routine chatter, silenced by --quiet
    global $quiet;
    if (!$quiet) echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $m . "\n";
}
function rm_report(string $m): void {     // anything that actually happened
    echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $m . "\n";
}

$lockPath = sys_get_temp_dir() . '/bloom_lesson_reminders.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Every-minute cron: a run that is still going is normal, not an error.
    exit(0);
}

try {
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // Inclusive of the top of the window so a lesson is picked up on the tick where
    // it is exactly $LEAD minutes away, and still caught later if that tick was missed.
    $from   = $nowUtc->format('Y-m-d H:i:s');
    $to     = $nowUtc->modify("+{$LEAD} minutes +59 seconds")->format('Y-m-d H:i:s');

    $lessons = ln_lessons_between($pdo, $from, $to);
    if ($onlyLes > 0) {
        $lessons = array_values(array_filter($lessons, static fn($l) => (int)$l['id'] === $onlyLes));
    }

    if (!$lessons) {
        rm_log("No lessons starting in the next {$LEAD} min.");
        exit(0);
    }
    rm_log(count($lessons) . " lesson(s) starting within {$LEAD} min" . ($dryRun ? '  [DRY RUN]' : ''));

    $tally = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0, 'optout' => 0, 'off' => 0];

    foreach ($lessons as $row) {
        // One lesson produces two recipients: the student and the tutor.
        $perLesson  = ln_group_by_recipient([$row]);
        $startUtc   = $row['scheduled_start_time'];
        $minsAway   = (int)max(1, (int)round(
            ((new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->getTimestamp() - $nowUtc->getTimestamp()) / 60
        ));

        foreach ($perLesson as $r) {
            $lesson = $r['lessons'][0];   // exactly one, we passed a single row
            $msg    = ln_reminder_email($r, $lesson, $minsAway);
            $occ    = (string)$lesson['id'];

            if ($dryRun) {
                rm_log(sprintf('  lesson #%d %s: would email %-32s "%s"',
                    $lesson['id'], $r['role'], $r['email'], $msg['subject']));
                $p = phone_to_e164($r['phone'] ?? null);
                rm_log('      whatsapp: ' . ($p['ok'] ? $p['e164'] . ($p['assumed'] ? '  (country assumed)' : '')
                                                      : 'skip — ' . phone_reason_text($p['reason'])));
                continue;
            }

            $res = ln_dispatch_email($pdo, $r, 'lesson_reminder', $lesson['id'], $occ, $msg);
            $tally[$res] = ($tally[$res] ?? 0) + 1;
            $line = "  lesson #{$lesson['id']} {$r['role']} email -> {$r['email']}: {$res}";
            // 'duplicate' is the normal steady state once a lesson has been handled,
            // and an opt-out repeats on every tick of the window.
            if ($res === 'duplicate' || $res === 'optout') rm_log($line); else rm_report($line);

            $waRes = ln_dispatch_whatsapp($pdo, $r, 'lesson_reminder', $lesson['id'], $occ, 'reminder', [
                '1' => $r['first_name'],
                '2' => $lesson['subject'],
                '3' => $lesson['with_name'],
                '4' => ln_time($lesson['start_utc'], $r['timezone']) . ' ' . ln_tz_abbr($lesson['start_utc'], $r['timezone']),
                '5' => ln_join_url($lesson['id'], $r['role']),
            ]);
            $tally[$waRes] = ($tally[$waRes] ?? 0) + 1;
            if ($waRes !== 'off' && $waRes !== 'duplicate' && $waRes !== 'optout') {
                rm_report("  lesson #{$lesson['id']} {$r['role']} whatsapp: {$waRes}");
            }
        }
    }

    if (!$dryRun) {
        $line = sprintf('Done. sent=%d failed=%d skipped=%d duplicate=%d opted-out=%d whatsapp-off=%d',
            $tally['sent'], $tally['failed'], $tally['skipped'], $tally['duplicate'], $tally['optout'], $tally['off']);
        // Worth a line only when something moved; a run of pure duplicates is noise.
        if ($tally['sent'] || $tally['failed'] || $tally['skipped']) rm_report($line); else rm_log($line);
    }
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, '[reminders] FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(2);
} finally {
    if (isset($lock) && $lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
}
