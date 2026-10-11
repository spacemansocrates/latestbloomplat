<?php
/**
 * cron/report_phone_coverage.php
 * Who can actually be reached on WhatsApp, and who needs chasing for a number.
 *
 * Read-only. Run it before switching WhatsApp on, and again as numbers come in.
 *
 * Usage:
 *   php cron/report_phone_coverage.php                # summary + the problem list
 *   php cron/report_phone_coverage.php --role=Student
 *   php cron/report_phone_coverage.php --csv > numbers.csv
 *   php cron/report_phone_coverage.php --active-only  # only people with future lessons
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/phone.php';

$opts       = getopt('', ['csv', 'role::', 'active-only']);
$csv        = array_key_exists('csv', $opts);
$activeOnly = array_key_exists('active-only', $opts);
$role       = isset($opts['role']) ? (string)$opts['role'] : '';

$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role,
               u.phone_number, u.guardian_phone,
               (SELECT COUNT(*) FROM lessons l
                 WHERE (l.student_id = u.id OR l.tutor_id = u.id)
                   AND l.status = 'Scheduled'
                   AND l.scheduled_start_time >= UTC_TIMESTAMP()) AS future_lessons
        FROM users u
        WHERE u.role IN ('Student','Tutor','Administrator','QualityControl')";
$params = [];
if ($role !== '') { $sql .= " AND u.role = ?"; $params[] = $role; }
$sql .= " ORDER BY FIELD(u.role,'Student','Tutor','Administrator','QualityControl'), u.last_name, u.first_name";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if ($activeOnly) {
    $rows = array_values(array_filter($rows, static fn($r) => (int)$r['future_lessons'] > 0));
}

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','role','name','email','phone_raw','phone_e164','status','guardian_raw','guardian_e164','future_lessons']);
    foreach ($rows as $r) {
        $p = phone_to_e164($r['phone_number']);
        $g = phone_to_e164($r['guardian_phone']);
        fputcsv($out, [
            $r['id'], $r['role'], trim($r['first_name'] . ' ' . $r['last_name']), $r['email'],
            (string)$r['phone_number'], $p['ok'] ? $p['e164'] : '',
            $p['ok'] ? ($p['assumed'] ? 'ok (country assumed)' : 'ok') : $p['reason'],
            (string)$r['guardian_phone'], $g['ok'] ? $g['e164'] : '',
            (int)$r['future_lessons'],
        ]);
    }
    fclose($out);
    exit(0);
}

$byRole   = [];
$problems = [];
$assumed  = [];

foreach ($rows as $r) {
    $p = phone_to_e164($r['phone_number']);
    $g = phone_to_e164($r['guardian_phone']);
    $reachable = $p['ok'] || $g['ok'];

    $byRole[$r['role']] ??= ['total' => 0, 'reachable' => 0, 'assumed' => 0];
    $byRole[$r['role']]['total']++;
    if ($reachable) $byRole[$r['role']]['reachable']++;
    if ($p['ok'] && $p['assumed']) {
        $byRole[$r['role']]['assumed']++;
        $assumed[] = [$r, $p];
    }
    if (!$reachable) $problems[] = [$r, $p];
}

$w = static fn(string $s, int $n) => str_pad(mb_substr($s, 0, $n), $n);

echo "WhatsApp reachability\n";
echo str_repeat('=', 74) . "\n";
printf("%-16s %8s %12s %10s\n", 'Role', 'People', 'Reachable', 'Assumed CC');
foreach ($byRole as $r => $c) {
    printf("%-16s %8d %12s %10d\n", $r, $c['total'],
        $c['reachable'] . ' (' . ($c['total'] ? round(100 * $c['reachable'] / $c['total']) : 0) . '%)',
        $c['assumed']);
}

if ($assumed) {
    echo "\nCountry code was inferred from a leading 0 — confirm these are local numbers,\n";
    echo "because a foreign number typed in its own local form becomes the wrong number:\n";
    echo str_repeat('-', 74) . "\n";
    foreach ($assumed as [$r, $p]) {
        printf("  #%-4d %-10s %-24s %-18s -> %s\n", $r['id'], $r['role'],
            $w(trim($r['first_name'] . ' ' . $r['last_name']), 24), (string)$r['phone_number'], $p['e164']);
    }
}

if ($problems) {
    echo "\nCannot be reached on WhatsApp (" . count($problems) . "):\n";
    echo str_repeat('-', 74) . "\n";
    printf("  %-5s %-10s %-24s %-14s %s\n", 'id', 'role', 'name', 'stored', 'why');
    foreach ($problems as [$r, $p]) {
        $flag = (int)$r['future_lessons'] > 0 ? ' *' : '';
        printf("  #%-4d %-10s %-24s %-14s %s%s\n", $r['id'], $r['role'],
            $w(trim($r['first_name'] . ' ' . $r['last_name']), 24),
            $w((string)$r['phone_number'], 14), phone_reason_text($p['reason']), $flag);
    }
    echo "\n  * has lessons still on the calendar — chase these first.\n";
}

echo "\nEmail is unaffected: reminders go out over email regardless of any of this.\n";
