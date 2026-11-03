<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';
require_once __DIR__ . '/admin_activity_helper.php';
require_once __DIR__ . '/recycle_bin_helper.php';

$conn = new mysqli("localhost", "root", "", "school_system");

require 'SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';

$message = "";

/* ================= HELPER: get a teacher's last name for display =================
   Teachers are still added/edited with their FULL name (typed once, stored
   as-is, and that's what the "Teacher Name" text field always shows when
   editing) — this only affects how the name is DISPLAYED elsewhere (the
   teacher list badge, the Add Student teacher checklist, etc.): just the
   last word of the full name. e.g. "Juan Dela Cruz" displays as "Cruz".
   Note: for a surname that's normally two words (e.g. "Dela Cruz", "De
   Leon"), only the very last word shows — let me know if you'd rather have
   it recognize multi-word surnames instead. */
function getTeacherLastName($fullName) {
    $fullName = trim($fullName ?? '');
    if ($fullName === '') return '';
    $parts = preg_split('/\s+/', $fullName);
    return end($parts);
}

/* ================= CREATE section_teachers IF NOT EXISTS ================= */
$conn->query("CREATE TABLE IF NOT EXISTS section_teachers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    year       VARCHAR(10) NOT NULL,
    section    VARCHAR(10) NOT NULL,
    teacher_id INT NOT NULL,
    UNIQUE KEY uq_section_teacher (year, section, teacher_id)
) ENGINE=InnoDB");

/* ================= ADD school_year_id COLUMN TO section_teachers IF NOT EXISTS =================
   Which teachers are assigned to a section can now differ per school year
   (e.g. Cruz for 2026-2027, Mendoza for 2025-2026), so students only ever
   see/evaluate the teacher(s) that were actually assigned for the school
   year their account belongs to. Backfills existing rows to the active
   school year so nothing already saved is lost. */
$sectionTeacherSyCheck = $conn->query("SHOW COLUMNS FROM section_teachers LIKE 'school_year_id'");
if ($sectionTeacherSyCheck && $sectionTeacherSyCheck->num_rows == 0) {
    $conn->query("ALTER TABLE section_teachers ADD COLUMN school_year_id INT NULL");

    $activeStRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    if ($activeStRow) {
        $activeStSyId = (int)$activeStRow['id'];
        $conn->query("UPDATE section_teachers SET school_year_id = $activeStSyId WHERE school_year_id IS NULL");
    }

    // The old UNIQUE KEY (year, section, teacher_id) would block the same
    // teacher being assigned to the same section again in a different
    // school year, so widen it to include school_year_id — same approach
    // already used for evaluations' unique key above.
    try {
        $conn->query("ALTER TABLE section_teachers ADD UNIQUE KEY uq_section_teacher_sy (year, section, teacher_id, school_year_id)");
    } catch (mysqli_sql_exception $e) {
        // Ignore — duplicate-name race from a concurrent request.
    }
    try {
        $conn->query("ALTER TABLE section_teachers DROP INDEX uq_section_teacher");
    } catch (mysqli_sql_exception $e) {
        // If MySQL won't drop it, leave it — uq_section_teacher_sy above is
        // what actually enforces correctness now either way.
    }
}

/* ================= ADD school_year_id COLUMN TO sections IF NOT EXISTS =================
   A "section" (e.g. Year 1 - A) should belong to a specific school year, the
   same way students/section_teachers already do — so switching to a new
   school year starts with NO sections added yet, and the admin has to add
   the section again (which is also how a teacher gets assigned to it for
   that new school year, via the existing "Add Section" form). Backfills
   existing rows to the active school year so nothing already saved is
   lost, then widens the unique key the same way section_teachers'/
   evaluations' keys were widened above. */
$sectionSyCheck = $conn->query("SHOW COLUMNS FROM sections LIKE 'school_year_id'");
if ($sectionSyCheck && $sectionSyCheck->num_rows == 0) {
    $conn->query("ALTER TABLE sections ADD COLUMN school_year_id INT NULL");

    $activeSecRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    if ($activeSecRow) {
        $activeSecSyId = (int)$activeSecRow['id'];
        $conn->query("UPDATE sections SET school_year_id = $activeSecSyId WHERE school_year_id IS NULL");
    }

    try {
        $conn->query("ALTER TABLE sections ADD UNIQUE KEY uq_section_sy (year, section, school_year_id)");
    } catch (mysqli_sql_exception $e) {
        // Ignore — duplicate-name race from a concurrent request.
    }
    try {
        $conn->query("ALTER TABLE sections DROP INDEX unique_section");
    } catch (mysqli_sql_exception $e) {
        // If MySQL won't drop it, leave it — uq_section_sy above is what
        // actually enforces correctness now either way.
    }
}

/* ================= ADD course COLUMN TO sections IF NOT EXISTS =================
   Lets a section be labeled with its program/course (e.g. "BSIT A" instead
   of just "Section A"). Purely a display label — it does not affect how a
   section is identified/looked-up anywhere (still year + section letter +
   school_year_id), so nothing else has to change. Existing sections get
   course = NULL and just fall back to showing "Section A" as before. */
$sectionCourseCheck = $conn->query("SHOW COLUMNS FROM sections LIKE 'course'");
if ($sectionCourseCheck && $sectionCourseCheck->num_rows == 0) {
    $conn->query("ALTER TABLE sections ADD COLUMN course VARCHAR(50) NULL");
}

/* ================= ADD school_year_id COLUMN TO teachers IF NOT EXISTS =================
   A teacher created while a given school year is active only shows up /
   is reusable while THAT exact school year is active — not from any other
   school year (e.g. a teacher added while 2025-2026 was active does not
   appear in 2026-2027, and vice versa; nothing falls back to "always
   visible"). Backfills existing rows to the active school year at the time
   of this migration — same pattern as sections/students/evaluations above
   — so nothing already saved just disappears; it simply belongs to
   whichever year was active when this ran, and can be added again under
   other years as needed. */
$teacherSyCheck = $conn->query("SHOW COLUMNS FROM teachers LIKE 'school_year_id'");
if ($teacherSyCheck && $teacherSyCheck->num_rows == 0) {
    $conn->query("ALTER TABLE teachers ADD COLUMN school_year_id INT NULL");

    $activeTeacherMigRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    if ($activeTeacherMigRow) {
        $activeTeacherMigSyId = (int)$activeTeacherMigRow['id'];
        $conn->query("UPDATE teachers SET school_year_id = $activeTeacherMigSyId WHERE school_year_id IS NULL");
    }
}

/* ================= CREATE student_teachers IF NOT EXISTS ================= */
$conn->query("CREATE TABLE IF NOT EXISTS student_teachers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    UNIQUE KEY uq_student_teacher (student_id, teacher_id)
) ENGINE=InnoDB");

