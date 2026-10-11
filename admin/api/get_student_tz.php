<?php
require_once __DIR__ . '/../../config/db_connect.php'; // unified DB credentials (.env)
/**
 * API Endpoint: Get Student Timezone
 * 
 * Allows admins to securely fetch a student's timezone via AJAX
 * for scheduling feedback in the lesson creation interface.
 * 
 * Request: GET /admin/api/get_student_tz.php?student_id={id}
 * Response: JSON { success: bool, data: { timezone: string, label: string }, message: string }
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');

// Helper functions
function jsonResponse(bool $success, array $data = [], ?string $message = null, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

function jsonError(string $message, int $statusCode = 400): void {
    jsonResponse(false, [], $message, $statusCode);
}

// Auth check: Must be logged in
if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Database connection
$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASSWORD;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    jsonError('Database connection failed', 500);
}

// Verify admin access (either role='Administrator' or listed in admin_details)
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id = ? AND admin_role IN ('Super Admin', 'Admin', 'Academic Coordinator')");
    $stmt->execute([$user_id]);
    $is_admin = (bool)$stmt->fetchColumn();
}

if (!$is_admin) {
    jsonError('Access denied', 403);
}

// Validate input
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($student_id <= 0) {
    jsonError('Invalid student ID', 422);
}

// Include timezone helper for utility functions
require_once __DIR__ . '/../../utils/timezone_helper.php';

// Ensure the timezone column exists
ensureTimezoneColumn($pdo);

// Fetch student's timezone
$stmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.timezone
    FROM users u
    WHERE u.id = ? AND u.role = 'Student'
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    jsonError('Student not found', 404);
}

// Get timezone or default
$timezone = $student['timezone'];
if (empty($timezone) || !isValidTimezone($timezone)) {
    $timezone = DEFAULT_TIMEZONE;
}

// Build response
jsonResponse(true, [
    'student_id' => (int)$student['id'],
    'student_name' => trim($student['first_name'] . ' ' . $student['last_name']),
    'timezone' => $timezone,
    'label' => getTimezoneLabel($timezone)
], 'Timezone retrieved successfully');
