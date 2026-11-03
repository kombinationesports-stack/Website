<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';

$conn = new mysqli("localhost", "root", "", "school_system");

// make sure the teacher title column exists (normally created by admin_backend.php)
$titleColChk = $conn->query("SHOW COLUMNS FROM teachers LIKE 'title'");
if ($titleColChk && $titleColChk->num_rows == 0) { $conn->query("ALTER TABLE teachers ADD COLUMN title VARCHAR(10) NULL"); }

/* ================= HELPER: get a teacher's last name for display =================
   Same as the one in admin_backend.php — only affects display here; the
   underlying stored name (used for the same-name merge in "All School
   Years" above) is untouched. */
function getTeacherLastName($fullName) {
    $fullName = trim($fullName ?? '');
    if ($fullName === '') return '';
    $parts = preg_split('/\s+/', $fullName);
    return end($parts);
}

/* ================= SCHOOL YEAR FILTER =================
   Defaults to "all" (identical to the report's original behavior) unless
   the admin explicitly picks a school year from the dropdown below. */
$schoolYears = [];
$syListRes = $conn->query("SELECT id, label, is_active FROM school_years ORDER BY label DESC");
if ($syListRes) {
    while ($syRow = $syListRes->fetch_assoc()) {
        $schoolYears[] = $syRow;
    }
}

$defaultSyId = 'all';
foreach ($schoolYears as $sy) {
    if (!empty($sy['is_active'])) { $defaultSyId = (string)$sy['id']; break; }
}
$selectedSyId = isset($_GET['sy']) ? $_GET['sy'] : $defaultSyId;
if ($selectedSyId !== 'all' && !ctype_digit((string)$selectedSyId)) {
    $selectedSyId = 'all';
}

$selectedSyLabel = 'All School Years';
if ($selectedSyId !== 'all') {
    foreach ($schoolYears as $sy) {
        if ((string)$sy['id'] === (string)$selectedSyId) {
            $selectedSyLabel = $sy['label'];
            break;
        }
    }
}

// Safe to inline directly: guaranteed 'all' or a validated integer above.
$syJoinFilter  = ($selectedSyId === 'all') ? "" : "AND e.school_year_id = " . (int)$selectedSyId;
$syWhereFilter = ($selectedSyId === 'all') ? "" : "AND school_year_id = " . (int)$selectedSyId;

/* ================= FETCH ALL TEACHERS WITH EVALUATION STATS =================
   Teachers can now have a DIFFERENT row per school year (e.g. Navarro –
   English in 2025-2026, Navarro – Math in 2026-2027 — two separate teacher
   records under the hood, one created per school year). Each row is always
   shown on its own — subjects from different years are never combined,
   even in "All School Years" view. Selecting a specific school year shows
   ONLY the row(s) that were actually created/assigned for that exact year
   — a teacher not explicitly tied to the selected year is never shown
   there, even if it has no year tag at all. */
$teacherSyFilter = "";
if ($selectedSyId !== 'all') {
    $teacherSyFilter = "AND t.school_year_id = " . (int)$selectedSyId;
}

/* ================= HIDE ORPHANED TEACHER ROWS WITH NO EVALUATIONS =================
   A teacher row can end up with zero evaluations and no current section
   assignment — e.g. a duplicate created while testing/typo-correcting a
   name, or a leftover from before a section was re-saved with a
   different spelling. Such a row has nothing real to report, but was
   still showing up as its own "0.00 / No evaluations yet" card and
   getting its (leftover) subject merged into that teacher's name-group
   in Analytics. A row is now only included if it has at least one
   evaluation OR is still actually assigned to a section right now — so
   genuinely retired teachers (section deleted, but their past
   evaluations should still count) are kept, while empty ghost rows are
   hidden. */
$sectionExistsSyFilter = "";
if ($selectedSyId !== 'all') {
    $sectionExistsSyFilter = "AND st.school_year_id = " . (int)$selectedSyId;
}

$analyticsRes = $conn->query("
    SELECT
        t.id,
        t.name,
        t.title,
        t.subject,
        COUNT(e.id)                                        AS total_evaluations,
        COALESCE(AVG(e.rating), 0)                        AS avg_rating,
        SUM(CASE WHEN e.rating = 5 THEN 1 ELSE 0 END)    AS count_5,
        SUM(CASE WHEN e.rating = 4 THEN 1 ELSE 0 END)    AS count_4,
        SUM(CASE WHEN e.rating = 3 THEN 1 ELSE 0 END)    AS count_3,
        SUM(CASE WHEN e.rating = 2 THEN 1 ELSE 0 END)    AS count_2,
        SUM(CASE WHEN e.rating = 1 THEN 1 ELSE 0 END)    AS count_1
    FROM teachers t
    LEFT JOIN evaluations e ON e.teacher_id = t.id $syJoinFilter
    WHERE 1=1 $teacherSyFilter
    GROUP BY t.id, t.name, t.title, t.subject
    ORDER BY avg_rating DESC
");

$analyticsRows = [];
while ($row = $analyticsRes->fetch_assoc()) {
    $analyticsRows[] = $row;
}

/* ── MERGE ROWS THAT BELONG TO THE SAME TEACHER (by name) ──
   Originally this only ran for "All School Years" (to combine e.g.
   Navarro – English (2025-2026) with Navarro – Math (2026-2027) into one
   "Navarro" row). It now ALSO runs when a SPECIFIC school year is
   selected, because a teacher can end up with more than one record
   within the very same active school year (e.g. after editing that
   teacher's subject from one section, which — to protect other sections
   from being affected — creates a separate teacher record just for that
   section). Those records are still the same person for that year, so
   their evaluations (rating + count) are combined here too, exactly the
   same way the "All School Years" merge already worked. This does not
   change how the fork/edit-per-section logic itself works — it only
   changes how Analytics displays the result. */
$merged = [];
foreach ($analyticsRows as $row) {
    $key = strtolower(trim($row['name']));
    if (!isset($merged[$key])) {
        $merged[$key] = [
            'name'     => $row['name'],
            'title'    => '',
            'subjects' => [],
            'count_5' => 0, 'count_4' => 0, 'count_3' => 0, 'count_2' => 0, 'count_1' => 0,
        ];
    }
    if ($merged[$key]['title'] === '' && !empty($row['title'])) { $merged[$key]['title'] = trim($row['title']); }
    foreach (array_filter(array_map('trim', explode(',', $row['subject'] ?? ''))) as $subj) {
        if ($subj !== '' && !in_array($subj, $merged[$key]['subjects'], true)) {
            $merged[$key]['subjects'][] = $subj;
        }
    }
    $merged[$key]['count_5'] += (int)$row['count_5'];
    $merged[$key]['count_4'] += (int)$row['count_4'];
    $merged[$key]['count_3'] += (int)$row['count_3'];
    $merged[$key]['count_2'] += (int)$row['count_2'];
    $merged[$key]['count_1'] += (int)$row['count_1'];
}

$analyticsRows = [];
foreach ($merged as $m) {
    $total = $m['count_5'] + $m['count_4'] + $m['count_3'] + $m['count_2'] + $m['count_1'];
    $wSum  = ($m['count_5'] * 5) + ($m['count_4'] * 4) + ($m['count_3'] * 3) + ($m['count_2'] * 2) + ($m['count_1'] * 1);

    $analyticsRows[] = [
        'name'              => $m['name'],
        'title'             => $m['title'],
        'subject'           => implode(', ', $m['subjects']),
        'total_evaluations' => $total,
        'count_5' => $m['count_5'], 'count_4' => $m['count_4'], 'count_3' => $m['count_3'],
        'count_2' => $m['count_2'], 'count_1' => $m['count_1'],
        'avg_rating' => $total > 0 ? $wSum / $total : 0,
    ];
}

$analyticsData = $analyticsRows;
usort($analyticsData, fn($a, $b) => $b['avg_rating'] <=> $a['avg_rating']);

/* ================= OVERALL STATS ================= */
$overallRes       = $conn->query("SELECT COUNT(*) AS total_eval, AVG(rating) AS overall_avg FROM evaluations WHERE 1=1 $syWhereFilter");
$overallRow       = $overallRes->fetch_assoc();
$totalEvaluations = (int)($overallRow['total_eval']   ?? 0);
$overallAvg       = round((float)($overallRow['overall_avg'] ?? 0), 2);

$totalStudents  = (int)($conn->query("SELECT COUNT(*) AS c FROM students WHERE 1=1 $syWhereFilter")->fetch_assoc()['c']                ?? 0);
$studentsEvaled = (int)($conn->query("SELECT COUNT(DISTINCT student_id) AS c FROM evaluations WHERE 1=1 $syWhereFilter")->fetch_assoc()['c'] ?? 0);
$completionPct  = $totalStudents > 0 ? round(($studentsEvaled / $totalStudents) * 100) : 0;

$totalTeachersCount = count($analyticsData);

/* ================= IMPROVEMENT SUGGESTIONS (click the stars) =================
   Built from the per-question answers students submit (evaluation_answers).
   For each teacher it finds the lowest-scoring questions and attaches a
   suggestion. Purely additive: nothing above this point is changed. */
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
    // ignore — suggestions simply fall back to the overall rating
}

