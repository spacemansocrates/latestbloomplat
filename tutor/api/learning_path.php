<?php
require_once __DIR__ . '/../auth_tutor_only.php';
$current_page = 'learning_path';

// 2) DB
require_once __DIR__ . '/../../config/db_connect.php';

// =========================
// Assigned students
// =========================
$students = [];
$st = $pdo->prepare("
  SELECT u.id, u.first_name, u.last_name, u.profile_photo_url
  FROM tutor_student_assignments a
  JOIN users u ON u.id=a.student_id
  WHERE a.tutor_id=? AND u.is_active=1
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
  ORDER BY u.first_name, u.last_name
");
$st->execute([$tutor_id]); $students = $st->fetchAll();

$sel_student = (int)($_GET['student_id'] ?? 0);
if (!$sel_student && !empty($students)) $sel_student = (int)$students[0]['id'];

// Subjects this tutor has taught this student (from lessons)
$subjects = [];
if ($sel_student) {
  $st = $pdo->prepare("
    SELECT DISTINCT s.id, s.name, s.curriculum, s.year_level
    FROM lessons l
    JOIN subjects s ON s.id=l.subject_id
    WHERE l.tutor_id=? AND l.student_id=? 
    ORDER BY s.name
  ");
  $st->execute([$tutor_id,$sel_student]);
  $subjects = $st->fetchAll();
}

$sel_subject = (int)($_GET['subject_id'] ?? 0);
if (!$sel_subject && !empty($subjects)) $sel_subject = (int)$subjects[0]['id'];

// If student/subject not selected, we still want to render the header/sidebar
$has_selection = ($sel_student && $sel_subject);

// =========================
// Load subject info + coverage
// =========================
$st = $pdo->prepare("SELECT id,name,curriculum,year_level FROM subjects WHERE id=?");
$st->execute([$sel_subject]);
$subject = $st->fetch() ?: ['name'=>'Subject','curriculum'=>'','year_level'=>0];

// total topics
$st = $pdo->prepare("SELECT COUNT(*) FROM topics WHERE subject_id=?");
$st->execute([$sel_subject]);
$total_topics = (int)$st->fetchColumn();

// covered topics (distinct) for this pair
$st = $pdo->prepare("
  SELECT COUNT(DISTINCT ltc.topic_id)
  FROM lesson_topics_covered ltc
  JOIN lessons l ON l.id=ltc.lesson_id
  WHERE l.tutor_id=? AND l.student_id=? AND l.subject_id=? AND l.status='Completed'
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
$covered_topics = (int)$st->fetchColumn();

$coverage_pct = ($total_topics>0) ? round($covered_topics*100/$total_topics,1) : 0.0;

// last completed lesson & its topics
$last_lesson = null; $last_topics = [];
$st = $pdo->prepare("
  SELECT l.id, l.scheduled_start_time, l.tutor_notes
  FROM lessons l
  WHERE l.tutor_id=? AND l.student_id=? AND l.subject_id=? AND l.status='Completed'
  ORDER BY l.scheduled_start_time DESC LIMIT 1
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
$last_lesson = $st->fetch();

if ($last_lesson) {
  $st = $pdo->prepare("
    SELECT t.id, t.name
    FROM lesson_topics_covered c
    JOIN topics t ON t.id=c.topic_id
    WHERE c.lesson_id=?
    ORDER BY t.id
  ");
  $st->execute([(int)$last_lesson['id']]); $last_topics = $st->fetchAll();
}

// covered topic IDs for “next topic” logic
$covered_ids = [];
$st = $pdo->prepare("
  SELECT DISTINCT t.id
  FROM lesson_topics_covered c
  JOIN lessons l ON l.id=c.lesson_id
  JOIN topics t ON t.id=c.topic_id
  WHERE l.tutor_id=? AND l.student_id=? AND l.subject_id=? AND l.status='Completed'
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
foreach ($st as $r) $covered_ids[] = (int)$r['id'];

// next topic = first (by id asc) not yet covered
$next_topic = null;
if ($total_topics > 0) {
  if ($covered_ids) {
    $ph = implode(',', array_fill(0, count($covered_ids), '?'));
    $params = array_merge([$sel_subject], $covered_ids);
    $sql = "SELECT id,name FROM topics WHERE subject_id=? AND id NOT IN ($ph) ORDER BY id ASC LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute($params); $next_topic = $st->fetch();
  } else {
    $st = $pdo->prepare("SELECT id,name FROM topics WHERE subject_id=? ORDER BY id ASC LIMIT 1");
    $st->execute([$sel_subject]); $next_topic = $st->fetch();
  }
}

// next topic’s first few subtopics
$next_subtopics = [];
if ($next_topic) {
  $st = $pdo->prepare("SELECT id,name FROM subtopics WHERE topic_id=? ORDER BY id ASC LIMIT 6");
  $st->execute([(int)$next_topic['id']]); $next_subtopics = $st->fetchAll();
}

// Pace: last 28d lessons/week
$st = $pdo->prepare("
  SELECT COUNT(*) FROM lessons
  WHERE tutor_id=? AND student_id=? AND subject_id=? AND status='Completed'
    AND scheduled_start_time >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
$lessons_last28 = (int)$st->fetchColumn();
$lessons_per_week = round($lessons_last28/4, 2);

// Topics/lesson (overall)
$st = $pdo->prepare("
  SELECT COUNT(DISTINCT c.topic_id) topics_covered, COUNT(DISTINCT l.id) lessons_with_topics
  FROM lessons l
  LEFT JOIN lesson_topics_covered c ON c.lesson_id=l.id
  WHERE l.tutor_id=? AND l.student_id=? AND l.subject_id=? AND l.status='Completed'
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
$row = $st->fetch();
$topics_per_lesson = ($row && (int)$row['lessons_with_topics']>0)
  ? round(((int)$row['topics_covered'])/((int)$row['lessons_with_topics']),2)
  : 0.0;

$topics_per_week = round($lessons_per_week * $topics_per_lesson, 2);
$topics_remaining = max(0, $total_topics - $covered_topics);
$weeks_remaining = ($topics_per_week>0) ? round($topics_remaining/$topics_per_week, 1) : null;

// Upcoming lesson
$st = $pdo->prepare("
  SELECT l.id, l.scheduled_start_time, s.name AS subject_name,
         CONCAT_WS(' ', u.first_name, u.last_name) AS student_name,
         u.profile_photo_url AS student_photo_url
  FROM lessons l
  JOIN subjects s ON s.id = l.subject_id
  JOIN users u ON u.id = l.student_id
  WHERE l.tutor_id=? AND l.student_id=? AND l.subject_id=?
    AND l.status='Scheduled' AND l.scheduled_start_time > NOW()
  ORDER BY l.scheduled_start_time ASC LIMIT 1
");
$st->execute([$tutor_id,$sel_student,$sel_subject]); $upcoming = $st->fetch();

// Heatmap (13 weeks x 7 days ~ 91 days)
$counts_by_day = [];
$st = $pdo->prepare("
  SELECT DATE(scheduled_start_time) d, COUNT(*) cnt
  FROM lessons
  WHERE tutor_id=? AND student_id=? AND subject_id=? AND status='Completed'
    AND scheduled_start_time >= DATE_SUB(CURDATE(), INTERVAL 91 DAY)
  GROUP BY DATE(scheduled_start_time)
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
foreach ($st as $r) $counts_by_day[$r['d']] = (int)$r['cnt'];

// ──────────────────────────────────────────────────────────────────────
// Suggested materials from library
// ──────────────────────────────────────────────────────────────────────
function app_url_base_prefix_lp(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // If we are in /tutor/learning_path.php, then dirname is /tutor,
    // and one more up is the root.
    $prefix = rtrim(dirname(dirname($script)), '/\\');
    return ($prefix === '/' ? '' : $prefix);
}
$materials_base = app_url_base_prefix_lp();

$subject_grade     = (int)($subject['year_level'] ?? 0);
$subject_curr      = trim((string)($subject['curriculum'] ?? ''));
$suggest_materials = [];

$cond_m = ["lm.is_published = 1"];
$par_m  = [];

// 1. Basic filter: same subject or no subject
$cond_m[] = "(lm.subject_id IS NULL OR lm.subject_id = ?)";
$par_m[]  = $sel_subject;

// 2. Optional filters: grade and curriculum (if defined)
if ($subject_grade > 0) {
    $cond_m[] = "(lm.grade_level IS NULL OR lm.grade_level = ?)";
    $par_m[]  = $subject_grade;
}
if ($subject_curr !== '') {
    $cond_m[] = "(lm.curriculum IS NULL OR lm.curriculum = ?)";
    $par_m[]  = $subject_curr;
}

$sqlMaterials = "
  SELECT lm.id, lm.title, lm.description, lm.material_type, lm.file_url, lm.external_url,
         lm.curriculum, lm.grade_level, s.name AS subject_name
  FROM learning_materials lm
  LEFT JOIN subjects s ON s.id = lm.subject_id
  WHERE " . implode(' AND ', $cond_m) . "
  ORDER BY
    CASE WHEN lm.subject_id = ? THEN 0 ELSE 1 END,
    lm.created_at DESC
  LIMIT 4
";
$par_m[] = $sel_subject;

$st = $pdo->prepare($sqlMaterials);
$st->execute($par_m);
$suggest_materials = $st->fetchAll();

// Topic tree (with coverage flags)
$topics = [];
$st = $pdo->prepare("SELECT id,name FROM topics WHERE subject_id=? ORDER BY id ASC");
$st->execute([$sel_subject]); $topics = $st->fetchAll();
$covered_set = array_flip($covered_ids);

$sub_by_topic = [];
if ($topics) {
  $ids = array_map(fn($r)=>$r['id'],$topics);
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT id,topic_id,name FROM subtopics WHERE topic_id IN ($ph) ORDER BY id ASC");
  $st->execute($ids);
  while ($r = $st->fetch()) $sub_by_topic[(int)$r['topic_id']][] = $r;
}

// Recent lessons list
$recent_lessons = [];
$st = $pdo->prepare("
  SELECT id, scheduled_start_time, status
  FROM lessons
  WHERE tutor_id=? AND student_id=? AND subject_id=?
  ORDER BY scheduled_start_time DESC LIMIT 6
");
$st->execute([$tutor_id,$sel_student,$sel_subject]);
$recent_lessons = $st->fetchAll();
