<?php
// students/report_lesson_issue.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
  http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$student_id = (int)$_SESSION['user_id'];
require_once __DIR__ . '/../../config/db_connect.php';

$lesson_id = (int)($_POST['lesson_id'] ?? 0);
$reason    = trim((string)($_POST['reason'] ?? ''));
if ($lesson_id <= 0 || $reason === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Missing data']); exit; }

// Verify lesson belongs to student
$st = $pdo->prepare("SELECT id FROM lessons WHERE id=? AND student_id=? LIMIT 1");
$st->execute([$lesson_id, $student_id]);
if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Lesson not found']); exit; }

// Notify admins (reuse your notifications table)
$msg = "Issue reported by student #$student_id for lesson #$lesson_id: $reason";
try {
  $admins = $pdo->query("SELECT user_id FROM admin_details");
  $ins = $pdo->prepare("INSERT INTO notifications (user_id,type,message,created_at,is_read) VALUES (?,'LessonIssue',?,NOW(),0)");
  foreach ($admins as $a) { $ins->execute([(int)$a['user_id'], $msg]); }
} catch (Throwable $e) {
  // fallback to platform Administrators
  $admins = $pdo->query("SELECT id FROM users WHERE role='Administrator'");
  $ins = $pdo->prepare("INSERT INTO notifications (user_id,type,message,created_at,is_read) VALUES (?,'LessonIssue',?,NOW(),0)");
  foreach ($admins as $a) { $ins->execute([(int)$a['id'], $msg]); }
}

echo json_encode(['success'=>true]);
