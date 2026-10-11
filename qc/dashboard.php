<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// qc/dashboard.php — Quality Control user dashboard
session_start();

date_default_timezone_set('Africa/Blantyre');

// ---- Auth check ----
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header('Location: ../login.php');
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

if ($user_role !== 'QualityControl' && $user_role !== 'Administrator') {
    // Only QC users (and optionally admins) can access this
    header('Location: ../login.php');
    exit();
}

// ---- DB connection (same credentials as your other admin pages) ----
$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASSWORD;

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('DB error: ' . htmlspecialchars($e->getMessage()));
}

// ---- Helpers ----
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    try {
        // Stored value is UTC; show it in the viewer's timezone (UK fallback).
        $tz = $_SESSION['timezone'] ?? 'Europe/London';
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) $tz = 'Europe/London';
        $d = new DateTime($s, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz));
        return $d->format('M j, Y g:i a');
    } catch (Exception $e) {
        return $s;
    }
}

function fmt_dur(int $s): string {
    if ($s <= 0) return '—';
    $m = intdiv($s, 60);
    $r = $s % 60;
    return $m . 'm ' . str_pad((string)$r, 2, '0', STR_PAD_LEFT) . 's';
}

function fmt_size(int $b): string {
    if ($b <= 0) return '—';
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $v = $b;
    while ($v >= 1024 && $i < count($u)-1) {
        $v /= 1024;
        $i++;
    }
    return sprintf(($v<10 && $i>0)?'%.1f %s':'%.0f %s', $v, $u[$i]);
}

// Optional-column helper (guards against schema differences)
function has_column(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}

// Detect review columns that may or may not exist
$col_rating_pedagogy    = has_column($pdo, 'quality_control_reviews', 'rating_pedagogy');
$col_rating_tech        = has_column($pdo, 'quality_control_reviews', 'rating_tech');
$col_student_engagement = has_column($pdo, 'quality_control_reviews', 'student_engagement');
$col_tags               = has_column($pdo, 'quality_control_reviews', 'tags');
$col_needs_followup     = has_column($pdo, 'quality_control_reviews', 'needs_followup');

// ---- CSRF ----
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---- Flash helpers ----
$flash_success = [];
$flash_error   = [];
function add_success($m){ global $flash_success; $flash_success[] = $m; }
function add_error($m){ global $flash_error; $flash_error[] = $m; }

