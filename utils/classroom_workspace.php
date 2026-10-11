<?php
/**
 * utils/classroom_workspace.php
 *
 * Resolving which whiteboard workspace a request is allowed to touch.
 *
 * The one rule this file exists to enforce: a workspace is never named by the
 * client. The caller supplies a lesson id and a session; everything else --
 * which workspace, which pages, whether this person may write to it -- is derived
 * here from the lessons row. A workspace id arriving in a query string is not
 * trusted as authorization for anything, because knowing an integer is not the
 * same as being party to the relationship it belongs to.
 *
 * Continuity is keyed to the tutor-student pair, not the lesson, which is the
 * whole point: lesson B for the same pair resolves to the same workspace as
 * lesson A and therefore opens last week's board.
 */

declare(strict_types=1);

/**
 * Deterministic workspace key for a lesson row.
 *
 * A demo lesson has no student. Keying demos by pair would give every demo a
 * tutor ever ran the same shared board (they all have student_id NULL), so they
 * are keyed by their own lesson instead and stay isolated from each other and
 * from real relationships.
 *
 * @param array $lesson  row with tutor_id, student_id, id
 */
function cw_workspace_key(array $lesson): string {
    $tutorId  = (int)($lesson['tutor_id'] ?? 0);
    $studentId = $lesson['student_id'] === null || $lesson['student_id'] === ''
        ? null
        : (int)$lesson['student_id'];

    if ($studentId === null || $studentId <= 0) {
        return 'demo:' . (int)($lesson['id'] ?? 0);
    }
    return 'pair:' . $tutorId . ':' . $studentId;
}

/**
 * Load the lesson and confirm the caller is a party to it.
 *
 * Returns null when the lesson does not exist OR the caller is not its tutor or
 * its student -- deliberately the same answer for both, so this cannot be used to
 * probe which lesson ids exist.
 *
 * @return array|null  lesson row, plus 'viewer_role' => 'tutor'|'student'
 */
function cw_authorize_lesson(PDO $pdo, int $lessonId, int $userId, string $role): ?array {
    if ($lessonId <= 0 || $userId <= 0) return null;
    if (!in_array($role, ['Student', 'Tutor'], true)) return null;

    $st = $pdo->prepare(
        "SELECT id, tutor_id, student_id, status, scheduled_start_time
         FROM lessons WHERE id = ? LIMIT 1"
    );
    $st->execute([$lessonId]);
    $lesson = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) return null;

    // Role and id must BOTH match. Checking only the id would let a compromised or
    // mis-set session role act on the wrong side of a lesson it happens to appear in.
    if ($role === 'Tutor' && (int)$lesson['tutor_id'] === $userId) {
        $lesson['viewer_role'] = 'tutor';
        return $lesson;
    }
    if ($role === 'Student' && $lesson['student_id'] !== null && (int)$lesson['student_id'] === $userId) {
        $lesson['viewer_role'] = 'student';
        return $lesson;
    }
    return null;
}

/**
 * Find or create the workspace for an already-authorized lesson.
 *
 * Concurrent first-joins are the interesting case: tutor and student can open the
 * room in the same second and both find no workspace. INSERT ... ON DUPLICATE KEY
 * against the unique workspace_key means the loser of that race updates the winner's
 * row instead of creating a second workspace, so the pair cannot end up with two
 * boards. The row is then re-read rather than relying on lastInsertId, which reports
 * the existing row's id inconsistently across MySQL versions after an ODKU no-op.
 */
function cw_get_or_create_workspace(PDO $pdo, array $lesson): array {
    $key = cw_workspace_key($lesson);
    $tutorId = (int)$lesson['tutor_id'];
    $studentId = ($lesson['student_id'] === null || (int)$lesson['student_id'] <= 0)
        ? null : (int)$lesson['student_id'];
    $demoLessonId = $studentId === null ? (int)$lesson['id'] : null;

    $pdo->prepare(
        "INSERT INTO classroom_workspaces (workspace_key, tutor_id, student_id, demo_lesson_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE tutor_id = VALUES(tutor_id)"
    )->execute([$key, $tutorId, $studentId, $demoLessonId]);

    $st = $pdo->prepare("SELECT * FROM classroom_workspaces WHERE workspace_key = ? LIMIT 1");
    $st->execute([$key]);
    $ws = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ws) {
        throw new RuntimeException('workspace could not be resolved for ' . $key);
    }
    return $ws;
}

/**
 * Pages of a workspace, oldest first. Archived pages are excluded unless asked for.
 */
