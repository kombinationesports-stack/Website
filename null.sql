-- ============================================================
-- ONE-TIME FIX: assign any teacher still missing a school year
-- Run this ONCE against the school_system database, after uploading
-- the new admin_backend.php / admin.php / admin_analytics.php.
--
-- Why you need this: the school_year_id column on `teachers` was
-- already added by an earlier version of the code, so the new
-- self-migrating backfill in admin_backend.php will NOT run again
-- (it only runs the very first time the column is created). Any
-- teacher row still sitting at school_year_id = NULL was, until now,
-- being shown in EVERY school year — that's the "Navarro shows up
-- in a year he wasn't added to" bug. This assigns those leftover
-- rows to whichever school year is currently active, so they stop
-- leaking into other years.
-- ============================================================

USE school_system;

UPDATE teachers t
JOIN school_years sy ON sy.is_active = 1
SET t.school_year_id = sy.id
WHERE t.school_year_id IS NULL;