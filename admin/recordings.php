<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// admin/recordings.php — Admin view of lesson recordings & attendance

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? '';

require_once __DIR__ . '/db_connect.php';

// ---- Admin check (role or admin_details) ----
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
  $st = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
  $st->execute([$user_id]);
  $is_admin = (bool)$st->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// ---- Helpers ----
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
// base prefix like "/tutsys"
function app_base_prefix(): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $prefix = rtrim(dirname(dirname($script)), '/\\');
  return $prefix === '/' ? '' : $prefix;
}
$BASE = app_base_prefix();

// Normalize any file_url from DB into a public URL we can <video src> or <a href>
function public_upload_url(string $file_url, string $base_prefix): string {
  $u = trim($file_url);
  if ($u === '') return '';
  if (preg_match('#^https?://#i', $u)) return $u; // external
  // strip ../ or ./ and ensure it starts with /uploads/...
  $u = ltrim($u, '/');
  $u = preg_replace('#^(\.?/)+#', '', $u);
  $u = preg_replace('#^\.\./#', '', $u);
  if (stripos($u, 'uploads/') !== 0) {
    // if someone stored "recordings/abc.mp4", force under uploads
    $u = 'uploads/' . $u;
  }
  return $base_prefix . '/' . $u;
}

// Helper function to reconnect if connection is lost
function reconnect_pdo_if_needed(PDO &$pdo): void {
  try {
    $pdo->query("SELECT 1");
  } catch (PDOException $e) {
    // Connection is dead, reconnect
    if (strpos($e->getMessage(), '2006') !== false || 
        strpos($e->getMessage(), 'MySQL server has gone away') !== false ||
        strpos($e->getMessage(), 'HY000') !== false) {
      $host = DB_HOST;
      $db   = DB_NAME;
      $user = DB_USER;
      $pass = DB_PASSWORD;
      $charset = 'utf8mb4';
      $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
      $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ];
      try {
        $pdo = new PDO($dsn, $user, $pass, $options);
      } catch (PDOException $e2) {
        error_log("Reconnection failed: " . $e2->getMessage());
        throw $e2;
      }
    } else {
      throw $e;
    }
  }
}

// Optional columns detection with reconnection handling
function has_column(PDO &$pdo, string $table, string $col): bool {
  try {
    reconnect_pdo_if_needed($pdo);
    $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
  } catch (PDOException $e) {
    // If still fails, return false (column doesn't exist or connection issue)
    error_log("Error checking column {$table}.{$col}: " . $e->getMessage());
    return false;
  }
}
$HAS_TUTOR_JOIN = has_column($pdo, 'lessons', 'tutor_join_time');
$HW_HAS_LESSON  = has_column($pdo, 'homework', 'lesson_id');

$lesson_type_options = [
  'Regular' => 'Regular',
  'Catchup' => 'Catchup',
  'Demo-Enrolled' => 'Demo-Enrolled',
  'Demo' => 'Demo',
  'Substitute Tutor' => 'Substitute Tutor',
  'Extra' => 'Extra',
  'Free' => 'Free',
  'Trial' => 'Trial',
];

$day_of_week_options = [
  '1' => 'Sunday',
  '2' => 'Monday',
  '3' => 'Tuesday',
  '4' => 'Wednesday',
  '5' => 'Thursday',
  '6' => 'Friday',
  '7' => 'Saturday',
];

// ---- Filters (defaults last 30 days) ----
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to']   ?? '';
try {
  $from = $date_from ? new DateTime($date_from . ' 00:00:00') : (new DateTime())->modify('-30 days')->setTime(0,0,0);
} catch(Exception $e){ $from = (new DateTime())->modify('-30 days')->setTime(0,0,0); }
try {
  $to   = $date_to ? new DateTime($date_to . ' 23:59:59') : (new DateTime())->setTime(23,59,59);
} catch(Exception $e){ $to = (new DateTime())->setTime(23,59,59); }

$from_sql = $from->format('Y-m-d H:i:s');
$to_sql   = $to->format('Y-m-d H:i:s');

$filter_subject = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int)$_GET['subject_id'] : 0;
$filter_tutor = (isset($_GET['tutor_id']) && $_GET['tutor_id'] !== '') ? (int)$_GET['tutor_id'] : 0;
$filter_student = (isset($_GET['student_id']) && $_GET['student_id'] !== '') ? (int)$_GET['student_id'] : 0;
$filter_year_level = trim($_GET['year_level'] ?? '');
$filter_lesson_type = trim($_GET['lesson_type'] ?? '');
$filter_day_of_week = trim($_GET['dow'] ?? '');

// ---- Fetch lessons in range + latest recording, student/tutor join times ----
// Grab the latest recording per lesson via a MAX(recorded_at) subquery.
$whereClauses = [
  "l.scheduled_start_time BETWEEN ? AND ?"
];
$params = [$from_sql, $to_sql];

if ($filter_subject > 0) {
  $whereClauses[] = "l.subject_id = ?";
  $params[] = $filter_subject;
}
if ($filter_tutor > 0) {
  $whereClauses[] = "l.tutor_id = ?";
  $params[] = $filter_tutor;
}
if ($filter_student > 0) {
  $whereClauses[] = "l.student_id = ?";
  $params[] = $filter_student;
}
if ($filter_year_level !== '' && $filter_year_level !== 'all') {
  $whereClauses[] = "subj.year_level = ?";
  $params[] = $filter_year_level;
}
if ($filter_lesson_type !== '' && isset($lesson_type_options[$filter_lesson_type])) {
  $whereClauses[] = "l.lesson_type = ?";
  $params[] = $filter_lesson_type;
}
if ($filter_day_of_week !== '' && isset($day_of_week_options[$filter_day_of_week])) {
  $whereClauses[] = "DAYOFWEEK(l.scheduled_start_time) = ?";
  $params[] = (int)$filter_day_of_week;
}

$whereSql = implode("\n  AND ", $whereClauses);

// ---- Pagination ----
$per_page = 10; // Records per page
$current_page_num = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page_num - 1) * $per_page;

// Get total count for pagination
$countSql = "
  SELECT COUNT(DISTINCT l.id)
  FROM lessons l
  JOIN subjects subj ON subj.id = l.subject_id
  JOIN users   stu   ON stu.id  = l.student_id
  JOIN users   tut   ON tut.id  = l.tutor_id
  WHERE $whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_records / $per_page));
