<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit();
}
$me = (int)$_SESSION['user_id'];

$receiver_id = isset($_POST['receiver_id']) && ctype_digit($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$body    = trim($_POST['body'] ?? ''); // comes from forms; maps to `content`
if ($receiver_id <= 0 || $body === '') {
    echo json_encode(['success'=>false,'message'=>'Missing receiver or message']); exit();
}

require_once __DIR__ . '/../../config/db_connect.php';

try {
    // A student may only message the same recipients the UI offers: an actively
    // assigned tutor, or an admin. This mirrors student/messages.php server-side so
    // the endpoint can't be used to message arbitrary users.
    $auth = $pdo->prepare("
        SELECT 1
        FROM users u
        WHERE u.id = :rid AND u.is_active = 1
          AND (
                (u.role = 'Administrator')
             OR (u.role = 'Tutor' AND EXISTS (
                    SELECT 1 FROM tutor_student_assignments tsa
                    WHERE tsa.tutor_id = u.id
                      AND tsa.student_id = :me
                      AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE())
                 ))
          )
        LIMIT 1
    ");
    $auth->execute([':rid' => $receiver_id, ':me' => $me]);
    if (!$auth->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'You can only message your assigned tutor or an admin.']);
        exit();
    }

    $ins = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, is_read, sent_at) VALUES (?,?,?,?,NOW())");
    $ins->execute([$me, $receiver_id, $body, 0]);

    echo json_encode(['success'=>true]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error']);
}
