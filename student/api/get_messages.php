<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit();
}
$me = (int)$_SESSION['user_id'];
if (empty($_GET['other_id']) || !ctype_digit($_GET['other_id'])) {
    echo json_encode(['success'=>false, 'message'=>'Invalid other_id']); exit();
}
$other = (int)$_GET['other_id'];

// PHP holds an exclusive lock on the session file for the whole request,
// so concurrent AJAX from one page queues up instead of running in
// parallel. Nothing below writes $_SESSION (reads still work), so the
// lock can go now. Same reasoning as student/availability.php.
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';

try {
    $u = $pdo->prepare("SELECT id, first_name, last_name, role, profile_photo_url FROM users WHERE id=? AND is_active=1");
    $u->execute([$other]);
    $user = $u->fetch();
    if (!$user) { echo json_encode(['success'=>false, 'message'=>'User not found']); exit(); }

    $m = $pdo->prepare("
      SELECT id, sender_id, receiver_id, content, is_read, sent_at
      FROM messages
      WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
      ORDER BY sent_at ASC
      LIMIT 200
    ");
    $m->execute([$me, $other, $other, $me]);
    $msgs = $m->fetchAll();

    echo json_encode([
        'success'=>true,
        'other'=>[
            'id'=>(int)$user['id'],
            'name'=>trim(($user['first_name']??'').' '.($user['last_name']??'')),
            'role'=>$user['role'] ?? '',
            'profile_photo'=>$user['profile_photo_url'] ?? null,
            'initials'=>strtoupper(substr($user['first_name']??'',0,1).substr($user['last_name']??'',0,1)) ?: 'U',
        ],
        'messages'=>array_map(function($r){
            return [
                'id'=>(int)$r['id'],
                'sender_id'=>(int)$r['sender_id'],
                'receiver_id'=>(int)$r['receiver_id'],
                'content'=>$r['content'] ?? '',
                'is_read'=>(int)$r['is_read'],
                'sent_at'=>$r['sent_at'],
            ];
        }, $msgs)
    ]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'message'=>'DB error']);
}
