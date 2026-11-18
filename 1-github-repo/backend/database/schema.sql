-- =============================================================================
-- ATTENDER schema.sql — kept in sync with attender_full.sql
-- =============================================================================
-- Prefer importing:  database/attender_full.sql  (one-file XAMPP / phpMyAdmin).
-- This file contains the same CREATE TABLE IF NOT EXISTS definitions (utf8mb4),
-- including face columns embedding_ready + face_enrolled.
-- Default is NON-destructive (no DROP of user data). Optional wipe stays commented.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS attender
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE attender;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Core identity
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS lecturers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  title         VARCHAR(80)  NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40)  NULL,
  department    VARCHAR(120) NULL,
  institution   VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lecturers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(120) NOT NULL,
  index_number  VARCHAR(40)  NOT NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(40)  NULL,
  programme     VARCHAR(160) NULL,
  level         VARCHAR(20)  NULL,
  password_hash VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_index (index_number),
  KEY idx_students_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lecturer_classes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(160) NOT NULL,
  programme     VARCHAR(160) NULL,
  department    VARCHAR(120) NULL,
  level         VARCHAR(20)  NULL,
  academic_year VARCHAR(20)  NULL,
  description   TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_classes_lecturer (lecturer_id),
  CONSTRAINT fk_classes_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  class_id      BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(40)  NOT NULL,
  title         VARCHAR(160) NOT NULL,
  semester      VARCHAR(40)  NULL,
  academic_year VARCHAR(20)  NULL,
  description   TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courses_lecturer_code_year (lecturer_id, code, academic_year),
  KEY idx_courses_class (class_id),
  CONSTRAINT fk_courses_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_courses_class FOREIGN KEY (class_id) REFERENCES lecturer_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity ↔ lecturer-class link (registration lifecycle) + face_enrolled
