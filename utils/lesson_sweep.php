<?php
/**
 * Closing off lessons that ended without anyone recording an outcome.
 *
 * Both dashboards used to do this inline, with a copy each, and both went on the
 * clock alone: any Scheduled lesson past its end time became a NoShow. That made
 * no-shows out of lessons people had actually attended, and NoShow pays the tutor
 * half. The rules live here now so the two pages cannot drift apart again.
 *
 * Two different absences, deliberately handled differently:
 *
 *   The student did not come  -> the lesson is a NoShow. Nothing is owed; the
 *                                catch-up trigger no longer raises a request for
 *                                these (see database/2026-09-04_no_catchup_for_student_noshow.sql).
 *   The tutor did not come    -> the student is owed a make-up, so the lesson is
 *                                cancelled on the tutor's side and a TutorNoShow
 *                                request is raised.
 *
 * Lessons where BOTH turned up are never touched here. Only the tutor can say
 * what happened, and the outcome drives their pay, so those surface in the
 * dashboard's "needs finishing" panel instead.
 */

// scheduled_end_time and the join times are not reliably on the same clock in
// this database, so nothing is closed until it is well past. Drop this to
// something small once that is settled.
const SWEEP_GRACE_HOURS = 3;

// A tutor absence from months ago is history, not an obligation. Raising a
// make-up request for it now would just confuse the student.
const SWEEP_TUTOR_WINDOW_DAYS = 14;

/**
 * Build the "scoped to one person" fragment both sweeps need.
 *
 * @return array{0:string,1:array} SQL fragment and its bound values
 */
function sweep_scope(?int $tutorId, ?int $studentId): array
{
    $sql = '';
    $args = [];
    if ($tutorId !== null) {
        $sql .= ' AND l.tutor_id = ?';
        $args[] = $tutorId;
    }
    if ($studentId !== null) {
        $sql .= ' AND l.student_id = ?';
        $args[] = $studentId;
    }
    return [$sql, $args];
}

/**
 * The student never opened the room and the lesson is well over: that is a NoShow.
 *
 * InProgress is included because nothing else ever resolved those, so they sat
 * open indefinitely, paid nothing and vanished from both dashboards after an hour.
 *
 * @return int lessons closed
 */
function sweep_absent_students(PDO $pdo, ?int $tutorId = null, ?int $studentId = null): int
{
    [$scope, $args] = sweep_scope($tutorId, $studentId);

    $stmt = $pdo->prepare("
        UPDATE lessons l
           SET l.status = 'NoShow'
         WHERE l.status IN ('Scheduled', 'InProgress')
           AND l.scheduled_end_time < DATE_SUB(NOW(), INTERVAL " . SWEEP_GRACE_HOURS . " HOUR)
           AND l.student_join_time IS NULL
           {$scope}
    ");
    $stmt->execute($args);
    return $stmt->rowCount();
}

/**
 * The student turned up and the tutor never did. The lesson is cancelled on the
 * tutor's side and the student is queued for a make-up.
 *
 * The request is inserted BEFORE the status change on purpose. trg_after_lesson_catchup
 * fires on that change and would otherwise write reason 'CancelledByTutor'; its
 * NOT EXISTS guard sees this row and stands down, so the more accurate
 * 'TutorNoShow' survives.
 *
 * A caveat that limits this to recent lessons: tutor_join_time only became
 * meaningful when lessons moved to LiveKit. 179 of the 211 completed lessons in
 * this database have no tutor join time at all, because the meeting providers
 * eras never recorded one. A null therefore does NOT mean "absent" on old rows.
 * Restricting to unresolved lessons inside a short window keeps this honest:
 * InProgress is only ever set by the LiveKit token endpoints, so those rows are
 * from the era where the column is trustworthy.
 *
 * @return int[] ids of the lessons closed
 */
function sweep_absent_tutors(PDO $pdo, ?int $tutorId = null, ?int $studentId = null): array
{
    [$scope, $args] = sweep_scope($tutorId, $studentId);

    $stmt = $pdo->prepare("
        SELECT l.id, l.student_id, l.tutor_id
          FROM lessons l
         WHERE l.status IN ('Scheduled', 'InProgress')
           AND l.scheduled_end_time < DATE_SUB(NOW(), INTERVAL " . SWEEP_GRACE_HOURS . " HOUR)
           AND l.scheduled_end_time > DATE_SUB(NOW(), INTERVAL " . SWEEP_TUTOR_WINDOW_DAYS . " DAY)
           AND l.student_join_time IS NOT NULL
           AND l.tutor_join_time IS NULL
           AND l.student_id IS NOT NULL
           AND l.suppress_catchup = 0
           {$scope}
    ");
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $raise  = $pdo->prepare("
        INSERT INTO catchup_requests (original_lesson_id, student_id, tutor_id, reason, status)
        SELECT ?, ?, ?, 'TutorNoShow', 'Open'
          FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM catchup_requests cr WHERE cr.original_lesson_id = ?)
    ");
    $cancel = $pdo->prepare("
        UPDATE lessons SET status = 'CancelledByTutor'
         WHERE id = ? AND status IN ('Scheduled', 'InProgress')
    ");

    $done = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $pdo->beginTransaction();
        try {
            $raise->execute([$id, (int) $r['student_id'], (int) $r['tutor_id'], $id]);
            $cancel->execute([$id]);
            $pdo->commit();
            $done[] = $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('sweep_absent_tutors failed on lesson ' . $id . ': ' . $e->getMessage());
        }
    }
    return $done;
}
