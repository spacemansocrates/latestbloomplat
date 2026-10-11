<?php
// admin/qc_assignments.php — Simple admin page to bulk-assign recordings to QC users
// and view QC feedback.

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

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------------------------
// Load QC users
// -----------------------------------------------------------------------------
$qcStmt = $pdo->prepare("
    SELECT id, first_name, last_name
    FROM users
    WHERE role = 'QualityControl' AND is_active = 1
    ORDER BY first_name, last_name
");
$qcStmt->execute();
$qc_users = $qcStmt->fetchAll(PDO::FETCH_ASSOC);

// Messages for bulk assign
$bulk_info  = '';
$bulk_error = '';

// -----------------------------------------------------------------------------
// Handle bulk assign POST
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_assign') {
    $qc_user_id = isset($_POST['qc_user_id']) && $_POST['qc_user_id'] !== ''
        ? (int)$_POST['qc_user_id'] : 0;
    $overwrite  = !empty($_POST['overwrite_existing']); // checkbox

    // Basic validation: QC user must exist
    if ($qc_user_id <= 0) {
        $bulk_error = 'Please select a Quality Control user before assigning.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'QualityControl' AND is_active = 1 LIMIT 1");
        $chk->execute([$qc_user_id]);
        $qc_exists = (int)$chk->fetchColumn();
        if (!$qc_exists) {
            $bulk_error = 'Selected QC user is invalid or inactive.';
        }
    }

    if ($bulk_error === '') {
        // Read filters from POST (mirrors GET filters below)
        $date_from = $_POST['from'] ?? '';
        $date_to   = $_POST['to']   ?? '';

        try {
            $from = $date_from ? new DateTime($date_from . ' 00:00:00') : (new DateTime())->modify('-30 days')->setTime(0,0,0);
        } catch(Exception $e) {
            $from = (new DateTime())->modify('-30 days')->setTime(0,0,0);
        }
        try {
            $to   = $date_to ? new DateTime($date_to . ' 23:59:59') : (new DateTime())->setTime(23,59,59);
        } catch(Exception $e) {
            $to = (new DateTime())->setTime(23,59,59);
        }

        $from_sql = $from->format('Y-m-d H:i:s');
        $to_sql   = $to->format('Y-m-d H:i:s');

        $filter_subject    = (isset($_POST['subject_id']) && $_POST['subject_id'] !== '') ? (int)$_POST['subject_id'] : 0;
        $filter_year_level = trim($_POST['year_level'] ?? '');
        $filter_tutor      = (isset($_POST['tutor_id']) && $_POST['tutor_id'] !== '') ? (int)$_POST['tutor_id'] : 0;
        $filter_student    = (isset($_POST['student_id']) && $_POST['student_id'] !== '') ? (int)$_POST['student_id'] : 0;

        $whereClauses = [
            "l.scheduled_start_time BETWEEN ? AND ?"
        ];
        $params = [$from_sql, $to_sql];

        if ($filter_subject > 0) {
            $whereClauses[] = "l.subject_id = ?";
            $params[] = $filter_subject;
        }
        if ($filter_year_level !== '' && $filter_year_level !== 'all') {
            $whereClauses[] = "subj.year_level = ?";
            $params[] = $filter_year_level;
        }
        if ($filter_tutor > 0) {
            $whereClauses[] = "l.tutor_id = ?";
            $params[] = $filter_tutor;
        }
        if ($filter_student > 0) {
            $whereClauses[] = "l.student_id = ?";
            $params[] = $filter_student;
        }

        $whereSql = implode("\n  AND ", $whereClauses);

        // Select lessons that HAVE at least one recording
        $sqlLessons = "
            SELECT DISTINCT l.id
            FROM lessons l
            JOIN subjects subj ON subj.id = l.subject_id
            JOIN lesson_recordings lr ON lr.lesson_id = l.id
            WHERE $whereSql
        ";
        $ls = $pdo->prepare($sqlLessons);
        $ls->execute($params);
        $lesson_ids = $ls->fetchAll(PDO::FETCH_COLUMN);
        $lesson_ids = array_map('intval', $lesson_ids);

        if (!$lesson_ids) {
            $bulk_info = 'No lessons with recordings match the selected filters, so nothing was assigned.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($overwrite) {
                    // Remove any existing QC assignments for these lessons
                    $in = implode(',', array_fill(0, count($lesson_ids), '?'));
                    $del = $pdo->prepare("DELETE FROM quality_control_assignments WHERE lesson_id IN ($in)");
                    $del->execute($lesson_ids);
                } else {
                    // Filter out lessons that already have any QC assignment
                    $in = implode(',', array_fill(0, count($lesson_ids), '?'));
                    $ex = $pdo->prepare("SELECT DISTINCT lesson_id FROM quality_control_assignments WHERE lesson_id IN ($in)");
                    $ex->execute($lesson_ids);
                    $already = $ex->fetchAll(PDO::FETCH_COLUMN);
                    $already = array_map('intval', $already);
                    $alreadySet = array_flip($already);

                    $lesson_ids = array_values(array_filter($lesson_ids, fn($id) => !isset($alreadySet[$id])));
                }

                if ($lesson_ids) {
                    $ins = $pdo->prepare("
                        INSERT INTO quality_control_assignments (qc_user_id, lesson_id, assigned_by_admin_id, status)
                        VALUES (?, ?, ?, 'Assigned')
                    ");
                    foreach ($lesson_ids as $lid) {
                        $ins->execute([$qc_user_id, $lid, $user_id]);
                    }
                    $pdo->commit();
                    $bulk_info = 'Assigned ' . count($lesson_ids) . ' recordings to the selected QC user.';
                } else {
                    if ($overwrite) {
                        $bulk_info = 'No lessons left to assign (all removed or none matched).';
                    } else {
                        $bulk_info = 'All matching lessons already had a QC assignment, so nothing new was assigned.';
                    }
                    if ($pdo->inTransaction()) $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $bulk_error = 'An error occurred while assigning recordings: ' . $e->getMessage();
            }
        }
    }

    // Allow page to continue and show updated preview + messages
}

