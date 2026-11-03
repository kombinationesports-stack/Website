<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$conn = new mysqli("localhost", "root", "", "school_system");

/* ================= HELPER: auto-guess Mr./Mrs. for display =================
   No gender field exists in the teachers table, so this guesses a title
   from the teacher's FIRST name only, for display purposes — it never
   touches the stored name, DB, or any existing logic. Uses a curated list
   of common Filipino/English first names, with a couple of fallback rules
   (e.g. "Ma. " prefix = Maria = female) for names not in the list. Not
   100% guaranteed correct for every name — let me know if a specific
   teacher shows the wrong title and I can add them to the list. */
function guessTeacherTitle($fullName) {
    $fullName = trim($fullName ?? '');
    if ($fullName === '') return '';

    $parts     = preg_split('/\s+/', $fullName);
    $firstName = strtolower($parts[0] ?? '');
    $firstName = preg_replace('/[^a-z]/', '', $firstName);

    // "Ma." is the common Filipino short form for "Maria" (e.g. "Ma. Teresa Santos")
    if (strtolower($parts[0] ?? '') === 'ma.' || strtolower($parts[0] ?? '') === 'ma') {
        return 'Mrs.';
    }

    $femaleNames = [
        'maria','ana','anna','rosa','carmen','teresa','cristina','elena','grace',
        'angela','joy','liza','nora','divina','jennifer','michelle','katherine',
        'karen','susan','linda','nancy','sarah','emily','elizabeth','margaret',
        'patricia','barbara','jessica','amanda','melissa','rachel','laura',
        'stephanie','rebecca','sharon','cynthia','kathleen','shirley','brenda',
        'emma','olivia','sophia','isabella','mia','charlotte','amelia','evelyn',
        'abigail','ella','chloe','victoria','lily','natalie','camila','hannah',
        'zoe','leah','claire','eleanor','samantha','stella','violet','mila',
        'allison','alexa','lourdes','fe','corazon','remedios','consuelo',
        'imelda','divina','editha','marilou','luzviminda','josephine',
        'erlinda','milagros','zenaida','perla','adoracion','alicia','estrella',
        'leticia','priscilla','salve','susana','veronica','yolanda','gloria',
        'evangeline','flordeliza','myrna','emelita','ligaya','virginia',
        'juliet','janet','jocelyn','joan','joanna','jenny','judith','julie',
        'rowena','rosario','rosalinda','marites','malou','melba','melinda',
        'nenita','nelia','norma','olivia','ofelia','precious','ruby','ruth',
        'sheila','sonia','vilma','wilma','ester','esther','faith','felicidad',
        'hazel','irene','iris','ivy','janice','jasmine','katrina','kristine',
        'lorna','lucia','lucy','mary','marilyn','marissa','mercedes','minerva',
        'monica','nida','pilar','regina','riza','rosemarie','sylvia','teresita',
        'vivian','angel','angelica','angelina','princess','queenie','rica',
        'rina','riza','roselle','marife','jasmin','janine','jean','jhoana',
    ];

    $maleNames = [
        'juan','jose','pedro','carlos','miguel','ricardo','roberto','fernando',
        'eduardo','antonio','manuel','francisco','rafael','daniel','david',
        'michael','james','john','robert','william','richard','joseph',
        'thomas','charles','christopher','mark','paul','steven','kevin',
        'brian','george','edward','ronald','timothy','jason','jeffrey','ryan',
        'jacob','gary','nicholas','eric','stephen','jonathan','larry','justin',
        'scott','brandon','benjamin','samuel','gregory','alexander','patrick',
        'frank','raymond','jack','dennis','jerry','tyler','aaron','adam',
        'nathan','henry','douglas','zachary','peter','kyle','walter','noah',
        'ethan','jeremy','christian','keith','roger','terry','austin','sean',
        'gerald','carl','harold','dylan','arthur','lawrence','jordan','jesse',
        'bryan','billy','bruce','gabriel','joe','logan','alan','willie',
        'elijah','wayne','ralph','roy','eugene','randy','vincent','russell',
        'louis','bobby','victor','martin','ernest','phillip','todd','craig',
        'shawn','clarence','philip','chris','johnny','earl','jimmy','danny',
        'tony','luis','mike','stanley','leonard','nathaniel','dominic',
        'rodrigo','reynaldo','ramon','ruben','romeo','rogelio','rolando',
        'ernesto','alfredo','alberto','arnel','arnold','bayani','bernardo',
        'bienvenido','celso','cesar','conrado','crisanto','danilo','diosdado',
        'domingo','edgardo','eleazar','elmer','emilio','enrico','ferdinand',
        'gaudencio','gregorio','herminio','honorio','isagani','jaime','joel',
        'jovito','leandro','leonardo','lorenzo','mariano','mario','marlon',
        'nestor','noel','norberto','oscar','pablo','pio','quirino','renato',
        'rey','rodel','rodolfo','salvador','santiago','severino','teodoro',
        'vicente','virgilio','wilfredo','ariel','allan','angelo','benito',
        'bonifacio','christian','dexter','edwin','efren','felix','florante',
        'francis','harold','ian','ismael','jayson','jeffrey','jerome','jonathan',
        'julius','kevin','leo','marvin','melvin','orlando','paulo','raul',
        'reynold','ricky','rico','robert','rommel','ronnel','rustom','samuel',
        'wilson','warren','zaldy',
    ];

    if (in_array($firstName, $femaleNames, true)) return 'Mrs.';
    if (in_array($firstName, $maleNames, true))   return 'Mr.';

    // Fallback: first names ending in "a" skew heavily female in
    // Filipino/Spanish naming conventions (Maria, Rosa, Angela, etc.)
    if (substr($firstName, -1) === 'a') return 'Mrs.';

    // Default fallback when nothing matched
    return 'Mr.';
}

