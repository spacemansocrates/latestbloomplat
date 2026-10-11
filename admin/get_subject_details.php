<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// get_subject_details.php

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASSWORD;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Admin-only endpoint (lives under /admin): a bare logged-in check is not enough.
$is_admin = (($_SESSION['user_role'] ?? '') === 'Administrator');
if (!$is_admin) {
    $g = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator','Mini Admin') LIMIT 1");
    $g->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $is_admin = (bool)$g->fetchColumn();
}
if (!$is_admin) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit(); }

if (isset($_GET['id'])) {
    $subject_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subject = $stmt->fetch();

    if ($subject) {
        header('Content-Type: application/json');
        echo json_encode($subject);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Subject not found']);
    }
}