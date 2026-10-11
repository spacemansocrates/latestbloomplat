<?php
/** Existing payout policy, using the tutor's current rate (no historical-rate model). */
function tutor_paid_hours_sql(): string {
    return "CASE WHEN l.status = 'Completed' THEN CASE WHEN l.student_id IS NULL THEN 0.5 ELSE TIMESTAMPDIFF(MINUTE, l.scheduled_start_time, l.scheduled_end_time) / 60.0 END WHEN l.status = 'NoShow' THEN 0.5 ELSE 0 END";
}

function earningsSum(PDO $pdo, int $tutor_id, float $rate, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): float {
    $sql = 'SELECT COALESCE(SUM((' . tutor_paid_hours_sql() . ') * ?), 0) FROM lessons l WHERE l.tutor_id = ?';
    $args = [$rate, $tutor_id];
    if ($start) { $sql .= ' AND l.scheduled_start_time >= ?'; $args[] = $start->format('Y-m-d H:i:s'); }
    if ($end) { $sql .= ' AND l.scheduled_start_time < ?'; $args[] = $end->format('Y-m-d H:i:s'); }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return (float)$stmt->fetchColumn();
}

/** Read-only: headers must not create tables, handle POSTs or output chart code. */
function tutor_lifetime_earnings(?PDO $pdo, int $tutorId): ?array {
    try {
        if (!$pdo) return null;
        $stmt = $pdo->prepare('SELECT hourly_rate, currency FROM tutor_payout_settings WHERE tutor_id = ?');
        $stmt->execute([$tutorId]);
        $settings = $stmt->fetch();
        if (!$settings || !isset($settings['hourly_rate']) || trim((string)($settings['currency'] ?? '')) === '') return null;
        return ['amount' => earningsSum($pdo, $tutorId, (float)$settings['hourly_rate']), 'currency' => $settings['currency']];
    } catch (Throwable $e) {
        error_log('[tutor earnings] lifetime unavailable for tutor ' . $tutorId . ' (' . get_class($e) . ', code ' . $e->getCode() . ')');
        return null;
    }
}