$current_page_num = min($current_page_num, $total_pages);
$offset = ($current_page_num - 1) * $per_page;

$sql = "
  SELECT
    l.id,
    l.scheduled_start_time,
    l.scheduled_end_time,
    l.status,
    l.student_id,
    l.tutor_id,
    l.external_room_id,
    l.student_join_time" .
    ($HAS_TUTOR_JOIN ? ", l.tutor_join_time" : ", NULL AS tutor_join_time") . ",
    subj.name AS subject_name,
    stu.first_name AS student_first, stu.last_name AS student_last,
    tut.first_name AS tutor_first,   tut.last_name AS tutor_last,
    lr.file_url     AS rec_url,
    lr.duration_seconds AS rec_duration,
    lr.file_size_bytes  AS rec_size,
    lr.recorded_at  AS rec_recorded_at
  FROM lessons l
  JOIN subjects subj ON subj.id = l.subject_id
  JOIN users   stu   ON stu.id  = l.student_id
  JOIN users   tut   ON tut.id  = l.tutor_id
  LEFT JOIN (
    SELECT x.*
    FROM lesson_recordings x
    JOIN (
      SELECT lesson_id, MAX(recorded_at) AS max_rec
      FROM lesson_recordings
      GROUP BY lesson_id
    ) latest
      ON latest.lesson_id = x.lesson_id AND latest.max_rec = x.recorded_at
  ) lr
    ON lr.lesson_id = l.id
  WHERE $whereSql
  ORDER BY l.scheduled_start_time DESC
  LIMIT ? OFFSET ?
