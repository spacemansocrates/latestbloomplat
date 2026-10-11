<?php
// lesson_detail_ajax.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Tutor') {
  http_response_code(403); echo json_encode(['success'=>false]); exit();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db_connect.php';
$db = $pdo;

$tutor_id  = (int)$_SESSION['user_id'];
$lesson_id = (int)($_GET['lesson_id'] ?? $_POST['lesson_id'] ?? 0);

// core
$st = $db->prepare("
  SELECT l.*, s.name AS subject_name, u.first_name, u.last_name
  FROM lessons l
  JOIN subjects s ON s.id=l.subject_id
  LEFT JOIN users u ON u.id=l.student_id
  WHERE l.id=? AND l.tutor_id=?
");
$st->execute([$lesson_id, $tutor_id]);
$lesson = $st->fetch();
if (!$lesson) { echo json_encode(['success'=>false, 'message'=>'Lesson not found']); exit(); }

// topics covered
$st = $db->prepare("
  SELECT t.id, t.name
  FROM lesson_topics_covered tc
  JOIN topics t ON t.id = tc.topic_id
  WHERE tc.lesson_id = ?
  ORDER BY t.name
");
$st->execute([$lesson_id]);
$topics = $st->fetchAll();

// subtopics covered (if table exists)
$hasSubtopics = (bool)$db->query("
  SELECT COUNT(*) c FROM information_schema.tables 
  WHERE table_schema = DATABASE() AND table_name='lesson_subtopics_covered'
")->fetchColumn();

$subtopics = [];
if ($hasSubtopics) {
  $st = $db->prepare("
    SELECT st.id, st.name, t.name AS topic_name
    FROM lesson_subtopics_covered lsc
    JOIN subtopics st ON st.id = lsc.subtopic_id
    JOIN topics t ON t.id = st.topic_id
    WHERE lsc.lesson_id = ?
    ORDER BY t.name, st.name
  ");
  $st->execute([$lesson_id]);
  $subtopics = $st->fetchAll();
}

// recording (if any)
$st = $db->prepare("SELECT file_url, file_size_bytes, duration_seconds FROM lesson_recordings WHERE lesson_id=?");
$st->execute([$lesson_id]);
$rec = $st->fetch() ?: null;

// (optional) homework assigned for this lesson
$st = $db->prepare("SELECT id, title, due_date FROM homework WHERE lesson_id=? ORDER BY created_at ASC");
$st->execute([$lesson_id]);
$homework = $st->fetchAll();

echo json_encode([
  'success'  => true,
  'lesson'   => [
    'id' => (int)$lesson['id'],
    'subject' => $lesson['subject_name'],
    'student' => trim(($lesson['first_name'] ?? '').' '.($lesson['last_name'] ?? '')),
    'status'  => $lesson['status'],
    'notes'   => $lesson['tutor_notes'] ?? '',
    // Stored times are UTC — return ISO-8601 with the UTC marker so the browser
    // parses the correct instant (new Date(...)) and localizes it.
    'start'   => $lesson['scheduled_start_time'] ? str_replace(' ', 'T', $lesson['scheduled_start_time']) . 'Z' : null,
    'end'     => $lesson['scheduled_end_time'] ? str_replace(' ', 'T', $lesson['scheduled_end_time']) . 'Z' : null,
  ],
  'topics'     => $topics,
  'subtopics'  => $subtopics,
  'recording'  => $rec,
  'homework'   => $homework,
]);
