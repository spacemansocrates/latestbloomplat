<?php
// admin_lessons.php — the single lessons page.
//
// This replaces three separate screens that each held a slice of the same data and
// disagreed about the details: admin_lessons.php (list), lessona.php (calendar) and
// admin_daily_lessons.php (day). They are now four views over one filter set, one
// detail drawer and one set of write actions:
//
//   ?view=list      the full table, filtered and paged
//   ?view=day       one day's agenda, with everything the old Daily page showed
//   ?view=calendar  a month grid
//   ?view=heatmap   volume over time, and which weekday/hour the teaching sits in
//
// lessona.php and admin_daily_lessons.php are now redirect stubs into these views.

require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
require_once __DIR__ . '/../utils/timezone_helper.php';

session_start();

// --- Auth: Admins only (Administrator, Admin, Academic Coordinator) ---
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$user_id      = (int)($_SESSION['user_id'] ?? 0);
$user_role    = $_SESSION['user_role'] ?? '';
$current_page = 'lessons';

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

try {
  $pdo = getDbConnection();
  $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
  error_log('admin_lessons DB error: ' . $e->getMessage());
  http_response_code(503);
  die('The lessons page is temporarily unavailable. Please try again shortly.');
}

$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
  $ck = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
  $ck->execute([$user_id]);
  $is_admin = (bool)$ck->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit; }

// NOTE: the old page ran three schema migrations on every request (two
// ensureNullable() calls, ensureTimezoneColumn(), and a CREATE TABLE for
// app_settings as MyISAM while the real table is InnoDB). That is DDL on a GET.
// All of it already exists in both databases; schema changes belong in migrations/.

$adminTimezone = getUserTimezone();
$adminTz       = new DateTimeZone($adminTimezone);
$utcTz         = new DateTimeZone('UTC');
$nowLocal      = getNowLocal($adminTimezone);
$nowUtcStr     = getNowUtc()->format('Y-m-d H:i:s');

