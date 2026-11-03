-- ============================================================
-- Add the evaluation_questions table (Questioner feature)
-- Run this once against the school_system database.
-- Purely additive — does not touch any existing table.
-- (Note: admin_questions.php also creates/seeds this table
-- automatically on first load, so running this file by hand
-- is optional — it's here in case you'd rather apply it via
-- phpMyAdmin/mysql directly.)
-- ============================================================

USE school_system;

CREATE TABLE IF NOT EXISTS evaluation_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed with the same 10 questions already used in Student_dashboard.php,
-- so existing evaluations/behavior are unaffected until an admin
-- adds or edits a question. Only runs if the table is empty.
INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher explains lessons clearly and in an understandable manner.' AS q, 1 AS o) AS t
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions);

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher comes to class on time and prepared.', 2) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 1;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher treats all students fairly and with respect.', 3) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 2;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher encourages student participation during class.', 4) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 3;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher gives helpful and timely feedback on assignments.', 5) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 4;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher uses teaching methods that help students learn effectively.', 6) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 5;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher is approachable and willing to help students with concerns.', 7) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 6;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher maintains an orderly and positive classroom environment.', 8) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 7;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT 'The teacher covers the required subject matter thoroughly.', 9) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 8;

INSERT INTO evaluation_questions (question_text, sort_order)
SELECT * FROM (SELECT "Overall, I am satisfied with this teacher's performance.", 10) AS t
WHERE (SELECT COUNT(*) FROM evaluation_questions) = 9;