// -----------------------------------------------------------------------------
// GET filters (for UI preview)
// -----------------------------------------------------------------------------
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to']   ?? '';

try {
    $from = $date_from ? new DateTime($date_from . ' 00:00:00') : (new DateTime())->modify('-30 days')->setTime(0,0,0);
} catch(Exception $e) {
    $from = (new DateTime())->modify('-30 days')->setTime(0,0,0);
}
try {
    $to   = $date_to ? new DateTime($date_to . ' 23:59:59') : (new DateTime())->setTime(23,59,59);
} catch(Exception $e) {
    $to = (new DateTime())->setTime(23,59,59);
}

$from_sql = $from->format('Y-m-d H:i:s');
$to_sql   = $to->format('Y-m-d H:i:s');

$filter_subject    = (isset($_GET['subject_id']) && $_GET['subject_id'] !== '') ? (int)$_GET['subject_id'] : 0;
$filter_year_level = trim($_GET['year_level'] ?? '');
$filter_tutor      = (isset($_GET['tutor_id']) && $_GET['tutor_id'] !== '') ? (int)$_GET['tutor_id'] : 0;
$filter_student    = (isset($_GET['student_id']) && $_GET['student_id'] !== '') ? (int)$_GET['student_id'] : 0;
$filter_qc_user    = (isset($_GET['qc_user_id']) && $_GET['qc_user_id'] !== '') ? (int)$_GET['qc_user_id'] : 0;

// Build WHERE for preview
$whereClauses = [
    "l.scheduled_start_time BETWEEN ? AND ?"
];
$params = [$from_sql, $to_sql];

