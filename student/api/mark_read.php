<?php
// students/mark_read.php
session_start();
header('Content-Type: application/json');

// 1. Authenticate the user
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// 2. Get the required IDs
$me = (int)$_SESSION['user_id'];
if (empty($_GET['other_id'])) {
    echo json_encode(['success' => false, 'message' => 'Other user ID is missing']);
    exit();
}
$other_id = (int)$_GET['other_id'];

// 3. Connect to the database
require_once __DIR__ . '/../../config/db_connect.php';

try {
    // 4. Prepare and execute the UPDATE statement
    // This query sets the is_read flag to 1 for all messages received
    // by the current user from the other user.
    $sql = "
        UPDATE messages
        SET is_read = 1
        WHERE receiver_id = :me
          AND sender_id = :other_id
          AND is_read = 0
    ";
    
    $stmt = $pdo->prepare($sql);
    
    $stmt->execute([
        ':me' => $me,
        ':other_id' => $other_id
    ]);

    // 5. Return a success response
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // Return an error response if the database query fails
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}