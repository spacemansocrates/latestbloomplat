<?php
// tutor/messages_api.php
// Prevent HTML errors from breaking JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../utils/profile_photo.php';

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Tutor') {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
    }
    $uid = (int)$_SESSION['user_id'];

    // DB
    require_once __DIR__ . '/../../config/db_connect.php';

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Helper: ensure the tutor is allowed to chat with this user (assigned student)
function can_chat(PDO $pdo, int $tutor_id, int $other_id): bool {
    // Allow messaging Admins or assigned Students
    $q = $pdo->prepare("SELECT role FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $q->execute([$other_id]);
    $u = $q->fetch();
    if (!$u) return false;
    // The users.role enum has no 'Admin'; administrators are 'Administrator'.
    // This comparison could never be true, so tutors could not open a conversation
    // with an admin even though the rule above says they may.
    if ($u['role'] === 'Administrator') return true;
    if ($u['role'] !== 'Student') return false;

    $s = $pdo->prepare("
        SELECT 1 FROM tutor_student_assignments
        WHERE tutor_id=? AND student_id=? AND (end_date IS NULL OR end_date >= CURDATE())
        LIMIT 1
    ");
    $s->execute([$tutor_id, $other_id]);
    return (bool)$s->fetch();
}

if ($action === 'list_conversations') {
    $q = trim((string)($_GET['q'] ?? ''));

    // Gather conversation partners + unread + last time using a single grouping
    // Note: With ATTR_EMULATE_PREPARES=false, we cannot reuse :uid multiple times in some driver versions.
    // We will use distinct parameter names for each occurrence just to be safe.
    $stmt = $pdo->prepare("
        SELECT
          CASE WHEN m.sender_id = :uid1 THEN m.receiver_id ELSE m.sender_id END AS other_id,
          SUM(CASE WHEN m.receiver_id = :uid2 AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread,
          MAX(m.sent_at) AS last_time
        FROM messages m
        WHERE (m.sender_id = :uid3 OR m.receiver_id = :uid4)
        GROUP BY other_id
        ORDER BY last_time DESC
    ");
    $stmt->execute(['uid1'=>$uid, 'uid2'=>$uid, 'uid3'=>$uid, 'uid4'=>$uid]);
    $rows = $stmt->fetchAll();

    $items = [];
    if ($rows) {
        // Build a map of other_ids
        $ids = array_map(fn($r)=>(int)$r['other_id'], $rows);
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $u = $pdo->prepare("SELECT id, first_name, last_name, role, profile_photo_url FROM users WHERE id IN ($in)");
            $u->execute($ids);
            $users = [];
            foreach ($u->fetchAll() as $usr) $users[(int)$usr['id']] = $usr;

            // Optionally filter by search q on name
            foreach ($rows as $r) {
                $oid = (int)$r['other_id'];
                $profile = $users[$oid] ?? null;
                if (!$profile) continue;

                $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? ''));
                if ($q !== '' && stripos($name, $q) === false) continue;

                // Only list what can actually be opened. get_messages enforces
                // can_chat(), so a conversation with someone no longer assigned used
                // to sit in the list and answer "Not allowed" when clicked, with no
                // way to clear it. The history is not deleted, just not offered.
                if (!can_chat($pdo, $uid, $oid)) continue;

                // Get last snippet
                $last = $pdo->prepare("
                    SELECT content FROM messages 
                    WHERE (sender_id=:uid1 AND receiver_id=:oid1) OR (sender_id=:oid2 AND receiver_id=:uid2)
                    ORDER BY sent_at DESC, id DESC LIMIT 1
                ");
                $last->execute(['uid1'=>$uid, 'oid1'=>$oid, 'oid2'=>$oid, 'uid2'=>$uid]);
                $snippet = $last->fetchColumn();

                $items[] = [
                    'other_id'     => $oid,
                    'name'         => $name,
                    'role'         => $profile['role'] ?? '',
                    'profile_photo'=> resolve_photo($profile['profile_photo_url'] ?? null, $name),
                    'unread'       => (int)$r['unread'],
                    'last_time'    => $r['last_time'],
                    'last_snippet' => $snippet ? mb_substr($snippet, 0, 120) : ''
                ];
            }
        }
    }
    echo json_encode(['success'=>true,'items'=>$items]); exit;
}

if ($action === 'get_messages') {
    $other_id = (int)($_GET['other_id'] ?? 0);
    if ($other_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing other_id']); exit; }

    if (!can_chat($pdo, $uid, $other_id)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed']); exit;
    }

    // Mark as read first
    $mr = $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $mr->execute([$other_id, $uid]);

    // Fetch last 200 messages (oldest -> newest)
    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, content, is_read, sent_at
        FROM messages
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
        ORDER BY sent_at ASC, id ASC
        LIMIT 200
    ");
    $stmt->execute([$uid,$other_id,$other_id,$uid]);
    $rows = $stmt->fetchAll();

    $items = array_map(function($m) use ($uid){
        return [
            'id'      => (int)$m['id'],
            'is_me'   => ((int)$m['sender_id'] === $uid),
            'content' => (string)$m['content'],
            'is_read' => (int)$m['is_read'] === 1,
            'sent_at' => $m['sent_at']
        ];
    }, $rows);

    echo json_encode(['success'=>true,'items'=>$items]); exit;
}

if ($action === 'send') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));

    if ($receiver_id <= 0 || $content === '') {
        echo json_encode(['success'=>false,'message'=>'Missing receiver or content']); exit;
    }
    if (mb_strlen($content) > 5000) {
        echo json_encode(['success'=>false,'message'=>'Message too long']); exit;
    }
    if (!can_chat($pdo, $uid, $receiver_id)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed']); exit;
    }

    $ins = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, is_read) VALUES (?,?,?,0)");
    $ins->execute([$uid, $receiver_id, $content]);

    echo json_encode(['success'=>true]); exit;
}

if ($action === 'mark_read') {
    $other_id = (int)($_GET['other_id'] ?? 0);
    if ($other_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing other_id']); exit; }

    if (!can_chat($pdo, $uid, $other_id)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed']); exit;
    }

    $mr = $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $mr->execute([$other_id, $uid]);

    echo json_encode(['success'=>true]); exit;
}

    echo json_encode(['success'=>false,'message'=>'Unknown action']);

} catch (Throwable $e) {
    error_log('[tutor messages] user=' . ($uid ?? 0) . ' action=' . ($action ?? '') . ' code=' . $e->getCode());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not load messages. Please try again.']);
}
