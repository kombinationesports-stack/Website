-- ============================================================
-- SCHOOL SYSTEM — FULL DATABASE (ALL TABLES)
-- Import this ONE file into a fresh MySQL server and you're done.
-- Combines: school_system.sql + migration.sql + evaluation.sql
--           + kahit_ano.sql + the columns admin_backend.php
--           normally adds automatically on first run.
-- ============================================================

CREATE DATABASE IF NOT EXISTS school_system;
USE school_system;

-- ------------------------------------------------------------
-- 1. TEACHERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teachers (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(255) NOT NULL,
    subject VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO teachers (name, subject) VALUES
('Mr. Santos',     'Mathematics'),
('Ms. Reyes',      'English'),
('Mr. Cruz',       'Science'),
('Ms. Garcia',     'Filipino'),
('Mr. Mendoza',    'History'),
('Ms. Torres',     'Arts'),
('Mr. Villanueva', 'Physical Education'),
('Ms. Flores',     'Computer Science');

-- ------------------------------------------------------------
-- 2. STUDENTS
--    (includes every column admin_backend.php expects to exist:
--     teacher_id, extra_info, and the detailed name/contact fields)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    student_id      VARCHAR(50),
    name            VARCHAR(100),
    email           VARCHAR(100),
    password        VARCHAR(255) NOT NULL,
    year            VARCHAR(10),
    section         VARCHAR(10),
    teacher_id      INT DEFAULT NULL,
    extra_info      TEXT NULL,
    last_name           VARCHAR(100) NULL,
    first_name          VARCHAR(100) NULL,
    middle_name         VARCHAR(100) NULL,
    gender              VARCHAR(10)  NULL,
    birthday            DATE         NULL,
    address             VARCHAR(255) NULL,
    contact_number      VARCHAR(30)  NULL,
    registration_no     VARCHAR(50)  NULL,
    course              VARCHAR(100) NULL,
    barangay            VARCHAR(100) NULL,
    city_municipality   VARCHAR(100) NULL,
    province            VARCHAR(100) NULL,

    CONSTRAINT fk_student_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. SECTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    year    VARCHAR(10),
    section VARCHAR(5),
    UNIQUE KEY unique_section (year, section)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. SECTION_TEACHERS
--    Which teachers are assigned to each section
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS section_teachers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    year       VARCHAR(10) NOT NULL,
    section    VARCHAR(10) NOT NULL,
    teacher_id INT NOT NULL,
    UNIQUE KEY uq_section_teacher (year, section, teacher_id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. STUDENT_TEACHERS
--    Every teacher actually assigned to a given student
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_teachers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    UNIQUE KEY uq_student_teacher (student_id, teacher_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. SCHOOL_YEARS
--    e.g. "2025-2026". Only ONE row can be is_active=1 at a time —
--    that's the year students are currently allowed to submit
--    evaluations for. Older years stay in the table so their
--    evaluation data/analytics remain viewable forever.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(20) NOT NULL UNIQUE,
    is_active  TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO school_years (label, is_active) VALUES ('2025-2026', 0);
INSERT INTO school_years (label, is_active) VALUES ('2026-2027', 1);

-- ------------------------------------------------------------
-- 7. EVALUATIONS
--    One evaluation per student, per teacher, PER SCHOOL YEAR —
--    so a student can evaluate the same teacher again once a
--    new school year opens, without losing last year's rating.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    teacher_id     INT NOT NULL,
    school_year_id INT NULL,
    rating         TINYINT NOT NULL,         -- 1 to 5 stars
    comments       TEXT,                     -- optional feedback
    submitted_at   DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_eval (student_id, teacher_id, school_year_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
) ENGINE=InnoDB;