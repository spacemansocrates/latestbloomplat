<?php
// admin_messaging_notifications.php — direct messages, broadcasts, and the
// notification oversight table.
//
// Three views over one page:  ?view=messages | broadcast | notifications
//
// What was wrong with the previous version, in short:
//   - Four POST forms (Broadcast Announcement, Generate Payment Due, Generate
//     Package Expiry, Send Schedule Change) had no handler anywhere in the file.
//     Clicking Publish did nothing at all — the page simply re-rendered.
//   - render_homework_detail() selected homework_submissions.submitted_at, a
//     column that does not exist (it is submission_date), so every
//     HomeworkAssigned / HomeworkGraded notification detail threw and the modal
//     read "Failed to load details."
//   - The type filter offered 'LowRating', which is not in the notifications.type
//     enum and therefore can never match a row, while omitting the seven types
//     that do exist — including QualityControlAssignment, the second most common.
//   - public_url() rewrote '../api/recording_url.php?lesson_id=N' into
//     '/uploads/api/recording_url.php?lesson_id=N', 404ing every R2-backed
//     recording embedded in a notification.
//   - Lesson times were printed straight from the UTC column, an hour out.
//   - Mark Read / Mark Unread wrote to any notification, including the 144 that
//     belong to students and tutors, silently hiding their alerts from them.

require_once __DIR__ . '/../config/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id      = (int)($_SESSION['user_id'] ?? 0);
$user_role    = $_SESSION['user_role'] ?? '';
$current_page = 'messaging';

require_once __DIR__ . '/../utils/timezone_helper.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function json_out($arr) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($arr);
    exit;
}

// ---------------------------------------------------------------- DB
// The old page hand-built a PDO from the DB_* constants and left DB_PORT out of
// the DSN, then died with the raw driver message on failure.
try {
    $pdo = getDbConnection();
    // messages.sent_at, notifications.created_at and lessons.scheduled_start_time
    // are all UTC instants; MySQL renders TIMESTAMP in the session timezone, so
    // pin it rather than inherit whatever the host is set to.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    error_log('admin_messaging DB error: ' . $e->getMessage());
    http_response_code(503);
    die('Messaging is temporarily unavailable. Please try again shortly.');
}