CREATE TABLE IF NOT EXISTS student_registrations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  class_id      BIGINT UNSIGNED NOT NULL,
  student_id    BIGINT UNSIGNED NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  face_enrolled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 only when face-service saved a real embedding',
  reject_reason VARCHAR(255) NULL,
  consented_at  DATETIME NULL,
  decided_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_class_student (class_id, student_id),
  KEY idx_reg_lecturer_status (lecturer_id, status),
  CONSTRAINT fk_reg_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_class FOREIGN KEY (class_id) REFERENCES lecturer_classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_enrollments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id      BIGINT UNSIGNED NOT NULL,
  course_id        BIGINT UNSIGNED NOT NULL,
  registration_id  BIGINT UNSIGNED NOT NULL,
  student_id       BIGINT UNSIGNED NOT NULL,
  enrolled_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enroll_course_reg (course_id, registration_id),
  UNIQUE KEY uq_enroll_course_student (course_id, student_id),
  KEY idx_enroll_lecturer (lecturer_id),
  CONSTRAINT fk_enroll_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_reg FOREIGN KEY (registration_id) REFERENCES student_registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_invitations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  class_id      BIGINT UNSIGNED NOT NULL,
  token         VARCHAR(64) NOT NULL,
  expires_at    DATETIME NOT NULL,
  max_uses      INT UNSIGNED NOT NULL DEFAULT 100,
  use_count     INT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  auto_approve  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_token (token),
  KEY idx_inv_lecturer (lecturer_id),
  CONSTRAINT fk_inv_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_class FOREIGN KEY (class_id) REFERENCES lecturer_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_locations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(160) NOT NULL,
  latitude      DECIMAL(10,7) NOT NULL,
  longitude     DECIMAL(10,7) NOT NULL,
  default_radius_m INT UNSIGNED NOT NULL DEFAULT 30,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_loc_lecturer (lecturer_id),
  CONSTRAINT fk_loc_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Attendance
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS attendance_sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NOT NULL,
  class_id      BIGINT UNSIGNED NOT NULL,
  method        ENUM('qr','code','both') NOT NULL DEFAULT 'qr',
  status        ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  opened_at     DATETIME NOT NULL,
  closed_at     DATETIME NULL,
  duration_min  INT UNSIGNED NULL,
  late_after_min INT UNSIGNED NOT NULL DEFAULT 10,
  radius_m      INT UNSIGNED NOT NULL DEFAULT 30,
  require_gps   TINYINT(1) NOT NULL DEFAULT 1,
  require_face  TINYINT(1) NOT NULL DEFAULT 1,
  qr_mode       ENUM('rotate','fixed','print') NOT NULL DEFAULT 'rotate',
  qr_rotate_sec INT UNSIGNED NOT NULL DEFAULT 30,
  location_name VARCHAR(160) NULL,
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sess_lecturer_status (lecturer_id, status),
  KEY idx_sess_course (course_id),
  CONSTRAINT fk_sess_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sess_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_sess_class FOREIGN KEY (class_id) REFERENCES lecturer_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  token_plain   VARCHAR(64) NULL COMMENT 'kept only while session open for QR display',
  expires_at    DATETIME NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_session (session_id, is_active),
  CONSTRAINT fk_token_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_codes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NOT NULL,
  code_hash     CHAR(64) NOT NULL,
  code_plain    VARCHAR(12) NULL COMMENT 'kept only while valid for display',
  expires_at    DATETIME NOT NULL,
  attempt_limit INT UNSIGNED NOT NULL DEFAULT 5,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_code_session (session_id, is_active),
  CONSTRAINT fk_code_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NOT NULL,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  student_id    BIGINT UNSIGNED NOT NULL,
  status        ENUM('present','late','absent','pending','review','failed') NOT NULL,
  method_used   ENUM('qr','code','manual') NULL,
  marked_at     DATETIME NULL,
  distance_m    DECIMAL(8,2) NULL,
  gps_accuracy_m DECIMAL(8,2) NULL,
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  face_similarity DECIMAL(5,4) NULL,
  fail_reason   VARCHAR(255) NULL,
  corrected_from VARCHAR(20) NULL,
  correction_reason VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_record_session_student (session_id, student_id),
  KEY idx_record_lecturer (lecturer_id),
  KEY idx_record_student (student_id),
  CONSTRAINT fk_record_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_record_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_record_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Face / biometrics (audit paths in PHP; embeddings live in face-service)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS facial_templates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    BIGINT UNSIGNED NOT NULL,
  lecturer_id   BIGINT UNSIGNED NULL COMMENT 'scope of enrolment when captured',
  provider      VARCHAR(40) NOT NULL DEFAULT 'interface',
  template_path VARCHAR(255) NOT NULL,
  embedding_meta JSON NULL,
  quality_score DECIMAL(5,4) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  embedding_ready TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 only after face-service persisted embedding',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_face_student (student_id, is_active),
  KEY idx_face_ready (student_id, embedding_ready, is_active),
  CONSTRAINT fk_face_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NULL,
  student_id    BIGINT UNSIGNED NULL,
  lecturer_id   BIGINT UNSIGNED NULL,
  input_type    ENUM('qr','code') NULL,
  stage         VARCHAR(40) NOT NULL,
  outcome       ENUM('pass','fail','pending') NOT NULL,
  reason        VARCHAR(255) NULL,
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  meta_json     JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_va_session (session_id),
  KEY idx_va_student (student_id),
  KEY idx_va_lecturer (lecturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NULL,
  actor_type    ENUM('lecturer','system','student') NOT NULL DEFAULT 'lecturer',
  actor_id      BIGINT UNSIGNED NULL,
  actor_name    VARCHAR(120) NULL,
  action        VARCHAR(80) NOT NULL,
  entity_type   VARCHAR(40) NULL,
  entity_id     BIGINT UNSIGNED NULL,
  detail        VARCHAR(500) NULL,
  reason        VARCHAR(255) NULL,
  previous_json JSON NULL,
  new_json      JSON NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_lecturer (lecturer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160) NOT NULL,
  body          VARCHAR(500) NULL,
  is_read       TINYINT(1) NOT NULL DEFAULT 0,
  link_path     VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_lecturer (lecturer_id, is_read),
  CONSTRAINT fk_notif_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecturer_id   BIGINT UNSIGNED NOT NULL,
  setting_key   VARCHAR(80) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_lecturer_key (lecturer_id, setting_key),
  CONSTRAINT fk_settings_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- OPTIONAL lab wipe ONLY — keep commented for production / real data.
-- Uncomment the block below ONLY when you intentionally want an empty schema.
-- =============================================================================
/*
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS verification_attempts;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_codes;
DROP TABLE IF EXISTS attendance_tokens;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS facial_templates;
DROP TABLE IF EXISTS course_enrollments;
DROP TABLE IF EXISTS student_registrations;
DROP TABLE IF EXISTS class_invitations;
DROP TABLE IF EXISTS classroom_locations;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS lecturer_classes;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS lecturers;
SET FOREIGN_KEY_CHECKS = 1;
-- Then re-run the CREATE TABLE statements above, or re-import this file.
*/

SELECT 'ATTENDER schema ready (attender_full.sql). Optional next: php database/seed.php' AS message;
