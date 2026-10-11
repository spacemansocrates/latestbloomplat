-- migrations/2026_09_24_assessments.sql
--
-- Diagnostic assessments taken in the free demo session. The questions and the
-- answer keys are NOT in the database: they live in assessments/bank/*.json and
-- are read from disk (see docs/assessments/BLUEPRINT.md and utils/assessment.php).
-- An attempt pins the assessment id AND version it was taken on, so editing a
-- bank file never changes a report that already exists.
--
-- Additive only: one new table, nothing existing is altered. Safe to re-run.

CREATE TABLE IF NOT EXISTS assessment_attempts (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  student_id         INT NOT NULL,
  tutor_id           INT NULL,
  lesson_id          INT NULL,
  assessment_id      VARCHAR(40) NOT NULL,
  assessment_version INT NOT NULL,
  -- assigned -> in_progress (student opened it) -> submitted -> approved (tutor)
  status             ENUM('assigned','in_progress','submitted','approved') NOT NULL DEFAULT 'assigned',

  -- Who the report goes to. The student account may use its own email, so the
  -- parent's is kept here rather than inferred from users.email.
  parent_name        VARCHAR(120) NULL,
  parent_email       VARCHAR(190) NULL,
  exam_board         VARCHAR(20) NULL,
  exam_soon          TINYINT(1) NOT NULL DEFAULT 0,  -- exam under 3 months away: plan moves up a tier

  answers            LONGTEXT NULL,  -- JSON {question_id: answer}
  written_marks      LONGTEXT NULL,  -- JSON {question_id: marks}, set by the tutor
  results            LONGTEXT NULL,  -- JSON snapshot of the scored results, frozen at approval

  recommended_plan   VARCHAR(20) NULL,
  final_plan         VARCHAR(20) NULL,
  tutor_note         TEXT NULL,

  assigned_by        INT NULL,
  started_at         DATETIME NULL,
  submitted_at       DATETIME NULL,
  approved_by        INT NULL,
  approved_at        DATETIME NULL,
  report_sent_at     DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_student_status (student_id, status),
  KEY idx_tutor_status (tutor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
