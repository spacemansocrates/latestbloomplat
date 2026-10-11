<?php
// delete_notification.php
session_start();
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$notification_id = $_POST['notification_id'] ?? null;

if (!$notification_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
    exit();
}

try {
    // Verify the notification belongs to the current user before deleting
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found or already deleted']);
        exit();
    }
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