function getImprovementSuggestion($questionText) {
    $q = strtolower($questionText ?? '');
    $map = [
        ['explain',       'Explain lessons step by step with simple, real-life examples, and check understanding with quick questions or a short recap at the end of each lesson.'],
        ['on time',       'Arrive on time and have the lesson plan and materials ready beforehand. Share the day\'s objectives at the start of class.'],
        ['prepared',      'Arrive on time and have the lesson plan and materials ready beforehand. Share the day\'s objectives at the start of class.'],
        ['fair',          'Apply the same standards to every student. Use clear grading rubrics and make sure all students are treated respectfully and given equal chances.'],
        ['participation', 'Add group activities, open-ended questions, and discussions. Call on different students and give credit for participation.'],
        ['feedback',      'Return graded work sooner and add specific comments on what was done well and what to improve.'],
        ['teaching method','Mix lectures with discussions, demonstrations, case studies, or hands-on tasks, and match the method to the topic.'],
        ['approachable',  'Set regular consultation hours, invite questions in and out of class, and respond to student concerns patiently.'],
        ['orderly',       'Set clear classroom rules at the start and enforce them consistently. Use positive reinforcement to keep a respectful atmosphere.'],
        ['environment',   'Set clear classroom rules at the start and enforce them consistently. Use positive reinforcement to keep a respectful atmosphere.'],
        ['subject matter','Follow the syllabus closely, pace topics so nothing is rushed or skipped, and summarize what was covered after each topic.'],
        ['overall',       'Focus on the lowest-rated areas listed here, and gather short mid-semester feedback from students to see if things are improving.'],
        ['satisfied',     'Focus on the lowest-rated areas listed here, and gather short mid-semester feedback from students to see if things are improving.'],
    ];
    foreach ($map as $pair) {
        if (strpos($q, $pair[0]) !== false) return $pair[1];
    }
    return 'Review this area with the department head and ask students for specific feedback on what they would like to see improved.';
}

