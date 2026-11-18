-- ATTENDER seed data
-- Prefer the PHP seeder (hashes passwords correctly and builds relations):
--
--   php database/seed.php
--
-- Seed logins created by seed.php:
--   (demo accounts - passwords are in database/seed.php; DEV ONLY)
--
-- Invite tokens:
--   inv_L300CS_9fK2xQ  (Level 300 CS)
--   inv_L200IT_r7Wm3B  (Level 200 IT)
--
-- This file intentionally does not embed bcrypt hashes (they should be
-- generated on the target machine). Run schema.sql first, then seed.php.

SELECT 'Run: php database/seed.php' AS instruction;
