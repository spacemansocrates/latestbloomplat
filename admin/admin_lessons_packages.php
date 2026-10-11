<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }

$db_host=DB_HOST; $db_name=DB_NAME; $db_user=DB_USER; $db_pass=DB_PASSWORD;
try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { http_response_code(500); exit; }

// Admin-only: a bare logged-in check would let any student/tutor read other
// students' package/billing data by changing student_id.
$is_admin = (($_SESSION['user_role'] ?? '') === 'Administrator');
if (!$is_admin) {
  $g = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator','Mini Admin') LIMIT 1");
  $g->execute([(int)($_SESSION['user_id'] ?? 0)]);
  $is_admin = (bool)$g->fetchColumn();
}
if (!$is_admin) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'Forbidden']); exit; }

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($student_id <= 0) { echo '[]'; exit; }

$st = $pdo->prepare("
  SELECT sp.id, sp.start_date, sp.end_date, sp.status, p.name AS pkg_name
  FROM student_packages sp
  JOIN packages p ON p.id = sp.package_id
  WHERE sp.student_id = ?
  ORDER BY sp.created_at DESC
");
$st->execute([$student_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_SLASHES);
