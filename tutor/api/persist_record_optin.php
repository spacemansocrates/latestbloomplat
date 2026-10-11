<?php
session_start(); 
require_once '../auth_tutor_only.php'; 
require_once __DIR__ . '/../../config/db_connect.php';

$tutor_id = $_SESSION['user_id'];
$lesson_id = (int)($_POST['lesson_id'] ?? 0);
$opt = (int)($_POST['opt_in'] ?? 0);
// ownership check
$ok = $pdo->prepare("SELECT 1 FROM lessons WHERE id=? AND tutor_id=?")->execute([$lesson_id,$tutor_id]);
if (!$ok) { http_response_code(403); exit; }
$pdo->prepare("UPDATE lessons SET recording_opt_in=? WHERE id=?")->execute([$opt,$lesson_id]);
