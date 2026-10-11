<?php
// students/dashboard.php — rewritten with join-window + rating prompt improvements

// 1) Session, Auth, Timezone
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}

// Load timezone helper for proper UTC handling
require_once __DIR__ . '/../utils/timezone_helper.php';
require_once __DIR__ . '/../utils/lesson_sweep.php';

// User's timezone from session (loaded during login)
$userTimezone = getUserTimezone();

// Set PHP timezone for this script (for backward compatibility with date() calls)
date_default_timezone_set($userTimezone);

$current_page = 'dashboard';
$student_id   = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_first_name'] ?? 'Student';

// --- Greeting helpers ---
$hour        = (int)date('G');
$greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$todayStr    = date('l, M j');
$hiName      = $student_name ?: 'Student';

// Short blurb for the next lesson (optional, shows under title)
$nextBlurb = 'No upcoming lesson';
// Note: $upcomingLesson is defined later, so this will be updated after the query

$banner_img   = 'banner2.jpg';   // absolute or relative URL
$banner_alt   = 'Back to School';                       // for accessibility
$banner_title = 'Back to School Special';
$banner_text  = 'Get 10% off packages this month.';
$banner_cta   = 'View Packages';
$banner_href  = 'packages.php';                         // set to null if no button



// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../app_bootstrap.php'; // adjust path if bootstrap elsewhere

// Ensure database connection is available
if ($pdo === null) {
    try {
        $pdo = getDbConnection();
    } catch (Exception $e) {
        die('Database connection failed. Please contact support if this issue persists.');
    }
}

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hwStatusPill($row) {
    if (!$row['submission_id']) {
        return ['text' => 'Not Submitted', 'class' => 'badge-not-submitted'];
    }
    switch ($row['submission_status']) {
        case 'Submitted': return ['text' => 'Submitted', 'class' => 'badge-submitted'];
        case 'Graded':    return ['text' => 'Graded',    'class' => 'badge-graded'];
        case 'Late':      return ['text' => 'Late',      'class' => 'badge-late'];
        default:          return ['text' => 'Unknown',   'class' => 'badge-unknown'];
    }
}

function resolve_profile_photo(?string $path): string {
    if (!$path) return '';
    $clean = str_replace('\\', '/', trim($path));
    if ($clean === '') return '';
    if (preg_match('~^(?:https?:)?//~i', $clean)) return $clean;
    if ($clean[0] === '/') return $clean;
    return uploads_url($clean);
}


/** NEW HELPER FUNCTION FOR HOMEWORK DUE DATES **/
function formatDueDate(?string $dueDate): array {
    if (!$dueDate) {
        return ['text' => 'No due date', 'class' => 'bg-gray-700 text-gray-300'];
    }
    $due = new DateTime($dueDate);
    $now = new DateTime();
    $diff = $now->diff($due);
    $days = (int)$diff->format('%r%a'); // %r gives +/- sign

    if ($days < -1) {
        return ['text' => 'Overdue', 'class' => 'bg-red-500/15 text-red-300 ring-red-500/30'];
    }
    if ($days === -1) {
        return ['text' => 'Due yesterday', 'class' => 'bg-red-500/15 text-red-300 ring-red-500/30'];
    }
    if ($days === 0) {
        return ['text' => 'Due today', 'class' => 'bg-amber-500/15 text-amber-300 ring-amber-500/30'];
    }
    if ($days === 1) {
        return ['text' => 'Due tomorrow', 'class' => 'bg-amber-500/15 text-amber-300 ring-amber-500/30'];
    }
    if ($days <= 7) {
        return ['text' => 'Due in ' . $days . ' days', 'class' => 'bg-sky-500/10 text-sky-300 ring-sky-500/20'];
    }
    return ['text' => 'Due ' . $due->format('M j'), 'class' => 'bg-gray-700/60 text-gray-300 ring-gray-600/30'];
}

function ts_or_null(?string $dt): ?int {
    if (!$dt) return null;                 // handles null/empty
    $ts = strtotime($dt);
    return $ts === false ? null : $ts;     // handles invalid strings
}
function fmt_dt(?string $dt, string $fmt = 'M j, Y g:i A'): string {
    $ts = ts_or_null($dt);
    return $ts !== null ? date($fmt, $ts) : '—';
}

function human_until(DateTime $start, ?DateTime $now = null): string {
    $now = $now ?: new DateTime();
    $diff = $now->diff($start);
    $neg = $start < $now;

    $days = (int)$diff->days;
    $h    = (int)$diff->h;
    $m    = (int)$diff->i;

    if ($days === 0 && $h === 0 && $m <= 2) return $neg ? 'Started just now' : 'Starting now';

    if ($days >= 7) {
        return ($neg ? '' : 'On ') . $start->format('M j, g:i A');
    }
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($h    > 0) $parts[] = $h . 'h';
    if ($m    > 0) $parts[] = $m . 'm';

    $txt = implode(' ', array_slice($parts, 0, 2));
    return $neg ? ($txt . ' ago') : ('In ' . $txt);
}

/** Join window = from (start - 1h) through (start + 1h) */
function is_join_window(DateTime $start, ?DateTime $now = null): bool {
    $now   = $now ?: new DateTime();
    $lower = (clone $start)->modify('-10 minutes');
    $upper = (clone $start)->modify('+1 hour');
    return ($now >= $lower && $now <= $upper);
}

function is_within_24_hours(DateTime $start, ?DateTime $now = null): bool {
    $now = $now ?: new DateTime();
    $time_diff = $start->getTimestamp() - $now->getTimestamp();
    return $time_diff > 0 && $time_diff < 24 * 3600;
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
            'button_href' => 'lesson_room_live.php?lesson_id=',
            'button_class' => 'bg-emerald-600 hover:bg-emerald-500'
        ];
    }
    
    // State B: Within 15 minutes before start OR lesson has started but not ended
    if ($minutes_until_start <= 15 && $now < $end) {
        return [
            'state' => 'B',
            'button_text' => 'Join Lesson',
            'can_join' => true,
            'button_href' => 'lesson_room_live.php?lesson_id=',
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

// ---------- AJAX ENDPOINTS ----------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $ok = function($data = [], $msg = null) {
        echo json_encode(['success'=>true, 'data'=>$data, 'message'=>$msg], JSON_UNESCAPED_SLASHES);
        exit();
    };
    $err = function($msg, $code = 400) {
        http_response_code($code);
        echo json_encode(['success'=>false, 'data'=>[], 'message'=>$msg], JSON_UNESCAPED_SLASHES);
        exit();
    };

    // Lesson detail: topics + (optional) subtopics + recording + meta
    if ($_GET['ajax'] === 'lesson_detail') {
        $lesson_id = (int)($_GET['lesson_id'] ?? 0);
        if ($lesson_id <= 0) $err('Invalid lesson id', 422);

        $sql = "
            SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status, l.tutor_notes,
                   s.name AS subject_name,
                   t.first_name AS tutor_first, t.last_name AS tutor_last, t.profile_photo_url AS tutor_photo
            FROM lessons l
            JOIN subjects s ON s.id = l.subject_id
            JOIN users t ON t.id = l.tutor_id
            WHERE l.id = ? AND l.student_id = ?
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$lesson_id, $student_id]);
        $lesson = $st->fetch();
        if (!$lesson) $err('Lesson not found', 404);

        // Topics covered
        $st = $pdo->prepare("
            SELECT t.name 
            FROM lesson_topics_covered tc 
            JOIN topics t ON t.id = tc.topic_id
            WHERE tc.lesson_id = ?
            ORDER BY t.name ASC
        ");
        $st->execute([$lesson_id]);
        $topics = array_map(fn($r) => $r['name'], $st->fetchAll());

        // Optional subtopics
        $subtopics = [];
        try {
            $ck = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'lesson_subtopics_covered'")->fetchColumn();
            if ($ck) {
                $st = $pdo->prepare("
                    SELECT st.name
                    FROM lesson_subtopics_covered lsc
                    JOIN subtopics st ON st.id = lsc.subtopic_id
                    WHERE lsc.lesson_id = ?
                    ORDER BY st.name ASC
                ");
                $st->execute([$lesson_id]);
                $subtopics = array_map(fn($r) => $r['name'], $st->fetchAll());
            }
        } catch (Throwable $e) {}

        // Recording
        $st = $pdo->prepare("SELECT file_url, file_size_bytes, duration_seconds, recorded_at FROM lesson_recordings WHERE lesson_id = ?");
        $st->execute([$lesson_id]);
        $rec = $st->fetch();
        $recording = $rec ? [
            'url' => $rec['file_url'],
            'size' => (int)($rec['file_size_bytes'] ?? 0),
            'duration' => (int)($rec['duration_seconds'] ?? 0),
            'recorded_at' => $rec['recorded_at']
        ] : null;

        $ok([
            'lesson'    => $lesson,
            'topics'    => $topics,
            'subtopics' => $subtopics,
            'recording' => $recording
        ]);
    }


    // Homework preview: description + attachments + submission
    if ($_GET['ajax'] === 'homework_preview') {
        $homework_id = (int)($_GET['homework_id'] ?? 0);
        if ($homework_id <= 0) $err('Invalid homework id', 422);

        $st = $pdo->prepare("
            SELECT h.id, h.title, h.description, h.due_date,
                   u.first_name AS tutor_first, u.last_name AS tutor_last
            FROM homework h
            JOIN users u ON u.id = h.tutor_id
            WHERE h.id = ? AND h.student_id = ?
        ");
        $st->execute([$homework_id, $student_id]);
        $hw = $st->fetch();
        if (!$hw) $err('Homework not found', 404);

        $st = $pdo->prepare("SELECT id AS submission_id, status, grade, content, submission_date FROM homework_submissions WHERE homework_id = ? AND student_id = ?");
        $st->execute([$homework_id, $student_id]);
        $sub = $st->fetch() ?: null;

        // Build secure links through downloader
        $st = $pdo->prepare("
            SELECT id, file_name, uploaded_at
            FROM homework_attachments
            WHERE homework_id = ?
            ORDER BY uploaded_at ASC, id ASC
        ");
        $st->execute([$homework_id]);

        $attachments = [];
        $DOWNLOAD_ENDPOINT = '/api/download.php'; // relative to /students/
        while ($row = $st->fetch()) {
            $attachments[] = [
                'id'          => (int)$row['id'],
                'file_name'   => (string)$row['file_name'],
                'file_url'    => $DOWNLOAD_ENDPOINT . '?type=homework&id=' . (int)$row['id'] . '&disposition=inline',
                'uploaded_at' => $row['uploaded_at'],
            ];
        }

        $ok(['homework'=>$hw, 'submission'=>$sub, 'attachments'=>$attachments]);
    }


    // Find the most recent "ended" (Completed OR end time passed), joined, unrated lesson
    if ($_GET['ajax'] === 'next_unrated_lesson') {
        $sql = "
          SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.tutor_id,
                 s.name AS subject_name,
                 u.first_name AS tutor_first, u.last_name AS tutor_last
          FROM lessons l
          JOIN subjects s ON s.id = l.subject_id
          JOIN users u    ON u.id = l.tutor_id
          WHERE l.student_id = ?
            AND l.student_join_time IS NOT NULL
            AND (
                  l.status = 'Completed'
                  OR (l.scheduled_end_time IS NOT NULL AND l.scheduled_end_time <= NOW())
                )
            AND NOT EXISTS (
                SELECT 1 FROM lesson_ratings r
                WHERE r.lesson_id = l.id AND r.student_id = ?
            )
          ORDER BY l.scheduled_end_time DESC
          LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$student_id, $student_id]);
        $row = $st->fetch();
        $ok(['lesson'=>$row ?: null]);
    }

    $err('Unknown action', 404);
}