// Per-question averages, grouped by teacher NAME (same grouping used by the
// merge above, so a teacher with several records still gets one result).
$tipsRaw = [];
try {
    $tipsRes = $conn->query("
        SELECT LOWER(TRIM(t.name))        AS tkey,
               ea.question_id,
               MAX(ea.question_text)      AS question_text,
               AVG(ea.answer)             AS avg_ans,
               COUNT(*)                   AS n,
               COUNT(DISTINCT ea.evaluation_id) AS evals
        FROM evaluation_answers ea
        JOIN evaluations e ON e.id = ea.evaluation_id
        JOIN teachers t    ON t.id = e.teacher_id
        WHERE 1=1 $syJoinFilter
        GROUP BY tkey, ea.question_id
    ");
    if ($tipsRes) {
        while ($r = $tipsRes->fetch_assoc()) {
            $tipsRaw[$r['tkey']][] = $r;
        }
    }
} catch (Throwable $e) {
    $tipsRaw = [];
}

$teacherTips = [];
foreach ($analyticsData as $t) {
    $key   = strtolower(trim($t['name']));
    $total = (int)$t['total_evaluations'];
    $entry = [
        'name'      => trim(($t['title'] ?? '') . ' ' . getTeacherLastName($t['name'])),
        'total'     => $total,
        'avg'       => round((float)$t['avg_rating'], 2),
        'detailed'  => 0,
        'items'     => [],
    ];

    if (!empty($tipsRaw[$key])) {
        $rows = $tipsRaw[$key];
        usort($rows, fn($a, $b) => (float)$a['avg_ans'] <=> (float)$b['avg_ans']);
        $entry['detailed'] = max(array_map(fn($r) => (int)$r['evals'], $rows));
        foreach ($rows as $r) {
            $avgAns = (float)$r['avg_ans'];
            // Skip questions that are rated well — only low-rated ones
            // (average below 3.0 out of 4) need an improvement suggestion.
            if ($avgAns >= 3.0) continue;
            $entry['items'][] = [
                'question'   => $r['question_text'],
                'avg'        => round($avgAns, 2),
                'pct'        => (int)round(($avgAns / 4) * 100),
                'suggestion' => getImprovementSuggestion($r['question_text']),
            ];
        }
    }
    $teacherTips[$key] = $entry;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Analytics — Admin Panel</title>
<style>
/* ── BASE — identical to admin.php ── */
* { box-sizing: border-box; }
body { margin:0; font-family:Arial; background:#f5f7fb; }

.sidebar {
    width: 230px;
    height: 100vh;
    background: #1f2937;
    position: fixed;
    top: 0; left: 0;
    color: white;
    padding-top: 20px;
}

.sidebar h2 {
    padding: 0 20px;
    font-size: 18px;
    margin-bottom: 10px;
}

/* ── SIDEBAR BRAND (logo + school name) — matches admin.php ── */
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 20px 16px;
    margin-bottom: 12px;
    border-bottom: 1px solid #374151;
}

.sidebar-brand .brand-logo {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #fff;
    padding: 3px;
    flex-shrink: 0;
    object-fit: contain;
}

.sidebar-brand .brand-text { line-height: 1.3; min-width: 0; }

.sidebar-brand .brand-school {
    font-size: 9.5px;
    font-weight: bold;
    color: #d4a843;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: normal;
}

.sidebar-brand h2 {
    padding: 0;
    margin: 2px 0 0;
    font-size: 15px;
}

.sidebar a {
    display: block;
    padding: 10px 20px;
    color: #9ca3af;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.15s, color 0.15s;
}

.sidebar a:hover  { background: #374151; color: white; }
.sidebar a.active { background: #374151; color: white; }
.sidebar a.logout { color: #f87171; }
.sidebar a.logout { position: absolute; bottom: 20px; width: 100%; }

.header {
    margin-left: 230px;
    background: #111827;
    padding: 20px;
    color: white;
    font-size: 18px;
    font-weight: bold;
}

.main {
    margin-left: 230px;
    padding: 30px;
}

/* ── STAT SUMMARY ROW ── */
.stat-row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 18px 22px;
    flex: 1;
    min-width: 150px;
}

.stat-label {
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 30px;
    font-weight: bold;
    color: #111827;
    line-height: 1;
    margin-bottom: 4px;
}

.stat-sub {
    font-size: 12px;
    color: #9ca3af;
}

.stat-bar-bg {
    background: #e5e7eb;
    border-radius: 4px;
    height: 6px;
    overflow: hidden;
    margin-top: 8px;
}

.stat-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: #1a7f74;
}

/* ── CONTROLS ROW ── */
.controls-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.filter-btn {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 13px;
    font-family: Arial;
    cursor: pointer;
    color: #374151;
    transition: all 0.15s;
}

.filter-btn:hover  { border-color: #1a7f74; color: #1a7f74; }
.filter-btn.active { background: #1a7f74; border-color: #1a7f74; color: #fff; }

.view-toggle { display: flex; gap: 8px; }

/* ── DOWNLOAD REPORT BUTTON ── */
.download-wrap { position: relative; }

.btn-download {
    background: #1a7f74;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: bold;
    font-family: Arial;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}

.btn-download:hover { background: #15665c; }

.download-menu {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.14);
    width: 200px;
    padding: 6px;
    z-index: 50;
}

.download-menu.open { display: block; }

.download-menu button {
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    padding: 9px 12px;
    font-size: 13px;
    font-family: Arial;
    color: #374151;
    border-radius: 7px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 9px;
    transition: background 0.12s;
}

.download-menu button:hover { background: #f3f4f6; color: #1a7f74; }

.download-menu .menu-divider {
    height: 1px;
    background: #f3f4f6;
    margin: 5px 4px;
}

/* ── PRINTABLE REPORT HEADER (hidden on screen, shown only when printing) ── */
.print-report-header { display: none; }

/* ── PRINT STYLESHEET ── */
@media print {
    .sidebar, .header, .controls-row, .no-results { display: none !important; }
    .main { margin-left: 0 !important; padding: 10px !important; }
    body { background: #fff !important; }

    #gridView { display: none !important; }
    #tableView { display: block !important; }

    .print-report-header {
        display: flex !important;
        align-items: center;
        gap: 14px;
        border-bottom: 3px solid #1a7f74;
        padding-bottom: 14px;
        margin-bottom: 18px;
    }
    .print-report-header img {
        width: 54px; height: 54px; border-radius: 50%;
    }
    .print-report-header h1 { font-size: 17px; color: #111827; }
    .print-report-header p { font-size: 11px; color: #6b7280; margin-top: 2px; }

    .stat-row { margin-bottom: 20px; }
    .stat-card { border: 1px solid #ddd; box-shadow: none; }

    .analytics-table { font-size: 10.5px; }
    .analytics-table thead tr { background: #f3f4f6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

.view-btn {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 13px;
    font-family: Arial;
    cursor: pointer;
    color: #374151;
    transition: all 0.15s;
}

.view-btn.active,
.view-btn:hover { background: #111827; border-color: #111827; color: #fff; }

/* ── TEACHER SEARCH BAR ── */
.search-wrap {
    position: relative;
    width: 240px;
    flex-shrink: 0;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 13px;
    pointer-events: none;
}

.search-input {
    width: 100%;
    padding: 8px 12px 8px 32px;
    border: 1px solid #ddd;
    border-radius: 20px;
    font-size: 13px;
    font-family: Arial;
    color: #374151;
    background: #fff;
    outline: none;
    transition: border-color 0.15s;
}

.search-input:focus { border-color: #1a7f74; }

.search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #9ca3af;
    font-size: 13px;
    cursor: pointer;
    display: none;
    padding: 2px;
}

.search-clear:hover { color: #374151; }

.no-results {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 50px;
    text-align: center;
    color: #9ca3af;
    font-size: 14px;
    width: 100%;
    display: none;
}

/* ── GRID — same .row as admin.php ── */
.row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 40px;
}

/* ── TEACHER CARD ── */
.teacher-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 20px 18px;
    width: 255px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    transition: box-shadow 0.2s;
    position: relative;
}

.teacher-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.10); }

/* ── RANK BADGE ── */
.rank-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    font-size: 11px;
    font-weight: bold;
    padding: 2px 9px;
    border-radius: 12px;
}

.rank-1 { background: #fef9c3; color: #b45309; border: 1px solid #fde68a; }
.rank-2 { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
.rank-3 { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
.rank-n { background: #f9fafb; color: #9ca3af; border: 1px solid #e5e7eb; }

/* ── DONUT RING ── */
.ring-wrap {
    position: relative;
    width: 120px;
    height: 120px;
}

.ring-wrap svg {
    width: 120px;
    height: 120px;
    transform: rotate(-90deg);
}

.ring-bg   { fill: none; stroke: #e5e7eb; stroke-width: 10; }
.ring-fill {
    fill: none;
    stroke-width: 10;
    stroke-linecap: round;
    stroke-dasharray: 339.29;
    stroke-dashoffset: 339.29;
    transition: stroke-dashoffset 1.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.ring-center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.ring-pct   { font-size: 22px; font-weight: bold; color: #111827; line-height: 1; }
.ring-label { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }

/* ── TEACHER INFO ── */
.t-name    { font-size: 15px; font-weight: bold; color: #111827; text-align: center; }
.t-subject { font-size: 12px; color: #6b7280; text-align: center; margin-top: 2px; }

/* ── STARS ── */
.stars { display: flex; gap: 2px; justify-content: center; margin-top: 6px; }
.star  { font-size: 14px; color: #d4a843; }
.star.empty { color: #d1d5db; }
.avg-lbl { font-size: 12px; color: #6b7280; margin-left: 4px; }

/* ── EVAL CHIP ── */
.eval-chip {
    font-size: 11px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 2px 10px;
    color: #6b7280;
    margin-top: 4px;
    display: inline-block;
}

/* ── BREAKDOWN BARS ── */
.breakdown { width: 100%; display: flex; flex-direction: column; gap: 5px; }

.bd-row { display: flex; align-items: center; gap: 7px; }

.bd-label { width: 50px; color: #6b7280; font-size: 11px; flex-shrink: 0; }

.bd-track {
    flex: 1;
    background: #f3f4f6;
    border-radius: 4px;
    height: 6px;
    overflow: hidden;
}

.bd-fill {
    height: 100%;
    border-radius: 4px;
    width: 0;
    transition: width 1.1s cubic-bezier(0.16, 1, 0.3, 1);
}

.bd-count { width: 20px; text-align: right; color: #9ca3af; font-size: 11px; flex-shrink: 0; }

/* ── NO DATA ── */
.no-data {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 50px;
    text-align: center;
    color: #9ca3af;
    font-size: 14px;
    width: 100%;
}

/* ── TABLE VIEW ── */
.analytics-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #ddd;
    font-size: 13px;
    margin-bottom: 40px;
}

.analytics-table thead tr { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.analytics-table thead th {
    padding: 11px 13px;
    text-align: left;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
}

.analytics-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background 0.12s; }
.analytics-table tbody tr:last-child { border-bottom: none; }
.analytics-table tbody tr:hover { background: #f9fafb; }
.analytics-table tbody td { padding: 12px 13px; color: #111827; vertical-align: middle; }

.pct-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

/* ── CLICKABLE STARS + IMPROVEMENT SUGGESTIONS MODAL ── */
.stars-clickable { cursor: pointer; border-radius: 6px; padding: 2px 6px; transition: background 0.15s; }
.stars-clickable:hover, .stars-clickable:focus { background: #fef9e7; outline: none; }

.tip-overlay {
    display: none; position: fixed; inset: 0; background: rgba(17,24,39,0.55);
    z-index: 9999; align-items: center; justify-content: center; padding: 20px;
}
.tip-overlay.open { display: flex; }
.tip-box {
    background: #fff; border-radius: 12px; width: 560px; max-width: 100%;
    max-height: 88vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}
.tip-head {
    background: #111827; color: #fff; padding: 16px 20px; border-radius: 12px 12px 0 0;
    border-bottom: 3px solid #d4a843; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
}
.tip-head h3 { margin: 0; font-size: 16px; }
.tip-head .tip-sub { font-size: 12px; color: #9ca3af; margin-top: 3px; }
.tip-close { background: none; border: none; color: #9ca3af; font-size: 22px; cursor: pointer; line-height: 1; }
.tip-close:hover { color: #fff; }
.tip-body { padding: 18px 20px 22px; }
.tip-note { font-size: 13px; color: #374151; background: #f3f4f6; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; line-height: 1.5; }
.tip-item { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
.tip-item.priority { border-color: #fca5a5; background: #fffafa; }
.tip-rank { font-size: 10.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 6px; }
.tip-q { font-size: 14px; font-weight: bold; color: #111827; line-height: 1.4; margin-bottom: 8px; }
.tip-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.tip-bar { flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
.tip-bar-fill { height: 100%; border-radius: 4px; }
.tip-score { font-size: 12px; color: #6b7280; white-space: nowrap; }
.tip-suggest { font-size: 13px; color: #1f2937; line-height: 1.55; }
.tip-suggest b { color: #15665c; }
@media print { .tip-overlay { display: none !important; } }
</style>
<style>
/* ── ADDITIONS: modal actions, summary strip, severity colors ── */
.tip-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; margin-left: auto; }
.tip-btn {
    display: inline-flex; align-items: center; gap: 5px; border: 1px solid rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.08); color: #fff; font-size: 12px; font-weight: bold;
    padding: 6px 11px; border-radius: 7px; cursor: pointer; font-family: inherit; transition: background 0.15s;
}
.tip-btn:hover { background: #d4a843; border-color: #d4a843; color: #111827; }
.tip-btn:disabled { opacity: 0.6; cursor: wait; }
.tip-summary { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.tip-chip { flex: 1 1 110px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 9px 12px; background: #f9fafb; }
.tip-chip .n { font-size: 20px; font-weight: bold; color: #111827; line-height: 1.1; }
.tip-chip .l { font-size: 11px; color: #6b7280; margin-top: 2px; }
.tip-sev { display: inline-block; font-size: 10.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; padding: 2px 8px; border-radius: 999px; color: #fff; margin-left: 6px; vertical-align: middle; }
.tip-item.sev-needs    { border-left: 4px solid #f97316; }
.tip-item.sev-can      { border-left: 4px solid #f59e0b; }
.tip-item { margin-bottom: 14px; }
@media print { .tip-actions { display: none !important; } }
</style>
<!-- PDF export libraries (used only by the Download Report button) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
</head>
<body>

<!-- ── SIDEBAR (exact match to admin.php) ── -->
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="logo.png" alt="NEUST Logo" class="brand-logo">
        <div class="brand-text">
            <div class="brand-school">NEUST</div>
            <h2>Admin Panel</h2>
        </div>
    </div>
    <a href="admin.php">📋 Manage Sections</a>
    <a href="admin_analytics.php" class="active">📊 Analytics</a>
    <a href="admin_questions.php">🗒️ Questioner</a>
    <a href="teacher_masterlist.php">🧑‍🏫 View Comments</a>
    <a href="teacher_scores.php">📈 Teacher Scores</a>
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER (exact match to admin.php) ── -->
<div class="header">
    Analytics — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <!-- Print-only header (hidden on screen, shown only in printed/exported report) -->
    <div class="print-report-header">
        <img src="logo.png" alt="NEUST Logo">
        <div>
            <h1>Nueva Ecija University of Science and Technology</h1>
            <p>Teacher Rating Analytics Report — School Year: <?= htmlspecialchars($selectedSyLabel) ?> — Generated <?= date('F j, Y g:i A') ?></p>
        </div>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <h2 style="color:#111827;font-size:20px;">Teacher Rating Overview</h2>

        <!-- ── DOWNLOAD REPORT ── -->
        <div class="download-wrap">
            <button class="btn-download" onclick="toggleDownloadMenu()">⬇ Download Report ▾</button>
            <div class="download-menu" id="downloadMenu">
                <button onclick="printReport()">🖨️ Print</button>
                <div class="menu-divider"></div>
                <button onclick="downloadPDF()">📄 Download as PDF</button>
                <button onclick="downloadExcel()">📊 Download as Excel</button>
                <button onclick="downloadCSV()">📑 Download as CSV</button>
            </div>
        </div>
    </div>

    <!-- SUMMARY STAT CARDS -->
    <div class="stat-row">

        <div class="stat-card">
            <div class="stat-label">Total Teachers</div>
            <div class="stat-value"><?= $totalTeachersCount ?></div>
            <div class="stat-sub">registered in system</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Total Evaluations</div>
            <div class="stat-value"><?= $totalEvaluations ?></div>
            <div class="stat-sub">submitted by students</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Overall Avg Rating</div>
            <div class="stat-value"><?= number_format($overallAvg, 1) ?></div>
            <div class="stat-sub">out of 5.0</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Student Completion</div>
            <div class="stat-value"><?= $completionPct ?>%</div>
            <div class="stat-sub"><?= $studentsEvaled ?> / <?= $totalStudents ?> students evaluated</div>
            <div class="stat-bar-bg">
                <div class="stat-bar-fill" style="width:<?= $completionPct ?>%;"></div>
            </div>
        </div>

    </div>

    <!-- CONTROLS -->
    <div class="controls-row">
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" id="teacherSearch" class="search-input" placeholder="Search teacher by name…" oninput="applyFilters()" autocomplete="off">
            <button type="button" class="search-clear" id="searchClearBtn" onclick="clearSearch()">✕</button>
        </div>
        <select id="schoolYearFilter" class="filter-btn" style="border-radius:8px;padding:7px 12px;" onchange="changeSchoolYear(this.value)">
            <option value="all" <?= $selectedSyId === 'all' ? 'selected' : '' ?>>All School Years</option>
            <?php foreach ($schoolYears as $sy): ?>
            <option value="<?= $sy['id'] ?>" <?= (string)$selectedSyId === (string)$sy['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($sy['label']) ?><?= $sy['is_active'] ? ' (Active)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div class="filter-row">
            <span style="font-size:13px;color:#6b7280;font-weight:bold;margin-right:4px;">Filter:</span>
            <button class="filter-btn active" onclick="filterCards('all',this)">All</button>
            <button class="filter-btn" onclick="filterCards('excellent',this)">Excellent ≥80%</button>
            <button class="filter-btn" onclick="filterCards('good',this)">Good 60–79%</button>
            <button class="filter-btn" onclick="filterCards('needs',this)">Needs Improvement</button>
            <button class="filter-btn" onclick="filterCards('none',this)">Not Yet Rated</button>
        </div>
        <div class="view-toggle">
            <button class="view-btn active" id="btnGrid"  onclick="setView('grid')">⊞ Grid</button>
            <button class="view-btn"        id="btnTable" onclick="setView('table')">☰ Table</button>
        </div>
    </div>

    <!-- GRID VIEW -->
    <div class="row" id="gridView">

    <?php if (empty($analyticsData)): ?>
        <div class="no-data">No teachers found in the system.</div>
    <?php endif; ?>

    <?php foreach ($analyticsData as $i => $t):
        $avg   = (float)$t['avg_rating'];
        $total = (int)$t['total_evaluations'];
        $pct   = $total > 0 ? round(($avg / 5) * 100) : 0;
        $rank  = $i + 1;

        // Ring color (uses admin's teal #1a7f74 as top colour)
        if ($pct >= 80)      $color = '#1a7f74';
        elseif ($pct >= 60)  $color = '#3b82f6';
        elseif ($pct >= 40)  $color = '#f59e0b';
        elseif ($pct > 0)    $color = '#ef4444';
        else                  $color = '#d1d5db';

        if ($rank === 1)      $rankClass = 'rank-1';
        elseif ($rank === 2)  $rankClass = 'rank-2';
        elseif ($rank === 3)  $rankClass = 'rank-3';
        else                   $rankClass = 'rank-n';

        if ($total === 0)    $cat = 'none';
        elseif ($pct >= 80)  $cat = 'excellent';
        elseif ($pct >= 60)  $cat = 'good';
        else                  $cat = 'needs';

        $fullStars = (int)floor($avg);

        $bdColors = [5=>'#1a7f74', 4=>'#3b82f6', 3=>'#f59e0b', 2=>'#f97316', 1=>'#ef4444'];
        $bdLabels = [5=>'Excel.',  4=>'Good',    3=>'Fair',    2=>'Poor',    1=>'V.Poor'];

        // circumference 2π×54 ≈ 339.29
        $offset = 339.29 - ($pct / 100) * 339.29;
    ?>

    <div class="teacher-card" data-cat="<?= $cat ?>" data-name="<?= htmlspecialchars(strtolower($t['name'])) ?>">

        <span class="rank-badge <?= $rankClass ?>">#<?= $rank ?></span>

        <!-- Donut ring -->
        <div class="ring-wrap">
            <svg viewBox="0 0 120 120">
                <circle class="ring-bg"   cx="60" cy="60" r="54"/>
                <circle class="ring-fill" cx="60" cy="60" r="54"
                        stroke="<?= $color ?>"
                        data-offset="<?= $offset ?>"/>
            </svg>
            <div class="ring-center">
                <span class="ring-pct"><?= $total > 0 ? $pct.'%' : '—' ?></span>
                <span class="ring-label"><?= $total > 0 ? 'Score' : 'No data' ?></span>
            </div>
        </div>

        <!-- Name / subject -->
        <div style="width:100%;text-align:center;">
            <div class="t-name"><?= htmlspecialchars(trim(($t['title'] ?? '') . ' ' . getTeacherLastName($t['name']))) ?></div>
            <?php if ($selectedSyId !== 'all'): ?>
            <div class="t-subject"><?= htmlspecialchars($t['subject'] ?? 'No subject') ?></div>
            <?php endif; ?>
            <div class="stars stars-clickable" role="button" tabindex="0" title="Click to see what to improve" data-tipkey="<?= htmlspecialchars(strtolower(trim($t['name']))) ?>" onclick="openTips(this.getAttribute('data-tipkey'))" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openTips(this.getAttribute('data-tipkey'));}">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <span class="star <?= $s <= $fullStars ? '' : 'empty' ?>">★</span>
                <?php endfor; ?>
                <span class="avg-lbl"><?= number_format($avg, 2) ?></span>
            </div>
            <span class="eval-chip"><?= $total ?> evaluation<?= $total !== 1 ? 's' : '' ?></span>
        </div>

        <!-- Breakdown bars -->
        <?php if ($total > 0): ?>
        <div class="breakdown">
            <?php for ($s = 5; $s >= 1; $s--):
                $cnt    = (int)$t["count_$s"];
                $barPct = $total > 0 ? round(($cnt / $total) * 100) : 0;
            ?>
            <div class="bd-row">
                <div class="bd-label"><?= $bdLabels[$s] ?></div>
                <div class="bd-track">
                    <div class="bd-fill" data-width="<?= $barPct ?>"
                         style="background:<?= $bdColors[$s] ?>;"></div>
                </div>
                <div class="bd-count"><?= $cnt ?></div>
            </div>
            <?php endfor; ?>
        </div>
        <?php else: ?>
            <div style="font-size:12px;color:#9ca3af;text-align:center;">No evaluations yet.</div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

    </div><!-- /gridView -->

    <!-- NO SEARCH RESULTS MESSAGE (shared by both views) -->
    <div class="no-results" id="noResultsMsg">No teachers match your search.</div>

    <!-- TABLE VIEW -->
    <div id="tableView" style="display:none;">
    <table class="analytics-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Teacher</th>
                <?php if ($selectedSyId !== 'all'): ?>
                <th>Subject</th>
                <?php endif; ?>
                <th>Stars</th>
                <th>Score %</th>
                <th>Evaluations</th>
                <th style="color:#1a7f74;">Excellent</th>
                <th style="color:#3b82f6;">Good</th>
                <th style="color:#f59e0b;">Fair</th>
                <th style="color:#f97316;">Poor</th>
                <th style="color:#ef4444;">Very Poor</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($analyticsData as $i => $t):
            $avg   = (float)$t['avg_rating'];
            $total = (int)$t['total_evaluations'];
            $pct   = $total > 0 ? round(($avg / 5) * 100) : 0;
            $fullStars = (int)floor($avg);

            if ($total === 0)    $cat = 'none';
            elseif ($pct >= 80)  $cat = 'excellent';
            elseif ($pct >= 60)  $cat = 'good';
            else                  $cat = 'needs';

            if ($pct >= 80)      $pillStyle = 'background:#d1fae5;color:#065f46;';
            elseif ($pct >= 60)  $pillStyle = 'background:#dbeafe;color:#1e40af;';
            elseif ($pct >= 40)  $pillStyle = 'background:#fef3c7;color:#92400e;';
            elseif ($pct > 0)    $pillStyle = 'background:#fee2e2;color:#991b1b;';
            else                  $pillStyle = 'background:#f3f4f6;color:#6b7280;';
        ?>
        <tr data-cat="<?= $cat ?>" data-name="<?= htmlspecialchars(strtolower($t['name'])) ?>">
            <td style="color:#9ca3af;"><?= $i+1 ?></td>
            <td style="font-weight:bold;"><?= htmlspecialchars(trim(($t['title'] ?? '') . ' ' . getTeacherLastName($t['name']))) ?></td>
            <?php if ($selectedSyId !== 'all'): ?>
            <td style="color:#6b7280;"><?= htmlspecialchars($t['subject'] ?? '—') ?></td>
            <?php endif; ?>
            <td>
                <div class="stars stars-clickable" style="justify-content:flex-start;" role="button" tabindex="0" title="Click to see what to improve" data-tipkey="<?= htmlspecialchars(strtolower(trim($t['name']))) ?>" onclick="openTips(this.getAttribute('data-tipkey'))" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openTips(this.getAttribute('data-tipkey'));}">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <span class="star <?= $s <= $fullStars ? '' : 'empty' ?>">★</span>
                    <?php endfor; ?>
                    <span class="avg-lbl"><?= number_format($avg, 2) ?></span>
                </div>
            </td>
            <td><span class="pct-pill" style="<?= $pillStyle ?>"><?= $total > 0 ? $pct.'%' : '—' ?></span></td>
            <td><?= $total ?></td>
            <td style="color:#1a7f74;font-weight:bold;"><?= (int)$t['count_5'] ?></td>
            <td style="color:#3b82f6;font-weight:bold;"><?= (int)$t['count_4'] ?></td>
            <td style="color:#f59e0b;font-weight:bold;"><?= (int)$t['count_3'] ?></td>
            <td style="color:#f97316;font-weight:bold;"><?= (int)$t['count_2'] ?></td>
            <td style="color:#ef4444;font-weight:bold;"><?= (int)$t['count_1'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- /tableView -->

</div><!-- /main -->

<script>
let currentCategory = 'all';

window.addEventListener('load', () => {
    // Animate donut rings
    document.querySelectorAll('.ring-fill').forEach(c => {
        c.style.strokeDashoffset = c.getAttribute('data-offset');
    });
    // Animate breakdown bars
    document.querySelectorAll('.bd-fill').forEach(b => {
        b.style.width = b.getAttribute('data-width') + '%';
    });
});

function filterCards(cat, btn) {
    currentCategory = cat;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function setView(v) {
    document.getElementById('gridView').style.display  = v === 'grid'  ? '' : 'none';
    document.getElementById('tableView').style.display = v === 'table' ? '' : 'none';
    document.getElementById('btnGrid').classList.toggle('active',  v === 'grid');
    document.getElementById('btnTable').classList.toggle('active', v === 'table');
    applyFilters();
}

/* ── Combined category + search filtering ── */
function applyFilters() {
    const query      = document.getElementById('teacherSearch').value.trim().toLowerCase();
    const clearBtn   = document.getElementById('searchClearBtn');
    clearBtn.style.display = query ? 'block' : 'none';

    const isTableView = document.getElementById('tableView').style.display !== 'none';
    let visibleCount = 0;

    document.querySelectorAll('.teacher-card').forEach(card => {
        const matchesCat    = (currentCategory === 'all' || card.getAttribute('data-cat') === currentCategory);
        const matchesSearch = !query || card.getAttribute('data-name').includes(query);
        const show = matchesCat && matchesSearch;
        card.style.display = show ? '' : 'none';
        if (show && !isTableView) visibleCount++;
    });

    document.querySelectorAll('.analytics-table tbody tr').forEach(row => {
        const matchesCat    = (currentCategory === 'all' || row.getAttribute('data-cat') === currentCategory);
        const matchesSearch = !query || row.getAttribute('data-name').includes(query);
        const show = matchesCat && matchesSearch;
        row.style.display = show ? '' : 'none';
        if (show && isTableView) visibleCount++;
    });

    document.getElementById('noResultsMsg').style.display = visibleCount === 0 ? 'block' : 'none';
}

function clearSearch() {
    document.getElementById('teacherSearch').value = '';
    applyFilters();
    document.getElementById('teacherSearch').focus();
}

/* ── School Year Filter ── */
function changeSchoolYear(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('sy', value);
    window.location.href = url.toString();
}

/* ═══════════════════════════════════════════════
   DOWNLOAD REPORT — Print / PDF / Excel / CSV
   All formats are built from the same visible
   .analytics-table so numbers always match what's
   on screen. No server round-trip required.
   ═══════════════════════════════════════════════ */

const downloadMenu = document.getElementById('downloadMenu');

function toggleDownloadMenu() {
    downloadMenu.classList.toggle('open');
}

// Close the dropdown when clicking outside it
document.addEventListener('click', function (e) {
    if (!e.target.closest('.download-wrap')) {
        downloadMenu.classList.remove('open');
    }
});

function printReport() {
    downloadMenu.classList.remove('open');
    window.print();
}

// Reads the analytics table (always present in the DOM, even when Grid view is active)
function getReportRows() {
    const table = document.querySelector('.analytics-table');
    const rows = [];
    table.querySelectorAll('tr').forEach(tr => {
        const visible = tr.style.display !== 'none';
        if (!visible) return; // respect active filter/search
        const cells = [...tr.children].map(cell => cell.innerText.trim().replace(/\s+/g, ' '));
        rows.push(cells);
    });
    return rows;
}

function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function downloadCSV() {
    downloadMenu.classList.remove('open');
    const rows = getReportRows();
    const csv = rows.map(r => r.map(cell => '"' + cell.replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    triggerDownload(blob, 'teacher_analytics_report.csv');
}

function downloadExcel() {
    downloadMenu.classList.remove('open');
    const rows = getReportRows();
    let tableHtml = '<table border="1"><thead><tr>' +
        rows[0].map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>' +
        rows.slice(1).map(r => '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>').join('') +
        '</tbody></table>';

    const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
    <x:Name>Teacher Analytics</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
    </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>
    <body>${tableHtml}</body></html>`;

    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel' });
    triggerDownload(blob, 'teacher_analytics_report.xls');
}

function downloadPDF() {
    downloadMenu.classList.remove('open');
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });

    doc.setFontSize(14);
    doc.text('Nueva Ecija University of Science and Technology', 14, 15);
    doc.setFontSize(10);
    doc.setTextColor(107, 114, 128);
    doc.text('Teacher Rating Analytics Report — School Year: <?= addslashes(htmlspecialchars($selectedSyLabel)) ?> — Generated ' + new Date().toLocaleString(), 14, 21);

    doc.autoTable({
        html: '.analytics-table',
        startY: 27,
        styles: { fontSize: 8 },
        headStyles: { fillColor: [26, 127, 116], textColor: 255 }
    });

    doc.save('teacher_analytics_report.pdf');
}
</script>


<!-- ── IMPROVEMENT SUGGESTIONS MODAL (opened by clicking a teacher's stars) ── -->
<div class="tip-overlay" id="tipOverlay">
    <div class="tip-box">
        <div class="tip-head">
            <div>
                <h3 id="tipTitle">Suggestions</h3>
                <div class="tip-sub" id="tipSub"></div>
            </div>
            <div class="tip-actions">
                <button type="button" class="tip-btn" id="tipPrintBtn" onclick="printTips()" title="Print this report">🖨️ Print</button>
                <button type="button" class="tip-btn" id="tipPdfBtn" onclick="downloadTipsPDF()" title="Download as PDF">⬇️ PDF</button>
            </div>
            <button type="button" class="tip-close" onclick="closeTips()" aria-label="Close">&times;</button>
        </div>
        <div class="tip-body" id="tipBody"></div>
    </div>
</div>

<script>
const TEACHER_TIPS = <?= json_encode($teacherTips, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let CURRENT_TIP = null;
const TIP_SY_LABEL = <?= json_encode($selectedSyLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function tipEsc(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
}

function openTips(key) {
    const t = TEACHER_TIPS[key];
    if (!t) return;
    CURRENT_TIP = t;

    document.getElementById('tipTitle').textContent = 'What to improve — ' + t.name;
    document.getElementById('tipSub').textContent =
        TIP_SY_LABEL + ' • ' + t.total + ' evaluation' + (t.total !== 1 ? 's' : '') + ' • avg ' + Number(t.avg).toFixed(2) + ' / 5.00';

    let html = '';

    if (t.total === 0) {
        html = '<div class="tip-note">No evaluations yet, so there is nothing to analyze. Suggestions will appear once students evaluate this teacher.</div>';
    } else if (t.detailed === 0) {
        const good = t.avg >= 4;
        html = '<div class="tip-note">This teacher\'s evaluations were submitted before per-question answers were being saved, so the exact weak areas can\'t be identified yet. ' +
               'Suggestions based on individual questions will appear for new evaluations.</div>' +
               '<div class="tip-item"><div class="tip-q">' + (good ? 'Overall rating is strong' : 'Overall rating can be improved') + '</div>' +
               '<div class="tip-suggest">' + (good
                    ? 'Keep up the current teaching approach and continue gathering student feedback.'
                    : 'Review the comments students left and discuss the lower-rated areas with the department head.') + '</div></div>';
    } else if (!t.items.length) {
        html = '<div class="tip-note">✅ Nothing needs improvement right now — every question is rated well by students. Keep up the good work!</div>';
    } else {
        const cCrit = t.items.filter(function (x) { return x.avg < 2.0; }).length;
        const cNeed = t.items.filter(function (x) { return x.avg >= 2.0 && x.avg < 2.5; }).length;
        const cCan  = t.items.length - cCrit - cNeed;
        html += '<div class="tip-summary">' +
            '<div class="tip-chip"><div class="n">' + t.items.length + '</div><div class="l">area' + (t.items.length !== 1 ? 's' : '') + ' to improve</div></div>' +
            '<div class="tip-chip"><div class="n" style="color:' + tipSeverity(t.items[0].avg).color + ';">' + Number(t.items[0].avg).toFixed(2) + '</div><div class="l">lowest score (of 4.00)</div></div>' +
            '<div class="tip-chip"><div class="n">' + cCrit + ' / ' + cNeed + ' / ' + cCan + '</div><div class="l">critical / needs / can improve</div></div>' +
            '</div>';
        html += '<div class="tip-note">Only the low-rated areas are shown (scored 1–4 by students). Start with the first one.</div>';

        t.items.forEach(function (it, i) {
            let label, color;
            if (it.avg < 2.5) { label = 'Needs improvement'; color = '#ef4444'; }
            else              { label = 'Can be improved';   color = '#f59e0b'; }
            // added: 3-level severity (overrides the colors/labels above)
            const sev = tipSeverity(it.avg);
            label = sev.label; color = sev.color;

            html += '<div class="tip-item' + (i === 0 ? ' priority' : '') + '">' +
                '<div class="tip-rank" style="color:' + color + ';">' + (i === 0 ? 'Lowest rated • ' : '#' + (i + 1) + ' lowest • ') + label + '</div>' +
                '<div class="tip-q">' + tipEsc(it.question) + '</div>' +
                '<div class="tip-bar-row"><div class="tip-bar"><div class="tip-bar-fill" style="width:' + it.pct + '%;background:' + color + ';"></div></div>' +
                '<div class="tip-score">' + Number(it.avg).toFixed(2) + ' / 4.00 (' + it.pct + '%)</div></div>' +
                '<div class="tip-suggest"><b>Suggestion:</b> ' + tipEsc(it.suggestion) + '</div>' +
                '</div>';
        });
    }

    if (t.detailed > 0 && t.detailed < t.total) {
        html += '<div style="font-size:11.5px;color:#9ca3af;">Based on ' + t.detailed + ' of ' + t.total + ' evaluations that have per-question answers.</div>';
    }

    document.getElementById('tipBody').innerHTML = html;
    document.getElementById('tipOverlay').classList.add('open');
}

// ── ADDITIONS: severity levels, Print and PDF export ──
function tipSeverity(avg) {
    if (avg < 2.0) return { label: 'Critical',          color: '#dc2626', cls: 'sev-critical' };
    if (avg < 2.5) return { label: 'Needs improvement', color: '#f97316', cls: 'sev-needs' };
    return            { label: 'Can be improved',       color: '#f59e0b', cls: 'sev-can' };
}

function tipFileName(ext) {
    const base = (CURRENT_TIP ? CURRENT_TIP.name : 'teacher').replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '');
    return 'improvement_report_' + base + '_' + String(TIP_SY_LABEL).replace(/[^a-z0-9]+/gi, '-') + '.' + ext;
}

function tipTodayText() {
    return new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

function tipReportStatus(t) {
    if (t.total === 0) return 'No evaluations yet.';
    if (t.detailed === 0) return 'Evaluations were submitted before per-question answers were saved, so individual weak areas cannot be identified.';
    if (!t.items.length) return 'Nothing needs improvement. Every question is rated well by students.';
    return '';
}

function printTips() {
    const t = CURRENT_TIP;
    if (!t) return;
    let items = '';
    t.items.forEach(function (it, i) {
        const sev = tipSeverity(it.avg);
        items += '<div class="item" style="border-left-color:' + sev.color + ';">' +
            '<div class="rank" style="color:' + sev.color + ';">' + (i === 0 ? 'Lowest rated • ' : '#' + (i + 1) + ' lowest • ') + sev.label + '</div>' +
            '<div class="q">' + tipEsc(it.question) + '</div>' +
            '<div class="bar"><div style="width:' + it.pct + '%;background:' + sev.color + ';"></div></div>' +
            '<div class="score">' + Number(it.avg).toFixed(2) + ' / 4.00 (' + it.pct + '%)</div>' +
            '<div class="sg"><b>Suggestion:</b> ' + tipEsc(it.suggestion) + '</div></div>';
    });
    const status = tipReportStatus(t);
    if (status) items = '<div class="item" style="border-left-color:#9ca3af;">' + tipEsc(status) + '</div>';

    const doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + tipEsc(tipFileName('pdf')) + '</title><style>' +
        '@page{size:A4;margin:16mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:0}' +
        '.head{border-bottom:3px solid #1a7f74;padding-bottom:10px;margin-bottom:14px}' +
        '.school{font-size:11px;letter-spacing:1px;color:#1a7f74;font-weight:bold}h1{font-size:19px;margin:3px 0 2px}' +
        '.meta{font-size:11.5px;color:#4b5563;line-height:1.6}' +
        '.item{border:1px solid #e5e7eb;border-left:5px solid #f59e0b;border-radius:6px;padding:10px 12px;margin-bottom:10px;' +
        'page-break-inside:avoid;break-inside:avoid;font-size:12.5px}' +
        '.rank{font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}' +
        '.q{font-weight:bold;font-size:13px;margin-bottom:6px}.bar{height:7px;background:#e5e7eb;border-radius:4px;overflow:hidden}' +
        '.bar div{height:100%;-webkit-print-color-adjust:exact;print-color-adjust:exact}.score{font-size:11px;color:#6b7280;margin:4px 0 6px}' +
        '.sg{line-height:1.5}.foot{margin-top:12px;font-size:10px;color:#9ca3af;text-align:center}' +
        '</style></head><body>' +
        '<div class="head"><div class="school">NEUST • TEACHER EVALUATION SYSTEM</div><h1>What to improve — ' + tipEsc(t.name) + '</h1>' +
        '<div class="meta">School Year: ' + tipEsc(TIP_SY_LABEL) + ' &nbsp;|&nbsp; Evaluations: ' + t.total +
        ' &nbsp;|&nbsp; Average: ' + Number(t.avg).toFixed(2) + ' / 5.00 &nbsp;|&nbsp; Areas to improve: ' + t.items.length +
        '<br>Generated: ' + tipTodayText() + '</div></div>' + items +
        '<div class="foot">Generated by NEUST Teacher Evaluation System</div></body></html>';

    let frame = document.getElementById('tipPrintFrame');
    if (frame) frame.remove();
    frame = document.createElement('iframe');
    frame.id = 'tipPrintFrame';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);
    frame.contentDocument.open();
    frame.contentDocument.write(doc);
    frame.contentDocument.close();
    setTimeout(function () {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }, 250);
}

function downloadTipsPDF() {
    const t = CURRENT_TIP;
    if (!t) return;
    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert('The PDF library did not load. Check your internet connection and try again, or use Print.');
        return;
    }
    const btn = document.getElementById('tipPdfBtn');
    btn.disabled = true;
    try {
        const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
        const W = 210, M = 15, CW = W - M * 2, PH = 297, BOTTOM = PH - 18;
        let y = M;

        function hex(h) { return [parseInt(h.substr(1, 2), 16), parseInt(h.substr(3, 2), 16), parseInt(h.substr(5, 2), 16)]; }
        function newPage() { pdf.addPage(); y = M; }

        pdf.setFillColor(26, 127, 116); pdf.rect(M, y + 11, CW, 0.8, 'F');
        pdf.setFont('helvetica', 'bold'); pdf.setFontSize(9); pdf.setTextColor(26, 127, 116);
        pdf.text('NEUST  |  TEACHER EVALUATION SYSTEM', M, y);
        pdf.setFontSize(16); pdf.setTextColor(17, 24, 39);
        const title = pdf.splitTextToSize('What to improve - ' + t.name, CW);
        pdf.text(title, M, y + 7);
        y += 7 + title.length * 6.5;
        pdf.setFillColor(26, 127, 116); pdf.rect(M, y - 4.5, CW, 0.8, 'F');
        pdf.setFont('helvetica', 'normal'); pdf.setFontSize(9.5); pdf.setTextColor(75, 85, 99);
        pdf.text('School Year: ' + TIP_SY_LABEL + '    Evaluations: ' + t.total + '    Average: ' + Number(t.avg).toFixed(2) + ' / 5.00    Areas to improve: ' + t.items.length, M, y + 1);
        pdf.text('Generated: ' + tipTodayText(), M, y + 6);
        y += 14;

        const status = tipReportStatus(t);
        if (status) {
            pdf.setFontSize(10.5); pdf.setTextColor(55, 65, 81);
            pdf.text(pdf.splitTextToSize(status, CW), M, y);
        }

        t.items.forEach(function (it, i) {
            const sev = tipSeverity(it.avg), c = hex(sev.color);
            pdf.setFont('helvetica', 'bold'); pdf.setFontSize(11);
            const qLines = pdf.splitTextToSize(it.question || '', CW - 10);
            pdf.setFont('helvetica', 'normal'); pdf.setFontSize(10);
            const sLines = pdf.splitTextToSize('Suggestion: ' + (it.suggestion || ''), CW - 10);
            const h = 6 + qLines.length * 5 + 9 + sLines.length * 4.6 + 6;
            if (y + h > BOTTOM) newPage();   // never split a card across pages

            pdf.setDrawColor(229, 231, 235); pdf.setLineWidth(0.3);
            pdf.roundedRect(M, y, CW, h, 2, 2, 'S');
            pdf.setFillColor(c[0], c[1], c[2]); pdf.rect(M, y, 1.8, h, 'F');

            let yy = y + 6;
            pdf.setFont('helvetica', 'bold'); pdf.setFontSize(8); pdf.setTextColor(c[0], c[1], c[2]);
            pdf.text(((i === 0 ? 'LOWEST RATED  |  ' : '#' + (i + 1) + ' LOWEST  |  ') + sev.label).toUpperCase(), M + 6, yy);
            yy += 5;
            pdf.setFontSize(11); pdf.setTextColor(17, 24, 39);
            pdf.text(qLines, M + 6, yy);
            yy += qLines.length * 5;

            pdf.setFillColor(229, 231, 235); pdf.roundedRect(M + 6, yy - 1, CW - 50, 2.4, 1.2, 1.2, 'F');
            pdf.setFillColor(c[0], c[1], c[2]); pdf.roundedRect(M + 6, yy - 1, Math.max(2, (CW - 50) * it.pct / 100), 2.4, 1.2, 1.2, 'F');
            pdf.setFont('helvetica', 'normal'); pdf.setFontSize(9); pdf.setTextColor(107, 114, 128);
            pdf.text(Number(it.avg).toFixed(2) + ' / 4.00 (' + it.pct + '%)', M + CW - 42, yy + 1);
            yy += 7;

            pdf.setFontSize(10); pdf.setTextColor(31, 41, 55);
            pdf.text(sLines, M + 6, yy);
            y += h + 4;
        });

        const pages = pdf.getNumberOfPages();
        for (let p = 1; p <= pages; p++) {
            pdf.setPage(p); pdf.setFontSize(8); pdf.setTextColor(156, 163, 175);
            pdf.text('NEUST Teacher Evaluation System  -  Page ' + p + ' of ' + pages, W / 2, PH - 8, { align: 'center' });
        }
        pdf.save(tipFileName('pdf'));
    } catch (err) {
        console.error(err);
        alert('Could not create the PDF. You can use Print and choose "Save as PDF" instead.');
    }
    btn.disabled = false;
}

function closeTips() {
    document.getElementById('tipOverlay').classList.remove('open');
}

document.getElementById('tipOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeTips();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeTips();
});
</script>

</body>
</html>