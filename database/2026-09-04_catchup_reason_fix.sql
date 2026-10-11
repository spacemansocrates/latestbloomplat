-- Catch-up requests were blaming the tutor for every student absence.
--
-- trg_after_lesson_catchup mapped the two Cancelled statuses to themselves and
-- let everything else fall through to 'TutorNoShow'. The only status that could
-- reach that ELSE is 'NoShow', which in this system means the STUDENT did not
-- attend: finish_lesson.php picks it precisely when student_join_time is null.
-- The reason enum already carries 'StudentNoShow'; it had never been used once.
--
-- Part 1 corrects the trigger. Part 2 relabels the rows it produced.
-- Neither part changes whether a catch-up is offered, only who it is attributed
-- to. Whether a student who missed their own lesson should be queued for a free
-- make-up at all is a business decision, deliberately left alone here.

-- ---------------------------------------------------------------- part 1
DELIMITER $$

CREATE OR REPLACE DEFINER = `bloomtutsys`@`localhost` TRIGGER `trg_after_lesson_catchup`
AFTER UPDATE ON `lessons`
FOR EACH ROW
BEGIN
  IF NEW.suppress_catchup = 0
     AND NEW.student_id IS NOT NULL
     AND (NEW.status IN ('CancelledByTutor','CancelledByStudent','NoShow')
          AND OLD.status <> NEW.status) THEN

    INSERT INTO catchup_requests (original_lesson_id, student_id, tutor_id, reason, status)
    SELECT NEW.id, NEW.student_id, NEW.tutor_id,
           (CASE NEW.status
              WHEN 'CancelledByTutor'   THEN 'CancelledByTutor'
              WHEN 'CancelledByStudent' THEN 'CancelledByStudent'
              -- The IF above admits only NoShow here, and a NoShow is the
              -- student failing to open the room, not the tutor.
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
-- Only rows whose lesson really was a student absence. A TutorNoShow raised
-- against a CancelledByTutor lesson is already right, and rows that have been
-- closed out are left as the historical record.
UPDATE catchup_requests cr
  JOIN lessons l ON l.id = cr.original_lesson_id
   SET cr.reason = 'StudentNoShow'
 WHERE cr.reason = 'TutorNoShow'
   AND l.status = 'NoShow'
   AND l.student_join_time IS NULL;
