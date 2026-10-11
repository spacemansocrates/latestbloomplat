<?php
// tutor/get_student_profile.php — Optimized version
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../utils/profile_photo.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Tutor') {
  http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$tutor_id   = (int)$_SESSION['user_id'];
$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid student']); exit; }

// ---- DB ----
require_once __DIR__ . '/../../config/db_connect.php';

try {
// ---- basic access check: assigned or has lessons with this student ----
// We assume tutor_student_assignments exists as it is core.
$allowed = false;
try {
    $q = $pdo->prepare("SELECT 1 FROM tutor_student_assignments
                        WHERE tutor_id=? AND student_id=? AND (end_date IS NULL OR end_date >= CURDATE())
                        LIMIT 1");
    $q->execute([$tutor_id,$student_id]);
    $allowed = (bool)$q->fetchColumn();
} catch (Exception $e) {
    // If table missing, we ignore this check (or handle as false)
}

if (!$allowed) {
    // Fallback: check lessons table if assignment not found or table error
    $q = $pdo->prepare("SELECT 1 FROM lessons WHERE tutor_id=? AND student_id=? LIMIT 1");
    $q->execute([$tutor_id,$student_id]);
    $allowed = (bool)$q->fetchColumn();
}

if (!$allowed) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Not assigned to this student']); exit; }

// ---- student core ----
$st = $pdo->prepare("SELECT first_name,last_name,profile_photo_url FROM users WHERE id=? AND role='Student' LIMIT 1");
$st->execute([$student_id]);
$s = $st->fetch();
if (!$s) { echo json_encode(['success'=>false,'message'=>'Student not found']); exit; }

$student = [
  'id'    => $student_id,
  'name'  => trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')),
  'profile_photo' => resolve_photo($s['profile_photo_url'] ?? null, trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''))),
  'initials' => strtoupper(substr($s['first_name'] ?? 'S',0,1).substr($s['last_name'] ?? 'T',0,1)),
  'notes'  => ''
];

// detailed notes (optional table)
try {
    $nq = $pdo->prepare("SELECT notes FROM tutor_student_notes WHERE tutor_id=? AND student_id=? ORDER BY updated_at DESC LIMIT 1");
    $nq->execute([$tutor_id,$student_id]);
    $student['notes'] = (string)$nq->fetchColumn();
} catch (Exception $e) { /* ignore if table missing */ }