// ---------- FETCH PAGE DATA ----------
// Get current lesson (in progress) or next upcoming lesson
$upcomingLesson = null;

// Close off lessons nobody recorded an outcome for. This page used to carry its
// own copy of this, marking any ended lesson a NoShow on the clock alone, which
// undid the same fix made on the tutor side. Both now share utils/lesson_sweep.php.
//
// Running the tutor-absence check here matters more than running it on the tutor's
// own dashboard: a tutor who did not turn up is not the one who comes back to look.
sweep_absent_students($pdo, null, $student_id);
sweep_absent_tutors($pdo, null, $student_id);

// Get current/upcoming lesson: InProgress lessons or Scheduled lessons that haven't ended
$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status,
           s.name AS subject_name,
           s.hero_image_url, s.hero_image_alt,
           t.first_name AS tutor_first, t.last_name AS tutor_last, t.profile_photo_url AS tutor_photo
    FROM lessons l
    JOIN subjects s ON s.id = l.subject_id
    JOIN users t ON t.id = l.tutor_id
    WHERE l.student_id = ?
      AND (
          (l.status = 'InProgress' AND l.scheduled_end_time >= NOW() - INTERVAL 1 HOUR)
          OR (l.status = 'Scheduled' AND l.scheduled_end_time > NOW())
      )
    ORDER BY 
        CASE WHEN l.status = 'InProgress' THEN 0 ELSE 1 END,
        l.scheduled_start_time ASC
    LIMIT 1
");
$stmt->execute([$student_id]);
$upcomingLesson = $stmt->fetch();

// Update nextBlurb after fetching lesson
if (!empty($upcomingLesson)) {
    $stHeader = utcToLocal($upcomingLesson['scheduled_start_time'], $userTimezone);
    $nextBlurb = $upcomingLesson['subject_name'] . ' · ' . $stHeader->format('M j, g:i A');
}

// Next lessons (top 3, excluding current lesson)
$excludeId = $upcomingLesson ? $upcomingLesson['id'] : 0;
$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status,
           s.name AS subject_name,
           s.hero_image_url, s.hero_image_alt,
           t.first_name AS tutor_first, t.last_name AS tutor_last, t.profile_photo_url AS tutor_photo
    FROM lessons l
    JOIN subjects s ON s.id = l.subject_id
    JOIN users t ON t.id = l.tutor_id
    WHERE l.student_id = ?
      AND l.id != ?
      AND (
          (l.status = 'InProgress' AND l.scheduled_end_time >= NOW() - INTERVAL 1 HOUR)
          OR (l.status = 'Scheduled' AND l.scheduled_end_time > NOW())
      )
    ORDER BY l.scheduled_start_time ASC
    LIMIT 3
");
$stmt->execute([$student_id, $excludeId]);
$nextLessons = $stmt->fetchAll();

