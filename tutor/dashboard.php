<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// 1. AUTH (Tutor only)
require_once __DIR__ . '/auth_tutor_only.php';

// 3. CURRENT PAGE
$current_page = 'dashboard';

// =================================================================
// DATABASE CONNECTION & DATA FETCHING
// =================================================================
$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass=DB_PASSWORD;


// Load timezone helper for proper UTC handling
require_once __DIR__ . '/../utils/timezone_helper.php';
require_once __DIR__ . '/../utils/lesson_sweep.php';

// User's timezone from session (loaded during login)
$userTimezone = getUserTimezone();
$tutor_timezone = $userTimezone; // Alias for consistency in tutor dashboard

// Set PHP timezone for this script (for backward compatibility with date() calls)
date_default_timezone_set($userTimezone);

$pdo = getDbConnection();

// Ensure payout settings table exists (used for currency/rate)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tutor_payout_settings (
        tutor_id INT NOT NULL,
        hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'USD',
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tutor_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4  ;
");


// Safe HTML
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Builds a full URL to /uploads/* from wherever this file sits.
 * /tutsys2/tutor/*  -> /tutsys2/uploads/*
 */
function uploads_url(string $relPath): string {
  $relPath = ltrim($relPath, '/'); // e.g. banners/banner2.jpg
  // If already absolute (http/https) just return it
  if (preg_match('~^https?://~i', $relPath)) return $relPath;

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  // Go up one level from /tutor to the app root, then /uploads
  $appRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/'); // e.g. /tutsys2
  return "{$scheme}://{$host}{$appRoot}/uploads/{$relPath}";
}





