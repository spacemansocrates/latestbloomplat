<?php
require_once 'auth_tutor_only.php';
require_once __DIR__ . '/../../config/db_connect.php';
$current_page = 'notifications';

function notification_label(?string $type): string {
    if (empty($type)) return 'General Notification';
    $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $type);
    $label = str_replace('_', ' ', $label);
    return ucwords(trim($label));
}
function notification_action_url(array $row): string {
    $type = strtolower((string)($row['type'] ?? ''));
    $id   = (int)($row['related_entity_id'] ?? 0);

    if (strpos($type, 'lesson') !== false || strpos($type, 'schedule') !== false) {
        return 'schedule.php';
    }
    if (strpos($type, 'homework') !== false) {
        return 'homework.php';
    }
    if (strpos($type, 'payment') !== false || strpos($type, 'package') !== false) {
        return 'earnings.php';
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
$stmt->execute([$tutor_id]);
$notifications = $stmt->fetchAll();

$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
    ->execute([$tutor_id]);
