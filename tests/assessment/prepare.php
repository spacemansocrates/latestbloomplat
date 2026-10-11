<?php
/**
 * tests/assessment/prepare.php
 * The WhatsApp fixture database (users with known passwords, packages tables),
 * plus the assessments migration and two attempts:
 *   1: Sam (student 2) with Tara (tutor 1), assigned, parent recorded
 *   2: Nina (student 3) with Otto (tutor 4), assigned
 *
 * Same isolation as tests/classroom: 127.0.0.1:33316 only, never the project .env.
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/../whatsapp/prepare.php';   // drops and rebuilds the database
ob_end_clean();

$root = dirname(__DIR__, 2);
$pdo = ct_pdo();

$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($root . '/migrations/2026_09_24_assessments.sql'));
$pdo->exec($sql);
$pdo->exec($sql);   // re-running the migration must be harmless
$sqlB = preg_replace('/^\s*--.*$/m', '', file_get_contents($root . '/migrations/2026_09_24b_assessment_pace_email.sql'));
$pdo->exec($sqlB);
$pdo->exec($sqlB);

// Columns production has that the tutor test schema does not, all written by
// admin/assessments.php when it creates a demo student.
$pdo->exec("
ALTER TABLE users ADD COLUMN IF NOT EXISTS customer_code VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(120) NULL;
ALTER TABLE tutor_student_assignments ADD COLUMN IF NOT EXISTS assigned_by_admin_id INT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS provider_session_id VARCHAR(255) NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS notes TEXT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS confirmed_by_admin_id INT NULL;
");
$sqlC = preg_replace('/^\s*--.*$/m', '', file_get_contents($root . '/migrations/2026_09_24c_stripe_subscriptions.sql'));
$pdo->exec($sqlC);
$pdo->exec($sqlC);

$pdo->exec("
INSERT INTO assessment_attempts (id, student_id, tutor_id, assessment_id, assessment_version, status, parent_name, parent_email, exam_board, exam_soon)
VALUES (1, 2, 1, 'ks1-maths', 1, 'assigned', 'Pat Parent', 'pat@example.test', NULL, 0),
       (2, 3, 4, 'ks1-maths', 1, 'assigned', NULL, NULL, NULL, 0);
");

// 3: Sam (student 2) again with Tara, a second subject already submitted, with a written answer still to mark:
// for the admin "review and send" path.
$pdo->prepare("INSERT INTO assessment_attempts
    (id, student_id, tutor_id, assessment_id, assessment_version, status, parent_name, parent_email, pace, answers, started_at, submitted_at)
    VALUES (3, 2, 1, 'ks1-maths', 1, 'submitted', 'Pat Parent', 'pat@example.test', 'term', ?, NOW(), NOW())")
    ->execute([json_encode(['q01' => 1, 'q02' => '12', 'q03' => 0, 'q04' => 3, 'q05' => 'fifteen', 'q06' => '8',
                            'q07' => '15', 'q08' => '100', 'q09' => 1, 'q10' => 2, 'q11' => [0, 2],
                            'q12' => "Line them up <script>x()</script> and use a ruler."])]);

echo "prepared assessment fixtures\n";
