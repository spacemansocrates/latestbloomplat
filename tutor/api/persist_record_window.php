<?php
session_start(); 
require_once '../auth_tutor_only.php'; 
require_once __DIR__ . '/../../config/db_connect.php';

$tutor_id = $_SESSION['user_id'];
$lesson_id = (int)($_POST['lesson_id'] ?? 0);
$action = $_POST['action'] ?? '';
$pdo->prepare("UPDATE lessons SET ".($action==='start'?"recording_started_at=NOW()":"recording_ended_at=NOW()")." WHERE id=? AND tutor_id=?")->execute([$lesson_id,$tutor_id]);