/*
|--------------------------------------------------------------------------
| AJAX: Recordings for a lesson
| -> DB (COALESCE(playback_url, file_url)) + Lessonspace API, deduped
|--------------------------------------------------------------------------
*/
if (isset($_GET['ajax']) && $_GET['ajax'] === 'recordings') {
    header('Content-Type: application/json; charset=UTF-8');
    $lesson_id = (int)($_GET['lesson_id'] ?? 0);
    if ($lesson_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Bad lesson id']);
        exit;
    }

    // Ensure this lesson is assigned to this QC (unless admin)
    if ($user_role === 'QualityControl') {
        $chk = $pdo->prepare("
            SELECT 1 FROM quality_control_assignments
            WHERE lesson_id = ? AND qc_user_id = ? LIMIT 1
        ");
        $chk->execute([$lesson_id, $user_id]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Not assigned to this lesson']);
            exit;
        }
    }

    $recordings = [];

    // DB recordings (all, newest first). Every recording is served through the
    // authenticated gateway (../api/recording_url.php), which redirects to a
    // time-limited Cloudflare R2 presigned URL — or the local file for anything
    // not yet migrated. QC never sees the bucket or a raw file path.
    $rec = $pdo->prepare("
        SELECT
            id,
            file_size_bytes,
            duration_seconds,
            recorded_at,
            provider,
            status AS recording_status
        FROM lesson_recordings
        WHERE lesson_id = ?
        ORDER BY recorded_at DESC, id DESC
    ");
    $rec->execute([$lesson_id]);
    $gatewayUrl = '../api/recording_url.php?lesson_id=' . $lesson_id;
    foreach ($rec as $r) {
        $recordings[] = [
            'id'           => (int)$r['id'],
            'url'          => $gatewayUrl,
            'fallback_url' => $gatewayUrl,
            'size'         => (int)($r['file_size_bytes'] ?? 0),
            'duration'     => (int)($r['duration_seconds'] ?? 0),
            'recorded'     => $r['recorded_at'],
            'provider'     => $r['provider'] ?: 'LiveKit',
            'status'       => $r['recording_status'] ?: 'Ready',
            'source'       => 'db',
        ];
    }

    echo json_encode([
        'ok'         => true,
        'recordings' => $recordings,
    ]);
    exit;
}

// ---- Handle review submit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_review') {
        $assignment_id       = (int)($_POST['assignment_id'] ?? 0);
        $lesson_id           = (int)($_POST['lesson_id'] ?? 0);
        $rating_overall      = (int)($_POST['rating_overall'] ?? 0);
        $rating_pedagogy     = (int)($_POST['rating_pedagogy'] ?? 0);
        $rating_tech         = (int)($_POST['rating_tech'] ?? 0);
        $student_engagement  = (int)($_POST['student_engagement'] ?? 0);
        $tags                = trim($_POST['tags'] ?? '');
        $comments            = trim($_POST['comments'] ?? '');
        $needs_followup      = !empty($_POST['needs_followup']) ? 1 : 0;

        try {
            if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
                throw new RuntimeException('Invalid session token. Refresh the page and try again.');
            }
            if ($assignment_id <= 0 || $lesson_id <= 0) {
                throw new RuntimeException('Invalid assignment.');
            }

            foreach ([$rating_overall, $rating_pedagogy, $rating_tech, $student_engagement] as $r) {
                if ($r < 0 || $r > 5) {
                    throw new RuntimeException('Ratings must be between 0 and 5.');
                }
            }

            // Ensure assignment belongs to this QC (unless admin)
            $assignChk = $pdo->prepare("
                SELECT qc_user_id FROM quality_control_assignments
                WHERE id = ? AND lesson_id = ? LIMIT 1
            ");
            $assignChk->execute([$assignment_id, $lesson_id]);
            $owner = $assignChk->fetchColumn();
            if (!$owner) {
                throw new RuntimeException('Assignment not found.');
            }
            if ($user_role === 'QualityControl' && (int)$owner !== $user_id) {
                throw new RuntimeException('You are not assigned to this review.');
            }

            // Prepare columns (only those that exist)
            $reviewCols = [
                'overall_rating'     => $rating_overall,
                'comments_to_admin'  => $comments,
                'flag_for_admin'     => $needs_followup,
                'qc_user_id'         => $user_id,
                'lesson_id'          => $lesson_id,
                'assignment_id'      => $assignment_id,
            ];
            if ($col_rating_pedagogy)    $reviewCols['rating_pedagogy']    = $rating_pedagogy;
            if ($col_rating_tech)        $reviewCols['rating_tech']        = $rating_tech;
            if ($col_student_engagement) $reviewCols['student_engagement'] = $student_engagement;
            if ($col_tags)               $reviewCols['tags']               = $tags;
            if ($col_needs_followup)     $reviewCols['needs_followup']     = $needs_followup;

            // Upsert by assignment_id
            $existing = $pdo->prepare("SELECT id FROM quality_control_reviews WHERE assignment_id = ? LIMIT 1");
            $existing->execute([$assignment_id]);
            $review_id = (int)$existing->fetchColumn();

            if ($review_id > 0) {
                $set  = [];
                $vals = [];
                foreach ($reviewCols as $col => $val) {
                    $set[]  = "$col = ?";
                    $vals[] = $val;
                }
                $vals[] = $review_id;
                $sql = "UPDATE quality_control_reviews SET " . implode(', ', $set) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($vals);
            } else {
                $cols  = array_keys($reviewCols);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO quality_control_reviews (" . implode(',', $cols) . ", created_at) VALUES ($place, NOW())";
                $pdo->prepare($sql)->execute(array_values($reviewCols));
            }

            // Mark assignment status
            $pdo->prepare("UPDATE quality_control_assignments SET status = 'Completed' WHERE id = ?")->execute([$assignment_id]);

            add_success('Review saved. Thank you!');
        } catch (Throwable $e) {
            add_error($e->getMessage());
        }
    }
}

