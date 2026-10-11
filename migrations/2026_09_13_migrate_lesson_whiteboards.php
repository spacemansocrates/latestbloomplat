<?php
/**
 * migrations/2026_09_13_migrate_lesson_whiteboards.php
 *
 * Import historic per-lesson boards into the durable workspaces created by
 * 2026_09_13_classroom_workspaces.sql.
 *
 * Run:
 *   php migrations/2026_09_13_migrate_lesson_whiteboards.php            # dry run, prints a plan
 *   php migrations/2026_09_13_migrate_lesson_whiteboards.php --apply    # writes
 *
 * Guarantees, because this runs against boards people care about:
 *
 *  - READ-ONLY on lesson_whiteboards. Nothing is updated or deleted there. If this
 *    migration turns out to be wrong, the original boards are all still sitting in
 *    that table and it can be re-run after deleting the imported pages.
 *
 *  - IDEMPOTENT. Each imported page records source_lesson_id, and
 *    UNIQUE(workspace_id, source_lesson_id) means a second run inserts nothing.
 *    Re-running is a no-op, not a duplicate set of pages.
 *
 *  - ONE PAGE PER LESSON, never a merge. The obvious shortcut -- concatenate every
 *    historic scene for a pair into one board -- would pile a term's worth of
 *    lessons on top of each other at overlapping coordinates and is unrecoverable
 *    once saved. Each lesson's board keeps its own page.
 *
 *  - DETERMINISTIC ORDER. Pages are ordered by the lesson's scheduled_start_time
 *    (id as tie-break), so page order matches the order the lessons happened and
 *    two runs on the same data produce the same result.
 *
 * Initial active page: the LAST page by that ordering, i.e. the most recent lesson's
 * board. That is what the pair were last working on, so it is what should be on
 * screen when they next join. Earlier pages remain one click away.
 *
 * Empty boards are skipped. A lesson_whiteboards row whose scene is NULL or an empty
 * array means the board was never drawn on; importing it would add a blank page for
 * every lesson that only ever used video. The row itself is left untouched.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration is a command-line script.\n");
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/classroom_workspace.php';

$apply = in_array('--apply', $argv, true);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

/**
 * Does a table exist on the connected database?
 *
 * information_schema rather than SHOW TABLES LIKE ?: the app connects with
 * PDO::ATTR_EMULATE_PREPARES => false, and MySQL's native protocol cannot bind a
 * placeholder into a SHOW statement, so the prepare fails outright.
 */
function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

// Confirm the destination tables exist before reading anything, so a half-applied
// deployment fails loudly here rather than part-way through the import.
foreach (['classroom_workspaces', 'classroom_workspace_pages'] as $table) {
    if (!table_exists($pdo, $table)) {
        fwrite(STDERR, "Missing table `$table`. Apply 2026_09_13_classroom_workspaces.sql first.\n");
        exit(1);
    }
}

if (!table_exists($pdo, 'lesson_whiteboards')) {
    echo "No lesson_whiteboards table on this database: nothing to import.\n";
    exit(0);
}

// Ordered by when the lesson happened, so page order reflects lesson order.
$rows = $pdo->query(
    "SELECT w.lesson_id, w.scene, w.files,
            l.id AS lid, l.tutor_id, l.student_id, l.scheduled_start_time
     FROM lesson_whiteboards w
     JOIN lessons l ON l.id = w.lesson_id
     ORDER BY l.scheduled_start_time ASC, l.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

echo ($apply ? "APPLYING" : "DRY RUN") . ": " . count($rows) . " lesson board(s) found\n\n";

$imported = 0;
$skippedEmpty = 0;
$alreadyThere = 0;
$touchedWorkspaces = [];

foreach ($rows as $row) {
    $scene = $row['scene'] !== null ? json_decode((string)$row['scene'], true) : null;
    if (!is_array($scene) || count($scene) === 0) {
        $skippedEmpty++;
        continue;
    }

    $lesson = [
        'id'         => (int)$row['lid'],
        'tutor_id'   => (int)$row['tutor_id'],
        'student_id' => $row['student_id'],
    ];
    $key = cw_workspace_key($lesson);

    if (!$apply) {
        $label = $row['scheduled_start_time'] ?? 'undated';
        echo sprintf("  lesson %-6d -> %-28s (%s, %d elements)\n",
            (int)$row['lesson_id'], $key, $label, count($scene));
        $touchedWorkspaces[$key] = true;
        $imported++;
        continue;
    }

    $ws = cw_get_or_create_workspace($pdo, $lesson);
    $workspaceId = (int)$ws['id'];
    $touchedWorkspaces[$key] = true;

    // The unique key is the real guard; this check just keeps the counters honest.
    $chk = $pdo->prepare(
        "SELECT id FROM classroom_workspace_pages WHERE workspace_id = ? AND source_lesson_id = ? LIMIT 1"
    );
    $chk->execute([$workspaceId, (int)$row['lesson_id']]);
    if ($chk->fetchColumn()) {
        $alreadyThere++;
        continue;
    }

    $pos = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM classroom_workspace_pages WHERE workspace_id = ?");
    $pos->execute([$workspaceId]);
    $position = (int)$pos->fetchColumn();

    $title = $row['scheduled_start_time']
        ? 'Lesson ' . date('j M Y', strtotime((string)$row['scheduled_start_time']))
        : 'Lesson ' . (int)$row['lesson_id'];

    try {
        $pdo->prepare(
            "INSERT INTO classroom_workspace_pages
                (workspace_id, title, position, scene, files, revision, source_lesson_id)
             VALUES (?, ?, ?, ?, ?, 0, ?)"
        )->execute([
            $workspaceId, $title, $position,
            $row['scene'], $row['files'], (int)$row['lesson_id'],
        ]);
        $imported++;
    } catch (PDOException $e) {
        // 23000 = the unique key fired, i.e. a concurrent run already imported it.
        if ($e->getCode() === '23000') { $alreadyThere++; continue; }
        throw $e;
    }
}

// Point each touched workspace at its most recent page.
if ($apply) {
    foreach (array_keys($touchedWorkspaces) as $key) {
        $st = $pdo->prepare("SELECT id FROM classroom_workspaces WHERE workspace_key = ? LIMIT 1");
        $st->execute([$key]);
        $workspaceId = (int)$st->fetchColumn();
        if (!$workspaceId) continue;

        $st = $pdo->prepare(
            "SELECT id FROM classroom_workspace_pages
             WHERE workspace_id = ? AND is_archived = 0
             ORDER BY position DESC, id DESC LIMIT 1"
        );
        $st->execute([$workspaceId]);
        $pageId = (int)$st->fetchColumn();
        if ($pageId) {
            $pdo->prepare("UPDATE classroom_workspaces SET active_page_id = ? WHERE id = ?")
                ->execute([$pageId, $workspaceId]);
        }
    }
}

echo "\n";
echo "  workspaces touched : " . count($touchedWorkspaces) . "\n";
echo "  pages imported     : $imported\n";
echo "  already imported   : $alreadyThere\n";
echo "  skipped (empty)    : $skippedEmpty\n";
echo $apply
    ? "\nDone. lesson_whiteboards was not modified.\n"
    : "\nDry run only. Re-run with --apply to write.\n";
