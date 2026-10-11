<?php
// save_student_notes.php
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

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$student_id = isset($payload['student_id']) ? (int)$payload['student_id'] : 0;
$notes = trim((string)($payload['notes'] ?? ''));

if ($student_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid student id']);
    exit();
}

// Check assignment
$chk = $pdo->prepare("
  SELECT 1
  FROM tutor_student_assignments 
  WHERE tutor_id = ? AND student_id = ? 
    AND (end_date IS NULL OR end_date >= CURDATE())
  LIMIT 1
");
$chk->execute([$tutor_id, $student_id]);
if (!$chk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Student not assigned to this tutor']);
    exit();
}

// Ensure notes table exists
$pdo->exec("
  CREATE TABLE IF NOT EXISTS tutor_student_notes (
    id INT NOT NULL AUTO_INCREMENT,
    tutor_id INT NOT NULL,
    student_id INT NOT NULL,
    notes TEXT,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tutor_student (tutor_id, student_id)
  ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4  ;
");

// Upsert
$up = $pdo->prepare("
  INSERT INTO tutor_student_notes (tutor_id, student_id, notes)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE notes = VALUES(notes), updated_at = CURRENT_TIMESTAMP
");
$up->execute([$tutor_id, $student_id, $notes]);

echo json_encode(['success' => true]);
