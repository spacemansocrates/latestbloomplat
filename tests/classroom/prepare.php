<?php
/**
 * tests/classroom/prepare.php
 *
 * Build a disposable database for the classroom-workspace tests.
 *
 * Isolation, deliberately: this connects to 127.0.0.1:33316 (the throwaway MariaDB
 * the tutor test harness already uses) and NEVER reads the project's .env. That
 * matters here more than usual -- this repository's .env points DB_HOST at the
 * production Hostinger database, so anything that loads config/db_connect.php on
 * this machine is talking to live customer data. Tests must not.
 *
 * Run: php tests/classroom/prepare.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$root = dirname(__DIR__, 2);

$pdo = new PDO(
    'mysql:host=' . CT_DB_HOST . ';port=' . CT_DB_PORT,
    CT_DB_USER, CT_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('DROP DATABASE IF EXISTS `' . CT_DB_NAME . '`');
$pdo->exec('CREATE DATABASE `' . CT_DB_NAME . '` CHARACTER SET utf8mb4');
$pdo->exec('USE `' . CT_DB_NAME . '`');

foreach ([
    $root . '/tests/tutor/schema.sql',
    $root . '/tests/classroom/schema_additions.sql',
    $root . '/migrations/2026_09_13_classroom_workspaces.sql',
    $root . '/migrations/2026_09_13_classroom_book_state.sql',
] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing schema file: $file\n");
        exit(1);
    }
    // Strip comment-only lines so multi-statement execution is not confused by the
    // long explanatory headers these files carry.
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    $pdo->exec($sql);
}

// --- fixtures -------------------------------------------------------------
// tests/tutor/schema.sql seeds its own users, lessons and subjects for the tutor
// regression suite. Those ids collide with the ones below, and the classroom tests
// need a cast built for isolation cases rather than for tutor pages, so the seeded
// rows in just these three tables are cleared and replaced. Everything else the
// tutor schema sets up is left alone.
foreach (['lessons', 'subjects', 'users', 'learning_materials',
          'homework', 'homework_submissions', 'homework_attachments',
          'submission_attachments'] as $table) {
    $pdo->exec("DELETE FROM `$table`");
}

// Two tutors, three students, one unrelated pair, and two demo lessons, so the tests
// can prove isolation rather than just the happy path.
$pdo->exec("
INSERT INTO users (id, first_name, last_name, email, role, is_active) VALUES
  (1, 'Tara',  'Tutor',   'tara@example.test',  'Tutor',   1),
  (2, 'Sam',   'Student', 'sam@example.test',   'Student', 1),
  (3, 'Nina',  'Student', 'nina@example.test',  'Student', 1),
  (4, 'Otto',  'Tutor',   'otto@example.test',  'Tutor',   1),
  (5, 'Pia',   'Student', 'pia@example.test',   'Student', 1);

INSERT INTO subjects (id, name, curriculum, year_level) VALUES (1, 'Maths', 'CAPS', 9);

-- Tara + Sam meet three times. These must all share one workspace.
INSERT INTO lessons (id, tutor_id, student_id, subject_id, scheduled_start_time, scheduled_end_time, status) VALUES
  (101, 1, 2, 1, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 'Completed'),
  (102, 1, 2, 1, '2026-09-08 09:00:00', '2026-09-08 10:00:00', 'Completed'),
  (103, 1, 2, 1, '2026-09-15 09:00:00', '2026-09-15 10:00:00', 'Scheduled'),
  -- Tara + Nina: a different relationship, must not see Sam's board.
  (201, 1, 3, 1, '2026-09-02 09:00:00', '2026-09-02 10:00:00', 'Completed'),
  -- Otto + Pia: unrelated entirely.
  (301, 4, 5, 1, '2026-09-03 09:00:00', '2026-09-03 10:00:00', 'Completed'),
  -- Two demo lessons for Tara, no student. Must NOT share a workspace.
  (401, 1, NULL, 1, '2026-09-04 09:00:00', '2026-09-04 10:00:00', 'Completed'),
  (402, 1, NULL, 1, '2026-09-05 09:00:00', '2026-09-05 10:00:00', 'Completed');
");

// Historic per-lesson boards, as the old lesson-keyed endpoint would have left them.
function scene_json(string $marker, int $count = 2): string {
    $els = [];
    for ($i = 0; $i < $count; $i++) {
        $els[] = [
            'id' => $marker . '-el-' . $i, 'type' => 'rectangle',
            'x' => 10 * $i, 'y' => 10 * $i, 'width' => 50, 'height' => 50,
            'version' => 1, 'versionNonce' => 1234 + $i, 'isDeleted' => false,
        ];
    }
    return json_encode($els);
}

$ins = $pdo->prepare("INSERT INTO lesson_whiteboards (lesson_id, scene, files, updated_by) VALUES (?, ?, ?, ?)");
$ins->execute([101, scene_json('L101'), json_encode(new stdClass()), 1]);
$ins->execute([102, scene_json('L102', 3), json_encode(new stdClass()), 1]);
$ins->execute([201, scene_json('L201'), null, 1]);
$ins->execute([301, scene_json('L301'), null, 4]);
$ins->execute([401, scene_json('L401'), null, 1]);
$ins->execute([402, scene_json('L402'), null, 1]);
// An empty board: never drawn on. The migration should skip it, not create a blank page.
$ins->execute([103, json_encode([]), null, 1]);

// Materials and attachment rows for the file-gateway security tests: one published
// material, one unpublished, plus a homework attachment and a submission that used to
// be reachable through api/file_url.php by any signed-in user who knew the filename.
$pdo->exec("
INSERT INTO learning_materials (id, title, material_type, file_url, is_published) VALUES
  (1, 'Published Workbook',   'PDF', '/uploads/materials/published.pdf',   1),
  (2, 'Unpublished Draft',    'PDF', '/uploads/materials/unpublished.pdf', 0);

INSERT INTO homework (id, tutor_id, student_id, title, due_date)
  VALUES (1, 1, 2, 'Fractions', '2026-09-20 00:00:00');
INSERT INTO homework_attachments (id, homework_id, file_name, file_url)
  VALUES (1, 1, 'Assignment.pdf', '/uploads/homework/assignment.pdf');
INSERT INTO homework_submissions (id, homework_id, student_id, submission_date, content, status)
  VALUES (1, 1, 2, NOW(), 'done', 'Submitted');
INSERT INTO submission_attachments (id, submission_id, file_name, file_url)
  VALUES (1, 1, 'MyAnswers.pdf', '/uploads/submissions/private.pdf');
");

// Every fixture user shares one password so the HTTP tests can sign in as any of
// them. Hashed with the app's own algorithm rather than stored in plain text, so
// the fixture exercises the real authenticate.php path.
$hash = password_hash('ClassroomTest123!', PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ?')->execute([$hash]);

$pdo->exec("INSERT INTO subjects (id, name, curriculum, year_level) VALUES
    (2, 'History', 'CAPS', 9), (3, 'Science', 'CAPS', 9);
    INSERT INTO lessons (id, tutor_id, student_id, subject_id, status) VALUES
    (104, 1, 2, 3, 'Scheduled'), (105, 1, 2, NULL, 'Scheduled');");
$q = $pdo->prepare('INSERT INTO learning_materials
    (id, title, description, material_type, file_url, is_published, subject_id, grade_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [10, "Maths <img src=x onerror=alert(1)> tutor's book", '<b>Fractions & algebra</b>', 'Book', '/uploads/materials/book-maths.pdf', 1, 1, 9],
    [11, 'A history book', 'Four pages to explore', 'Book', '/uploads/materials/book-history.pdf', 1, 2, 9],
    [12, 'Private book', 'Unpublished', 'Book', '/uploads/materials/book-private.pdf', 0, 1, 9],
    [13, 'Missing maths book', 'File was not migrated', 'Book', '/uploads/materials/book-missing.pdf', 1, 1, 9],
    [14, 'Not a PDF', '', 'Book', '/uploads/materials/book.docx', 1, 1, 9],
    [15, 'Worksheet PDF', '', 'Worksheet', '/uploads/materials/worksheet.pdf', 1, 1, 9],
] as $row) $q->execute($row);

echo "prepared " . CT_DB_NAME . " on " . CT_DB_HOST . ":" . CT_DB_PORT . "\n";