// ------------------------------------------------------------- Authorize
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
    $st = $pdo->prepare("SELECT admin_role FROM admin_details WHERE user_id = ?");
    $st->execute([$user_id]);
    $row = $st->fetch();
    $is_admin = $row && in_array($row['admin_role'], ['Super Admin','Admin','Academic Coordinator'], true);
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// ------------------------------------------------------------- CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
function csrf_ok(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

// ------------------------------------------------------------- Constants
// The real notifications.type enum. The old filter listed 'LowRating' (which the
// column cannot hold) and omitted everything from CatchupRequested onwards.
const NOTIF_TYPES = [
    'NewMessage', 'PaymentDue', 'PackageExpiry', 'ScheduleChange',
    'HomeworkAssigned', 'HomeworkGraded',
    'CatchupRequested', 'CatchupProposed', 'CatchupAccepted', 'CatchupExpired',
    'QualityControlAssignment', 'QualityControlReport', 'QualityControlFlag',
];
const AUDIENCES     = ['All', 'Tutors', 'Students'];   // announcements.target_audience
const MSG_MAX       = 4000;    // messages.content is TEXT; this is a sane typing limit
const TITLE_MAX     = 255;     // announcements.title
const ANNOUNCE_MAX  = 8000;
const PER_PAGE      = 50;
const THREAD_MAX    = 500;
const RENOTIFY_DAYS = 3;       // do not send the same nudge to the same person twice inside this window

$adminTz  = getUserTimezone();
$adminTzO = new DateTimeZone($adminTz);

/** A UTC datetime string rendered in the admin's zone. */
function when(?string $utc, string $fmt = 'M j, Y g:i a'): string {
    if (!$utc) return '—';
    try { return utcToLocal($utc, $GLOBALS['adminTz'])->format($fmt); }
    catch (Throwable $e) { return (string)$utc; }
}
/** "3 days ago" / "just now" — for how stale a pending thing is. */
function ago(?string $utc): string {
    if (!$utc) return '';
    try {
        $d = (new DateTime('now', new DateTimeZone('UTC')))->diff(new DateTime($utc, new DateTimeZone('UTC')));
        if ($d->days > 0)  return $d->days . ($d->days === 1 ? ' day ago' : ' days ago');
        if ($d->h > 0)     return $d->h . ($d->h === 1 ? ' hour ago' : ' hours ago');
        if ($d->i > 0)     return $d->i . ' min ago';
        return 'just now';
    } catch (Throwable $e) { return ''; }
}
function plural(int $n, string $one, ?string $many = null): string {
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}
function display_name(?array $u): string {
    if (!$u) return 'Unknown user';
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n !== '' ? $n : ('User #' . (int)($u['id'] ?? 0));
}
function user_info(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    $q = $pdo->prepare("SELECT id, first_name, last_name, role, is_active FROM users WHERE id = ? LIMIT 1");
    $q->execute([$id]);
    $u = $q->fetch() ?: null;
    if ($u) $u['name'] = display_name($u);
    return $cache[$id] = $u;
}

/**
 * A URL that will actually play a recording.
 *
 * The old public_url() prepended "uploads/" to anything that was not absolute,
 * which turned the R2 gateway path '../api/recording_url.php?lesson_id=N' —
 * 8 of the 28 recordings on record — into '/uploads/api/recording_url.php?...'
 * and 404'd it. Anything not already an absolute URL goes through the gateway,
 * which is what admin/recordings.php does.
 */
function recording_url(array $rec, int $lesson_id): string {
    foreach ([$rec['playback_url'] ?? '', $rec['file_url'] ?? ''] as $u) {
        $u = trim((string)$u);
        if ($u !== '' && preg_match('#^https?://#i', $u)) return $u;
    }
    return $lesson_id > 0 ? '../api/recording_url.php?lesson_id=' . $lesson_id : '';
}

// ------------------------------------------------------------- View + filters
$VIEWS = ['messages', 'broadcast', 'notifications'];
$view  = $_GET['view'] ?? 'messages';
if (!in_array($view, $VIEWS, true)) $view = 'messages';
$current_subpage = $view;

$with_user_id = max(0, (int)($_GET['with'] ?? 0));
$nf_type      = in_array($_GET['type'] ?? '', NOTIF_TYPES, true) ? $_GET['type'] : '';
$nf_user      = max(0, (int)($_GET['user_id'] ?? 0));
$nf_unread    = ($_GET['unread'] ?? '') === '1';
$nf_mine      = ($_GET['mine'] ?? '') === '1';
$page_no      = max(1, (int)($_GET['p'] ?? 1));
$q_search     = trim((string)($_GET['q'] ?? ''));
if (strlen($q_search) > 120) $q_search = substr($q_search, 0, 120);

function qs(array $over = []): string {
    $base = [
        'view' => $GLOBALS['view'], 'with' => $GLOBALS['with_user_id'],
        'type' => $GLOBALS['nf_type'], 'user_id' => $GLOBALS['nf_user'],
        'unread' => $GLOBALS['nf_unread'] ? '1' : '', 'mine' => $GLOBALS['nf_mine'] ? '1' : '',
        'q' => $GLOBALS['q_search'],
    ];
    $merged = array_merge($base, $over);
    $merged = array_filter($merged, function ($v) { return $v !== '' && $v !== 0 && $v !== null && $v !== '0'; });
    return 'admin_messaging_notifications.php?' . http_build_query($merged);
}

// ==================================================== Notification detail
// notifications.related_entity_id is polymorphic — it means a different table for
// each type — so nothing enforces it. 51 of the 161 rows on record (32%) point at
// something that has since been deleted. The old page rendered those as an empty
// modal; each renderer below returns an explicit "no longer exists" panel instead.

function panel(string $inner, string $extraClass = ''): string {
    return '<div class="mt-3 bg-gray-950/40 border border-gray-700 rounded-lg p-3 ' . $extraClass . '">' . $inner . '</div>';
}
function kv(string $k, ?string $v, bool $strong = false): string {
    $val = ($v === null || $v === '') ? '<span class="text-gray-600">&mdash;</span>' : h($v);
    return '<div><span class="text-gray-500">' . h($k) . ':</span> <span class="text-gray-200'
         . ($strong ? ' font-semibold' : '') . '">' . $val . '</span></div>';
}
function gone(string $what, int $id): string {
    return panel('<p class="text-amber-300 text-sm font-semibold">' . h($what) . ' #' . $id . ' no longer exists.</p>'
        . '<p class="text-xs text-amber-200/60 mt-1">The notification was sent, then the record it pointed at was deleted. '
        . 'Nothing links them, so the reference cannot be repaired &mdash; the notification itself is all that survives.</p>',
        'border-amber-500/30 bg-amber-500/5');
}

function render_recording_block(PDO $pdo, int $lesson_id): string {
    if ($lesson_id <= 0) return '';
    $q = $pdo->prepare("SELECT file_url, playback_url, duration_seconds, file_size_bytes, recorded_at, status, provider
                        FROM lesson_recordings WHERE lesson_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1");
    $q->execute([$lesson_id]);
    $rec = $q->fetch();
    if (!$rec) return '';
    $url = recording_url($rec, $lesson_id);
    if ($url === '') return '';

    $dur = (int)($rec['duration_seconds'] ?? 0);
    $durTxt = $dur > 0 ? intdiv($dur, 60) . 'm ' . str_pad((string)($dur % 60), 2, '0', STR_PAD_LEFT) . 's' : '—';
    $b = (int)($rec['file_size_bytes'] ?? 0);
    $sizeTxt = '—';
    if ($b > 0) { $u = ['B','KB','MB','GB','TB']; $i = 0; $v = $b; while ($v >= 1024 && $i < 4) { $v /= 1024; $i++; }
                  $sizeTxt = sprintf(($v < 10 && $i > 0) ? '%.1f %s' : '%.0f %s', $v, $u[$i]); }

    if (($rec['status'] ?? '') !== 'Ready') {
        return panel('<p class="font-semibold text-white mb-1">Recording</p>'
            . '<p class="text-sm text-gray-400">Not playable yet &mdash; status is ' . h((string)$rec['status']) . '.</p>');
    }
    // Absolute provider links (Zoom) open in a tab; gateway URLs stream inline.
    $isExternal = preg_match('#^https?://#i', $url);
    $media = $isExternal
        ? '<a href="' . h($url) . '" target="_blank" rel="noopener" class="text-indigo-300 underline break-all">Open recording at ' . h((string)($rec['provider'] ?: 'provider')) . '</a>'
        : '<video src="' . h($url) . '" controls preload="none" class="w-full rounded-lg bg-black/40"></video>';

    return panel('<p class="font-semibold text-white mb-2">Recording</p>' . $media
        . '<div class="mt-2 grid grid-cols-3 gap-2 text-xs text-gray-400">'
        . '<div><span class="text-gray-500">Length:</span> ' . h($durTxt) . '</div>'
        . '<div><span class="text-gray-500">Size:</span> ' . h($sizeTxt) . '</div>'
        . '<div><span class="text-gray-500">Recorded:</span> ' . h(when($rec['recorded_at'] ?? null, 'M j, Y')) . '</div>'
        . '</div>');
}

function render_lesson_block(PDO $pdo, int $lesson_id, string $label = 'Lesson'): string {
    $q = $pdo->prepare("SELECT l.id, l.status, l.lesson_type, l.scheduled_start_time, l.scheduled_end_time,
                               CONCAT(t.first_name,' ',t.last_name) tutor_name,
                               CONCAT(s.first_name,' ',s.last_name) student_name,
                               subj.name subject_name
                        FROM lessons l
                        LEFT JOIN users t ON t.id = l.tutor_id
                        LEFT JOIN users s ON s.id = l.student_id
                        LEFT JOIN subjects subj ON subj.id = l.subject_id
                        WHERE l.id = ? LIMIT 1");
    $q->execute([$lesson_id]);
    $L = $q->fetch();
    if (!$L) return gone($label, $lesson_id);

    // The old page printed scheduled_start_time straight from the column and ran
    // date() over it in Africa/Blantyre — the stored value is a UTC instant, so
    // every time in this modal was an hour out for a London admin.
    $whenTxt = when($L['scheduled_start_time']) . ' &ndash; ' . when($L['scheduled_end_time'], 'g:i a');

    return panel(
        '<div class="flex items-start justify-between gap-2">'
        . '<p class="text-white font-semibold">Lesson #' . (int)$L['id'] . ' &middot; ' . h($L['subject_name'] ?: 'No subject') . '</p>'
        . '<a href="admin_lessons.php?view=list&q=' . (int)$L['id'] . '" class="text-xs text-indigo-300 hover:text-indigo-200 whitespace-nowrap">Open in Lessons &rarr;</a>'
        . '</div>'
        . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
        . kv('Tutor', $L['tutor_name'])
        . kv('Student', $L['student_name'] ?: 'None (demo)')
        . '<div class="sm:col-span-2"><span class="text-gray-500">When:</span> <span class="text-gray-200">' . $whenTxt
        . '</span> <span class="text-xs text-gray-500">(' . h($GLOBALS['adminTz']) . ')</span></div>'
        . kv('Status', $L['status'])
        . kv('Type', $L['lesson_type'])
        . '</div>'
    );
}

function render_chat(PDO $pdo, int $a, int $b, int $highlight = 0): string {
    if ($a <= 0 || $b <= 0) return '';
    $ua = user_info($pdo, $a); $ub = user_info($pdo, $b);
    $MAX = 400;
    $q = $pdo->prepare("SELECT id, sender_id, content, sent_at FROM messages
                        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
                        ORDER BY id DESC LIMIT $MAX");
    $q->execute([$a, $b, $b, $a]);
    $msgs = array_reverse($q->fetchAll());   // newest-first query, oldest-first display

    $head = '<div class="flex items-center justify-between gap-3">'
          . '<p class="text-white font-semibold">Conversation</p>'
          . '<a href="' . h(qs(['view' => 'messages', 'with' => ($ua && $ua['role'] !== 'Administrator') ? $a : $b, 'p' => null])) . '" class="text-xs text-indigo-300 hover:text-indigo-200">Open thread &rarr;</a>'
          . '</div>'
          . '<p class="text-xs text-gray-500 mt-0.5">' . h(display_name($ua)) . ' and ' . h(display_name($ub))
          . ' &middot; ' . (count($msgs) >= $MAX ? 'last ' . $MAX : plural(count($msgs), 'message')) . '</p>';

    if (!$msgs) return panel($head . '<p class="text-sm text-gray-400 mt-3">No messages between these two.</p>');

    $body = '<div class="mt-3 max-h-80 overflow-auto pr-1 space-y-2">';
    foreach ($msgs as $m) {
        $isA  = ((int)$m['sender_id'] === $a);
        $hl   = ($highlight > 0 && (int)$m['id'] === $highlight) ? ' ring-2 ring-amber-400/70' : '';
        $body .= '<div class="flex ' . ($isA ? 'justify-start' : 'justify-end') . '">'
              . '<div class="max-w-[80%] px-3 py-2 ' . ($isA ? 'bg-gray-800 border border-gray-700 text-gray-100 rounded-2xl rounded-tl-md' : 'bg-indigo-600 text-white rounded-2xl rounded-tr-md') . $hl . '">'
              . '<div class="text-[11px] opacity-80 mb-1">' . h(display_name($isA ? $ua : $ub)) . '</div>'
              . '<div class="text-sm whitespace-pre-wrap break-words">' . h($m['content']) . '</div>'
              . '<div class="text-[11px] opacity-70 mt-1">' . h(when($m['sent_at'], 'M j, g:i a')) . '</div>'
              . '</div></div>';
    }
    return panel($head . $body . '</div>');
}

function render_homework(PDO $pdo, int $homework_id): string {
    $q = $pdo->prepare("SELECT h.*, s.first_name s_first, s.last_name s_last, t.first_name t_first, t.last_name t_last
                        FROM homework h
                        LEFT JOIN users s ON s.id = h.student_id
                        LEFT JOIN users t ON t.id = h.tutor_id
                        WHERE h.id = ? LIMIT 1");
    $q->execute([$homework_id]);
    $H = $q->fetch();
    if (!$H) return gone('Homework', $homework_id);

    $out = '<p class="text-white font-semibold text-base">' . h($H['title']) . '</p>'
         . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
         . kv('Student', trim(($H['s_first'] ?? '') . ' ' . ($H['s_last'] ?? '')))
         . kv('Tutor',   trim(($H['t_first'] ?? '') . ' ' . ($H['t_last'] ?? '')))
         . kv('Due',     $H['due_date'] ? when($H['due_date'], 'M j, Y g:i a') : null)
         . kv('Set',     when($H['created_at'], 'M j, Y'))
         . '</div>';
    if (!empty($H['description'])) {
        $out .= '<div class="mt-3 text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($H['description']) . '</div>';
    }

    // The old query selected homework_submissions.submitted_at — a column that has
    // never existed. It is submission_date, and the resulting "Unknown column"
    // exception took down every homework notification detail on the page.
    $sq = $pdo->prepare("SELECT hs.id, hs.student_id, hs.submission_date, hs.status, hs.grade, hs.tutor_feedback, hs.graded_at, hs.content
                         FROM homework_submissions hs WHERE hs.homework_id = ?
                         ORDER BY hs.submission_date DESC, hs.id DESC LIMIT 10");
    $sq->execute([$homework_id]);
    $subs = $sq->fetchAll();

    $out .= '<div class="mt-4"><p class="text-sm font-semibold text-white mb-2">'
          . ($subs ? plural(count($subs), 'submission') : 'Submissions') . '</p>';
    if (!$subs) {
        $out .= '<p class="text-sm text-gray-400">Nothing submitted yet.</p>';
    } else {
        foreach ($subs as $s) {
            $badge = ['Graded' => 'bg-emerald-500/10 text-emerald-300', 'Late' => 'bg-amber-500/10 text-amber-300'][$s['status']] ?? 'bg-gray-700 text-gray-300';
            $out .= '<div class="bg-gray-900 border border-gray-700 rounded-lg p-3 text-sm mb-2">'
                  . '<div class="flex items-center justify-between gap-3">'
                  . '<span class="text-xs font-medium py-0.5 px-2 rounded-full ' . $badge . '">' . h((string)$s['status']) . '</span>'
                  . '<span class="text-xs text-gray-400">' . h(when($s['submission_date'], 'M j, Y g:i a')) . '</span>'
                  . '</div>'
                  . '<div class="mt-2 grid grid-cols-2 gap-2 text-xs">'
                  . kv('Grade', $s['grade'] !== null ? rtrim(rtrim(number_format((float)$s['grade'], 2), '0'), '.') : null)
                  . kv('Graded', $s['graded_at'] ? when($s['graded_at'], 'M j, Y') : null)
                  . '</div>'
                  . (!empty($s['content'])        ? '<div class="mt-2 text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($s['content']) . '</div>' : '')
                  . (!empty($s['tutor_feedback']) ? '<div class="mt-2 border-l-2 border-indigo-500/50 pl-2 text-sm text-gray-300 whitespace-pre-wrap break-words"><span class="text-xs text-gray-500 block">Tutor feedback</span>' . h($s['tutor_feedback']) . '</div>' : '')
                  . '</div>';
        }
    }
    return panel($out . '</div>');
}

function render_notif_detail(PDO $pdo, array $n): string {
    $type = (string)$n['type'];
    $rel  = $n['related_entity_id'] === null ? 0 : (int)$n['related_entity_id'];
    $rows = '';

    if ($rel === 0 && $type !== 'NewMessage') {
        $rows = panel('<p class="text-sm text-gray-400">This notification carries no reference to a record, so there is nothing further to show.</p>');
    } elseif ($type === 'NewMessage') {
        if ($rel === 0) {
            $rows = panel('<p class="text-sm text-gray-400">This notification has no message id attached &mdash; 26 of the NewMessage rows on record were written without one, so the conversation cannot be identified.</p>');
        } else {
            $q = $pdo->prepare("SELECT id, sender_id, receiver_id, content, sent_at FROM messages WHERE id = ? LIMIT 1");
            $q->execute([$rel]);
            $M = $q->fetch();
            if (!$M) { $rows = gone('Message', $rel); }
            else {
                $rows = panel(
                    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
                    . kv('From', display_name(user_info($pdo, (int)$M['sender_id'])))
                    . kv('To',   display_name(user_info($pdo, (int)$M['receiver_id'])))
                    . kv('Sent', when($M['sent_at']))
                    . kv('Message ID', '#' . (int)$M['id'])
                    . '</div>'
                    . '<div class="mt-2 text-gray-200 whitespace-pre-wrap break-words border-l-2 border-amber-400/60 pl-3">' . h($M['content']) . '</div>'
                ) . render_chat($pdo, (int)$M['sender_id'], (int)$M['receiver_id'], (int)$M['id']);
            }
        }
    } elseif ($type === 'PaymentDue' || $type === 'PackageExpiry') {
        $q = $pdo->prepare("SELECT sp.*, pk.name pkg_name, pk.price, u.first_name, u.last_name,
                                   (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.student_package_id=sp.id AND p.status='Completed') paid
                            FROM student_packages sp
                            JOIN packages pk ON pk.id = sp.package_id
                            JOIN users u ON u.id = sp.student_id
                            WHERE sp.id = ? LIMIT 1");
        $q->execute([$rel]);
        $sp = $q->fetch();
        if (!$sp) { $rows = gone('Student package', $rel); }
        else {
            $rows = panel(
                '<div class="flex items-start justify-between gap-2">'
                . '<p class="text-white font-semibold">' . h($sp['pkg_name']) . '</p>'
                . '<a href="admin_revenue.php?view=payments&student_id=' . (int)$sp['student_id'] . '" class="text-xs text-indigo-300 hover:text-indigo-200 whitespace-nowrap">Open in Revenue &rarr;</a>'
                . '</div>'
                . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
                . kv('Student', trim($sp['first_name'] . ' ' . $sp['last_name']))
                . kv('Status', $sp['status'])
                . kv('Runs', $sp['start_date'] . ' to ' . $sp['end_date'])
                . kv('Lessons left', $sp['lessons_remaining'] . ' of ' . $sp['total_lessons'])
                . kv('Paid', number_format((float)$sp['paid'], 2) . ' of ' . number_format((float)$sp['price'], 2))
                . '</div>'
            );
        }
    } elseif ($type === 'ScheduleChange') {
        $rows = render_lesson_block($pdo, $rel) . render_recording_block($pdo, $rel);
    } elseif ($type === 'HomeworkAssigned') {
        $rows = render_homework($pdo, $rel);
    } elseif ($type === 'HomeworkGraded') {
        // related_entity_id is a homework_submissions.id here; fall back to homework.id.
        $q = $pdo->prepare("SELECT homework_id FROM homework_submissions WHERE id = ? LIMIT 1");
        $q->execute([$rel]);
        $hid = $q->fetchColumn();
        $rows = $hid ? render_homework($pdo, (int)$hid) : gone('Graded submission', $rel);
    } elseif (str_starts_with($type, 'Catchup')) {
        $q = $pdo->prepare("SELECT c.*, s.first_name s_first, s.last_name s_last, t.first_name t_first, t.last_name t_last
                            FROM catchup_requests c
                            LEFT JOIN users s ON s.id = c.student_id
                            LEFT JOIN users t ON t.id = c.tutor_id
                            WHERE c.id = ? LIMIT 1");
        $q->execute([$rel]);
        $c = $q->fetch();
        if (!$c) { $rows = gone('Catch-up request', $rel); }
        else {
            $rows = panel(
                '<p class="text-white font-semibold">Catch-up request #' . (int)$c['id'] . '</p>'
                . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
                . kv('Student', trim(($c['s_first'] ?? '') . ' ' . ($c['s_last'] ?? '')))
                . kv('Tutor',   trim(($c['t_first'] ?? '') . ' ' . ($c['t_last'] ?? '')))
                . kv('Raised because', $c['reason'])
                . kv('Status', $c['status'], true)
                . kv('Opened', when($c['created_at'], 'M j, Y'))
                . '</div>'
            ) . render_lesson_block($pdo, (int)$c['original_lesson_id'], 'Original lesson');
        }
    } elseif ($type === 'QualityControlAssignment') {
        $q = $pdo->prepare("SELECT a.*, q.first_name q_first, q.last_name q_last, b.first_name b_first, b.last_name b_last
                            FROM quality_control_assignments a
                            LEFT JOIN users q ON q.id = a.qc_user_id
                            LEFT JOIN users b ON b.id = a.assigned_by_admin_id
                            WHERE a.id = ? LIMIT 1");
        $q->execute([$rel]);
        $a = $q->fetch();
        if (!$a) { $rows = gone('QC assignment', $rel); }
        else {
            $rows = panel(
                '<div class="flex items-start justify-between gap-2">'
                . '<p class="text-white font-semibold">QC assignment #' . (int)$a['id'] . '</p>'
                . '<a href="qc_assignment.php" class="text-xs text-indigo-300 hover:text-indigo-200 whitespace-nowrap">Open QC &rarr;</a>'
                . '</div>'
                . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
                . kv('Reviewer', trim(($a['q_first'] ?? '') . ' ' . ($a['q_last'] ?? '')))
                . kv('Assigned by', trim(($a['b_first'] ?? '') . ' ' . ($a['b_last'] ?? '')))
                . kv('Status', $a['status'], true)
                . kv('Due', $a['due_at'] ? when($a['due_at'], 'M j, Y g:i a') : null)
                . '</div>'
                . (!empty($a['notes']) ? '<div class="mt-2 text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($a['notes']) . '</div>' : '')
            ) . render_lesson_block($pdo, (int)$a['lesson_id'], 'Lesson under review')
              . render_recording_block($pdo, (int)$a['lesson_id']);
        }
    } elseif ($type === 'QualityControlReport' || $type === 'QualityControlFlag') {
        $q = $pdo->prepare("SELECT r.*, q.first_name q_first, q.last_name q_last
                            FROM quality_control_reviews r
                            LEFT JOIN users q ON q.id = r.qc_user_id
                            WHERE r.id = ? LIMIT 1");
        $q->execute([$rel]);
        $r = $q->fetch();
        if (!$r) { $rows = gone('QC review', $rel); }
        else {
            $flagCls = ($r['behaviour_flag'] ?? 'None') === 'SeriousConcern' ? 'text-rose-300 font-semibold'
                     : (($r['behaviour_flag'] ?? '') === 'MinorIssue' ? 'text-amber-300' : 'text-gray-200');
            $rows = panel(
                '<p class="text-white font-semibold">QC review #' . (int)$r['id'] . '</p>'
                . '<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">'
                . kv('Reviewer', trim(($r['q_first'] ?? '') . ' ' . ($r['q_last'] ?? '')))
                . kv('Overall', $r['overall_rating'] !== null ? $r['overall_rating'] . ' / 5' : null, true)
                . '<div><span class="text-gray-500">Behaviour:</span> <span class="' . $flagCls . '">' . h((string)($r['behaviour_flag'] ?: 'None')) . '</span></div>'
                . kv('Audio / video', (((int)($r['audio_ok'] ?? 0)) ? 'audio ok' : 'audio poor') . ' &middot; ' . (((int)($r['video_ok'] ?? 0)) ? 'video ok' : 'video poor'))
                . '</div>'
                . (!empty($r['comments_to_admin'])  ? '<div class="mt-3"><p class="text-xs text-gray-500">To admin</p><p class="text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($r['comments_to_admin']) . '</p></div>' : '')
                . (!empty($r['comments_to_tutor'])  ? '<div class="mt-2"><p class="text-xs text-gray-500">To tutor</p><p class="text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($r['comments_to_tutor']) . '</p></div>' : '')
            ) . render_lesson_block($pdo, (int)$r['lesson_id'], 'Lesson reviewed')
              . render_recording_block($pdo, (int)$r['lesson_id']);
        }
    } else {
        $rows = panel('<p class="text-sm text-gray-400">No detail view is defined for the type "' . h($type) . '".</p>');
    }

    $recipient = user_info($pdo, (int)$n['user_id']);
    $isMine    = ((int)$n['user_id'] === $GLOBALS['user_id']);

    return '<div class="space-y-1">'
        . '<div class="flex items-start justify-between gap-3">'
        . '<div><h4 class="text-white text-lg font-semibold">' . h(preg_replace('/(?<!^)([A-Z])/', ' $1', $type)) . '</h4>'
        . '<p class="text-xs text-gray-400">' . h(when($n['created_at'])) . ' &middot; notification #' . (int)$n['id'] . '</p></div>'
        . '<div class="text-right text-xs">'
        . '<p class="text-gray-500">Sent to</p>'
        . '<p class="' . ($isMine ? 'text-emerald-300' : 'text-amber-300') . ' font-semibold">' . h(display_name($recipient)) . ($isMine ? ' (you)' : '') . '</p>'
        . '<p class="text-gray-600">' . h((string)($recipient['role'] ?? '')) . '</p>'
        . '</div></div>'
        . (!empty($n['message']) ? '<p class="mt-2 text-sm text-gray-300 whitespace-pre-wrap break-words">' . h($n['message']) . '</p>' : '')
        . $rows . '</div>';
}

// ==================================================== Nudge candidates
// PaymentDue and PackageExpiry are real members of the notifications.type enum and
// the old page had forms for both — with no handler behind them, so nothing was
// ever sent. They are implemented here, but because they message real students,
// nothing fires without an explicit confirm on a list you have seen first.

/** Who currently qualifies for a nudge, and who already had one recently. */
function nudge_candidates(PDO $pdo, string $kind, int $days): array {
    // Bounds are computed in PHP rather than as "INTERVAL ? DAY", which binds a
    // placeholder into a syntax position the driver will not always accept.
    $today   = (new DateTime('now', $GLOBALS['adminTzO']))->format('Y-m-d');
    $until   = (new DateTime('now', $GLOBALS['adminTzO']))->modify("+$days days")->format('Y-m-d');
    $olderTh = (new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days")->format('Y-m-d H:i:s');
    $recentTh= (new DateTime('now', new DateTimeZone('UTC')))->modify('-' . RENOTIFY_DAYS . ' days')->format('Y-m-d H:i:s');

    if ($kind === 'payment_due') {
        // One row per student package, not per payment. rel_id is the package,
        // because that is what the PaymentDue detail view resolves against — and a
        // package with three pending payments would otherwise send the same person
        // the same reminder three times in one batch.
        $sql = "SELECT sp.id rel_id, sp.student_id user_id,
                       SUM(pay.amount) amount, COUNT(*) n_payments, MIN(pay.payment_date) since,
                       u.first_name, u.last_name, u.is_active, pk.name pkg_name
                FROM payments pay
                JOIN student_packages sp ON sp.id = pay.student_package_id
                JOIN users u  ON u.id  = sp.student_id
                JOIN packages pk ON pk.id = sp.package_id
                WHERE pay.status = 'Pending' AND pay.payment_date <= ?
                GROUP BY sp.id, sp.student_id, u.first_name, u.last_name, u.is_active, pk.name
                ORDER BY MIN(pay.payment_date) ASC";
        $args = [$olderTh];
        $type = 'PaymentDue';
    } else {
        // An active package ending inside `days` days.
        $sql = "SELECT sp.id rel_id, sp.end_date since, sp.student_id user_id, sp.lessons_remaining,
                       u.first_name, u.last_name, u.is_active, pk.name pkg_name
                FROM student_packages sp
                JOIN users u  ON u.id  = sp.student_id
                JOIN packages pk ON pk.id = sp.package_id
                WHERE sp.status = 'Active' AND sp.end_date BETWEEN ? AND ?
                ORDER BY sp.end_date ASC";
        $args = [$today, $until];
        $type = 'PackageExpiry';
    }
    $s = $pdo->prepare($sql);
    $s->execute($args);
    $rows = $s->fetchAll();

    // Skip anyone who already got this exact nudge recently, and anyone deactivated.
    $dedupe = $pdo->prepare("SELECT COUNT(*) FROM notifications
                             WHERE user_id = ? AND type = ? AND related_entity_id = ? AND created_at >= ?");
    $out = [];
    foreach ($rows as $r) {
        $dedupe->execute([(int)$r['user_id'], $type, (int)$r['rel_id'], $recentTh]);
        $r['recent'] = (int)$dedupe->fetchColumn() > 0;
        $r['skip']   = $r['recent'] || !(int)$r['is_active'];
        $r['reason'] = !(int)$r['is_active'] ? 'account deactivated'
                     : ($r['recent'] ? 'already nudged in the last ' . RENOTIFY_DAYS . ' days' : '');
        $out[] = $r;
    }
    return ['type' => $type, 'rows' => $out];
}

// ==================================================== AJAX endpoints
if (isset($_GET['ajax'])) {
    $a = (string)$_GET['ajax'];

    if ($a === 'poll_thread') {
        $with  = max(0, (int)($_GET['with'] ?? 0));
        $after = max(0, (int)($_GET['after_id'] ?? 0));
        if ($with <= 0) json_out(['ok' => false, 'error' => 'Missing conversation.']);

        // The two-way OR must be bracketed, or AND binds tighter and the id filter
        // applies to only half the conversation.
        $q = $pdo->prepare("SELECT id, sender_id, content, sent_at FROM messages
                            WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))
                              AND id > ? ORDER BY id ASC LIMIT 200");
        $q->execute([$user_id, $with, $with, $user_id, $after]);
        $rows = $q->fetchAll();

        $msgs = [];
        foreach ($rows as $r) {
            $msgs[] = [
                'id'      => (int)$r['id'],
                'mine'    => ((int)$r['sender_id'] === $user_id),
                'content' => (string)$r['content'],
                'when'    => when($r['sent_at'], 'M j, g:i a'),
            ];
        }
        if ($rows) {
            $pdo->prepare("UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id=? AND is_read=0")
                ->execute([$user_id, $with]);
        }
        json_out(['ok' => true, 'messages' => $msgs, 'last_id' => (int)($msgs ? end($msgs)['id'] : $after)]);
    }

    if ($a === 'send_message') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST required.']);
        if (!csrf_ok()) { http_response_code(419); json_out(['ok' => false, 'error' => 'Your session expired. Reload the page.']); }

        $to      = max(0, (int)($_POST['to_user_id'] ?? 0));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '')                 json_out(['ok' => false, 'error' => 'Write something first.']);
        if (mb_strlen($content) > MSG_MAX)   json_out(['ok' => false, 'error' => 'That message is longer than ' . MSG_MAX . ' characters.']);
        if ($to === $user_id)                json_out(['ok' => false, 'error' => 'You cannot message yourself.']);

        // The old endpoint inserted against any integer: messaging a deleted user
        // wrote a row nobody would ever read, and reported success.
        $u = user_info($pdo, $to);
        if (!$u)                    json_out(['ok' => false, 'error' => 'That user no longer exists.']);
        if (!(int)$u['is_active'])  json_out(['ok' => false, 'error' => display_name($u) . "'s account is deactivated, so they will not see this."]);

        $ins = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, is_read, sent_at) VALUES (?,?,?,0,UTC_TIMESTAMP())");
        $ins->execute([$user_id, $to, $content]);
        $mid = (int)$pdo->lastInsertId();
        // trg_after_message_insert raises the recipient's NewMessage notification.
        json_out(['ok' => true, 'message' => ['id' => $mid, 'when' => when((new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'M j, g:i a')]]);
    }

    if ($a === 'notif_detail') {
        $nid = max(0, (int)($_GET['id'] ?? 0));
        $q = $pdo->prepare("SELECT * FROM notifications WHERE id = ? LIMIT 1");
        $q->execute([$nid]);
        $n = $q->fetch();
        if (!$n) json_out(['ok' => false, 'error' => 'That notification no longer exists.']);
        json_out([
            'ok'      => true,
            'html'    => render_notif_detail($pdo, $n),
            'is_read' => (int)($n['is_read'] ?? 0),
            'mine'    => ((int)$n['user_id'] === $user_id),
            'owner'   => display_name(user_info($pdo, (int)$n['user_id'])),
        ]);
    }

    if ($a === 'notif_mark') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST required.']);
        if (!csrf_ok()) { http_response_code(419); json_out(['ok' => false, 'error' => 'Your session expired. Reload the page.']); }
        $nid   = max(0, (int)($_POST['id'] ?? 0));
        $state = (($_POST['state'] ?? 'read') === 'read') ? 1 : 0;

        // 144 of the 161 notifications on record belong to students and tutors. The
        // old endpoint let an admin flip any of them, which quietly marked someone
        // else's unread alert as read and removed it from their own notifications
        // page before they ever saw it. Read state is the recipient's to own.
        $q = $pdo->prepare("SELECT user_id FROM notifications WHERE id = ? LIMIT 1");
        $q->execute([$nid]);
        $owner = $q->fetchColumn();
        if ($owner === false)          json_out(['ok' => false, 'error' => 'That notification no longer exists.']);
        if ((int)$owner !== $user_id)  json_out(['ok' => false, 'error' => 'That notification belongs to someone else. Marking it read would hide it from them.']);

        $pdo->prepare("UPDATE notifications SET is_read = ? WHERE id = ? AND user_id = ?")->execute([$state, $nid, $user_id]);
        json_out(['ok' => true, 'is_read' => $state]);
    }

    json_out(['ok' => false, 'error' => 'Unknown action.']);
}

// ==================================================== POST actions (PRG)
$ok  = $_SESSION['msg_ok']  ?? [];
$err = $_SESSION['msg_err'] ?? [];
unset($_SESSION['msg_ok'], $_SESSION['msg_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = (string)($_POST['action'] ?? '');
    $redirect = (string)($_POST['redirect'] ?? qs());
    if (!preg_match('~^admin_messaging_notifications\.php(\?|$)~', $redirect)) $redirect = qs();

    try {
        if (!csrf_ok()) throw new RuntimeException('Your session expired. Reload the page and try again.');

        // ---- Broadcast -------------------------------------------------------
        if ($action === 'create_announcement') {
            $title    = trim((string)($_POST['title'] ?? ''));
            $content  = trim((string)($_POST['content'] ?? ''));
            $audience = in_array($_POST['target_audience'] ?? '', AUDIENCES, true) ? $_POST['target_audience'] : '';
            $deliver  = isset($_POST['deliver']);

            if ($title === '')                        throw new RuntimeException('Give the announcement a title.');
            if (mb_strlen($title) > TITLE_MAX)        throw new RuntimeException('The title is longer than ' . TITLE_MAX . ' characters.');
            if ($content === '')                      throw new RuntimeException('Write the announcement body.');
            if (mb_strlen($content) > ANNOUNCE_MAX)   throw new RuntimeException('The announcement is longer than ' . ANNOUNCE_MAX . ' characters.');
            if ($audience === '')                     throw new RuntimeException('Choose who this is for.');

            $roles = $audience === 'Tutors' ? ['Tutor'] : ($audience === 'Students' ? ['Student'] : ['Tutor', 'Student']);
            $in    = implode(',', array_fill(0, count($roles), '?'));
            $rq    = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND role IN ($in) AND id <> ?");
            $rq->execute(array_merge($roles, [$user_id]));
            $recipients = array_map('intval', array_column($rq->fetchAll(), 'id'));

            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO announcements (admin_id, title, content, target_audience, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())")
                    ->execute([$user_id, $title, $content, $audience]);
                $sent = 0;
                if ($deliver && $recipients) {
                    // Nothing on the student or tutor side reads the announcements
                    // table — it has exactly one row and no consumer anywhere in the
                    // codebase. Delivering the announcement as a direct message puts
                    // it where recipients already look, and trg_after_message_insert
                    // raises their NewMessage notification for free.
                    $body = $title . "\n\n" . $content;
                    $ins  = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, is_read, sent_at) VALUES (?,?,?,0,UTC_TIMESTAMP())");
                    foreach ($recipients as $rid) { $ins->execute([$user_id, $rid, $body]); $sent++; }
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            $ok[] = $deliver
                ? 'Announcement published and delivered to ' . plural($sent, 'person', 'people') . '.'
                : 'Announcement saved. It was not delivered to anyone — tick "Deliver to inboxes" to send it.';
        }

        // ---- Nudges ----------------------------------------------------------
        if ($action === 'send_nudge') {
            $kind = in_array($_POST['kind'] ?? '', ['payment_due', 'pkg_expiry'], true) ? $_POST['kind'] : '';
            $days = max(0, min(365, (int)($_POST['days'] ?? 7)));
            if ($kind === '') throw new RuntimeException('Unknown nudge type.');
            if (($_POST['confirm'] ?? '') !== '1') throw new RuntimeException('Nothing was sent — review the list and press Send.');

            $c    = nudge_candidates($pdo, $kind, $days);
            $type = $c['type'];
            $send = array_values(array_filter($c['rows'], fn($r) => !$r['skip']));
            if (!$send) throw new RuntimeException('Nobody qualifies right now, so nothing was sent.');

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, message, related_entity_id, is_read, created_at) VALUES (?,?,?,?,0,UTC_TIMESTAMP())");
                $seen = [];
                foreach ($send as $r) {
                    // Belt and braces: never write the same (person, record) twice in one batch.
                    $key = (int)$r['user_id'] . ':' . (int)$r['rel_id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;

                    if ($type === 'PaymentDue') {
                        $n   = (int)($r['n_payments'] ?? 1);
                        $msg = ($n === 1 ? 'A payment of ' : $n . ' payments totalling ')
                             . number_format((float)($r['amount'] ?? 0), 2)
                             . ' for your "' . $r['pkg_name'] . '" package '
                             . ($n === 1 ? 'is' : 'are') . ' still outstanding.';
                    } else {
                        $left = (int)($r['lessons_remaining'] ?? 0);
                        $msg  = 'Your "' . $r['pkg_name'] . '" package ends on '
                              . (new DateTime($r['since']))->format('j M Y') . '.'
                              . ($left > 0 ? ' You still have ' . plural($left, 'lesson') . ' left on it.' : '');
                    }
                    $ins->execute([(int)$r['user_id'], $type, $msg, (int)$r['rel_id']]);
                }
                $written = count($seen);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            $skipped = count($c['rows']) - count($send);
            $ok[] = plural($written, 'notification') . ' sent.'
                  . ($skipped > 0 ? ' ' . plural($skipped, 'person', 'people') . ' skipped (already nudged recently, or deactivated).' : '');
        }

        // ---- Schedule change -------------------------------------------------
        if ($action === 'notify_schedule_change') {
            $lesson_id = max(0, (int)($_POST['lesson_id'] ?? 0));
            $note      = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) > 500) throw new RuntimeException('Keep the note under 500 characters.');

            $q = $pdo->prepare("SELECT l.id, l.tutor_id, l.student_id, l.scheduled_start_time, subj.name subject_name
                                FROM lessons l LEFT JOIN subjects subj ON subj.id = l.subject_id
                                WHERE l.id = ? LIMIT 1");
            $q->execute([$lesson_id]);
            $L = $q->fetch();
            if (!$L) throw new RuntimeException('That lesson no longer exists.');

            $targets = array_values(array_unique(array_filter([(int)$L['tutor_id'], (int)$L['student_id']])));
            if (!$targets) throw new RuntimeException('That lesson has neither a tutor nor a student to notify.');
            // Only notify accounts that can actually read it.
            $in = implode(',', array_fill(0, count($targets), '?'));
            $aq = $pdo->prepare("SELECT id FROM users WHERE id IN ($in) AND is_active = 1");
            $aq->execute($targets);
            $targets = array_map('intval', array_column($aq->fetchAll(), 'id'));
            if (!$targets) throw new RuntimeException('Both people on that lesson have deactivated accounts.');

            $msg = 'The schedule for your ' . ($L['subject_name'] ?: '') . ' lesson on '
                 . when($L['scheduled_start_time'], 'M j') . ' has changed.'
                 . ($note !== '' ? ' ' . $note : '');
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, message, related_entity_id, is_read, created_at) VALUES (?,'ScheduleChange',?,?,0,UTC_TIMESTAMP())");
                foreach ($targets as $t) $ins->execute([$t, $msg, $lesson_id]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            $ok[] = plural(count($targets), 'person', 'people') . ' notified about lesson #' . $lesson_id . '.';
        }

    } catch (Throwable $e) {
        $err[] = $e->getMessage();
    }

    $_SESSION['msg_ok']  = $ok;
    $_SESSION['msg_err'] = $err;
    header('Location: ' . $redirect, true, 303);
    exit();
}

// ==================================================== Shared counters
$myUnreadMsgs = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE receiver_id = " . (int)$user_id . " AND is_read = 0")->fetchColumn();
$myUnreadNotifs = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . (int)$user_id . " AND is_read = 0")->fetchColumn();
$allUnreadNotifs = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();

// ==================================================== Messages view
$conversations = []; $thread = []; $lastMsgId = 0; $partner = null;
if ($view === 'messages') {
    $cw = ''; $ca = [$user_id, $user_id, $user_id, $user_id];
    if ($q_search !== '') {
        $cw = " AND CONCAT(u.first_name,' ',u.last_name) LIKE ?";
        $ca[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q_search) . '%';
    }
    // One row per correspondent, with their last message and how many of theirs
    // this admin has not read. u.id <> the admin excludes notes-to-self.
    $c = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.role, u.is_active,
               MAX(m.sent_at) last_time,
               MAX(m.id) last_id,
               SUM(CASE WHEN m.receiver_id = ? AND m.is_read = 0 THEN 1 ELSE 0 END) unread
        FROM users u
        JOIN messages m ON (m.sender_id = u.id AND m.receiver_id = ?) OR (m.receiver_id = u.id AND m.sender_id = ?)
        WHERE u.id <> ? $cw
        GROUP BY u.id, u.first_name, u.last_name, u.role, u.is_active
        ORDER BY unread DESC, last_time DESC");
    $c->execute($ca);
    $conversations = $c->fetchAll();

    // Preview line per conversation, fetched by id rather than squeezed out of a
    // GROUP_CONCAT (whose 1024-byte default would silently truncate long messages).
    if ($conversations) {
        $ids = array_map(fn($r) => (int)$r['last_id'], $conversations);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $bq  = $pdo->prepare("SELECT id, sender_id, content FROM messages WHERE id IN ($in)");
        $bq->execute($ids);
        $bodies = [];
        foreach ($bq as $r) $bodies[(int)$r['id']] = $r;
        foreach ($conversations as &$cv) {
            $b = $bodies[(int)$cv['last_id']] ?? null;
            $cv['last_body'] = $b ? (string)$b['content'] : '';
            $cv['last_mine'] = $b ? ((int)$b['sender_id'] === $user_id) : false;
        }
        unset($cv);
    }

    if ($with_user_id > 0) {
        $partner = user_info($pdo, $with_user_id);
        if ($partner) {
            $t = $pdo->prepare("SELECT id, sender_id, content, sent_at, is_read FROM messages
                                WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
                                ORDER BY id DESC LIMIT " . THREAD_MAX);
            $t->execute([$user_id, $with_user_id, $with_user_id, $user_id]);
            $thread = array_reverse($t->fetchAll());
            if ($thread) $lastMsgId = (int)end($thread)['id'];
            $pdo->prepare("UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id=? AND is_read=0")
                ->execute([$user_id, $with_user_id]);
        }
    }
}

// ==================================================== Broadcast view
$announcements = []; $nudge = null; $nudgeKind = ''; $nudgeDays = 7; $lessonPicker = [];
if ($view === 'broadcast') {
    // LEFT JOIN: the old query used an inner one, so an announcement written by an
    // admin who has since been removed disappeared from its own history.
    $announcements = $pdo->query("
        SELECT a.*, CONCAT(u.first_name,' ',u.last_name) admin_name
        FROM announcements a LEFT JOIN users u ON u.id = a.admin_id
        ORDER BY a.created_at DESC, a.id DESC LIMIT 20")->fetchAll();

    $nudgeKind = in_array($_GET['nudge'] ?? '', ['payment_due', 'pkg_expiry'], true) ? $_GET['nudge'] : '';
    $nudgeDays = max(0, min(365, (int)($_GET['days'] ?? 7)));
    if ($nudgeKind !== '') $nudge = nudge_candidates($pdo, $nudgeKind, $nudgeDays);

    // Lessons in a window the admin might plausibly be rescheduling. Bounds are
    // built locally and converted, not compared against MySQL's NOW().
    $lo = localToUtc((new DateTime('now', $adminTzO))->modify('-7 days')->format('Y-m-d H:i:s'), $adminTz)->format('Y-m-d H:i:s');
    $hi = localToUtc((new DateTime('now', $adminTzO))->modify('+60 days')->format('Y-m-d H:i:s'), $adminTz)->format('Y-m-d H:i:s');
    $lp = $pdo->prepare("SELECT l.id, l.scheduled_start_time, l.status,
                                CONCAT(t.first_name,' ',t.last_name) tutor_name,
                                CONCAT(s.first_name,' ',s.last_name) student_name,
                                subj.name subject_name
                         FROM lessons l
                         LEFT JOIN users t ON t.id = l.tutor_id
                         LEFT JOIN users s ON s.id = l.student_id
                         LEFT JOIN subjects subj ON subj.id = l.subject_id
                         WHERE l.scheduled_start_time BETWEEN ? AND ?
                           AND l.status IN ('Scheduled','InProgress')
                         ORDER BY l.scheduled_start_time ASC LIMIT 200");
    $lp->execute([$lo, $hi]);
    $lessonPicker = $lp->fetchAll();
}

// ==================================================== Notifications view
$notifs = []; $notifTotal = 0; $notifPages = 1; $typeCounts = [];
if ($view === 'notifications') {
    $w = ['1=1']; $a = [];
    if ($nf_type !== '') { $w[] = 'n.type = ?';    $a[] = $nf_type; }
    if ($nf_user  >  0)  { $w[] = 'n.user_id = ?'; $a[] = $nf_user; }
    if ($nf_mine)        { $w[] = 'n.user_id = ?'; $a[] = $user_id; }
    if ($nf_unread)      { $w[] = 'n.is_read = 0'; }
    if ($q_search !== '') {
        $w[] = "(n.message LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q_search) . '%';
        $a[] = $like; $a[] = $like;
    }
    $where = implode(' AND ', $w);

    $cq = $pdo->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE $where");
    $cq->execute($a);
    $notifTotal = (int)$cq->fetchColumn();
    $notifPages = max(1, (int)ceil($notifTotal / PER_PAGE));
    if ($page_no > $notifPages) $page_no = $notifPages;

    // The old page pulled 300 rows with no pagination and no counts.
    $nq = $pdo->prepare("SELECT n.*, u.first_name, u.last_name, u.role
                         FROM notifications n JOIN users u ON u.id = n.user_id
                         WHERE $where ORDER BY n.created_at DESC, n.id DESC
                         LIMIT " . PER_PAGE . " OFFSET " . (($page_no - 1) * PER_PAGE));
    $nq->execute($a);
    $notifs = $nq->fetchAll();

    foreach ($pdo->query("SELECT type, COUNT(*) c, SUM(is_read=0) unread FROM notifications GROUP BY type") as $r) {
        $typeCounts[$r['type']] = ['c' => (int)$r['c'], 'unread' => (int)$r['unread']];
    }
}

// People the admin can start a conversation with.
$everyone = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE is_active = 1 ORDER BY role, first_name, last_name")->fetchAll();

$VIEW_META = [
    'messages'      => ['Messages',      'Your direct conversations with tutors and students.'],
    'broadcast'     => ['Broadcast',     'Announcements and reminders that go out to many people at once.'],
    'notifications' => ['Notifications', 'Every notification the platform has raised, for anyone.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Messaging &mdash; <?php echo h($VIEW_META[$view][0]); ?></title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #111827; color: #d1d5db; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #1f2937; }
    ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
    .card { background-color: #0f1623; border: 1px solid rgba(255,255,255,.06); border-radius: .75rem; }
    .form-input { background-color:#374151; border:1px solid #4b5563; color:#f3f4f6; border-radius:.5rem; padding:.5rem .75rem; font-size:.875rem; width:100%; }
    .form-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(79,70,229,.4); }
    .tab { padding:.5rem 1rem; border-radius:.5rem; font-size:.875rem; font-weight:600; white-space:nowrap; }
    .tab-on  { background:#4f46e5; color:#fff; }
    .tab-off { color:#9ca3af; }
    .tab-off:hover { background:#1f2937; color:#e5e7eb; }
    .bubble-me   { background:#4f46e5; color:#fff; border-radius:1rem 1rem .25rem 1rem; }
    .bubble-them { background:#374151; border:1px solid #4b5563; border-radius:1rem 1rem 1rem .25rem; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  </style>
</head>
<body class="flex min-h-screen">

  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-5 lg:p-8 min-w-0">

    <header class="mb-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-2xl font-bold text-white">Messaging &mdash; <?php echo h($VIEW_META[$view][0]); ?></h2>
          <p class="text-gray-400 text-sm mt-0.5"><?php echo h($VIEW_META[$view][1]); ?></p>
        </div>
        <div class="text-right text-xs">
          <p class="text-gray-500 uppercase tracking-wide">Signed in as</p>
          <p class="text-sm text-gray-300 font-semibold"><?php echo h(display_name(user_info($pdo, $user_id))); ?></p>
          <p class="text-gray-500">times shown in <?php echo h($adminTz); ?></p>
        </div>
      </div>

      <nav class="flex flex-wrap items-center gap-1 mt-4 border-b border-gray-700/60 pb-3">
        <?php foreach ($VIEW_META as $k => $meta): ?>
          <a href="<?php echo h(qs(['view' => $k, 'p' => null, 'with' => null])); ?>" class="tab <?php echo $view === $k ? 'tab-on' : 'tab-off'; ?>">
            <?php echo h($meta[0]); ?>
            <?php if ($k === 'messages' && $myUnreadMsgs > 0): ?>
              <span class="ml-1 text-xs bg-indigo-500/30 text-indigo-200 px-1.5 py-0.5 rounded-full"><?php echo $myUnreadMsgs; ?></span>
            <?php endif; ?>
            <?php if ($k === 'notifications' && $myUnreadNotifs > 0): ?>
              <span class="ml-1 text-xs bg-indigo-500/30 text-indigo-200 px-1.5 py-0.5 rounded-full"><?php echo $myUnreadNotifs; ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </header>

    <?php foreach ($ok as $m): ?>
      <div class="mb-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($err as $m): ?>
      <div class="mb-3 bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div>
    <?php endforeach; ?>

<?php if ($view === 'messages'): ?>

  <div class="grid grid-cols-1 lg:grid-cols-[22rem_1fr] gap-6 items-start">

    <!-- ------------------------------------------------ Conversation list -->
    <div class="card overflow-hidden">
      <div class="p-4 border-b border-gray-700/50">
        <div class="flex items-center justify-between gap-2 mb-3">
          <h3 class="text-base font-semibold text-white">Conversations</h3>
          <button type="button" id="openNew" class="text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white py-1.5 px-2.5 rounded-lg">New</button>
        </div>
        <form method="get" class="flex gap-2">
          <input type="hidden" name="view" value="messages">
          <?php if ($with_user_id): ?><input type="hidden" name="with" value="<?php echo (int)$with_user_id; ?>"><?php endif; ?>
          <input name="q" value="<?php echo h($q_search); ?>" placeholder="Search people" class="form-input">
          <button class="text-xs font-semibold bg-gray-700 hover:bg-gray-600 text-white px-3 rounded-lg">Go</button>
        </form>
      </div>

      <?php if (!$conversations): ?>
        <p class="text-gray-400 text-sm p-4">
          <?php echo $q_search !== '' ? 'Nobody matches "' . h($q_search) . '".' : 'No conversations yet. Press New to start one.'; ?>
        </p>
      <?php else: ?>
        <div class="max-h-[34rem] overflow-y-auto divide-y divide-gray-800/70">
          <?php foreach ($conversations as $c): $on = ((int)$c['id'] === $with_user_id); ?>
            <a href="<?php echo h(qs(['view' => 'messages', 'with' => (int)$c['id']])); ?>"
               class="block px-4 py-3 <?php echo $on ? 'bg-indigo-600/15 border-l-2 border-indigo-500' : 'hover:bg-gray-800/50 border-l-2 border-transparent'; ?>">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-semibold text-white text-sm truncate">
                    <?php echo h(trim($c['first_name'] . ' ' . $c['last_name'])); ?>
                    <?php if (!(int)$c['is_active']): ?><span class="text-xs text-gray-500 font-normal">(inactive)</span><?php endif; ?>
                  </p>
                  <p class="text-xs text-gray-500"><?php echo h($c['role']); ?></p>
                </div>
                <div class="text-right shrink-0">
                  <?php if ((int)$c['unread'] > 0): ?>
                    <span class="text-xs font-bold bg-indigo-500 text-white px-1.5 py-0.5 rounded-full"><?php echo (int)$c['unread']; ?></span>
                  <?php endif; ?>
                  <p class="text-xs text-gray-500 mt-0.5 whitespace-nowrap"><?php echo h(ago($c['last_time'])); ?></p>
                </div>
              </div>
              <p class="text-xs text-gray-400 truncate mt-1">
                <?php if (!empty($c['last_mine'])): ?><span class="text-gray-600">You:</span> <?php endif; ?>
                <?php echo h(mb_substr(preg_replace('/\s+/', ' ', (string)$c['last_body']), 0, 70)); ?>
              </p>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------- Thread -->
    <div class="card flex flex-col" style="min-height:34rem">
      <?php if ($with_user_id <= 0): ?>
        <div class="flex-1 grid place-items-center p-10 text-center">
          <div>
            <p class="text-gray-400">Pick a conversation on the left, or start a new one.</p>
            <?php if ($myUnreadMsgs > 0): ?>
              <p class="text-sm text-indigo-300 mt-2"><?php echo plural($myUnreadMsgs, 'message'); ?> waiting for you.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif (!$partner): ?>
        <div class="flex-1 grid place-items-center p-10 text-center">
          <p class="text-amber-300 text-sm">That user no longer exists.</p>
        </div>
      <?php else: ?>
        <div class="p-4 border-b border-gray-700/50 flex items-center justify-between gap-3">
          <div>
            <p class="font-semibold text-white"><?php echo h(display_name($partner)); ?></p>
            <p class="text-xs text-gray-500"><?php echo h($partner['role']); ?>
              <?php if (!(int)$partner['is_active']): ?><span class="text-amber-400">&middot; account deactivated</span><?php endif; ?>
            </p>
          </div>
          <p class="text-xs text-gray-500"><?php echo count($thread) >= THREAD_MAX ? 'last ' . THREAD_MAX : plural(count($thread), 'message'); ?></p>
        </div>

        <div id="thread" class="flex-1 overflow-y-auto p-4 space-y-2" style="max-height:26rem" data-last-id="<?php echo (int)$lastMsgId; ?>">
          <?php if (!$thread): ?>
            <p class="text-gray-500 text-sm text-center py-8">No messages yet. Say something.</p>
          <?php endif; ?>
          <?php
            $prevDay = '';
            foreach ($thread as $m):
              $mine = ((int)$m['sender_id'] === $user_id);
              $day  = when($m['sent_at'], 'Y-m-d');
              if ($day !== $prevDay):
                $prevDay = $day;
          ?>
            <p class="text-center text-xs text-gray-600 py-1"><?php echo h(when($m['sent_at'], 'l, M j, Y')); ?></p>
          <?php endif; ?>
            <div class="flex <?php echo $mine ? 'justify-end' : 'justify-start'; ?>">
              <div class="max-w-[75%] px-3 py-2 <?php echo $mine ? 'bubble-me' : 'bubble-them'; ?>">
                <p class="text-sm whitespace-pre-wrap break-words"><?php echo h($m['content']); ?></p>
                <p class="text-[11px] opacity-70 mt-1"><?php echo h(when($m['sent_at'], 'g:i a')); ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ((int)$partner['is_active']): ?>
          <form class="p-4 border-t border-gray-700/50" id="sendForm">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="to_user_id" value="<?php echo (int)$with_user_id; ?>">
            <div class="flex items-end gap-2">
              <textarea name="content" id="msgBox" class="form-input resize-none" rows="2" maxlength="<?php echo MSG_MAX; ?>"
                        placeholder="Message <?php echo h($partner['first_name']); ?>&hellip;  (Enter to send, Shift+Enter for a new line)"></textarea>
              <button type="submit" id="sendBtn" class="bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-semibold py-2 px-5 rounded-lg text-sm">Send</button>
            </div>
            <p id="sendErr" class="text-xs text-red-400 mt-2 hidden"></p>
          </form>
        <?php else: ?>
          <div class="p-4 border-t border-gray-700/50">
            <p class="text-sm text-amber-300"><?php echo h($partner['first_name']); ?>&rsquo;s account is deactivated, so they cannot receive messages.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- New conversation -->
  <div id="newModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="modal-backdrop absolute inset-0 bg-black/70"></div>
    <div class="relative card w-full max-w-md mx-4 p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">New conversation</h3>
        <button type="button" class="modal-close text-gray-400 hover:text-white text-xl leading-none">&times;</button>
      </div>
      <form method="get" class="space-y-3">
        <input type="hidden" name="view" value="messages">
        <div>
          <label class="text-xs text-gray-400">Message who?</label>
          <select name="with" class="form-input" required>
            <option value="">Select a person</option>
            <?php
              $byRole = [];
              foreach ($everyone as $u) { if ((int)$u['id'] !== $user_id) $byRole[$u['role']][] = $u; }
              foreach ($byRole as $role => $list): ?>
                <optgroup label="<?php echo h($role); ?>">
                  <?php foreach ($list as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>"><?php echo h(trim($u['first_name'] . ' ' . $u['last_name'])); ?></option>
                  <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" class="modal-close bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">Open thread</button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php if ($view === 'broadcast'): ?>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">

    <!-- --------------------------------------------------- Announcement -->
    <div class="xl:col-span-2 space-y-6">
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white">Announcement</h3>
        <p class="text-xs text-gray-500 mt-0.5 mb-4">Saved to the announcement log, and optionally delivered to every recipient&rsquo;s inbox.</p>
        <form method="post" class="space-y-3">
          <input type="hidden" name="action" value="create_announcement">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
          <div>
            <label class="text-xs text-gray-400">Title</label>
            <input name="title" class="form-input" maxlength="<?php echo TITLE_MAX; ?>" required placeholder="New prices from October">
          </div>
          <div>
            <label class="text-xs text-gray-400">Body</label>
            <textarea name="content" rows="5" class="form-input resize-y" maxlength="<?php echo ANNOUNCE_MAX; ?>" required placeholder="Write the announcement&hellip;"></textarea>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-400">Audience</label>
              <select name="target_audience" class="form-input" required>
                <?php foreach (AUDIENCES as $aud): ?>
                  <option value="<?php echo h($aud); ?>"><?php echo $aud === 'All' ? 'Everyone (tutors and students)' : h($aud) . ' only'; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <label class="flex items-start gap-2 text-sm text-gray-300 sm:mt-6">
              <input type="checkbox" name="deliver" value="1" class="w-4 h-4 rounded mt-0.5" checked>
              <span>Deliver to inboxes
                <span class="block text-xs text-gray-500">Sends it as a message, which is the only place recipients will see it.</span>
              </span>
            </label>
          </div>
          <div class="bg-amber-500/5 border border-amber-500/25 rounded-lg p-3">
            <p class="text-xs text-amber-200/80">
              Nothing in the student or tutor area reads the announcements table &mdash; it holds one row and has no consumer anywhere in the codebase.
              Leaving &ldquo;Deliver to inboxes&rdquo; unticked files the announcement for the record and shows it to nobody.
            </p>
          </div>
          <div class="text-right">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 px-5 rounded-lg text-sm">Publish</button>
          </div>
        </form>
      </div>

      <!-- --------------------------------------------------- Nudges -->
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white">Reminders</h3>
        <p class="text-xs text-gray-500 mt-0.5 mb-4">
          Raises a real notification for each person. Nothing is sent until you have seen the list and pressed Send.
          Anyone already reminded in the last <?php echo RENOTIFY_DAYS; ?> days is skipped.
        </p>

        <div class="flex flex-wrap gap-2 mb-4">
          <?php
            $kinds = ['payment_due' => 'Outstanding payments', 'pkg_expiry' => 'Packages ending soon'];
            foreach ($kinds as $k => $label):
          ?>
            <a href="<?php echo h(qs(['view' => 'broadcast', 'nudge' => $k, 'days' => $nudgeDays])); ?>"
               class="text-sm px-3 py-2 rounded-lg font-semibold <?php echo $nudgeKind === $k ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-300 hover:text-white'; ?>"><?php echo h($label); ?></a>
          <?php endforeach; ?>
          <?php if ($nudgeKind !== ''): ?>
            <a href="<?php echo h(qs(['view' => 'broadcast', 'nudge' => null, 'days' => null])); ?>" class="text-sm px-3 py-2 text-gray-500 hover:text-gray-300">Clear</a>
          <?php endif; ?>
        </div>

        <?php if ($nudgeKind === ''): ?>
          <p class="text-sm text-gray-400">Pick one above to see who currently qualifies.</p>
        <?php else:
          $rows = $nudge['rows'];
          $sendable = array_values(array_filter($rows, fn($r) => !$r['skip']));
          $skipped  = count($rows) - count($sendable);
        ?>
          <form method="get" class="flex flex-wrap items-end gap-3 mb-4 pb-4 border-b border-gray-700/50">
            <input type="hidden" name="view" value="broadcast">
            <input type="hidden" name="nudge" value="<?php echo h($nudgeKind); ?>">
            <div>
              <label class="text-xs text-gray-400"><?php echo $nudgeKind === 'payment_due' ? 'Unpaid for at least' : 'Ending within'; ?></label>
              <div class="flex items-center gap-2">
                <input type="number" name="days" min="0" max="365" value="<?php echo (int)$nudgeDays; ?>" class="form-input w-24">
                <span class="text-sm text-gray-400">days</span>
                <button class="text-xs font-semibold bg-gray-700 hover:bg-gray-600 text-white py-2 px-3 rounded-lg">Refresh</button>
              </div>
            </div>
          </form>

          <?php if (!$rows): ?>
            <p class="text-sm text-gray-400">
              Nobody qualifies. <?php echo $nudgeKind === 'payment_due'
                ? 'No payment has been pending for that long.'
                : 'No active package ends inside that window.'; ?>
            </p>
          <?php else: ?>
            <div class="overflow-x-auto rounded-lg border border-gray-700/60">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-gray-400 text-xs uppercase tracking-wide border-b border-gray-700">
                    <th class="text-left px-3 py-2 font-semibold">Person</th>
                    <th class="text-left px-3 py-2 font-semibold">Package</th>
                    <th class="text-left px-3 py-2 font-semibold"><?php echo $nudgeKind === 'payment_due' ? 'Owed' : 'Lessons left'; ?></th>
                    <th class="text-left px-3 py-2 font-semibold"><?php echo $nudgeKind === 'payment_due' ? 'Oldest since' : 'Ends'; ?></th>
                    <th class="text-right px-3 py-2 font-semibold">Will send</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr class="border-b border-gray-800/70 <?php echo $r['skip'] ? 'opacity-50' : ''; ?>">
                      <td class="px-3 py-2 text-white font-semibold"><?php echo h(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                      <td class="px-3 py-2 text-gray-300"><?php echo h($r['pkg_name']); ?></td>
                      <td class="px-3 py-2 text-gray-300 whitespace-nowrap">
                        <?php if ($nudgeKind === 'payment_due'): ?>
                          <?php echo h(number_format((float)$r['amount'], 2)); ?>
                          <?php if ((int)$r['n_payments'] > 1): ?><span class="text-xs text-gray-500">across <?php echo (int)$r['n_payments']; ?> payments</span><?php endif; ?>
                        <?php else: ?>
                          <?php echo (int)$r['lessons_remaining']; ?>
                        <?php endif; ?>
                      </td>
                      <td class="px-3 py-2 text-gray-400 whitespace-nowrap">
                        <?php echo h($nudgeKind === 'payment_due' ? when($r['since'], 'M j, Y') : (new DateTime($r['since']))->format('M j, Y')); ?>
                      </td>
                      <td class="px-3 py-2 text-right">
                        <?php if ($r['skip']): ?>
                          <span class="text-xs text-gray-500"><?php echo h($r['reason']); ?></span>
                        <?php else: ?>
                          <span class="text-xs text-emerald-400 font-semibold">yes</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <form method="post" class="mt-4 flex flex-wrap items-center justify-between gap-3"
                  onsubmit="return confirm('Send <?php echo count($sendable); ?> notification(s)? Each person will see it in their notifications.');">
              <input type="hidden" name="action" value="send_nudge">
              <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="redirect" value="<?php echo h(qs(['nudge' => $nudgeKind, 'days' => $nudgeDays])); ?>">
              <input type="hidden" name="kind" value="<?php echo h($nudgeKind); ?>">
              <input type="hidden" name="days" value="<?php echo (int)$nudgeDays; ?>">
              <input type="hidden" name="confirm" value="1">
              <p class="text-sm text-gray-400">
                <span class="text-white font-semibold"><?php echo count($sendable); ?></span> will be notified<?php echo $skipped ? ', ' . $skipped . ' skipped' : ''; ?>.
              </p>
              <button <?php echo $sendable ? '' : 'disabled'; ?>
                      class="bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold py-2 px-5 rounded-lg text-sm">
                Send <?php echo count($sendable); ?> reminder<?php echo count($sendable) === 1 ? '' : 's'; ?>
              </button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- ------------------------------------------- Schedule change -->
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white">Tell a lesson&rsquo;s tutor and student it moved</h3>
        <p class="text-xs text-gray-500 mt-0.5 mb-4">Raises a ScheduleChange notification for both people on the lesson.</p>
        <?php if (!$lessonPicker): ?>
          <p class="text-sm text-gray-400">No scheduled lessons in the next 60 days.</p>
        <?php else: ?>
          <form method="post" class="space-y-3">
            <input type="hidden" name="action" value="notify_schedule_change">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
            <div>
              <label class="text-xs text-gray-400">Lesson</label>
              <select name="lesson_id" class="form-input" required>
                <option value="">Select a lesson</option>
                <?php foreach ($lessonPicker as $L): ?>
                  <option value="<?php echo (int)$L['id']; ?>">
                    <?php echo h(when($L['scheduled_start_time'], 'M j, g:i a') . '  ·  ' . ($L['subject_name'] ?: 'No subject')
                        . '  ·  ' . ($L['tutor_name'] ?: 'no tutor') . ' with ' . ($L['student_name'] ?: 'no student')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-400">Add a line of context (optional)</label>
              <input name="note" class="form-input" maxlength="500" placeholder="Moved to Thursday at the tutor's request.">
            </div>
            <div class="text-right">
              <button class="bg-gray-700 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded-lg text-sm">Notify both</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- ------------------------------------------------ History -->
    <div class="card p-6">
      <h3 class="text-lg font-semibold text-white mb-1">Announcement log</h3>
      <p class="text-xs text-gray-500 mb-4"><?php echo plural(count($announcements), 'entry', 'entries'); ?></p>
      <?php if (!$announcements): ?>
        <p class="text-sm text-gray-400">Nothing published yet.</p>
      <?php else: ?>
        <div class="space-y-3 max-h-[40rem] overflow-y-auto pr-1">
          <?php foreach ($announcements as $a): ?>
            <div class="bg-gray-800/60 rounded-lg p-3">
              <div class="flex items-start justify-between gap-2">
                <p class="font-semibold text-white text-sm"><?php echo h($a['title']); ?></p>
                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700 text-gray-300 whitespace-nowrap"><?php echo h($a['target_audience']); ?></span>
              </div>
              <p class="text-sm text-gray-300 mt-1.5 whitespace-pre-wrap break-words"><?php echo h($a['content']); ?></p>
              <p class="text-xs text-gray-500 mt-2">
                <?php echo h($a['admin_name'] ? trim($a['admin_name']) : 'a removed admin'); ?> &middot; <?php echo h(when($a['created_at'], 'M j, Y')); ?>
              </p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<?php if ($view === 'notifications'): ?>

  <?php
    $TYPE_STYLE = [
      'NewMessage'               => 'bg-indigo-500/10 text-indigo-300',
      'PaymentDue'               => 'bg-amber-500/10 text-amber-300',
      'PackageExpiry'            => 'bg-rose-500/10 text-rose-300',
      'ScheduleChange'           => 'bg-sky-500/10 text-sky-300',
      'HomeworkAssigned'         => 'bg-emerald-500/10 text-emerald-300',
      'HomeworkGraded'           => 'bg-emerald-500/10 text-emerald-300',
      'CatchupRequested'         => 'bg-orange-500/10 text-orange-300',
      'CatchupProposed'          => 'bg-orange-500/10 text-orange-300',
      'CatchupAccepted'          => 'bg-orange-500/10 text-orange-300',
      'CatchupExpired'           => 'bg-gray-600/30 text-gray-400',
      'QualityControlAssignment' => 'bg-violet-500/10 text-violet-300',
      'QualityControlReport'     => 'bg-violet-500/10 text-violet-300',
      'QualityControlFlag'       => 'bg-rose-500/10 text-rose-300',
    ];
    $spaced = fn($t) => preg_replace('/(?<!^)([A-Z])/', ' $1', $t);
  ?>

  <div class="card p-4 mb-5">
    <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3 items-end">
      <input type="hidden" name="view" value="notifications">
      <div>
        <label class="text-xs text-gray-400">Type</label>
        <select name="type" class="form-input">
          <option value="">All types</option>
          <?php foreach (NOTIF_TYPES as $t): $tc = $typeCounts[$t] ?? ['c' => 0, 'unread' => 0]; ?>
            <option value="<?php echo h($t); ?>" <?php echo $nf_type === $t ? 'selected' : ''; ?>>
              <?php echo h($spaced($t)); ?><?php echo $tc['c'] ? ' (' . $tc['c'] . ')' : ' — none'; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-400">Recipient</label>
        <select name="user_id" class="form-input">
          <option value="0">Anyone</option>
          <?php foreach ($everyone as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>" <?php echo $nf_user === (int)$u['id'] ? 'selected' : ''; ?>>
              <?php echo h(trim($u['first_name'] . ' ' . $u['last_name']) . ' — ' . $u['role']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-400">Search</label>
        <input name="q" value="<?php echo h($q_search); ?>" class="form-input" placeholder="message or name">
      </div>
      <div class="flex flex-col gap-1.5">
        <label class="flex items-center gap-2 text-sm text-gray-300">
          <input type="checkbox" name="unread" value="1" class="w-4 h-4 rounded" <?php echo $nf_unread ? 'checked' : ''; ?>>
          <span>Unread only</span>
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-300">
          <input type="checkbox" name="mine" value="1" class="w-4 h-4 rounded" <?php echo $nf_mine ? 'checked' : ''; ?>>
          <span>Only mine</span>
        </label>
      </div>
      <div class="flex items-center gap-2">
        <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 px-4 rounded-lg text-sm">Apply</button>
        <a href="admin_messaging_notifications.php?view=notifications" class="text-sm text-gray-400 hover:text-gray-200">Reset</a>
      </div>
    </form>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
    <div class="card p-4"><p class="text-xs text-gray-400">Matching</p><p class="text-xl font-extrabold text-white mt-1"><?php echo number_format($notifTotal); ?></p><p class="text-xs text-gray-500">page <?php echo $page_no; ?> of <?php echo $notifPages; ?></p></div>
    <a href="<?php echo h(qs(['view'=>'notifications','unread'=>'1','mine'=>'1','p'=>null])); ?>" class="card p-4 hover:ring-1 hover:ring-indigo-500/40 transition"><p class="text-xs text-gray-400">Unread &mdash; yours</p><p class="text-xl font-extrabold <?php echo $myUnreadNotifs ? 'text-indigo-400' : 'text-gray-600'; ?> mt-1"><?php echo $myUnreadNotifs; ?></p><p class="text-xs text-gray-500">you can clear these</p></a>
    <div class="card p-4"><p class="text-xs text-gray-400">Unread &mdash; everyone</p><p class="text-xl font-extrabold text-gray-300 mt-1"><?php echo $allUnreadNotifs; ?></p><p class="text-xs text-gray-500">theirs to clear, not yours</p></div>
    <a href="<?php echo h(qs(['view'=>'messages','with'=>null,'p'=>null])); ?>" class="card p-4 hover:ring-1 hover:ring-indigo-500/40 transition"><p class="text-xs text-gray-400">Unread messages</p><p class="text-xl font-extrabold <?php echo $myUnreadMsgs ? 'text-indigo-400' : 'text-gray-600'; ?> mt-1"><?php echo $myUnreadMsgs; ?></p><p class="text-xs text-gray-500">in your inbox</p></a>
  </div>

  <div class="card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-gray-700/50">
      <h3 class="text-lg font-semibold text-white">Notification log</h3>
      <p class="text-xs text-gray-500">Click a row for the full record behind it</p>
    </div>

    <?php if (!$notifs): ?>
      <p class="text-gray-400 text-sm p-6">
        Nothing matches these filters. <a class="text-indigo-400 hover:text-indigo-300" href="admin_messaging_notifications.php?view=notifications">Clear them</a>.
      </p>
    <?php else: ?>
      <div class="divide-y divide-gray-800/70">
        <?php foreach ($notifs as $n):
          $mine   = ((int)$n['user_id'] === $user_id);
          $unread = !(int)$n['is_read'];
          $style  = $TYPE_STYLE[$n['type']] ?? 'bg-gray-700 text-gray-300';
        ?>
          <div class="notif-row px-4 py-3 hover:bg-gray-800/50 cursor-pointer flex flex-wrap items-start gap-3 <?php echo $unread ? 'border-l-2 border-indigo-500' : 'border-l-2 border-transparent'; ?>"
               data-notif-id="<?php echo (int)$n['id']; ?>" role="button" tabindex="0">
            <span class="text-xs font-medium px-2 py-1 rounded-full whitespace-nowrap <?php echo $style; ?>"><?php echo h($spaced($n['type'])); ?></span>
            <div class="flex-1 min-w-[12rem]">
              <p class="text-sm <?php echo $unread ? 'text-white font-medium' : 'text-gray-300'; ?>"><?php echo h($n['message']); ?></p>
              <p class="text-xs text-gray-500 mt-0.5">
                to <span class="<?php echo $mine ? 'text-emerald-400' : 'text-gray-400'; ?>"><?php echo h(trim($n['first_name'] . ' ' . $n['last_name'])); ?><?php echo $mine ? ' (you)' : ''; ?></span>
                <span class="text-gray-700">&middot;</span> <?php echo h($n['role']); ?>
                <?php if ($n['related_entity_id'] !== null): ?><span class="text-gray-700">&middot;</span> ref #<?php echo (int)$n['related_entity_id']; ?><?php endif; ?>
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-xs text-gray-400 whitespace-nowrap"><?php echo h(when($n['created_at'], 'M j, Y')); ?></p>
              <p class="text-xs text-gray-600"><?php echo h(when($n['created_at'], 'g:i a')); ?></p>
            </div>
            <?php if ($unread): ?>
              <span class="text-xs text-indigo-300 font-semibold self-center whitespace-nowrap">unread</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($notifPages > 1): ?>
        <div class="flex items-center justify-between p-4 border-t border-gray-700/50 text-sm">
          <?php if ($page_no > 1): ?>
            <a href="<?php echo h(qs(['p' => $page_no - 1])); ?>" class="text-indigo-400 hover:text-indigo-300 font-semibold">&larr; Newer</a>
          <?php else: ?><span></span><?php endif; ?>
          <span class="text-gray-500">Page <?php echo $page_no; ?> of <?php echo $notifPages; ?> &middot; <?php echo number_format($notifTotal); ?> notifications</span>
          <?php if ($page_no < $notifPages): ?>
            <a href="<?php echo h(qs(['p' => $page_no + 1])); ?>" class="text-indigo-400 hover:text-indigo-300 font-semibold">Older &rarr;</a>
          <?php else: ?><span></span><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>

  <!-- ------------------------------------------- Notification detail -->
  <div id="notifModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="modal-backdrop absolute inset-0 bg-black/70"></div>
    <div class="relative card w-full max-w-3xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-white font-semibold">Notification</h3>
        <button type="button" class="modal-close text-gray-400 hover:text-white text-xl leading-none">&times;</button>
      </div>
      <div id="notifBody" class="text-sm text-gray-300">Loading&hellip;</div>
      <div class="mt-5 flex flex-wrap items-center justify-between gap-2 pt-3 border-t border-gray-700/50">
        <p id="notifOwnerNote" class="text-xs text-gray-500"></p>
        <div class="flex items-center gap-2">
          <button id="markBtn" class="hidden bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-3 py-1.5 rounded-lg"></button>
          <button type="button" class="modal-close bg-gray-700 hover:bg-gray-600 text-white text-sm px-3 py-1.5 rounded-lg">Close</button>
        </div>
      </div>
    </div>
  </div>

  </main>

<script>
(function () {
  'use strict';
  var CSRF = <?php echo json_encode($csrf); ?>;

  // ---- Modals. The old page bound nothing to its backdrop, so clicking outside
  // never closed anything, and Escape did nothing either.
  function open(el)  { if (!el) return; el.classList.remove('hidden'); el.classList.add('flex'); document.body.style.overflow = 'hidden'; }
  function close(el) { if (!el) return; el.classList.add('hidden'); el.classList.remove('flex'); document.body.style.overflow = ''; }
  document.querySelectorAll('.modal-close, .modal-backdrop').forEach(function (b) {
    b.addEventListener('click', function () { close(b.closest('.fixed.inset-0')); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.fixed.inset-0').forEach(function (m) { if (!m.classList.contains('hidden')) close(m); });
  });
  var newBtn = document.getElementById('openNew');
  if (newBtn) newBtn.addEventListener('click', function () { open(document.getElementById('newModal')); });

  // ---- Thread
  var thread = document.getElementById('thread');
  if (thread) thread.scrollTop = thread.scrollHeight;

<?php if ($view === 'messages' && $with_user_id > 0 && $partner): ?>
  var WITH = <?php echo (int)$with_user_id; ?>;
  var lastId = Number(thread && thread.dataset.lastId || 0);
  var sending = false;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]; }); }

  function addBubble(mine, content, whenTxt) {
    if (!thread) return;
    var wrap = document.createElement('div');
    wrap.className = 'flex ' + (mine ? 'justify-end' : 'justify-start');
    wrap.innerHTML = '<div class="max-w-[75%] px-3 py-2 ' + (mine ? 'bubble-me' : 'bubble-them') + '">'
      + '<p class="text-sm whitespace-pre-wrap break-words">' + esc(content) + '</p>'
      + '<p class="text-[11px] opacity-70 mt-1">' + esc(whenTxt) + '</p></div>';
    thread.appendChild(wrap);
    thread.scrollTop = thread.scrollHeight;
  }

  // The old page polled every 2.5s forever, including while the tab was in the
  // background — roughly 1,400 requests an hour from a window left open. This
  // backs off when the tab is hidden and after a run of empty polls.
  var IDLE_STEPS = [4000, 8000, 15000, 30000];
  var quiet = 0, timer = null;

  function schedule() {
    clearTimeout(timer);
    if (document.hidden) { timer = setTimeout(schedule, 20000); return; }
    var wait = IDLE_STEPS[Math.min(quiet, IDLE_STEPS.length - 1)];
    timer = setTimeout(poll, wait);
  }

  async function poll() {
    if (document.hidden) { schedule(); return; }
    try {
      var r = await fetch('?ajax=poll_thread&with=' + WITH + '&after_id=' + lastId, { credentials: 'same-origin' });
      if (r.ok) {
        var d = await r.json();
        if (d && d.ok && d.messages && d.messages.length) {
          d.messages.forEach(function (m) { addBubble(m.mine, m.content, m.when); });
          lastId = Number(d.last_id || lastId);
          quiet = 0;
        } else { quiet++; }
      }
    } catch (e) { quiet++; }
    schedule();
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { quiet = 0; schedule(); } });
  schedule();

  var form = document.getElementById('sendForm');
  var box  = document.getElementById('msgBox');
  var btn  = document.getElementById('sendBtn');
  var errP = document.getElementById('sendErr');

  async function send() {
    if (sending) return;
    var content = (box.value || '').trim();
    if (!content) return;
    sending = true; btn.disabled = true; errP.classList.add('hidden');
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('to_user_id', String(WITH));
    fd.append('content', content);
    try {
      var r = await fetch('?ajax=send_message', { method: 'POST', body: fd, credentials: 'same-origin' });
      var d = await r.json();
      if (d && d.ok) {
        addBubble(true, content, d.message.when);
        lastId = Math.max(lastId, Number(d.message.id || 0));
        box.value = '';
        quiet = 0;
      } else {
        // The old version swallowed every failure silently: the message simply
        // never appeared and nothing said why.
        errP.textContent = (d && d.error) || 'The message could not be sent.';
        errP.classList.remove('hidden');
      }
    } catch (e) {
      errP.textContent = 'The message could not be sent — check your connection.';
      errP.classList.remove('hidden');
    }
    sending = false; btn.disabled = false;
  }

  if (form) form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
  if (box) box.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
<?php endif; ?>

  // ---- Notification detail
  var nModal = document.getElementById('notifModal');
  var nBody  = document.getElementById('notifBody');
  var nNote  = document.getElementById('notifOwnerNote');
  var markBtn = document.getElementById('markBtn');
  var currentId = 0, currentRead = 0, currentMine = false;

  function paintMark() {
    if (!markBtn) return;
    if (!currentMine) { markBtn.classList.add('hidden'); return; }
    markBtn.classList.remove('hidden');
    markBtn.textContent = currentRead ? 'Mark unread' : 'Mark read';
  }

  async function openNotif(id) {
    currentId = Number(id || 0);
    open(nModal);
    nBody.textContent = 'Loading…';
    if (markBtn) markBtn.classList.add('hidden');
    if (nNote) nNote.textContent = '';
    try {
      var r = await fetch('?ajax=notif_detail&id=' + currentId, { credentials: 'same-origin' });
      var d = await r.json();
      if (d && d.ok) {
        nBody.innerHTML = d.html;
        currentRead = Number(d.is_read) ? 1 : 0;
        currentMine = !!d.mine;
        // Read state belongs to the recipient. An admin browsing someone else's
        // notification gets no button, rather than one that hides it from them.
        if (nNote) nNote.textContent = currentMine
          ? (currentRead ? 'You have read this.' : 'Unread in your own notifications.')
          : 'This is ' + (d.owner || 'someone else') + "'s notification — only they can mark it read.";
        paintMark();
      } else {
        nBody.textContent = (d && d.error) || 'Could not load that notification.';
      }
    } catch (e) {
      nBody.textContent = 'Could not load that notification.';
    }
  }

  document.querySelectorAll('.notif-row').forEach(function (row) {
    row.addEventListener('click', function () { openNotif(row.dataset.notifId); });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openNotif(row.dataset.notifId); }
    });
  });

  if (markBtn) markBtn.addEventListener('click', async function () {
    if (!currentMine || !currentId) return;
    var next = currentRead ? 'unread' : 'read';
    markBtn.disabled = true;
    try {
      var body = new URLSearchParams({ id: String(currentId), state: next, csrf: CSRF });
      var r = await fetch('?ajax=notif_mark', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
      });
      var d = await r.json();
      if (d && d.ok) {
        currentRead = Number(d.is_read) ? 1 : 0;
        if (nNote) nNote.textContent = currentRead ? 'You have read this.' : 'Unread in your own notifications.';
        paintMark();
      } else if (nNote) {
        nNote.textContent = (d && d.error) || 'That could not be changed.';
      }
    } catch (e) {
      if (nNote) nNote.textContent = 'That could not be changed.';
    }
    markBtn.disabled = false;
  });

  // One submit per form — a double click used to post twice.
  document.querySelectorAll('form[method="post"]').forEach(function (f) {
    f.addEventListener('submit', function () {
      f.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (b) {
        setTimeout(function () { b.disabled = true; b.style.opacity = '.6'; }, 0);
      });
    });
  });
})();
</script>
</body>
</html>
