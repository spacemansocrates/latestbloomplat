<?php
// tutor_join.php
session_start(); 
require_once '../auth_tutor_only.php'; 
require_once __DIR__ . '/../../config/db_connect.php';

$lesson_id = (int)($_POST['lesson_id'] ?? 0);
if (!$lesson_id) { echo json_encode(['success'=>false,'message'=>'Invalid lesson']); exit(); }

// Ensure the lesson belongs to this tutor and is Scheduled today (optional check)
$st = $pdo->prepare("SELECT id FROM lessons WHERE id=? AND tutor_id=?");
$st->execute([$lesson_id, $tutor_id]);
if (!$st->fetchColumn()) {
  echo json_encode(['success'=>false,'message'=>'Lesson not found']);
  exit();
}

// Record first join time only
$upd = $pdo->prepare("UPDATE lessons SET tutor_join_time = COALESCE(tutor_join_time, NOW()) WHERE id=? AND tutor_id=?");
$upd->execute([$lesson_id, $tutor_id]);

echo json_encode(['success'=>true]);
