-- migrations/2026_09_13_classroom_workspaces.sql
--
-- Durable tutor-student whiteboard workspaces.
--
-- The problem this solves: lesson_whiteboards is keyed by lesson_id, so a board
-- lives and dies with one lesson row. When the same tutor and student meet again
-- under a new lesson id, they get a blank board and last week's work is stranded
-- on a lesson nobody opens again.
--
-- Continuity belongs to the RELATIONSHIP, not the lesson. A workspace is keyed by
-- (tutor, student); individual lessons keep their own video room, attendance and
-- recording exactly as before. Nothing here touches lessons or lesson_whiteboards.
--
-- workspace_key is the identity, and it is deterministic so that resolving it is a
-- pure function of the lesson row -- no "find or guess" logic, and the unique index
-- makes concurrent first-joins safe:
--     pair:<tutor_id>:<student_id>     a real tutor-student relationship
--     demo:<lesson_id>                 a demo lesson with no student
--
-- Demos are deliberately keyed by lesson. Every demo has student_id NULL, so keying
-- them by pair would pour every demo any tutor ever ran into one shared board.
--
-- A NULL student_id cannot carry that rule in a UNIQUE index by itself: MySQL treats
-- NULLs as distinct, so UNIQUE(tutor_id, student_id) would never collide for demos
-- and would happily create a second workspace for the same pair on a race. A single
-- generated string column sidesteps NULL comparison semantics entirely.
--
-- Additive only. No existing table or column is modified or dropped.

CREATE TABLE IF NOT EXISTS `classroom_workspaces` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `workspace_key` varchar(96)  NOT NULL,            -- 'pair:<t>:<s>' | 'demo:<lesson>'
  `tutor_id`      int(11)      NOT NULL,
  `student_id`    int(11)          NULL,            -- NULL for demo workspaces
  `demo_lesson_id` int(11)         NULL,            -- set only when student_id IS NULL
  `active_page_id` int(11)         NULL,            -- which page opens by default
  `created_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workspace_key` (`workspace_key`),
  KEY `idx_workspace_tutor` (`tutor_id`),
  KEY `idx_workspace_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One page = one Excalidraw scene. A workspace has many, so a tutor can start fresh
-- without losing last week's board: new page, previous pages still there.
--
-- revision is the concurrency control. Every save states the revision it was based
-- on and the server only accepts it when that still matches, then increments. A
-- stale tab, a delayed request, or a second client cannot silently flatten newer
-- work -- it gets a conflict and the current scene back instead.
--
-- source_lesson_id records which lesson_whiteboards row a page was imported from.
-- UNIQUE(workspace_id, source_lesson_id) is what makes the migration idempotent:
-- re-running it cannot import the same historic board twice. MySQL allows many NULLs
-- in a unique index, so pages created normally in class are unaffected by it.
CREATE TABLE IF NOT EXISTS `classroom_workspace_pages` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `workspace_id`   int(11)      NOT NULL,
  `title`          varchar(120) NOT NULL DEFAULT 'Page',
  `position`       int(11)      NOT NULL DEFAULT 0,
  `scene`          longtext         NULL,
  `files`          longtext         NULL,
  `revision`       int(11)      NOT NULL DEFAULT 0,
  `is_archived`    tinyint(1)   NOT NULL DEFAULT 0,
  `source_lesson_id` int(11)        NULL,           -- provenance for migrated boards
  `updated_by`     int(11)          NULL,
  `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_source_lesson` (`workspace_id`, `source_lesson_id`),
  KEY `idx_page_workspace_pos` (`workspace_id`, `position`),
  CONSTRAINT `fk_page_workspace` FOREIGN KEY (`workspace_id`)
    REFERENCES `classroom_workspaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Deliberately NO foreign keys from these tables to `users` or `lessons`.
--
-- notification_dispatch_log could add one to users because users is InnoDB, but the
-- engine of every table on the production box has not been verified from here, and a
-- FOREIGN KEY against a MyISAM table is accepted silently and enforces nothing --
-- while against a table with a different column type it aborts the whole migration.
-- tutor_id / student_id / source_lesson_id are plain indexed columns; the application
-- resolves and authorizes them on every request anyway (utils/classroom_workspace.php),
-- so the constraint would be belt-and-braces, not the thing keeping access correct.
--
-- Consequence to accept knowingly: deleting a user does not cascade these rows.
-- They are orphaned board history, unreachable through the app because resolution
-- always starts from a live lesson the caller is a party to.