$studentId = $_SESSION['student_id'];

/* ================= PER-SUBJECT EVALUATIONS (additive migration) =================
   A teacher who handles 2+ subjects (e.g. FIL-3 and GE-04) is now evaluated ONCE PER SUBJECT.
   - adds evaluations.teacher_subject_id (NULL for all evaluations saved before this change)
   - adds a unique key (student, teacher, school year, subject) and drops the older
     (student, teacher[, school year]) unique keys, which would block the 2nd subject.
   Old evaluations are NOT modified or deleted. Safe to run on every load. */
$hasEvalSubjectCol = false;
try {
    $chk = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'teacher_subject_id'");
    if ($chk && $chk->num_rows == 0) {
        $conn->query("ALTER TABLE evaluations ADD COLUMN teacher_subject_id INT NULL");
    }
    $chk = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'teacher_subject_id'");
    $hasEvalSubjectCol = ($chk && $chk->num_rows > 0);
} catch (Throwable $e) {
    $hasEvalSubjectCol = false;
}

if ($hasEvalSubjectCol) {
    try {
        $uqIdx = [];
        $uqRes = $conn->query("
            SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluations' AND NON_UNIQUE = 0
            GROUP BY INDEX_NAME
        ");
        if ($uqRes) {
            while ($uqRow = $uqRes->fetch_assoc()) $uqIdx[$uqRow['INDEX_NAME']] = $uqRow['cols'];
        }
        // add the new key FIRST, then drop the old ones (same safe order admin_backend.php uses)
        if (!isset($uqIdx['unique_eval_subject'])) {
            $conn->query("ALTER TABLE evaluations ADD UNIQUE KEY unique_eval_subject (student_id, teacher_id, school_year_id, teacher_subject_id)");
        }
        foreach ($uqIdx as $uqName => $uqCols) {
            if ($uqName === 'PRIMARY' || $uqName === 'unique_eval_subject') continue;
            if ($uqCols === 'student_id,teacher_id' || $uqCols === 'student_id,teacher_id,school_year_id') {
                try { $conn->query("ALTER TABLE evaluations DROP INDEX `$uqName`"); } catch (Throwable $e) { /* leave it */ }
            }
        }
    } catch (Throwable $e) {
        // Never block the dashboard if the key can't be changed.
    }
}

/* ================= FETCH ACTIVE SCHOOL YEAR (needed first, to pick the right account row) ================= */
$activeSyRow           = $conn->query("SELECT id, label FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$activeSchoolYearId    = $activeSyRow['id']    ?? null;
$activeSchoolYearLabel = $activeSyRow['label'] ?? null;

/* ================= FETCH STUDENT INFO =================
   The same Student No. can have one account row PER school year. Prefer the row that belongs to
   the active school year (newest first), instead of whichever row the database returns first. */
$stmt = $conn->prepare("
    SELECT id, name, year, section
    FROM students
    WHERE student_id = ?
    ORDER BY (school_year_id <=> ?) DESC, id DESC
    LIMIT 1
");
$stmt->bind_param("si", $studentId, $activeSchoolYearId);
$stmt->execute();
$stmt->bind_result($studentDbId, $studentName, $studentYear, $studentSection);
$stmt->fetch();
$stmt->close();

/* ================= FETCH ACTIVE SCHOOL YEAR ================= */
$activeSyRow           = $conn->query("SELECT id, label FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$activeSchoolYearId    = $activeSyRow['id']    ?? null;
$activeSchoolYearLabel = $activeSyRow['label'] ?? null;

/* ================= FETCH TEACHERS ASSIGNED TO THIS SECTION, FOR THIS SCHOOL YEAR =================
   Scoped to the active school year so a student only sees the teacher(s)
   actually assigned for the school year their account belongs to — e.g.
   Cruz for 2026-2027, Mendoza for 2025-2026 — instead of every teacher
   ever assigned to the section across all years. */
/* Teachers now come from the SUBJECTS the student is enrolled in (admin.php: Teacher -> Subject -> Excel import).
   One card per teacher AND subject: if the student has 2 subjects with the same teacher (e.g. FIL-3 and GE-04),
   each subject gets its own card and is evaluated separately. */
$teachers = [];
$stmt = $conn->prepare("
    SELECT DISTINCT t.id, t.name, ts.id AS teacher_subject_id, ts.subject AS subject, ts.year_level
    FROM subject_students ss
    JOIN students s          ON s.id  = ss.student_id
    JOIN teacher_subjects ts ON ts.id = ss.teacher_subject_id
    JOIN teachers t          ON t.id  = ts.teacher_id
    WHERE s.student_id = ? AND t.school_year_id <=> ?
    ORDER BY t.name, ts.year_level, ts.subject
");
if ($stmt) {
    $stmt->bind_param("si", $studentId, $activeSchoolYearId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['teacher_subject_id'] = (int)$row['teacher_subject_id'];
        $row['card_key']           = 's' . $row['teacher_subject_id'];
        $teachers[$row['card_key']] = $row;
    }
    $stmt->close();
}

/* Legacy fallback: students created under the old Section system (they have a section)
   still see the teachers assigned to that section. */
if ($studentSection !== null && $studentSection !== '') {
    $listedTeacherIds = [];
    foreach ($teachers as $listedCard) $listedTeacherIds[$listedCard['id']] = true;
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.subject
        FROM section_teachers st
        JOIN teachers t ON st.teacher_id = t.id
        WHERE st.year = ? AND st.section = ? AND st.school_year_id <=> ?
        ORDER BY t.name
    ");
    $stmt->bind_param("ssi", $studentYear, $studentSection, $activeSchoolYearId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!isset($listedTeacherIds[$row['id']])) {
            $row['teacher_subject_id'] = 0;
            $row['card_key']           = 't' . $row['id'];
            $teachers[$row['card_key']] = $row;
        }
    }
    $stmt->close();
}
$teachers = array_values($teachers);

/* Hint: subjects exist for this student but under a different school year than the active one. */
$otherSyHint = '';
if (empty($teachers)) {
    $h = $conn->prepare("
        SELECT DISTINCT COALESCE(sy.label, 'no school year') AS label
        FROM subject_students ss
        JOIN students s          ON s.id  = ss.student_id
        JOIN teacher_subjects ts ON ts.id = ss.teacher_subject_id
        JOIN teachers t          ON t.id  = ts.teacher_id
        LEFT JOIN school_years sy ON sy.id = t.school_year_id
        WHERE s.student_id = ?
    ");
    if ($h) {
        $h->bind_param("s", $studentId);
        $h->execute();
        $hr = $h->get_result();
        $labels = [];
        while ($x = $hr->fetch_assoc()) $labels[] = $x['label'];
        $h->close();
        if (!empty($labels)) {
            $otherSyHint = 'Your subjects are under School Year ' . implode(', ', $labels)
                         . ', but the active school year is ' . ($activeSchoolYearLabel ?? 'none') . '.';
        }
    }
}

/* ================= FETCH ALREADY COMPLETED EVALUATIONS (for the active school year only) =================
   Scoping this to the active school year means that once a new school year
   opens, teachers become "Pending" again for evaluation, while everything
   the student submitted in past school years stays saved and untouched. */
$completed = [];
if ($activeSchoolYearId) {
    $evalRes = $conn->prepare("SELECT teacher_id, teacher_subject_id FROM evaluations WHERE student_id = ? AND school_year_id = ?");
    $evalRes->bind_param("ii", $studentDbId, $activeSchoolYearId);
    $evalRes->execute();
    $evalResult = $evalRes->get_result();
    $legacyEvalTeachers = [];
    while ($row = $evalResult->fetch_assoc()) {
        if (!empty($row['teacher_subject_id'])) {
            $completed['s' . $row['teacher_subject_id']] = true;
        } else {
            // evaluation saved before per-subject evaluations existed (covers the teacher's first card only)
            $legacyEvalTeachers[$row['teacher_id']] = true;
        }
    }
    $evalRes->close();

    $legacyUsed = [];
    foreach ($teachers as $card) {
        if (isset($legacyEvalTeachers[$card['id']]) && !isset($legacyUsed[$card['id']])) {
            $completed[$card['card_key']] = true;
            $legacyUsed[$card['id']] = true;
        }
    }
}

/* ================= FETCH EVALUATION HISTORY (all school years, read-only) ================= */
$evalHistory = [];
$histStmt = $conn->prepare("
    SELECT sy.label AS school_year, t.name AS teacher_name, e.rating, e.submitted_at
    FROM evaluations e
    JOIN teachers t ON t.id = e.teacher_id
    LEFT JOIN school_years sy ON sy.id = e.school_year_id
    WHERE e.student_id = ?
    ORDER BY sy.label DESC, t.name ASC
");
$histStmt->bind_param("i", $studentDbId);
$histStmt->execute();
$histResult = $histStmt->get_result();
while ($row = $histResult->fetch_assoc()) {
    $evalHistory[] = $row;
}
$histStmt->close();

/* ================= QUESTIONS =================
   Pulled from evaluation_questions (managed by the admin's "Questioner"
   page) so newly added/edited questions show up here automatically.
   Falls back to the original 10 questions if the table doesn't exist
   yet or is empty, so evaluation never breaks. */
$questions = [];
$qResCheck = $conn->query("SHOW TABLES LIKE 'evaluation_questions'");
if ($qResCheck && $qResCheck->num_rows > 0) {
    $qRes = $conn->query("SELECT id, question_text FROM evaluation_questions WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    if ($qRes) {
        while ($row = $qRes->fetch_assoc()) {
            $questions[] = $row;
        }
    }
}
if (empty($questions)) {
    $fallbackQuestionTexts = [
        "The teacher explains lessons clearly and in an understandable manner.",
        "The teacher comes to class on time and prepared.",
        "The teacher treats all students fairly and with respect.",
        "The teacher encourages student participation during class.",
        "The teacher gives helpful and timely feedback on assignments.",
        "The teacher uses teaching methods that help students learn effectively.",
        "The teacher is approachable and willing to help students with concerns.",
        "The teacher maintains an orderly and positive classroom environment.",
        "The teacher covers the required subject matter thoroughly.",
        "Overall, I am satisfied with this teacher's performance.",
    ];
    foreach ($fallbackQuestionTexts as $i => $qText) {
        $questions[] = ['id' => $i + 1, 'question_text' => $qText];
    }
}
$totalQuestions = count($questions);

/* ================= PER-QUESTION ANSWERS TABLE (additive) =================
   Stores each student's 1-4 answer for every question, linked to the
   evaluation row, so Analytics can tell which areas a teacher is weakest
   in. Does not touch the existing evaluations table or its logic. */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS evaluation_answers (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        evaluation_id INT NOT NULL,
        question_id   INT NOT NULL,
        question_text TEXT NULL,
        answer        TINYINT NOT NULL,
        UNIQUE KEY uq_eval_question (evaluation_id, question_id),
        FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
} catch (Throwable $e) {
    // Never block the dashboard if this can't be created.
}

/* ================= SUBMIT EVALUATION ================= */
$evalMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_id'])) {

    $teacherId        = intval($_POST['teacher_id']);
    $teacherSubjectId = intval($_POST['teacher_subject_id'] ?? 0);
    $comments         = trim($_POST['comments'] ?? '');

    // one evaluation per teacher + subject card
    $cardKey = $teacherSubjectId > 0 ? 's' . $teacherSubjectId : 't' . $teacherId;

    // Collect per-question ratings and compute average
    $questionRatings = [];
    foreach ($questions as $q) {
        $qVal = intval($_POST["q{$q['id']}"] ?? 0);
        $questionRatings[] = $qVal;
    }

    $validRatings = array_filter($questionRatings, fn($v) => $v >= 1 && $v <= 4);

    if ($activeSchoolYearId && !isset($completed[$cardKey]) && $totalQuestions > 0 && count($validRatings) === $totalQuestions) {

        // Store average rating (scaled: avg of 1-4 mapped to 1-5 range, or store raw avg)
        $avgRating = round(array_sum($questionRatings) / $totalQuestions, 2);
        // Store as 1-5 scale: (avg-1)/(4-1)*4+1
        $ratingStored = round((($avgRating - 1) / 3) * 4 + 1);
        $ratingStored = max(1, min(5, $ratingStored));

        $subjectParam = $teacherSubjectId > 0 ? $teacherSubjectId : null;
        $ins = $conn->prepare("
            INSERT INTO evaluations (student_id, teacher_id, school_year_id, teacher_subject_id, rating, comments, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->bind_param("iiiiis", $studentDbId, $teacherId, $activeSchoolYearId, $subjectParam, $ratingStored, $comments);
        $ins->execute();
        $newEvaluationId = $ins->insert_id;
        $ins->close();

        // Save each question's answer too (used by Analytics suggestions).
        // Wrapped so a failure here can never stop the evaluation itself
        // from being saved.
        try {
            $ansStmt = $conn->prepare("INSERT IGNORE INTO evaluation_answers (evaluation_id, question_id, question_text, answer) VALUES (?, ?, ?, ?)");
            foreach ($questions as $q) {
                $qId   = (int)$q['id'];
                $qText = $q['question_text'];
                $qAns  = intval($_POST["q{$q['id']}"] ?? 0);
                $ansStmt->bind_param("iisi", $newEvaluationId, $qId, $qText, $qAns);
                $ansStmt->execute();
            }
            $ansStmt->close();
        } catch (Throwable $e) {
            // ignore
        }

        $completed[$cardKey] = true;
        $evalMessage = "success";
    } elseif (!$activeSchoolYearId) {
        $evalMessage = "closed";
    }
}

/* ================= DEADLINE ================= */
$deadline = "May 30, 2026";

/* ================= COUNTS ================= */
$totalTeachers  = count($teachers);
$completedCount = count($completed);
$pendingCount   = $totalTeachers - $completedCount;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Teacher Evaluation</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f5f7fb;
    min-height: 100vh;
}

/* ── HEADER ── */
.top-header {
    background: #111827;
    color: white;
    padding: 18px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 3px solid #1a7f74;
}

.top-header .brand {
    display: flex;
    align-items: center;
    gap: 14px;
}

.top-header .brand-logo {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: #fff;
    padding: 3px;
    flex-shrink: 0;
    object-fit: contain;
}

.top-header .brand-school {
    font-size: 10px;
    font-weight: bold;
    color: #d4a843;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 2px;
}

.top-header h1 {
    font-size: 19px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.top-header .user-info {
    font-size: 13px;
    color: #9ca3af;
    display: flex;
    align-items: center;
    gap: 16px;
}

.top-header a {
    color: #f87171;
    text-decoration: none;
    font-size: 13px;
}
.top-header a:hover { opacity: 0.8; }

/* ── CONTENT ── */
.content {
    max-width: 780px;
    margin: 36px auto;
    padding: 0 20px;
}

/* ── META ROW ── */
.meta-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.meta-row .section-info {
    font-size: 14px;
    color: #555;
}

.meta-row .section-info strong {
    color: #1e2a3a;
}

.deadline {
    color: #888;
    font-size: 14px;
}

/* ── PROGRESS BAR ── */
.progress-wrap {
    background: white;
    border-radius: 10px;
    padding: 16px 22px;
    margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 16px;
}

.progress-bar-bg {
    flex: 1;
    background: #e9ecef;
    border-radius: 20px;
    height: 10px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: #27ae60;
    border-radius: 20px;
    transition: width 0.4s ease;
    width: <?= $totalTeachers > 0 ? round(($completedCount / $totalTeachers) * 100) : 0 ?>%;
}

.progress-label {
    font-size: 13px;
    color: #555;
    white-space: nowrap;
}

.progress-label span {
    font-weight: 700;
    color: #27ae60;
}

/* ── TEACHER CARD ── */
.teacher-card {
    background: #fff;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    transition: box-shadow 0.2s;
}

.teacher-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.11);
}

.teacher-info h3 {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 4px;
}

.teacher-info .subject {
    font-size: 14px;
    color: #888;
}

.teacher-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}

/* ── BADGES ── */
.badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.badge-pending   { background: #f5a623; color: white; }
.badge-completed { background: #27ae60; color: white; }

/* ── BUTTONS ── */
.btn-evaluate {
    background: #1a7f74;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-evaluate:hover { background: #15665c; }

.btn-done {
    background: #bdc3c7;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: 600;
    cursor: default;
}

/* ── MODAL OVERLAY ── */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.55);
    z-index: 100;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }

.modal {
    background: white;
    border-radius: 14px;
    padding: 32px 36px;
    width: 620px;
    max-width: 96vw;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 8px 40px rgba(0,0,0,0.22);
}

.modal-header {
    margin-bottom: 6px;
}

.modal-header h3 {
    font-size: 20px;
    color: #1a1a2e;
    font-weight: 700;
}

.modal-subject {
    font-size: 13px;
    color: #888;
    margin-bottom: 20px;
}

/* ── RATING SCALE LEGEND ── */
.scale-legend {
    display: flex;
    gap: 10px;
    margin-bottom: 22px;
    flex-wrap: wrap;
}

.scale-legend .scale-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #555;
}

.scale-dot {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: white;
}

.dot-1 { background: #e74c3c; }
.dot-2 { background: #e67e22; }
.dot-3 { background: #3498db; }
.dot-4 { background: #27ae60; }

/* ── QUESTIONS TABLE ── */
.questions-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 22px;
    font-size: 13.5px;
}

.questions-table thead tr {
    background: #f4f6f9;
}

.questions-table thead th {
    padding: 10px 8px;
    font-size: 12px;
    font-weight: 700;
    color: #444;
    text-align: center;
    border-bottom: 2px solid #e0e3e8;
}

.questions-table thead th:first-child {
    text-align: left;
    padding-left: 12px;
    width: 55%;
}

.questions-table tbody tr {
    border-bottom: 1px solid #f0f2f5;
    transition: background 0.15s;
}

.questions-table tbody tr:hover {
    background: #fafbfd;
}

.questions-table tbody td {
    padding: 11px 8px;
    vertical-align: middle;
    text-align: center;
    color: #333;
}

.questions-table tbody td:first-child {
    text-align: left;
    padding-left: 12px;
    line-height: 1.45;
    color: #222;
}

.q-num {
    display: inline-block;
    width: 22px;
    height: 22px;
    background: #111827;
    color: white;
    border-radius: 50%;
    text-align: center;
    line-height: 22px;
    font-size: 11px;
    font-weight: 700;
    margin-right: 8px;
    flex-shrink: 0;
}

/* ── RADIO RATING BUTTONS ── */
.rating-radio { display: none; }

.rating-btn {
    display: inline-block;
    width: 34px;
    height: 34px;
    line-height: 34px;
    border-radius: 50%;
    border: 2px solid #ddd;
    font-size: 13px;
    font-weight: 700;
    color: #999;
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
    user-select: none;
}

.rating-btn:hover { border-color: #1a7f74; color: #1a7f74; }

/* When the corresponding radio is checked, style the label */
.rating-radio:checked + .rating-btn {
    border-color: transparent;
    color: white;
}

/* Per-value colors when selected */
input.rating-radio[value="1"]:checked + .rating-btn { background: #e74c3c; border-color: #e74c3c; }
input.rating-radio[value="2"]:checked + .rating-btn { background: #e67e22; border-color: #e67e22; }
input.rating-radio[value="3"]:checked + .rating-btn { background: #3498db; border-color: #3498db; }
input.rating-radio[value="4"]:checked + .rating-btn { background: #27ae60; border-color: #27ae60; }

/* ── VALIDATION ROW HIGHLIGHT ── */
.questions-table tbody tr.unanswered {
    background: #fff8f8;
}
.questions-table tbody tr.unanswered td:first-child {
    color: #c0392b;
}

/* ── COMMENTS ── */
.field-label {
    font-size: 12px;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: block;
    margin-bottom: 7px;
}

textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #ddd;
    border-radius: 7px;
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    min-height: 90px;
    margin-bottom: 20px;
    transition: border-color 0.2s;
}
textarea:focus { outline: none; border-color: #1a7f74; }

/* ── MODAL ACTIONS ── */
.modal-actions {
    display: flex;
    gap: 10px;
}

.btn-submit {
    flex: 1;
    background: #1a7f74;
    color: white;
    border: none;
    border-radius: 7px;
    padding: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-submit:hover { background: #15665c; }

.btn-cancel {
    background: #f0f2f5;
    color: #555;
    border: none;
    border-radius: 7px;
    padding: 12px 22px;
    font-size: 15px;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-cancel:hover { background: #e0e3e8; }

/* ── VALIDATION MESSAGE ── */
.val-msg {
    display: none;
    color: #e74c3c;
    font-size: 13px;
    margin-bottom: 12px;
    font-weight: 600;
}

/* ── TOAST ── */
.toast {
    display: none;
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: #27ae60;
    color: white;
    padding: 12px 22px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    z-index: 200;
}

.no-teacher {
    text-align: center;
    color: #aaa;
    padding: 60px 20px;
    font-size: 15px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}

.all-done {
    text-align: center;
    background: #eafaf1;
    border: 1.5px solid #27ae60;
    color: #1e8449;
    border-radius: 10px;
    padding: 16px;
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 20px;
}

/* scrollbar for modal */
.modal::-webkit-scrollbar { width: 6px; }
.modal::-webkit-scrollbar-track { background: #f0f2f5; border-radius: 10px; }
.modal::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
</style>
</head>
<body>

<div class="top-header">
    <div class="brand">
        <img src="logo.png" alt="NEUST Logo" class="brand-logo">
        <div>
            <div class="brand-school">Nueva Ecija University of Science and Technology</div>
            <h1>Student Teacher Evaluation</h1>
        </div>
    </div>
    <div class="user-info">
        <span>Hello, <?= htmlspecialchars($studentName) ?></span>
        <a href="login.php">Logout</a>
    </div>
</div>

<div class="content">

    <div class="meta-row">
        <div class="section-info">
            Year <strong><?= htmlspecialchars($studentYear) ?></strong>
            <?php if ($studentSection !== null && $studentSection !== ''): ?>
                — Section <strong><?= htmlspecialchars($studentSection) ?></strong>
            <?php endif; ?>
            <?php if ($activeSchoolYearLabel): ?>
                — School Year <strong><?= htmlspecialchars($activeSchoolYearLabel) ?></strong>
            <?php endif; ?>
        </div>
        <div class="deadline">Deadline: <?= htmlspecialchars($deadline) ?></div>
    </div>

    <!-- Evaluation period closed -->
    <?php if (!$activeSchoolYearId): ?>
    <div class="no-teacher" style="margin-bottom:22px;">
        📌 Evaluation is currently closed. Please check back once the next school year opens.
    </div>
    <?php endif; ?>

    <!-- Progress bar -->
    <?php if ($totalTeachers > 0 && $activeSchoolYearId): ?>
    <div class="progress-wrap">
        <div class="progress-bar-bg">
            <div class="progress-bar-fill"></div>
        </div>
        <div class="progress-label">
            <span><?= $completedCount ?></span> / <?= $totalTeachers ?> evaluated
        </div>
    </div>
    <?php endif; ?>

    <!-- All done banner -->
    <?php if ($activeSchoolYearId && $totalTeachers > 0 && $completedCount === $totalTeachers): ?>
    <div class="all-done">🎓 You have evaluated all your teachers. Thank you!</div>
    <?php endif; ?>

    <!-- No teachers -->
    <?php if (empty($teachers)): ?>
        <div class="no-teacher">No teachers have been assigned to you yet. Please check back later.
            <?php if (!empty($otherSyHint)): ?><br><small><?= htmlspecialchars($otherSyHint) ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Teacher cards -->
    <?php foreach ($teachers as $teacher): ?>
    <?php $isDone = isset($completed[$teacher['card_key']]); ?>

    <div class="teacher-card">
        <div class="teacher-info">
            <h3><?= htmlspecialchars(guessTeacherTitle($teacher['name']) . ' ' . $teacher['name']) ?></h3>
            <div class="subject"><?= htmlspecialchars($teacher['subject'] ?? '') ?></div>
        </div>
        <div class="teacher-actions">
            <?php if ($isDone): ?>
                <span class="badge badge-completed">Completed</span>
                <button class="btn-done" disabled>Done</button>
            <?php elseif (!$activeSchoolYearId): ?>
                <span class="badge badge-pending">Pending</span>
                <button class="btn-evaluate" disabled title="Evaluation is currently closed">Closed</button>
            <?php else: ?>
                <span class="badge badge-pending">Pending</span>
                <button class="btn-evaluate"
                        onclick="openModal(
                            <?= $teacher['id'] ?>,
                            '<?= addslashes(htmlspecialchars(guessTeacherTitle($teacher['name']) . ' ' . $teacher['name'])) ?>',
                            '<?= addslashes(htmlspecialchars($teacher['subject'] ?? '')) ?>',
                            <?= (int)$teacher['teacher_subject_id'] ?>
                        )">
                    Evaluate
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php endforeach; ?>

    <!-- ── EVALUATION HISTORY (read-only, all school years) ── -->
    <?php if (!empty($evalHistory)): ?>
    <div style="margin-top:34px;">
        <h3 style="font-size:16px;color:#1e2a3a;margin-bottom:12px;">📜 Your Evaluation History</h3>
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.07);overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th style="text-align:left;padding:10px 16px;color:#6b7280;font-size:11px;text-transform:uppercase;">School Year</th>
                        <th style="text-align:left;padding:10px 16px;color:#6b7280;font-size:11px;text-transform:uppercase;">Teacher</th>
                        <th style="text-align:left;padding:10px 16px;color:#6b7280;font-size:11px;text-transform:uppercase;">Rating</th>
                        <th style="text-align:left;padding:10px 16px;color:#6b7280;font-size:11px;text-transform:uppercase;">Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($evalHistory as $h): ?>
                    <tr style="border-top:1px solid #f3f4f6;">
                        <td style="padding:10px 16px;"><?= htmlspecialchars($h['school_year'] ?? '—') ?></td>
                        <td style="padding:10px 16px;font-weight:600;"><?= htmlspecialchars(guessTeacherTitle($h['teacher_name']) . ' ' . $h['teacher_name']) ?></td>
                        <td style="padding:10px 16px;"><?= str_repeat('★', (int)$h['rating']) . str_repeat('☆', 5 - (int)$h['rating']) ?></td>
                        <td style="padding:10px 16px;color:#9ca3af;"><?= htmlspecialchars(date('M d, Y', strtotime($h['submitted_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── EVALUATION MODAL ── -->
<div class="modal-overlay" id="modalOverlay">
<div class="modal">

    <div class="modal-header">
        <h3 id="modalTeacherName"></h3>
    </div>
    <div class="modal-subject" id="modalTeacherSubject"></div>

    <!-- Rating scale legend -->
    <div class="scale-legend">
        <div class="scale-item"><div class="scale-dot dot-1">1</div> Poor</div>
        <div class="scale-item"><div class="scale-dot dot-2">2</div> Fair</div>
        <div class="scale-item"><div class="scale-dot dot-3">3</div> Good</div>
        <div class="scale-item"><div class="scale-dot dot-4">4</div> Excellent</div>
    </div>

    <form method="POST" id="evalForm">
        <input type="hidden" name="teacher_id" id="modalTeacherId">
        <input type="hidden" name="teacher_subject_id" id="modalTeacherSubjectId" value="0">

        <!-- Questions table -->
        <table class="questions-table">
            <thead>
                <tr>
                    <th>Criteria</th>
                    <th>1<br><small>Poor</small></th>
                    <th>2<br><small>Fair</small></th>
                    <th>3<br><small>Good</small></th>
                    <th>4<br><small>Excellent</small></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $qPos => $q): $qDisplayNum = $qPos + 1; ?>
                <tr id="row_q<?= $q['id'] ?>">
                    <td>
                        <span class="q-num"><?= $qDisplayNum ?></span><?= htmlspecialchars($q['question_text']) ?>
                    </td>
                    <?php for ($v = 1; $v <= 4; $v++): ?>
                    <td>
                        <input class="rating-radio" type="radio"
                               name="q<?= $q['id'] ?>"
                               id="q<?= $q['id'] ?>_v<?= $v ?>"
                               value="<?= $v ?>">
                        <label class="rating-btn" for="q<?= $q['id'] ?>_v<?= $v ?>"><?= $v ?></label>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Validation message -->
        <div class="val-msg" id="valMsg">⚠️ Please answer all <?= $totalQuestions ?> questions before submitting.</div>

        <!-- Comments -->
        <label class="field-label" for="comments">
            Comments <span style="font-weight:400;text-transform:none;">(optional)</span>
        </label>
        <textarea id="comments" name="comments"
                  placeholder="Share your thoughts about this teacher..."></textarea>

        <div class="modal-actions">
            <button type="button" class="btn-submit" onclick="submitEval()">Submit Evaluation</button>
            <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        </div>
    </form>

</div>
</div>

<!-- Toast notification -->
<div class="toast" id="toast">✅ Evaluation submitted!</div>

<script>
function openModal(id, name, subject, teacherSubjectId) {
    document.getElementById('modalTeacherId').value            = id;
    document.getElementById('modalTeacherSubjectId').value     = teacherSubjectId || 0;
    document.getElementById('modalTeacherName').textContent    = name;
    document.getElementById('modalTeacherSubject').textContent = subject;
    // Clear all radios
    document.querySelectorAll('.rating-radio').forEach(r => r.checked = false);
    // Clear row highlights
    document.querySelectorAll('.questions-table tbody tr').forEach(r => r.classList.remove('unanswered'));
    document.getElementById('comments').value = '';
    document.getElementById('valMsg').style.display = 'none';
    document.getElementById('modalOverlay').classList.add('active');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
}

var evalQuestionIds = <?= json_encode(array_column($questions, 'id')) ?>;

function submitEval() {
    // Validate all questions answered
    let allAnswered = true;
    evalQuestionIds.forEach(function(qid) {
        const row = document.getElementById('row_q' + qid);
        const checked = document.querySelector('input[name="q' + qid + '"]:checked');
        if (!checked) {
            row.classList.add('unanswered');
            allAnswered = false;
        } else {
            row.classList.remove('unanswered');
        }
    });

    if (!allAnswered) {
        const msg = document.getElementById('valMsg');
        msg.style.display = 'block';
        // Scroll to first unanswered
        const firstUnanswered = document.querySelector('.unanswered');
        if (firstUnanswered) firstUnanswered.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    document.getElementById('evalForm').submit();
}

// Close modal when clicking outside
document.getElementById('modalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

<?php if ($evalMessage === 'success'): ?>
window.addEventListener('load', function() {
    const toast = document.getElementById('toast');
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 3000);
});
<?php elseif ($evalMessage === 'closed'): ?>
window.addEventListener('load', function() {
    const toast = document.getElementById('toast');
    toast.textContent = '📌 Evaluation is currently closed for this school year.';
    toast.style.background = '#ef4444';
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 3500);
});
<?php endif; ?>
</script>

</body>
</html>