";
$params[] = $per_page;
$params[] = $offset;
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// ---- Preload homework info per lesson (fast-ish):
$lesson_ids = array_map(fn($r) => (int)$r['id'], $rows);
$hw_map = []; // lesson_id => array of homework rows (title, id, due_date)
if ($lesson_ids) {
  if ($HW_HAS_LESSON) {
    // direct link: homework.lesson_id
    $in = implode(',', array_fill(0, count($lesson_ids), '?'));
    $q = $pdo->prepare("
      SELECT h.id, h.title, h.due_date, h.lesson_id
      FROM homework h
      WHERE h.lesson_id IN ($in)
      ORDER BY h.id DESC
    ");
    $q->execute($lesson_ids);
    foreach ($q as $r) {
      $lid = (int)$r['lesson_id'];
      $hw_map[$lid][] = $r;
    }
  } else {
    // heuristic: same tutor→student, due within 3 days after lesson start
    // Build one batched query using UNION ALL to avoid N+1:
    // (This is simplified by looping; for scale, create a temp table)
    foreach ($rows as $r) {
      $lid = (int)$r['id'];
      $tut = (int)$r['tutor_id'];
      $stu = (int)$r['student_id'];
      $start = new DateTime($r['scheduled_start_time']);
      $startPlus3d = (clone $start)->modify('+3 days');
      $q = $pdo->prepare("
        SELECT h.id, h.title, h.due_date
        FROM homework h
        WHERE h.tutor_id=? AND h.student_id=? AND (h.due_date IS NULL OR h.due_date BETWEEN ? AND ?)
        ORDER BY h.id DESC
        LIMIT 5
      ");
      $q->execute([$tut, $stu, $start->format('Y-m-d H:i:s'), $startPlus3d->format('Y-m-d H:i:s')]);
      $hw_map[$lid] = $q->fetchAll();
    }
  }
}

// --- AJAX: detail view for a lesson (include ALL recordings + richer data) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
  header('Content-Type: application/json; charset=UTF-8');
  $lesson_id = (int)($_GET['lesson_id'] ?? 0);
  if ($lesson_id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Bad id']); exit; }

  // Lesson row
  $q = $pdo->prepare("
    SELECT l.*, subj.name AS subject_name,
           stu.first_name AS student_first, stu.last_name AS student_last,
           tut.first_name AS tutor_first,   tut.last_name AS tutor_last,
           l.external_room_id
    FROM lessons l
    JOIN subjects subj ON subj.id = l.subject_id
    JOIN users stu ON stu.id = l.student_id
    JOIN users tut ON tut.id = l.tutor_id
    WHERE l.id = ?
    LIMIT 1
  ");
  $q->execute([$lesson_id]);
  $L = $q->fetch();
  if (!$L) { echo json_encode(['ok'=>false, 'error'=>'Not found']); exit; }

// Recordings (all from DB). Served through the authenticated gateway
// (../api/recording_url.php), which 302-redirects to a time-limited Cloudflare R2
// link, or the local file for anything not yet migrated.
$rec = $pdo->prepare("SELECT id, file_size_bytes, duration_seconds, recorded_at FROM lesson_recordings WHERE lesson_id=? ORDER BY recorded_at DESC");
$rec->execute([$lesson_id]);
$recordings = [];
$gatewayUrl = '../api/recording_url.php?lesson_id=' . (int)$lesson_id;

foreach ($rec as $r) {
  $recordings[] = [
    'id'        => (int)$r['id'],
    'url'       => $gatewayUrl,
    'size'      => (int)($r['file_size_bytes'] ?? 0),
    'duration'  => (int)($r['duration_seconds'] ?? 0),
    'recorded'  => $r['recorded_at'],
    'source'    => 'db',
  ];
}


  // Homework
  $hws = [];
  if ($HW_HAS_LESSON) {
    $hq = $pdo->prepare("SELECT id, title, due_date FROM homework WHERE lesson_id=? ORDER BY id DESC");
    $hq->execute([$lesson_id]);
    $hws = $hq->fetchAll();
  } else {
    $start = new DateTime($L['scheduled_start_time']);
    $startPlus3d = (clone $start)->modify('+3 days');
    $hq = $pdo->prepare("
      SELECT id, title, due_date
      FROM homework
      WHERE tutor_id=? AND student_id=? AND (due_date IS NULL OR due_date BETWEEN ? AND ?)
      ORDER BY id DESC
      LIMIT 10
    ");
    $hq->execute([(int)$L['tutor_id'], (int)$L['student_id'], $start->format('Y-m-d H:i:s'), $startPlus3d->format('Y-m-d H:i:s')]);
    $hws = $hq->fetchAll();
  }

  // Rating (if any)
  $rq = $pdo->prepare("SELECT rating, feedback, created_at FROM lesson_ratings WHERE lesson_id=? LIMIT 1");
  $rq->execute([$lesson_id]);
  $rating = $rq->fetch() ?: null;

  echo json_encode([
    'ok' => true,
    'lesson' => [
      'id' => (int)$L['id'],
      'subject' => $L['subject_name'],
      'student' => $L['student_first'].' '.$L['student_last'],
      'tutor'   => $L['tutor_first'].' '.$L['tutor_last'],
      'start'   => $L['scheduled_start_time'],
      'end'     => $L['scheduled_end_time'],
      'status'  => $L['status'],
      'student_join_time' => $L['student_join_time'],
      'tutor_join_time'   => $HAS_TUTOR_JOIN ? $L['tutor_join_time'] : null,
      'tutor_notes'       => $L['tutor_notes'] ?? null,
      'external_room_id'  => $L['external_room_id'] ?? null,
    ],
    'recordings' => $recordings,
    'homework'   => $hws,
    'rating'     => $rating
  ]);
  exit;
}

$subject_options = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$year_level_options = $pdo->query("
  SELECT DISTINCT year_level
  FROM subjects
  WHERE year_level IS NOT NULL AND year_level <> ''
  ORDER BY year_level + 0, year_level
")->fetchAll(PDO::FETCH_COLUMN);
if ($filter_year_level !== '' && $filter_year_level !== 'all' && !in_array($filter_year_level, $year_level_options, true)) {
  $year_level_options[] = $filter_year_level;
  natcasesort($year_level_options);
  $year_level_options = array_values($year_level_options);
}

$tutorStmt = $pdo->prepare("
  SELECT DISTINCT u.id, u.first_name, u.last_name
  FROM lessons l
  JOIN users u ON u.id = l.tutor_id
  WHERE l.scheduled_start_time BETWEEN ? AND ?
  ORDER BY u.first_name, u.last_name
");
$tutorStmt->execute([$from_sql, $to_sql]);
$tutor_options = $tutorStmt->fetchAll(PDO::FETCH_ASSOC);
if ($filter_tutor > 0 && !array_filter($tutor_options, fn($t) => (int)$t['id'] === $filter_tutor)) {
  $extraTutor = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? LIMIT 1");
  $extraTutor->execute([$filter_tutor]);
  if ($row = $extraTutor->fetch(PDO::FETCH_ASSOC)) {
    $tutor_options[] = $row;
  }
}

$studentStmt = $pdo->prepare("
  SELECT DISTINCT u.id, u.first_name, u.last_name
  FROM lessons l
  JOIN users u ON u.id = l.student_id
  WHERE l.student_id IS NOT NULL
    AND l.scheduled_start_time BETWEEN ? AND ?
  ORDER BY u.first_name, u.last_name
  LIMIT 300
");
$studentStmt->execute([$from_sql, $to_sql]);
$student_options = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
if ($filter_student > 0 && !array_filter($student_options, fn($s) => (int)$s['id'] === $filter_student)) {
  $extraStudent = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? LIMIT 1");
  $extraStudent->execute([$filter_student]);
  if ($row = $extraStudent->fetch(PDO::FETCH_ASSOC)) {
    $student_options[] = $row;
  }
}

// ---- UI Data transforms ----
function fmt_dt(?string $s): string {
  if (!$s) return '—';
  try {
    // Stored value is UTC; show it in the viewer's timezone (UK fallback).
    $tz = $_SESSION['timezone'] ?? 'Europe/London';
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) $tz = 'Europe/London';
    $d = new DateTime($s, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone($tz));
    return $d->format('M j, Y g:i a');
  } catch(Exception $e){ return $s; }
}
function fmt_size(int $b): string {
  if ($b <= 0) return '—';
  $u = ['B','KB','MB','GB','TB']; $i=0; $v=$b;
  while ($v >= 1024 && $i < count($u)-1){ $v/=1024; $i++; }
  return sprintf(($v<10 && $i>0)?'%.1f %s':'%.0f %s', $v, $u[$i]);
}
function fmt_dur(int $s): string {
  if ($s <= 0) return '—';
  $m = intdiv($s,60); $r = $s%60;
  return $m.'m '.str_pad((string)$r,2,'0',STR_PAD_LEFT).'s';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Recordings</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <div id="detailModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-3xl p-6 relative max-h-[90vh] overflow-y-auto">
      <div class="modal-loader absolute inset-0 bg-gray-900/90 rounded-xl flex items-center justify-center hidden z-10">
        <div class="flex flex-col items-center gap-3">
          <div class="w-12 h-12 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
          <p class="text-white text-sm">Loading...</p>
        </div>
      </div>
      <button id="detailClose" class="absolute top-4 right-4 text-gray-400 hover:text-white z-20 bg-gray-800/80 rounded-full p-2 transition hover:bg-gray-700">
        <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <div id="detailBody" class="space-y-4">Loading…</div>
    </div>
  </div>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;background:#111827;color:#d1d5db}
    ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:#1f2937}::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .chip{font-size:11px;padding:4px 8px;border-radius:6px;display:inline-flex;align-items:center;font-weight:500}
    .line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    table tbody tr:hover{background-color:rgba(31,41,55,0.4)}
    /* .custom-scrollbar::-webkit-scrollbar{height:10px}
    .custom-scrollbar::-webkit-scrollbar-track{background:#1f2937;border-radius:4px}
    .custom-scrollbar::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .custom-scrollbar::-webkit-scrollbar-thumb:hover{background:#6b7280}
    .custom-scrollbar{scrollbar-width:thin;scrollbar-color:#4b5563 #1f2937} */
    table{width:100%;table-layout:auto}
  </style>
</head>
  <body class="flex min-h-screen">
  <?php $current_page='recordings'; include __DIR__.'/admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-6 lg:p-8">
    <header class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">Lesson Recordings</h1>
        <p class="text-gray-400">View recordings, attendance, homework links, and tutor lateness.</p>
      </div>
      <a href="admin_dashboard.php" class="text-sm text-indigo-300 hover:text-indigo-200">Back to dashboard</a>
    </header>

    <!-- Filters -->
    <section class="bg-gray-900 p-5 rounded-lg mb-6">
      <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 items-end">
        <div>
          <label class="block text-sm text-gray-300 mb-1">From</label>
          <input type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">To</label>
          <input type="date" name="to" value="<?= h($to->format('Y-m-d')) ?>" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Subject</label>
          <select name="subject_id" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All subjects</option>
            <?php foreach ($subject_options as $subj): ?>
              <?php $sid = (int)$subj['id']; ?>
              <option value="<?= h($sid) ?>" <?= ($filter_subject === $sid) ? 'selected' : '' ?>><?= h($subj['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Year Level</label>
          <select name="year_level" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All year levels</option>
            <?php foreach ($year_level_options as $yl): ?>
              <?php $label = is_numeric($yl) ? 'Year '.$yl : $yl; ?>
              <option value="<?= h($yl) ?>" <?= ($filter_year_level !== '' && (string)$filter_year_level === (string)$yl) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Lesson Type</label>
          <select name="lesson_type" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All lesson types</option>
            <?php foreach ($lesson_type_options as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= ($filter_lesson_type === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Day of the Week</label>
          <select name="dow" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">Any day</option>
            <?php foreach ($day_of_week_options as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= ($filter_day_of_week === (string)$value) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Tutor</label>
          <select name="tutor_id" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All tutors</option>
            <?php foreach ($tutor_options as $tutor): ?>
              <?php $tid = (int)$tutor['id']; $tName = trim(($tutor['first_name'] ?? '').' '.($tutor['last_name'] ?? '')) ?: ('Tutor #'.$tid); ?>
              <option value="<?= h($tid) ?>" <?= ($filter_tutor === $tid) ? 'selected' : '' ?>><?= h($tName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">Student</label>
          <select name="student_id" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All students</option>
            <?php foreach ($student_options as $student): ?>
              <?php $sid = (int)$student['id']; $sName = trim(($student['first_name'] ?? '').' '.($student['last_name'] ?? '')) ?: ('Student #'.$sid); ?>
              <option value="<?= h($sid) ?>" <?= ($filter_student === $sid) ? 'selected' : '' ?>><?= h($sName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2 xl:col-span-4 flex flex-wrap gap-3 justify-end">
          <a href="recordings.php" class="text-sm text-gray-300 hover:text-white self-center px-3 py-2 rounded-lg hover:bg-gray-800 transition">Reset</a>
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2 rounded-lg transition">Apply Filters</button>
        </div>
      </form>
    </section>

    <!-- Table -->
    <section class="bg-gray-900 rounded-lg overflow-hidden border border-gray-700/50 shadow-lg">
      <div>
        <table class="min-w-full text-sm divide-y divide-gray-800/50">
          <thead class="bg-gray-800/80 backdrop-blur-sm">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Date & Time</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Subject</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Tutor</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Student</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Recording</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Attendance</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Tutor Punctuality</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-300 text-xs uppercase tracking-wider">Homework</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-300 text-xs uppercase tracking-wider">Actions</th>
            </tr>
          </thead>  

          <tbody class="divide-y divide-gray-800/50">
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="px-4 py-12 text-center text-gray-400">
                <div class="flex flex-col items-center gap-2">
                  <svg class="w-12 h-12 text-gray-600" data-lucide="video-off" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
                  <p class="text-sm">No lessons found for this date range.</p>
                  <p class="text-xs text-gray-500">Try adjusting your filters above.</p>
                </div>
              </td></tr>
            <?php else: foreach ($rows as $r):
              $start = $r['scheduled_start_time'] ? new DateTime($r['scheduled_start_time']) : null;
              $tutorJoin = $r['tutor_join_time'] ? new DateTime($r['tutor_join_time']) : null;
              $studentJoin = $r['student_join_time'] ? new DateTime($r['student_join_time']) : null;

              $tutorLateMin = '—';
              $late_chip_class = 'chip bg-gray-700/60 text-gray-300';
              if ($tutorJoin && $start) {
                $diffSec = $tutorJoin->getTimestamp() - $start->getTimestamp();
                if ($diffSec > 0) {
                  $mins = (int)round($diffSec/60);
                  $tutorLateMin = $mins . 'm';
                  $late_chip_class = 'chip bg-amber-500/15 text-amber-300';
                  if ($mins >= 10) $late_chip_class = 'chip bg-rose-500/15 text-rose-300';
                } else {
                  $tutorLateMin = 'On time';
                  $late_chip_class = 'chip bg-emerald-500/15 text-emerald-300';
                }
              }

              // joined chips with icons
              $st_chip = $studentJoin 
                ? '<span class="chip bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 flex items-center gap-1"><svg class="w-3 h-3" data-lucide="check-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>Student</span>' 
                : '<span class="chip bg-gray-700/60 text-gray-300 border border-gray-600/30 flex items-center gap-1"><svg class="w-3 h-3" data-lucide="x-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x-circle"></use></svg>Student</span>';
              $tu_chip = $tutorJoin   
                ? '<span class="chip bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 flex items-center gap-1"><svg class="w-3 h-3" data-lucide="check-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>Tutor</span>'   
                : '<span class="chip bg-gray-700/60 text-gray-300 border border-gray-600/30 flex items-center gap-1"><svg class="w-3 h-3" data-lucide="x-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x-circle"></use></svg>Tutor</span>';

              $rec_url = $r['rec_url'] ? ('../api/recording_url.php?lesson_id=' . (int)$r['id']) : '';
              $rec_info = $r['rec_url']
                ? (fmt_dur((int)$r['rec_duration']).' • '.fmt_size((int)$r['rec_size']))
                : '—';

              // homework summary
              $lid = (int)$r['id'];
              $hws = $hw_map[$lid] ?? [];
              $hw_summary = '—';
              if ($hws) {
                $titles = array_map(fn($x)=>$x['title'] ?? ('#'.$x['id']), $hws);
                $hw_summary = h(implode(' • ', array_slice($titles, 0, 2)));
                if (count($titles) > 2) $hw_summary .= ' +' . (count($titles)-2);
              }
              ?>
              <tr class="hover:bg-gray-800/40 transition-colors group">
                <td class="px-4 py-4 align-top">
                  <div class="flex flex-col gap-0.5">
                    <span class="text-white font-medium"><?= h($start ? $start->format('M j, Y') : '—') ?></span>
                    <span class="text-xs text-gray-400"><?= h($start ? $start->format('g:i A') : '') ?></span>
                  </div>
                </td>
                <td class="px-4 py-4 align-top">
                  <span class="text-white font-medium"><?= h($r['subject_name']) ?></span>
                </td>
                <td class="px-4 py-4 align-top">
                  <div class="flex flex-col gap-0.5">
                    <span class="text-white"><?= h($r['tutor_first'].' '.$r['tutor_last']) ?></span>
                  </div>
                </td>
                <td class="px-4 py-4 align-top">
                  <div class="flex flex-col gap-0.5">
                    <span class="text-white"><?= h($r['student_first'].' '.$r['student_last']) ?></span>
                  </div>
                </td>
                <td class="px-4 py-4 align-top">
                  <?php if ($rec_url): ?>
                    <div class="flex flex-col gap-2">
                      <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" data-lucide="video" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
                        <div class="flex flex-col gap-0.5 min-w-0">
                          <span class="text-xs text-gray-300 truncate"><?= h($rec_info) ?></span>
                        </div>
                      </div>
                      <div class="flex flex-wrap gap-1.5">
                        <a href="<?= h($rec_url) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 px-2.5 py-1.5 rounded-md transition">
                          <svg class="w-3 h-3" data-lucide="play" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-play"></use></svg>
                          <span>Watch</span>
                        </a>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="flex items-center gap-2 text-gray-500">
                      <svg class="w-4 h-4" data-lucide="video-off" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
                      <span class="text-xs">No recording</span>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-4 align-top">
                  <div class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-1.5">
                      <?= $st_chip ?>
                      <?= $tu_chip ?>
                    </div>
                    <?php if ($studentJoin && $tutorJoin): ?>
                      <span class="text-[10px] text-gray-500">Both joined</span>
                    <?php elseif ($studentJoin || $tutorJoin): ?>
                      <span class="text-[10px] text-gray-500">Partial</span>
                    <?php else: ?>
                      <span class="text-[10px] text-gray-500">No join data</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-4 align-top">
                  <div class="flex flex-col gap-1.5">
                    <span class="<?= $late_chip_class ?> inline-flex items-center gap-1.5 w-fit border">
                      <?php if (strpos($late_chip_class, 'emerald') !== false): ?>
                        <svg class="w-3.5 h-3.5" data-lucide="check-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
                        <span class="font-medium"><?= h($tutorLateMin) ?></span>
                      <?php elseif (strpos($late_chip_class, 'amber') !== false): ?>
                        <svg class="w-3.5 h-3.5" data-lucide="clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                        <span class="font-medium"><?= h($tutorLateMin) ?></span>
                      <?php elseif (strpos($late_chip_class, 'rose') !== false): ?>
                        <svg class="w-3.5 h-3.5" data-lucide="alert-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>
                        <span class="font-medium"><?= h($tutorLateMin) ?></span>
                      <?php else: ?>
                        <svg class="w-3.5 h-3.5" data-lucide="minus" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-minus"></use></svg>
                        <span><?= h($tutorLateMin) ?></span>
                      <?php endif; ?>
                    </span>
                    <?php if ($tutorJoin && $start): 
                      $diffSec = $tutorJoin->getTimestamp() - $start->getTimestamp();
                      if ($diffSec > 0): 
                        $lateMins = round($diffSec/60);
                        $lateSecs = $diffSec % 60;
                      ?>
                        <div class="flex items-center gap-1 text-[10px] <?= $lateMins >= 10 ? 'text-red-400' : 'text-amber-400' ?>">
                          <svg class="w-3 h-3" data-lucide="clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                          <span><?= $lateMins ?>m <?= $lateSecs ?>s after start</span>
                        </div>
                      <?php elseif ($diffSec < 0): ?>
                        <div class="flex items-center gap-1 text-[10px] text-emerald-400">
                          <svg class="w-3 h-3" data-lucide="check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg>
                          <span><?= abs(round($diffSec/60)) ?>m early</span>
                        </div>
                      <?php endif; 
                    endif; ?>
                  </div>
                </td>
                <td class="px-4 py-4 align-top">
                  <?php if ($hws): ?>
                    <div class="flex flex-col gap-1">
                      <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-indigo-400" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
                        <span class="text-xs text-gray-300 line-clamp-2" title="<?= h($hw_summary) ?>"><?= h($hw_summary) ?></span>
                      </div>
                      <span class="text-[10px] text-gray-500"><?= count($hws) ?> assignment<?= count($hws) !== 1 ? 's' : '' ?></span>
                    </div>
                  <?php else: ?>
                    <div class="flex items-center gap-1.5 text-gray-500">
                      <svg class="w-3.5 h-3.5" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
                      <span class="text-xs">None</span>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-4 align-top text-right">
                  <button class="detail-btn bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 text-xs font-semibold px-3 py-2 rounded-lg transition flex items-center gap-1.5 group-hover:bg-indigo-600/40"
                          data-lesson-id="<?= (int)$r['id'] ?>">
                    <svg class="w-3.5 h-3.5" data-lucide="eye" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-eye"></use></svg>
                    <span>View</span>
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      
      <!-- Pagination -->
      <?php if (isset($total_pages) && isset($total_records)): ?>
        <div class="bg-gray-800/50 border-t border-gray-700/50 px-4 py-4">
          <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-sm text-gray-400">
              Showing <span class="text-white font-medium"><?= isset($offset) ? $offset + 1 : 1 ?></span> to 
              <span class="text-white font-medium"><?= isset($offset) && isset($per_page) ? min($offset + $per_page, $total_records) : $total_records ?></span> of 
              <span class="text-white font-medium"><?= number_format($total_records) ?></span> lessons
              <?php if (isset($total_pages) && $total_pages > 1): ?>
                <span class="text-gray-500 ml-1">(Page <?= isset($current_page) ? $current_page : 1 ?> of <?= $total_pages ?>)</span>
              <?php endif; ?>
            </div>
            
            <?php if (isset($total_pages) && $total_pages > 1): ?>
            <div class="flex items-center gap-2">
              <!-- Previous Button -->
              <?php
              $prev_page = $current_page_num > 1 ? $current_page_num - 1 : null;
              $query_params = $_GET;
              if ($prev_page) {
                $query_params['page'] = $prev_page;
                $prev_url = '?' . http_build_query($query_params);
              } else {
                $prev_url = '#';
              }
              ?>
              <a href="<?= $prev_url ?>" 
                 class="px-3 py-2 rounded-lg text-sm font-medium transition <?= $prev_page ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-800 text-gray-500 cursor-not-allowed' ?> flex items-center gap-1.5"
                 <?= !$prev_page ? 'onclick="return false;"' : '' ?>>
                <svg class="w-4 h-4" data-lucide="chevron-left" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg>
                <span>Previous</span>
              </a>
              
              <!-- Page Numbers -->
              <div class="flex items-center gap-1">
                <?php
                $max_visible = 7;
                $start_page = max(1, $current_page_num - floor($max_visible / 2));
                $end_page = min($total_pages, $start_page + $max_visible - 1);
                if ($end_page - $start_page < $max_visible - 1) {
                  $start_page = max(1, $end_page - $max_visible + 1);
                }
                
                // First page
                if ($start_page > 1) {
                  $query_params['page'] = 1;
                  $first_url = '?' . http_build_query($query_params);
                  echo '<a href="' . h($first_url) . '" class="px-3 py-2 rounded-lg text-sm font-medium bg-gray-700 hover:bg-gray-600 text-white transition">1</a>';
                  if ($start_page > 2) {
                    echo '<span class="px-2 text-gray-500">...</span>';
                  }
                }
                
                // Page numbers
                for ($i = $start_page; $i <= $end_page; $i++) {
                  $query_params['page'] = $i;
                  $page_url = '?' . http_build_query($query_params);
                  if ($i == $current_page_num) {
                    echo '<span class="px-3 py-2 rounded-lg text-sm font-semibold bg-indigo-600 text-white">' . $i . '</span>';
                  } else {
                    echo '<a href="' . h($page_url) . '" class="px-3 py-2 rounded-lg text-sm font-medium bg-gray-700 hover:bg-gray-600 text-white transition">' . $i . '</a>';
                  }
                }
                
                // Last page
                if ($end_page < $total_pages) {
                  if ($end_page < $total_pages - 1) {
                    echo '<span class="px-2 text-gray-500">...</span>';
                  }
                  $query_params['page'] = $total_pages;
                  $last_url = '?' . http_build_query($query_params);
                  echo '<a href="' . h($last_url) . '" class="px-3 py-2 rounded-lg text-sm font-medium bg-gray-700 hover:bg-gray-600 text-white transition">' . $total_pages . '</a>';
                }
                ?>
              </div>
              
              <!-- Next Button -->
              <?php
              $next_page = $current_page_num < $total_pages ? $current_page_num + 1 : null;
              if ($next_page) {
                $query_params['page'] = $next_page;
                $next_url = '?' . http_build_query($query_params);
              } else {
                $next_url = '#';
              }
              ?>
              <a href="<?= $next_url ?>" 
                 class="px-3 py-2 rounded-lg text-sm font-medium transition <?= $next_page ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-800 text-gray-500 cursor-not-allowed' ?> flex items-center gap-1.5"
                 <?= !$next_page ? 'onclick="return false;"' : '' ?>>
                <span>Next</span>
                <svg class="w-4 h-4" data-lucide="chevron-right" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg>
              </a>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <script>
    const detailModal = document.getElementById('detailModal');
    const detailBody  = document.getElementById('detailBody');
    const btnClose    = document.getElementById('detailClose');

    function openModal(){ detailModal.classList.remove('hidden'); detailModal.classList.add('flex'); }
    function closeModal(){ detailModal.classList.add('hidden'); detailModal.classList.remove('flex'); }

    btnClose?.addEventListener('click', closeModal);
    detailModal.addEventListener('click', (e)=>{ if (e.target === detailModal) closeModal(); });

    function humanSize(bytes){
      bytes = Number(bytes||0); if(!bytes) return '—';
      const u=['B','KB','MB','GB','TB']; let i=0, v=bytes;
      while(v>=1024 && i<u.length-1){ v/=1024; i++; }
      return (v<10 && i>0 ? v.toFixed(1) : Math.round(v)) + ' ' + u[i];
    }
    function humanDur(sec){
      sec = Number(sec||0); if(!sec) return '—';
      const m = Math.floor(sec/60), s = sec%60;
      return `${m}m ${String(s).padStart(2,'0')}s`;
    }
    // The apostrophe is in the pattern but was missing from the map, so any name
    // with one in it (O'Brien) rendered the word "undefined" in its place.
    function esc(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    document.querySelectorAll('.detail-btn').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.lessonId;
        const loader = detailModal.querySelector('.modal-loader');
        if (loader) loader.classList.remove('hidden');
        detailBody.innerHTML = 'Loading…';
        openModal();
        try{
          const r = await fetch(`recordings.php?ajax=detail&lesson_id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
          const data = await r.json();
          const loader = detailModal.querySelector('.modal-loader');
          if (loader) loader.classList.add('hidden');
          if (!data || !data.ok) { detailBody.textContent = data?.error || 'Failed to load.'; return; }

          const L = data.lesson;
          const recs = data.recordings || [];
          const hws  = data.homework || [];
          const rating = data.rating;

          // header
          const startDate = new Date(L.start.replace(' ','T'));
          const endDate = L.end ? new Date(L.end.replace(' ','T')) : null;
          const duration = endDate ? Math.round((endDate - startDate) / 60000) : 0;
          
          let header = `
            <div class="flex items-start justify-between mb-4 pb-4 border-b border-gray-700">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-2">
                  <h3 class="text-xl font-bold text-white">${esc(L.subject)}</h3>
                  <span class="chip ${L.status==='Completed'?'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30': (String(L.status||'').startsWith('Cancelled')?'bg-rose-500/15 text-rose-300 border border-rose-500/30': L.status==='NoShow'?'bg-amber-500/15 text-amber-300 border border-amber-500/30': 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/30')}">${esc(L.status || '—')}</span>
                </div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500" data-lucide="hash" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-hash"></use></svg>
                    <span class="text-gray-400">Lesson ID:</span>
                    <span class="text-white font-medium">#${L.id}</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500" data-lucide="clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                    <span class="text-gray-400">Duration:</span>
                    <span class="text-white font-medium">${duration}m</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500" data-lucide="user" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-user"></use></svg>
                    <span class="text-gray-400">Tutor:</span>
                    <span class="text-white font-medium">${esc(L.tutor)}</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-500" data-lucide="graduation-cap" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-graduation-cap"></use></svg>
                    <span class="text-gray-400">Student:</span>
                    <span class="text-white font-medium">${esc(L.student)}</span>
                  </div>
                </div>
                <div class="mt-2 text-xs text-gray-500">
                  <svg class="w-3.5 h-3.5 inline mr-1" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                  ${startDate.toLocaleString()} ${endDate ? ' - ' + endDate.toLocaleTimeString() : ''}
                </div>
              </div>
            </div>
          `;

          // join row with timing calculations (reuse startDate/endDate from header)
          const studentJoinDate = L.student_join_time ? new Date(L.student_join_time.replace(' ','T')) : null;
          const tutorJoinDate = L.tutor_join_time ? new Date(L.tutor_join_time.replace(' ','T')) : null;
          
          let studentLate = '';
          let tutorLate = '';
          if (studentJoinDate && startDate) {
            const diff = (studentJoinDate - startDate) / 60000;
            if (diff > 0) studentLate = ` (${Math.round(diff)}m late)`;
            else if (diff < -5) studentLate = ` (${Math.abs(Math.round(diff))}m early)`;
          }
          if (tutorJoinDate && startDate) {
            const diff = (tutorJoinDate - startDate) / 60000;
            if (diff > 0) tutorLate = ` (${Math.round(diff)}m late)`;
            else if (diff < -5) tutorLate = ` (${Math.abs(Math.round(diff))}m early)`;
          }
          
          let join = `
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
              <div class="bg-gray-800/50 rounded-lg p-4 border border-gray-700/50">
                <div class="flex items-center gap-2 mb-2">
                  <svg class="w-4 h-4 text-indigo-400" data-lucide="graduation-cap" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-graduation-cap"></use></svg>
                  <div class="text-xs text-gray-400 font-medium">Student Joined</div>
                </div>
                <div class="text-white font-semibold text-sm">${studentJoinDate ? studentJoinDate.toLocaleString() : '—'}</div>
                ${studentLate ? `<div class="text-xs mt-1 ${studentLate.includes('late') ? 'text-amber-400' : 'text-emerald-400'}">${studentLate}</div>` : ''}
              </div>
              <div class="bg-gray-800/50 rounded-lg p-4 border border-gray-700/50">
                <div class="flex items-center gap-2 mb-2">
                  <svg class="w-4 h-4 text-indigo-400" data-lucide="user" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-user"></use></svg>
                  <div class="text-xs text-gray-400 font-medium">Tutor Joined</div>
                </div>
                <div class="text-white font-semibold text-sm">${tutorJoinDate ? tutorJoinDate.toLocaleString() : '—'}</div>
                ${tutorLate ? `<div class="text-xs mt-1 ${tutorLate.includes('late') ? 'text-amber-400' : 'text-emerald-400'}">${tutorLate}</div>` : ''}
              </div>
              <div class="bg-gray-800/50 rounded-lg p-4 border border-gray-700/50">
                <div class="flex items-center gap-2 mb-2">
                  <svg class="w-4 h-4 text-indigo-400" data-lucide="clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                  <div class="text-xs text-gray-400 font-medium">Lesson Ended</div>
                </div>
                <div class="text-white font-semibold text-sm">${endDate ? endDate.toLocaleString() : '—'}</div>
                ${endDate && startDate ? `<div class="text-xs mt-1 text-gray-500">${Math.round((endDate - startDate) / 60000)} minutes</div>` : ''}
              </div>
            </div>
          `;

          // recordings block
          let recHtml = '';
          if (recs.length) {
            recHtml += `<div class="space-y-3">
              <div class="flex items-center gap-2 mb-3">
                <svg class="w-5 h-5 text-indigo-400" data-lucide="video" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
                <p class="font-semibold text-white">Recordings (${recs.length})</p>
              </div>`;
            for (const r of recs) {
              // Recordings served via the gateway (302 → Cloudflare R2 webm); always inline.
              const canInline = !!r.url;
              const embed = canInline
                ? `<video src="${esc(r.url)}" controls class="w-full rounded-lg bg-black/30 border border-gray-700 max-h-64"></video>`
                : `<div class="rounded-lg border border-gray-700 overflow-hidden aspect-video bg-black">
                     <iframe src="${esc(r.url)}" allow="camera; microphone; display-capture; fullscreen;" frameborder="0" class="w-full h-full"></iframe>
                   </div>`;
              recHtml += `<div class="bg-gray-800/50 rounded-lg p-4 space-y-3 border border-gray-700/50">
                <div class="flex items-center justify-between text-xs">
                  <div class="flex items-center gap-3 text-gray-300">
                    <span class="flex items-center gap-1">
                      <svg class="w-3.5 h-3.5" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                      ${r.recorded ? new Date(r.recorded.replace(' ','T')).toLocaleString() : 'Unknown date'}
                    </span>
                    <span class="flex items-center gap-1">
                      <svg class="w-3.5 h-3.5" data-lucide="clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                      ${humanDur(r.duration)}
                    </span>
                    <span class="flex items-center gap-1">
                      <svg class="w-3.5 h-3.5" data-lucide="hard-drive" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-hard-drive"></use></svg>
                      ${humanSize(r.size)}
                    </span>
                  </div>
                  <span class="chip bg-indigo-500/10 text-indigo-300 border border-indigo-500/20">${r.source === 'db' ? 'Database' : 'API'}</span>
                </div>
                ${embed}
                <div class="flex flex-wrap gap-2 justify-end">
                  <a href="${esc(r.url)}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 px-3 py-1.5 rounded-md transition">
                    <svg class="w-3.5 h-3.5" data-lucide="play" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-play"></use></svg>
                    <span>Watch</span>
                  </a>
                  <a href="${esc(r.url)}" download class="inline-flex items-center gap-1.5 text-xs bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 px-3 py-1.5 rounded-md transition" title="Download recording">
                    <svg class="w-3.5 h-3.5" data-lucide="download" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-download"></use></svg>
                    <span>Download</span>
                  </a>
                  <button type="button" onclick="shareRecording('${esc(r.url)}', '${esc(L.subject)} - ${new Date(L.start.replace(' ','T')).toLocaleDateString()}')" class="inline-flex items-center gap-1.5 text-xs bg-purple-500/20 hover:bg-purple-500/30 text-purple-300 px-3 py-1.5 rounded-md transition" title="Share recording">
                    <svg class="w-3.5 h-3.5" data-lucide="share-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-share-2"></use></svg>
                    <span>Share</span>
                  </button>
                </div>
              </div>`;
            }
            recHtml += `</div>`;
          } else {
            recHtml = `<div class="bg-gray-800/30 rounded-lg p-6 text-center border border-gray-700/50">
              <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" data-lucide="video-off" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
              <p class="text-sm text-gray-400">No recordings found for this lesson.</p>
            </div>`;
          }

          // homework block
          let hwHtml = '';
          if (hws.length) {
            hwHtml = `<div class="space-y-3">
              <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-400" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
                <p class="font-semibold text-white">Homework Assignments (${hws.length})</p>
              </div>
              <div class="space-y-2">
                ${hws.map(x=>{
                  const dueDate = x.due_date ? new Date((x.due_date||'').replace(' ','T')) : null;
                  const isOverdue = dueDate && dueDate < new Date();
                  return `<div class="bg-gray-800/50 rounded-lg p-3 border border-gray-700/50">
                    <div class="flex items-start justify-between gap-2">
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                          <span class="text-xs font-mono text-indigo-400">#${x.id}</span>
                          <span class="text-sm font-medium text-white">${esc(x.title || 'Untitled')}</span>
                        </div>
                        ${dueDate ? `<div class="flex items-center gap-1.5 text-xs ${isOverdue ? 'text-red-400' : 'text-gray-400'}">
                          <svg class="w-3.5 h-3.5" data-lucide="${isOverdue ? 'alert-circle' : 'calendar'}" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-${isOverdue ? 'alert-circle' : 'calendar'}"></use></svg>
                          <span>Due: ${dueDate.toLocaleString()}${isOverdue ? ' (Overdue)' : ''}</span>
                        </div>` : '<span class="text-xs text-gray-500">No due date</span>'}
                      </div>
                    </div>
                  </div>`;
                }).join('')}
              </div>
            </div>`;
          } else {
            hwHtml = `<div class="bg-gray-800/30 rounded-lg p-4 text-center border border-gray-700/50">
              <svg class="w-8 h-8 text-gray-600 mx-auto mb-2" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
              <p class="text-sm text-gray-400">No homework found linked to this lesson.</p>
            </div>`;
          }

          // rating
          let ratingHtml = '';
          if (rating) {
            ratingHtml = `<div class="bg-gray-800 rounded-lg p-3">
              <div class="text-xs text-gray-400">Student rating</div>
              <div class="text-white font-semibold">${Number(rating.rating||0)}★</div>
              ${rating.feedback ? `<div class="text-sm text-gray-300 mt-1">${esc(rating.feedback)}</div>` : ``}
            </div>`;
          }

          // notes
          let notesHtml = '';
          if (L.tutor_notes && String(L.tutor_notes).trim().length) {
            notesHtml = `<div class="bg-gray-800/50 rounded-lg p-4 border border-gray-700/50">
              <div class="flex items-center gap-2 mb-2">
                <svg class="w-5 h-5 text-indigo-400" data-lucide="file-text" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file-text"></use></svg>
                <p class="font-semibold text-white">Tutor Notes</p>
              </div>
              <p class="text-sm text-gray-300 whitespace-pre-wrap leading-relaxed">${esc(L.tutor_notes)}</p>
            </div>`;
          }

          detailBody.innerHTML = `
            ${header}
            ${join}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div>${recHtml}</div>
              <div class="space-y-4">
                ${hwHtml}
                ${ratingHtml}
                ${notesHtml}
              </div>
            </div>
            <div class="text-right pt-2">
              <button class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg" id="detailClose2">Close</button>
            </div>
          `;
          document.getElementById('detailClose2')?.addEventListener('click', closeModal);
        } catch(e){
          detailBody.innerHTML = `<div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-center">
            <svg class="w-8 h-8 text-red-400 mx-auto mb-2" data-lucide="alert-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>
            <p class="text-red-300">Failed to load lesson details.</p>
            <p class="text-xs text-red-400 mt-1">${esc(e.message || 'Unknown error')}</p>
          </div>`;
          
        }
      });
    });

    // Share recording function
    function shareRecording(url, title) {
      if (navigator.share) {
        navigator.share({
          title: title || 'Lesson Recording',
          text: 'Check out this lesson recording',
          url: url
        }).catch(err => {
          console.log('Error sharing:', err);
          copyToClipboard(url);
        });
      } else {
        copyToClipboard(url);
      }
    }
    
    function copyToClipboard(text) {
      navigator.clipboard.writeText(text).then(() => {
        // Show toast notification
        const toast = document.createElement('div');
        toast.className = 'fixed top-4 right-4 bg-emerald-600 text-white px-4 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2';
        toast.innerHTML = '<svg class="w-4 h-4" data-lucide="check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg><span>Link copied to clipboard!</span>';
        document.body.appendChild(toast);
        
        setTimeout(() => {
          toast.remove();
        }, 3000);
      }).catch(err => {
        console.error('Failed to copy:', err);
        // Fallback
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        
        const toast = document.createElement('div');
        toast.className = 'fixed top-4 right-4 bg-emerald-600 text-white px-4 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2';
        toast.innerHTML = '<svg class="w-4 h-4" data-lucide="check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg><span>Link copied to clipboard!</span>';
        document.body.appendChild(toast);
        
        setTimeout(() => {
          toast.remove();
        }, 3000);
      });
    }

  </script>
</body>
</html>
