<?php
/**
 * tests/classroom/test_migration.php
 *
 * Verify the state the historic-board migration leaves behind.
 *
 * Assumes prepare.php has been run and the migration applied (see run.sh / the
 * commands in docs/classroom-workspaces.md). Checks the outcome in the database
 * rather than the script's own console output.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__, 2) . '/utils/classroom_workspace.php';

$pdo = ct_pdo();

$passed = 0;
$failed = [];

function check(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed[] = $label; echo "FAIL  $label\n"; }
}

function ws(PDO $pdo, string $key): ?array {
    $st = $pdo->prepare("SELECT * FROM classroom_workspaces WHERE workspace_key = ?");
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

echo "--- historic boards became workspace pages ---\n";

$pair12 = ws($pdo, 'pair:1:2');
check($pair12 !== null, 'the Tara+Sam workspace exists');

$pages = cw_list_pages($pdo, (int)$pair12['id']);
check(count($pages) === 2, 'both of that pair\'s lessons became pages (got ' . count($pages) . ')');

$sources = array_map(fn($p) => (int)$p['source_lesson_id'], $pages);
check($sources === [101, 102],
    'pages are ordered by when the lesson happened, not by lesson id or insert order');

echo "\n--- scenes survived intact, not merged ---\n";

$st = $pdo->prepare("SELECT scene FROM classroom_workspace_pages WHERE workspace_id = ? AND source_lesson_id = ?");
$st->execute([(int)$pair12['id'], 101]);
$scene101 = json_decode((string)$st->fetchColumn(), true);
$st->execute([(int)$pair12['id'], 102]);
$scene102 = json_decode((string)$st->fetchColumn(), true);

check(count($scene101) === 2 && $scene101[0]['id'] === 'L101-el-0',
    'lesson 101\'s scene is on its own page, unchanged');
check(count($scene102) === 3 && $scene102[0]['id'] === 'L102-el-0',
    'lesson 102\'s scene is on its own page, unchanged');
check($scene101[0]['id'] !== $scene102[0]['id'],
    'the two lessons were NOT flattened on top of each other');

echo "\n--- the active page is the most recent lesson ---\n";

$st = $pdo->prepare("SELECT source_lesson_id FROM classroom_workspace_pages WHERE id = ?");
$st->execute([(int)$pair12['active_page_id']]);
check((int)$st->fetchColumn() === 102,
    'the workspace opens on the latest lesson\'s board (102), with 101 still available');

echo "\n--- isolation ---\n";

$pair13 = ws($pdo, 'pair:1:3');
$pair45 = ws($pdo, 'pair:4:5');
check($pair13 !== null && $pair45 !== null, 'the other relationships have their own workspaces');
check(count(cw_list_pages($pdo, (int)$pair13['id'])) === 1,
    'Tara+Nina has only its own lesson, not Sam\'s');
check(count(cw_list_pages($pdo, (int)$pair45['id'])) === 1,
    'the unrelated pair has only its own lesson');

$demo401 = ws($pdo, 'demo:401');
$demo402 = ws($pdo, 'demo:402');
check($demo401 !== null && $demo402 !== null && (int)$demo401['id'] !== (int)$demo402['id'],
    'the two demo lessons stayed in separate workspaces');
check(count(cw_list_pages($pdo, (int)$demo401['id'])) === 1,
    'a demo workspace holds only its own board');

echo "\n--- empty boards were skipped ---\n";

$st = $pdo->query("SELECT COUNT(*) FROM classroom_workspace_pages WHERE source_lesson_id = 103");
check((int)$st->fetchColumn() === 0,
    'the never-drawn-on board (lesson 103) produced no blank page');

echo "\n--- the source table was not touched ---\n";

$st = $pdo->query("SELECT COUNT(*) FROM lesson_whiteboards");
check((int)$st->fetchColumn() === 7, 'all 7 original lesson_whiteboards rows are still there');

$st = $pdo->query("SELECT scene FROM lesson_whiteboards WHERE lesson_id = 101");
$orig = json_decode((string)$st->fetchColumn(), true);
check(count($orig) === 2 && $orig[0]['id'] === 'L101-el-0',
    'the original board content is unchanged and still recoverable');

echo "\n--- continuity: lesson B opens lesson A's board ---\n";

// The actual user-facing promise: a NEW lesson for the same pair resolves to the
// workspace holding the previous lessons' work.
$st = $pdo->prepare("SELECT id, tutor_id, student_id FROM lessons WHERE id = ?");
$st->execute([103]);
$lessonB = $st->fetch();

$wsForB = cw_get_or_create_workspace($pdo, $lessonB);
check((int)$wsForB['id'] === (int)$pair12['id'],
    'a brand-new lesson for the same pair resolves to the existing workspace');

$activePage = cw_resolve_active_page($pdo, $wsForB);
$sceneB = json_decode((string)$activePage['scene'], true);
check(is_array($sceneB) && count($sceneB) === 3 && $sceneB[0]['id'] === 'L102-el-0',
    'joining that new lesson shows the previous lesson\'s drawing, not a blank board');

echo "\n";
echo "passed: $passed\n";
if ($failed) {
    echo "FAILED: " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
echo "all green\n";
