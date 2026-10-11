<?php
// send_message.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Tutor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// DB
require_once __DIR__ . '/../../config/db_connect.php';

$tutor_id = (int)$_SESSION['user_id'];

$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$subject    = trim((string)($_POST['subject'] ?? ''));
$message    = trim((string)($_POST['message'] ?? ''));

if ($student_id <= 0 || $message === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Verify the receiver is assigned to this tutor
$chk = $pdo->prepare("
  SELECT 1 FROM tutor_student_assignments
  WHERE tutor_id = ? AND student_id = ? 
    AND (end_date IS NULL OR end_date >= CURDATE())
  LIMIT 1
");
$chk->execute([$tutor_id, $student_id]);
if (!$chk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Student not assigned to this tutor']);
    exit();
}

// Build content (since messages table has no 'subject' column)
$content = $subject !== '' 
    ? "Subject: " . $subject . "\n\n" . $message
    : $message;

$ins = $pdo->prepare("
  INSERT INTO messages (sender_id, receiver_id, content, is_read)
  VALUES (?, ?, ?, 0)
");
$ins->execute([$tutor_id, $student_id, $content]);

echo json_encode(['success' => true]);
