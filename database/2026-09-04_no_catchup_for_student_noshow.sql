-- A student who misses their own lesson is no longer offered a make-up.
--
-- Follows 2026-09-04_catchup_reason_fix.sql, which corrected the attribution.
-- This one changes the policy: 'NoShow' comes out of the trigger's status list,
-- so only a cancellation by either side raises a catch-up request. The two
-- Cancelled statuses are untouched.
--
-- Note there is still no automatic detection of a TUTOR failing to appear.
-- Those lessons stay InProgress and surface in the dashboard's "needs finishing"
-- panel; a tutor no-show becomes a catch-up only when someone sets the lesson to
-- CancelledByTutor by hand, as lesson 294 was.

-- ---------------------------------------------------------------- part 1
DELIMITER $$

CREATE OR REPLACE DEFINER = `bloomtutsys`@`localhost` TRIGGER `trg_after_lesson_catchup`
AFTER UPDATE ON `lessons`
FOR EACH ROW
BEGIN
  IF NEW.suppress_catchup = 0
     AND NEW.student_id IS NOT NULL
     AND (NEW.status IN ('CancelledByTutor','CancelledByStudent')
          AND OLD.status <> NEW.status) THEN

    INSERT INTO catchup_requests (original_lesson_id, student_id, tutor_id, reason, status)
    SELECT NEW.id, NEW.student_id, NEW.tutor_id,
           (CASE NEW.status
              WHEN 'CancelledByTutor'   THEN 'CancelledByTutor'
              WHEN 'CancelledByStudent' THEN 'CancelledByStudent'
              -- Unreachable: the IF above admits only those two. Present so the
              -- CASE always yields a value for a NOT NULL column.
              ELSE 'StudentNoShow'
            END),
           'Open'
    FROM DUAL
    WHERE NOT EXISTS (
      SELECT 1 FROM catchup_requests cr WHERE cr.original_lesson_id = NEW.id
    );

  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------- part 2
-- Withdraw the outstanding offers. Only the Open ones: request 34 is already
-- Proposed with three times put to the student, so retracting it would pull back
-- something they may already have been shown. Every read in the app filters on
-- status IN ('Open','Proposed'), so Expired drops these from the tutor's
-- catch-up page, the student's availability page and both sidebar badges.
UPDATE catchup_requests
   SET status = 'Expired'
 WHERE reason = 'StudentNoShow'
   AND status = 'Open';
