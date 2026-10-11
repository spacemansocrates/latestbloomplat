-- Last presented book belongs to the tutor-student workspace, including demo isolation.
-- Additive only. No foreign keys to users or lessons: their engines and column
-- types have not all been verified on production (see classroom_workspaces.sql).
CREATE TABLE IF NOT EXISTS classroom_book_state (
  workspace_id int(11) NOT NULL,
  material_id int(11) NOT NULL,
  page int(11) NOT NULL DEFAULT 1,
  zoom decimal(5,2) NOT NULL DEFAULT 1.00,
  updated_by int(11) NOT NULL,
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