// ---- recent lessons ----
// Core functionality, assuming lessons/subjects tables exist
$ls = $pdo->prepare("
  SELECT l.id, l.subject_id, s.name AS subject, l.scheduled_start_time, l.status
  FROM lessons l
  JOIN subjects s ON s.id = l.subject_id
  WHERE l.tutor_id=? AND l.student_id=?
  ORDER BY l.scheduled_start_time DESC
  LIMIT 50
");
$ls->execute([$tutor_id,$student_id]);
$lessonRows = $ls->fetchAll();

$lessonIds = array_map(fn($r)=>(int)$r['id'],$lessonRows);

// ---- topics/subtopics ----
$topicsByLesson = [];
$subtopicsByLesson = [];
$allTopicsSet = [];

if ($lessonIds) {
  $in = implode(',', array_fill(0,count($lessonIds),'?'));

  // topics (optional but likely)
  try {
      $q = $pdo->prepare("
        SELECT ltc.lesson_id, t.name AS topic_name
        FROM lesson_topics_covered ltc
        JOIN topics t ON t.id = ltc.topic_id
        WHERE ltc.lesson_id IN ($in)
      ");
      $q->execute($lessonIds);
      while ($r = $q->fetch()) {
        $lid = (int)$r['lesson_id'];
        $nm  = trim((string)$r['topic_name']);
        if ($nm === '') continue;
        $topicsByLesson[$lid][] = $nm;
        $allTopicsSet[$nm] = true;
      }
  } catch (Exception $e) { /* ignore */ }

  // subtopics (optional)
  try {
      $q = $pdo->prepare("
        SELECT lsc.lesson_id, t.name AS topic_name, st.name AS subtopic_name
        FROM lesson_subtopics_covered lsc
        JOIN subtopics st ON st.id = lsc.subtopic_id
        JOIN topics t     ON t.id = st.topic_id
        WHERE lsc.lesson_id IN ($in)
      ");
      $q->execute($lessonIds);
      while ($r = $q->fetch()) {
        $lid = (int)$r['lesson_id'];
        $t = trim((string)$r['topic_name']);
        $stxt = trim((string)$r['subtopic_name']);
        if ($t !== '' && $stxt !== '') {
          $subtopicsByLesson[$lid][] = $t.' → '.$stxt;
        }
      }
  } catch (Exception $e) { /* ignore */ }
}

// Build lesson rows. Stored times are UTC: compare in UTC, display in the tutor's zone.
$lessons = [];
$now = new DateTime('now', new DateTimeZone('UTC'));
$viewerTz = $_SESSION['timezone'] ?? 'Europe/London';
if (!in_array($viewerTz, DateTimeZone::listIdentifiers(), true)) $viewerTz = 'Europe/London';
$viewerTzObj = new DateTimeZone($viewerTz);
$completed_total = 0;
$upcoming_total = 0;

foreach ($lessonRows as $r) {
  $dt = new DateTime($r['scheduled_start_time'], new DateTimeZone('UTC'));
  if ($r['status']==='Completed') $completed_total++;
  if ($r['status']==='Scheduled' && $dt>$now) $upcoming_total++;

  $dtLocal = (clone $dt)->setTimezone($viewerTzObj);
  $lid = (int)$r['id'];
  $topicList = $topicsByLesson[$lid] ?? [];
  $topicTxt = $topicList ? implode(', ', array_unique($topicList)) : '';

  $lessons[] = [
    'id'         => $lid,
    'date'       => $dtLocal->format('M j, Y g:i a'),
    'status'     => $r['status'],
    'subject'    => $r['subject'],
    'subject_id' => (int)$r['subject_id'],
    'topic'      => $topicTxt,
    'subtopics'  => $subtopicsByLesson[$lid] ?? []
  ];
}

$topics = array_values(array_unique(array_keys($allTopicsSet)));

$most_recent_topic = '—';
foreach ($lessons as $L) {
  if (!empty($L['topic'])) { $most_recent_topic = $L['topic']; break; }
}

// ---- progress & overall score ----
$progress_pct = null; 
$progress_subject = null;
$overall_score = null;

// Progress
try {
    $subjectCounts = [];
    foreach ($lessons as $L) {
        $sid = (int)$L['subject_id'];
        $subjectCounts[$sid] = ($subjectCounts[$sid] ?? 0) + 1;
    }
    
    if ($subjectCounts) {
        arsort($subjectCounts);
        $subjectForProgressId = (int)array_key_first($subjectCounts);
        foreach ($lessons as $L) {
            if ((int)$L['subject_id'] === $subjectForProgressId) { $progress_subject = $L['subject']; break; }
        }

        // Get total topics
        $q = $pdo->prepare("SELECT COUNT(*) FROM topics WHERE subject_id=?");
        $q->execute([$subjectForProgressId]);
        $total_topics = (int)$q->fetchColumn();

        // Get covered topics count
        if ($total_topics > 0) {
            $q = $pdo->prepare("
              SELECT COUNT(DISTINCT ltc.topic_id)
              FROM lesson_topics_covered ltc
              JOIN lessons l ON l.id = ltc.lesson_id
              WHERE l.student_id=? AND l.tutor_id=? AND l.subject_id=?
            ");
            $q->execute([$student_id, $tutor_id, $subjectForProgressId]);
            $covered = (int)$q->fetchColumn();
            $progress_pct = (int)round(($covered/$total_topics)*100);
        }
    }
} catch (Exception $e) {
    // If topics tables don't exist or other error, leave progress as null
}

// Overall Score: average of graded homework (homework_submissions.grade is a 0-100
// percentage). Falls back to attendance below when the student has no grades yet.
try {
    $q = $pdo->prepare("
      SELECT AVG(hs.grade) AS avg_score
      FROM homework_submissions hs
      JOIN homework h ON h.id = hs.homework_id
      WHERE h.student_id = ? AND h.tutor_id = ? AND hs.grade IS NOT NULL
    ");
    $q->execute([$student_id, $tutor_id]);
    $avg = $q->fetchColumn();
    if ($avg !== false && $avg !== null) {
        $overall_score = (int)round((float)$avg);
    }
} catch (PDOException $e) {
    error_log('get_student_profile score query failed: ' . $e->getMessage());
}

if ($overall_score === null) {
  // Attendance fallback
  try {
      $q = $pdo->prepare("
        SELECT
          SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS c_completed,
          SUM(CASE WHEN status='NoShow'    THEN 1 ELSE 0 END) AS c_noshow
        FROM lessons
        WHERE tutor_id=? AND student_id=?
      ");
      $q->execute([$tutor_id,$student_id]);
      $a = $q->fetch();
      $comp = (int)($a['c_completed'] ?? 0);
      $base = $comp + (int)($a['c_noshow'] ?? 0);
      if ($base > 0) $overall_score = (int)round(($comp/$base)*100);
  } catch (Exception $e) {}
}

// Summary counts (re-query or use loop counts? Loop counts are only for the recent 50 limit)
// Better to query real totals
$completed_all = 0;
$upcoming_all = 0;
try {
    $q1 = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE tutor_id=? AND student_id=? AND status='Completed'");
    $q1->execute([$tutor_id,$student_id]);
    $completed_all = (int)$q1->fetchColumn();

    $q2 = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE tutor_id=? AND student_id=? AND status='Scheduled' AND scheduled_start_time > NOW()");
    $q2->execute([$tutor_id,$student_id]);
    $upcoming_all = (int)$q2->fetchColumn();
} catch (Exception $e) {
    // Fallback: use the counts from the recent 50 loop (better than 0)
    $completed_all = $completed_total;
    $upcoming_all = $upcoming_total;
}

echo json_encode([
  'success'=>true,
  'student'=>$student,
  'stats'=>[
    'overall_score'=>$overall_score,
    'progress_pct'=>$progress_pct,
    'progress_subject'=>$progress_subject,
    'completed_lessons'=>$completed_all,
    'upcoming_lessons'=>$upcoming_all,
    'most_recent_topic'=>$most_recent_topic
  ],
  'topics'=>$topics,
  'lessons'=>$lessons
]);

} catch (Throwable $e) {
    error_log('[student profile] tutor=' . $tutor_id . ' student=' . $student_id . ' code=' . $e->getCode());
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Could not load this profile. Please try again.']);
}
