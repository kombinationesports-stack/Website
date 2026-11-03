-- ============================================================
-- Add + activate school year 2026-2027
-- Run this once against the school_system database
-- (e.g. via phpMyAdmin > SQL tab, or `mysql -u root school_system < this_file.sql`)
-- ============================================================

USE school_system;

-- 1. Add the new school year if it doesn't already exist
INSERT INTO school_years (label, is_active)
SELECT '2026-2027', 0
WHERE NOT EXISTS (
    SELECT 1 FROM school_years WHERE label = '2026-2027'
);

-- 2. Only one school year can be active at a time — stop all others
UPDATE school_years SET is_active = 0;

-- 3. Activate 2026-2027
UPDATE school_years SET is_active = 1 WHERE label = '2026-2027';
