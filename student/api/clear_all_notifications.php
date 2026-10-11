<?php
// clear_all_notifications.php
session_start();
require_once __DIR__ . '/../../config/db_connect.php';
header('Content-Type: application/json');

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Delete all notifications for the current user
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    $count = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'count' => $count,
        'message' => "Deleted $count notifications"
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