if ($filter_subject > 0) {
    $whereClauses[] = "l.subject_id = ?";
    $params[] = $filter_subject;
}
if ($filter_year_level !== '' && $filter_year_level !== 'all') {
    $whereClauses[] = "subj.year_level = ?";
    $params[] = $filter_year_level;
}
if ($filter_tutor > 0) {
    $whereClauses[] = "l.tutor_id = ?";
    $params[] = $filter_tutor;
}
if ($filter_student > 0) {
    $whereClauses[] = "l.student_id = ?";
    $params[] = $filter_student;
}
if ($filter_qc_user > 0) {
    $whereClauses[] = "qca.qc_user_id = ?";
    $params[] = $filter_qc_user;
}

$whereSql = implode("\n  AND ", $whereClauses);

// Count matching lessons with recordings
$sqlCount = "
    SELECT COUNT(DISTINCT l.id)
    FROM lessons l
    JOIN subjects subj ON subj.id = l.subject_id
    JOIN lesson_recordings lr ON lr.lesson_id = l.id
    LEFT JOIN quality_control_assignments qca ON qca.lesson_id = l.id
    WHERE $whereSql
";
$ct = $pdo->prepare($sqlCount);
$ct->execute($params);
$total_matching = (int)$ct->fetchColumn();

// Preview list (limited)
$sqlPreview = "
    SELECT DISTINCT
        l.id,
        l.scheduled_start_time,
        subj.name AS subject_name,
        subj.year_level,
        tut.first_name AS tutor_first, tut.last_name AS tutor_last,
        stu.first_name AS student_first, stu.last_name AS student_last,
        qca.qc_user_id,
        qca.status AS qc_status,
        qc.first_name AS qc_first_name,
        qc.last_name  AS qc_last_name
    FROM lessons l
    JOIN subjects subj ON subj.id = l.subject_id
    JOIN users tut ON tut.id = l.tutor_id
    JOIN users stu ON stu.id = l.student_id
    JOIN lesson_recordings lr ON lr.lesson_id = l.id
    LEFT JOIN quality_control_assignments qca ON qca.lesson_id = l.id
    LEFT JOIN users qc ON qc.id = qca.qc_user_id
    WHERE $whereSql
    ORDER BY l.scheduled_start_time DESC
    LIMIT 100
";
$st = $pdo->prepare($sqlPreview);
$st->execute($params);
$preview_rows = $st->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Options for selects
// -----------------------------------------------------------------------------
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

