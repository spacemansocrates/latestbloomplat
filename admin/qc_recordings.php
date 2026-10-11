<?php
// admin/qc_recordings.php — Admin view to assign recordings to Quality Control users and see their feedback

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

// Optional columns detection
function has_column(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
  $q->execute([$table, $col]);
  return (bool)$q->fetchColumn();
}
$HAS_TUTOR_JOIN = has_column($pdo, 'lessons', 'tutor_join_time');
$HW_HAS_LESSON  = has_column($pdo, 'homework', 'lesson_id');

// --- Handle QC assignment POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action    = $_POST['action'] ?? '';
  $lesson_id = isset($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : 0;
  $qc_user_id_raw = $_POST['qc_user_id'] ?? '';
  $qc_user_id = ($qc_user_id_raw === '' ? 0 : (int)$qc_user_id_raw);

  if ($action === 'save_qc' && $lesson_id > 0) {
    try {
      $pdo->beginTransaction();

      if ($qc_user_id > 0) {
        // Validate that the QC user exists and is active
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'QualityControl' AND is_active = 1 LIMIT 1");
        $chk->execute([$qc_user_id]);
        $qc_exists = (int)$chk->fetchColumn();
        if ($qc_exists) {
          // Only one QC assignment per lesson (simple rule): remove any existing assignment for this lesson
          $del = $pdo->prepare("DELETE FROM quality_control_assignments WHERE lesson_id = ?");
          $del->execute([$lesson_id]);

          // Insert new assignment
          $ins = $pdo->prepare("
            INSERT INTO quality_control_assignments (qc_user_id, lesson_id, assigned_by_admin_id, status)
            VALUES (?, ?, ?, 'Assigned')
          ");
          $ins->execute([$qc_user_id, $lesson_id, $user_id]);
        }
      } else {
        // Unassign QC from this lesson (also removes any reviews via FK cascade)
        $del = $pdo->prepare("DELETE FROM quality_control_assignments WHERE lesson_id = ?");
        $del->execute([$lesson_id]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // optionally log
    }
  }

  // Redirect to avoid resubmissions (preserve GET filters)
  $query = $_GET;
  $qs = $query ? ('?' . http_build_query($query)) : '';
  header('Location: qc_recordings.php' . $qs);
  exit();
}

// ---- Static options ----
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

$filter_subject     = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int)$_GET['subject_id'] : 0;
$filter_tutor       = (isset($_GET['tutor_id']) && $_GET['tutor_id'] !== '') ? (int)$_GET['tutor_id'] : 0;
$filter_student     = (isset($_GET['student_id']) && $_GET['student_id'] !== '') ? (int)$_GET['student_id'] : 0;
$filter_year_level  = trim($_GET['year_level'] ?? '');
$filter_lesson_type = trim($_GET['lesson_type'] ?? '');
$filter_day_of_week = trim($_GET['dow'] ?? '');
$filter_qc_user     = (isset($_GET['qc_user_id']) && $_GET['qc_user_id'] !== '') ? (int)$_GET['qc_user_id'] : 0;

// ---- QC users list ----
$qcStmt = $pdo->prepare("
  SELECT id, first_name, last_name
  FROM users
  WHERE role = 'QualityControl' AND is_active = 1
  ORDER BY first_name, last_name
");
$qcStmt->execute();
$qc_users = $qcStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Fetch lessons in range + latest recording + QC assignment/review ----
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
if ($filter_qc_user > 0) {
  $whereClauses[] = "qca.qc_user_id = ?";
  $params[] = $filter_qc_user;
}

$whereSql = implode("\n  AND ", $whereClauses);

$sql = "
  SELECT
    l.id,
    l.scheduled_start_time,
    l.scheduled_end_time,
    l.status,
    l.student_id,
    l.tutor_id,
    l.student_join_time" .
    ($HAS_TUTOR_JOIN ? ", l.tutor_join_time" : ", NULL AS tutor_join_time") . ",
    subj.name AS subject_name,
    stu.first_name AS student_first, stu.last_name AS student_last,
    tut.first_name AS tutor_first,   tut.last_name AS tutor_last,
    lr.file_url     AS rec_url,
    lr.duration_seconds AS rec_duration,
    lr.file_size_bytes  AS rec_size,
    lr.recorded_at  AS rec_recorded_at,

    qca.id           AS qc_assignment_id,
    qca.qc_user_id   AS qc_user_id,
    qca.status       AS qc_status,
    qca.assigned_at  AS qc_assigned_at,

    qcr.id              AS qc_review_id,
    qcr.overall_rating  AS qc_overall_rating,
    qcr.behaviour_flag  AS qc_behaviour_flag,
    qcr.flag_for_admin  AS qc_flag_for_admin,
    qcr.comments_to_admin AS qc_comments_to_admin,
    qcr.created_at      AS qc_reviewed_at,

    qc.first_name AS qc_first_name,
    qc.last_name  AS qc_last_name

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
  LEFT JOIN (
    SELECT qa.*
    FROM quality_control_assignments qa
    JOIN (
      SELECT lesson_id, MAX(assigned_at) AS max_assigned
      FROM quality_control_assignments
      GROUP BY lesson_id
    ) latest_qc
      ON latest_qc.lesson_id = qa.lesson_id
     AND latest_qc.max_assigned = qa.assigned_at
  ) qca
    ON qca.lesson_id = l.id
  LEFT JOIN quality_control_reviews qcr
    ON qcr.assignment_id = qca.id
  LEFT JOIN users qc
    ON qc.id = qca.qc_user_id

  WHERE $whereSql
  ORDER BY l.scheduled_start_time DESC
  LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// ---- Preload homework info per lesson (same as original) ----
$lesson_ids = array_map(fn($r) => (int)$r['id'], $rows);
$hw_map = []; // lesson_id => array of homework rows
if ($lesson_ids) {
  if ($HW_HAS_LESSON) {
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

// --- AJAX: detail view for a lesson (same as recordings.php) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
  header('Content-Type: application/json; charset=UTF-8');
  $lesson_id = (int)($_GET['lesson_id'] ?? 0);
  if ($lesson_id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Bad id']); exit; }

  // Lesson row
  $q = $pdo->prepare("
    SELECT l.*, subj.name AS subject_name,
           stu.first_name AS student_first, stu.last_name AS student_last,
           tut.first_name AS tutor_first,   tut.last_name AS tutor_last
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

  // Recordings — served through the authenticated gateway (../api/recording_url.php),
  // which 302-redirects to a time-limited Cloudflare R2 link, or the local file for
  // anything not yet migrated. Admins never see the bucket or a raw file path.
  $rec = $pdo->prepare("SELECT id, file_size_bytes, duration_seconds, recorded_at, provider FROM lesson_recordings WHERE lesson_id=? ORDER BY recorded_at DESC");
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
      'provider'  => $r['provider'] ?: 'LiveKit',
      'source'    => 'db',
    ];
  }

  // Homework
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

  // Rating
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
    ],
    'recordings' => $recordings,
    'homework'   => $hws,
    'rating'     => $rating
  ]);
  exit;
}

// ---- Options for selects ----
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

// ---- UI helpers ----
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
  <title>Admin — Quality Control Recordings</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;background:#111827;color:#d1d5db}
    ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:#1f2937}::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .chip{font-size:12px;padding:3px 8px;border-radius:9999px;display:inline-flex;align-items:center}
  </style>
</head>
<body class="flex min-h-screen">
  <?php $current_page='qc_recordings'; include __DIR__.'/admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-6 lg:p-8">
    <header class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">Quality Control — Lesson Recordings</h1>
        <p class="text-gray-400">Assign recordings to QC users and view their feedback.</p>
      </div>
      <div class="flex gap-3">
        <a href="recordings.php" class="text-sm text-indigo-300 hover:text-indigo-200">Regular recordings view</a>
        <a href="admin_dashboard.php" class="text-sm text-indigo-300 hover:text-indigo-200">Back to dashboard</a>
      </div>
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
        <div>
          <label class="block text-sm text-gray-300 mb-1">QC User</label>
          <select name="qc_user_id" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All QC users</option>
            <?php foreach ($qc_users as $qc): ?>
              <?php $qid = (int)$qc['id']; $qName = trim(($qc['first_name'] ?? '').' '.($qc['last_name'] ?? '')) ?: ('QC #'.$qid); ?>
              <option value="<?= h($qid) ?>" <?= ($filter_qc_user === $qid) ? 'selected' : '' ?>><?= h($qName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2 xl:col-span-4 flex flex-wrap gap-3 justify-end">
          <a href="qc_recordings.php" class="text-sm text-gray-300 hover:text-white self-center">Reset</a>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2 rounded-lg">Apply</button>
        </div>
      </form>
    </section>

    <!-- Table -->
    <section class="bg-gray-900 rounded-lg overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-800 text-gray-300">
            <tr>
              <th class="px-4 py-3 text-left">Date</th>
              <th class="px-4 py-3 text-left">Subject</th>
              <th class="px-4 py-3 text-left">Tutor</th>
              <th class="px-4 py-3 text-left">Student</th>
              <th class="px-4 py-3 text-left">Recording</th>
              <th class="px-4 py-3 text-left">Quality Control</th>
              <th class="px-4 py-3 text-left">Joined</th>
              <th class="px-4 py-3 text-left">Tutor Late</th>
              <th class="px-4 py-3 text-left">Homework</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $viewerTz = $_SESSION['timezone'] ?? 'Europe/London';
              if (!in_array($viewerTz, DateTimeZone::listIdentifiers(), true)) $viewerTz = 'Europe/London';
              $viewerTzObj = new DateTimeZone($viewerTz);
            ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">No lessons found for this range.</td></tr>
            <?php else: foreach ($rows as $r):
              // Stored time is UTC; display in the viewer's zone.
              $start = $r['scheduled_start_time'] ? (new DateTime($r['scheduled_start_time'], new DateTimeZone('UTC')))->setTimezone($viewerTzObj) : null;
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

              $st_chip = $studentJoin ? '<span class="chip bg-emerald-500/15 text-emerald-300">Student</span>' : '<span class="chip bg-gray-700/60 text-gray-300">Student</span>';
              $tu_chip = $tutorJoin   ? '<span class="chip bg-emerald-500/15 text-emerald-300">Tutor</span>'   : '<span class="chip bg-gray-700/60 text-gray-300">Tutor</span>';

              // Route through the authenticated gateway (R2 presign or local fallback).
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

              // QC info
              $qcName = '';
              if (!empty($r['qc_first_name']) || !empty($r['qc_last_name'])) {
                $qcName = trim(($r['qc_first_name'] ?? '').' '.($r['qc_last_name'] ?? ''));
              }
              $qcStatus   = $r['qc_status'] ?? null;
              $qcRating   = isset($r['qc_overall_rating']) && $r['qc_overall_rating'] !== null ? (int)$r['qc_overall_rating'] : null;
              $qcBehaviour = $r['qc_behaviour_flag'] ?? 'None';
              $qcFlag     = (int)($r['qc_flag_for_admin'] ?? 0);
              $qcComments = trim((string)($r['qc_comments_to_admin'] ?? ''));

              // QC status chip
              $qcStatusChipClass = 'chip bg-gray-700/60 text-gray-300';
              if ($qcStatus === 'Assigned')  $qcStatusChipClass = 'chip bg-indigo-500/15 text-indigo-300';
              if ($qcStatus === 'InReview')  $qcStatusChipClass = 'chip bg-amber-500/15 text-amber-300';
              if ($qcStatus === 'Completed') $qcStatusChipClass = 'chip bg-emerald-500/15 text-emerald-300';
              if ($qcStatus === 'Cancelled') $qcStatusChipClass = 'chip bg-rose-500/15 text-rose-300';

              // behaviour chip
              $behChip = '';
              if ($qcBehaviour === 'MinorIssue') {
                $behChip = '<span class="chip bg-amber-500/15 text-amber-300 mt-1">Minor issue</span>';
              } elseif ($qcBehaviour === 'SeriousConcern') {
                $behChip = '<span class="chip bg-rose-500/15 text-rose-300 mt-1">Serious concern</span>';
              }

              $qcCommentsShort = $qcComments ? (mb_strlen($qcComments) > 90 ? mb_substr($qcComments,0,90).'…' : $qcComments) : '';
            ?>
              <tr class="border-t border-gray-800 hover:bg-gray-800/60">
                <td class="px-4 py-3 align-top"><?= h($start ? $start->format('M j, Y g:i a') : '—') ?></td>
                <td class="px-4 py-3 align-top"><?= h($r['subject_name']) ?></td>
                <td class="px-4 py-3 align-top"><?= h($r['tutor_first'].' '.$r['tutor_last']) ?></td>
                <td class="px-4 py-3 align-top"><?= h($r['student_first'].' '.$r['student_last']) ?></td>
                <td class="px-4 py-3 align-top">
                  <?php if ($rec_url): ?>
                    <div class="text-xs text-gray-300">
                      <div><?= h($rec_info) ?></div>
                      <a href="<?= h($rec_url) ?>" target="_blank" class="text-indigo-300 hover:text-indigo-200 underline">Open</a>
                    </div>
                  <?php else: ?>
                    <span class="text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 align-top">
                  <div class="text-xs text-gray-200">
                    <?php if ($qcName): ?>
                      <div>Assigned to: <span class="text-gray-100 font-semibold"><?= h($qcName) ?></span></div>
                      <?php if ($qcStatus): ?>
                        <div class="mt-1"><span class="<?= $qcStatusChipClass ?>"><?= h($qcStatus) ?></span></div>
                      <?php endif; ?>
                      <?php if ($r['qc_review_id']): ?>
                        <div class="mt-2 space-y-1">
                          <?php if ($qcRating !== null): ?>
                            <div>Rating: <span class="text-yellow-300 font-semibold"><?= h($qcRating) ?>★</span></div>
                          <?php endif; ?>
                          <?php if ($behChip): ?>
                            <div><?= $behChip ?></div>
                          <?php endif; ?>
                          <?php if ($qcFlag): ?>
                            <div class="text-xs text-rose-300">Flagged for admin attention</div>
                          <?php endif; ?>
                          <?php if ($qcCommentsShort): ?>
                            <div class="text-xs text-gray-300">“<?= h($qcCommentsShort) ?>”</div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <div class="mt-2 text-xs text-gray-400">No review submitted yet.</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="text-xs text-gray-400 mb-1">Not assigned to QC.</div>
                    <?php endif; ?>
                  </div>

                  <!-- Assign / change QC -->
                  <form method="post" class="mt-3 flex flex-col gap-2 text-xs">
                    <input type="hidden" name="lesson_id" value="<?= (int)$r['id'] ?>">
                    <div class="flex gap-2">
                      <select name="qc_user_id" class="flex-1 bg-gray-800 text-white border border-gray-700 rounded-lg p-1.5 text-xs">
                        <option value="">Unassigned</option>
                        <?php foreach ($qc_users as $qc): ?>
                          <?php
                            $qid = (int)$qc['id'];
                            $qName = trim(($qc['first_name'] ?? '').' '.($qc['last_name'] ?? '')) ?: ('QC #'.$qid);
                            $sel = ((int)($r['qc_user_id'] ?? 0) === $qid) ? 'selected' : '';
                          ?>
                          <option value="<?= h($qid) ?>" <?= $sel ?>><?= h($qName) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" name="action" value="save_qc"
                              class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-3 py-1.5 rounded-lg">
                        Save
                      </button>
                    </div>
                  </form>
                </td>
                <td class="px-4 py-3 align-top space-x-1"><?= $st_chip ?> <?= $tu_chip ?></td>
                <td class="px-4 py-3 align-top"><span class="<?= $late_chip_class ?>"><?= h($tutorLateMin) ?></span></td>
                <td class="px-4 py-3 align-top text-gray-300"><?= $hw_summary ?></td>
                <td class="px-4 py-3 align-top text-right">
                  <button class="detail-btn bg-gray-700 hover:bg-gray-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg"
                          data-lesson-id="<?= (int)$r['id'] ?>">Details</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <!-- Detail Modal -->
  <div id="detailModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-3xl p-6 relative">
      <button id="detailClose" class="absolute top-4 right-4 text-gray-400 hover:text-white">
        <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <div id="detailBody" class="space-y-4">Loading…</div>
    </div>
  </div>

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
        detailBody.innerHTML = 'Loading…';
        openModal();
        try{
          const r = await fetch(`qc_recordings.php?ajax=detail&lesson_id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
          const data = await r.json();
          if (!data || !data.ok) { detailBody.textContent = data?.error || 'Failed to load.'; return; }

          const L = data.lesson;
          const recs = data.recordings || [];
          const hws  = data.homework || [];
          const rating = data.rating;

          let header = `
            <div class="flex items-start justify-between">
              <div>
                <h3 class="text-xl font-bold text-white">${esc(L.subject)}</h3>
                <p class="text-sm text-gray-400">Lesson #${L.id} · ${new Date(L.start.replace(' ','T')).toLocaleString()}</p>
                <p class="text-sm text-gray-400">Tutor: <span class="text-gray-200">${esc(L.tutor)}</span> · Student: <span class="text-gray-200">${esc(L.student)}</span></p>
              </div>
              <span class="chip ${L.status==='Completed'?'bg-emerald-500/15 text-emerald-300': (String(L.status||'').startsWith('Cancelled')?'bg-rose-500/15 text-rose-300': L.status==='NoShow'?'bg-amber-500/15 text-amber-300': 'bg-indigo-500/15 text-indigo-300')}">${esc(L.status || '—')}</span>
            </div>
          `;

          let join = `
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="bg-gray-800 rounded-lg p-3">
                <div class="text-xs text-gray-400">Student joined</div>
                <div class="text-white font-semibold">${L.student_join_time ? new Date(L.student_join_time.replace(' ','T')).toLocaleString() : '—'}</div>
              </div>
              <div class="bg-gray-800 rounded-lg p-3">
                <div class="text-xs text-gray-400">Tutor joined</div>
                <div class="text-white font-semibold">${L.tutor_join_time ? new Date(L.tutor_join_time.replace(' ','T')).toLocaleString() : '—'}</div>
              </div>
              <div class="bg-gray-800 rounded-lg p-3">
                <div class="text-xs text-gray-400">Ended</div>
                <div class="text-white font-semibold">${L.end ? new Date(L.end.replace(' ','T')).toLocaleString() : '—'}</div>
              </div>
            </div>
          `;

          let recHtml = '';
          if (recs.length) {
            recHtml += `<div class="space-y-3"><p class="font-semibold text-white">Recordings</p>`;
            for (const r of recs) {
              // Recordings are webm video served via the gateway (302 → R2 link).
              const embed = `<video src="${esc(r.url)}" controls preload="metadata" class="w-full rounded-lg bg-black/30 border border-gray-700"></video>`;
              recHtml += `<div class="bg-gray-800 rounded-lg p-3 space-y-2">
                <div class="text-sm text-gray-300">${r.recorded ? new Date(r.recorded.replace(' ','T')).toLocaleString() : ''} · ${humanDur(r.duration)} · ${humanSize(r.size)}${r.provider ? ' · ' + esc(r.provider) : ''}</div>
                ${embed}
                <div><a href="${esc(r.url)}" target="_blank" class="text-indigo-300 hover:text-indigo-200 underline text-xs">Open in new tab</a></div>
              </div>`;
            }
            recHtml += `</div>`;
          } else {
            recHtml = `<p class="text-sm text-gray-400">No recordings found for this lesson.</p>`;
          }

          let hwHtml = '';
          if (hws.length) {
            hwHtml = `<div class="space-y-2">
              <p class="font-semibold text-white">Homework (linked/near this lesson)</p>
              <ul class="list-disc list-inside text-sm text-gray-300">
                ${hws.map(x=>`<li>#${x.id} — ${esc(x.title || '')}${x.due_date ? ` <span class="text-gray-400">(due ${new Date((x.due_date||'').replace(' ','T')).toLocaleString()})</span>` : ''}</li>`).join('')}
              </ul>
            </div>`;
          } else {
            hwHtml = `<p class="text-sm text-gray-400">No homework found linked to this lesson.</p>`;
          }

          let ratingHtml = '';
          if (rating) {
            ratingHtml = `<div class="bg-gray-800 rounded-lg p-3">
              <div class="text-xs text-gray-400">Student rating</div>
              <div class="text-white font-semibold">${Number(rating.rating||0)}★</div>
              ${rating.feedback ? `<div class="text-sm text-gray-300 mt-1">${esc(rating.feedback)}</div>` : ``}
            </div>`;
          }

          let notesHtml = '';
          if (L.tutor_notes && String(L.tutor_notes).trim().length) {
            notesHtml = `<div>
              <p class="font-semibold text-white">Tutor Notes</p>
              <p class="text-sm text-gray-300 mt-1 whitespace-pre-wrap">${esc(L.tutor_notes)}</p>
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
          document.getElementById('detailClose2')?.addEventListener('click', ()=>detailModal.classList.add('hidden'));
        } catch(e){
          detailBody.textContent = 'Failed to load.';
        }
      });
    });
  </script>
</body>
</html>