// ===== Banner for Tutor Dashboard =====
// ===== Banner for Tutor Dashboard (match students dashboard shape) =====
$banner = null;
try {
  $st = $pdo->prepare("
    SELECT id, page_key, audience, title, message, image_url, alt_text, cta_label, cta_url, is_active
    FROM ui_banners
    WHERE is_active = 1
      AND page_key IN ('tutors_dashboard', 'all_dashboards', 'students_dashboard', 'teachers_dashboard')
      AND audience IN ('All','Tutors')
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    ORDER BY 
      (page_key = 'tutors_dashboard') DESC,
      updated_at DESC
    LIMIT 1
  ");
  $st->execute();
  $banner = $st->fetch() ?: null;
} catch (Throwable $e) {
  // error_log($e->getMessage());
}

// Normalize to the same names you used on the student dashboard
$banner_enabled = !empty($banner) && trim((string)$banner['title']) !== '';
$banner_img     = uploads_url($banner['image_url'] ?? '');
$banner_alt     = $banner['alt_text'] ?? '';
$banner_title   = $banner['title'] ?? '';
$banner_text    = $banner['message'] ?? '';
$banner_cta     = $banner['cta_label'] ?? '';
$banner_href    = $banner['cta_url'] ?? '';

// Hide if totally empty
if (!$banner_img && !$banner_text && !$banner_cta) {
  $banner_enabled = false;
}


// Currency map
$currency_symbols = [
    'USD'=>'$', 'EUR'=>'€', 'GBP'=>'£', 'MWK'=>'MK', 'ZAR'=>'R', 'KES'=>'KSh', 'TZS'=>'TSh'
];

// Load rate & currency
$stmt = $pdo->prepare("SELECT hourly_rate, currency FROM tutor_payout_settings WHERE tutor_id = ?");
$stmt->execute([$tutor_id]);
$set = $stmt->fetch() ?: ['hourly_rate'=>0.00,'currency'=>'USD'];
$hourly_rate = (float)$set['hourly_rate'];
$currency    = $set['currency'];
$C           = $currency_symbols[$currency] ?? ($currency.' ');

// Load tutor default meeting link (fallback when a lesson has no custom link)
$stmt = $pdo->prepare("SELECT google_meet_link FROM tutor_details WHERE user_id = ?");
$stmt->execute([$tutor_id]);
$tutorDefaultLink = trim((string)($stmt->fetchColumn() ?: '')) ?: null;

// --- Fetch Data for the Dashboard ---

// Get The Single Most Upcoming Lesson (include ID)
// Get all upcoming lessons (including in-progress)
// Close off lessons nobody recorded an outcome for. The rules, and the reasons
// behind them, are in utils/lesson_sweep.php; the student dashboard runs the same
// two calls so the pages cannot drift apart.
sweep_absent_students($pdo, $tutor_id, null);
sweep_absent_tutors($pdo, $tutor_id, null);

// Get current/upcoming lesson: InProgress lessons or Scheduled lessons that haven't ended
$stmt = $pdo->prepare("
    SELECT l.id, l.meeting_url, l.scheduled_start_time, l.scheduled_end_time, l.status,
           s.name AS subject_name, u.first_name, u.last_name, u.profile_photo_url
    FROM lessons l
    JOIN subjects s ON l.subject_id = s.id
    LEFT JOIN users u ON l.student_id = u.id
    WHERE l.tutor_id = :tutor_id
      AND (
          (l.status = 'InProgress' AND l.scheduled_end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
          OR (l.status = 'Scheduled' AND l.scheduled_end_time > NOW())
      )
    ORDER BY 
        CASE WHEN l.status = 'InProgress' THEN 0 ELSE 1 END,
        l.scheduled_start_time ASC
    LIMIT 1
");

$stmt->execute([':tutor_id' => $tutor_id]);
$upcomingLesson = $stmt->fetch();

// Lessons that ended with the student present but no outcome recorded. These are
// deliberately not auto-resolved: only the tutor can say what happened, and the
// outcome drives their pay. Left alone they simply never close.
$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.status,
           s.name AS subject_name, u.first_name, u.last_name, u.profile_photo_url
    FROM lessons l
    JOIN subjects s ON l.subject_id = s.id
    LEFT JOIN users u ON l.student_id = u.id
    WHERE l.tutor_id = ?
      AND l.status IN ('Scheduled', 'InProgress')
      AND l.scheduled_end_time < NOW()
      AND l.student_join_time IS NOT NULL
    ORDER BY l.scheduled_start_time DESC
    LIMIT 5
");
$stmt->execute([$tutor_id]);
$unfinishedLessons = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM lessons
    WHERE tutor_id = ?
      AND status IN ('Scheduled', 'InProgress')
      AND scheduled_end_time < NOW()
      AND student_join_time IS NOT NULL
");
$stmt->execute([$tutor_id]);
$unfinishedCount = (int) $stmt->fetchColumn();

// Get additional next lessons (for the next lessons section, excluding current)
$excludeId = $upcomingLesson ? $upcomingLesson['id'] : 0;
$nextLessons = [];
if ($upcomingLesson) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.meeting_url, l.scheduled_start_time, l.scheduled_end_time, l.tutor_notes,
               s.name AS subject_name, u.first_name, u.last_name, u.profile_photo_url, l.status
        FROM lessons l
        JOIN subjects s ON l.subject_id = s.id
        LEFT JOIN users u ON l.student_id = u.id
        WHERE l.tutor_id = ?
          AND l.id != ?
          AND (
              (l.status = 'InProgress' AND l.scheduled_end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
              OR (l.status = 'Scheduled' AND l.scheduled_end_time > NOW())
          )
        ORDER BY l.scheduled_start_time ASC
        LIMIT 3
    ");
    $stmt->execute([$tutor_id, $excludeId]);
    $nextLessons = $stmt->fetchAll();
}

$now = new DateTime(); // Uses local timezone set above

// Previous Lessons
$stmt = $pdo->prepare("
SELECT l.scheduled_start_time, l.status, s.name AS subject_name, 
       u.first_name, u.last_name, u.profile_photo_url
FROM lessons l
JOIN subjects s ON l.subject_id = s.id
LEFT JOIN users u ON l.student_id = u.id
WHERE l.tutor_id = ? AND l.scheduled_start_time < NOW()
ORDER BY l.scheduled_start_time DESC
LIMIT 3
");
$stmt->execute([$tutor_id]);
$previousLessons = $stmt->fetchAll();

// Homework Submissions needing grading
$stmt = $pdo->prepare("
SELECT hs.id as submission_id, h.id as homework_id, h.title as homework_title, u.first_name, u.last_name, u.profile_photo_url
FROM homework_submissions hs
JOIN homework h ON hs.homework_id = h.id
JOIN users u ON hs.student_id = u.id
WHERE h.tutor_id = ? AND hs.status IN ('Submitted', 'Late')
ORDER BY hs.submission_date DESC
LIMIT 3
");
$stmt->execute([$tutor_id]);
$homeworkSubmissions = $stmt->fetchAll();

// Notifications (unread)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$tutor_id]);
$notification_unread_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$tutor_id]);
$notifications = $stmt->fetchAll();

// ==============================
// EARNINGS (real, from lessons)
// ==============================
// Helpers for date ranges
$weekStart  = (new DateTime('monday this week'))->setTime(0,0,0);
$weekEnd    = (clone $weekStart)->modify('+7 days');
$monthStart = (new DateTime('first day of this month'))->setTime(0,0,0);

require_once __DIR__ . '/../utils/tutor_earnings.php';



// This Week / Month / Total
$earn_week  = earningsSum($pdo, $tutor_id, $hourly_rate, $weekStart, $weekEnd);
$earn_month = earningsSum($pdo, $tutor_id, $hourly_rate, $monthStart, null);
$earn_total = earningsSum($pdo, $tutor_id, $hourly_rate, null, null);

$paidHoursSql = tutor_paid_hours_sql();
// Weekly chart: group by day this week
// Weekly chart: group by day this week (same payout rules as above)
$stmt = $pdo->prepare("
  SELECT DATE(l.scheduled_start_time) d,
         COALESCE(SUM(({$paidHoursSql}) * ?), 0) AS total
  FROM lessons l
  WHERE l.tutor_id = ?
    AND l.scheduled_start_time >= ?
    AND l.scheduled_start_time <  ?
  GROUP BY d
");
$stmt->execute([
  $hourly_rate,
  $tutor_id,
  $weekStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s')
]);
$byDay = [];
while ($r = $stmt->fetch()) { $byDay[$r['d']] = (float)$r['total']; }


// Build Mon..Sun labels + data
$labels = [];
$dataPts = [];
$cursor = clone $weekStart;
for ($i=0;$i<7;$i++) {
    $labels[] = $cursor->format('D');
    $key = $cursor->format('Y-m-d');
    $dataPts[] = round($byDay[$key] ?? 0.0, 2);
    $cursor->modify('+1 day');
}

// ====================================
// TUTOR SCORE (composite) - last 90d
// ====================================
$winStart = (new DateTime('-90 days'))->setTime(0,0,0);

// Student Ratings (0–100)
$stmt = $pdo->prepare("
  SELECT AVG(r.rating) FROM lesson_ratings r
  JOIN lessons l ON l.id = r.lesson_id
  WHERE l.tutor_id = ? AND r.created_at >= ?
");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$avg_rating = (float)$stmt->fetchColumn();
$score_ratings = is_null($avg_rating) || $avg_rating <= 0 ? 50.0 : min(100.0, max(0.0, ($avg_rating/5.0)*100.0));

// Homework Performance (avg grade %, 0–100)
$stmt = $pdo->prepare("
  SELECT AVG(hs.grade) FROM homework_submissions hs
  JOIN homework h ON h.id = hs.homework_id
  WHERE h.tutor_id = ? AND hs.status='Graded' AND (hs.graded_at IS NOT NULL) AND hs.graded_at >= ?
");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$avg_grade = $stmt->fetchColumn();
$score_hw_perf = is_null($avg_grade) ? 50.0 : max(0.0, min(100.0, (float)$avg_grade));

// Homework Given Ratio (% lessons with HW)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE tutor_id=? AND scheduled_start_time>=?");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$lessons_90 = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM homework WHERE tutor_id=? AND created_at>=?");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$hw_90 = (int)$stmt->fetchColumn();

$score_hw_ratio = $lessons_90>0 ? min(100.0, ($hw_90/$lessons_90)*100.0) : 50.0;

// Punctuality (% tutor joined ≤5 min late on Completed)
$stmt = $pdo->prepare("
  SELECT 100*SUM(CASE WHEN l.tutor_join_time IS NOT NULL 
                      AND TIMESTAMPDIFF(MINUTE, l.scheduled_start_time, l.tutor_join_time) <= 5 
                 THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)
  FROM lessons l
  WHERE l.tutor_id=? AND l.status='Completed' AND l.scheduled_start_time>=?
");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$score_punct = (float)($stmt->fetchColumn() ?? 50.0);
if (!$score_punct && $score_punct!==0.0) $score_punct = 50.0;

// Topic Coverage Efficiency (% completed lessons with ≥1 topic)
$stmt = $pdo->prepare("
  SELECT 100*SUM(CASE WHEN EXISTS (SELECT 1 FROM lesson_topics_covered tc WHERE tc.lesson_id=l.id)
                 THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)
  FROM lessons l
  WHERE l.tutor_id=? AND l.status='Completed' AND l.scheduled_start_time>=?
");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$score_topics = (float)($stmt->fetchColumn() ?? 50.0);
if (!$score_topics && $score_topics!==0.0) $score_topics = 50.0;

// Engagement (attendance: student joined on Completed/NoShow)
$stmt = $pdo->prepare("
  SELECT 100*SUM(CASE WHEN l.student_join_time IS NOT NULL THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)
  FROM lessons l
  WHERE l.tutor_id=? AND l.scheduled_start_time>=? AND l.status IN ('Completed','NoShow')
");
$stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
$score_engage = (float)($stmt->fetchColumn() ?? 50.0);
if (!$score_engage && $score_engage!==0.0) $score_engage = 50.0;

// Retention Rate (use tutor_metrics if present)
$stmt = $pdo->prepare("SELECT student_retention_rate, homework_feedback_avg_hours FROM tutor_metrics WHERE tutor_id=?");
$stmt->execute([$tutor_id]);
$tm = $stmt->fetch() ?: ['student_retention_rate'=>null, 'homework_feedback_avg_hours'=>null];

if (!is_null($tm['student_retention_rate'])) {
    $score_retention = max(0.0, min(100.0, (float)$tm['student_retention_rate']));
} else {
    // Fallback: % of students with >=2 lessons (last 90d)
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM (
        SELECT student_id, COUNT(*) c
        FROM lessons
        WHERE tutor_id=? AND scheduled_start_time>=?
        GROUP BY student_id
      ) t WHERE t.c >= 2
    ");
    $stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
    $multi = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT student_id) FROM lessons
      WHERE tutor_id=? AND scheduled_start_time>=?
    ");
    $stmt->execute([$tutor_id, $winStart->format('Y-m-d H:i:s')]);
    $uniq = (int)$stmt->fetchColumn();

    $score_retention = $uniq>0 ? ($multi/$uniq)*100.0 : 50.0;
}

// Feedback Responsiveness (lower hours → higher score)
$avg_resp_hours = is_null($tm['homework_feedback_avg_hours']) ? null : (float)$tm['homework_feedback_avg_hours'];
$score_feedback = is_null($avg_resp_hours) ? 50.0 : max(0.0, min(100.0, (48.0 - $avg_resp_hours)/48.0 * 100.0));

// Weights
$W = [
  'ratings'   => 22,
  'hw_perf'   => 18,
  'hw_ratio'  => 10,
  'punct'     => 10,
  'topics'    => 13,
  'engage'    => 10,
  'retention' => 10,
  'feedback'  => 7,
];

// Composite
$parts = [
  'Student Ratings'          => $score_ratings * $W['ratings']/100.0,
  'Homework Performance'     => $score_hw_perf * $W['hw_perf']/100.0,
  'Homework Given Ratio'     => $score_hw_ratio * $W['hw_ratio']/100.0,
  'Punctuality'              => $score_punct * $W['punct']/100.0,
  'Topic Coverage Efficiency'=> $score_topics * $W['topics']/100.0,
  'Engagement'               => $score_engage * $W['engage']/100.0,
  'Retention Rate'           => $score_retention * $W['retention']/100.0,
  'Feedback Responsiveness'  => $score_feedback * $W['feedback']/100.0,
];
$composite = 0.0; foreach ($parts as $v) { $composite += $v; }

// Colors for donut segments
$donutColors = [
  'rgba(99,102,241,0.9)',   // indigo
  'rgba(16,185,129,0.9)',   // emerald
  'rgba(59,130,246,0.9)',   // blue
  'rgba(234,179,8,0.9)',    // amber
  'rgba(244,114,182,0.9)',  // pink
  'rgba(139,92,246,0.9)',   // violet
  'rgba(251,146,60,0.9)',   // orange
  'rgba(45,212,191,0.9)',   // teal
];


// Build Mon..Sun labels + data
$weekLabels = [];
$weekDataPts = [];
$cursor = clone $weekStart;
for ($i=0;$i<7;$i++) {
    $weekLabels[] = $cursor->format('D');          // e.g., Mon, Tue...
    $key = $cursor->format('Y-m-d');
    $weekDataPts[] = round($byDay[$key] ?? 0.0, 2);
    $cursor->modify('+1 day');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tutor Dashboard</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
            integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        .sidebar-icon { stroke-width: 1.5; }

        /* ---- Full-page load skeleton (critical, inline: shows before Tailwind/Chart.js load) ---- */
        #page-skeleton {
            position: fixed; inset: 0; z-index: 100000;
            background: #171717;                 /* dark default */
            padding: 20px; overflow: hidden;
            display: flex; gap: 20px;
        }
        html[data-theme="light"] #page-skeleton { background: #f7f7fb; }
        #page-skeleton.hide { opacity: 0; visibility: hidden; transition: opacity .35s ease, visibility .35s ease; }
        #page-skeleton .sk { background: rgba(148,163,184,0.14); border-radius: 12px; position: relative; overflow: hidden; }
        #page-skeleton .sk::after {
            content: ''; position: absolute; inset: 0; transform: translateX(-100%);
            background: linear-gradient(90deg, transparent 0, rgba(148,163,184,0.22) 50%, transparent 100%);
            animation: skeleton-shimmer 1.3s infinite;
        }
        #page-skeleton .ps-side { width: 240px; flex-shrink: 0; display: none; }
        @media (min-width: 1024px){ #page-skeleton .ps-side { display: block; } }
        #page-skeleton .ps-main { flex: 1; display: flex; flex-direction: column; gap: 18px; }
        #page-skeleton .ps-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        @media (max-width: 767px){ #page-skeleton .ps-row { grid-template-columns: 1fr; } }

        /* ---- Skeleton loading shimmer (theme-aware) ---- */
        .skeleton {
            position: relative;
            overflow: hidden;
            background: rgba(148,163,184,0.12);
            border-radius: 0.5rem;
        }
        .skeleton::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent 0, rgba(148,163,184,0.18) 50%, transparent 100%);
            animation: skeleton-shimmer 1.3s infinite;
        }
        @keyframes skeleton-shimmer { 100% { transform: translateX(100%); } }

        /* ---- Button loading spinner ---- */
        .btn-spinner {
            display: inline-block;
            width: 1rem; height: 1rem;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 9999px;
            animation: btn-spin 0.6s linear infinite;
            vertical-align: -2px;
        }
        @keyframes btn-spin { to { transform: rotate(360deg); } }
        button[data-loading="1"], a[data-loading="1"] { opacity: 0.75; pointer-events: none; }

        /* subtle field flash when a live value updates */
        .live-updated { animation: live-flash 0.9s ease-out; }
        @keyframes live-flash { 0% { background-color: rgba(99,102,241,0.18); } 100% { background-color: transparent; } }

        /* ---- Light-mode colour remap for this dark-first page ----
           Scoped to <main> + the lesson modal so the sidebar/header aren't touched.
           We remap every dark utility, then re-assert the promotional hero
           (data-keep-dark) and coloured buttons so they stay as designed. ---- */
        html[data-theme="light"] main .bg-gray-900,
        html[data-theme="light"] #lessonDetailModal .bg-gray-900 { background-color:#ffffff !important; }
        html[data-theme="light"] main .bg-gray-800,
        html[data-theme="light"] #lessonDetailModal .bg-gray-800 { background-color:#f1f5f9 !important; }
        html[data-theme="light"] main .bg-gray-700 { background-color:#ffffff !important; }
        html[data-theme="light"] main .bg-gray-600 { background-color:#e2e8f0 !important; }
        html[data-theme="light"] main .border-gray-700,
        html[data-theme="light"] main .border-gray-800 { border-color:#e5e7eb !important; }

        html[data-theme="light"] main .text-white,
        html[data-theme="light"] #lessonDetailModal .text-white { color:#111827 !important; }
        html[data-theme="light"] main .text-gray-300,
        html[data-theme="light"] #lessonDetailModal .text-gray-300 { color:#374151 !important; }
        html[data-theme="light"] main .text-gray-400,
        html[data-theme="light"] #lessonDetailModal .text-gray-400 { color:#6b7280 !important; }

        /* re-assert the dark promotional hero (attr+class specificity beats the above) */
        html[data-theme="light"] main [data-keep-dark].bg-gray-900 { background-color:#0f172a !important; }
        html[data-theme="light"] main [data-keep-dark].border-gray-800 { border-color:#1f2937 !important; }
        html[data-theme="light"] main [data-keep-dark] .text-white,
        html[data-theme="light"] main [data-keep-dark] .text-gray-100 { color:#ffffff !important; }

        /* keep white text on coloured buttons */
        html[data-theme="light"] main .bg-indigo-600.text-white,
        html[data-theme="light"] main .bg-indigo-500.text-white,
        html[data-theme="light"] main .bg-emerald-600.text-white,
        html[data-theme="light"] main .bg-emerald-500.text-white,
        html[data-theme="light"] #lessonDetailModal .bg-indigo-600.text-white { color:#ffffff !important; }
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: 9999px;
            background: #f87171;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 2px rgba(31,41,55,0.8);
        }
    </style>
</head>
<body class="flex min-h-screen">
    <!-- Page-load skeleton: shown instantly (critical inline CSS) while the tab loads
         Tailwind/Chart.js/fonts/charts, then removed on window 'load'. -->
    <div id="page-skeleton" aria-hidden="true">
        <div class="ps-side">
            <div class="sk" style="height:100%"></div>
        </div>
        <div class="ps-main">
            <div class="sk" style="height:56px"></div>
            <div class="sk" style="height:200px"></div>
            <div class="ps-row">
                <div class="sk" style="height:120px"></div>
                <div class="sk" style="height:120px"></div>
                <div class="sk" style="height:120px"></div>
            </div>
            <div class="ps-row" style="grid-template-columns: 2fr 1fr;">
                <div class="sk" style="height:260px"></div>
                <div class="sk" style="height:260px"></div>
            </div>
        </div>
    </div>
    <script>
        // Remove the skeleton once the page is fully loaded (with a safety timeout).
        (function(){
            var sk = document.getElementById('page-skeleton');
            if (!sk) return;
            function done(){ sk.classList.add('hide'); setTimeout(function(){ sk.remove(); }, 400); }
            if (document.readyState === 'complete') { done(); }
            else { window.addEventListener('load', done); setTimeout(done, 4000); }
        })();
    </script>
    <?php
    // Include centralized page loader component (if not already included by sidebar)
    if (!defined('PAGE_LOADER_INCLUDED')) {
      define('PAGE_LOADER_INCLUDED', true);
      include __DIR__ . '/../components/page_loader.php';
    }
    ?>

    <!-- Sidebar -->
    <?php include 'components/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 lg:p-8">
      <?php 
          $header_config = [
              'show_greeting' => true,
              'greeting_text' => 'Welcome back',
              'greeting_name' => $tutor_name . '!',
              'date_text' => date('l, F j'),
              'next_lesson_text' => '...' // Optional: calculate next lesson time if desired
          ];
          include 'components/header.php';
      ?>

      <?php
      function humanTimeUntil(DateTime $start, ?DateTime $end = null): string {
          $now = new DateTime();

          // Already started?
          if ($now >= $start) {
              // Still in progress?
              if ($end && $now < $end) {
                  $rem = $now->diff($end);
                  $h = $rem->d * 24 + $rem->h;
                  $m = $rem->i;
                  if ($h === 0 && $m <= 1) return "Live • ending in <1m";
                  return "Live • ends in " . trim(($h ? "{$h}h " : "") . ($m ? "{$m}m" : ""));
              }
              // Started but no longer in progress
              $ago = $start->diff($now);
              $h = $ago->d * 24 + $ago->h;
              $m = $ago->i;
              if ($h === 0) return "Ended {$m}m ago";
              return "Ended {$h}h " . ($m ? "{$m}m " : "") . "ago";
          }

          // In the future
          $diff = $now->diff($start);
          $totalMin = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;

          if ($totalMin < 1) return "Starting now";
          if ($totalMin < 60) return "In {$diff->i}m";
          if ($totalMin < 180) return "In {$diff->h}h " . ($diff->i ? "{$diff->i}m" : "");

          // Same day
          if ($diff->days === 0) return "Today at " . $start->format('g:i A');
          // Tomorrow
          if ($diff->days === 1) return "Tomorrow at " . $start->format('g:i A');
          // Within a week
          if ($diff->days < 7) return $start->format('D \a\t g:i A');
          // Otherwise, full date
          return $start->format('M j \a\t g:i A');
      }

      /**
       * Get lesson card state based on time windows
       * State A: >15 mins before start → "Get Lesson Details" (can't join)
       * State B: 15 mins before to end → "Join Lesson"
       * State C: InProgress → "Return to Lesson"
       * 
       * @param DateTime $start Start time (in user's local timezone)
       * @param DateTime $end End time (in user's local timezone)
       * @param string $status Current lesson status
       * @param DateTime|null $now Current time (defaults to now)
       * @return array ['state' => 'A'|'B'|'C', 'button_text' => string, 'can_join' => bool, 'button_href' => string]
       */
      function get_lesson_card_state(DateTime $start, DateTime $end, string $status, ?DateTime $now = null): array {
          $now = $now ?: new DateTime();
          $minutes_until_start = (int)(($start->getTimestamp() - $now->getTimestamp()) / 60);
          
          // State C: Lesson is InProgress
          if ($status === 'InProgress') {
              return [
                  'state' => 'C',
                  'button_text' => 'Return to Lesson',
                  'can_join' => true,
                  'button_href' => 'join_lesson_live.php?lesson_id=',
                  'button_class' => 'bg-emerald-600 hover:bg-emerald-500'
              ];
          }
          
          // State B: Within 15 minutes before start OR lesson has started but not ended
          if ($minutes_until_start <= 15 && $now < $end) {
              return [
                  'state' => 'B',
                  'button_text' => 'Join Lesson',
                  'can_join' => true,
                  'button_href' => 'join_lesson_live.php?lesson_id=',
                  'button_class' => 'bg-indigo-600 hover:bg-indigo-500'
              ];
          }
          
          // State A: More than 15 minutes before start
          return [
              'state' => 'A',
              'button_text' => 'Get Lesson Details',
              'can_join' => false,
              'button_href' => '#',
              'button_class' => 'bg-gray-600 hover:bg-gray-500 cursor-not-allowed'
          ];
      }

      function getMeetingUrl(array $meetingData): string {
    $meetingUrl = $meetingData['meeting_url'] ?? '';
    
    // Any https meeting link the admin saved. This used to accept zoom.us and
    // nothing else, which silently dropped the Google Meet links most tutors have.
    if (preg_match('~^https://~i', $meetingUrl)) {
        return $meetingUrl;
    }
    
    // Anything that is not an https link is not something to hand a browser.
    return '';
}

function addRoleToUrl(string $url, string $role): string {
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $sep . 'role=' . rawurlencode($role);
}      ?>
      <?php if ($banner_enabled): ?>
        <section class="mb-8">
          <div data-keep-dark class="relative overflow-hidden rounded-2xl bg-gray-900 border border-gray-800 ring-1 ring-white/5">
            <?php if (!empty($banner_img)): ?>
              <img src="<?= h($banner_img) ?>" alt="<?= h($banner_alt ?: ($banner_title ?: 'Banner')) ?>" class="absolute inset-0 w-full h-full object-cover opacity-1 pointer-events-none select-none" loading="lazy">
              <div class="absolute inset-0 bg-gradient-to-r from-gray-900/60 via-gray-900/40 to-gray-900/20"></div>
              <div class="absolute inset-0 shadow-[inset_0_-80px_120px_-40px_rgba(17,24,39,0.2)]"></div>
            <?php endif; ?>
            <div class="relative px-8 py-12 md:px-12 md:py-16 min-h-[240px] flex items-center">
              <div class="max-w-3xl">
                <?php if (!empty($banner_title)): ?>
                  <h3 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight"><?= h($banner_title) ?></h3>
                <?php endif; ?>
                <?php if (!empty($banner_text)): ?>
                  <p class="text-base md:text-lg text-gray-100/90 mt-2"><?= h($banner_text) ?></p>
                <?php endif; ?>
                <?php if (!empty($banner_href) && !empty($banner_cta)): ?>
                  <a href="<?= h($banner_href) ?>"
                     class="mt-6 inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-400/60 transition">
                    <?= h($banner_cta) ?>
                    <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>
      <?php endif; ?>


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- left side -->
            <div class="lg:col-span-2 space-y-4">

                <?php if (!empty($unfinishedLessons)): ?>
                <!-- Lessons that ended with the student present and still have no outcome -->
                <div class="card-base border border-amber-500/30 bg-amber-500/5">
                    <div class="flex items-start gap-3 mb-3">
                        <svg class="w-5 h-5 mt-0.5 shrink-0 text-amber-300" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>
                        <div>
                            <h3 class="text-lg font-semibold text-primary">
                                <?= $unfinishedCount === 1 ? '1 lesson needs finishing' : $unfinishedCount . ' lessons need finishing' ?>
                            </h3>
                            <p class="text-sm text-gray-400 mt-0.5">
                                The student attended these, but no outcome has been recorded yet. They stay open, and unpaid, until you close them.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($unfinishedLessons as $uf):
                            $ufWhen = utcToLocal($uf['scheduled_start_time'], $tutor_timezone);
                            $ufWho  = trim(($uf['first_name'] ?? '') . ' ' . ($uf['last_name'] ?? ''));
                        ?>
                        <div class="card-base-sub p-3 rounded-lg flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white truncate"><?= h($uf['subject_name']) ?></p>
                                <p class="text-sm text-gray-400 truncate">
                                    <?= $ufWho !== '' ? 'with ' . h($ufWho) : 'Demo Lesson' ?>
                                    &middot; <?= h($ufWhen->format('D j M, g:i A')) ?>
                                </p>
                            </div>
                            <a href="finish_lesson.php?lesson_id=<?= (int)$uf['id'] ?>"
                               class="shrink-0 inline-flex items-center gap-1.5 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold px-3 py-2 rounded-lg transition-colors">
                                Finish
                                <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($unfinishedCount > count($unfinishedLessons)): ?>
                        <p class="text-xs text-gray-500 mt-3">
                            Showing <?= count($unfinishedLessons) ?> of <?= $unfinishedCount ?>.
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Lesson Status Card -->
                <div class="card-base">
                    <h3 class="text-lg font-semibold text-primary mb-4">
                        <?php
                        if ($upcomingLesson) {
                            // Convert UTC times from database to tutor's local timezone
                            $st = utcToLocal($upcomingLesson['scheduled_start_time'], $tutor_timezone);
                            $en = utcToLocal($upcomingLesson['scheduled_end_time'], $tutor_timezone);
                            $cardState = get_lesson_card_state($st, $en, $upcomingLesson['status'], $now);
                            echo $cardState['state'] === 'C' ? 'Current Lesson' : 'Next Lesson';
                        } else {
                            echo !empty($nextLessons) ? 'Next Lesson' : 'No Upcoming Lessons';
                        }
                        ?>
                    </h3>
                    
                    <?php 
                    $displayLesson = $upcomingLesson ?: ($nextLessons[0] ?? null);
                    if ($displayLesson): 
                        // Convert UTC times from database to tutor's local timezone
                        $startTime = utcToLocal($displayLesson['scheduled_start_time'], $tutor_timezone);
                        $endTime = utcToLocal($displayLesson['scheduled_end_time'], $tutor_timezone);
                        $cardState = get_lesson_card_state($startTime, $endTime, $displayLesson['status'], $now);
                        $isNow = $cardState['state'] === 'C' || ($cardState['state'] === 'B' && $now >= $startTime);
                    ?>
                        <div class="<?= $isNow ? 'bg-emerald-500/10 border-emerald-500/30' : 'bg-indigo-500/10 border-indigo-500/30' ?> border p-5 rounded-lg flex flex-wrap items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <?php if ($displayLesson): ?>
                                    <div id="lesson-countdown-container" class="mb-2">
                                        <?php if ($cardState['state'] === 'C'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-200">
                                                <span class="flex h-2 w-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                                                <span id="lesson-countdown-text">In Progress</span>
                                            </span>
                                        <?php else: ?>
                                            <p class="text-sm <?= $isNow ? 'text-emerald-400' : 'text-indigo-400' ?> font-medium">
                                                <span id="lesson-countdown-text"><?= htmlspecialchars(humanTimeUntil($startTime, $endTime)) ?></span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div id="lesson-countdown-timer" class="text-2xl md:text-3xl font-mono font-bold <?= $isNow ? 'text-emerald-400' : 'text-indigo-400' ?> mb-1" style="display: none;"></div>
                                <?php endif; ?>
                                
                                <h4 class="text-xl font-bold text-gray-900 dark:text-white mt-1"><?= htmlspecialchars($displayLesson['subject_name']) ?></h4>
                                
                                <div class="flex items-center gap-3 mt-3 text-gray-300">
                                    <?php 
                                    $initials = '';
                                    if (!empty($displayLesson['first_name'])) {
                                        $initials = strtoupper(substr($displayLesson['first_name'], 0, 1) . 
                                            (isset($displayLesson['last_name']) ? substr($displayLesson['last_name'], 0, 1) : ''));
                                    } else {
                                        $initials = '??';
                                    }
                                    ?>
                                    <?= photo_img($displayLesson['profile_photo_url'] ?? null, trim(($displayLesson['first_name'] ?? '').' '.($displayLesson['last_name'] ?? '')) ?: 'Demo Lesson') ?>
                                    <span class="truncate">
                                        <?= htmlspecialchars($displayLesson['first_name'] 
                                            ? 'with ' . $displayLesson['first_name'] . ' ' . ($displayLesson['last_name'] ?? '') 
                                            : 'Demo Lesson') ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                                <div class="text-right">
                                    <div class="text-lg font-semibold text-gray-200 whitespace-nowrap">
                                        <?= $startTime->format('g:i A') . ' - ' . $endTime->format('g:i A') ?>
                                    </div>
                                    <?php if ($isNow): ?>
                                        <div class="text-xs text-emerald-300 mt-1">
                                            Ends at <?= $endTime->format('g:i A') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php 
                                $meetingUrl = $displayLesson['meeting_url'] ?? '';
                                $meetingLink = getMeetingUrl($displayLesson);
                                ?>
                                <?php if ($cardState['can_join']): ?>
                                    <?php if (!empty($meetingLink)): ?>
                                        <a class="<?= $cardState['button_class'] ?> 
                                                text-white font-semibold py-2 px-5 rounded-lg transition-colors whitespace-nowrap 
                                                inline-flex items-center gap-2"
                                           href="<?= htmlspecialchars($meetingLink) ?>"
                                           target="_blank" rel="noopener">
                                            <svg class="h-5 w-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
                                            <?= htmlspecialchars($cardState['button_text']) ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= $cardState['button_href'] . (int)$displayLesson['id'] ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="<?= $cardState['button_class'] ?> 
                                                        text-white font-semibold py-2 px-5 rounded-lg transition-colors whitespace-nowrap
                                                        inline-flex items-center gap-2"
                                                    title="Join lesson">
                                            <svg class="h-5 w-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
                                            <?= htmlspecialchars($cardState['button_text']) ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button class="<?= $cardState['button_class'] ?> 
                                                text-white font-semibold py-2 px-5 rounded-lg transition-colors whitespace-nowrap
                                                inline-flex items-center gap-2 lesson-details-btn"
                                            data-lesson-id="<?= (int)$displayLesson['id'] ?>"
                                            disabled>
                                        <?= htmlspecialchars($cardState['button_text']) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 px-4">
                            <div class="mx-auto w-16 h-16 bg-gray-800 rounded-full flex items-center justify-center text-gray-600 mb-3">
                                <svg class="h-8 w-8" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>
                            </div>
                            <h4 class="text-lg font-medium text-gray-200">No upcoming lessons</h4>
                            <p class="mt-1 text-sm text-gray-400 max-w-md mx-auto">
                                You don't have any scheduled lessons right now. Check back later or schedule a new lesson.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Earnings Overview (REAL) -->
                <div class="card-base">
                    <h3 class="text-lg font-semibold text-primary mb-4">Earnings</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="p-4 rounded-lg bg-indigo-500/5 border border-indigo-500/10">
                            <p class="text-sm text-secondary">This Week</p>
                            <p id="earn-week" class="text-2xl font-bold text-primary mt-1"><?php echo htmlspecialchars($C . number_format($earn_week, 2)); ?></p>
                        </div>
                        <div class="p-4 rounded-lg bg-indigo-500/5 border border-indigo-500/10">
                            <p class="text-sm text-secondary">This Month</p>
                            <p id="earn-month" class="text-2xl font-bold text-primary mt-1"><?php echo htmlspecialchars($C . number_format($earn_month, 2)); ?></p>
                        </div>
                        <div class="p-4 rounded-lg bg-indigo-500/5 border border-indigo-500/10">
                            <p class="text-sm text-secondary">Total Earnings</p>
                            <p id="earn-total" class="text-2xl font-bold text-primary mt-1"><?php echo htmlspecialchars($C . number_format($earn_total, 2)); ?></p>
                        </div>
                    </div>
                    <div class="h-56"><canvas id="earningsChart"></canvas></div>
                </div>

                <!-- Previous Lessons -->
                <div class="card-base">
                    <h3 class="text-lg font-semibold text-primary mb-4">Previous Lessons</h3>
                    <div class="space-y-1">
                        <?php if (empty($previousLessons)): ?>
                            <p class="text-gray-400">No recent lesson history.</p>
                        <?php else: ?>
                            <?php foreach ($previousLessons as $lesson): 
                                $statusClass = 'text-green-400';
                                if (strpos($lesson['status'], 'Cancelled') !== false) $statusClass = 'text-red-400';
                            ?>
                            <div class="card-base-sub p-4 rounded-lg flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <?php 
                                        $studentName = htmlspecialchars(($lesson['first_name'] ?? '') . ' ' . ($lesson['last_name'] ?? ''));
                                        $initials = '';
                                        if (!empty($lesson['first_name'])) $initials .= strtoupper(substr($lesson['first_name'], 0, 1));
                                        if (!empty($lesson['last_name'])) $initials .= strtoupper(substr($lesson['last_name'], 0, 1));
                                        if (empty($initials)) $initials = 'U';
                                        $photoUrl = !empty($lesson['profile_photo_url']) ?
                                            resolve_photo($lesson['profile_photo_url'], $studentName) :
                                            '../assets/avatar.php?s=96&bg=1f2937&fg=ffffff&text=' . urlencode($initials);
                                    ?>
                                    <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center overflow-hidden ring-2 ring-gray-200 dark:ring-gray-700">
                                        <img src="<?php echo h($photoUrl); ?>"
                                             alt="<?php echo $studentName; ?>"
                                             class="w-full h-full object-cover"
                                             onerror="this.onerror=null; this.src='../assets/avatar.php?s=96&bg=1f2937&fg=ffffff&text=<?php echo urlencode($initials); ?>';">
                                    </div>
                                    <div>
                                        <p class="font-semibold"><?php echo htmlspecialchars($lesson['subject_name']); ?></p>
                                        <p class="text-sm font-regular">with <?php echo $studentName; ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-thin"><?php echo (new DateTime($lesson['scheduled_start_time']))->format('M j, Y'); ?></p>
                                    <span class="text-sm font-medium <?php echo $statusClass; ?>"><?php echo htmlspecialchars($lesson['status']); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="schedule.php" class="block w-full mt-4 text-center text-indigo-400 font-semibold py-2 hover:text-indigo-300">View All History</a>
                </div>
            </div>

          <!-- right side -->
          <div class="lg:col-span-1 space-y-4">
            <div class="card-base relative overflow-hidden">
              <!-- subtle glow -->
              <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-indigo-500/10 blur-2xl rounded-full pointer-events-none"></div>

              <div class="flex items-center justify-between mb-6 relative z-10">
                <div class="flex items-center gap-3">
                  <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500/20 to-fuchsia-500/20 text-indigo-300 ring-1 ring-indigo-500/20">
                    <svg class="w-5 h-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-star"></use></svg>
                  </span>

                  <div>
                    <h3 class="text-base font-bold leading-none">Tutor Score</h3>
                    <p class="text-xs text-gray-400 mt-1">Composite (last 90 days)</p>
                  </div>
                </div>

                <span class="text-xs font-medium text-gray-400 card-base px-2.5 py-1 rounded-md border border-gray-700/50">
                  <?= date('M Y') ?>
                </span>
              </div>

              <div class="h-64 relative z-10">
                <canvas
                  id="tutorScoreDonut"
                  data-composite="<?= number_format($composite, 1, '.', '') ?>"
                  data-labels='<?= json_encode(array_keys($parts)) ?>'
                  data-values='<?= json_encode(array_values($parts)) ?>'
                    data-colors='<?= json_encode(array_values($donutColors)) ?>'
                  >
                </canvas>
              </div>
              
                <!-- Optional: compact breakdown -->
                <div class="mt-6 grid grid-cols-2 gap-3 text-sm text-gray-300">
                  <?php
                    $scorePartLabels = array_keys($parts);
                    $scorePartVals   = array_values($parts);
                    for ($i=0; $i < min(4, count($scorePartLabels)); $i++):
                  ?>
                    <div class="flex items-center justify-between card-base-sub rounded-lg px-3 py-2 border border-gray-800">
                      <span class="truncate"><?= h($scorePartLabels[$i]) ?></span>
                      <span class="font-semibold"><?= number_format($scorePartVals[$i], 1) ?></span>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>

                <!-- Next Lessons -->
                <div class="card-base">
                  <h3 class="text-lg font-semibold text-primary mb-4">Next Lessons</h3>
                  <div class="space-y-2">
                    <?php if(empty($nextLessons)): ?>
                         <p class="text-gray-400 text-center py-4">No other lessons scheduled soon.</p>
                    <?php else: ?>
                    <?php foreach($nextLessons as $lesson): 
                      $startTime = new DateTime($lesson['scheduled_start_time']);
                      $endTime = new DateTime($lesson['scheduled_end_time']);
                    ?>
                    <div class="p-3 rounded-lg hover:bg-gray-800/50 transition-colors cursor-pointer next-lesson-item"
                      data-id="<?php echo (int)$lesson['id']; ?>"
                      data-subject="<?php echo htmlspecialchars($lesson['subject_name']); ?>"
                      data-student="<?php echo htmlspecialchars($lesson['first_name'] . ' ' . $lesson['last_name']); ?>"
                      data-date="<?php echo $startTime->format('F j, Y'); ?>"
                      data-time="<?php echo $startTime->format('g:i A') . ' - ' . $endTime->format('g:i A'); ?>"
                      data-notes="<?php echo htmlspecialchars($lesson['tutor_notes'] ?? 'No notes for this lesson.'); ?>"
                      data-meeting-url="<?php echo htmlspecialchars($lesson['meeting_url'] ?? ''); ?>">
                      <div class="flex gap-4">
                        <div class="w-12 h-12 flex-shrink-0 bg-gray-800 rounded-lg flex flex-col items-center justify-center">
                          <p class="text-xs text-gray-400"><?php echo strtoupper($startTime->format('M')); ?></p>
                          <p class="text-lg font-bold text-white"><?php echo $startTime->format('j'); ?></p>
                        </div>
                        <div>
                          <p class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($lesson['subject_name']); ?></p>
                          <p class="text-sm text-gray-400">
                            <?php
                              $studentLabel = ($lesson['first_name'] ?? null)
                                ? ('with ' . $lesson['first_name'] . ' ' . $lesson['last_name'])
                                : 'Demo Lesson';
                              echo htmlspecialchars($studentLabel);
                            ?>
                          </p>
                          <p class="text-sm text-indigo-400 mt-1"><?php echo $startTime->format('g:i A') . ' - ' . $endTime->format('g:i A'); ?></p>
                        </div>
                      </div>
                      <button class="w-full mt-4 text-center text-indigo-400 font-semibold py-2 hover:text-indigo-300 block">View Full Schedule</button>
                    </div>
                      <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                
                <!-- Homework Submissions Card -->
                <div class="card-base">
                    <h3 class="text-lg font-semibold text-primary mb-4">Homework Submissions</h3>
                    <div class="space-y-4">
                        <?php if (empty($homeworkSubmissions)): ?>
                            <p class="text-gray-400 text-center py-4">No submissions need grading.</p>
                        <?php else: ?>
                            <?php foreach ($homeworkSubmissions as $submission): ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <?= photo_img($submission['profile_photo_url'], $submission['first_name'].' '.$submission['last_name']) ?>
                                    <div>
                                        <p class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                                        <p class="text-sm text-gray-400"><?php echo htmlspecialchars($submission['homework_title']); ?></p>
                                    </div>
                                </div>
                                <a href="homework.php?homework_id=<?= (int)$submission['homework_id'] ?>&amp;submission_id=<?= (int)$submission['submission_id'] ?>" class="text-sm font-semibold text-indigo-400 hover:text-indigo-300">Grade</a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="homework.php" class="block w-full mt-5 text-center text-indigo-400 font-semibold py-2 hover:text-indigo-300">View All Submissions</a>
                </div>
            </div>
        </div>
    </main>

    <!-- ========= MODALS START ========= -->
    <form id="joinLessonForm" method="post" action="join_lesson_live.php">
        <input type="hidden" name="lesson_id" id="joinLessonInput" value="">
    </form>

    <div id="lessonDetailModal" class="fixed inset-0 bg-black/70 flex items-center justify-center p-4 z-50 hidden modal-backdrop">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl w-full max-w-lg p-8 relative">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-gray-900 dark:hover:text-white modal-close-btn"><svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg></button>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Lesson Details</h3>
            <!-- Skeleton shown while the modal populates; hidden once content is ready -->
            <div id="modal-skeleton" class="space-y-4 hidden">
                <div class="skeleton h-5 w-2/3"></div>
                <div class="skeleton h-5 w-1/2"></div>
                <div class="skeleton h-5 w-3/5"></div>
                <div class="skeleton h-5 w-2/5"></div>
                <div class="skeleton h-16 w-full mt-6"></div>
            </div>
            <div id="modal-body">
            <div class="space-y-4 text-gray-600 dark:text-gray-300" id="modal-lesson-info">
                <div class="flex justify-between items-center"><span class="font-semibold text-gray-400">Subject:</span><span class="font-medium text-gray-900 dark:text-white" data-modal-id="subject"></span></div>
                <div class="flex justify-between items-center"><span class="font-semibold text-gray-400">Student:</span><span class="font-medium text-gray-900 dark:text-white" data-modal-id="student"></span></div>
                <div class="flex justify-between items-center"><span class="font-semibold text-gray-400">Date:</span><span class="font-medium text-gray-900 dark:text-white" data-modal-id="date"></span></div>
                <div class="flex justify-between items-center"><span class="font-semibold text-gray-400">Time:</span><span class="font-medium text-gray-900 dark:text-white" data-modal-id="time"></span></div>
            </div>
            <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Private Notes</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400" data-modal-id="notes"></p>
            </div>
            </div>
            <div class="flex justify-end gap-3 pt-6 mt-2">
                <button type="button" class="py-2.5 px-5 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white font-semibold rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 modal-close-btn">Close</button>
                <button id="modalJoinBtn" type="button" class="py-2.5 px-5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500">Join Lesson</button>
            </div>
        </div>
    </div>
    
<script>
document.addEventListener('DOMContentLoaded', function() {
  // === Earnings Chart (real data) ===
  const ctxE = document.getElementById('earningsChart');
  if (ctxE) {
    new Chart(ctxE, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($weekLabels); ?>,
        datasets: [{
          label: 'Earnings (<?php echo addslashes($currency); ?>)',
          data: <?php echo json_encode($weekDataPts); ?>,
          fill: true,
          backgroundColor: 'rgba(99, 102, 241, 0.2)',
          borderColor: 'rgba(99, 102, 241, 1)',
          tension: 0.4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.1)' }, ticks: { color: '#9ca3af' } },
          x: { grid: { display: false }, ticks: { color: '#9ca3af' } }
        },
        plugins: { legend: { display: false } }
      }
    });
  }
        // === Notification Dropdown ===
        const notificationBtn = document.getElementById('notification-btn');
        const notificationPanel = document.getElementById('notification-panel');
        if (notificationBtn && notificationPanel) {
            notificationBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationPanel.classList.toggle('hidden');
            });
            window.addEventListener('click', (e) => {
                if (!notificationPanel.classList.contains('hidden') && !notificationPanel.contains(e.target) && !notificationBtn.contains(e.target)) {
                    notificationPanel.classList.add('hidden');
                }
            });
        }
        
        // === Lesson Details Modal ===
        const lessonDetailModal = document.getElementById('lessonDetailModal');
        const nextLessonItems = document.querySelectorAll('.next-lesson-item');
        const joinForm = document.getElementById('joinLessonForm');
        const joinInput = document.getElementById('joinLessonInput');

        const openModal = (el) => el.classList.remove('hidden');
        const closeModal = (el) => el.classList.add('hidden');

        // Open the lesson modal with a brief skeleton loading state before revealing
        // the populated content (also the pattern any future fetched modal should use).
        const openLessonModalWithSkeleton = (el) => {
            const sk = document.getElementById('modal-skeleton');
            const body = document.getElementById('modal-body');
            if (sk && body) { sk.classList.remove('hidden'); body.classList.add('hidden'); }
            el.classList.remove('hidden');
            setTimeout(() => {
                if (sk) sk.classList.add('hidden');
                if (body) body.classList.remove('hidden');
            }, 260);
        };

        // Reusable button loading state: spinner + disabled.
        const setBtnLoading = (btn, loading, labelWhileLoading) => {
            if (!btn) return;
            if (loading) {
                if (btn.dataset.origHtml == null) btn.dataset.origHtml = btn.innerHTML;
                btn.setAttribute('data-loading', '1');
                btn.innerHTML = '<span class="btn-spinner"></span>' + (labelWhileLoading ? ' ' + labelWhileLoading : '');
            } else {
                btn.removeAttribute('data-loading');
                if (btn.dataset.origHtml != null) { btn.innerHTML = btn.dataset.origHtml; delete btn.dataset.origHtml; }
            }
        };
        window.setBtnLoading = setBtnLoading;

        if (lessonDetailModal && nextLessonItems.length > 0) {
            nextLessonItems.forEach(item => {
                item.addEventListener('click', () => {
                    const subject = item.dataset.subject;
                    const student = item.dataset.student;
                    const date = item.dataset.date;
                    const time = item.dataset.time;
const notes = item.dataset.notes;
const id = item.dataset.id;
const meetingUrl = (item.dataset.meetingUrl || '').trim();

lessonDetailModal.querySelector('[data-modal-id="subject"]').textContent = subject;
lessonDetailModal.querySelector('[data-modal-id="student"]').textContent = (student && student.trim().length) ? student : 'Demo Lesson';
lessonDetailModal.querySelector('[data-modal-id="date"]').textContent = date;
lessonDetailModal.querySelector('[data-modal-id="time"]').textContent = time;
lessonDetailModal.querySelector('[data-modal-id="notes"]').textContent = notes;

joinInput.value = id;

// Stash the URL on the button for quick access
const btn = document.getElementById('modalJoinBtn');
btn.dataset.meetingUrl = meetingUrl;

openLessonModalWithSkeleton(lessonDetailModal);

                });
            });
        }
       document.getElementById('modalJoinBtn')?.addEventListener('click', () => {
    const btn = document.getElementById('modalJoinBtn');
    setBtnLoading(btn, true, 'Joining…');
    const url = (btn?.dataset?.meetingUrl || '').trim();
    if (url) {
        // append role=tutor safely on the client
        const target = url + (url.includes('?') ? '&' : '?') + 'role=tutor';
        window.open(target, '_blank', 'noopener');
        // opened in a new tab — restore the button so the modal stays usable
        setTimeout(() => setBtnLoading(btn, false), 1200);
    } else if (joinInput.value) {
        joinForm.submit(); // fallback (default join flow) — navigates away
    } else {
        setBtnLoading(btn, false);
    }
});


        // Universal modal close
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal || e.target.closest('.modal-close-btn')) {
                    closeModal(modal);
                }
            });
        });

// === Tutor Score Donut (multi-segment, curved ring, center value) ===
(function(){
  const el = document.getElementById('tutorScoreDonut');
  if (!el) return;

  // read data from data-attrs
  const composite = parseFloat(el.dataset.composite || '0');
  let labels = [];
  let values = [];
  let colors = [];
  try {
    labels = JSON.parse(el.dataset.labels || '[]');
    values = JSON.parse(el.dataset.values || '[]');
    colors = JSON.parse(el.dataset.colors || '[]');
  } catch(e){}

  // fallback guards
  if (!Array.isArray(labels)) labels = [];
  if (!Array.isArray(values)) values = [];
  if (!Array.isArray(colors)) colors = [];

  // graceful empty state
  if (!values.length) {
    labels = ['Score', 'Remaining'];
    values = [composite, Math.max(0, 100 - composite)];
    colors = ['#6366f1', '#374151'];
  }

  // soft “rounded” ends in Chart.js for doughnut:
  // use borderRadius, thicker cutout, and borderColor to match bg
  const bgBorder = 'transparent'; 

  // center text plugin
  const centerText = {
    id: 'centerText',
    afterDraw(chart) {
      const {ctx, chartArea:{left, top, width, height}} = chart;
      ctx.save();
      ctx.font = '700 36px Inter, sans-serif';
      
      // Theme-aware color
      const isLight = document.body.classList.contains('theme-light');
      ctx.fillStyle = isLight ? '#111827' : '#ffffff';
      
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(composite.toFixed(1), left + width/2, top + height/2);
      ctx.font = '500 13px Inter, sans-serif';
      ctx.fillStyle = '#9ca3af';
      ctx.fillText('Score', left + width/2, top + height/2 + 28);
      ctx.restore();
    }
  };

  new Chart(el, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: colors,
        borderColor: bgBorder,
        borderWidth: 6, 
        borderRadius: 12,
        spacing: 4,     
        hoverOffset: 6,
        hoverBorderColor: bgBorder,
        hoverBorderWidth: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '72%',            // chunky ring like the new style
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0b1020',
          titleColor: '#fff',
          // bodyColor: '#cbd5e1',
          // borderColor: '#334155',
          borderWidth: 1,
          callbacks: {
            label: (ctx) => `${ctx.label}: ${(+ctx.raw).toFixed(1)}`
          }
        }
      },
      animation: { duration: 700 }
    },
    plugins: [centerText]
  });
})();

// ---------- Lesson Countdown Timer ----------
<?php if ($displayLesson): ?>
(function(){
    const countdownContainer = document.getElementById('lesson-countdown-container');
    const countdownText = document.getElementById('lesson-countdown-text');
    const countdownTimer = document.getElementById('lesson-countdown-timer');
    
    if (!countdownContainer || !countdownText || !countdownTimer) return;

    const lesson_start_time = '<?= $displayLesson['scheduled_start_time'] ?>';
    const lesson_end_time = '<?= $displayLesson['scheduled_end_time'] ?>';
    const lesson_status = '<?= $displayLesson['status'] ?>';
    
    if (!lesson_start_time) return;

    // Database stores UTC time, so append 'Z' to treat as UTC in JavaScript
    const start = new Date(lesson_start_time.replace(' ', 'T') + 'Z');
    const end = new Date(lesson_end_time.replace(' ', 'T') + 'Z');

    function updateCountdown() {
        const now = new Date();
        const startDiff = start.getTime() - now.getTime();
        const endDiff = end.getTime() - now.getTime();

        // If lesson is InProgress (check actual status, not just time)
        if (lesson_status === 'InProgress') {
            if (endDiff > 0) {
                const hours = Math.floor((endDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((endDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((endDiff % (1000 * 60)) / 1000);
                
                countdownText.textContent = 'In Progress';
                countdownTimer.style.display = 'block';
                countdownTimer.textContent = `Ends in ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                countdownTimer.className = 'text-2xl md:text-3xl font-mono font-bold text-emerald-400 mb-1';
            } else {
                countdownText.textContent = 'Lesson Ended';
                countdownTimer.style.display = 'none';
            }
        }
        // If lesson time has started but status is still Scheduled, show "Join Now" instead
        else if (now >= start && now < end && lesson_status === 'Scheduled') {
            if (endDiff > 0) {
                const hours = Math.floor((endDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((endDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((endDiff % (1000 * 60)) / 1000);
                
                countdownText.textContent = 'Join Now';
                countdownTimer.style.display = 'block';
                countdownTimer.textContent = `Ends in ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                countdownTimer.className = 'text-2xl md:text-3xl font-mono font-bold text-indigo-400 mb-1';
            } else {
                countdownText.textContent = 'Lesson Ended';
                countdownTimer.style.display = 'none';
            }
        } 
        // If lesson hasn't started yet, show countdown to start
        else if (startDiff > 0) {
            const days = Math.floor(startDiff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((startDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((startDiff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((startDiff % (1000 * 60)) / 1000);

            // Show countdown timer if less than 24 hours away
            if (startDiff <= 24 * 60 * 60 * 1000) {
                countdownTimer.style.display = 'block';
                if (days > 0) {
                    countdownTimer.textContent = `${days}d ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                } else {
                    countdownTimer.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }
                countdownTimer.className = 'text-2xl md:text-3xl font-mono font-bold text-indigo-400 mb-1';
                
                // Update text based on time remaining
                if (startDiff <= 15 * 60 * 1000) {
                    countdownText.textContent = 'Starting Soon';
                    countdownText.parentElement.className = 'text-sm text-emerald-400 font-medium';
                } else if (startDiff <= 60 * 60 * 1000) {
                    countdownText.textContent = 'Less than 1 hour';
                    countdownText.parentElement.className = 'text-sm text-indigo-400 font-medium';
                } else {
                    countdownText.textContent = 'Upcoming';
                    countdownText.parentElement.className = 'text-sm text-indigo-400 font-medium';
                }
            } else {
                countdownTimer.style.display = 'none';
                // Show relative time for lessons more than 24 hours away
                if (days === 1) {
                    countdownText.textContent = 'Tomorrow';
                } else if (days < 7) {
                    countdownText.textContent = `In ${days} days`;
                } else {
                    countdownText.textContent = start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }
            }
        }
        // Lesson has passed
        else {
            countdownText.textContent = 'Lesson Ended';
            countdownTimer.style.display = 'none';
        }
    }

    // Update every second
    setInterval(updateCountdown, 1000);
    updateCountdown();
})();
<?php endif; ?>

    // ---------- Live auto-update (poll for changes) ----------
    (function(){
        const notifButtons = ['notif-btn', 'notif-btn-mobile'];
        function updateNotifBadge(count){
            notifButtons.forEach(id => {
                const btn = document.getElementById(id);
                if (!btn) return;
                let badge = btn.querySelector('.ui-notification-badge');
                if (count > 0){
                    if (!badge){
                        badge = document.createElement('span');
                        badge.className = 'ui-notification-badge';
                        btn.appendChild(badge);
                    }
                    const txt = count > 99 ? '99+' : String(count);
                    if (badge.textContent.trim() !== txt){
                        badge.textContent = txt;
                        badge.classList.add('live-updated');
                        setTimeout(() => badge.classList.remove('live-updated'), 900);
                    }
                } else if (badge){
                    badge.remove();
                }
            });
        }

        let baseline = null; // set on first poll to avoid a load-time reload loop
        async function poll(){
            try {
                const res = await fetch('api/dashboard_live.php', { credentials: 'same-origin' });
                if (!res.ok) return;
                const d = await res.json();
                if (!d || !d.success) return;
                updateNotifBadge(d.notif_count || 0);
                // If the next lesson changed (new booking, cancellation, or a status
                // flip to InProgress/joinable), soft-reload so the lesson card is right.
                const sig = (d.lesson_id || 0) + '|' + (d.lesson_status || '');
                if (baseline === null){ baseline = sig; return; }
                if (sig !== baseline){ location.reload(); }
            } catch(e){ /* ignore transient errors */ }
        }
        setInterval(poll, 30000);
        // refresh immediately when the tab regains focus
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
        poll(); // establish baseline right away
    })();

    });
</script>
</body>
</html>