/** A local wall-clock date (+ time) as the UTC instant it denotes. */
function localToUtcStr(string $date, string $time, DateTimeZone $tz): ?string {
  $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
  return $dt ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
}
/** UTC bounds [start, endExclusive) for a local calendar day. */
function localDayBounds(string $ymd, DateTimeZone $tz): array {
  $a = localToUtcStr($ymd, '00:00', $tz);
  $b = (new DateTime($a, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
  return [$a, $b];
}

function get_setting(PDO $pdo, string $key, $default=null) {
  $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key`=?");
  $s->execute([$key]);
  $v = $s->fetchColumn();
  return ($v === false || $v === null) ? $default : $v;
}
$lessonPrefs = json_decode((string)get_setting($pdo,'org.lesson_prefs',''), true);
if (!is_array($lessonPrefs)) { $lessonPrefs = ['allow30'=>1,'allow60'=>1,'allow_demo_without_student'=>1]; }

// 'InProgress' was missing from this list, which is what made the edit modal
// silently reset a running lesson to Scheduled.
$STATUS_OPTS   = ['Scheduled','InProgress','Completed','CancelledByTutor','CancelledByStudent','NoShow'];
$TYPE_OPTS     = ['Regular','Catchup','Demo'];
// A lesson occupying a tutor's slot. InProgress belongs here: a lesson actually
// running did not block a double booking.
$BUSY_STATUSES = "'Scheduled','InProgress','Completed','NoShow'";

$STATUS_BADGE = [
  'Scheduled'          => 'bg-indigo-500/10 text-indigo-300',
  'InProgress'         => 'bg-sky-500/10 text-sky-300',
  'Completed'          => 'bg-emerald-500/10 text-emerald-300',
  'CancelledByTutor'   => 'bg-amber-500/10 text-amber-300',
  'CancelledByStudent' => 'bg-orange-500/10 text-orange-300',
  'NoShow'             => 'bg-rose-500/10 text-rose-300',
];
$STATUS_DOT = [
  'Scheduled'=>'#6366f1','InProgress'=>'#0ea5e9','Completed'=>'#10b981',
  'CancelledByTutor'=>'#f59e0b','CancelledByStudent'=>'#f97316','NoShow'=>'#ef4444',
];

$tutors   = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Tutor' AND is_active=1 ORDER BY first_name, last_name")->fetchAll();
$students = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Student' AND is_active=1 ORDER BY first_name, last_name")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$oks = []; $errs = [];
function ok($m){ global $oks; $oks[] = $m; }
function er($m){ global $errs; $errs[] = $m; }

function schedulingTimezone(PDO $pdo, ?int $student_id, string $fallback): string {
  if (!$student_id) return $fallback;
  $s = $pdo->prepare("SELECT timezone FROM users WHERE id = ?");
  $s->execute([$student_id]);
  $tz = $s->fetchColumn();
  return ($tz && isValidTimezone($tz)) ? $tz : $fallback;
}

/** Everything a DELETE would take with it. */
function lessonDependents(PDO $pdo, int $lessonId): array {
  // Eleven tables cascade off `lessons`, so removing one silently destroys its
  // ratings, its recording row, its QC review, its topic coverage and any catch-up
  // the student is owed. The old confirm() said only "Delete this lesson?".
  $checks = [
    'rating'           => "SELECT COUNT(*) FROM lesson_ratings WHERE lesson_id=?",
    'recording'        => "SELECT COUNT(*) FROM lesson_recordings WHERE lesson_id=?",
    'QC assignment'    => "SELECT COUNT(*) FROM quality_control_assignments WHERE lesson_id=?",
    'QC review'        => "SELECT COUNT(*) FROM quality_control_reviews WHERE lesson_id=?",
    'covered topic'    => "SELECT COUNT(*) FROM lesson_topics_covered WHERE lesson_id=?",
    'catch-up request' => "SELECT COUNT(*) FROM catchup_requests WHERE original_lesson_id=?",
    'homework task'    => "SELECT COUNT(*) FROM homework WHERE lesson_id=?", // ON DELETE SET NULL
  ];
  $out = [];
  foreach ($checks as $label => $sql) {
    try {
      $s = $pdo->prepare($sql); $s->execute([$lessonId]);
      $n = (int)$s->fetchColumn();
      if ($n > 0) $out[$label] = $n;
    } catch (Throwable $e) { /* table absent on an older schema */ }
  }
  return $out;
}
function describeDependents(array $deps): string {
  $bits = [];
  foreach ($deps as $label => $n) $bits[] = $n . ' ' . $label . ($n === 1 ? '' : 's');
  return implode(', ', $bits);
}

// =====================================================================
// WRITE ACTIONS
// =====================================================================
$POSTED = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';

if ($POSTED === 'create_single' || $POSTED === 'create_recurring') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token.');
    $tutor_id     = (int)($_POST['tutor_id'] ?? 0);
    $subject_id   = (int)($_POST['subject_id'] ?? 0);
    $duration_min = (int)($_POST['duration'] ?? 60);
    $notes        = trim($_POST['notes'] ?? '');
    $student_id   = ($_POST['student_id'] ?? '') !== '' ? (int)$_POST['student_id'] : null;
    $meeting_url  = trim($_POST['meeting_url'] ?? '');
    // A demo is a lesson with no student. The old code also called every 30-minute
    // lesson a demo, so booking a half-hour for a real student demanded an external
    // meeting link and stamped "Demo lesson" into the tutor's notes.
    $is_demo      = ($student_id === null);

    if (!$tutor_id || !$subject_id) throw new RuntimeException('Tutor and subject are required.');
    if (!in_array($duration_min,[30,60],true)) throw new RuntimeException('Invalid duration.');
    if ($duration_min===60 && !$student_id) throw new RuntimeException('Student required for 60-minute lessons.');
    if ($is_demo && empty($lessonPrefs['allow_demo_without_student'])) {
      throw new RuntimeException('Demo lessons without a student are disabled in Settings.');
    }
    if ($meeting_url !== '' && !filter_var($meeting_url, FILTER_VALIDATE_URL)) {
      throw new RuntimeException('Meeting link is invalid.');
    }

    $inputTimezone = schedulingTimezone($pdo, $student_id, $adminTimezone);
    $localTz = new DateTimeZone($inputTimezone);

    // scheduled_*_utc are NOT NULL with no default and production runs
    // STRICT_TRANS_TABLES, so omitting them made the insert fail outright.
    // lesson_type is written here too — nothing wrote it before, so every demo ever
    // created still reads 'Regular'.
    $ins = $pdo->prepare("INSERT INTO lessons
      (student_package_id, tutor_id, student_id, subject_id, meeting_url,
       scheduled_start_time, scheduled_end_time, scheduled_start_time_utc, scheduled_end_time_utc,
       scheduled_timezone, status, lesson_type, tutor_notes)
      VALUES (NULL,?,?,?,?,?,?,?,?,?,'Scheduled',?,?)");
    $conflictQ = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE tutor_id=? AND status IN ($BUSY_STATUSES) AND scheduled_start_time < ? AND scheduled_end_time > ?");

    $slots = [];
    if ($POSTED === 'create_single') {
      $date_str = trim($_POST['lesson_date'] ?? '');
      $time_str = trim($_POST['lesson_time'] ?? '');
      if (!$date_str || !$time_str) throw new RuntimeException('Date and time are required.');
      $s = localToUtcStr($date_str, $time_str, $localTz);
      if (!$s) throw new RuntimeException('Invalid start datetime.');
      $slots[] = $s;
    } else {
      $start_date = trim($_POST['start_date'] ?? '');
      $end_date   = trim($_POST['end_date'] ?? '');
      $start_time = trim($_POST['start_time'] ?? '');
      $days       = array_values(array_intersect(array_map('intval', (array)($_POST['days'] ?? [])), [1,2,3,4,5,6,7]));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end_date)) throw new RuntimeException('Invalid date range.');
      if (!preg_match('/^\d{2}:\d{2}$/',$start_time)) throw new RuntimeException('Invalid time.');
      if (!$days) throw new RuntimeException('Select at least one weekday.');
      $iter = DateTime::createFromFormat('Y-m-d H:i:s', $start_date.' 00:00:00', $localTz);
      $end  = DateTime::createFromFormat('Y-m-d H:i:s', $end_date.' 00:00:00', $localTz);
      if (!$iter || !$end || $end < $iter) throw new RuntimeException('Invalid date range.');
      // A runaway range would insert thousands of rows before anyone noticed.
      if ((int)$iter->diff($end)->days > 366) throw new RuntimeException('Date range cannot exceed one year.');
      // Each occurrence is converted separately, which keeps the local wall clock
      // stable across a DST change inside the range.
      while ($iter <= $end) {
        if (in_array((int)$iter->format('N'), $days, true)) {
          $slots[] = localToUtcStr($iter->format('Y-m-d'), $start_time, $localTz);
        }
        $iter->modify('+1 day');
      }
      if (!$slots) throw new RuntimeException('No dates in that range fell on the selected weekdays.');
    }

    $created = 0; $conf = 0;
    foreach ($slots as $sUtc) {
      $st = new DateTime($sUtc, $utcTz);
      $en = (clone $st)->modify("+{$duration_min} minutes");
      $conflictQ->execute([$tutor_id, $en->format('Y-m-d H:i:s'), $st->format('Y-m-d H:i:s')]);
      if ((int)$conflictQ->fetchColumn() > 0) { $conf++; continue; }
      $ins->execute([
        $tutor_id, $student_id, $subject_id, ($meeting_url ?: null),
        $st->format('Y-m-d H:i:s'), $en->format('Y-m-d H:i:s'),
        $st->format('Y-m-d H:i:s'), $en->format('Y-m-d H:i:s'),
        $inputTimezone, ($is_demo ? 'Demo' : 'Regular'), ($notes ?: null),
      ]);
      $created++;
    }
    if ($created) ok($created === 1 ? 'Lesson created.' : "Created {$created} lessons.");
    if ($conf)    er("Skipped {$conf} slot(s) — the tutor was already booked.");
    if (!$created && !$conf) er('Nothing was created.');
  } catch (Throwable $e) { er($e->getMessage()); }
}

if ($POSTED === 'update_lesson') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token.');
    $lesson_id    = (int)($_POST['lesson_id'] ?? 0);
    $tutor_id     = (int)($_POST['tutor_id'] ?? 0);
    $subject_id   = (int)($_POST['subject_id'] ?? 0);
    $student_id   = ($_POST['student_id'] ?? '') !== '' ? (int)$_POST['student_id'] : null;
    $date_str     = trim($_POST['lesson_date'] ?? '');
    $time_str     = trim($_POST['lesson_time'] ?? '');
    $duration_min = (int)($_POST['duration'] ?? 60);
    $status       = trim($_POST['status'] ?? '');
    $lesson_type  = trim($_POST['lesson_type'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $meeting_url  = trim($_POST['meeting_url'] ?? '');
    $wa_event     = !empty($_POST['whatsapp_event_created']) ? 1 : 0;

    if ($lesson_id<=0 || !$tutor_id || !$subject_id || !$date_str || !$time_str) throw new RuntimeException('Missing fields.');
    // Neither of these was checked. 'InProgress' was missing from $STATUS_OPTS, so
    // the modal's <select> had no option matching a running lesson, the browser
    // submitted nothing for it, and the old `?? 'Scheduled'` default quietly reset
    // every InProgress lesson an admin so much as opened and saved.
    if (!in_array($status, $STATUS_OPTS, true)) throw new RuntimeException('Invalid status.');
    if (!in_array($lesson_type, $TYPE_OPTS, true)) $lesson_type = ($student_id === null ? 'Demo' : 'Regular');
    if (!in_array($duration_min,[30,60],true)) throw new RuntimeException('Invalid duration.');
    if ($duration_min===60 && !$student_id) throw new RuntimeException('Student required for 60-minute lessons.');
    if ($meeting_url !== '' && !filter_var($meeting_url, FILTER_VALIDATE_URL)) throw new RuntimeException('Meeting link is invalid.');

    $cur = $pdo->prepare("SELECT id, tutor_id, scheduled_start_time, scheduled_end_time FROM lessons WHERE id=?");
    $cur->execute([$lesson_id]);
    $existing = $cur->fetch();
    if (!$existing) throw new RuntimeException('Lesson not found.');

    $inputTimezone = schedulingTimezone($pdo, $student_id, $adminTimezone);
    $stStr = localToUtcStr($date_str, $time_str, new DateTimeZone($inputTimezone));
    if (!$stStr) throw new RuntimeException('Invalid start datetime.');
    $st = new DateTime($stStr, $utcTz);
    $en = (clone $st)->modify("+{$duration_min} minutes");

    // The conflict check exists to stop a NEW double booking, not to stop history
    // being recorded. The data contains a genuine overlapping pair, and checking
    // unconditionally meant an admin could not so much as mark that lesson
    // Completed. So only check when the slot or the tutor actually moves.
    $movedSlot = ($st->format('Y-m-d H:i:s') !== $existing['scheduled_start_time'])
              || ($en->format('Y-m-d H:i:s') !== $existing['scheduled_end_time'])
              || ((int)$tutor_id !== (int)$existing['tutor_id']);
    if ($movedSlot) {
      $conflictQ = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE tutor_id=? AND id<>? AND status IN ($BUSY_STATUSES) AND scheduled_start_time < ? AND scheduled_end_time > ?");
      $conflictQ->execute([$tutor_id,$lesson_id,$en->format('Y-m-d H:i:s'),$st->format('Y-m-d H:i:s')]);
      if ((int)$conflictQ->fetchColumn() > 0) throw new RuntimeException('Conflict: tutor already has a lesson in that timeslot.');
    }

    // scheduled_*_utc are deliberately left alone: nothing in the codebase reads
    // them and 309 of 310 rows already disagree with them.
    $pdo->prepare("UPDATE lessons
      SET tutor_id=?, student_id=?, subject_id=?, meeting_url=?,
          scheduled_start_time=?, scheduled_end_time=?, scheduled_timezone=?,
          status=?, lesson_type=?, tutor_notes=?, whatsapp_event_created=?
      WHERE id=?")->execute([
      $tutor_id, $student_id, $subject_id, ($meeting_url ?: null),
      $st->format('Y-m-d H:i:s'), $en->format('Y-m-d H:i:s'), $inputTimezone,
      $status, $lesson_type, ($notes ?: null), $wa_event, $lesson_id,
    ]);

    ok('Lesson updated.');
  } catch (Throwable $e) { er($e->getMessage()); }
}

if ($POSTED === 'delete_lesson') {
  try {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) throw new RuntimeException('Invalid session token.');
    $lesson_id = (int)($_POST['lesson_id'] ?? 0);
    if ($lesson_id<=0) throw new RuntimeException('Invalid lesson.');
    $s = $pdo->prepare("SELECT id FROM lessons WHERE id=?");
    $s->execute([$lesson_id]);
    if (!$s->fetchColumn()) throw new RuntimeException('Lesson not found.');

    $deps = lessonDependents($pdo, $lesson_id);
    if ($deps && ($_POST['ack_destructive'] ?? '') !== '1') {
      throw new RuntimeException(
        'This lesson has ' . describeDependents($deps) . ' attached, which deletion would destroy. '
        . 'Cancel it instead (Edit → Status), or press Delete again to confirm.'
      );
    }
    $pdo->prepare("DELETE FROM lessons WHERE id=?")->execute([$lesson_id]);
    ok('Lesson deleted' . ($deps ? ', along with ' . describeDependents($deps) . '.' : '.'));
  } catch (Throwable $e) { er($e->getMessage()); }
}

// =====================================================================
// SHARED FILTERS
// =====================================================================
$view = $_GET['view'] ?? 'list';
if (!in_array($view, ['list','day','calendar','heatmap'], true)) $view = 'list';
// admin_sidebar.php highlights the active leaf from this.
$current_subpage = $view;

$flt_tutor   = (int)($_GET['tutor_id']   ?? 0);
$flt_student = (int)($_GET['student_id'] ?? 0);
$flt_subject = (int)($_GET['subject_id'] ?? 0);
$flt_status  = in_array($_GET['status'] ?? '', $STATUS_OPTS, true) ? $_GET['status'] : '';
$flt_type    = in_array($_GET['type'] ?? '', $TYPE_OPTS, true) ? $_GET['type'] : '';
$flt_time    = in_array($_GET['time'] ?? '', ['all','future','past','today'], true) ? $_GET['time'] : 'all';
$flt_from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$flt_to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : '';
$flt_duration= in_array($_GET['duration'] ?? '', ['30','60','any'], true) ? $_GET['duration'] : 'any';
$flt_demo    = in_array($_GET['demo'] ?? '', ['only','exclude','any'], true) ? $_GET['demo'] : 'any';
$flt_flag    = in_array($_GET['flag'] ?? '', ['unfinished','nolink','recorded','norecording'], true) ? $_GET['flag'] : '';
$flt_q       = trim($_GET['q'] ?? '');
// From the old Daily page, which was the only place these two lived.
$flt_code    = trim($_GET['code'] ?? '');
$flt_year    = trim($_GET['year'] ?? '');

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $nowLocal->format('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : $nowLocal->format('Y-m');
$weeks = (int)($_GET['weeks'] ?? 26); if (!in_array($weeks, [13,26,52], true)) $weeks = 26;

$page = max(1, (int)($_GET['p'] ?? 1));
$pp   = (int)($_GET['pp'] ?? 20);
if (!in_array($pp,[10,20,50,100],true)) $pp = 20;

/** WHERE fragments shared by every view. */
function buildFilters(array $f, string $nowUtcStr, DateTimeZone $adminTz): array {
  $w = []; $p = [];
  if ($f['tutor'])   { $w[] = "l.tutor_id = ?";   $p[] = $f['tutor']; }
  if ($f['student']) { $w[] = "l.student_id = ?"; $p[] = $f['student']; }
  if ($f['subject']) { $w[] = "l.subject_id = ?"; $p[] = $f['subject']; }
  if ($f['status'])  { $w[] = "l.status = ?";     $p[] = $f['status']; }
  if ($f['type'])    { $w[] = "l.lesson_type = ?"; $p[] = $f['type']; }
  // These compared UTC-stored instants against the server's local NOW() and against
  // raw local date strings. Both boundaries are converted here instead.
  if ($f['time'] === 'future') { $w[] = "l.scheduled_start_time >= ?"; $p[] = $nowUtcStr; }
  if ($f['time'] === 'past')   { $w[] = "l.scheduled_start_time <  ?"; $p[] = $nowUtcStr; }
  if ($f['time'] === 'today')  {
    [$a,$b] = localDayBounds((new DateTime('now', $adminTz))->format('Y-m-d'), $adminTz);
    $w[] = "l.scheduled_start_time >= ? AND l.scheduled_start_time < ?"; $p[] = $a; $p[] = $b;
  }
  if ($f['from']) { $w[] = "l.scheduled_start_time >= ?"; $p[] = localToUtcStr($f['from'], '00:00', $adminTz); }
  if ($f['to'])   { [, $b] = localDayBounds($f['to'], $adminTz); $w[] = "l.scheduled_start_time < ?"; $p[] = $b; }
  if ($f['duration'] !== 'any') {
    $w[] = "TIMESTAMPDIFF(MINUTE, l.scheduled_start_time, l.scheduled_end_time) = ?"; $p[] = (int)$f['duration'];
  }
  // lesson_type is only now being written, so historical demos are still 'Regular'
  // with a NULL student. Match either so the filter covers the whole history.
  if ($f['demo'] === 'only')    { $w[] = "(l.lesson_type='Demo' OR l.student_id IS NULL)"; }
  if ($f['demo'] === 'exclude') { $w[] = "(l.lesson_type<>'Demo' AND l.student_id IS NOT NULL)"; }
  if ($f['flag'] === 'unfinished')  { $w[] = "l.status IN ('Scheduled','InProgress') AND l.scheduled_end_time < ?"; $p[] = $nowUtcStr; }
  if ($f['flag'] === 'nolink')      { $w[] = "(l.meeting_url IS NULL OR l.meeting_url='')"; }
  if ($f['flag'] === 'recorded')    { $w[] = "EXISTS (SELECT 1 FROM lesson_recordings lr WHERE lr.lesson_id=l.id AND lr.status='Ready')"; }
  if ($f['flag'] === 'norecording') { $w[] = "l.status='Completed' AND NOT EXISTS (SELECT 1 FROM lesson_recordings lr WHERE lr.lesson_id=l.id)"; }
  if ($f['code'] !== '') { $w[] = "u.customer_code LIKE ?"; $p[] = '%'.$f['code'].'%'; }
  if ($f['year'] !== '') { $w[] = "u.school_year_label = ?"; $p[] = $f['year']; }
  if ($f['q'] !== '') {
    $w[] = "(CONCAT(t.first_name,' ',t.last_name) LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR s.name LIKE ? OR u.customer_code LIKE ?)";
    $like = '%'.$f['q'].'%';
    array_push($p, $like, $like, $like, $like);
  }
  return [$w ? ('WHERE '.implode(' AND ',$w)) : '', $p];
}

$F = [
  'tutor'=>$flt_tutor,'student'=>$flt_student,'subject'=>$flt_subject,'status'=>$flt_status,
  'type'=>$flt_type,'time'=>$flt_time,'from'=>$flt_from,'to'=>$flt_to,'duration'=>$flt_duration,
  'demo'=>$flt_demo,'flag'=>$flt_flag,'q'=>$flt_q,'code'=>$flt_code,'year'=>$flt_year,
];
[$whereSql, $whereParams] = buildFilters($F, $nowUtcStr, $adminTz);

$JOINS = "
  FROM lessons l
  JOIN users t   ON t.id = l.tutor_id
  LEFT JOIN users u ON u.id = l.student_id
  JOIN subjects s ON s.id = l.subject_id";

$LIST_COLS = "
  l.id, l.tutor_id, l.student_id, l.subject_id, l.status, l.lesson_type,
  l.meeting_url, l.external_room_id, l.tutor_notes, l.recording_opt_in, l.whatsapp_event_created,
  l.scheduled_start_time, l.scheduled_end_time, l.tutor_join_time, l.student_join_time,
  s.name AS subject_name,
  t.first_name AS tutor_fn, t.last_name AS tutor_ln,
  u.first_name AS student_fn, u.last_name AS student_ln,
  u.customer_code AS student_code, u.school_year_label AS student_year,
  TIMESTAMPDIFF(MINUTE, l.scheduled_start_time, l.scheduled_end_time) AS duration_min,
  (SELECT COUNT(*) FROM lesson_recordings lr WHERE lr.lesson_id=l.id AND lr.status='Ready') AS rec_ready,
  (SELECT COUNT(*) FROM lesson_recordings lr WHERE lr.lesson_id=l.id) AS rec_any,
  (SELECT COUNT(*) FROM lesson_ratings lrt WHERE lrt.lesson_id=l.id) AS n_ratings,
  (SELECT COUNT(*) FROM quality_control_assignments qa WHERE qa.lesson_id=l.id) AS n_qc,
  (SELECT COUNT(*) FROM catchup_requests cr WHERE cr.original_lesson_id=l.id) AS n_catchups";

/** Preserve filters across links. */
$keep = [
  'view'=>$view,'tutor_id'=>$flt_tutor,'student_id'=>$flt_student,'subject_id'=>$flt_subject,
  'status'=>$flt_status,'type'=>$flt_type,'time'=>$flt_time,'from'=>$flt_from,'to'=>$flt_to,
  'duration'=>$flt_duration,'demo'=>$flt_demo,'flag'=>$flt_flag,'q'=>$flt_q,'code'=>$flt_code,
  'year'=>$flt_year,'pp'=>$pp,'date'=>$date,'month'=>$month,'weeks'=>$weeks,
];
function qs(array $keep, array $over = []) {
  $b = [];
  foreach ($keep as $k=>$v) {
    if ($v === '' || $v === null || $v === 0 || $v === 'any') continue;
    if ($k === 'time' && $v === 'all') continue;
    if ($k === 'view' && $v === 'list') continue;
    $b[$k] = $v;
  }
  foreach ($over as $k=>$v) { if ($v === null) unset($b[$k]); else $b[$k] = $v; }
  return '?' . http_build_query($b);
}

// =====================================================================
// AJAX: lesson detail drawer
// =====================================================================
if (($_GET['ajax'] ?? '') === 'detail') {
  header('Content-Type: text/html; charset=utf-8');
  $id = (int)($_GET['id'] ?? 0);
  $q = $pdo->prepare("SELECT $LIST_COLS, l.scheduled_timezone,
        t.email AS tutor_email, t.phone_number AS tutor_phone,
        u.email AS student_email, u.phone_number AS student_phone, u.timezone AS student_tz,
        u.guardian_name, u.guardian_phone
      $JOINS WHERE l.id = ? LIMIT 1");
  $q->execute([$id]);
  $L = $q->fetch();
  if (!$L) { echo '<p class="text-gray-400 p-4">Lesson not found.</p>'; exit; }

  $st = utcToLocal($L['scheduled_start_time'], $adminTimezone);
  $en = utcToLocal($L['scheduled_end_time'], $adminTimezone);
  $studentTz = $L['student_tz'] ?: null;
  $rec = $pdo->prepare("SELECT status, provider, duration_seconds, file_size_bytes, recorded_at FROM lesson_recordings WHERE lesson_id=? ORDER BY id DESC LIMIT 1");
  $rec->execute([$id]); $R = $rec->fetch();
  $topics = $pdo->prepare("SELECT tp.name FROM lesson_topics_covered ltc JOIN topics tp ON tp.id=ltc.topic_id WHERE ltc.lesson_id=? LIMIT 12");
  try { $topics->execute([$id]); $topicRows = $topics->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $topicRows = []; }
  $rat = $pdo->prepare("SELECT rating, feedback FROM lesson_ratings WHERE lesson_id=? LIMIT 3");
  $rat->execute([$id]); $ratings = $rat->fetchAll();
  $deps = lessonDependents($pdo, $id);

  $row = function($label, $value, $muted = false) {
    echo '<div class="flex gap-3 py-1.5 border-b border-gray-800/60 text-sm">'
       . '<div class="w-32 flex-shrink-0 text-gray-500">' . h($label) . '</div>'
       . '<div class="flex-1 ' . ($muted ? 'text-gray-500' : 'text-gray-200') . ' break-words">' . $value . '</div></div>';
  };
  ?>
  <div class="space-y-4">
    <div>
      <div class="flex items-start justify-between gap-3">
        <div>
          <h4 class="text-lg font-semibold text-white"><?php echo h($L['subject_name']); ?></h4>
          <p class="text-sm text-gray-400"><?php echo h($st->format('l, j F Y')); ?> &middot; <?php echo h($st->format('g:i a')); ?>&ndash;<?php echo h($en->format('g:i a')); ?> &middot; <?php echo (int)$L['duration_min']; ?> min</p>
        </div>
        <span class="text-xs px-2.5 py-1 rounded-full flex-shrink-0 <?php echo $STATUS_BADGE[$L['status']] ?? 'bg-gray-700 text-gray-300'; ?>"><?php echo h($L['status']); ?></span>
      </div>
    </div>

    <div>
      <h5 class="text-[11px] uppercase tracking-wider text-gray-500 mb-1">People</h5>
      <?php
      $row('Tutor', h(trim($L['tutor_fn'].' '.$L['tutor_ln'])) . '<div class="text-xs text-gray-500">' . h($L['tutor_email'] ?? '') . ($L['tutor_phone'] ? ' &middot; ' . h($L['tutor_phone']) : '') . '</div>');
      if ($L['student_id']) {
        $row('Student', h(trim($L['student_fn'].' '.$L['student_ln']))
          . '<div class="text-xs text-gray-500">' . h($L['student_email'] ?? '') . ($L['student_phone'] ? ' &middot; ' . h($L['student_phone']) : '') . '</div>'
          . ($L['student_code'] ? '<div class="text-xs text-gray-500">code ' . h($L['student_code']) . ($L['student_year'] ? ' &middot; ' . h($L['student_year']) : '') . '</div>' : ''));
        if ($L['guardian_name'] || $L['guardian_phone']) {
          $row('Guardian', h(trim(($L['guardian_name'] ?? '') . ' ' . ($L['guardian_phone'] ?? ''))));
        }
      } else {
        $row('Student', 'No student &mdash; demo lesson', true);
      }
      ?>
    </div>

    <div>
      <h5 class="text-[11px] uppercase tracking-wider text-gray-500 mb-1">Timing</h5>
      <?php
      $row('Your time', h($st->format('j M Y, g:i a')) . ' <span class="text-gray-500">(' . h($adminTimezone) . ')</span>');
      if ($studentTz && $studentTz !== $adminTimezone) {
        $sl = utcToLocal($L['scheduled_start_time'], $studentTz);
        $row('Student time', h($sl->format('j M Y, g:i a')) . ' <span class="text-gray-500">(' . h($studentTz) . ')</span>');
      }
      $row('Booked in', h($L['scheduled_timezone'] ?: '—'), true);
      $row('Tutor joined',   $L['tutor_join_time']   ? '<span class="text-emerald-400">' . h(utcToLocal($L['tutor_join_time'], $adminTimezone)->format('g:i a')) . '</span>' : '<span class="text-gray-500">never</span>');
      $row('Student joined', $L['student_join_time'] ? '<span class="text-emerald-400">' . h(utcToLocal($L['student_join_time'], $adminTimezone)->format('g:i a')) . '</span>' : '<span class="text-gray-500">never</span>');
      ?>
    </div>

    <div>
      <h5 class="text-[11px] uppercase tracking-wider text-gray-500 mb-1">Class recording</h5>
      <?php if ($R && $R['status'] === 'Ready'): ?>
        <video src="../api/recording_url.php?lesson_id=<?php echo (int)$L['id']; ?>" controls preload="none"
               class="w-full rounded-lg bg-black/40 border border-gray-700 max-h-64"></video>
        <p class="text-xs text-gray-500 mt-1">
          <?php echo h($R['provider'] ?: 'recording'); ?>
          <?php if ($R['duration_seconds']): ?> &middot; <?php echo gmdate((int)$R['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', (int)$R['duration_seconds']); ?><?php endif; ?>
          <?php if ($R['file_size_bytes']): ?> &middot; <?php echo number_format($R['file_size_bytes']/1048576, 1); ?> MB<?php endif; ?>
          &middot; <a class="text-indigo-400 hover:text-indigo-300" href="../api/recording_url.php?lesson_id=<?php echo (int)$L['id']; ?>" target="_blank" rel="noopener">open</a>
        </p>
      <?php elseif ($R): ?>
        <p class="text-sm text-gray-400">Recording is <span class="text-amber-300"><?php echo h($R['status']); ?></span>.</p>
      <?php elseif ($L['status'] === 'Completed'): ?>
        <p class="text-sm text-gray-500">No recording was captured for this lesson.</p>
      <?php else: ?>
        <p class="text-sm text-gray-500">Not recorded<?php echo $L['recording_opt_in'] ? ' yet — opted in.' : '.'; ?></p>
      <?php endif; ?>
    </div>

    <div>
      <h5 class="text-[11px] uppercase tracking-wider text-gray-500 mb-1">Lesson</h5>
      <?php
      $row('Type', h($L['lesson_type']) . ($L['student_id'] === null && $L['lesson_type'] !== 'Demo' ? ' <span class="text-gray-500">(no student)</span>' : ''));
      $row('Notes', $L['tutor_notes'] ? nl2br(h($L['tutor_notes'])) : '<span class="text-gray-500">none</span>');
      $row('Topics', $topicRows ? h(implode(', ', $topicRows)) : '<span class="text-gray-500">none recorded</span>');
      $row('Room', $L['external_room_id'] ? h($L['external_room_id']) : '<span class="text-gray-500">built-in room</span>');
      $row('External link', $L['meeting_url'] ? '<a class="text-indigo-400 hover:text-indigo-300 break-all" target="_blank" rel="noopener noreferrer" href="' . h($L['meeting_url']) . '">' . h($L['meeting_url']) . '</a>' : '<span class="text-gray-500">none</span>');
      $row('WhatsApp event', $L['whatsapp_event_created'] ? '<span class="text-emerald-400">created</span>' : '<span class="text-gray-500">not created</span>');
      if ($ratings) {
        $txt = '';
        foreach ($ratings as $rr) $txt .= '<div>' . str_repeat('★', (int)$rr['rating']) . ' <span class="text-xs text-gray-500">' . h($rr['feedback'] ?? '') . '</span></div>';
        $row('Rating', $txt);
      }
      $row('QC', (int)$L['n_qc'] ? (int)$L['n_qc'] . ' assignment(s)' : '<span class="text-gray-500">none</span>');
      if ((int)$L['n_catchups']) $row('Catch-ups', (int)$L['n_catchups'] . ' request(s)');
      ?>
    </div>

    <?php if ($deps): ?>
      <p class="text-xs text-amber-300/80 bg-amber-500/5 border border-amber-500/20 rounded-lg px-3 py-2">
        Deleting this lesson would also destroy: <?php echo h(describeDependents($deps)); ?>.
      </p>
    <?php endif; ?>

    <div class="flex gap-2 pt-1">
      <button type="button" class="drawer-edit flex-1 bg-indigo-600 hover:bg-indigo-500 text-white text-sm py-2 rounded-lg font-semibold"
        data-id="<?php echo (int)$L['id']; ?>"
        data-tutor="<?php echo (int)$L['tutor_id']; ?>"
        data-student="<?php echo $L['student_id'] ? (int)$L['student_id'] : ''; ?>"
        data-subject="<?php echo (int)$L['subject_id']; ?>"
        data-date="<?php echo h($st->format('Y-m-d')); ?>"
        data-time="<?php echo h($st->format('H:i')); ?>"
        data-duration="<?php echo (int)$L['duration_min']; ?>"
        data-status="<?php echo h($L['status']); ?>"
        data-type="<?php echo h($L['lesson_type']); ?>"
        data-notes="<?php echo h($L['tutor_notes'] ?? ''); ?>"
        data-meeting-url="<?php echo h($L['meeting_url'] ?? ''); ?>"
        data-wa="<?php echo (int)$L['whatsapp_event_created']; ?>"
      >Edit lesson</button>
      <form method="post" class="js-delete flex-shrink-0"
            data-confirm="<?php echo h('Delete the ' . $st->format('j M Y g:i a') . ' lesson?' . ($deps ? "\n\nThis will also permanently destroy: " . describeDependents($deps) . '.' : '')); ?>">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete_lesson">
        <input type="hidden" name="lesson_id" value="<?php echo (int)$L['id']; ?>">
        <?php if ($deps): ?><input type="hidden" name="ack_destructive" value="1"><?php endif; ?>
        <button class="bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 text-sm py-2 px-4 rounded-lg font-semibold">Delete</button>
      </form>
    </div>
  </div>
  <?php
  exit;
}

// =====================================================================
// PER-VIEW DATA
// =====================================================================
$rows = []; $total_rows = 0; $total_pages = 1;
$dayRows = []; $calDays = []; $heat = []; $hourGrid = []; $heatMax = 0; $hourMax = 0;

if ($view === 'list') {
  $c = $pdo->prepare("SELECT COUNT(*) $JOINS $whereSql");
  $c->execute($whereParams);
  $total_rows  = (int)$c->fetchColumn();
  $total_pages = max(1, (int)ceil($total_rows / $pp));
  $page        = min($page, $total_pages);
  $offset      = ($page-1)*$pp;
  $q = $pdo->prepare("SELECT $LIST_COLS $JOINS $whereSql ORDER BY l.scheduled_start_time DESC LIMIT $pp OFFSET $offset");
  $q->execute($whereParams);
  $rows = $q->fetchAll();
}

if ($view === 'day') {
  [$dayA, $dayB] = localDayBounds($date, $adminTz);
  $extra = $whereSql ? $whereSql . ' AND ' : 'WHERE ';
  $q = $pdo->prepare("SELECT $LIST_COLS $JOINS {$extra} l.scheduled_start_time >= ? AND l.scheduled_start_time < ? ORDER BY l.scheduled_start_time ASC");
  $q->execute(array_merge($whereParams, [$dayA, $dayB]));
  $dayRows = $q->fetchAll();
  $total_rows = count($dayRows);
}

if ($view === 'calendar') {
  $mStart = DateTime::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $adminTz);
  $mEnd   = (clone $mStart)->modify('first day of next month');
  $gridStart = (clone $mStart)->modify('monday this week');
  if ($gridStart > $mStart) $gridStart->modify('-7 days');
  $gridEnd = (clone $mEnd)->modify('monday this week');
  if ($gridEnd < $mEnd) $gridEnd->modify('+7 days');

  $a = $gridStart->format('Y-m-d'); $b = $gridEnd->format('Y-m-d');
  $extra = $whereSql ? $whereSql . ' AND ' : 'WHERE ';
  $q = $pdo->prepare("SELECT l.id, l.status, l.lesson_type, l.scheduled_start_time,
        s.name AS subject_name, t.first_name AS tutor_fn, u.first_name AS student_fn
      $JOINS {$extra} l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
      ORDER BY l.scheduled_start_time ASC");
  $q->execute(array_merge($whereParams, [
    localToUtcStr($a, '00:00', $adminTz),
    localToUtcStr($b, '00:00', $adminTz),
  ]));
  foreach ($q as $r) {
    $d = utcToLocal($r['scheduled_start_time'], $adminTimezone);
    $calDays[$d->format('Y-m-d')][] = $r + ['_t' => $d->format('g:i a')];
  }
  $calGridStart = $gridStart; $calGridEnd = $gridEnd; $calMonthStart = $mStart;
}

if ($view === 'heatmap') {
  // Bucketed in PHP against the admin's calendar, like the dashboard charts —
  // GROUP BY DATE() would bucket by the UTC date while the labels are local days.
  $hmStart = (clone $nowLocal)->modify('monday this week')->modify('-' . (($weeks - 1) * 7) . ' days');
  $hmStart->setTime(0,0,0);
  $hmEndEx = (clone $nowLocal)->modify('monday this week')->modify('+7 days')->setTime(0,0,0);
  $extra = $whereSql ? $whereSql . ' AND ' : 'WHERE ';
  $q = $pdo->prepare("SELECT l.scheduled_start_time, l.status $JOINS {$extra} l.scheduled_start_time >= ? AND l.scheduled_start_time < ?");
  $q->execute(array_merge($whereParams, [
    $hmStart->format('Y-m-d H:i:s') === '' ? null : localToUtcStr($hmStart->format('Y-m-d'), '00:00', $adminTz),
    localToUtcStr($hmEndEx->format('Y-m-d'), '00:00', $adminTz),
  ]));
  foreach ($q as $r) {
    $d = utcToLocal($r['scheduled_start_time'], $adminTimezone);
    $key = $d->format('Y-m-d');
    $heat[$key] = ($heat[$key] ?? 0) + 1;
    $hourGrid[(int)$d->format('N')][(int)$d->format('G')] = ($hourGrid[(int)$d->format('N')][(int)$d->format('G')] ?? 0) + 1;
  }
  $heatMax = $heat ? max($heat) : 0;
  foreach ($hourGrid as $dw => $hrs) { $hourMax = max($hourMax, max($hrs)); }
  $heatStart = $hmStart; $heatEndEx = $hmEndEx;
}

// Chips
$unfinished_n = (function() use ($pdo,$nowUtcStr) {
  $s=$pdo->prepare("SELECT COUNT(*) FROM lessons WHERE status IN ('Scheduled','InProgress') AND scheduled_end_time < ?");
  $s->execute([$nowUtcStr]); return (int)$s->fetchColumn(); })();
$today_n = (function() use ($pdo,$nowLocal,$adminTz) {
  [$a,$b] = localDayBounds($nowLocal->format('Y-m-d'), $adminTz);
  $s=$pdo->prepare("SELECT COUNT(*) FROM lessons WHERE scheduled_start_time >= ? AND scheduled_start_time < ?");
  $s->execute([$a,$b]); return (int)$s->fetchColumn(); })();

$VIEWS = ['list'=>'List','day'=>'Day','calendar'=>'Calendar','heatmap'=>'Heatmap'];

/** Shared row renderer bits. */
function lessonChips(array $L): string {
  $out = [];
  if ((int)$L['rec_ready'])  $out[] = '<span class="text-[10px] px-1.5 py-0.5 rounded bg-violet-500/15 text-violet-300">rec</span>';
  elseif ((int)$L['rec_any'])$out[] = '<span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">rec…</span>';
  if ((int)$L['n_ratings'])  $out[] = '<span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-300">★</span>';
  if ((int)$L['n_qc'])       $out[] = '<span class="text-[10px] px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-300">QC</span>';
  if ((int)$L['n_catchups']) $out[] = '<span class="text-[10px] px-1.5 py-0.5 rounded bg-rose-500/15 text-rose-300">catch-up</span>';
  return implode(' ', $out);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin — Lessons</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.5rem .7rem;width:100%;font-size:.875rem}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.25)}
    ::-webkit-scrollbar{width:8px;height:8px} ::-webkit-scrollbar-track{background:#1f2937} ::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .card{background:#0f1623;border:1px solid rgba(255,255,255,.06);border-radius:.75rem}
    .table-wrap{overflow:auto;border:1px solid #374151;border-radius:.75rem;max-height:68vh}
    table thead th{position:sticky;top:0;background:#0f172a;z-index:1}
    .drawer{transition:transform .18s ease-out}
    .drawer.closed{transform:translateX(100%)}
    .hm-cell{width:13px;height:13px;border-radius:2px}
    .cal-day{min-height:104px}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-5 lg:p-7 min-w-0">
    <header class="flex flex-wrap items-start justify-between gap-4 mb-4">
      <div>
        <h2 class="text-2xl font-bold text-white">Lessons</h2>
        <p class="text-gray-400 text-sm mt-0.5">
          <span title="Every time on this page is shown in your timezone"><?php echo h($adminTimezone); ?></span>
          <?php if ($view === 'list'): ?> &middot; <?php echo number_format($total_rows); ?> matching<?php endif; ?>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button id="openSingleModal" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">New lesson</button>
        <button id="openRecurringModal" class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Recurring series</button>
      </div>
    </header>

    <?php if (!empty($oks) || !empty($errs)): ?>
      <div class="mb-4 space-y-2">
        <?php foreach ($oks as $m): ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div><?php endforeach; ?>
        <?php foreach ($errs as $m): ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- View switcher + quick filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <div class="inline-flex rounded-lg bg-gray-900/70 ring-1 ring-gray-700/60 p-1">
        <?php foreach ($VIEWS as $vk => $vl):
          $on = ($view === $vk); ?>
          <a href="<?php echo h(qs($keep, ['view'=>$vk,'p'=>null])); ?>"
             class="px-3.5 py-1.5 rounded-md text-sm font-medium transition <?php echo $on ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-gray-200'; ?>"><?php echo h($vl); ?></a>
        <?php endforeach; ?>
      </div>
      <?php
      $chips = [
        ['Today',           qs($keep, ['view'=>'day','date'=>$nowLocal->format('Y-m-d'),'flag'=>null,'time'=>null,'p'=>null]), ($view==='day' && $date===$nowLocal->format('Y-m-d')), $today_n, false],
        ['Upcoming',        qs($keep, ['view'=>'list','time'=>'future','flag'=>null,'p'=>null]), ($flt_time==='future'), null, false],
        ['Needs finishing', qs($keep, ['view'=>'list','flag'=>'unfinished','time'=>null,'p'=>null]), ($flt_flag==='unfinished'), $unfinished_n, true],
        ['Recorded',        qs($keep, ['view'=>'list','flag'=>'recorded','p'=>null]), ($flt_flag==='recorded'), null, false],
      ];
      foreach ($chips as [$label,$href,$active,$count,$warn]):
        $cls = $active ? 'bg-indigo-500/15 text-indigo-300 ring-indigo-500/40'
             : (($warn && $count) ? 'bg-amber-500/10 text-amber-300 ring-amber-500/30 hover:bg-amber-500/20'
             : 'bg-gray-900/60 text-gray-400 hover:text-gray-200 ring-gray-700/60'); ?>
        <a href="<?php echo h($href); ?>" class="px-3.5 py-1.5 rounded-full text-sm font-medium ring-1 transition <?php echo $cls; ?>">
          <?php echo h($label); ?><?php if ($count !== null): ?> <span class="opacity-70"><?php echo number_format($count); ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Shared filters -->
    <form method="get" class="card p-3 mb-4">
      <input type="hidden" name="view" value="<?php echo h($view); ?>">
      <?php if ($view==='day'): ?><input type="hidden" name="date" value="<?php echo h($date); ?>"><?php endif; ?>
      <?php if ($view==='calendar'): ?><input type="hidden" name="month" value="<?php echo h($month); ?>"><?php endif; ?>
      <?php if ($view==='heatmap'): ?><input type="hidden" name="weeks" value="<?php echo (int)$weeks; ?>"><?php endif; ?>
      <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-2">
        <input type="text" name="q" value="<?php echo h($flt_q); ?>" placeholder="Search name, subject, code…" class="form-input col-span-2">
        <select name="tutor_id" class="form-input">
          <option value="">All tutors</option>
          <?php foreach($tutors as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo $flt_tutor===(int)$t['id']?'selected':''; ?>><?php echo h($t['first_name'].' '.$t['last_name']); ?></option><?php endforeach; ?>
        </select>
        <select name="student_id" class="form-input">
          <option value="">All students</option>
          <?php foreach($students as $s2): ?><option value="<?php echo (int)$s2['id']; ?>" <?php echo $flt_student===(int)$s2['id']?'selected':''; ?>><?php echo h($s2['first_name'].' '.$s2['last_name']); ?></option><?php endforeach; ?>
        </select>
        <select name="subject_id" class="form-input">
          <option value="">All subjects</option>
          <?php foreach($subjects as $s2): ?><option value="<?php echo (int)$s2['id']; ?>" <?php echo $flt_subject===(int)$s2['id']?'selected':''; ?>><?php echo h($s2['name']); ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-input">
          <option value="">Any status</option>
          <?php foreach($STATUS_OPTS as $stt): ?><option value="<?php echo h($stt); ?>" <?php echo $flt_status===$stt?'selected':''; ?>><?php echo h($stt); ?></option><?php endforeach; ?>
        </select>
        <select name="demo" class="form-input">
          <option value="any">Demos &amp; lessons</option>
          <option value="only"    <?php echo $flt_demo==='only'?'selected':''; ?>>Demos only</option>
          <option value="exclude" <?php echo $flt_demo==='exclude'?'selected':''; ?>>Exclude demos</option>
        </select>
        <select name="duration" class="form-input">
          <option value="any">Any length</option>
          <option value="30" <?php echo $flt_duration==='30'?'selected':''; ?>>30 min</option>
          <option value="60" <?php echo $flt_duration==='60'?'selected':''; ?>>60 min</option>
        </select>
        <select name="flag" class="form-input">
          <option value="">Any</option>
          <option value="unfinished"  <?php echo $flt_flag==='unfinished'?'selected':''; ?>>Needs finishing</option>
          <option value="recorded"    <?php echo $flt_flag==='recorded'?'selected':''; ?>>Has a recording</option>
          <option value="norecording" <?php echo $flt_flag==='norecording'?'selected':''; ?>>Completed, no recording</option>
          <option value="nolink"      <?php echo $flt_flag==='nolink'?'selected':''; ?>>No external link</option>
        </select>
        <input type="text" name="code" value="<?php echo h($flt_code); ?>" placeholder="Customer code" class="form-input">
        <?php if ($view === 'list'): ?>
          <input type="date" name="from" value="<?php echo h($flt_from); ?>" class="form-input" title="From (your timezone)">
          <input type="date" name="to"   value="<?php echo h($flt_to); ?>"   class="form-input" title="To (your timezone)">
        <?php endif; ?>
        <div class="flex gap-2 col-span-2 md:col-span-4 xl:col-span-6 justify-end pt-1">
          <a href="admin_lessons.php?view=<?php echo h($view); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">Reset</a>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Apply</button>
        </div>
      </div>
    </form>

<?php if ($view === 'list'): ?>
    <section class="card p-4">
      <div class="table-wrap">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-gray-300 border-b border-gray-700">
              <th class="text-left px-3 py-2 w-44">When</th>
              <th class="text-left px-3 py-2 w-40">Tutor</th>
              <th class="text-left px-3 py-2 w-40">Student</th>
              <th class="text-left px-3 py-2 w-36">Subject</th>
              <th class="text-center px-3 py-2 w-16">Len</th>
              <th class="text-left px-3 py-2 w-40">Status</th>
              <th class="text-left px-3 py-2 w-40">Attached</th>
              <th class="text-right px-3 py-2 w-20">&nbsp;</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="8" class="text-center text-gray-400 py-10">No lessons match your filters.</td></tr>
            <?php else: foreach ($rows as $L):
              $st_l = utcToLocal($L['scheduled_start_time'], $adminTimezone);
              $en_l = utcToLocal($L['scheduled_end_time'],   $adminTimezone);
              $isDemo = ($L['lesson_type'] === 'Demo' || $L['student_id'] === null);
              $unfinished = ($L['scheduled_end_time'] < $nowUtcStr) && in_array($L['status'], ['Scheduled','InProgress'], true);
            ?>
              <tr class="border-b border-gray-800 hover:bg-gray-800/70 cursor-pointer js-open-detail <?php echo $unfinished ? 'bg-amber-500/[0.04]' : ''; ?>" data-id="<?php echo (int)$L['id']; ?>">
                <td class="px-3 py-2 align-top text-gray-300 whitespace-nowrap">
                  <?php echo h($st_l->format('j M Y')); ?><br>
                  <span class="text-xs text-gray-500"><?php echo h($st_l->format('g:i a').' – '.$en_l->format('g:i a')); ?></span>
                </td>
                <td class="px-3 py-2 align-top text-white">
                  <?php echo h(trim(($L['tutor_fn']??'').' '.($L['tutor_ln']??''))); ?>
                  <?php if ($L['tutor_join_time']): ?><span class="block text-[10px] text-emerald-400/80">joined</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top text-gray-300">
                  <?php if ($L['student_id']): ?>
                    <?php echo h(trim(($L['student_fn']??'').' '.($L['student_ln']??''))); ?>
                    <?php if ($L['student_join_time']): ?><span class="block text-[10px] text-emerald-400/80">joined</span><?php endif; ?>
                  <?php else: ?><span class="text-gray-500">no student</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top text-white">
                  <?php echo h($L['subject_name']); ?>
                  <?php if ($isDemo): ?><span class="block text-[10px] text-amber-300/90">Demo</span>
                  <?php elseif ($L['lesson_type']==='Catchup'): ?><span class="block text-[10px] text-sky-300/90">Catch-up</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top text-center text-gray-300"><?php echo (int)$L['duration_min']; ?>m</td>
                <td class="px-3 py-2 align-top">
                  <span class="text-xs px-2 py-1 rounded-full <?php echo $STATUS_BADGE[$L['status']] ?? 'bg-gray-700 text-gray-300'; ?>"><?php echo h($L['status']); ?></span>
                  <?php if ($unfinished): ?><span class="block text-[10px] text-amber-400 mt-1">ended, not closed</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top"><?php echo lessonChips($L) ?: '<span class="text-gray-600">&mdash;</span>'; ?></td>
                <td class="px-3 py-2 align-top text-right"><span class="text-indigo-400 text-xs font-semibold">Open</span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between pt-3 text-sm">
          <div><?php if ($page>1): ?><a class="px-3 py-1.5 bg-gray-700 rounded-md hover:bg-gray-600" href="<?php echo h(qs($keep,['p'=>$page-1])); ?>">Prev</a><?php endif; ?></div>
          <div class="text-gray-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
          <div><?php if ($page<$total_pages): ?><a class="px-3 py-1.5 bg-gray-700 rounded-md hover:bg-gray-600" href="<?php echo h(qs($keep,['p'=>$page+1])); ?>">Next</a><?php endif; ?></div>
        </div>
      <?php endif; ?>
    </section>

<?php elseif ($view === 'day'):
  $dPrev = (new DateTime($date, $adminTz))->modify('-1 day')->format('Y-m-d');
  $dNext = (new DateTime($date, $adminTz))->modify('+1 day')->format('Y-m-d');
?>
    <section class="card p-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
          <a href="<?php echo h(qs($keep,['date'=>$dPrev])); ?>" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm">‹</a>
          <h3 class="text-lg font-semibold text-white"><?php echo h((new DateTime($date, $adminTz))->format('l, j F Y')); ?></h3>
          <a href="<?php echo h(qs($keep,['date'=>$dNext])); ?>" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm">›</a>
          <a href="<?php echo h(qs($keep,['date'=>$nowLocal->format('Y-m-d')])); ?>" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 rounded-lg text-sm text-gray-300">Today</a>
        </div>
        <form method="get" class="flex items-center gap-2">
          <?php foreach ($keep as $k=>$v) { if ($k==='date'||$v===''||$v===0||$v===null||$v==='any') continue; echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">'; } ?>
          <input type="date" name="date" value="<?php echo h($date); ?>" class="form-input w-auto" onchange="this.form.submit()">
        </form>
      </div>

      <?php if (empty($dayRows)): ?>
        <p class="text-gray-400 text-center py-12 text-sm">No lessons on this day.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($dayRows as $L):
            $st_l = utcToLocal($L['scheduled_start_time'], $adminTimezone);
            $en_l = utcToLocal($L['scheduled_end_time'],   $adminTimezone);
            $unfinished = ($L['scheduled_end_time'] < $nowUtcStr) && in_array($L['status'], ['Scheduled','InProgress'], true);
          ?>
            <div class="js-open-detail cursor-pointer flex items-start gap-4 bg-gray-800/50 hover:bg-gray-800 px-4 py-3 rounded-lg border-l-2 <?php echo $unfinished ? 'border-amber-500' : 'border-transparent'; ?>" data-id="<?php echo (int)$L['id']; ?>">
              <div class="w-24 flex-shrink-0">
                <p class="text-sm font-semibold text-white tabular-nums"><?php echo h($st_l->format('g:i a')); ?></p>
                <p class="text-[11px] text-gray-500 tabular-nums">to <?php echo h($en_l->format('g:i a')); ?></p>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-white truncate">
                  <?php echo h($L['subject_name']); ?>
                  <?php if ($L['lesson_type']!=='Regular'): ?><span class="text-[10px] text-amber-300/90 ml-1"><?php echo h($L['lesson_type']); ?></span><?php endif; ?>
                </p>
                <p class="text-xs text-gray-400 truncate">
                  <?php echo h(trim($L['tutor_fn'].' '.$L['tutor_ln'])); ?> &rarr;
                  <?php echo $L['student_id'] ? h(trim($L['student_fn'].' '.$L['student_ln'])) : '<span class="text-gray-500">no student</span>'; ?>
                  <?php if ($L['student_code']): ?><span class="text-gray-600"> &middot; <?php echo h($L['student_code']); ?></span><?php endif; ?>
                </p>
                <div class="mt-1"><?php echo lessonChips($L); ?></div>
              </div>
              <span class="text-[11px] px-2.5 py-1 rounded-full flex-shrink-0 <?php echo $STATUS_BADGE[$L['status']] ?? 'bg-gray-700 text-gray-300'; ?>"><?php echo h($L['status']); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

<?php elseif ($view === 'calendar'):
  $mPrev = (clone $calMonthStart)->modify('-1 month')->format('Y-m');
  $mNext = (clone $calMonthStart)->modify('+1 month')->format('Y-m');
?>
    <section class="card p-4">
      <div class="flex items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
          <a href="<?php echo h(qs($keep,['month'=>$mPrev])); ?>" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm">‹</a>
          <h3 class="text-lg font-semibold text-white"><?php echo h($calMonthStart->format('F Y')); ?></h3>
          <a href="<?php echo h(qs($keep,['month'=>$mNext])); ?>" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm">›</a>
          <a href="<?php echo h(qs($keep,['month'=>$nowLocal->format('Y-m')])); ?>" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 rounded-lg text-sm text-gray-300">This month</a>
        </div>
      </div>
      <div class="grid grid-cols-7 gap-1 mb-1">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?>
          <div class="text-[11px] uppercase tracking-wider text-gray-500 text-center py-1"><?php echo $dn; ?></div>
        <?php endforeach; ?>
      </div>
      <div class="grid grid-cols-7 gap-1">
        <?php
        $cur = clone $calGridStart;
        while ($cur < $calGridEnd):
          $k = $cur->format('Y-m-d');
          $inMonth = ($cur->format('Y-m') === $calMonthStart->format('Y-m'));
          $isToday = ($k === $nowLocal->format('Y-m-d'));
          $items = $calDays[$k] ?? [];
        ?>
          <div class="cal-day rounded-lg p-1.5 <?php echo $inMonth ? 'bg-gray-900/60' : 'bg-gray-900/20'; ?> <?php echo $isToday ? 'ring-1 ring-indigo-500/60' : ''; ?>">
            <div class="flex items-center justify-between mb-1">
              <a href="<?php echo h(qs($keep,['view'=>'day','date'=>$k])); ?>"
                 class="text-[11px] <?php echo $inMonth ? 'text-gray-300' : 'text-gray-600'; ?> hover:text-indigo-300"><?php echo (int)$cur->format('j'); ?></a>
              <?php if (count($items) > 3): ?><span class="text-[10px] text-gray-500"><?php echo count($items); ?></span><?php endif; ?>
            </div>
            <?php foreach (array_slice($items, 0, 3) as $it): ?>
              <div class="js-open-detail cursor-pointer text-[10px] leading-tight mb-0.5 px-1 py-0.5 rounded truncate hover:bg-gray-700/60"
                   data-id="<?php echo (int)$it['id']; ?>"
                   title="<?php echo h($it['_t'].' '.$it['subject_name'].' — '.$it['tutor_fn']); ?>"
                   style="background:<?php echo h(($STATUS_DOT[$it['status']] ?? '#6b7280')); ?>1f;color:<?php echo h($STATUS_DOT[$it['status']] ?? '#9ca3af'); ?>">
                <?php echo h($it['_t']); ?> <?php echo h($it['subject_name']); ?>
              </div>
            <?php endforeach; ?>
            <?php if (count($items) > 3): ?>
              <a href="<?php echo h(qs($keep,['view'=>'day','date'=>$k])); ?>" class="text-[10px] text-indigo-400 hover:text-indigo-300">+<?php echo count($items)-3; ?> more</a>
            <?php endif; ?>
          </div>
        <?php $cur->modify('+1 day'); endwhile; ?>
      </div>
    </section>

<?php elseif ($view === 'heatmap'): ?>
    <section class="card p-5 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h3 class="text-base font-semibold text-white">Lesson volume</h3>
          <p class="text-xs text-gray-500">Each square is one day. Click a day to open it.</p>
        </div>
        <div class="inline-flex rounded-lg bg-gray-900/70 ring-1 ring-gray-700/60 p-1">
          <?php foreach ([13=>'13w',26=>'26w',52=>'52w'] as $wk=>$wl): ?>
            <a href="<?php echo h(qs($keep,['weeks'=>$wk])); ?>" class="px-3 py-1 rounded-md text-xs font-medium <?php echo $weeks===$wk?'bg-indigo-600 text-white':'text-gray-400 hover:text-gray-200'; ?>"><?php echo $wl; ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($heatMax === 0): ?>
        <p class="text-sm text-gray-500 py-8 text-center">No lessons in this window.</p>
      <?php else:
        // Columns are weeks, rows are Mon–Sun.
        $cols = [];
        $cur = clone $heatStart;
        while ($cur < $heatEndEx) { $cols[][] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }
        $weeksArr = array_chunk(array_merge(...$cols), 7);
        $shade = function(int $n) use ($heatMax) {
          if ($n === 0) return '#1f2937';
          $r = $n / max(1,$heatMax);
          if ($r <= .25) return '#312e81';
          if ($r <= .50) return '#4338ca';
          if ($r <= .75) return '#6366f1';
          return '#a5b4fc';
        };
      ?>
        <div class="flex gap-2 overflow-x-auto pb-2">
          <div class="flex flex-col gap-[3px] pt-[2px] flex-shrink-0">
            <?php foreach (['Mon','','Wed','','Fri','','Sun'] as $lbl): ?>
              <div class="hm-cell !w-8 text-[9px] text-gray-500 leading-[13px]"><?php echo $lbl; ?></div>
            <?php endforeach; ?>
          </div>
          <div class="flex gap-[3px]">
            <?php foreach ($weeksArr as $wk): ?>
              <div class="flex flex-col gap-[3px]">
                <?php foreach ($wk as $d):
                  $n = $heat[$d] ?? 0; ?>
                  <a href="<?php echo h(qs($keep,['view'=>'day','date'=>$d])); ?>"
                     class="hm-cell block hover:ring-1 hover:ring-white/40"
                     style="background:<?php echo $shade($n); ?>"
                     title="<?php echo h((new DateTime($d, $adminTz))->format('D j M Y')); ?>: <?php echo $n; ?> lesson<?php echo $n===1?'':'s'; ?>"></a>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flex items-center gap-2 mt-3 text-[11px] text-gray-500">
          <span>Less</span>
          <?php foreach (['#1f2937','#312e81','#4338ca','#6366f1','#a5b4fc'] as $c): ?>
            <span class="hm-cell inline-block" style="background:<?php echo $c; ?>"></span>
          <?php endforeach; ?>
          <span>More</span>
          <span class="ml-3">Busiest day: <?php echo (int)$heatMax; ?> lessons</span>
        </div>
      <?php endif; ?>
    </section>

    <section class="card p-5">
      <h3 class="text-base font-semibold text-white mb-1">When lessons happen</h3>
      <p class="text-xs text-gray-500 mb-4">Weekday against hour, over the same window — where the teaching load actually sits.</p>
      <?php if ($hourMax === 0): ?>
        <p class="text-sm text-gray-500 py-8 text-center">No lessons in this window.</p>
      <?php else:
        $hours = range(6, 22);
        $shade2 = function(int $n) use ($hourMax) {
          if ($n === 0) return '#1f2937';
          $r = $n / max(1,$hourMax);
          if ($r <= .25) return '#134e4a';
          if ($r <= .50) return '#0f766e';
          if ($r <= .75) return '#14b8a6';
          return '#5eead4';
        };
      ?>
        <div class="overflow-x-auto">
          <table class="text-[10px] border-separate" style="border-spacing:3px">
            <thead>
              <tr><th></th><?php foreach ($hours as $hh): ?><th class="text-gray-500 font-normal w-6"><?php echo $hh; ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
              <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $dw=>$dl): ?>
                <tr>
                  <td class="text-gray-500 pr-1 text-right"><?php echo $dl; ?></td>
                  <?php foreach ($hours as $hh):
                    $n = $hourGrid[$dw][$hh] ?? 0; ?>
                    <td class="rounded" style="width:22px;height:18px;background:<?php echo $shade2($n); ?>"
                        title="<?php echo $dl; ?> <?php echo $hh; ?>:00 — <?php echo $n; ?> lesson<?php echo $n===1?'':'s'; ?>"></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-[11px] text-gray-500 mt-3">Busiest slot: <?php echo (int)$hourMax; ?> lessons. Hours shown in <?php echo h($adminTimezone); ?>.</p>
      <?php endif; ?>
    </section>
<?php endif; ?>
  </main>

  <!-- DETAIL DRAWER -->
  <div id="drawerBackdrop" class="fixed inset-0 bg-black/50 hidden z-40"></div>
  <aside id="drawer" class="drawer closed fixed top-0 right-0 h-full w-full max-w-md bg-gray-900 border-l border-gray-700 z-50 overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800 sticky top-0 bg-gray-900 z-10">
      <h3 class="text-white font-semibold">Lesson detail</h3>
      <button type="button" id="drawerClose" class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
    </div>
    <div id="drawerBody" class="p-5"><p class="text-gray-400 text-sm">Loading…</p></div>
  </aside>

  <?php
  // Shared create/edit modal markup
  $renderPeople = function($prefix) use ($tutors,$students,$subjects) { };
  ?>
  <!-- CREATE ONE-OFF -->
  <div id="singleModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/65" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">Create lesson</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-gray-200 text-xl leading-none">&times;</button>
      </div>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="create_single">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Tutor</label>
            <select name="tutor_id" class="form-input" required><option value="">Select tutor…</option>
              <?php foreach($tutors as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['first_name'].' '.$t['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Subject</label>
            <select name="subject_id" class="form-input" required><option value="">Select subject…</option>
              <?php foreach($subjects as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['name']); ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Date</label><input type="date" name="lesson_date" class="form-input" required value="<?php echo h($view==='day'?$date:$nowLocal->format('Y-m-d')); ?>"></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Start time</label><input type="time" name="lesson_time" class="form-input" required value="16:00"></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Duration</label>
            <select name="duration" class="form-input" id="durationSingle">
              <?php if ($lessonPrefs['allow30']): ?><option value="30">30 minutes</option><?php endif; ?>
              <?php if ($lessonPrefs['allow60']): ?><option value="60" selected>60 minutes</option><?php endif; ?>
            </select></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Student <span class="text-xs text-gray-500">(required for 60-min)</span></label>
            <select name="student_id" id="studentSelSingle" class="form-input"><option value="">— None (demo) —</option>
              <?php foreach($students as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['first_name'].' '.$s2['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Notes</label><input name="notes" class="form-input" placeholder="Optional…"></div>
        </div>
        <div><label class="block text-sm text-gray-300 mb-1.5">External meeting link <span class="text-xs text-gray-500">(optional)</span></label>
          <input type="url" name="meeting_url" class="form-input" placeholder="https://…">
          <p class="text-xs text-gray-500 mt-1">Lessons run in the built-in room; this is only for an external call.</p></div>
        <div class="flex justify-end gap-2 pt-3 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded-lg font-semibold">Create</button>
        </div>
      </form>
    </div>
  </div>

  <!-- CREATE RECURRING -->
  <div id="recurringModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/65" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">Recurring series</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-gray-200 text-xl leading-none">&times;</button>
      </div>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="create_recurring">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Tutor</label>
            <select name="tutor_id" class="form-input" required><option value="">Select tutor…</option>
              <?php foreach($tutors as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['first_name'].' '.$t['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Subject</label>
            <select name="subject_id" class="form-input" required><option value="">Select subject…</option>
              <?php foreach($subjects as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['name']); ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Start date</label><input type="date" name="start_date" class="form-input" required></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">End date</label><input type="date" name="end_date" class="form-input" required></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Start time</label><input type="time" name="start_time" class="form-input" required value="16:00"></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Duration</label>
            <select name="duration" class="form-input" id="durationSel">
              <?php if ($lessonPrefs['allow30']): ?><option value="30">30 minutes</option><?php endif; ?>
              <?php if ($lessonPrefs['allow60']): ?><option value="60" selected>60 minutes</option><?php endif; ?>
            </select></div>
        </div>
        <div><label class="block text-sm text-gray-300 mb-1.5">Weekdays</label>
          <div class="grid grid-cols-4 md:grid-cols-7 gap-2">
            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$lbl): ?>
              <label class="bg-gray-800 rounded-md px-3 py-2 flex items-center gap-2 cursor-pointer hover:bg-gray-700 text-sm">
                <input type="checkbox" name="days[]" value="<?php echo $n; ?>"> <span><?php echo $lbl; ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Student <span class="text-xs text-gray-500">(required for 60-min)</span></label>
            <select name="student_id" id="studentSel" class="form-input"><option value="">— None (demo) —</option>
              <?php foreach($students as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['first_name'].' '.$s2['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Notes</label><input name="notes" class="form-input" placeholder="Optional…"></div>
        </div>
        <div><label class="block text-sm text-gray-300 mb-1.5">External meeting link <span class="text-xs text-gray-500">(optional)</span></label>
          <input type="url" name="meeting_url" class="form-input" placeholder="https://…"></div>
        <div class="flex justify-end gap-2 pt-3 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-4 py-2 rounded-lg font-semibold">Create series</button>
        </div>
      </form>
    </div>
  </div>

  <!-- EDIT -->
  <div id="editModal" class="modal fixed inset-0 hidden items-center justify-center z-[60]">
    <div class="absolute inset-0 bg-black/65" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">Edit lesson</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-gray-200 text-xl leading-none">&times;</button>
      </div>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="update_lesson">
        <input type="hidden" name="lesson_id" id="ed_id">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Tutor</label>
            <select name="tutor_id" id="ed_tutor" class="form-input" required>
              <?php foreach($tutors as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['first_name'].' '.$t['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Subject</label>
            <select name="subject_id" id="ed_subject" class="form-input" required>
              <?php foreach($subjects as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['name']); ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Date</label><input type="date" name="lesson_date" id="ed_date" class="form-input" required></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Start time</label><input type="time" name="lesson_time" id="ed_time" class="form-input" required></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Duration</label>
            <select name="duration" id="ed_duration" class="form-input"><option value="30">30 minutes</option><option value="60">60 minutes</option></select></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Student</label>
            <select name="student_id" id="ed_student" class="form-input"><option value="">— None (demo) —</option>
              <?php foreach($students as $s2): ?><option value="<?php echo (int)$s2['id']; ?>"><?php echo h($s2['first_name'].' '.$s2['last_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Status</label>
            <select name="status" id="ed_status" class="form-input" required>
              <?php foreach($STATUS_OPTS as $stt): ?><option value="<?php echo h($stt); ?>"><?php echo h($stt); ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Type</label>
            <select name="lesson_type" id="ed_type" class="form-input">
              <?php foreach($TYPE_OPTS as $tt): ?><option value="<?php echo h($tt); ?>"><?php echo h($tt); ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div><label class="block text-sm text-gray-300 mb-1.5">Notes</label><input name="notes" id="ed_notes" class="form-input"></div>
        <div><label class="block text-sm text-gray-300 mb-1.5">External meeting link</label><input type="url" name="meeting_url" id="ed_meeting_url" class="form-input" placeholder="https://…"></div>
        <label class="flex items-center gap-2 text-sm text-gray-300">
          <input type="checkbox" name="whatsapp_event_created" id="ed_wa" value="1"> WhatsApp calendar event created
        </label>
        <div class="flex justify-end gap-2 pt-3 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-4 py-2 rounded-lg font-semibold">Save changes</button>
        </div>
      </form>
    </div>
  </div>

<script>
(function(){
  const open  = (el) => { if (el) { el.classList.remove('hidden'); el.classList.add('flex'); } };
  const close = (el) => { if (el) { el.classList.add('hidden'); el.classList.remove('flex'); } };
  const closeModals = () => document.querySelectorAll('.modal').forEach(close);

  document.querySelectorAll('[data-close-modal]').forEach(el =>
    el.addEventListener('click', () => close(el.closest('.modal'))));

  document.getElementById('openSingleModal').addEventListener('click', () => open(document.getElementById('singleModal')));
  document.getElementById('openRecurringModal').addEventListener('click', () => open(document.getElementById('recurringModal')));

  // Drawer
  const drawer = document.getElementById('drawer');
  const backdrop = document.getElementById('drawerBackdrop');
  const body = document.getElementById('drawerBody');
  const openDrawer = async (id) => {
    body.innerHTML = '<p class="text-gray-400 text-sm">Loading…</p>';
    drawer.classList.remove('closed'); backdrop.classList.remove('hidden');
    try {
      const r = await fetch('admin_lessons.php?ajax=detail&id=' + encodeURIComponent(id), {credentials:'same-origin'});
      body.innerHTML = await r.text();
      wireDrawer();
    } catch (e) { body.innerHTML = '<p class="text-rose-400 text-sm">Could not load this lesson.</p>'; }
  };
  const closeDrawer = () => { drawer.classList.add('closed'); backdrop.classList.add('hidden'); };
  document.getElementById('drawerClose').addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeModals(); closeDrawer(); } });

  document.querySelectorAll('.js-open-detail').forEach(el => {
    el.addEventListener('click', (e) => {
      if (e.target.closest('a,button,form')) return;
      openDrawer(el.dataset.id);
    });
  });

  const editModal = document.getElementById('editModal');
  function fillEdit(d) {
    const set = (id,v) => { const el = document.getElementById(id); if (el) el.value = v; };
    set('ed_id', d.id); set('ed_tutor', d.tutor); set('ed_subject', d.subject);
    set('ed_date', d.date); set('ed_time', d.time); set('ed_duration', d.duration);
    set('ed_status', d.status); set('ed_type', d.type || 'Regular');
    set('ed_notes', d.notes || ''); set('ed_meeting_url', d.meetingUrl || '');
    set('ed_student', d.student || '');
    document.getElementById('ed_wa').checked = (d.wa === '1');
    document.getElementById('ed_student').required = (String(d.duration) === '60');
    open(editModal);
  }
  function wireDrawer() {
    const b = body.querySelector('.drawer-edit');
    if (b) b.addEventListener('click', () => fillEdit(b.dataset));
    body.querySelectorAll('form.js-delete').forEach(f =>
      f.addEventListener('submit', (e) => { if (!window.confirm(f.dataset.confirm || 'Delete?')) e.preventDefault(); }));
  }

  const dur = document.getElementById('ed_duration');
  if (dur) dur.addEventListener('change', () => {
    document.getElementById('ed_student').required = (dur.value === '60');
  });
  [['durationSingle','studentSelSingle'],['durationSel','studentSel']].forEach(([d,s]) => {
    const D = document.getElementById(d), S = document.getElementById(s);
    if (!D || !S) return;
    const sync = () => { S.required = (D.value === '60'); };
    D.addEventListener('change', sync); sync();
  });
})();
</script>
</body>
</html>
