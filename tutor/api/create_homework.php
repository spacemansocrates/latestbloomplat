<?php
// tutor/create_homework.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Tutor') {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$tutor_id = (int)$_SESSION['user_id'];

// DB
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../integrations/file_storage.php';

// Inputs
$title       = trim($_POST['title'] ?? '');
$student_id  = (int)($_POST['student_id'] ?? 0);
$due_raw     = trim($_POST['due_date'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($title === '' || $student_id <= 0 || $due_raw === '' || $description === '') {
  echo json_encode(['success'=>false,'message'=>'Missing fields']); exit;
}

// Ensure this student is assigned to this tutor (active)
$own = $pdo->prepare("
  SELECT 1
  FROM tutor_student_assignments
  WHERE tutor_id=? AND student_id=? AND (end_date IS NULL OR end_date >= CURDATE())
  LIMIT 1
");
$own->execute([$tutor_id,$student_id]);
if (!$own->fetchColumn()) {
  echo json_encode(['success'=>false,'message'=>'Student not assigned to you']); exit;
}

// Normalize due date (HTML datetime-local -> Y-m-d H:i:s)
$due_at = date('Y-m-d H:i:s', strtotime($due_raw));

// Create homework
$ins = $pdo->prepare("
  INSERT INTO homework (lesson_id, tutor_id, student_id, title, description, due_date)
  VALUES (NULL, ?, ?, ?, ?, ?)
");
$ins->execute([$tutor_id,$student_id,$title,$description,$due_at]);
$homework_id = (int)$pdo->lastInsertId();

// Handle attachments (optional)
$uploads_root = __DIR__ . '/../uploads';
if (!is_dir($uploads_root)) { @mkdir($uploads_root, 0775, true); }
$uploads_root = realpath($uploads_root);
if ($uploads_root === false) {
  echo json_encode(['success'=>false,'message'=>'Uploads folder missing']); exit;
}
$dest_dir = $uploads_root . '/homework';
if (!is_dir($dest_dir)) @mkdir($dest_dir, 0775, true);

$max_bytes = 10 * 1024 * 1024;
$allowed   = ['pdf','doc','docx','png','jpg','jpeg'];
$rejected  = []; // reported back so the tutor knows a file did not attach

if (!empty($_FILES['attachments']['name'][0])) {
  $cnt = count($_FILES['attachments']['name']);
  for ($i=0; $i<$cnt; $i++) {
    $name = $_FILES['attachments']['name'][$i] ?? '';
    $tmp  = $_FILES['attachments']['tmp_name'][$i] ?? '';
    $err  = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $size = $_FILES['attachments']['size'][$i] ?? 0;
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $rejected[] = "$name (upload failed)"; continue; }
    if ($size > $max_bytes) { $rejected[] = "$name (over 10 MB)"; continue; }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) { $rejected[] = "$name (type not allowed)"; continue; }

    // Unique stored filename
    $stored = bin2hex(random_bytes(8)).'_'.preg_replace('/[^A-Za-z0-9._-]/','_', $name);
    $abs    = $dest_dir.'/'.$stored;

    if (!move_uploaded_file($tmp, $abs)) { $rejected[] = "$name (could not be saved)"; continue; }

    // Persist DB using *file_url* (web path)
    $file_url  = '/uploads/homework/'.$stored;
    storage_offload($abs, $file_url); // to bucket; stays local if it fails
    $file_name = $name;

    $ia = $pdo->prepare("
      INSERT INTO homework_attachments (homework_id, file_name, file_url)
      VALUES (?, ?, ?)
    ");
    $ia->execute([$homework_id, $file_name, $file_url]);
  }
}

// Trigger will notify the student (HomeworkAssigned)
$out = ['success'=>true,'homework_id'=>$homework_id];
if ($rejected) {
  $out['warning']  = 'Homework saved, but these files were not attached: ' . implode(', ', $rejected);
  $out['rejected'] = $rejected;
}
echo json_encode($out);
