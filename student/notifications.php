<?php
// student/notifications.php
// session_start();
// if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
//     header('Location: ../login.php');
//     exit();
// }
$is_tab = true;

$student_id = (int)$_SESSION['user_id'];
$current_page = 'notifications';
$is_tab = isset($_GET['tab']) && $_GET['tab'] === 'true';

require_once __DIR__ . '/../config/db_connect.php';

// function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dtfmt($iso, $fmt = 'M j, Y g:i a') {
    $t = strtotime((string)$iso);
    return $t ? date($fmt, $t) : '';
}
function notification_label(?string $type): string {
    if (!$type) return 'Notification';
    $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $type);
    $label = str_replace('_', ' ', $label);
    return ucwords(trim($label));
}
function notification_action_url(array $row): string {
    $type = strtolower((string)($row['type'] ?? ''));
    $id   = (int)($row['related_entity_id'] ?? 0);

    if (strpos($type, 'lesson') !== false || strpos($type, 'schedule') !== false) {
        return $id ? 'schedule.php?lesson_id=' . $id : 'schedule.php';
    }
    if (strpos($type, 'homework') !== false) {
        return 'homeworks.php';
    }
    if (strpos($type, 'package') !== false || strpos($type, 'payment') !== false) {
        return 'packages.php';
    }
    if (strpos($type, 'message') !== false) {
        return 'messages.php';
    }
    return 'dashboard.php';
}

$stmt = $pdo->prepare("
    SELECT id, type, message, related_entity_id, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 150
");
$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();

$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
    ->execute([$student_id]);
?>

