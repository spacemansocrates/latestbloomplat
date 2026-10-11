-- tests/classroom/schema_additions.sql
--
-- Tables the classroom tests need on top of tests/tutor/schema.sql.
--
-- lesson_whiteboards is reconstructed here from how api/whiteboard.php actually used
-- it (keyed by lesson_id, scene/files as JSON text, updated_by, updated_at). The real
-- table's DDL is not in the repository -- it exists only on the production database --
-- so this is a faithful-to-usage stand-in, NOT a verified copy of production's
-- definition. Column types, engine and indexes there have not been confirmed from
-- here, and the migration must be re-checked against a genuine dump before release.

CREATE TABLE IF NOT EXISTS lesson_whiteboards (
  lesson_id  INT PRIMARY KEY,
  scene      LONGTEXT NULL,
  files      LONGTEXT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