function cw_list_pages(PDO $pdo, int $workspaceId, bool $includeArchived = false): array {
    $sql = "SELECT id, title, position, revision, is_archived, source_lesson_id, updated_at
            FROM classroom_workspace_pages WHERE workspace_id = ?";
    if (!$includeArchived) $sql .= " AND is_archived = 0";
    $sql .= " ORDER BY position ASC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$workspaceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The page a room should open on.
 *
 * Order of preference, and why:
 *   1. workspace.active_page_id, when it still points at a live page -- this is
 *      what the pair were last working on, which is what they expect to see.
 *   2. the last page by position -- for a freshly migrated workspace that is the
 *      most recent lesson's board, because the migration orders imported pages by
 *      the lesson's scheduled start.
 *   3. a new empty page, created on demand, for a workspace that has none yet.
 *
 * Creating the first page lazily (rather than in the migration) means a pair who
 * never used the board do not accumulate empty rows.
 */
function cw_resolve_active_page(PDO $pdo, array $workspace): array {
    $workspaceId = (int)$workspace['id'];

    if (!empty($workspace['active_page_id'])) {
        $st = $pdo->prepare(
            "SELECT * FROM classroom_workspace_pages
             WHERE id = ? AND workspace_id = ? AND is_archived = 0 LIMIT 1"
        );
        $st->execute([(int)$workspace['active_page_id'], $workspaceId]);
        $page = $st->fetch(PDO::FETCH_ASSOC);
        if ($page) return $page;
    }

    $st = $pdo->prepare(
        "SELECT * FROM classroom_workspace_pages
         WHERE workspace_id = ? AND is_archived = 0
         ORDER BY position DESC, id DESC LIMIT 1"
    );
    $st->execute([$workspaceId]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if ($page) return $page;

    return cw_create_page($pdo, $workspaceId, 'Page 1');
}

/**
 * Append a new blank page and make it the active one.
 */
function cw_create_page(PDO $pdo, int $workspaceId, ?string $title = null): array {
    $st = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM classroom_workspace_pages WHERE workspace_id = ?");
    $st->execute([$workspaceId]);
    $position = (int)$st->fetchColumn();

    $title = $title !== null && trim($title) !== ''
        ? mb_substr(trim($title), 0, 120)
        : 'Page ' . $position;

    $pdo->prepare(
        "INSERT INTO classroom_workspace_pages (workspace_id, title, position, scene, files, revision)
         VALUES (?, ?, ?, NULL, NULL, 0)"
    )->execute([$workspaceId, $title, $position]);

    $pageId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE classroom_workspaces SET active_page_id = ? WHERE id = ?")
        ->execute([$pageId, $workspaceId]);

    $st = $pdo->prepare("SELECT * FROM classroom_workspace_pages WHERE id = ? LIMIT 1");
    $st->execute([$pageId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/**
 * Confirm a page belongs to a workspace the caller already proved access to.
 *
 * Always call this before acting on a client-supplied page id. It is the check that
 * stops "page 7" from one relationship being opened or overwritten from another
 * relationship's lesson room.
 */
function cw_page_in_workspace(PDO $pdo, int $pageId, int $workspaceId): ?array {
    if ($pageId <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM classroom_workspace_pages WHERE id = ? AND workspace_id = ? LIMIT 1");
    $st->execute([$pageId, $workspaceId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Revision-checked save. Returns ['ok'=>true,'revision'=>N] or ['ok'=>false,...].
 *
 * The UPDATE carries the expected revision in its WHERE clause, so two saves racing
 * on the same page cannot both win: whichever commits second matches zero rows and
 * is told to reconcile. This is what stops a stale tab, a retried request that was
 * merely slow, or a reconnecting client from overwriting newer work -- the failure
 * mode the old lesson-keyed endpoint had no defence against at all.
 *
 * rowCount() of 0 is ambiguous in MySQL: it also means "matched, but every column
 * was already identical". The revision column is incremented on every accepted
 * save, so a genuine match always changes at least that one value and the
 * ambiguity cannot arise here.
 */
function cw_save_page(PDO $pdo, int $pageId, int $workspaceId, int $expectedRevision,
                      ?string $sceneJson, ?string $filesJson, int $userId): array {
    $st = $pdo->prepare(
        "UPDATE classroom_workspace_pages
         SET scene = ?, files = ?, revision = revision + 1, updated_by = ?
         WHERE id = ? AND workspace_id = ? AND revision = ?"
    );
    $st->execute([$sceneJson, $filesJson, $userId, $pageId, $workspaceId, $expectedRevision]);

    if ($st->rowCount() === 1) {
        return ['ok' => true, 'revision' => $expectedRevision + 1];
    }

    $current = cw_page_in_workspace($pdo, $pageId, $workspaceId);
    if (!$current) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    return [
        'ok'       => false,
        'reason'   => 'conflict',
        'revision' => (int)$current['revision'],
        'scene'    => $current['scene'] !== null ? json_decode($current['scene'], true) : null,
        'files'    => $current['files'] !== null ? json_decode($current['files'], true) : null,
    ];
}