// ---- Filters (for QC user queue) ----
$status_filter = $_GET['status'] ?? 'Open'; // Open | Completed | All
$date_from     = $_GET['from'] ?? '';
$date_to       = $_GET['to']   ?? '';

try {
    $from = $date_from ? new DateTime($date_from . ' 00:00:00') : (new DateTime())->modify('-30 days')->setTime(0,0,0);
} catch (Exception $e) {
    $from = (new DateTime())->modify('-30 days')->setTime(0,0,0);
}
try {
    $to = $date_to ? new DateTime($date_to . ' 23:59:59') : (new DateTime())->setTime(23,59,59);
} catch (Exception $e) {
    $to = (new DateTime())->setTime(23,59,59);
}

$from_sql = $from->format('Y-m-d H:i:s');
$to_sql   = $to->format('Y-m-d H:i:s');

// ---- Fetch QC queue ----
$reviewSelect = "
    qcr.id                AS review_id,
    qcr.overall_rating    AS rating_overall,
    qcr.comments_to_admin AS comments,
    qcr.flag_for_admin    AS flag_for_admin,
    qcr.created_at        AS reviewed_at
";
if ($col_rating_pedagogy)    $reviewSelect .= ", qcr.rating_pedagogy";
if ($col_rating_tech)        $reviewSelect .= ", qcr.rating_tech";
if ($col_student_engagement) $reviewSelect .= ", qcr.student_engagement";
if ($col_tags)               $reviewSelect .= ", qcr.tags";
if ($col_needs_followup)     $reviewSelect .= ", qcr.needs_followup";

$where = [
    "COALESCE(qca.assigned_at, l.scheduled_start_time) BETWEEN :from AND :to",
];
$params = [
    ':from' => $from_sql,
    ':to'   => $to_sql,
];

if ($user_role === 'QualityControl') {
    $where[]        = "qca.qc_user_id = :qc";
    $params[':qc']  = $user_id;
}