// Previous 3 lessons (include whether rated)
$stmt = $pdo->prepare("
    SELECT l.id, l.scheduled_start_time, l.scheduled_end_time, l.status,
           s.name AS subject_name,
           t.first_name AS tutor_first, t.last_name AS tutor_last, t.profile_photo_url AS tutor_photo,
           r.id AS rating_id, r.rating AS rating_value
    FROM lessons l
    JOIN subjects s ON s.id = l.subject_id
    JOIN users t ON t.id = l.tutor_id
    LEFT JOIN lesson_ratings r
           ON r.lesson_id = l.id AND r.student_id = ?
    WHERE l.student_id = ? AND l.scheduled_start_time < NOW()
    ORDER BY l.scheduled_start_time DESC
    LIMIT 3
");
$stmt->execute([$student_id, $student_id]);
$previousLessons = $stmt->fetchAll();

// Homework (prioritize newest pending for this student)
$stmt = $pdo->prepare("
    SELECT h.id, h.title, h.due_date, h.description,
           u.first_name AS tutor_first, u.last_name AS tutor_last,
           hs.id AS submission_id, hs.status AS submission_status, hs.grade
    FROM homework h
    JOIN users u ON u.id = h.tutor_id
    LEFT JOIN homework_submissions hs ON hs.homework_id = h.id AND hs.student_id = ?
    WHERE h.student_id = ?
    ORDER BY
        CASE WHEN hs.status IN ('Submitted','Graded') THEN 1 ELSE 0 END ASC,
        h.created_at DESC,
        h.due_date IS NULL,
        h.due_date ASC
    LIMIT 6
");
$stmt->execute([$student_id, $student_id]);
$homeworks = $stmt->fetchAll();

// Unread notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$student_id]);
$notification_unread_count = (int)$stmt->fetchColumn();

// Unread notifications (5 preview)
$stmt = $pdo->prepare("
    SELECT id, type, message, created_at
    FROM notifications
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();

// Active package (latest)
$stmt = $pdo->prepare("
    SELECT 
        sp.id,
        sp.start_date,
        sp.end_date,
        sp.total_lessons,
        p.name,
        p.lessons_per_week,
        GREATEST(
            sp.total_lessons - COALESCE(SUM(CASE 
                WHEN l.student_package_id = sp.id 
                     AND l.status IN ('Scheduled','Completed') THEN 1 
                ELSE 0 
            END), 0),
            0
        ) AS remaining_lessons
    FROM student_packages sp
    JOIN packages p ON p.id = sp.package_id
    LEFT JOIN lessons l 
        ON l.student_package_id = sp.id
        AND l.student_id = sp.student_id
        AND (l.status IN ('Scheduled','Completed'))
    WHERE sp.student_id = ?
      AND (sp.end_date IS NULL OR sp.end_date >= CURDATE())
    GROUP BY 
        sp.id, sp.start_date, sp.end_date, sp.total_lessons, 
        p.name, p.lessons_per_week
    ORDER BY sp.start_date DESC
    LIMIT 1
");
$stmt->execute([$student_id]);
$activePackage = $stmt->fetch();

// Last 8 homework grades for sparkline
$stmt = $pdo->prepare("
    SELECT DATE(hs.submission_date) AS submitted_on, hs.grade
    FROM homework_submissions hs
    JOIN homework h ON h.id = hs.homework_id
    WHERE hs.student_id = ? AND hs.grade IS NOT NULL
    ORDER BY hs.submission_date DESC
    LIMIT 8
");
$stmt->execute([$student_id]);
$gradeRows = array_reverse($stmt->fetchAll()); // oldest→newest

$gradeLabels = array_map(fn($r) => date('M j', strtotime($r['submitted_on'])), $gradeRows);
$gradeValues = array_map(fn($r) => (float)$r['grade'], $gradeRows);

// Student Score = average graded homework
$stmt = $pdo->prepare("SELECT AVG(grade) FROM homework_submissions WHERE student_id = ? AND grade IS NOT NULL");
$stmt->execute([$student_id]);
$studentScore = (float)($stmt->fetchColumn() ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM homework_submissions WHERE student_id = ? AND grade IS NOT NULL");
$stmt->execute([$student_id]);
$gradedCount = (int)$stmt->fetchColumn();

// Recordings summary (latest + count) for this student
$recording_count = 0;
$latestRecording = null;
try {
    $cnt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM lesson_recordings lr
        JOIN lessons l ON l.id = lr.lesson_id
        WHERE l.student_id = ?
    ");
    $cnt->execute([$student_id]);
    $recording_count = (int)$cnt->fetchColumn();

    $latestStmt = $pdo->prepare("
        SELECT 
            lr.id AS recording_id,
            lr.lesson_id,
            lr.file_url,
            COALESCE(lr.playback_url, lr.file_url) AS playback_url,
            lr.duration_seconds,
            COALESCE(lr.recorded_at, l.scheduled_end_time) AS recorded_at,
            s.name AS subject_name,
            u.first_name AS tutor_first,
            u.last_name  AS tutor_last
        FROM lesson_recordings lr
        JOIN lessons l ON l.id = lr.lesson_id
        JOIN subjects s ON s.id = l.subject_id
        JOIN users u ON u.id = l.tutor_id
        WHERE l.student_id = ?
        ORDER BY COALESCE(lr.recorded_at, l.scheduled_end_time) DESC, lr.id DESC
        LIMIT 1
    ");
    $latestStmt->execute([$student_id]);
    $latestRecording = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $recording_count = 0;
    $latestRecording = null;
}

// ---------- UI BANNER (Students Dashboard) ----------
$st = $pdo->prepare("
  SELECT *
  FROM ui_banners
  WHERE page_key = 'students_dashboard'
    AND audience IN ('All','Students')
    AND is_active = 1
    AND (starts_at IS NULL OR starts_at <= NOW())
    AND (ends_at   IS NULL OR ends_at   >= NOW())
  ORDER BY updated_at DESC
  LIMIT 1
");
$st->execute();
$banner = $st->fetch() ?: null;

// Normalize fields + resolve image URL from relative to public
$banner_enabled = !empty($banner) && trim((string)$banner['title']) !== '';
$banner_img     = uploads_url($banner['image_url'] ?? null); // '/uploads/banners/...'

// Fallback: if the referenced image doesn't exist, use banner2.jpg
if ($banner_img && !empty($banner['image_url'])) {
    $img_path = UPLOADS_DIR . '/' . ltrim($banner['image_url'], '/');
    if (!file_exists($img_path)) {
        $banner_img = uploads_url('banners/banner2.jpg');
    }
}

$banner_alt     = $banner['alt_text'] ?? '';
$banner_title   = $banner['title'] ?? '';
$banner_text    = $banner['message'] ?? '';
$banner_cta     = $banner['cta_label'] ?? '';
$banner_href    = $banner['cta_url'] ?? '';

// Optional: hide the banner if no text or image
if (!$banner_img && !$banner_text && !$banner_cta) {
    $banner_enabled = false;
}

$stmt = $pdo->prepare("
    SELECT
      SUM(
        CASE
          WHEN (l.status = 'Completed'
                OR (l.scheduled_end_time IS NOT NULL AND l.scheduled_end_time <= NOW()))
               AND l.student_join_time IS NOT NULL
          THEN 1 ELSE 0 END
      ) AS attended,
      SUM(
        CASE
          WHEN (l.status = 'Completed'
                OR (l.scheduled_end_time IS NOT NULL AND l.scheduled_end_time <= NOW()))
          THEN 1 ELSE 0 END
      ) AS total
    FROM lessons l
    WHERE l.student_id = ?
");
$stmt->execute([$student_id]);
list($attendedCount, $attTotal) = array_map('intval', $stmt->fetch(PDO::FETCH_NUM) ?: [0,0]);
$attendancePct = $attTotal > 0 ? round(($attendedCount / $attTotal) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Tailwind + Chart.js + Inter -->
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
            integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        /* ---- Light-mode colour remap for this dark-first page ----
           Scoped to <main> + the modals so the sidebar/header (theme.css-handled) are
           untouched. Remaps every dark utility, then re-asserts the dark hero cards
           (data-keep-dark) and coloured buttons.
           NB: only #lesson-hero is data-keep-dark. The promo banner is NOT --
           theme.css flips .banner-tint to a white wash in light mode, so forcing
           its heading white there put white text on a white tint. ---- */
        html[data-theme="light"] main .bg-gray-900,
        html[data-theme="light"] #lesson-modal .bg-gray-900,
        html[data-theme="light"] #homework-modal .bg-gray-900,
        html[data-theme="light"] #rating-modal .bg-gray-900 { background-color:#ffffff !important; }
        html[data-theme="light"] main .bg-gray-800,
        html[data-theme="light"] #lesson-modal .bg-gray-800,
        html[data-theme="light"] #homework-modal .bg-gray-800,
        html[data-theme="light"] #rating-modal .bg-gray-800 { background-color:#f1f5f9 !important; }
        html[data-theme="light"] main .bg-gray-700,
        html[data-theme="light"] #lesson-modal .bg-gray-700,
        html[data-theme="light"] #homework-modal .bg-gray-700,
        html[data-theme="light"] #rating-modal .bg-gray-700 { background-color:#ffffff !important; }
        html[data-theme="light"] main .border-gray-700,
        html[data-theme="light"] main .border-gray-800 { border-color:#e5e7eb !important; }
        html[data-theme="light"] main .text-white,
        html[data-theme="light"] #lesson-modal .text-white,
        html[data-theme="light"] #homework-modal .text-white,
        html[data-theme="light"] #rating-modal .text-white { color:#111827 !important; }
        html[data-theme="light"] main .text-gray-300,
        html[data-theme="light"] #lesson-modal .text-gray-300,
        html[data-theme="light"] #homework-modal .text-gray-300,
        html[data-theme="light"] #rating-modal .text-gray-300 { color:#374151 !important; }
        html[data-theme="light"] main .text-gray-400,
        html[data-theme="light"] #lesson-modal .text-gray-400,
        html[data-theme="light"] #homework-modal .text-gray-400,
        html[data-theme="light"] #rating-modal .text-gray-400 { color:#5c6470 !important; } /* #6b7280 was 4.36:1 on the card surface */

        /* The four stat tiles keep their solid dark brand fill in light mode too,
           so their text must stay white (the remap above would darken it). */
        html[data-theme="light"] main .tile-upcoming .text-white,
        html[data-theme="light"] main .tile-package .text-white,
        html[data-theme="light"] main .tile-homework .text-white,
        html[data-theme="light"] main .tile-attendance .text-white { color:#ffffff !important; }

        /* re-assert the dark hero cards (attr+class specificity beats the remap) */
        html[data-theme="light"] main [data-keep-dark].bg-gray-900 { background-color:#0f172a !important; }
        html[data-theme="light"] main [data-keep-dark] .text-white,
        html[data-theme="light"] main [data-keep-dark] .text-gray-100,
        html[data-theme="light"] main [data-keep-dark] .text-gray-300 { color:#ffffff !important; }

        /* indigo-400 on the bg-indigo-500/10 chips reads 3.98:1 in dark mode */
        html[data-theme="dark"] main .text-indigo-400.bg-indigo-500\/10 { color:#a5b4fc; }

        /* keep white text on coloured buttons */
        html[data-theme="light"] main .bg-indigo-600.text-white,
        html[data-theme="light"] main .bg-indigo-500.text-white,
        html[data-theme="light"] main .bg-emerald-600.text-white,
        html[data-theme="light"] main .bg-emerald-500.text-white,
        html[data-theme="light"] main .bg-green-600.text-white,
        html[data-theme="light"] #lesson-modal .bg-indigo-600.text-white { color:#ffffff !important; }
    </style>
</head>
<body class="flex min-h-screen">
    <?php include __DIR__ . '/../components/ui_page_skeleton.php'; ?>
    <?php
    // Include centralized page loader component (if not already included by sidebar)
    if (!defined('PAGE_LOADER_INCLUDED')) {
      define('PAGE_LOADER_INCLUDED', true);
      include __DIR__ . '/../components/page_loader.php';
    }
    ?>

    <?php include __DIR__ . '/components/sidebar.php'; ?>

    <main class="flex-1 p-6 lg:p-8">
        <!-- Header -->
        <?php
        $header_config = [
            'page_title' => 'Dashboard',
            'show_greeting' => true,
            'greeting_text' => $greeting,
            'greeting_name' => $hiName,
            'date_text' => $todayStr,
            'next_lesson_text' => $nextBlurb
        ];
        include __DIR__ . '/components/header.php';
        ?>
        <?php
            // quick helpers reused
            $dueSoon = 0;
            $nowTs   = time();
            foreach ($homeworks as $hw) {
                $dueTs = ts_or_null($hw['due_date'] ?? null);
                if (!$hw['submission_id'] && $dueTs !== null && $dueTs >= $nowTs) { $dueSoon++; }
            }
            $nextCount = is_array($nextLessons ?? null) ? count($nextLessons) : 0;
            $avgScore  = max(0, min(100, (float)$studentScore)); // already computed above

            // Decide if the banner should be taken over by the upcoming lesson
            $joinHero = false;
            $heroSrc = null;
            $heroAlt = null;
            $heroTitle = null;
            $heroText = null;
            $heroCtaLabel = null;
            $heroCtaHref = null;

            if (!empty($upcomingLesson)) {
                $st = new DateTime($upcomingLesson['scheduled_start_time']);
                $joinHero = is_join_window($st); // you already have this helper

                if ($joinHero && !empty($upcomingLesson['hero_image_url'])) {
                    // Use the subject hero image saved by admin (stored as /uploads/subjects/xxx)
                    $heroSrc = $upcomingLesson['hero_image_url']; // absolute web path from root
                    $heroAlt = $upcomingLesson['hero_image_alt'] ?: ($upcomingLesson['subject_name'] . ' illustration');
                }
            }

            // Fallback to the normal marketing banner if no subject hero picked
            if (!$heroSrc) {
                $heroSrc = $banner_img ?? '';   // you already set this from ui_banners
                $heroAlt = $banner_alt ?? '';
            }

            // Build the hero content depending on mode
            if ($joinHero && !empty($upcomingLesson)) {
                $start = new DateTime($upcomingLesson['scheduled_start_time']);
                $end   = new DateTime($upcomingLesson['scheduled_end_time']);
                $tutor = trim(($upcomingLesson['tutor_first'] ?? '') . ' ' . ($upcomingLesson['tutor_last'] ?? ''));
                $heroTitle   = 'Next up: ' . $upcomingLesson['subject_name'];
                $heroText    = 'with ' . $tutor . ' · ' . $start->format('M j, g:i A') . ' – ' . $end->format('g:i A');
                $heroCtaLabel= 'Join Lesson';
                $heroCtaHref = 'lesson_room_live.php?lesson_id=' . (int)$upcomingLesson['id'];
            } else {
                // keep your existing marketing banner copy
                $heroTitle   = $banner_title ?? '';
                $heroText    = $banner_text ?? '';
                $heroCtaLabel= (!empty($banner_href) && !empty($banner_cta)) ? $banner_cta : '';
                $heroCtaHref = (!empty($banner_href) && !empty($banner_cta)) ? $banner_href : '';
            }
        ?>

        <section class="mb-4">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <?php
                    $is_lesson_imminent = false;
                    $heroCardState = null;
                    if ($upcomingLesson) {
                        // Convert UTC times from database to user's local timezone
                        $st = utcToLocal($upcomingLesson['scheduled_start_time'], $userTimezone);
                        $en = utcToLocal($upcomingLesson['scheduled_end_time'], $userTimezone);
                        $heroCardState = get_lesson_card_state($st, $en, $upcomingLesson['status']);
                        // Show hero for lessons within 24 hours OR currently in join window (State B or C)
                        $is_lesson_imminent = is_within_24_hours($st) || $heroCardState['state'] === 'B' || $heroCardState['state'] === 'C';
                    }

                    if ($is_lesson_imminent):
                ?>
                <div class="lg:col-span-5">
                    <div id="lesson-hero" data-keep-dark class="relative overflow-hidden rounded-2xl bg-gray-900 border ring-1 ring-white/5 p-8 md:p-12 flex flex-col items-center justify-center text-center">
                        <div id="lesson-hero-bg" class="absolute inset-0 w-full h-full object-cover opacity-20 pointer-events-none select-none bg-cover bg-center"></div>
                        <div class="hero-tint absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/80 to-transparent"></div>

                        <div class="relative z-10">
                            <h2 id="lesson-status-text" class="text-2xl md:text-3xl font-bold text-white transition-all">
                                <?= $heroCardState['state'] === 'C' ? 'Lesson In Progress' : 'Upcoming Lesson' ?>
                            </h2>
                            <p id="countdown" class="text-5xl md:text-6xl font-mono font-bold text-white my-3 transition-all"></p>
                            <p class="text-lg text-gray-300"><?= h($upcomingLesson['subject_name']) ?></p>
                            <p id="lesson-sub-text" class="text-gray-400 mt-1 transition-all"></p>
                            
                            <?php if ($heroCardState['can_join']): ?>
                            <a id="join-button" href="<?= $heroCardState['button_href'] . (int)$upcomingLesson['id'] ?>" 
                               target="_blank" rel="noopener noreferrer"
                               class="btn mt-6 inline-flex items-center gap-3 px-8 py-3 rounded-xl text-white font-semibold text-lg transition-all duration-300 <?= $heroCardState['button_class'] ?>">
                                <?= h($heroCardState['button_text']) ?>
                            </a>
                            <?php else: ?>
                            <button id="join-button" class="btn mt-6 inline-flex items-center gap-3 px-8 py-3 rounded-xl text-white font-semibold text-lg transition-all duration-300 lesson-details-btn <?= $heroCardState['button_class'] ?>" 
                                    data-lesson-id="<?= (int)$upcomingLesson['id'] ?>">
                                <?= h($heroCardState['button_text']) ?>
                            </button>
                            <?php endif; ?>
                            
                            <div class="mt-6 text-sm text-gray-400 space-y-1">
                                <p>
                                    <?php
                                    $tutorPhotoSrc = resolve_profile_photo($upcomingLesson['tutor_photo'] ?? null);
                                    if (!$tutorPhotoSrc) {
                                        $tutorInitials = strtoupper(substr($upcomingLesson['tutor_first'] ?? 'T', 0, 1) . substr($upcomingLesson['tutor_last'] ?? 'R', 0, 1));
                                        $tutorPhotoSrc = '../assets/avatar.php?s=32&bg=4b5563&fg=ffffff&text=' . urlencode($tutorInitials);
                                    }
                                    ?>
                                    <img src="<?= h($tutorPhotoSrc) ?>" class="w-6 h-6 rounded-full inline-block mr-2 border border-gray-600" alt="Tutor avatar">
                                    Tutor: <?= h($upcomingLesson['tutor_first'] . ' ' . $upcomingLesson['tutor_last']) ?> 
                                    <span id="tutor-status" class="inline-block w-2.5 h-2.5 rounded-full bg-gray-500 ml-1.5 align-middle"></span>
                                </p>
                                <?php if(!empty($upcomingLesson['tutor_notes'])): ?>
                                <p id="prepare-notes" class="text-gray-300 bg-white/5 px-3 py-2 rounded-md inline-block">
                                    <svg class="w-4 h-4 inline-block mr-2" data-lucide="clipboard-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clipboard-check"></use></svg>
                                    <strong>Notes:</strong> <?= h($upcomingLesson['tutor_notes']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="lg:col-span-3 flex flex-col">
                <?php if (!empty($banner_img) && !empty($banner_title)): ?>
                    <div class="relative overflow-hidden rounded-2xl bg-gray-900 border border-white/10 banner-shell flex-1">
                    <img src="<?= h($banner_img) ?>" alt="" class="absolute inset-0 w-full h-full object-cover banner-img pointer-events-none select-none" loading="lazy">
                    <div class="banner-tint absolute inset-0 bg-gradient-to-r from-gray-900/80 via-gray-900/55 to-gray-900/30"></div>
                    <div class="banner-shadow absolute inset-0 shadow-[inset_0_-80px_120px_-40px_rgba(17,24,39,0.9)]"></div>
                    <div class="relative px-8 py-12 md:px-12 md:py-16 h-full flex items-center">
                        <div class="max-w-3xl">
                        <h3 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight banner-text"><?= h($banner_title) ?></h3>
                        <?php if (!empty($banner_text)): ?><p class="text-base md:text-lg text-gray-100/90 mt-2 banner-text"><?= h($banner_text) ?></p><?php endif; ?>
                        <?php if (!empty($banner_href) && !empty($banner_cta)): ?>
                            <a href="<?= h($banner_href) ?>" class="mt-6 btn btn-primary">
                            <?= h($banner_cta) ?>
                            <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                            </a>
                        <?php endif; ?>
                        </div>
                    </div>
                    </div>
                <?php endif; ?>
                </div>
                <div class="lg:col-span-2 grid grid-cols-2 gap-4">
                    <!-- Tile 1: Upcoming Lesson (Gold) -->
                    <div class="tile-upcoming group relative rounded-xl p-5 border border-amber-500/30 bg-gradient-to-br from-amber-500/20 to-amber-500/10 hover:-translate-y-0.5 transition">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/25 text-amber-200 ring-1 ring-amber-400/30">
                        <svg class="w-5 h-5" data-lucide="calendar-clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar-clock"></use></svg>
                        </span>
                        <?php if ($upcomingLesson):
                        // Convert UTC times from database to user's local timezone
                        $st = utcToLocal($upcomingLesson['scheduled_start_time'], $userTimezone);
                        $en = utcToLocal($upcomingLesson['scheduled_end_time'], $userTimezone);
                        $cardState = get_lesson_card_state($st, $en, $upcomingLesson['status']);
                        ?>
                        <span class="text-[11px] font-medium px-2 py-1 rounded <?= $cardState['can_join'] ? 'bg-emerald-500/20 text-emerald-200 ring-emerald-500/20' : 'bg-amber-500/20 text-amber-200 ring-amber-500/20' ?> ring-1">
                            <?= h(human_until($st)) ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <p class="mt-3 text-xs text-amber-100/80">Upcoming Lesson</p>
                    <p class="text-base md:text-lg font-semibold text-white leading-snug mt-1 line-clamp-2">
                        <?php if ($upcomingLesson): ?>
                        <?= h($upcomingLesson['subject_name']) ?> · <?= $st->format('M j, g:i A') ?>
                        <?php else: ?>
                        No upcoming lesson
                        <?php endif; ?>
                    </p>

                    <?php if ($upcomingLesson): ?>
                        <div class="mt-3 flex items-center gap-2">
                        <?php if ($cardState['can_join']): ?>
                            <a href="<?= $cardState['button_href'] . (int)$upcomingLesson['id'] ?>"
                            target="_blank" rel="noopener noreferrer"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md <?= $cardState['button_class'] ?> text-white">
                            <?= h($cardState['button_text']) ?>
                            </a>
                        <?php else: ?>
                            <button class="lesson-details-btn text-xs font-semibold px-3 py-1.5 rounded-md bg-gray-900/40 hover:bg-gray-900/60 text-amber-200 border border-amber-400/20"
                                    data-lesson-id="<?= (int)$upcomingLesson['id'] ?>">
                            <?= h($cardState['button_text']) ?>
                            </button>
                        <?php endif; ?>
                        <span class="text-[11px] text-amber-100/70"><?= $st->format('g:i A') ?>–<?= $en->format('g:i A') ?></span>
                        </div>
                    <?php endif; ?>
                    </div>

                    <!-- Tile 2: Package (Emerald) -->
                    <div class="tile-package group relative rounded-xl p-5 border border-emerald-500/20 bg-gradient-to-br from-emerald-500/15 to-emerald-500/5 hover:translate-y-[-2px] transition">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-400/20">
                            <svg class="w-5 h-5" data-lucide="package" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-package"></use></svg>
                            </span>
                        <?php if ($activePackage): 
                            $total = (int)$activePackage['total_lessons'];
                            $remain = (int)$activePackage['remaining_lessons'];
                            $used = max(0, $total - $remain);
                            $pct = $total > 0 ? round(($used / $total) * 100) : 0;
                        ?>
                            <span class="text-[11px] font-medium px-2 py-1 rounded bg-emerald-500/15 text-emerald-200 ring-1 ring-white/10"><?= $pct ?>%</span>
                        <?php endif; ?>
                        </div>
                        <p class="mt-3 text-xs text-emerald-200/80">Active Package</p>
                        <p class="text-base md:text-lg font-semibold text-white leading-snug mt-1 line-clamp-2">
                        <?php if ($activePackage): ?>
                            <?= h($activePackage['name']) ?> · <?= $used ?>/<?= $total ?> used
                        <?php else: ?>
                            None
                        <?php endif; ?>
                        </p>
                    </div>

                    <!-- Tile 3: Recordings (Fuchsia repurposed) -->
                    <div class="tile-homework group relative rounded-xl p-5 border border-fuchsia-500/20 bg-gradient-to-br from-fuchsia-500/15 to-fuchsia-500/5 hover:translate-y-[-2px] transition">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-fuchsia-500/20 text-fuchsia-300 ring-1 ring-fuchsia-400/20">
                            <svg class="w-5 h-5" data-lucide="video" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
                            </span>
                        <a href="recordings.php" class="text-[11px] font-semibold px-3 py-1 rounded bg-white/20 text-white ring-1 ring-white/30 hover:bg-white/30 transition">
                            View All
                        </a>
                        </div>
                        <p class="mt-3 text-xs text-fuchsia-200/80">Recordings</p>
                        <p class="text-2xl font-extrabold text-white mt-1"><?= (int)$recording_count ?></p>
                        <?php if ($latestRecording): ?>
                        <p class="text-sm text-white/90 mt-2"><?= h($latestRecording['subject_name']) ?> · <?= h(trim(($latestRecording['tutor_first'] ?? '').' '.($latestRecording['tutor_last'] ?? ''))) ?></p>
                        <p class="text-xs text-white/70"><?= h($latestRecording['recorded_at'] ? (new DateTime($latestRecording['recorded_at']))->format('M j, g:i a') : 'Most recent') ?></p>
                        <?php else: ?>
                        <p class="text-sm text-white/80 mt-2">No recordings yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Tile 4: Attendance (Sky) -->
                    <div class="tile-attendance group relative rounded-xl p-5 border border-sky-500/20 bg-gradient-to-br from-sky-500/15 to-sky-500/5 hover:translate-y-[-2px] transition">
                        <div class="flex items-center justify-between">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-500/20 text-sky-300 ring-1 ring-sky-400/20">
                            <svg class="w-5 h-5" data-lucide="user-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-user-check"></use></svg>
                        </span>
                        <span class="text-[11px] font-medium px-2 py-1 rounded bg-sky-500/15 text-sky-200 ring-1 ring-white/10">
                            <?= $attTotal ?> total
                        </span>
                        </div>
                        <p class="mt-3 text-xs text-sky-200/80">Attendance</p>
                        <p class="text-2xl font-extrabold text-white mt-1"><?= (int)$attendancePct ?>%</p>
                        <p class="text-[11px] text-sky-200/70 mt-0.5"><?= (int)$attendedCount ?> of <?= (int)$attTotal ?> attended</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Top Stats -->
        
        <!-- Main Grid -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <!-- Left (xl: span 2) -->
            <div class="xl:col-span-2 space-y-4">

                <!-- Homework -->
                <div class="glass p-6 rounded-xl border border-gray-800">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-lg font-bold text-white">Pending Homework</h3>
                        <!-- <a href="homeworks.php" class="btn-clear flex items-center gap-1">
                            View All
                            <svg class="w-4 h-4" data-lucide="arrow-right" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                        </a> -->
                        <button onclick="window.location.href='homeworks.php'" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-indigo-400 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 rounded-md transition-colors duration-200">
                            View All
                            <svg class="w-4 h-4" data-lucide="arrow-right" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                        </button>
                    </div>
                    <div class="space-y-3 homework-container">
                        <?php if (empty($homeworks)): ?>
                            <div class="text-center py-8">
                                <div class="mx-auto w-16 h-16 bg-emerald-500/10 text-emerald-400 flex items-center justify-center rounded-full">
                                    <svg class="w-8 h-8" data-lucide="check-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-check"></use></svg>
                                </div>
                                <p class="mt-4 font-semibold text-white">All caught up!</p>
                                <p class="text-sm text-gray-400">No pending homework assignments.</p>
                            </div>
                        <?php else: 
                            $homeworksCount = count($homeworks);
                            $itemsPerPage = 5;
                            $showMore = $homeworksCount > $itemsPerPage;
                            
                            foreach (array_slice($homeworks, 0, $itemsPerPage) as $index => $hw):
                                $pill = hwStatusPill($hw);
                                $dueInfo = formatDueDate($hw['due_date']);
                        ?>
                        <div class="row-item homework-item group flex items-center gap-1 p-3 rounded-lg transition-all duration-200 border border-transparent hover:border-indigo-500/20" <?= $index >= $itemsPerPage ? 'style="display: none;"' : '' ?>>
                            <div class="hw-icon flex-shrink-0 w-10 h-10 bg-fuchsia-500/10 text-fuchsia-400 flex items-center justify-center rounded-lg">
                                <svg class="w-5 h-5" data-lucide="notebook-pen" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-notebook-pen"></use></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-white truncate"><?= h($hw['title']) ?></p>
                                <p class="text-xs text-gray-400">by <?= h($hw['tutor_first'] . ' ' . $hw['tutor_last']) ?></p>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span class="hidden md:inline-flex items-center text-xs font-medium py-1 px-2.5 rounded-md ring-1 ring-inset <?= $dueInfo['class'] ?>">
                                    <?= h($dueInfo['text']) ?>
                                </span>
                                <span class="text-xs font-medium py-1 px-2.5 rounded-full <?= $pill['class'] ?>">
                                    <?= h($pill['text']) ?>
                                </span>
                                <button class="hw-preview-btn font-semibold text-indigo-400 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 px-4 py-2 rounded-md transition" data-homework-id="<?= (int)$hw['id'] ?>">
                                    View
                                </button>
                            </div>
                        </div>
                        <?php 
                            endforeach; 
                            
                            // Add remaining items as hidden
                            if ($showMore) {
                                foreach (array_slice($homeworks, $itemsPerPage) as $hw) {
                                    $pill = hwStatusPill($hw);
                                    $dueInfo = formatDueDate($hw['due_date']);
                        ?>
                        <div class="homework-item hidden group flex items-center gap-4 bg-gray-800/50 hover:bg-gray-800 p-3 rounded-lg transition-all duration-200 border border-transparent hover:border-indigo-500/20">
                            <div class="hw-icon flex-shrink-0 w-10 h-10 bg-fuchsia-500/10 text-fuchsia-400 flex items-center justify-center rounded-lg">
                                <svg class="w-5 h-5" data-lucide="notebook-pen" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-notebook-pen"></use></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-white truncate"><?= h($hw['title']) ?></p>
                                <p class="text-xs text-gray-400">by <?= h($hw['tutor_first'] . ' ' . $hw['tutor_last']) ?></p>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span class="hidden md:inline-flex items-center text-xs font-medium py-1 px-2.5 rounded-md ring-1 ring-inset <?= $dueInfo['class'] ?>">
                                    <?= h($dueInfo['text']) ?>
                                </span>
                                <span class="text-xs font-medium py-1 px-2.5 rounded-full <?= $pill['class'] ?>">
                                    <?= h($pill['text']) ?>
                                </span>
                                <button class="hw-preview-btn font-semibold text-indigo-400 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 px-4 py-2 rounded-md transition" data-homework-id="<?= (int)$hw['id'] ?>">
                                    View
                                </button>
                            </div>
                        </div>
                        <?php 
                                }
                            }
                            endif; 
                        ?>
                    </div>
                    <?php if (!empty($homeworks) && $showMore): ?>
                    <div class="mt-4 text-center">
                        <button id="show-more-btn" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-indigo-400 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 rounded-md transition-colors duration-200">
                            Load More
                            <svg class="w-4 h-4 ml-2" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Lessons -->
                <div class="glass p-6 rounded-lg">
                    <h3 class="text-lg font-semibold text-white mb-4">Recent Lessons</h3>
                    <div class="space-y-4">
                        <?php if (empty($previousLessons)): ?>
                            <p class="text-gray-400">No lesson history yet.</p>
                        <?php else: foreach ($previousLessons as $lsn):
                            $statusClass = $lsn['status'] === 'Completed' ? 'text-green-400' :
                                           (strpos($lsn['status'], 'Cancelled') !== false ? 'text-red-400' :
                                           ($lsn['status'] === 'NoShow' ? 'text-amber-400' : 'text-gray-300'));
                            $stPrev = new DateTime($lsn['scheduled_start_time']);
                            $tutorName = $lsn['tutor_first'] . ' ' . $lsn['tutor_last'];
                            $unrated = ($lsn['status'] === 'Completed' && !$lsn['rating_id']); // display hint; auto-prompt uses AJAX logic
                        ?>
                        <div class="row-item p-4 rounded-lg flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-white"><?= h($lsn['subject_name']) ?></p>
                                <p class="text-sm text-gray-400">with <?= h($tutorName) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-gray-300"><?= $stPrev->format('M j, Y') ?></p>
                                <div class="flex items-center gap-3 justify-end mt-1">
                                    <span class="text-sm font-medium <?= $statusClass ?>"><?= h($lsn['status']) ?></span>
                                    <button class="text-sm font-semibold text-indigo-400 hover:text-indigo-300 lesson-details-btn" data-lesson-id="<?= (int)$lsn['id'] ?>">Details</button>
                                    <?php if ($unrated): ?>
                                        <button class="ml-2 text-sm font-semibold text-amber-400 hover:text-amber-300 rate-btn"
                                                data-lesson-id="<?= (int)$lsn['id'] ?>"
                                                data-subject="<?= h($lsn['subject_name']) ?>"
                                                data-tutor="<?= h($tutorName) ?>">
                                            Rate
                                        </button>
                                    <?php elseif ($lsn['rating_value']): ?>
                                        <span class="text-xs text-gray-400">Rated: <?= (int)$lsn['rating_value'] ?>/5</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

            </div>

            <!-- Right -->
            <div class="xl:col-span-1 space-y-4">
                <!-- Student Score Donut -->
                <div class="glass p-6 rounded-xl border border-gray-800 relative overflow-hidden">
                    <!-- Subtle background glow effect -->
                    <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-indigo-500/10 blur-2xl rounded-full pointer-events-none"></div>

                    <!-- Header with Lucide Icon -->
                    <div class="flex items-center justify-between mb-6 relative z-10">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500/20 to-fuchsia-500/20 text-indigo-300 ring-1 ring-indigo-500/20">
                                <svg class="w-5 h-5" data-lucide="award" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-award"></use></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white leading-none">Avg. Score</h3>
                                <p class="text-xs text-gray-400 mt-1">Based on homework</p>
                            </div>
                        </div>
                        <span class="custom-text-badge">
                            <?= (int)$gradedCount ?> graded
                        </span>
                    </div>

                    <!-- Chart Container -->
                    <div class="h-64 relative z-10">
                        <canvas id="scoreDonut"
                                data-score="<?= number_format($studentScore, 2, '.', '') ?>"></canvas>
                    </div>
                </div>

                <!-- Recent Homework Grades (sparkline) -->
                <div class="glass p-6 rounded-lg">
                    <h3 class="text-lg font-semibold text-white mb-4">Recent Homework Grades</h3>
                    <div class="h-72">
                        <canvas id="gradesChart"></canvas>
                    </div>
                </div>

                <!-- Package -->
                <div class="glass p-6 rounded-lg">
                    <h3 class="text-lg font-semibold text-white mb-4">Package Progress</h3>
                    <?php if ($activePackage): 
                        $total = (int)$activePackage['total_lessons'];
                        $remain = (int)$activePackage['remaining_lessons'];
                        $used = max(0, $total - $remain);
                        $pct = $total > 0 ? round(($used / $total) * 100) : 0;
                    ?>
                    <p class="text-sm text-gray-400 mb-2"><?= h($activePackage['name']) ?> · <?= (int)$activePackage['lessons_per_week'] ?> lessons/week</p>
                    <div class="w-full bg-gray-800 rounded-full h-3 overflow-hidden">
                        <div class="bg-indigo-500 h-3" style="width: <?= $pct ?>%"></div>
                    </div>
                    <p class="text-sm text-gray-400 mt-2"><?= $used ?>/<?= $total ?> used · <?= $remain ?> remaining</p>
                    <p class="text-xs text-gray-500 mt-1">From <?= (new DateTime($activePackage['start_date']))->format('M j, Y') ?> to <?= (new DateTime($activePackage['end_date']))->format('M j, Y') ?></p>
                    <?php else: ?>
                        <p class="text-gray-400">No active package.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Lesson Details Modal -->
    <div id="lesson-modal" class="modal fixed inset-0 bg-black/70 z-50 items-center justify-center p-4">
        <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-2xl p-6 relative">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white" data-close-lesson>
                <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <div id="lesson-modal-body" class="space-y-4">
                <!-- filled dynamically -->
            </div>
        </div>
    </div>

    <!-- Homework Preview Modal -->
    <div id="homework-modal" class="modal fixed inset-0 bg-black/70 z-50 items-center justify-center p-4">
        <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-2xl p-6 relative">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white" data-close-homework>
                <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <div id="homework-modal-body" class="space-y-4">
                <!-- filled dynamically -->
            </div>
        </div>
    </div>

    <!-- Rating Modal -->
    <div id="rating-modal" class="modal fixed inset-0 bg-black/70 z-50 items-center justify-center p-4">
        <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-lg p-6 relative">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white" data-close-rating>
                <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <h3 class="text-xl font-bold text-white mb-1">Rate your lesson</h3>
            <p class="text-gray-400 mb-4" id="rating-meta"></p>
            <form id="rating-form" class="space-y-4">
                <input type="hidden" name="lesson_id" id="rating-lesson-id">
                <div class="flex items-center gap-2" id="rating-stars" aria-label="Rating">
                    <!-- Stars injected by JS -->
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Comments (optional)</label>
                    <textarea name="comment" id="rating-comment" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-lg p-3 text-white placeholder-gray-400" placeholder="How was the session?"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="py-2 px-4 bg-gray-700 hover:bg-gray-600 rounded-lg text-white" data-close-rating>Later</button>
                    <button type="submit" class="py-2 px-4 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white font-semibold">Submit</button>
                </div>
            </form>
        </div>
    </div>

<script>
// Note: Theme toggle is now handled by header.php

// Chart.js: Recent Homework Grades
(function(){
    const el = document.getElementById('gradesChart');
    if (!el) return;
    const labels = <?= json_encode($gradeLabels) ?>;
    const dataVals = <?= json_encode($gradeValues) ?>;
    new Chart(el, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Grade (%)',
                data: dataVals,
                fill: true,
                backgroundColor: 'rgba(99, 102, 241, 0.2)',
                borderColor: 'rgba(99, 102, 241, 1)',
                tension: 0.35,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true, max: 100,
                    ticks: { color: '#9ca3af' },
                    grid: { color: 'rgba(255,255,255,0.1)' }
                },
                x: {
                    ticks: { color: '#9ca3af' },
                    grid: { display: false }
                }
            },
            plugins: { legend: { display: false } }
        }
    });
})();

// Chart.js: Student Score Donut with center text (IMPROVED)
(function(){
    const el = document.getElementById('scoreDonut');
    if (!el) return;
    
    const score = Math.max(0, Math.min(100, parseFloat(el.dataset.score || '0')));
    const ctx = el.getContext('2d');
    let chart = null;

    // Function to get colors based on score and theme
    function getScoreColors() {
        const style = getComputedStyle(document.documentElement);
        const isLight = document.body.classList.contains('theme-light');
        
        // Get theme colors
        const remainingColor = style.getPropertyValue('--donut-remaining').trim();
        
        // Create gradient for the score based on performance
        const gradient = ctx.createLinearGradient(0, 0, 0, el.clientHeight);
        if (score >= 80) {
            gradient.addColorStop(0, '#34d399'); // emerald-400
            gradient.addColorStop(1, '#059669'); // emerald-600
        } else if (score >= 60) {
            gradient.addColorStop(0, '#fcd34d'); // amber-300
            gradient.addColorStop(1, '#f59e0b'); // amber-500
        } else {
            gradient.addColorStop(0, '#fb7185'); // rose-400
            gradient.addColorStop(1, '#e11d48'); // rose-600
        }

        return {
            gradient,
            backgroundColor: [gradient, remainingColor],
            textColor: isLight ? '#1C1E4F' : '#fff',
            secondaryTextColor: isLight ? '#4b5563' : '#9ca3af'
        };
    }

    // Function to update chart colors
    function updateChart() {
        if (!chart) return;
        const colors = getScoreColors();
        chart.data.datasets[0].backgroundColor = colors.backgroundColor;
        chart.update();
    }

    // Center text plugin
    const pluginCenter = {
        id: 'centerText',
        afterDraw(chart) {
            const {ctx, chartArea: {left, top, width, height}} = chart;
            const colors = getScoreColors();
            
            ctx.save();
            // Score Percentage
            ctx.font = '700 36px Inter, sans-serif';
            ctx.fillStyle = colors.textColor;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(`${score.toFixed(0)}%`, left + width/2, top + height/2 - 5);
            
            // "Average Score" label
            ctx.font = '500 13px Inter, sans-serif';
            ctx.fillStyle = colors.secondaryTextColor;
            ctx.fillText('Avg. Score', left + width/2, top + height/2 + 25);
            ctx.restore();
        }
    };

    // Initialize chart
    function initChart() {
        const colors = getScoreColors();
        
        chart = new Chart(el, {
            type: 'doughnut',
            data: {
                labels: ['Score', 'Remaining'],
                datasets: [{
                    data: [score, Math.max(0, 100 - score)],
                    backgroundColor: colors.backgroundColor,
                    borderWidth: 0,
                    borderRadius: 12,
                    spacing: 4,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: { 
                    legend: { display: false },
                    tooltip: { enabled: false }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            },
            plugins: [pluginCenter]
        });
    }

    // Listen for theme changes
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === 'class') {
                setTimeout(updateChart, 10);
            }
        });
    });

    // Observe both html and body for theme changes
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class']
    });
    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class']
    });

    // Initialize the chart
    initChart();
})();


// Utilities
function openModal(id){ document.getElementById(id)?.classList.add('show'); }
function closeModal(id){ document.getElementById(id)?.classList.remove('show'); }
document.querySelector('[data-close-lesson]')?.addEventListener('click', ()=>closeModal('lesson-modal'));
document.querySelector('[data-close-homework]')?.addEventListener('click', ()=>closeModal('homework-modal'));
document.querySelectorAll('[data-close-rating]')?.forEach(btn=>btn.addEventListener('click', ()=>closeModal('rating-modal')));
window.addEventListener('click', (e)=>{
    if (e.target === document.getElementById('lesson-modal')) closeModal('lesson-modal');
    if (e.target === document.getElementById('homework-modal')) closeModal('homework-modal');
    if (e.target === document.getElementById('rating-modal')) closeModal('rating-modal');
});

// Lesson details (upcoming + recent)
document.querySelectorAll('.lesson-details-btn').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
        const id = btn.dataset.lessonId;
        if (!id) return;

        const modalBody = document.getElementById('lesson-modal-body');
        modalBody.innerHTML = `
            <div class="space-y-4">
                <div class="skeleton" style="height:28px;width:60%"></div>
                <div class="flex items-center gap-3">
                    <div class="skeleton" style="height:40px;width:40px;border-radius:9999px"></div>
                    <div class="skeleton" style="height:18px;width:45%"></div>
                </div>
                <div class="skeleton" style="height:16px;width:100%"></div>
                <div class="skeleton" style="height:16px;width:85%"></div>
                <div class="skeleton" style="height:120px;width:100%;margin-top:8px"></div>
            </div>
        `;
        openModal('lesson-modal');

        const res = await fetch(`<?= basename(__FILE__) ?>?ajax=lesson_detail&lesson_id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
        const json = await res.json();
        if (!json.success) { 
            modalBody.innerHTML = `<p class="text-center text-red-400 p-8">${json.message || 'Unable to load lesson'}</p>`;
            return; 
        }
        const {lesson, topics, subtopics, recording} = json.data;

        // Database stores UTC time, so append 'Z' to treat as UTC in JavaScript
        const st = new Date(lesson.scheduled_start_time.replace(' ', 'T') + 'Z');
        const en = new Date(lesson.scheduled_end_time.replace(' ', 'T') + 'Z');
        const dateStr = st.toLocaleDateString(undefined, {year:'numeric', month:'long', day:'numeric'});
        const timeStr = `${st.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'})} - ${en.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'})}`;

        const initials = (lesson.tutor_first?.[0]||'T').toUpperCase() + (lesson.tutor_last?.[0]||'R').toUpperCase();
        const avatar = lesson.tutor_photo || `../assets/avatar.php?s=40&bg=7c3aed&fg=ffffff&text=${initials}`;

        let topicsHtml = '';
        if ((topics && topics.length) || (subtopics && subtopics.length)) {
            topicsHtml += `<div class="space-y-1">
                <p class="font-semibold text-white">Topics Covered</p>
                ${topics?.length ? `<div class="flex flex-wrap gap-2">` + topics.map(t=>`<span class="badge bg-indigo-500/10 text-indigo-300">${t}</span>`).join('') + `</div>` : `<p class="text-sm text-gray-400">No topics recorded.</p>`}
                ${subtopics?.length ? `<p class="text-sm text-gray-400 mt-2">Subtopics: ${subtopics.join(', ')}</p>` : ``}
            </div>`;
        }

        let recHtml = '';
        if (recording?.url) {
            const mins = Math.floor((recording.duration||0)/60);
            const secs = (recording.duration||0)%60;
            const dur = recording.duration ? `${mins}m ${secs}s` : '—';
            const canInline = /\.(mp4|webm|ogg)$/i.test(recording.url);
            recHtml = `<div class="space-y-2">
                <p class="font-semibold text-white">Recording</p>
                ${canInline ? `<video src="${recording.url}" controls class="w-full rounded-lg bg-black/30"></video>`
                            : `<a href="${recording.url}" target="_blank" class="text-indigo-400 hover:text-indigo-300 underline">Open recording</a>`}
                <p class="text-xs text-gray-400">Duration: ${dur}</p>
            </div>`;
        }

        document.getElementById('lesson-modal-body').innerHTML = `
            <div class="flex items-center gap-3">
                <img src="${avatar}" class="rounded-lg w-10 h-10 object-cover" alt="Tutor">
                <div>
                    <h4 class="text-xl font-bold text-white">${lesson.subject_name}</h4>
                    <p class="text-sm text-gray-300">with ${lesson.tutor_first} ${lesson.tutor_last}</p>
                </div>
                <span class="ml-auto text-xs font-medium px-2 py-1 rounded ${lesson.status==='Completed'?'bg-emerald-500/10 text-emerald-300': (lesson.status?.startsWith('Cancelled')?'bg-rose-500/10 text-rose-300': lesson.status==='NoShow'?'bg-amber-500/10 text-amber-300':'bg-indigo-500/10 text-indigo-300')}">${lesson.status}</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-gray-800 rounded-lg p-4">
                    <p class="text-xs text-gray-400">Date</p>
                    <p class="text-white font-semibold">${dateStr}</p>
                </div>
                <div class="bg-gray-800 rounded-lg p-4">
                    <p class="text-xs text-gray-400">Time</p>
                    <p class="text-white font-semibold">${timeStr}</p>
                </div>
            </div>

            ${lesson.tutor_notes ? `<div><p class="font-semibold text-white">Notes</p><p class="text-sm text-gray-300 mt-1">${lesson.tutor_notes}</p></div>` : ``}

            ${topicsHtml}
            ${recHtml}
        `;
        openModal('lesson-modal');
    });
});

// Homework preview buttons (IMPROVED with actionable modal)
document.querySelectorAll('.hw-preview-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.homeworkId;
        if (!id) return;
        
        const modalBody = document.getElementById('homework-modal-body');
        modalBody.innerHTML = `
            <div class="space-y-4">
                <div class="skeleton" style="height:28px;width:55%"></div>
                <div class="skeleton" style="height:16px;width:100%"></div>
                <div class="skeleton" style="height:16px;width:90%"></div>
                <div class="skeleton" style="height:80px;width:100%;margin-top:8px"></div>
                <div class="skeleton" style="height:36px;width:130px;margin-top:8px"></div>
            </div>`;
        openModal('homework-modal');

        const res = await fetch(`<?= basename(__FILE__) ?>?ajax=homework_preview&homework_id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
        const json = await res.json();

        if (!json.success) { 
            modalBody.innerHTML = `<p class="text-center text-red-400 p-8">${json.message || 'Unable to load homework'}</p>`;
            return; 
        }

        const {homework, submission, attachments} = json.data;

        // Database stores UTC time, so append 'Z' to treat as UTC in JavaScript
        const due = new Date(homework.due_date.replace(' ', 'T') + 'Z');
        const dueStr = due.toLocaleString([], {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});

        let attachHtml = '';
        if (attachments && attachments.length) {
            attachHtml = `<div class="space-y-3">
                <h4 class="font-semibold text-white text-base border-b border-gray-700 pb-2">Attachments</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">` +
                attachments.map(a => {
                    const name = a.file_name || 'Attachment';
                    // Use a Lucide icon for generic files
                    return `<a href="${a.file_url}" target="_blank" rel="noopener" class="flex items-center gap-3 bg-gray-800 p-3 rounded-lg hover:bg-gray-700 transition">
                                <svg class="w-5 h-5 text-indigo-400 flex-shrink-0" data-lucide="file-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file-down"></use></svg>
                                <span class="text-sm text-gray-300 truncate">${name}</span>
                            </a>`;
                }).join('') +
                `</div></div>`;
        }

        const subHtml = submission ? `
            <div class="space-y-3">
                <h4 class="font-semibold text-white text-base border-b border-gray-700 pb-2">Your Submission</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
                    <div class="bg-gray-800 rounded-lg p-3"><p class="text-xs text-gray-400">Status</p><p class="text-white font-semibold">${submission.status}</p></div>
                    <div class="bg-gray-800 rounded-lg p-3"><p class="text-xs text-gray-400">Grade</p><p class="text-white font-semibold">${submission.grade ?? '—'}</p></div>
                    <div class="bg-gray-800 rounded-lg p-3"><p class="text-xs text-gray-400">Submitted</p><p class="text-white font-semibold">${submission.submission_date ? new Date(submission.submission_date.replace(' ','T') + 'Z').toLocaleDateString() : '—'}</p></div>
                </div>
                ${submission.content ? `<div><p class="font-semibold text-white mt-2">Submission Notes</p><div class="mt-1 p-3 bg-gray-800 rounded-lg text-sm text-gray-300 whitespace-pre-wrap">${submission.content}</div></div>` : ``}
            </div>` 
            : `<div class="text-center p-6 bg-gray-800 rounded-lg border border-dashed border-gray-700">
                <p class="font-semibold text-white">This homework has not been submitted.</p>
               </div>`;

        // --- Action Button Logic ---
        const actionButtonHtml = !submission 
            ? `<a href="homeworks.php?submit=${homework.id}" class="w-full text-center py-3 px-6 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white font-semibold transition flex items-center justify-center gap-2">
                 <svg class="w-4 h-4" data-lucide="send" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-send"></use></svg> Submit Homework
               </a>`
            : `<a href="homeworks.php" class="w-full text-center py-3 px-6 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-white font-semibold transition flex items-center justify-center gap-2">
                 <svg class="w-4 h-4" data-lucide="arrow-right" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg> View more homeworks
               </a>`;

        modalBody.innerHTML = `
            <div class="flex items-start gap-4 pb-4 border-b border-gray-800">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-indigo-400">Homework Details</p>
                    <h3 class="text-2xl font-bold text-white mt-1">${homework.title}</h3>
                    <p class="text-sm text-gray-400 mt-1">Assigned by ${homework.tutor_first} ${homework.tutor_last} · <span class="font-medium text-amber-400">Due ${dueStr}</span></p>
                </div>
            </div>
            <div class="space-y-6 py-4 max-h-[60vh] overflow-y-auto pr-2">
                ${homework.description ? `<div><h4 class="font-semibold text-white text-base">Description</h4><p class="text-sm text-gray-300 mt-2 whitespace-pre-wrap bg-gray-800/50 p-3 rounded-md">${homework.description}</p></div>` : ``}
                ${attachHtml}
                ${subHtml}
            </div>
            <div class="pt-4 border-t border-gray-800">
                ${actionButtonHtml}
            </div>
        `;
    });
});
// ---------- Homework Pagination ----------
(function() {
    const showMoreBtn = document.getElementById('show-more-btn');
    if (!showMoreBtn) return;

    const homeworkContainer = document.querySelector('.homework-container');
    const homeworkItems = document.querySelectorAll('.homework-item');
    let visibleCount = 5; // Initial number of visible items
    const itemsPerPage = 5; // Number of items to show per click

    showMoreBtn.addEventListener('click', () => {
        // Show next batch of items
        const nextBatch = Array.from(homeworkItems).slice(visibleCount, visibleCount + itemsPerPage);
        
        if (nextBatch.length > 0) {
            nextBatch.forEach(item => {
                item.classList.remove('hidden');
                item.style.display = 'flex'; // Match the flex display of other items
            });
            
            visibleCount += nextBatch.length;
            
            // Hide the button if we've reached the end
            if (visibleCount >= homeworkItems.length) {
                showMoreBtn.style.display = 'none';
            }
        }
    });
})();

// ---------- Ratings ----------

function injectStars(container, initial=0) {
    container.innerHTML = '';
    for (let i=1; i<=5; i++) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 24 24');
        svg.classList.add('star', 'rating-star', i<=initial ? 'text-yellow-400' : 'text-gray-400');
        svg.dataset.value = String(i);
        svg.innerHTML = `<path d="m12 17.27 6.18 3.73-1.64-7.03L21 9.24l-7.19-.62L12 2 10.19 8.62 3 9.24l4.46 4.73L5.82 21z" stroke-width="1.5"></path>`;
        container.appendChild(svg);
    }
    
    // Initialize the hidden input if it doesn't exist
    let hiddenInput = document.getElementById('rating-form-rating');
    if (!hiddenInput) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'rating';
        hiddenInput.id = 'rating-form-rating';
        hiddenInput.value = '0';
        document.getElementById('rating-form').appendChild(hiddenInput);
    } else {
        hiddenInput.value = initial;
    }
}