/* ================= CREATE school_years IF NOT EXISTS =================
   Only ONE row can be is_active = 1 at a time — that is the school year
   students are currently allowed to submit evaluations for. Nothing else
   in the system reads/writes this table unless a school year is actually
   involved, so this cannot affect any existing feature. */
$conn->query("CREATE TABLE IF NOT EXISTS school_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(20) NOT NULL UNIQUE,
    is_active  TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

/* ================= SEED A DEFAULT SCHOOL YEAR IF NONE EXISTS =================
   Runs once. Picks a sensible default label based on today's date so the
   system always has an active school year to attach evaluations to. */
$syCountRow = $conn->query("SELECT COUNT(*) AS c FROM school_years")->fetch_assoc();
if ((int)($syCountRow['c'] ?? 0) === 0) {
    $curMonth = (int)date('n');
    $curYear  = (int)date('Y');
    // School year typically starts mid-year (Jun) in PH academic calendars.
    $defaultLabel = ($curMonth >= 6)
        ? $curYear . "-" . ($curYear + 1)
        : ($curYear - 1) . "-" . $curYear;

    $seed = $conn->prepare("INSERT INTO school_years (label, is_active) VALUES (?, 1)");
    $seed->bind_param("s", $defaultLabel);
    $seed->execute();
    $seed->close();
}

/* ================= ADD school_year_id COLUMN TO evaluations IF NOT EXISTS ================= */
$syColCheck = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'school_year_id'");
if ($syColCheck && $syColCheck->num_rows == 0) {
    $conn->query("ALTER TABLE evaluations ADD COLUMN school_year_id INT NULL");

    // Backfill any evaluations submitted before school years existed into
    // whichever school year is active right now, so old ratings/analytics
    // are never lost or orphaned.
    $activeRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    if ($activeRow) {
        $activeId = (int)$activeRow['id'];
        $conn->query("UPDATE evaluations SET school_year_id = $activeId WHERE school_year_id IS NULL");
    }
}

/* ================= WIDEN evaluations UNIQUE KEY TO INCLUDE school_year_id =================
   The original key was (student_id, teacher_id), which permanently locked a
   student out of ever evaluating the same teacher again. Replacing it with
   (student_id, teacher_id, school_year_id) lets a student evaluate the same
   teacher again once a new school year opens, while still preventing more
   than one evaluation per teacher within the same school year.

   IMPORTANT ORDER: the old 2-column key can't just be dropped — MySQL keeps
   it around internally to support the student_id/teacher_id foreign keys.
   So we add the new 3-column key FIRST (its leftmost columns still cover
   both FKs), and only then drop the old one, safely wrapped in case MySQL
   still refuses for any reason. */
$existingIdxCols = [];
$idxRows = $conn->query("
    SELECT INDEX_NAME, COUNT(*) AS col_count
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'evaluations'
      AND NON_UNIQUE = 0
    GROUP BY INDEX_NAME
");
if ($idxRows) {
    while ($idxRow = $idxRows->fetch_assoc()) {
        $existingIdxCols[$idxRow['INDEX_NAME']] = (int)$idxRow['col_count'];
    }
}

// Step 1: add the new 3-column unique key if it doesn't already exist.
if (!isset($existingIdxCols['unique_eval_sy']) && !isset($existingIdxCols['unique_eval_subject'])) {
    try {
        $conn->query("ALTER TABLE evaluations ADD UNIQUE KEY unique_eval_sy (student_id, teacher_id, school_year_id)");
    } catch (mysqli_sql_exception $e) {
        // Ignore — most likely a duplicate-name race from a concurrent request.
    }
}

// Step 2: now safe to drop the old 2-column key, since unique_eval_sy's
// leftmost columns already cover what the foreign keys need.
if (isset($existingIdxCols['unique_eval']) && $existingIdxCols['unique_eval'] < 3) {
    try {
        $conn->query("ALTER TABLE evaluations DROP INDEX unique_eval");
    } catch (mysqli_sql_exception $e) {
        // If MySQL still won't drop it for some reason, leave it in place —
        // unique_eval_sy above is what actually enforces per-school-year
        // uniqueness, so evaluations still work correctly either way.
    }
}

/* ================= ADD extra_info COLUMN TO students IF NOT EXISTS ================= */
$colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'extra_info'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE students ADD COLUMN extra_info TEXT NULL");
}

/* ================= ENSURE password COLUMN CAN STORE A FULL HASH =================
   password_hash() produces a 60-character bcrypt hash (and PASSWORD_DEFAULT may
   grow longer in future PHP versions). If this column was ever created narrower
   than that (e.g. from an old plaintext-password schema), MySQL silently
   truncates every hash on insert, which makes password_verify() fail forever —
   the account gets created and the email goes out, but the student can never
   actually log in. This widens the column if needed, without touching any data. */
$pwCol = $conn->query("SHOW COLUMNS FROM students LIKE 'password'");
if ($pwCol && $pwCol->num_rows > 0) {
    $pwColInfo = $pwCol->fetch_assoc();
    if (stripos($pwColInfo['Type'], 'varchar(255)') === false) {
        $conn->query("ALTER TABLE students MODIFY COLUMN password VARCHAR(255) NOT NULL");
    }
}

/* ================= ADD DETAILED STUDENT COLUMNS IF NOT EXISTS ================= */
$detailColumns = [
    "last_name"          => "VARCHAR(100) NULL",
    "first_name"         => "VARCHAR(100) NULL",
    "middle_name"        => "VARCHAR(100) NULL",
    "gender"             => "VARCHAR(10)  NULL",
    "birthday"           => "DATE         NULL",
    "address"            => "VARCHAR(255) NULL",
    "contact_number"     => "VARCHAR(30)  NULL",
    "registration_no"    => "VARCHAR(50)  NULL",
    "course"             => "VARCHAR(100) NULL",
    "barangay"           => "VARCHAR(100) NULL",
    "city_municipality"  => "VARCHAR(100) NULL",
    "province"           => "VARCHAR(100) NULL",
];
foreach ($detailColumns as $colName => $colDef) {
    $dc = $conn->query("SHOW COLUMNS FROM students LIKE '$colName'");
    if ($dc && $dc->num_rows == 0) {
        $conn->query("ALTER TABLE students ADD COLUMN $colName $colDef");
    }
}

/* ================= ADD school_year_id COLUMN TO students IF NOT EXISTS =================
   Same idea as evaluations.school_year_id above: this tags each student row
   with the school year it currently belongs to, so importing/adding students
   for a new school year doesn't get mixed up with older years. Nothing
   existing reads/writes this unless a school year is actually involved, so
   this cannot affect any existing feature. */
$studentSyColCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'school_year_id'");
if ($studentSyColCheck && $studentSyColCheck->num_rows == 0) {
    $conn->query("ALTER TABLE students ADD COLUMN school_year_id INT NULL");

    // Backfill any students added before this existed into whichever school
    // year is active right now, so nobody's record is left blank/orphaned.
    $activeStudentRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    if ($activeStudentRow) {
        $activeStudentSyId = (int)$activeStudentRow['id'];
        $conn->query("UPDATE students SET school_year_id = $activeStudentSyId WHERE school_year_id IS NULL");
    }
}


/* ================= SUBJECT CATALOG (per year level) =================
   Same list that used to live in admin.php. Each subject code belongs to
   one year level, so adding "FIL-1" to a teacher automatically knows the
   students imported under it are Year 1. */
$subjectsByYear = [
    1 => ["FIL-1", "GE-04", "GE-5", "GE-7", "PATHFIT 1", "NSTP 11"],
    2 => ["IT-MS01", "FIL-3", "IT-PF01", "IT-WS02", "CC-103", "GE-06", "GE-08", "PATHFIT 3"],
    3 => ["IT-SA01", "IT-IM01", "ELP", "IT-IPT02", "IT-IPT01", "IT-IAS01"],
    4 => ["IT-SP01", "IT-SIA01", "IT-WS01", "IT-CAP02", "IT-WS07", "IT-WA06"],
];

/* ================= SEMESTER CATALOG =================
   1st semester = the original $subjectsByYear above (unchanged).
   2nd semester = its own subject list per year level. */
$subjectsBySemester = [
    1 => $subjectsByYear,
    2 => [
        1 => ["FIL-2", "NSTP 12", "PATHFIT 2", "IT-WS01", "GE-1", "GE-2", "CC-102", "IT-NET02"],
        2 => ["PATHFIT 4", "CC-104", "CC-105", "IT-PF02", "IT-HC101", "IT-MS02", "GE-3", "GE-9"],
        3 => ["IT-IAS02", "IT-CAP01", "IT-WS03", "IT-WS04", "IT-WS05"],
        4 => [],
    ],
];

/* Selected semester (1 or 2) — chosen from the switch beside "+ Add Teacher",
   remembered in the session, defaults to 1st semester. */
if (isset($_REQUEST['semester']) && in_array((int)$_REQUEST['semester'], [1, 2], true)) {
    $_SESSION['semester'] = (int)$_REQUEST['semester'];
}
$activeSemester = (int)($_SESSION['semester'] ?? 1);
if (!in_array($activeSemester, [1, 2], true)) $activeSemester = 1;

function subjectYearLevel($subject, $semester = 1) {
    global $subjectsBySemester;
    $catalog = $subjectsBySemester[$semester] ?? $subjectsBySemester[1];
    foreach ($catalog as $lvl => $list) {
        if (in_array($subject, $list, true)) return (int)$lvl;
    }
    return 0;
}

function esc_html($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ================= CREATE teacher_subjects / subject_students =================
   teacher_subjects : the subjects added to a teacher (one row per teacher+subject).
   subject_students : which students were imported/added under that subject.
   A subject with 0 rows in subject_students shows the RED "empty" sign in admin.php. */
$tsTableExisted = ($conn->query("SHOW TABLES LIKE 'teacher_subjects'")->num_rows > 0);

$conn->query("CREATE TABLE IF NOT EXISTS teacher_subjects (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT NOT NULL,
    subject        VARCHAR(50) NOT NULL,
    year_level     TINYINT NOT NULL DEFAULT 1,
    school_year_id INT NULL,
    UNIQUE KEY uq_teacher_subject (teacher_id, subject)
) ENGINE=InnoDB");

/* Semester support: existing rows become 1st semester; the unique key now includes the
   semester so the same subject can exist in both. New key is added BEFORE the old one is dropped. */
$tsSemCheck = $conn->query("SHOW COLUMNS FROM teacher_subjects LIKE 'semester'");
if ($tsSemCheck && $tsSemCheck->num_rows == 0) {
    $conn->query("ALTER TABLE teacher_subjects ADD COLUMN semester TINYINT NOT NULL DEFAULT 1");
}
try {
    $tsNewKey = $conn->query("SHOW INDEX FROM teacher_subjects WHERE Key_name = 'uq_teacher_subject_sem'");
    if ($tsNewKey && $tsNewKey->num_rows == 0) {
        $conn->query("ALTER TABLE teacher_subjects ADD UNIQUE KEY uq_teacher_subject_sem (teacher_id, subject, semester)");
    }
    $tsOldKey = $conn->query("SHOW INDEX FROM teacher_subjects WHERE Key_name = 'uq_teacher_subject'");
    $tsNewKey2 = $conn->query("SHOW INDEX FROM teacher_subjects WHERE Key_name = 'uq_teacher_subject_sem'");
    if ($tsOldKey && $tsOldKey->num_rows > 0 && $tsNewKey2 && $tsNewKey2->num_rows > 0) {
        $conn->query("ALTER TABLE teacher_subjects DROP INDEX uq_teacher_subject");
    }
} catch (Throwable $e) {
    // ignore: keep the old key if the migration can't finish
}

$conn->query("CREATE TABLE IF NOT EXISTS subject_students (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    teacher_subject_id INT NOT NULL,
    student_id         INT NOT NULL,
    UNIQUE KEY uq_subject_student (teacher_subject_id, student_id)
) ENGINE=InnoDB");

/* ================= IMPORT SECTIONS =================
   Section picked in the subject's "Upload Excel" form BEFORE importing. Starts with a fixed
   list (A-E); the admin can add more from the same form. The chosen section is saved on the
   subject_students row (NOT on students.section, so nothing that reads students.section changes). */
$conn->query("CREATE TABLE IF NOT EXISTS import_sections (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_import_section (name)
) ENGINE=InnoDB");

$ssSectionCheck = $conn->query("SHOW COLUMNS FROM subject_students LIKE 'section'");
if ($ssSectionCheck && $ssSectionCheck->num_rows == 0) {
    $conn->query("ALTER TABLE subject_students ADD COLUMN section VARCHAR(30) NULL");
}

$isCount = $conn->query("SELECT COUNT(*) AS c FROM import_sections")->fetch_assoc();
if ((int)$isCount['c'] === 0) {
    foreach (['A', 'B', 'C', 'D', 'E'] as $defSec) {
        $conn->query("INSERT IGNORE INTO import_sections (name) VALUES ('$defSec')");
    }
}

/* One-time backfill (only the first time these tables are created) so teachers/students
   that already exist from the old Section flow don't just disappear:
   - every subject saved in teachers.subject becomes a teacher_subjects row
   - students already linked to that teacher (student_teachers) are enrolled in the
     subject whose year level matches the student's year. */
if (!$tsTableExisted) {
    $legacyT = $conn->query("SELECT id, subject, school_year_id FROM teachers WHERE subject IS NOT NULL AND subject <> ''");
    while ($lt = $legacyT->fetch_assoc()) {
        foreach (array_filter(array_map('trim', explode(',', $lt['subject']))) as $subj) {
            $lvl = subjectYearLevel($subj);
            if ($lvl === 0) continue;
            $ins = $conn->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject, year_level, school_year_id) VALUES (?, ?, ?, ?)");
            $ins->bind_param("isii", $lt['id'], $subj, $lvl, $lt['school_year_id']);
            $ins->execute();
            $ins->close();
        }
    }
    $conn->query("
        INSERT IGNORE INTO subject_students (teacher_subject_id, student_id)
        SELECT ts.id, st.student_id
        FROM student_teachers st
        JOIN students s ON s.id = st.student_id
        JOIN teacher_subjects ts ON ts.teacher_id = st.teacher_id
                                AND ts.year_level = CAST(s.year AS UNSIGNED)
    ");
}

/* Keep teachers.subject = "FIL-1, GE-04" so analytics / student dashboard
   (which still read that column) keep working with no changes. */
function syncTeacherSubjectString($teacherId) {
    global $conn;
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ? ORDER BY year_level, subject");
    $stmt->bind_param("i", $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    $subs = [];
    while ($r = $res->fetch_assoc()) $subs[] = $r['subject'];
    $stmt->close();
    $joined = implode(', ', $subs);
    $upd = $conn->prepare("UPDATE teachers SET subject = ? WHERE id = ?");
    $upd->bind_param("si", $joined, $teacherId);
    $upd->execute();
    $upd->close();
}

/* ================= ADD SCHOOL YEAR ================= */
if (isset($_POST['add_school_year'])) {

    $label = trim($_POST['new_school_year'] ?? '');

    /* Past school years cannot be created - the present year or a later one is fine.
       The present year comes from the server calendar (real time): in 2026, 2026-2027 and later are allowed. */
    $syNowStart   = (int)date('Y');
    $syNowLabel   = $syNowStart . '-' . ($syNowStart + 1);
    $syLabelOk    = ($label === '') ? true : (bool)preg_match('/^\d{4}-\d{4}$/', $label) && ((int)substr($label, 5, 4) === (int)substr($label, 0, 4) + 1);
    $syLabelAllow = $syLabelOk && $label !== '' && ((int)substr($label, 0, 4) >= $syNowStart) && ((int)substr($label, 5, 4) === (int)substr($label, 0, 4) + 1);

    if ($label !== '' && !$syLabelOk) {
        $message .= "❌ Invalid format — use the format YYYY-YYYY (e.g. $syNowLabel)<br>";
    } elseif ($label !== '' && !$syLabelAllow) {
        $message .= "❌ Past school years cannot be created. The earliest you can add is $syNowLabel<br>";
    } elseif ($label !== '') {
        $check = $conn->prepare("SELECT id FROM school_years WHERE label = ?");
        $check->bind_param("s", $label);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message .= "❌ School year \"$label\" already exists<br>";
        } else {
            $insert = $conn->prepare("INSERT INTO school_years (label, is_active) VALUES (?, 0)");
            $insert->bind_param("s", $label);
            $insert->execute();
            $insert->close();
            $message .= "✅ School year \"$label\" added<br>";
            logAdminActivity($conn, 'School Year', 'Added school year', "School year \"$label\"");
        }
        $check->close();
    } else {
        $message .= "❌ Please enter a school year label<br>";
    }
}

/* ================= ACTIVATE SCHOOL YEAR (open evaluations for it) ================= */
if (isset($_POST['activate_school_year'])) {

    $syId = intval($_POST['activate_school_year']);

    // Only one school year can be active at a time.
    $conn->query("UPDATE school_years SET is_active = 0");

    $stmt = $conn->prepare("UPDATE school_years SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $syId);
    $stmt->execute();
    $stmt->close();

    $message .= "✅ School year activated — students can now submit evaluations for it<br>";
    logAdminActivity($conn, 'School Year', 'Activated school year', "School year \"" . activityLogSyLabel($conn, $syId) . "\" — evaluations opened for students");
}

/* ================= STOP SCHOOL YEAR (close evaluations for it) ================= */
if (isset($_POST['stop_school_year'])) {

    $syId = intval($_POST['stop_school_year']);

    $stmt = $conn->prepare("UPDATE school_years SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $syId);
    $stmt->execute();
    $stmt->close();

    $message .= "🛑 Evaluation period stopped for that school year. Past evaluations remain saved and visible.<br>";
    logAdminActivity($conn, 'School Year', 'Stopped evaluation period', "School year \"" . activityLogSyLabel($conn, $syId) . "\" — evaluations closed");
}

/* ================= FETCH SCHOOL YEARS ================= */
$schoolYears = [];
$syListRes = $conn->query("SELECT id, label, is_active FROM school_years ORDER BY label DESC");
while ($syRow = $syListRes->fetch_assoc()) {
    $schoolYears[] = $syRow;
}

$activeSchoolYear = null;
foreach ($schoolYears as $sy) {
    if ($sy['is_active']) {
        $activeSchoolYear = $sy;
        break;
    }
}


/* ================= HELPERS: teacher / subject lookup (active school year only) ================= */
/* Semester support: a teacher belongs to ONE semester (existing teachers = 1st semester),
   so the 2nd semester starts empty and teachers are added there separately. */
$teacherSemCheck = $conn->query("SHOW COLUMNS FROM teachers LIKE 'semester'");
if ($teacherSemCheck && $teacherSemCheck->num_rows == 0) {
    $conn->query("ALTER TABLE teachers ADD COLUMN semester TINYINT NOT NULL DEFAULT 1");
}

/* Title (Mr./Mrs./Ms.) is now CHOSEN by the admin when adding/editing a teacher
   and stored here - nothing is auto-guessed anymore. Existing teachers stay blank until edited. */
$teacherTitleCheck = $conn->query("SHOW COLUMNS FROM teachers LIKE 'title'");
if ($teacherTitleCheck && $teacherTitleCheck->num_rows == 0) {
    $conn->query("ALTER TABLE teachers ADD COLUMN title VARCHAR(10) NULL");
}

function cleanTeacherTitle($t) {
    $t = trim($t ?? '');
    return in_array($t, ['Mr.', 'Mrs.', 'Ms.'], true) ? $t : '';
}

$activeSyIdNow = $activeSchoolYear['id'] ?? null;

function getActiveTeacher($teacherId) {
    global $conn, $activeSyIdNow, $activeSemester;
    $stmt = $conn->prepare("SELECT id, name, subject FROM teachers WHERE id = ? AND school_year_id <=> ? AND semester = ?");
    $stmt->bind_param("iii", $teacherId, $activeSyIdNow, $activeSemester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getActiveTeacherSubject($tsId) {
    global $conn, $activeSyIdNow, $activeSemester;
    $stmt = $conn->prepare("
        SELECT ts.id, ts.teacher_id, ts.subject, ts.year_level, t.name AS teacher_name, t.title AS teacher_title
        FROM teacher_subjects ts
        JOIN teachers t ON t.id = ts.teacher_id
        WHERE ts.id = ? AND t.school_year_id <=> ? AND t.semester = ?
    ");
    $stmt->bind_param("iii", $tsId, $activeSyIdNow, $activeSemester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function teacherDisplayName($fullName, $title = '') {
    return trim(trim($title ?? '') . ' ' . getTeacherLastName($fullName));
}

/* ================= ADD TEACHER =================
   Replaces the old "Add Section". Creates a teacher tagged to the ACTIVE school year. */
if (isset($_POST['add_teacher'])) {
    $tName  = trim($_POST['teacher_name'] ?? '');
    $tTitle = cleanTeacherTitle($_POST['teacher_title'] ?? '');

    if ($activeSyIdNow === null) {
        $message .= "❌ No active school year — activate one first<br>";
    } elseif ($tName === '') {
        $message .= "❌ Please enter a teacher name<br>";
    } else {
        $dup = $conn->prepare("SELECT id FROM teachers WHERE LOWER(name) = LOWER(?) AND school_year_id = ? AND semester = ? LIMIT 1");
        $dup->bind_param("sii", $tName, $activeSyIdNow, $activeSemester);
        $dup->execute();
        $dup->store_result();
        $exists = $dup->num_rows > 0;
        $dup->close();

        if ($exists) {
            $message .= "❌ Teacher \"" . esc_html($tName) . "\" already exists for this school year<br>";
        } else {
            $ins = $conn->prepare("INSERT INTO teachers (name, title, subject, school_year_id, semester) VALUES (?, ?, '', ?, ?)");
            $ins->bind_param("ssii", $tName, $tTitle, $activeSyIdNow, $activeSemester);
            $ins->execute();
            $ins->close();
            $message .= "✅ Teacher \"" . esc_html($tName) . "\" added — now add a subject to them<br>";
            logAdminActivity($conn, 'Teacher', 'Added teacher', "Teacher \"$tName\" (SY " . ($activeSchoolYear['label'] ?? '') . ")");
        }
    }
}

/* ================= EDIT TEACHER NAME ================= */
if (isset($_POST['edit_teacher'])) {
    $teacherId = intval($_POST['edit_teacher']);
    $tName     = trim($_POST['teacher_name'] ?? '');
    $tTitle    = cleanTeacherTitle($_POST['teacher_title'] ?? '');

    if ($teacherId > 0 && $tName !== '' && getActiveTeacher($teacherId)) {
        $oldTeacherForLog = getActiveTeacher($teacherId);
        $upd = $conn->prepare("UPDATE teachers SET name = ?, title = ? WHERE id = ?");
        $upd->bind_param("ssi", $tName, $tTitle, $teacherId);
        $upd->execute();
        $upd->close();
        $message .= "✅ Teacher updated<br>";
        logAdminActivity($conn, 'Teacher', 'Edited teacher name', "\"" . ($oldTeacherForLog['name'] ?? '') . "\" → \"$tName\"");
    } else {
        $message .= "❌ Please enter a teacher name<br>";
    }
}

/* ================= DELETE TEACHER =================
   Removes the teacher for this school year together with their subjects, their
   student links and their evaluations (same cleanup the old "Remove Teacher" did).
   Student accounts themselves are NOT deleted. */
if (isset($_POST['delete_teacher'])) {
    $teacherId = intval($_POST['delete_teacher']);

    if ($teacherId > 0 && getActiveTeacher($teacherId)) {
        $delTeacherForLog = getActiveTeacher($teacherId);
        if (!recycleBinSaveTeacher($conn, $teacherId)) {
            $message .= "⚠️ Could not save a copy to the Recycle Bin<br>";
        }
        $conn->query("DELETE ss FROM subject_students ss JOIN teacher_subjects ts ON ts.id = ss.teacher_subject_id WHERE ts.teacher_id = $teacherId");
        $conn->query("DELETE FROM teacher_subjects WHERE teacher_id = $teacherId");
        $conn->query("DELETE FROM evaluations WHERE teacher_id = $teacherId");
        $conn->query("DELETE FROM student_teachers WHERE teacher_id = $teacherId");
        $conn->query("DELETE FROM section_teachers WHERE teacher_id = $teacherId");
        $conn->query("DELETE FROM teachers WHERE id = $teacherId");
        $message .= "✅ Teacher removed<br>";
        logAdminActivity($conn, 'Teacher', 'Deleted teacher', "Teacher \"" . ($delTeacherForLog['name'] ?? '') . "\" removed together with their subjects, student links and evaluations");
    }
}

/* ================= ADD SUBJECT(S) TO A TEACHER ================= */
if (isset($_POST['add_subjects'])) {
    $teacherId = intval($_POST['add_subjects']);
    $picked    = array_values(array_unique(array_filter(array_map('trim', $_POST['subjects'] ?? []))));

    if ($teacherId > 0 && getActiveTeacher($teacherId) && !empty($picked)) {
        $added = 0;
        $addedSubjNames = [];
        foreach ($picked as $subj) {
            $lvl = subjectYearLevel($subj, $activeSemester);
            if ($lvl === 0) continue; // not in the catalog
            $ins = $conn->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject, year_level, school_year_id, semester) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param("isiii", $teacherId, $subj, $lvl, $activeSyIdNow, $activeSemester);
            $ins->execute();
            $added += $ins->affected_rows > 0 ? 1 : 0;
            if ($ins->affected_rows > 0) $addedSubjNames[] = $subj;
            $ins->close();
        }
        syncTeacherSubjectString($teacherId);
        if ($added > 0) {
            $subjTeacherForLog = getActiveTeacher($teacherId);
            logAdminActivity($conn, 'Subject', 'Added subject(s) to teacher', "Teacher \"" . ($subjTeacherForLog['name'] ?? '') . "\" — added: " . implode(', ', $addedSubjNames));
        }
        $message .= $added > 0
            ? "✅ $added subject(s) added — click the subject to import students<br>"
            : "ℹ️ Those subjects were already added<br>";
    } else {
        $message .= "❌ Please pick at least one subject<br>";
    }
}

/* ================= REMOVE SUBJECT FROM A TEACHER ================= */
if (isset($_POST['remove_subject'])) {
    $tsId = intval($_POST['remove_subject']);
    $ts   = getActiveTeacherSubject($tsId);

    if ($ts) {
        // students that were enrolled in this subject
        $enrolled = [];
        $q = $conn->query("SELECT student_id FROM subject_students WHERE teacher_subject_id = $tsId");
        while ($r = $q->fetch_assoc()) $enrolled[] = (int)$r['student_id'];

        if (!recycleBinSaveSubject($conn, $tsId)) {
            $message .= "⚠️ Could not save a copy to the Recycle Bin<br>";
        }
        $conn->query("DELETE FROM subject_students WHERE teacher_subject_id = $tsId");
        $conn->query("DELETE FROM teacher_subjects WHERE id = $tsId");

        // un-link the teacher from a student only if that student isn't in any other subject of the same teacher
        foreach ($enrolled as $sid) {
            $left = $conn->prepare("
                SELECT COUNT(*) AS c FROM subject_students ss
                JOIN teacher_subjects x ON x.id = ss.teacher_subject_id
                WHERE ss.student_id = ? AND x.teacher_id = ?
            ");
            $left->bind_param("ii", $sid, $ts['teacher_id']);
            $left->execute();
            $c = (int)($left->get_result()->fetch_assoc()['c'] ?? 0);
            $left->close();
            if ($c === 0) {
                $conn->query("DELETE FROM student_teachers WHERE student_id = $sid AND teacher_id = " . (int)$ts['teacher_id']);
            }
        }

        syncTeacherSubjectString((int)$ts['teacher_id']);
        $message .= "✅ Subject " . esc_html($ts['subject']) . " removed<br>";
        logAdminActivity($conn, 'Subject', 'Removed subject from teacher', "Subject \"" . $ts['subject'] . "\" removed from teacher \"" . $ts['teacher_name'] . "\" (" . count($enrolled) . " enrolled student(s) unlinked)");
    }
}

/* ================= MAILER (same SMTP settings as before, now in one place) ================= */
function sendStudentAccountEmail($email, $name, $studentID, $passwordPlain, $ts) {
    global $activeSchoolYear;

    $schoolYearLabel = $activeSchoolYear['label'] ?? '';
    $teacherLabel    = teacherDisplayName($ts['teacher_name'], $ts['teacher_title'] ?? '');

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'donglopez041@gmail.com';
    $mail->Password   = 'dbqzacfmoyjarngg';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->setFrom('donglopez041@gmail.com', 'School Admin');
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = "Student Account" . ($schoolYearLabel ? " - SY $schoolYearLabel" : "");
    $mail->Body    = "
        <h3>Hello " . esc_html($name) . "</h3>
        <p><b>ID:</b> " . esc_html($studentID) . "</p>
        <p><b>Password:</b> $passwordPlain</p>
        <p><b>Subject:</b> " . esc_html($ts['subject']) . " (Year " . (int)$ts['year_level'] . ")</p>
        <p><b>Teacher:</b> " . esc_html($teacherLabel) . "</p>" .
        ($schoolYearLabel ? "<p><b>School Year:</b> " . esc_html($schoolYearLabel) . "</p>" : "") . "
    ";
    $mail->send();
}

/* Put a student under a subject (and link the subject's teacher to the student so
   the teacher shows up on the student's evaluation page). */
function enrollStudentInSubject($studentPk, $ts, $importSection = null) {
    global $conn;
    $a = $conn->prepare("INSERT IGNORE INTO subject_students (teacher_subject_id, student_id, section) VALUES (?, ?, ?)");
    $a->bind_param("iis", $ts['id'], $studentPk, $importSection);
    $a->execute();
    $a->close();

    $b = $conn->prepare("INSERT IGNORE INTO student_teachers (student_id, teacher_id) VALUES (?, ?)");
    $b->bind_param("ii", $studentPk, $ts['teacher_id']);
    $b->execute();
    $b->close();
}

function isEnrolledInSubject($studentPk, $tsId) {
    global $conn;
    $c = $conn->prepare("SELECT id FROM subject_students WHERE teacher_subject_id = ? AND student_id = ?");
    $c->bind_param("ii", $tsId, $studentPk);
    $c->execute();
    $c->store_result();
    $n = $c->num_rows;
    $c->close();
    return $n > 0;
}

/* ================= EXCEL IMPORT (per subject) =================
   Same logic as before: reads the Excel, creates the account with a random
   password, saves Student No. as the login ID, and EMAILS the ID + password.
   Difference: students go under the clicked subject (and its teacher) instead of a section.
   If the Student No. already has an account this school year (e.g. they are also in
   another subject), no new account/email is made — they are just added to this subject. */
function importSubjectStudents($file, $ts, $importSection = null) {

    global $conn, $activeSchoolYear;

    $activeSchoolYearId = $activeSchoolYear['id'] ?? null;
    $yearLevel = (string)(int)$ts['year_level'];

    $sent = 0; $failed = 0; $linked = 0; $skipped = 0; $missingData = 0;
    $smtpErrors = [];

    $xlsx = SimpleXLSX::parse($file);
    if (!$xlsx) {
        return "❌ Could not read the Excel file: " . esc_html(SimpleXLSX::parseError());
    }

    $rows = $xlsx->rows();
    $headers = array_map(function($h) {
        return strtolower(str_replace(' ', '', trim($h)));
    }, array_shift($rows));

    $validRows = array_values(array_filter($rows, fn($r) => !empty(array_filter($r))));

    /* Same-file guard: if EVERY student in this file is already in THIS subject, the same file
       was already imported here, so it is blocked (before any account/email is made).
       A different file, or the same students going into a different subject, still imports. */
    $fileStudentNos = [];
    foreach ($validRows as $vr) {
        $vr = array_slice(array_pad($vr, count($headers), ''), 0, count($headers));
        $vs = array_combine($headers, $vr);
        $vNo    = trim($vs['studentno.']   ?? '');
        $vEmail = trim($vs['emailaddress'] ?? '');
        if ($vNo !== '' && $vEmail !== '') $fileStudentNos[$vNo] = true;
    }
    if (!empty($fileStudentNos)) {
        $allInSubject = true;
        $guard = $conn->prepare("
            SELECT ss.id FROM subject_students ss
            JOIN students s ON s.id = ss.student_id
            WHERE ss.teacher_subject_id = ? AND s.student_id = ? AND s.school_year_id <=> ?
            LIMIT 1
        ");
        foreach (array_keys($fileStudentNos) as $no) {
            $no = (string)$no;
            $guard->bind_param("isi", $ts['id'], $no, $activeSchoolYearId);
            $guard->execute();
            $guard->store_result();
            if ($guard->num_rows === 0) { $allInSubject = false; break; }
        }
        $guard->close();
        if ($allInSubject) {
            return "❌ This Excel file was already imported to " . esc_html($ts['subject']) . " — all " . count($fileStudentNos) . " students are already in this subject. Upload a different file, or import it to another subject.";
        }
    }

    foreach ($validRows as $row) {

        $row     = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
        $student = array_combine($headers, $row);

        $studentID        = trim($student['studentno.']       ?? '');
        $registrationNo   = $student['registrationno.']  ?? '';
        $email            = trim($student['emailaddress']     ?? '');
        $name             = $student['studentname']      ?? 'Student';
        $lastName = ''; $firstName = ''; $middleName = '';
        $course           = $student['course']           ?? '';
        $gender           = $student['sex']              ?? '';
        $birthday         = $student['dateofbirth']      ?? '';
        $contactNumber    = $student['mobile#']          ?? '';
        $barangay         = $student['barangay']          ?? '';
        $cityMunicipality = $student['city/municipality'] ?? '';
        $province         = $student['province']          ?? '';
        $address          = trim(implode(', ', array_filter([$barangay, $cityMunicipality, $province])));

        if ($studentID && $email) {

            $check = $conn->prepare("SELECT id FROM students WHERE student_id=? AND school_year_id <=> ? LIMIT 1");
            $check->bind_param("si", $studentID, $activeSchoolYearId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $pk = (int)$existing['id'];
                if (isEnrolledInSubject($pk, $ts['id'])) {
                    $skipped++;
                } else {
                    enrollStudentInSubject($pk, $ts, $importSection);
                    $linked++;
                }
                continue;
            }

            $passwordPlain = substr(bin2hex(random_bytes(4)), 0, 8);
            $hashed        = password_hash($passwordPlain, PASSWORD_DEFAULT);
            $extraInfo     = json_encode($student);
            $section       = '';
            $teacherId     = (int)$ts['teacher_id'];

            $insert = $conn->prepare("
                INSERT INTO students (student_id, name, email, password, year, section, teacher_id, extra_info, last_name, first_name, middle_name, gender, birthday, address, contact_number, registration_no, course, barangay, city_municipality, province, school_year_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param("ssssssisssssssssssssi", $studentID, $name, $email, $hashed, $yearLevel, $section, $teacherId, $extraInfo, $lastName, $firstName, $middleName, $gender, $birthday, $address, $contactNumber, $registrationNo, $course, $barangay, $cityMunicipality, $province, $activeSchoolYearId);
            $insert->execute();
            $newStudentPk = $insert->insert_id;
            $insert->close();

            enrollStudentInSubject($newStudentPk, $ts, $importSection);

            try {
                sendStudentAccountEmail($email, $name, $studentID, $passwordPlain, $ts);
                $sent++;
            } catch (Exception $e) {
                $failed++;
                $smtpErrors[] = htmlspecialchars($e->getMessage());
            }

        } elseif (!empty(array_filter($student))) {
            $missingData++;
        }
    }

    $result = esc_html($ts['subject']) . " (" . esc_html(teacherDisplayName($ts['teacher_name'], $ts['teacher_title'] ?? '')) . ") → Sent: $sent | Failed: $failed | Already had an account (added to subject): $linked | Already in this subject (skipped): $skipped";
    if ($activeSchoolYear) {
        $result = "[" . esc_html($activeSchoolYear['label']) . "] " . $result;
    }
    if ($importSection !== null && $importSection !== '') {
        $result .= " | Section: " . esc_html($importSection);
    }
    if ($missingData > 0) {
        $result .= " | Missing Student No./Email (skipped): $missingData";
    }
    if (!empty($smtpErrors)) {
        $result .= "<br><span style='color:red;font-size:12px;'>SMTP Error: " . implode(" | ", array_unique($smtpErrors)) . "</span>";
    }
    return $result;
}

if (isset($_POST['import_subject'])) {
    $tsId = intval($_POST['import_subject']);
    $ts   = getActiveTeacherSubject($tsId);
    $file = $_FILES['student_file'] ?? null;

    if (!$ts) {
        $message .= "❌ Subject not found for the active school year<br>";
    } elseif (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $message .= "❌ Please choose an Excel file to upload<br>";
    } else {
        // Section must be picked first. "__new__" = admin typed a new section in the form.
        $pickedSection = trim($_POST['section'] ?? '');
        if ($pickedSection === '__new__') {
            $pickedSection = trim(preg_replace('/\s+/', ' ', $_POST['new_section'] ?? ''));
            if ($pickedSection !== '' && mb_strlen($pickedSection) <= 30) {
                $addSec = $conn->prepare("INSERT IGNORE INTO import_sections (name) VALUES (?)");
                $addSec->bind_param("s", $pickedSection);
                $addSec->execute();
                $addSec->close();
            } else {
                $pickedSection = '';
            }
        }
        if ($pickedSection !== '') {
            $secLookup = $conn->prepare("SELECT name FROM import_sections WHERE name = ? LIMIT 1");
            $secLookup->bind_param("s", $pickedSection);
            $secLookup->execute();
            $secRow = $secLookup->get_result()->fetch_assoc();
            $secLookup->close();
            $pickedSection = $secRow['name'] ?? '';
        }

        if ($pickedSection === '') {
            $message .= "❌ Please pick a section (or type a new one, max 30 characters) before importing<br>";
        } else {
            $importResultForLog = importSubjectStudents($file['tmp_name'], $ts, $pickedSection);
            $message .= $importResultForLog . "<br>";
            logAdminActivity(
                $conn, 'Student', 'Imported students from Excel',
                "File \"" . ($file['name'] ?? '') . "\" → " . $ts['subject'] . " (" . $ts['teacher_name'] . "), Section " . $pickedSection . " | " . trim(strip_tags(html_entity_decode(preg_replace('/<br\s*\/?>/i', ' ', $importResultForLog)))),
                (strpos($importResultForLog, '❌') === 0) ? 'failed' : 'success'
            );
        }
    }
}

/* ================= ADD STUDENT (MANUAL) — now picks a Subject instead of a Section ================= */
if (isset($_POST['add_student'])) {

    $tsId          = intval($_POST['subject_id'] ?? 0);
    $studentID     = trim($_POST['student_id']    ?? '');
    $lastName      = trim($_POST['last_name']      ?? '');
    $firstName     = trim($_POST['first_name']     ?? '');
    $middleName    = trim($_POST['middle_name']    ?? '');
    $gender        = trim($_POST['gender']         ?? '');
    $birthday      = trim($_POST['birthday']       ?? '');
    $address       = trim($_POST['address']        ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $studentEmail  = trim($_POST['student_email']  ?? '');
    $studentName   = preg_replace('/\s+/', ' ', trim("$firstName $middleName $lastName"));
    $ts            = $tsId > 0 ? getActiveTeacherSubject($tsId) : null;

    if ($ts && $studentID && $lastName && $firstName && $studentEmail) {

        $activeSchoolYearId = $activeSchoolYear['id'] ?? null;

        $check = $conn->prepare("SELECT id FROM students WHERE student_id=? AND school_year_id <=> ? LIMIT 1");
        $check->bind_param("si", $studentID, $activeSchoolYearId);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $pk = (int)$existing['id'];
            if (isEnrolledInSubject($pk, $ts['id'])) {
                $message .= "❌ Student ID already exists in " . esc_html($ts['subject']) . " for this school year<br>";
            } else {
                enrollStudentInSubject($pk, $ts);
                $message .= "✅ Student already had an account this school year — added to " . esc_html($ts['subject']) . " (no new email sent)<br>";
                logAdminActivity($conn, 'Student', 'Added existing student to subject', "Student No. $studentID added to " . $ts['subject'] . " (" . $ts['teacher_name'] . ")");
            }
        } else {
            $passwordPlain = substr(bin2hex(random_bytes(4)), 0, 8);
            $hashed        = password_hash($passwordPlain, PASSWORD_DEFAULT);
            $yearLevel     = (string)(int)$ts['year_level'];
            $section       = '';
            $teacherId     = (int)$ts['teacher_id'];

            $insert = $conn->prepare("
                INSERT INTO students (student_id, name, email, password, year, section, teacher_id, last_name, first_name, middle_name, gender, birthday, address, contact_number, school_year_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param("ssssssisssssssi", $studentID, $studentName, $studentEmail, $hashed, $yearLevel, $section, $teacherId, $lastName, $firstName, $middleName, $gender, $birthday, $address, $contactNumber, $activeSchoolYearId);
            $insert->execute();
            $newStudentPk = $insert->insert_id;
            $insert->close();

            enrollStudentInSubject($newStudentPk, $ts);

            try {
                sendStudentAccountEmail($studentEmail, $studentName, $studentID, $passwordPlain, $ts);
                $message .= "✅ Student " . esc_html($studentName) . " added to " . esc_html($ts['subject']) . " and emailed<br>";
                logAdminActivity($conn, 'Student', 'Added student manually', "$studentName (Student No. $studentID) added to " . $ts['subject'] . " (" . $ts['teacher_name'] . ") — account email sent");
            } catch (Exception $e) {
                $message .= "✅ Student " . esc_html($studentName) . " added, but email failed: " . esc_html($e->getMessage()) . "<br>";
                logAdminActivity($conn, 'Student', 'Added student manually', "$studentName (Student No. $studentID) added to " . $ts['subject'] . " (" . $ts['teacher_name'] . ") — account email FAILED", 'failed');
            }
        }
    } else {
        $message .= "❌ Missing required fields to add student<br>";
    }
}

/* ================= FETCH TEACHERS (scoped to the active school year) =================
   A teacher added while a given school year is active is ONLY shown here
   while THAT exact school year is active — same idea as sections/students
   above. A teacher not explicitly tied to the active year never shows up,
   even if it has no year tag at all — every school year's teacher list is
   completely independent. */
$teachers = [];
$activeTeacherSyId = $activeSchoolYear['id'] ?? null;
$tRes = $conn->prepare("SELECT id, name, title, subject, school_year_id FROM teachers WHERE school_year_id = ? AND semester = ? ORDER BY name");
$tRes->bind_param("ii", $activeTeacherSyId, $activeSemester);
$tRes->execute();
$tFetchResult = $tRes->get_result();
while ($tRow = $tFetchResult->fetch_assoc()) {
    $teachers[] = $tRow;
}
$tRes->close();

/* ================= FETCH ALL TEACHER NAMES EVER USED (reuse suggestions) =================
   Lets the admin pick/reuse an existing teacher's name from ANY past school
   year when adding a teacher for the currently active one, instead of
   retyping it from scratch — this is what makes it easy to use "the same
   teacher" every year. Picking a suggested name doesn't change any of the
   scoping above: a fresh teacher row (with whichever subjects are checked)
   still gets created for the active year if one doesn't already exist for
   it, so each year keeps its own subject(s) for that teacher. */
$allTeacherNames = [];
$allNamesRes = $conn->query("SELECT DISTINCT name FROM teachers ORDER BY name");
while ($nRow = $allNamesRes->fetch_assoc()) {
    $allTeacherNames[] = $nRow['name'];
}


/* ================= FETCH SUBJECTS + STUDENTS PER SUBJECT (active school year) ================= */
$teacherSubjects = [];   // teacher_id => [ [id, subject, year_level, count], ... ]
$subjectStudents = [];   // teacher_subject_id => [ [student_id, name, email], ... ]
$subjSyId = $activeSchoolYear['id'] ?? null;

$tsStmt = $conn->prepare("
    SELECT ts.id, ts.teacher_id, ts.subject, ts.year_level,
           (SELECT COUNT(*) FROM subject_students ss WHERE ss.teacher_subject_id = ts.id) AS cnt
    FROM teacher_subjects ts
    JOIN teachers t ON t.id = ts.teacher_id
    WHERE t.school_year_id <=> ? AND ts.semester = ?
    ORDER BY ts.year_level, ts.subject
");
$tsStmt->bind_param("ii", $subjSyId, $activeSemester);
$tsStmt->execute();
$tsRes = $tsStmt->get_result();
while ($r = $tsRes->fetch_assoc()) {
    $teacherSubjects[(int)$r['teacher_id']][] = [
        'id'         => (int)$r['id'],
        'subject'    => $r['subject'],
        'year_level' => (int)$r['year_level'],
        'count'      => (int)$r['cnt'],
    ];
}
$tsStmt->close();

$ssStmt = $conn->prepare("
    SELECT ss.teacher_subject_id, s.student_id, s.name, s.email, ss.section
    FROM subject_students ss
    JOIN students s ON s.id = ss.student_id
    JOIN teacher_subjects ts ON ts.id = ss.teacher_subject_id
    JOIN teachers t ON t.id = ts.teacher_id
    WHERE t.school_year_id <=> ? AND ts.semester = ?
    ORDER BY s.name
");
$ssStmt->bind_param("ii", $subjSyId, $activeSemester);
$ssStmt->execute();
$ssRes = $ssStmt->get_result();
while ($r = $ssRes->fetch_assoc()) {
    $subjectStudents[(int)$r['teacher_subject_id']][] = $r;
}
$ssStmt->close();

/* ================= IMPORT SECTION LIST (for the dropdown in the Upload Excel form) ================= */
$importSections = [];
$isRes = $conn->query("SELECT name FROM import_sections ORDER BY id ASC");
if ($isRes) {
    while ($r = $isRes->fetch_assoc()) $importSections[] = $r['name'];
}

/* ================= RETURN ================= */
return [
    "message"          => $message,
    "teachers"         => $teachers,
    "allTeacherNames"  => $allTeacherNames,
    "teacherSubjects"  => $teacherSubjects,
    "subjectStudents"  => $subjectStudents,
    "importSections"   => $importSections,
    "subjectsByYear"   => $subjectsBySemester[$activeSemester],
    "activeSemester"   => $activeSemester,
    "schoolYears"     => $schoolYears,
    "activeSchoolYear" => $activeSchoolYear
];