if ($status_filter === 'Open') {
    $where[] = "qca.status IN ('Assigned','InReview','In Progress')";
} elseif ($status_filter === 'Completed') {
    $where[] = "qca.status = 'Completed'";
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
  SELECT
    qca.id           AS assignment_id,
    qca.lesson_id,
    qca.status,
    qca.assigned_at,
    $reviewSelect,

    l.scheduled_start_time,
    l.scheduled_end_time,
    subj.name        AS subject_name,
    tut.first_name   AS tutor_first,
    tut.last_name    AS tutor_last,
    stu.first_name   AS student_first,
    stu.last_name    AS student_last,

    lr.file_url      AS rec_url,
    lr.duration_seconds AS rec_duration,
    lr.file_size_bytes  AS rec_size,
    lr.recorded_at   AS rec_recorded_at
  FROM quality_control_assignments qca
  JOIN lessons l         ON l.id = qca.lesson_id
  JOIN subjects subj     ON subj.id = l.subject_id
  JOIN users tut         ON tut.id = l.tutor_id
  JOIN users stu         ON stu.id = l.student_id
  LEFT JOIN (
    SELECT x.*
    FROM lesson_recordings x
    JOIN (
      SELECT lesson_id, MAX(recorded_at) AS max_rec
      FROM lesson_recordings
      GROUP BY lesson_id
    ) latest
      ON latest.lesson_id = x.lesson_id
     AND latest.max_rec   = x.recorded_at
  ) lr
    ON lr.lesson_id = l.id
  LEFT JOIN quality_control_reviews qcr
    ON qcr.assignment_id = qca.id
  $where_sql
  ORDER BY qca.assigned_at DESC
  LIMIT 300
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- Simple stats for widgets ----
$stats_stmt = $pdo->prepare("
  SELECT
    SUM(qca.status IN ('Assigned','InReview','In Progress')) AS open_count,
    SUM(qca.status = 'Completed')                            AS done_count,
    COUNT(*)                                                 AS total_count
  FROM quality_control_assignments qca
  " . ($user_role === 'QualityControl' ? "WHERE qca.qc_user_id = :qc" : "")
);
if ($user_role === 'QualityControl') {
    $stats_stmt->execute([':qc' => $user_id]);
} else {
    $stats_stmt->execute();
}
$stats = $stats_stmt->fetch() ?: ['open_count'=>0,'done_count'=>0,'total_count'=>0];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>QC Dashboard</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, sans-serif; background-color:#111827; color:#e5e7eb; }
    ::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:#1f2937}::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .chip{font-size:12px;padding:3px 8px;border-radius:9999px;display:inline-flex;align-items:center}
  </style>
</head>
<body class="min-h-screen flex flex-col bg-gray-900">

  <!-- Simple top bar -->
  <header class="w-full border-b border-gray-800 bg-gray-900/95 backdrop-blur z-20">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="h-9 w-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-lg">QC</div>
        <div>
          <p class="text-sm text-gray-400">Quality Control</p>
          <h1 class="text-lg font-semibold text-white">Recording Review Dashboard</h1>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <div class="text-sm text-gray-300">
          Logged in as
          <span class="font-semibold">
            <?php
            $fn = $_SESSION['user_first_name'] ?? '';
            $ln = $_SESSION['user_last_name'] ?? '';
            echo h(trim("$fn $ln") ?: ('User #' . $user_id));
            ?>
          </span>
        </div>
        <a href="../logout.php"
           class="px-3 py-1.5 rounded-lg text-xs font-semibold border border-gray-700 bg-gray-800 hover:bg-gray-700 text-gray-100">
          Logout
        </a>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-7xl mx-auto w-full px-4 py-6 lg:py-8 space-y-6">

    <!-- Flash messages -->
    <?php if (!empty($flash_success) || !empty($flash_error)): ?>
      <div class="space-y-2">
        <?php foreach ($flash_success as $m): ?>
          <div class="bg-emerald-500/10 border border-emerald-500/40 text-emerald-300 px-4 py-2 rounded-lg text-sm"><?php echo h($m); ?></div>
        <?php endforeach; ?>
        <?php foreach ($flash_error as $m): ?>
          <div class="bg-rose-500/10 border border-rose-500/40 text-rose-300 px-4 py-2 rounded-lg text-sm"><?php echo h($m); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Stats cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="bg-gray-800 rounded-xl p-4">
        <p class="text-xs text-gray-400">Open assignments</p>
        <p class="mt-1 text-3xl font-semibold text-white"><?php echo (int)$stats['open_count']; ?></p>
      </div>
      <div class="bg-gray-800 rounded-xl p-4">
        <p class="text-xs text-gray-400">Completed reviews</p>
        <p class="mt-1 text-3xl font-semibold text-emerald-400"><?php echo (int)$stats['done_count']; ?></p>
      </div>
      <div class="bg-gray-800 rounded-xl p-4">
        <p class="text-xs text-gray-400">Total assigned</p>
        <p class="mt-1 text-3xl font-semibold text-indigo-400"><?php echo (int)$stats['total_count']; ?></p>
      </div>
    </section>

    <!-- Filters -->
    <section class="bg-gray-800 rounded-xl p-4">
      <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <div>
          <label class="block text-xs text-gray-300 mb-1">From</label>
          <input type="date" name="from" value="<?php echo h($from->format('Y-m-d')); ?>" class="w-full bg-gray-900 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-100">
        </div>
        <div>
          <label class="block text-xs text-gray-300 mb-1">To</label>
          <input type="date" name="to" value="<?php echo h($to->format('Y-m-d')); ?>" class="w-full bg-gray-900 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-100">
        </div>
        <div>
          <label class="block text-xs text-gray-300 mb-1">Status</label>
          <select name="status" class="w-full bg-gray-900 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-100">
            <option value="Open"      <?php echo $status_filter==='Open'?'selected':''; ?>>Open only</option>
            <option value="Completed" <?php echo $status_filter==='Completed'?'selected':''; ?>>Completed only</option>
            <option value="All"       <?php echo $status_filter==='All'?'selected':''; ?>>All</option>
          </select>
        </div>
        <div class="flex gap-2 justify-end md:col-span-1">
          <a href="dashboard.php" class="self-center text-xs text-gray-300 hover:text-white">Reset</a>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">Apply</button>
        </div>
      </form>
    </section>

    <!-- Main layout: left = queue list, right = review panel -->
    <section class="grid grid-cols-1 xl:grid-cols-5 gap-6">
      <!-- Queue -->
      <div class="xl:col-span-3 bg-gray-800 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-white">Assigned recordings</h2>
          <p class="text-xs text-gray-400">Click a row to start a review.</p>
        </div>
        <div class="overflow-x-auto max-h-[550px]">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-900 text-gray-300 sticky top-0 z-10">
              <tr>
                <th class="px-3 py-2 text-left">Date</th>
                <th class="px-3 py-2 text-left">Subject</th>
                <th class="px-3 py-2 text-left">Tutor</th>
                <th class="px-3 py-2 text-left">Student</th>
                <th class="px-3 py-2 text-left">Recording</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-right">Review</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="7" class="px-4 py-8 text-center text-gray-400">No assignments found for this range.</td>
                </tr>
              <?php else: foreach ($rows as $r):
                $start   = $r['scheduled_start_time'] ? new DateTime($r['scheduled_start_time']) : null;
                $has_rec = !empty($r['rec_url']); // recording exists; it is served via the gateway
                $rec_info = $has_rec
                  ? (fmt_dur((int)$r['rec_duration']) . ' • ' . fmt_size((int)$r['rec_size']))
                  : '—';

                $status = $r['status'] ?? 'Assigned';
                $status_class = 'chip bg-indigo-500/10 text-indigo-300';
                if ($status === 'Completed') $status_class = 'chip bg-emerald-500/10 text-emerald-300';
                elseif ($status === 'In Progress') $status_class = 'chip bg-amber-500/10 text-amber-300';

                $tutor_name   = trim(($r['tutor_first'] ?? '') . ' ' . ($r['tutor_last'] ?? ''));
                $student_name = trim(($r['student_first'] ?? '') . ' ' . ($r['student_last'] ?? ''));
                ?>
                <tr class="border-t border-gray-700 hover:bg-gray-900/60 cursor-pointer qc-row"
                    data-assignment-id="<?php echo (int)$r['assignment_id']; ?>"
                    data-review-id="<?php echo (int)$r['review_id']; ?>"
                    data-lesson-id="<?php echo (int)$r['lesson_id']; ?>"
                    data-subject="<?php echo h($r['subject_name']); ?>"
                    data-tutor="<?php echo h($tutor_name); ?>"
                    data-student="<?php echo h($student_name); ?>"
                    data-rec-info="<?php echo h($rec_info); ?>"
                    data-status="<?php echo h($status); ?>"
                    data-rating-overall="<?php echo (int)($r['rating_overall'] ?? 0); ?>"
                    data-rating-pedagogy="<?php echo (int)($r['rating_pedagogy'] ?? 0); ?>"
                    data-rating-tech="<?php echo (int)($r['rating_tech'] ?? 0); ?>"
                    data-student-engagement="<?php echo (int)($r['student_engagement'] ?? 0); ?>"
                    data-tags="<?php echo h($r['tags'] ?? ''); ?>"
                    data-comments="<?php echo h($r['comments'] ?? ''); ?>"
                    data-needs-followup="<?php echo (int)($r['needs_followup'] ?? $r['flag_for_admin'] ?? 0); ?>"
                >
                  <td class="px-3 py-2 align-top"><?php echo h($start ? $start->format('M j, Y g:i a') : '—'); ?></td>
                  <td class="px-3 py-2 align-top"><?php echo h($r['subject_name']); ?></td>
                  <td class="px-3 py-2 align-top"><?php echo h($tutor_name); ?></td>
                  <td class="px-3 py-2 align-top"><?php echo h($student_name); ?></td>
                  <td class="px-3 py-2 align-top">
                    <?php if ($has_rec): ?>
                      <div class="text-[11px] text-gray-300">
                        <div><?php echo h($rec_info); ?></div>
                        <span class="inline-flex items-center gap-1 text-emerald-300">
                          <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Recording ready
                        </span>
                      </div>
                    <?php else: ?>
                      <span class="text-gray-400">No recording</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-2 align-top">
                    <span class="<?php echo $status_class; ?>"><?php echo h($status); ?></span>
                  </td>
                  <td class="px-3 py-2 align-top text-right">
                    <button type="button"
                            class="inline-flex items-center text-[11px] font-semibold px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white qc-review-btn">
                      Review
                    </button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Review panel -->
      <div class="xl:col-span-2 bg-gray-800 rounded-xl p-4 flex flex-col gap-3" id="qcReviewPanel">
        <h2 class="text-sm font-semibold text-white flex items-center justify-between">
          <span>Review details</span>
          <span class="text-[11px] text-gray-400" id="qcStatusBadge">Select an assignment from the list</span>
        </h2>

        <!-- Meta -->
        <div class="bg-gray-900 rounded-lg p-3 text-xs space-y-1" id="qcMetaBlock" style="display:none;">
          <p><span class="text-gray-400">Subject:</span> <span id="qcMetaSubject" class="text-gray-100"></span></p>
          <p><span class="text-gray-400">Tutor:</span> <span id="qcMetaTutor" class="text-gray-100"></span></p>
          <p><span class="text-gray-400">Student:</span> <span id="qcMetaStudent" class="text-gray-100"></span></p>
          <p><span class="text-gray-400">Recording:</span> <span id="qcMetaRecInfo" class="text-gray-100"></span></p>
        </div>

        <!-- Video / iframe container with toolbar -->
        <div class="bg-black/40 rounded-lg border border-gray-700 overflow-hidden" id="qcVideoWrapper" style="display:none;">
          <div class="flex items-center justify-between px-3 py-2 bg-gray-900/80 border-b border-gray-800 text-xs">
            <span class="text-gray-300">Recording preview</span>
            <div class="flex items-center gap-2">
              <button type="button"
                      id="qcOpenTabBtn"
                      class="px-2.5 py-1 rounded-md bg-gray-800 hover:bg-gray-700 text-gray-100 border border-gray-700 text-[11px]">
                Open in new tab
              </button>
              <button type="button"
                      id="qcFullscreenBtn"
                      class="px-2.5 py-1 rounded-md bg-indigo-600 hover:bg-indigo-500 text-white text-[11px]">
                Fullscreen
              </button>
            </div>
          </div>
          <div class="relative w-full pt-[56.25%] bg-black">
            <div id="qcVideoContainer" class="absolute inset-0 w-full h-full"></div>
          </div>
        </div>

        <!-- Review form -->
        <form method="post" class="space-y-3 text-xs" id="qcForm" style="display:none;">
          <input type="hidden" name="action" value="save_review">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="assignment_id" id="qcAssignmentId">
          <input type="hidden" name="lesson_id" id="qcLessonId">

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-gray-300 mb-1">Overall rating (0–5)</label>
              <input type="number" min="0" max="5" name="rating_overall" id="rating_overall"
                     class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100">
            </div>
            <div>
              <label class="block text-gray-300 mb-1">Pedagogy (0–5)</label>
              <input type="number" min="0" max="5" name="rating_pedagogy" id="rating_pedagogy"
                     class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100">
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-gray-300 mb-1">Tech quality (0–5)</label>
              <input type="number" min="0" max="5" name="rating_tech" id="rating_tech"
                     class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100">
            </div>
            <div>
              <label class="block text-gray-300 mb-1">Student engagement (0–5)</label>
              <input type="number" min="0" max="5" name="student_engagement" id="student_engagement"
                     class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100">
            </div>
          </div>

          <div>
            <label class="block text-gray-300 mb-1">Tags (comma-separated)</label>
            <input type="text" name="tags" id="tags"
                   placeholder="e.g. strong-explanation, weak-tech, needs-followup"
                   class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100">
          </div>

          <div>
            <label class="block text-gray-300 mb-1">Comments / feedback</label>
            <textarea name="comments" id="comments" rows="4"
                      class="w-full bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-100"
                      placeholder="Summarise the strengths, weaknesses and any issues that need attention."></textarea>
          </div>

          <label class="inline-flex items-center gap-2 text-gray-300">
            <input type="checkbox" name="needs_followup" id="needs_followup"
                   class="h-4 w-4 rounded border-gray-600 bg-gray-900 text-amber-500 focus:ring-amber-500">
            <span>Flag for follow-up (admin / academic coordinator should review this lesson)</span>
          </label>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" id="qcClearBtn"
                    class="px-3 py-1.5 rounded-lg text-xs bg-gray-700 hover:bg-gray-600 text-gray-100">
              Clear selection
            </button>
            <button type="submit"
                    class="px-4 py-1.5 rounded-lg text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white">
              Save review
            </button>
          </div>
        </form>
      </div>
    </section>

  </main>

  <script>
    function $(sel, root=document){ return root.querySelector(sel); }
    function $all(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }

    const rows   = $all('.qc-row');
    const meta   = $('#qcMetaBlock');
    const statusBadge   = $('#qcStatusBadge');
    const videoWrapper  = $('#qcVideoWrapper');
    const videoContainer= $('#qcVideoContainer');
    const form   = $('#qcForm');

    const metaSubject = $('#qcMetaSubject');
    const metaTutor   = $('#qcMetaTutor');
    const metaStudent = $('#qcMetaStudent');
    const metaRecInfo = $('#qcMetaRecInfo');

    const assignmentInput = $('#qcAssignmentId');
    const lessonInput     = $('#qcLessonId');
    const ratingOverall   = $('#rating_overall');
    const ratingPed       = $('#rating_pedagogy');
    const ratingTech      = $('#rating_tech');
    const studentEng      = $('#student_engagement');
    const tagsInput       = $('#tags');
    const commentsInput   = $('#comments');
    const needsFollow     = $('#needs_followup');
    const clearBtn        = $('#qcClearBtn');

    const openTabBtn      = $('#qcOpenTabBtn');
    const fullscreenBtn   = $('#qcFullscreenBtn');

    let currentRecordingUrl = '';

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

    async function loadRecordingsForLesson(lessonId){
      currentRecordingUrl = '';
      if (!lessonId) {
        videoWrapper.style.display = 'none';
        videoContainer.innerHTML   = '';
        metaRecInfo.textContent    = 'No recording selected';
        return;
      }

      metaRecInfo.textContent = 'Loading recording…';
      videoWrapper.style.display = 'none';
      videoContainer.innerHTML   = '';

      try {
        const resp = await fetch(`dashboard.php?ajax=recordings&lesson_id=${encodeURIComponent(lessonId)}`, {
          credentials: 'same-origin'
        });
        const data = await resp.json();
        if (!data || !data.ok || !Array.isArray(data.recordings) || !data.recordings.length) {
          metaRecInfo.textContent = 'No recordings found for this lesson.';
          return;
        }

        // Use the first (newest) recording. Every recording is served through the
        // gateway URL, which 302-redirects to a short-lived Cloudflare R2 link.
        const r = data.recordings[0];
        const durText  = r.duration ? humanDur(r.duration) : '—';
        const sizeText = r.size ? humanSize(r.size) : '—';

        metaRecInfo.textContent = `${durText} • ${sizeText} • ${r.provider || 'Stored'}`;

        const url = r.url || r.fallback_url;
        if (!url) {
          videoWrapper.style.display = 'none';
          videoContainer.innerHTML   = '';
          return;
        }

        currentRecordingUrl = url;

        // Recordings are webm video; the browser follows the gateway redirect.
        videoContainer.innerHTML = `
          <video src="${url}" controls preload="metadata"
                 class="w-full h-full rounded-b-lg bg-black"></video>
        `;

        videoWrapper.style.display = 'block';
      } catch (e) {
        console.error(e);
        metaRecInfo.textContent = 'Error loading recording.';
        videoWrapper.style.display = 'none';
        videoContainer.innerHTML   = '';
        currentRecordingUrl        = '';
      }
    }

    function populateFromRow(tr){
      const subject  = tr.dataset.subject || '';
      const tutor    = tr.dataset.tutor || '';
      const student  = tr.dataset.student || '';
      const status   = tr.dataset.status || 'Assigned';
      const recInfo  = tr.dataset.recInfo || '—';

      assignmentInput.value      = tr.dataset.assignmentId || '';
      lessonInput.value          = tr.dataset.lessonId || '';
      ratingOverall.value        = tr.dataset.ratingOverall || '';
      ratingPed.value            = tr.dataset.ratingPedagogy || '';
      ratingTech.value           = tr.dataset.ratingTech || '';
      studentEng.value           = tr.dataset.studentEngagement || '';
      tagsInput.value            = tr.dataset.tags || '';
      commentsInput.value        = tr.dataset.comments || '';
      needsFollow.checked        = (tr.dataset.needsFollowup === '1');

      metaSubject.textContent    = subject;
      metaTutor.textContent      = tutor;
      metaStudent.textContent    = student;
      metaRecInfo.textContent    = recInfo;

      statusBadge.textContent    = 'Status: ' + status;
      meta.style.display         = 'block';
      form.style.display         = 'block';

      const lessonId = tr.dataset.lessonId || '';
      loadRecordingsForLesson(lessonId);

      // Mark as "In Progress" visually (client-side only)
      tr.dataset.status = 'In Progress';
    }

    rows.forEach(tr => {
      tr.addEventListener('click', () => populateFromRow(tr));
      const btn = tr.querySelector('.qc-review-btn');
      if (btn) btn.addEventListener('click', (e) => {
        e.stopPropagation();
        populateFromRow(tr);
      });
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        statusBadge.textContent = 'Select an assignment from the list';
        meta.style.display      = 'none';
        form.style.display      = 'none';
        videoWrapper.style.display = 'none';
        videoContainer.innerHTML   = '';
        metaRecInfo.textContent    = '';
        assignmentInput.value = '';
        lessonInput.value     = '';
        currentRecordingUrl   = '';
      });
    }

    if (openTabBtn) {
      openTabBtn.addEventListener('click', () => {
        if (!currentRecordingUrl) return;
        window.open(currentRecordingUrl, '_blank', 'noopener');
      });
    }

    if (fullscreenBtn) {
      fullscreenBtn.addEventListener('click', () => {
        if (!videoWrapper) return;
        if (videoWrapper.requestFullscreen) {
          videoWrapper.requestFullscreen();
        } else if (videoWrapper.webkitRequestFullscreen) {
          videoWrapper.webkitRequestFullscreen();
        } else if (videoWrapper.msRequestFullscreen) {
          videoWrapper.msRequestFullscreen();
        }
      });
    }
  </script>
</body>
</html>
