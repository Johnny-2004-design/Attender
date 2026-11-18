-- !!! DEV / DEMO ONLY — never import into a real production database. !!!
-- Optional demo lecturers for shared-host import (no SSH / no seed.php).
-- Import AFTER remote_import.sql while the panel database is selected.
-- Rotate or delete these accounts before any real institutional use.

SET NAMES utf8mb4;

INSERT INTO lecturers (name, title, email, phone, department, institution, password_hash, is_active, created_at)
VALUES
  ('Kwame Mensah', 'Dr', 'k.mensah@ucc.edu.gh', NULL, 'Computer Science', 'University of Cape Coast', '$2y$10$s.UpbOqU/j9neQpaWfiAi.oDkFjLZbPrt89hPitvnlRWEg0BRITV6', 1, NOW()),
  ('Ama Owusu', 'Ms', 'a.owusu@ucc.edu.gh', NULL, 'IT', 'University of Cape Coast', '$2y$10$kX0EWQpmGSHmH43UzHsCq.FOT1seYR9YH.qWdLpYhcvFbBhj7U86O', 1, NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Demo tip: after login, open Settings and set requireFace = false for UCC presentation
-- (face-service cannot run on InfinityFree). Keep requireGps as needed for HTTPS phone demos.

SELECT 'Demo lecturers created. Passwords are defined in database/seed.php (DEV ONLY - change or delete before real use).' AS note;