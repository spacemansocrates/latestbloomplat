-- tutor_availability.day_of_week held two different encodings at once.
--
-- tutor/schedule.php wrote ISO days  : 1 = Monday .. 7 = Sunday
-- tutor/catchup.php  wrote 0-based   : 0 = Monday .. 6 = Sunday
--
-- Both pages write the same column, so the table ended up with EIGHT distinct day
-- numbers for a seven day week. Whichever page read it, some days came out shifted
-- and one day never matched at all, which means the catch-up proposal generator was
-- offering students the wrong days.
--
-- The code is now ISO 1..7 everywhere (fixed 2026-09-05). This migration moves the
-- rows that are still 0-based. Run it ONCE, after deploying that code.
--
--   SELECT day_of_week, COUNT(*) FROM tutor_availability GROUP BY day_of_week;
--
-- Only rows belonging to a tutor who has a day_of_week = 0 can be identified with
-- certainty as 0-based, because 0 is a value schedule.php can never write. A tutor
-- with a 7 is certainly ISO. A tutor whose days all fall in 1..6 is ambiguous and is
-- deliberately left alone here: shifting them on a guess would move a real teaching
-- window onto the wrong day.

START TRANSACTION;

-- What is about to change.
SELECT ta.tutor_id,
       GROUP_CONCAT(ta.day_of_week ORDER BY ta.day_of_week) AS days_before
FROM tutor_availability ta
WHERE ta.tutor_id IN (SELECT tutor_id FROM (
        SELECT DISTINCT tutor_id FROM tutor_availability WHERE day_of_week = 0
      ) AS zero_days)
GROUP BY ta.tutor_id;

-- Shift every row belonging to those tutors up by one, highest first so the
-- UNIQUE KEY (tutor_id, day_of_week, start_time) never collides mid-update.
UPDATE tutor_availability
SET day_of_week = day_of_week + 1
WHERE tutor_id IN (SELECT tutor_id FROM (
        SELECT DISTINCT tutor_id FROM tutor_availability WHERE day_of_week = 0
      ) AS zero_days)
ORDER BY day_of_week DESC;

-- Nothing should be outside 1..7 now.
SELECT day_of_week, COUNT(*) AS rows_ FROM tutor_availability GROUP BY day_of_week ORDER BY day_of_week;

COMMIT;

-- Tutors whose encoding cannot be determined from the data are listed by this
-- query. Ask them to open My Schedule -> Availability and confirm the grid matches
-- the days they actually teach; it is a two second check and a re-save fixes it.
--
-- SELECT tutor_id, GROUP_CONCAT(day_of_week ORDER BY day_of_week) AS days
-- FROM tutor_availability
-- GROUP BY tutor_id
-- HAVING MAX(day_of_week) < 7;
