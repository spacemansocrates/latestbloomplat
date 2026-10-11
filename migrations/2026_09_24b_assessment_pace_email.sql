-- migrations/2026_09_24b_assessment_pace_email.sql
--
-- Follows 2026_09_24_assessments.sql.
--   pace          how fast the family wants to improve (steady | term | fast); with the
--                 overall score it decides the recommended plan. Replaces exam_soon,
--                 which is kept for old rows but no longer read.
--   report_error  why the last attempt to email the report failed, so an admin can see
--                 it and resend. Cleared when a send succeeds.
--
-- Additive and safe to re-run.

ALTER TABLE assessment_attempts
  ADD COLUMN IF NOT EXISTS pace VARCHAR(20) NULL AFTER exam_soon,
  ADD COLUMN IF NOT EXISTS report_error TEXT NULL AFTER report_sent_at;

UPDATE assessment_attempts SET pace = 'fast' WHERE pace IS NULL AND exam_soon = 1;
UPDATE assessment_attempts SET pace = 'steady' WHERE pace IS NULL;