function openRatingModal(lessonId, subjectName, tutorName) {
    document.getElementById('rating-lesson-id').value = lessonId;
    document.getElementById('rating-comment').value = '';
    document.getElementById('rating-meta').textContent = `${subjectName} · with ${tutorName}`;
    const starWrap = document.getElementById('rating-stars');
    injectStars(starWrap, 0);
    openModal('rating-modal');
}

// Auto prompt: most recent completed or ended (joined) & unrated lesson
(async function(){
    try {
        const res = await fetch(`<?= basename(__FILE__) ?>?ajax=next_unrated_lesson`, {credentials:'same-origin'});
        const json = await res.json();
        if (json.success && json.data && json.data.lesson) {
            const L = json.data.lesson;
            openRatingModal(L.id, L.subject_name, `${L.tutor_first} ${L.tutor_last}`);
        }
    } catch(e) { /* silent */ }
})();

// Manual "Rate" buttons in Recent Lessons
document.querySelectorAll('.rate-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        openRatingModal(btn.dataset.lessonId, btn.dataset.subject, btn.dataset.tutor);
    });
});

// Star interactions
(function(){
    const wrap = document.getElementById('rating-stars');
    if (!wrap) return;
    
    let current = 0;
    
    // Click handler for stars
    wrap.addEventListener('click', e => {
        const star = e.target.closest('.star');
        if (!star) return;
        
        current = parseInt(star.dataset.value, 10);
        document.getElementById('rating-form-rating').value = current;
        
        // Update star display
        const stars = wrap.querySelectorAll('.star');
        stars.forEach((s, i) => {
            s.classList.toggle('text-yellow-400', i < current);
            s.classList.toggle('text-gray-400', i >= current);
        });
    });
    
    // Hover effect
    wrap.addEventListener('mousemove', e=>{
        if (!(e.target instanceof SVGElement)) return;
        const v = parseInt(e.target.dataset.value||'0',10);
        if (!v) return;
        [...wrap.children].forEach((el,i)=> el.classList.toggle('active', i < v));
        [...wrap.children].forEach((el,i)=> el.classList.toggle('inactive', i >= v));
    });
    wrap.addEventListener('mouseleave', ()=>{
        const stars = wrap.querySelectorAll('.star');
        stars.forEach((s, i) => {
            s.classList.toggle('text-yellow-400', i < current);
            s.classList.toggle('text-gray-400', i >= current);
        });
    });
    wrap.addEventListener('click', e=>{
        if (!(e.target instanceof SVGElement)) return;
        const v = parseInt(e.target.dataset.value||'0',10);
        if (!v) return;
        current = v;
        const hidden = document.getElementById('rating-form-rating');
        if (hidden) hidden.value = String(current);
    });
    // ensure hidden input exists
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'rating';
    hidden.id = 'rating-form-rating';
    hidden.value = '0';
    document.getElementById('rating-form').appendChild(hidden);
})();

