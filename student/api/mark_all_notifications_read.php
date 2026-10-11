<?php
// mark_all_notifications_read.php
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
    // Mark all unread notifications as read
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE user_id = ? AND is_read = 0
    ");
    $result = $stmt->execute([$_SESSION['user_id']]);
    
    $count = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'count' => $count,
        'message' => "Marked $count notifications as read"
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
