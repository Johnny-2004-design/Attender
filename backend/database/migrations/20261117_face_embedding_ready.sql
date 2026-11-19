-- ATTENDER face audit migration — safe to run on existing DBs
-- Adds embedding_ready so PHP can refuse attendance until Python saved a real embedding.

USE attender;

ALTER TABLE facial_templates
  ADD COLUMN embedding_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE student_registrations
  ADD COLUMN face_enrolled TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- Existing null-provider rows stay embedding_ready=0 (correct).
-- Re-enrol via face-service to set embedding_ready=1.