// -----------------------------------------------------------------------------
// Recent QC feedback (simple list)
// -----------------------------------------------------------------------------
$feedback_rows = $pdo->query("
    SELECT
        qcr.id,
        qcr.created_at,
        qcr.overall_rating,
        qcr.behaviour_flag,
        qcr.flag_for_admin,
        qcr.comments_to_admin,
        l.id AS lesson_id,
        l.scheduled_start_time,
        subj.name AS subject_name,
        tut.first_name AS tutor_first, tut.last_name AS tutor_last,
        stu.first_name AS student_first, stu.last_name AS student_last,
        qc.first_name AS qc_first_name, qc.last_name AS qc_last_name
    FROM quality_control_reviews qcr
    JOIN quality_control_assignments qca ON qca.id = qcr.assignment_id
    JOIN lessons l ON l.id = qcr.lesson_id
    JOIN subjects subj ON subj.id = l.subject_id
    JOIN users tut ON tut.id = l.tutor_id
    JOIN users stu ON stu.id = l.student_id
    JOIN users qc  ON qc.id = qcr.qc_user_id
    ORDER BY qcr.flag_for_admin DESC, qcr.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — QC Assignments</title>
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
  <?php $current_page='qc_assignments'; include __DIR__.'/admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-6 lg:p-8 space-y-8">
    <header class="mb-2 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">Quality Control Assignments</h1>
        <p class="text-gray-400 text-sm">Pick a QC user, filter recordings by date / tutor / student / class, and bulk-assign them.</p>
      </div>
      <a href="recordings.php" class="text-sm text-indigo-300 hover:text-indigo-200">Go to full recordings view</a>
    </header>

    <?php if ($bulk_error): ?>
      <div class="bg-rose-900/60 border border-rose-700 text-rose-100 text-sm px-4 py-3 rounded-lg">
        <?= h($bulk_error) ?>
      </div>
    <?php elseif ($bulk_info): ?>
      <div class="bg-emerald-900/40 border border-emerald-600 text-emerald-100 text-sm px-4 py-3 rounded-lg">
        <?= h($bulk_info) ?>
      </div>
    <?php endif; ?>

    <!-- STEP 1 + 2: Filters & choose QC -->
    <section class="bg-gray-900 rounded-lg p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white mb-1">Step 1 & 2 — Choose QC user and filter recordings</h2>
      <p class="text-sm text-gray-400 mb-3">
        Use these filters to define the <strong>pool of recordings</strong> you want to assign.
        Then choose a QC user and click <strong>Assign</strong>.
      </p>

      <!-- Filters form (GET) -->
      <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-4 items-end">
        <div>
          <label class="block text-sm text-gray-300 mb-1">From (date)</label>
          <input type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1">To (date)</label>
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
          <label class="block text-sm text-gray-300 mb-1">Class / Year level</label>
          <select name="year_level" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">All year levels</option>
            <?php foreach ($year_level_options as $yl): ?>
              <?php $label = is_numeric($yl) ? 'Year '.$yl : $yl; ?>
              <option value="<?= h($yl) ?>" <?= ($filter_year_level !== '' && (string)$filter_year_level === (string)$yl) ? 'selected' : '' ?>><?= h($label) ?></option>
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
          <label class="block text-sm text-gray-300 mb-1">Currently assigned QC (optional filter)</label>
          <select name="qc_user_id" class="w-full bg-gray-800 text-white border border-gray-700 rounded-lg p-2.5">
            <option value="">Any QC / unassigned</option>
            <?php foreach ($qc_users as $qc): ?>
              <?php $qid = (int)$qc['id']; $qName = trim(($qc['first_name'] ?? '').' '.($qc['last_name'] ?? '')) ?: ('QC #'.$qid); ?>
              <option value="<?= h($qid) ?>" <?= ($filter_qc_user === $qid) ? 'selected' : '' ?>><?= h($qName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2 xl:col-span-4 flex flex-wrap gap-3 justify-end">
          <a href="qc_assignments.php" class="text-sm text-gray-300 hover:text-white self-center">Reset</a>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-4 py-2 rounded-lg">Apply filters</button>
        </div>
      </form>

      <!-- Summary + bulk assign form -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-start">
        <div class="bg-gray-800 rounded-lg p-4">
          <div class="text-xs uppercase tracking-wide text-gray-400 mb-1">Matching recordings</div>
          <div class="text-3xl font-bold text-white"><?= h($total_matching) ?></div>
          <p class="text-xs text-gray-400 mt-1">
            Lessons that <strong>have at least one recording</strong> and match the filters above.
          </p>
        </div>

        <!-- Bulk assign panel -->
        <form method="post" class="bg-gray-800 rounded-lg p-4 space-y-3 md:col-span-2">
          <input type="hidden" name="action" value="bulk_assign">
          <!-- Pass filters through as hidden so POST uses same range -->
          <input type="hidden" name="from" value="<?= h($from->format('Y-m-d')) ?>">
          <input type="hidden" name="to" value="<?= h($to->format('Y-m-d')) ?>">
          <input type="hidden" name="subject_id" value="<?= $filter_subject ? h($filter_subject) : '' ?>">
          <input type="hidden" name="year_level" value="<?= h($filter_year_level) ?>">
          <input type="hidden" name="tutor_id" value="<?= $filter_tutor ? h($filter_tutor) : '' ?>">
          <input type="hidden" name="student_id" value="<?= $filter_student ? h($filter_student) : '' ?>">

          <div class="flex flex-col md:flex-row gap-3 items-start md:items-center">
            <div class="flex-1">
              <label class="block text-xs text-gray-300 mb-1">Step 3 — Assign to QC user</label>
              <select name="qc_user_id" class="w-full bg-gray-900 text-white border border-gray-700 rounded-lg p-2 text-sm">
                <option value="">Select a QC user…</option>
                <?php foreach ($qc_users as $qc): ?>
                  <?php $qid = (int)$qc['id']; $qName = trim(($qc['first_name'] ?? '').' '.($qc['last_name'] ?? '')) ?: ('QC #'.$qid); ?>
                  <option value="<?= h($qid) ?>"><?= h($qName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex-1 text-xs text-gray-300">
              <label class="inline-flex items-center gap-2 mb-1">
                <input type="checkbox" name="overwrite_existing" value="1" class="rounded border-gray-600 bg-gray-900">
                <span>Overwrite existing QC assignments for these lessons</span>
              </label>
              <p class="text-[11px] text-gray-400 mt-1">
                If unchecked, only recordings that currently have <strong>no QC assignment</strong> will be assigned.
              </p>
            </div>
          </div>

          <div class="flex justify-between items-center mt-2">
            <p class="text-xs text-gray-400">
              Step 4 — Click to assign all <strong><?= h($total_matching) ?></strong> matching recordings 
              (after applying the overwrite rule).
            </p>
            <button type="submit"
                    class="bg-emerald-600 hover:bg-emerald-500 text-white font-semibold px-4 py-2 rounded-lg text-sm"
                    <?= $total_matching === 0 ? 'disabled class="opacity-50 cursor-not-allowed bg-emerald-700 text-white font-semibold px-4 py-2 rounded-lg text-sm"' : '' ?>>
              Assign recordings to QC
            </button>
          </div>
        </form>
      </div>

      <!-- Preview table -->
      <div class="mt-5">
        <h3 class="text-sm font-semibold text-gray-200 mb-2">Preview of matching recordings (first 100)</h3>
        <div class="overflow-x-auto bg-gray-800 rounded-lg">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-900 text-gray-300 text-[11px] uppercase">
              <tr>
                <th class="px-3 py-2 text-left">Date</th>
                <th class="px-3 py-2 text-left">Subject</th>
                <th class="px-3 py-2 text-left">Class</th>
                <th class="px-3 py-2 text-left">Tutor</th>
                <th class="px-3 py-2 text-left">Student</th>
                <th class="px-3 py-2 text-left">QC status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($preview_rows)): ?>
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">No lessons with recordings match the current filters.</td></tr>
              <?php else: foreach ($preview_rows as $r):
                $dt = $r['scheduled_start_time'] ? (new DateTime($r['scheduled_start_time']))->format('M j, Y g:i a') : '—';
                $classLabel = $r['year_level'] !== null && $r['year_level'] !== '' ? 'Year '.$r['year_level'] : '—';
                $qcName = '';
                if (!empty($r['qc_first_name']) || !empty($r['qc_last_name'])) {
                  $qcName = trim(($r['qc_first_name'] ?? '').' '.($r['qc_last_name'] ?? ''));
                }
                $qcStatus = $r['qc_status'] ?? null;
                $chipClass = 'chip bg-gray-700/60 text-gray-300';
                if ($qcStatus === 'Assigned')  $chipClass = 'chip bg-indigo-500/15 text-indigo-300';
                if ($qcStatus === 'InReview')  $chipClass = 'chip bg-amber-500/15 text-amber-300';
                if ($qcStatus === 'Completed') $chipClass = 'chip bg-emerald-500/15 text-emerald-300';
                if ($qcStatus === 'Cancelled') $chipClass = 'chip bg-rose-500/15 text-rose-300';
              ?>
                <tr class="border-t border-gray-700/60 hover:bg-gray-900/40">
                  <td class="px-3 py-2 align-top text-gray-200"><?= h($dt) ?></td>
                  <td class="px-3 py-2 align-top text-gray-200"><?= h($r['subject_name']) ?></td>
                  <td class="px-3 py-2 align-top text-gray-300"><?= h($classLabel) ?></td>
                  <td class="px-3 py-2 align-top text-gray-300"><?= h($r['tutor_first'].' '.$r['tutor_last']) ?></td>
                  <td class="px-3 py-2 align-top text-gray-300"><?= h($r['student_first'].' '.$r['student_last']) ?></td>
                  <td class="px-3 py-2 align-top text-gray-200">
                    <?php if ($qcName): ?>
                      <div><?= h($qcName) ?></div>
                      <?php if ($qcStatus): ?>
                        <div class="mt-1 inline-block"><span class="<?= $chipClass ?>"><?= h($qcStatus) ?></span></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-gray-400">Not assigned</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- QC Feedback section -->
    <section class="bg-gray-900 rounded-lg p-5">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h2 class="text-lg font-semibold text-white">Recent QC Feedback</h2>
          <p class="text-sm text-gray-400">The latest quality control reviews on lesson recordings.</p>
        </div>
      </div>

      <div class="overflow-x-auto bg-gray-800 rounded-lg">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-900 text-gray-300 text-[11px] uppercase">
            <tr>
              <th class="px-3 py-2 text-left">Date</th>
              <th class="px-3 py-2 text-left">QC User</th>
              <th class="px-3 py-2 text-left">Lesson</th>
              <th class="px-3 py-2 text-left">Tutor</th>
              <th class="px-3 py-2 text-left">Student</th>
              <th class="px-3 py-2 text-left">Rating</th>
              <th class="px-3 py-2 text-left">Behaviour</th>
              <th class="px-3 py-2 text-left">Flag</th>
              <th class="px-3 py-2 text-left">Comment</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($feedback_rows)): ?>
              <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No quality control feedback has been submitted yet.</td></tr>
            <?php else: foreach ($feedback_rows as $f):
              $dt = $f['created_at'] ? (new DateTime($f['created_at']))->format('M j, Y g:i a') : '—';
              $qcName = trim(($f['qc_first_name'] ?? '').' '.($f['qc_last_name'] ?? ''));
              $lessonLabel = ($f['subject_name'] ?? 'Lesson').' · #'.$f['lesson_id'];
              $beh = $f['behaviour_flag'] ?? 'None';
              $flag = (int)$f['flag_for_admin'] === 1;
              $comment = trim((string)($f['comments_to_admin'] ?? ''));
              if ($comment && mb_strlen($comment) > 120) {
                $comment = mb_substr($comment, 0, 120) . '…';
              }

              $behChip = '';
              if ($beh === 'MinorIssue') {
                $behChip = '<span class="chip bg-amber-500/15 text-amber-300">Minor issue</span>';
              } elseif ($beh === 'SeriousConcern') {
                $behChip = '<span class="chip bg-rose-500/15 text-rose-300">Serious concern</span>';
              } else {
                $behChip = '<span class="chip bg-gray-700/60 text-gray-300">None</span>';
              }
            ?>
              <tr class="border-t border-gray-700/60 hover:bg-gray-900/40">
                <td class="px-3 py-2 align-top text-gray-200"><?= h($dt) ?></td>
                <td class="px-3 py-2 align-top text-gray-200"><?= h($qcName) ?></td>
                <td class="px-3 py-2 align-top text-gray-200"><?= h($lessonLabel) ?></td>
                <td class="px-3 py-2 align-top text-gray-300"><?= h($f['tutor_first'].' '.$f['tutor_last']) ?></td>
                <td class="px-3 py-2 align-top text-gray-300"><?= h($f['student_first'].' '.$f['student_last']) ?></td>
                <td class="px-3 py-2 align-top text-gray-200">
                  <?php if ($f['overall_rating'] !== null): ?>
                    <span class="text-yellow-300 font-semibold"><?= h((int)$f['overall_rating']) ?>★</span>
                  <?php else: ?>
                    <span class="text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top"><?= $behChip ?></td>
                <td class="px-3 py-2 align-top">
                  <?php if ($flag): ?>
                    <span class="chip bg-rose-500/15 text-rose-300">Flagged</span>
                  <?php else: ?>
                    <span class="text-gray-500 text-[11px]">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2 align-top text-gray-300">
                  <?= $comment ? h($comment) : '<span class="text-gray-500 text-[11px]">No admin comment</span>' ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