// Handle star rating selection
document.querySelectorAll('.rating-star').forEach(star => {
    star.addEventListener('click', (e) => {
        const value = parseInt(e.currentTarget.dataset.value);
        document.getElementById('rating-form-rating').value = value;
        
        // Update star display
        document.querySelectorAll('.rating-star').forEach((s, i) => {
            s.classList.toggle('text-yellow-400', i < value);
            s.classList.toggle('text-gray-400', i >= value);
        });
    });
});

// Submit rating with soft UI update of Recent Lessons row
document.getElementById('rating-form')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const form = e.currentTarget;
    const lesson_id = document.getElementById('rating-lesson-id').value;
    const rating = document.getElementById('rating-form-rating').value;
    const comment = document.getElementById('rating-comment').value.trim();

    // Validate rating
    if (!rating || rating === '0') {
        alert('Please select a rating by clicking on the stars');
        return;
    }

    const fd = new FormData();
    fd.append('lesson_id', lesson_id);
    fd.append('rating', rating);
    if (comment) fd.append('comment', comment);

    try {
        const res = await fetch('api/rate_lesson.php', { method:'POST', body: fd, credentials:'same-origin' });
        const json = await res.json();
        if (!json.success) {
            alert(json.message || 'Could not save rating.');
            return;
        }
        
        // Show success message
        const successMsg = document.createElement('div');
        successMsg.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg flex items-center gap-2 z-50';
        successMsg.innerHTML = `
            <svg class="h-5 w-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
            <span>Thank you for your feedback!</span>
        `;
        document.body.appendChild(successMsg);
        
        // Auto-remove the success message after 3 seconds
        setTimeout(() => {
            successMsg.classList.add('opacity-0', 'translate-y-2', 'transition-all', 'duration-300');
            setTimeout(() => successMsg.remove(), 300);
        }, 3000);
        
        closeModal('rating-modal');

        // Soft-update the Recent Lessons row for this lesson (replace Rate with "Rated: X/5")
        const rowBtn = document.querySelector(`.rate-btn[data-lesson-id="${CSS.escape(lesson_id)}"]`);
        if (rowBtn) {
            const parent = rowBtn.parentElement; // the container with buttons
            rowBtn.remove();
            const ratedTag = document.createElement('span');
            ratedTag.className = 'text-xs text-gray-400';
            ratedTag.textContent = `Rated: ${parseInt(rating,10)}/5`;
            parent.appendChild(ratedTag);
        }

        // Optional: toast
        // alert('Thanks for your feedback!');
    } catch(err) {
        alert('Network error saving rating.');
    }
});

