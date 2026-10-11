<?php
/**
 * utils/lesson_notifications.php
 * Shared logic behind the two reminder jobs in cron/:
 *   - send_daily_digest.php    "here is your day", once each morning
 *   - send_lesson_reminders.php "starts in 15 minutes", once per lesson
 *
 * Both jobs do the same three things: find the lessons, work out who to tell, and
 * hand each message to every channel that is switched on (email always, WhatsApp
 * only when configured). Everything a job sends is claimed in
 * notification_dispatch_log first, so a re-run, an overlapping run or a retry can
 * never send the same thing twice.
 *
 * TIME COLUMNS. `scheduled_start_time` holds the UTC instant; that is what the
 * lesson room, lesson_sweep and every current INSERT use, and it is what we query
 * on here. `scheduled_start_time_utc` exists too and normally holds the same value,
 * but admin/admin_daily_lessons.php updates the first pair without the second, so
 * it goes stale when an admin edits a lesson. Do not switch these queries to it.
 */

declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/notification_prefs.php';
require_once __DIR__ . '/../integrations/twilio_whatsapp.php';

/** The school's clock: used for the digest day boundaries and as the fallback for
 *  the 43 users whose `timezone` column is an empty string. */
function ln_school_tz(): string {
    $tz = getenv('SCHOOL_TIMEZONE');
    $tz = ($tz === false) ? '' : trim((string)$tz);
    if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) return $tz;
    return 'Africa/Blantyre';
}

/** A recipient's own timezone, or the school's when it is unset or nonsense. */
function ln_user_tz(?string $tz): string {
    $tz = trim((string)$tz);
    if ($tz === '') return ln_school_tz();
    return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : ln_school_tz();
}

function ln_site_url(): string {
    $u = getenv('SITE_URL');
    $u = ($u === false) ? '' : trim((string)$u);
    if ($u === '') {
        $cfg = require __DIR__ . '/../config/email_config.php';
        $u = (string)($cfg['site_url'] ?? '');
    }
    return rtrim($u, '/');
}

/** Deep link into the lesson room for whichever side we are writing to. */
function ln_join_url(int $lessonId, string $role): string {
    $base = ln_site_url();
    return $role === 'tutor'
        ? $base . '/tutor/join_lesson_live.php?lesson_id=' . $lessonId
        : $base . '/student/lesson_room_live.php?lesson_id=' . $lessonId;
}

/** '14:30' in the reader's own zone. */
function ln_time(string $utcDatetime, string $tz): string {
    $d = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone($tz))->format('H:i');
}

/** 'Monday 8 September, 14:30' in the reader's own zone. */
function ln_datetime(string $utcDatetime, string $tz): string {
    $d = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone($tz))->format('l j F, H:i');
}

/** Short zone label so a reader abroad knows which clock we mean. */
function ln_tz_abbr(string $utcDatetime, string $tz): string {
    $d = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone($tz))->format('T');
}

// ---------------------------------------------------------------- queries

/**
 * Every Scheduled lesson starting inside [$fromUtc, $toUtc), with both parties.
 * Ordered by start so a digest reads down the day.
 */
