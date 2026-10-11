<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit();
}
$me = (int)$_SESSION['user_id'];

// PHP holds an exclusive lock on the session file for the whole request,
// so concurrent AJAX from one page queues up instead of running in
// parallel. Nothing below writes $_SESSION (reads still work), so the
// lock can go now. Same reasoning as student/availability.php.
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';

try {
    $sql = "
      SELECT o.other_id,
             u.first_name, u.last_name, u.role, u.profile_photo_url,
             (SELECT m2.content FROM messages m2 
               WHERE (m2.sender_id = :me1 AND m2.receiver_id = o.other_id)
                  OR (m2.sender_id = o.other_id AND m2.receiver_id = :me2)
               ORDER BY m2.sent_at DESC LIMIT 1) AS last_preview,
             (SELECT m2.sent_at FROM messages m2 
               WHERE (m2.sender_id = :me3 AND m2.receiver_id = o.other_id)
                  OR (m2.sender_id = o.other_id AND m2.receiver_id = :me4)
               ORDER BY m2.sent_at DESC LIMIT 1) AS last_time,
             (SELECT COUNT(*) FROM messages mu
               WHERE mu.receiver_id = :me5 AND mu.sender_id = o.other_id AND mu.is_read = 0) AS unread
      FROM (
        SELECT CASE WHEN sender_id=:me6 THEN receiver_id ELSE sender_id END AS other_id
        FROM messages
        WHERE sender_id=:me7 OR receiver_id=:me8
        GROUP BY other_id
      ) o
      JOIN users u ON u.id = o.other_id
      ORDER BY COALESCE(last_time, '1970-01-01') DESC
    ";
    
    $params = [
        ':me1' => $me,
        ':me2' => $me,
        ':me3' => $me,
        ':me4' => $me,
        ':me5' => $me,
        ':me6' => $me,
        ':me7' => $me,
        ':me8' => $me
    ];
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = array_map(function($r){
        $initials = strtoupper(substr($r['first_name'] ?? '',0,1) . substr($r['last_name'] ?? '',0,1));
        return [
            'other_id' => (int)$r['other_id'],
            'name'     => trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')),
            'role'     => $r['role'] ?? '',
            'profile_photo' => $r['profile_photo_url'] ?? null,
            'initials' => $initials ?: 'U',
            'last_preview' => $r['last_preview'] ?? '',
            'last_time'    => $r['last_time'] ?? null,
            'last_time_short' => $r['last_time'] ? date('M j, g:i a', strtotime($r['last_time'])) : '',
            'unread'    => (int)($r['unread'] ?? 0),
        ];
    }, $rows);

    echo json_encode(['success'=>true, 'items'=>$items]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'message'=>'DB error']);
}
