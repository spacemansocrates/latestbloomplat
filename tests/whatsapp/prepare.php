<?php
/**
 * tests/whatsapp/prepare.php
 * The classroom fixture database, plus what the WhatsApp suite needs on top:
 * the notification tables, an admin, a Mini Admin, phone numbers, and lessons
 * starting inside the reminder window.
 *
 * Same isolation as tests/classroom: 127.0.0.1:33316 only, never the project .env.
 */

declare(strict_types=1);

require_once __DIR__ . '/fixtures.php';

ob_start();
require __DIR__ . '/../classroom/prepare.php';   // drops and rebuilds the database
ob_end_clean();

$root = dirname(__DIR__, 2);
$pdo = ct_pdo();

foreach ([
    $root . '/migrations/2026_09_07_notification_dispatch_log.sql',
    $root . '/migrations/2026_09_14_whatsapp_admin.sql',
] as $file) {
    $pdo->exec(preg_replace('/^\s*--.*$/m', '', file_get_contents($file)));
}
// Re-running the migration must be harmless.
$pdo->exec(preg_replace('/^\s*--.*$/m', '', file_get_contents($root . '/migrations/2026_09_14_whatsapp_admin.sql')));

// Tables that exist on production but not in the tutor test schema. admin_details
// gates the admin area; the other three are read by student/settings.php through
// its payments and packages includes, which fatal without them. Columns are the
// ones those pages select, nothing more.
$pdo->exec("
CREATE TABLE IF NOT EXISTS admin_details (user_id INT PRIMARY KEY, admin_role VARCHAR(50));
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;   -- the student profile save writes it
CREATE TABLE IF NOT EXISTS packages (
  id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100), description TEXT, lessons_per_week INT,
  price DECIMAL(10,2), duration_days INT, is_active INT DEFAULT 1);
CREATE TABLE IF NOT EXISTS student_packages (
  id INT PRIMARY KEY AUTO_INCREMENT, student_id INT, package_id INT, start_date DATE, end_date DATE,
  total_lessons INT, lessons_remaining INT, status VARCHAR(20), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS payments (
  id INT PRIMARY KEY AUTO_INCREMENT, student_package_id INT, amount DECIMAL(10,2), payment_date DATETIME,
  payment_method VARCHAR(30), status VARCHAR(20), transaction_id VARCHAR(100));
");

$pdo->exec("
INSERT INTO users (id, first_name, last_name, email, role, is_active, phone_number) VALUES
  (9,  'Ada', 'Admin',     'ada@example.test', 'Administrator',  1, '+265991000009'),
  (10, 'Mo',  'MiniAdmin', 'mo@example.test',  'QualityControl', 1, NULL);
INSERT INTO admin_details (user_id, admin_role) VALUES (9, 'Admin'), (10, 'Mini Admin');

UPDATE users SET phone_number = '+265991000001' WHERE id = 1;   -- Tara, tutor
UPDATE users SET phone_number = '+265991000002' WHERE id = 2;   -- Sam, student
UPDATE users SET phone_number = '1'             WHERE id = 3;   -- Nina: the junk kind this database is full of
");

// Lessons inside the 15-minute reminder window. scheduled_start_time is UTC.
$at = static fn(int $min) => gmdate('Y-m-d H:i:s', time() + $min * 60);
$ins = $pdo->prepare("INSERT INTO lessons (id, tutor_id, student_id, subject_id, scheduled_start_time, scheduled_end_time, status)
                      VALUES (?, ?, ?, 1, ?, ?, 'Scheduled')");
$ins->execute([501, 1, 2, $at(10), $at(70)]);   // Tara + Sam
$ins->execute([502, 1, 3, $at(12), $at(72)]);   // Tara + Nina (unusable number)

$hash = password_hash(WA_PASSWORD, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ?')->execute([$hash]);

echo "prepared WhatsApp fixtures\n";