function ln_lessons_between(PDO $pdo, string $fromUtc, string $toUtc): array {
    $sql = "
        SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status,
               subj.name AS subject_name,
               t.id  AS tutor_id,  t.first_name AS tutor_first,  t.last_name AS tutor_last,
               t.email AS tutor_email, t.phone_number AS tutor_phone, t.timezone AS tutor_tz,
               s.id  AS student_id, s.first_name AS student_first, s.last_name AS student_last,
               s.email AS student_email, s.phone_number AS student_phone, s.timezone AS student_tz
        FROM lessons l
        JOIN subjects subj ON subj.id = l.subject_id
        JOIN users t ON t.id = l.tutor_id
        JOIN users s ON s.id = l.student_id
        WHERE l.status = 'Scheduled'
          AND l.scheduled_start_time >= :from
          AND l.scheduled_start_time <  :to
        ORDER BY l.scheduled_start_time ASC, l.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([':from' => $fromUtc, ':to' => $toUtc]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Turn lesson rows into one entry per person, so the digest is "your day"
 * rather than "a lesson". Returns user_id => [user fields, lessons[]].
 */
function ln_group_by_recipient(array $lessons): array {
    $out = [];
    foreach ($lessons as $l) {
        foreach (['student', 'tutor'] as $role) {
            $uid = (int)$l[$role . '_id'];
            if (!isset($out[$uid])) {
                $out[$uid] = [
                    'user_id'    => $uid,
                    'role'       => $role,
                    'first_name' => $l[$role . '_first'],
                    'last_name'  => $l[$role . '_last'],
                    'email'      => $l[$role . '_email'],
                    'phone'      => $l[$role . '_phone'],
                    'timezone'   => ln_user_tz($l[$role . '_tz']),
                    'lessons'    => [],
                ];
            }
            // The other side of this particular lesson is who they will be sitting with.
            $other = $role === 'student' ? 'tutor' : 'student';
            $out[$uid]['lessons'][] = [
                'id'         => (int)$l['id'],
                'subject'    => $l['subject_name'],
                'start_utc'  => $l['scheduled_start_time'],
                'end_utc'    => $l['scheduled_end_time'],
                'with_name'  => trim($l[$other . '_first'] . ' ' . $l[$other . '_last']),
                'with_role'  => $other,
            ];
        }
    }
    return $out;
}

// ---------------------------------------------------------------- the ledger

/**
 * Claim the right to send one message. Returns true when this run should send it.
 *
 * Claim-before-send: the unique key on dedupe_key means a second run (or a second
 * process) gets false rather than sending a duplicate. A previous attempt that
 * failed is retried, up to $maxAttempts, because a transient SMTP hiccup should
 * not silently cost someone their reminder.
 */
function ln_claim(PDO $pdo, string $dedupeKey, int $userId, string $kind,
                  string $channel, ?int $lessonId, int $maxAttempts = 3): bool {
    $st = $pdo->prepare("SELECT status, attempts FROM notification_dispatch_log WHERE dedupe_key = ? LIMIT 1");
    $st->execute([$dedupeKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Already delivered, deliberately skipped, or out of retries.
        if (in_array($row['status'], ['sent', 'skipped'], true)) return false;
        if ((int)$row['attempts'] >= $maxAttempts) return false;
        $up = $pdo->prepare("UPDATE notification_dispatch_log
                             SET status='sending', attempts = attempts + 1
                             WHERE dedupe_key = ? AND status <> 'sent'");
        $up->execute([$dedupeKey]);
        return $up->rowCount() > 0;
    }

    // Every column this table needs is either supplied here or has a default —
    // checked against production's STRICT_TRANS_TABLES, not just the dev copy.
    try {
        $ins = $pdo->prepare("INSERT INTO notification_dispatch_log
              (dedupe_key, user_id, kind, channel, lesson_id, status, attempts)
              VALUES (?, ?, ?, ?, ?, 'sending', 1)");
        $ins->execute([$dedupeKey, $userId, $kind, $channel, $lessonId]);
        return true;
    } catch (PDOException $e) {
        // 23000 = another run inserted the same key a moment ago. Not ours to send.
        if ($e->getCode() === '23000') return false;
        throw $e;
    }
}

function ln_complete(PDO $pdo, string $dedupeKey, string $status,
                     ?string $detail = null, ?string $providerRef = null): void {
    $st = $pdo->prepare("UPDATE notification_dispatch_log
                         SET status = ?, detail = ?, provider_ref = ?
                         WHERE dedupe_key = ?");
    $st->execute([$status, $detail !== null ? mb_substr($detail, 0, 255) : null, $providerRef, $dedupeKey]);
}

/** Record something we chose not to send, so it is visible and not retried forever. */
function ln_mark_skipped(PDO $pdo, string $dedupeKey, int $userId, string $kind,
                         string $channel, ?int $lessonId, string $why): void {
    try {
        $ins = $pdo->prepare("INSERT INTO notification_dispatch_log
              (dedupe_key, user_id, kind, channel, lesson_id, status, attempts, detail)
              VALUES (?, ?, ?, ?, ?, 'skipped', 0, ?)
              ON DUPLICATE KEY UPDATE detail = VALUES(detail)");
        $ins->execute([$dedupeKey, $userId, $kind, $channel, $lessonId, mb_substr($why, 0, 255)]);
    } catch (PDOException $e) { /* logging a skip must never break a run */ }
}

// ---------------------------------------------------------------- email bodies

function ln_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ln_email_shell(string $heading, string $innerHtml): string {
    $heading = ln_esc($heading);
    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,.08);">
<tr><td style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:18px 24px;">
  <h1 style="color:#fff;margin:0;font-size:20px;line-height:1.3;">{$heading}</h1>
</td></tr>
<tr><td style="padding:24px;">{$innerHtml}</td></tr>
<tr><td style="padding:16px 24px;background:#fafafa;border-top:1px solid #eee;">
  <p style="margin:0;color:#888;font-size:12px;line-height:1.5;">
    Bloom Education &middot; You are receiving this because you have lessons booked on the portal.
  </p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

/** "Your lessons today" — one row per lesson, in the reader's own timezone. */
function ln_digest_email(array $recipient, string $dayLabel): array {
    $tz    = $recipient['timezone'];
    $name  = ln_esc($recipient['first_name']);
    $count = count($recipient['lessons']);
    $word  = $count === 1 ? 'lesson' : 'lessons';

    $rows = '';
    foreach ($recipient['lessons'] as $l) {
        $time    = ln_esc(ln_time($l['start_utc'], $tz));
        $subject = ln_esc($l['subject']);
        $withWho = ln_esc($l['with_name']);
        $withLbl = $l['with_role'] === 'tutor' ? 'with' : 'with';
        $join    = ln_esc(ln_join_url($l['id'], $recipient['role']));
        $rows .= <<<ROW
<tr>
  <td style="padding:12px 0;border-bottom:1px solid #eee;">
    <div style="font-size:18px;font-weight:bold;color:#222;">{$time}</div>
    <div style="font-size:15px;color:#333;margin-top:2px;">{$subject}</div>
    <div style="font-size:13px;color:#777;margin-top:2px;">{$withLbl} {$withWho}</div>
    <a href="{$join}" style="display:inline-block;margin-top:8px;font-size:13px;color:#5b4bd6;text-decoration:none;">Open the lesson room &rarr;</a>
  </td>
</tr>
ROW;
    }

    $tzNote = ln_esc(ln_tz_abbr($recipient['lessons'][0]['start_utc'], $tz));
    $inner = <<<INNER
<p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 6px;">Hi <strong>{$name}</strong>,</p>
<p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 18px;">
  You have <strong>{$count} {$word}</strong> today, {$dayLabel}. Times are shown in your timezone ({$tzNote}).
</p>
<table width="100%" cellpadding="0" cellspacing="0">{$rows}</table>
INNER;

    $subject = "Your {$count} {$word} today";
    return [
        'subject' => $subject,
        'html'    => ln_email_shell('Your lessons today', $inner),
        'plain'   => ln_digest_plain($recipient, $dayLabel),
    ];
}

function ln_digest_plain(array $recipient, string $dayLabel): string {
    $tz = $recipient['timezone'];
    $out = "Hi {$recipient['first_name']},\n\n";
    $out .= "You have " . count($recipient['lessons']) . " lesson(s) today, {$dayLabel}.\n";
    $out .= "Times are in your timezone (" . ln_tz_abbr($recipient['lessons'][0]['start_utc'], $tz) . ").\n\n";
    foreach ($recipient['lessons'] as $l) {
        $out .= ln_time($l['start_utc'], $tz) . "  {$l['subject']} with {$l['with_name']}\n";
        $out .= "        " . ln_join_url($l['id'], $recipient['role']) . "\n";
    }
    $out .= "\nBloom Education\n";
    return $out;
}

/** "Starts in 15 minutes" for one lesson. */
function ln_reminder_email(array $recipient, array $lesson, int $minutesAway): array {
    $tz      = $recipient['timezone'];
    $name    = ln_esc($recipient['first_name']);
    $subj    = ln_esc($lesson['subject']);
    $withWho = ln_esc($lesson['with_name']);
    $time    = ln_esc(ln_time($lesson['start_utc'], $tz));
    $tzNote  = ln_esc(ln_tz_abbr($lesson['start_utc'], $tz));
    $join    = ln_esc(ln_join_url($lesson['id'], $recipient['role']));

    $inner = <<<INNER
<p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 6px;">Hi <strong>{$name}</strong>,</p>
<p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 20px;">
  Your <strong>{$subj}</strong> lesson with <strong>{$withWho}</strong> starts at
  <strong>{$time}</strong> ({$tzNote}) &mdash; about {$minutesAway} minutes from now.
</p>
<p style="margin:0 0 24px;">
  <a href="{$join}" style="display:inline-block;background:#5b4bd6;color:#fff;font-size:16px;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:6px;">Join the lesson</a>
</p>
<p style="color:#777;font-size:13px;line-height:1.6;margin:0;word-break:break-all;overflow-wrap:anywhere;">
  If the button does not work, paste this into your browser:<br>{$join}
</p>
INNER;

    $plain = "Hi {$recipient['first_name']},\n\n"
           . "Your {$lesson['subject']} lesson with {$lesson['with_name']} starts at "
           . ln_time($lesson['start_utc'], $tz) . " (" . ln_tz_abbr($lesson['start_utc'], $tz) . ")"
           . " - about {$minutesAway} minutes from now.\n\n"
           . "Join here: " . ln_join_url($lesson['id'], $recipient['role']) . "\n\nBloom Education\n";

    return [
        'subject' => "Starts in {$minutesAway} min: {$lesson['subject']} with {$lesson['with_name']}",
        'html'    => ln_email_shell('Your lesson starts soon', $inner),
        'plain'   => $plain,
    ];
}

// ---------------------------------------------------------------- dispatch

/**
 * Send one message over email, guarded by the ledger.
 * @return string one of 'sent', 'failed', 'skipped', 'duplicate', 'optout'
 */
function ln_dispatch_email(PDO $pdo, array $recipient, string $kind, ?int $lessonId,
                           string $occurrence, array $msg): string {
    // An opt-out writes nothing to the ledger, so turning the channel back on
    // later is honoured from the next message rather than blocked by a 'skipped'.
    if (!np_allowed($pdo, (int)$recipient['user_id'], $kind, 'email')) return 'optout';

    $key = "{$kind}:{$occurrence}:{$recipient['user_id']}:email";

    $to = trim((string)$recipient['email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        ln_mark_skipped($pdo, $key, $recipient['user_id'], $kind, 'email', $lessonId, 'No valid email address on file');
        return 'skipped';
    }
    if (!ln_claim($pdo, $key, $recipient['user_id'], $kind, 'email', $lessonId)) {
        return 'duplicate';
    }

    $res = send_email($to, $recipient['first_name'], $msg['subject'], $msg['html'], $msg['plain']);
    if (!empty($res['success'])) {
        ln_complete($pdo, $key, 'sent');
        return 'sent';
    }
    ln_complete($pdo, $key, 'failed', (string)($res['message'] ?? 'send_email returned failure'));
    return 'failed';
}

/**
 * Send one message over WhatsApp, guarded by the ledger.
 *
 * Silent no-op unless WHATSAPP_ENABLED is on and the template SID is configured —
 * WhatsApp ships switched off because most numbers in this database are unusable.
 * @return string 'sent', 'failed', 'skipped', 'duplicate', 'optout' or 'off'
 */
function ln_dispatch_whatsapp(PDO $pdo, array $recipient, string $kind, ?int $lessonId,
                              string $occurrence, string $template, array $vars): string {
    if (!whatsapp_enabled($pdo)) return 'off';
    $sid = whatsapp_template_sid($pdo, $template);
    if ($sid === null) return 'off';
    if (!np_allowed($pdo, (int)$recipient['user_id'], $kind, 'whatsapp')) return 'optout';

    $key = "{$kind}:{$occurrence}:{$recipient['user_id']}:whatsapp";

    $p = phone_to_e164($recipient['phone'] ?? null);
    if (!$p['ok']) {
        ln_mark_skipped($pdo, $key, $recipient['user_id'], $kind, 'whatsapp', $lessonId,
                        'Unusable number: ' . phone_reason_text($p['reason']));
        return 'skipped';
    }
    if (!ln_claim($pdo, $key, $recipient['user_id'], $kind, 'whatsapp', $lessonId)) {
        return 'duplicate';
    }

    $res = whatsapp_send_template($pdo, $p['e164'], $sid, $vars, [
        'user_id' => (int)$recipient['user_id'], 'kind' => $kind, 'dispatch_key' => $key,
    ]);
    if (!empty($res['success'])) {
        ln_complete($pdo, $key, 'sent', $res['status'] ?? null, $res['sid'] ?? null);
        return 'sent';
    }
    ln_complete($pdo, $key, 'failed', (string)($res['error'] ?? 'unknown Twilio error'));
    return 'failed';
}