// ---------- Live auto-update (poll for changes) ----------
(function(){
    function updateNotifBadge(count){
        ['notif-btn','notif-btn-mobile'].forEach(function(id){
            var btn = document.getElementById(id);
            if (!btn) return;
            var badge = btn.querySelector('.ui-notification-badge');
            if (count > 0){
                if (!badge){ badge = document.createElement('span'); badge.className = 'ui-notification-badge'; btn.appendChild(badge); }
                var txt = count > 99 ? '99+' : String(count);
                if (badge.textContent.trim() !== txt){
                    badge.textContent = txt;
                    badge.classList.add('live-updated');
                    setTimeout(function(){ badge.classList.remove('live-updated'); }, 900);
                }
            } else if (badge){ badge.remove(); }
        });
    }
    var baseline = null;
    async function poll(){
        try {
            var res = await fetch('api/dashboard_live.php', { credentials:'same-origin' });
            if (!res.ok) return;
            var d = await res.json();
            if (!d || !d.success) return;
            updateNotifBadge(d.notif_count || 0);
            var sig = (d.lesson_id || 0) + '|' + (d.lesson_status || '');
            if (baseline === null){ baseline = sig; return; }
            if (sig !== baseline){ location.reload(); }
        } catch(e){ /* ignore transient errors */ }
    }
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
    poll();
})();
</script>
<?php if ($is_lesson_imminent): ?>
<script>
// Countdown timer and state evolution
(function(){
    const hero = document.getElementById('lesson-hero');
    if (!hero) return;

    const lesson_start_time = '<?= $upcomingLesson ? $upcomingLesson['scheduled_start_time'] : '' ?>';
    if (!lesson_start_time) return;

    // Get actual lesson status from database
    const lesson_status = '<?= $upcomingLesson ? htmlspecialchars($upcomingLesson['status'], ENT_QUOTES, 'UTF-8') : 'Scheduled' ?>';

    // Database stores UTC time, so append 'Z' to treat as UTC in JavaScript
    const start = new Date(lesson_start_time.replace(' ', 'T') + 'Z');

    let joinButton = document.getElementById('join-button');
    
    function updateHero() {
        const now = new Date();
        const diff = start.getTime() - now.getTime();

        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        const countdownEl = document.getElementById('countdown');
        // Refresh button reference in case it was replaced
        joinButton = document.getElementById('join-button');
        const statusText = document.getElementById('lesson-status-text');
        const subText = document.getElementById('lesson-sub-text');
        const heroBg = document.getElementById('lesson-hero-bg');

        const joinWindowEnd = start.getTime() + 60 * 60 * 1000; // 1 hour after start
        const fifteenMinutesBefore = 15 * 60 * 1000; // 15 minutes in milliseconds
        const lessonId = <?= (int)($upcomingLesson['id'] ?? 0) ?>;
        
        // State A: More than 15 minutes before start
        if (diff > fifteenMinutesBefore) {
            hero.classList.remove('state-2', 'state-3', 'state-4');
            hero.classList.add('state-1');
            countdownEl.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            statusText.textContent = 'Upcoming Lesson';
            joinButton.textContent = 'Get Lesson Details';
            if (joinButton.tagName === 'A') {
                joinButton.href = '#';
            }
            heroBg.style.backgroundImage = "url('https://images.unsplash.com/photo-1531346971435-5a7748c2fd52?q=80&w=2070&auto=format&fit=crop')";
        }
        
        // State B: 15 minutes before start until lesson end
        else if (diff <= fifteenMinutesBefore && now.getTime() < joinWindowEnd) {
            hero.classList.remove('state-1', 'state-3', 'state-4');
            hero.classList.add('state-2');
            
            if (diff > 0) {
                // Before lesson starts
                countdownEl.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                statusText.textContent = 'Lesson Starting Soon';
                // Convert to link if it's a button
                if (joinButton.tagName === 'BUTTON') {
                    const link = document.createElement('a');
                    link.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = joinButton.className;
                    link.id = joinButton.id;
                    link.textContent = 'Join Lesson';
                    joinButton.parentNode.replaceChild(link, joinButton);
                    joinButton = document.getElementById('join-button'); // Refresh reference
                } else {
                    joinButton.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                    joinButton.target = '_blank';
                    joinButton.rel = 'noopener noreferrer';
                    joinButton.textContent = 'Join Lesson';
                }
            } else {
                // Lesson time has started, but check actual status
                const endTime = new Date('<?= $upcomingLesson['scheduled_end_time'] ?>'.replace(' ', 'T') + 'Z');
                const endDiff = endTime.getTime() - now.getTime();
                const endHours = Math.floor((endDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const endMinutes = Math.floor((endDiff % (1000 * 60 * 60)) / (1000 * 60));
                const endSeconds = Math.floor((endDiff % (1000 * 60)) / 1000);
                countdownEl.textContent = `${endHours.toString().padStart(2, '0')}:${endMinutes.toString().padStart(2, '0')}:${endSeconds.toString().padStart(2, '0')}`;
                
                // Only show "Lesson In Progress" if status is actually InProgress
                if (lesson_status === 'InProgress') {
                    statusText.textContent = 'Lesson In Progress';
                // Convert to link if it's a button
                if (joinButton.tagName === 'BUTTON') {
                    const link = document.createElement('a');
                    link.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = joinButton.className;
                    link.id = joinButton.id;
                    link.textContent = 'Return to Lesson';
                    joinButton.parentNode.replaceChild(link, joinButton);
                    joinButton = document.getElementById('join-button'); // Refresh reference
                } else {
                    joinButton.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                    joinButton.target = '_blank';
                    joinButton.rel = 'noopener noreferrer';
                    joinButton.textContent = 'Return to Lesson';
                }
                } else {
                    // Status is still Scheduled, show "Join Lesson" instead
                    statusText.textContent = 'Lesson Starting Now';
                    // Convert to link if it's a button
                    if (joinButton.tagName === 'BUTTON') {
                        const link = document.createElement('a');
                        link.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                        link.className = joinButton.className;
                        link.id = joinButton.id;
                        link.textContent = 'Join Lesson';
                        joinButton.parentNode.replaceChild(link, joinButton);
                        joinButton = document.getElementById('join-button'); // Refresh reference
                    } else {
                        joinButton.href = `lesson_room_live.php?lesson_id=${lessonId}`;
                        joinButton.textContent = 'Join Lesson';
                    }
                }
            }
            
            heroBg.style.backgroundImage = "url('https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=2132&auto=format&fit=crop')";
            
            // Browser tab notification when within 15 minutes
            if (diff > 0 && diff <= fifteenMinutesBefore && document.title !== 'Your lesson is starting!') {
                document.title = 'Your lesson is starting!';
            }
        }
        
        // After lesson ends
        else if (now.getTime() > joinWindowEnd) {
            hero.style.display = 'none';
            document.title = 'Student Dashboard';
        }

        // After lesson
        if (now.getTime() > joinWindowEnd) {
            hero.style.display = 'none';
            document.title = 'Student Dashboard';
        }
    }

    setInterval(updateHero, 1000);
    updateHero();
})();
</script>
<?php endif; ?>

</body>
</html>
