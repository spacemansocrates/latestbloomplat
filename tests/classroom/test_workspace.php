<?php
/**
 * tests/classroom/test_workspace.php
 *
 * Behaviour tests for the durable whiteboard workspace: identity, authorization,
 * concurrency, and the historic-board migration.
 *
 * These assert what the code DOES -- rows written, rows refused, values returned --
 * not that particular strings appear in the source.
 *
 * Run: php tests/classroom/prepare.php && php tests/classroom/test_workspace.php
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

function lesson(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT id, tutor_id, student_id FROM lessons WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
}

echo "--- workspace identity ---\n";

$l101 = lesson($pdo, 101);
$l103 = lesson($pdo, 103);
$l201 = lesson($pdo, 201);
$l401 = lesson($pdo, 401);
$l402 = lesson($pdo, 402);

check(cw_workspace_key($l101) === cw_workspace_key($l103),
    'two lessons for the same tutor+student share one workspace key');
check(cw_workspace_key($l101) !== cw_workspace_key($l201),
    'a different student for the same tutor gets a different workspace key');
check(cw_workspace_key($l401) !== cw_workspace_key($l402),
    'two demo lessons do NOT share a workspace (no null-student pile-up)');
check(cw_workspace_key($l401) === 'demo:401',
    'demo workspaces are keyed by their own lesson');

echo "\n--- authorization ---\n";

check(cw_authorize_lesson($pdo, 101, 1, 'Tutor') !== null,
    'the lesson tutor is authorized');
check(cw_authorize_lesson($pdo, 101, 2, 'Student') !== null,
    'the lesson student is authorized');
check(cw_authorize_lesson($pdo, 101, 4, 'Tutor') === null,
    'an unrelated tutor is refused');
check(cw_authorize_lesson($pdo, 101, 5, 'Student') === null,
    'an unrelated student is refused');
check(cw_authorize_lesson($pdo, 101, 1, 'Student') === null,
    'the tutor presenting a Student role is refused (role and id must both match)');
check(cw_authorize_lesson($pdo, 101, 2, 'Tutor') === null,
    'the student presenting a Tutor role is refused');
check(cw_authorize_lesson($pdo, 999999, 1, 'Tutor') === null,
    'a non-existent lesson is refused');
check(cw_authorize_lesson($pdo, 101, 6, 'Administrator') === null,
    'an administrator is not silently admitted to a private board');
check(cw_authorize_lesson($pdo, 401, 1, 'Tutor') !== null,
    'a demo lesson with no student still authorizes its tutor');

echo "\n--- workspace creation is stable ---\n";

$wsA = cw_get_or_create_workspace($pdo, $l101);
$wsB = cw_get_or_create_workspace($pdo, $l103);
check((int)$wsA['id'] === (int)$wsB['id'],
    'lesson A and a later lesson B for the same pair resolve to the SAME workspace');

$wsNina = cw_get_or_create_workspace($pdo, $l201);
check((int)$wsNina['id'] !== (int)$wsA['id'],
    'a different relationship gets its own workspace');

$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM classroom_workspaces")->fetchColumn();
cw_get_or_create_workspace($pdo, $l101);
cw_get_or_create_workspace($pdo, $l101);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM classroom_workspaces")->fetchColumn();
check($countBefore === $countAfter,
    'resolving an existing workspace repeatedly creates no duplicates');

echo "\n--- pages and revisions ---\n";

$page = cw_resolve_active_page($pdo, $wsA);
check((int)$page['revision'] === 0, 'a fresh page starts at revision 0');

$scene1 = json_encode([['id' => 'a', 'version' => 1]]);
$r1 = cw_save_page($pdo, (int)$page['id'], (int)$wsA['id'], 0, $scene1, null, 1);
check($r1['ok'] === true && $r1['revision'] === 1, 'a save based on the current revision is accepted');

$scene2 = json_encode([['id' => 'a', 'version' => 2]]);
$r2 = cw_save_page($pdo, (int)$page['id'], (int)$wsA['id'], 1, $scene2, null, 2);
check($r2['ok'] === true && $r2['revision'] === 2, 'the next save on the new revision is accepted');

// The stale-client case: a tab that loaded revision 1 and never saw revision 2.
$stale = cw_save_page($pdo, (int)$page['id'], (int)$wsA['id'], 1, json_encode([]), null, 1);
check($stale['ok'] === false && $stale['reason'] === 'conflict',
    'a save based on a superseded revision is REFUSED, not applied');
check(($stale['revision'] ?? 0) === 2,
    'the conflict reports the revision the server actually holds');
check(is_array($stale['scene']) && $stale['scene'][0]['version'] === 2,
    'the conflict hands back the newer scene so the client can merge');

$still = cw_page_in_workspace($pdo, (int)$page['id'], (int)$wsA['id']);
check(json_decode($still['scene'], true)[0]['version'] === 2,
    'the stale empty save did NOT overwrite the stored board');

echo "\n--- cross-workspace page access ---\n";

$ninaPage = cw_resolve_active_page($pdo, $wsNina);
check(cw_page_in_workspace($pdo, (int)$ninaPage['id'], (int)$wsA['id']) === null,
    "a page from another relationship is not reachable via this lesson's workspace");

$crossSave = cw_save_page($pdo, (int)$ninaPage['id'], (int)$wsA['id'], 0, json_encode([]), null, 1);
check($crossSave['ok'] === false && $crossSave['reason'] === 'not_found',
    'writing to another workspace\'s page is refused');

$ninaStill = cw_page_in_workspace($pdo, (int)$ninaPage['id'], (int)$wsNina['id']);
check((int)$ninaStill['revision'] === 0,
    'the other workspace\'s page was left untouched by the attempt');

echo "\n--- new pages ---\n";

$pagesBefore = count(cw_list_pages($pdo, (int)$wsA['id']));
$newPage = cw_create_page($pdo, (int)$wsA['id'], 'Fresh page');
$pagesAfter = cw_list_pages($pdo, (int)$wsA['id']);
check(count($pagesAfter) === $pagesBefore + 1, 'a new page is appended to the workspace');
check((int)$newPage['position'] > (int)$page['position'], 'the new page sorts after the old one');

$wsReloaded = $pdo->query("SELECT * FROM classroom_workspaces WHERE id = " . (int)$wsA['id'])->fetch();
check((int)$wsReloaded['active_page_id'] === (int)$newPage['id'],
    'creating a page makes it the active one');

$oldStill = cw_page_in_workspace($pdo, (int)$page['id'], (int)$wsA['id']);
check(json_decode($oldStill['scene'], true)[0]['version'] === 2,
    'starting a fresh page does not lose the previous page\'s work');

echo "\n";
echo "passed: $passed\n";
if ($failed) {
    echo "FAILED: " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
echo "all green\n";
