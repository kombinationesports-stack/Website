<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';

$conn = new mysqli("localhost", "root", "", "school_system");

/* ================= HELPER: get a teacher's last name for display =================
   Same helper already used in admin_backend.php / admin_analytics.php —
   only affects display here. */
function getTeacherLastName($fullName) {
    $fullName = trim($fullName ?? '');
    if ($fullName === '') return '';
    $parts = preg_split('/\s+/', $fullName);
    return end($parts);
}

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

/* ================= ACTIVE SCHOOL YEAR ================= */
$activeSyRow    = $conn->query("SELECT id, label FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
$activeSyId     = $activeSyRow['id']    ?? null;
$activeSyLabel  = $activeSyRow['label'] ?? null;

/* ================= FETCH SECTIONS (active school year only) =================
   Same scoping as "Manage Sections" (admin.php), so this list always
   matches whatever sections currently exist for the active school year. */
$sections = [];
$secStmt = $conn->prepare("SELECT year, section, course FROM sections WHERE school_year_id <=> ? ORDER BY year, section");
$secStmt->bind_param("i", $activeSyId);
$secStmt->execute();
$secResult = $secStmt->get_result();
while ($row = $secResult->fetch_assoc()) {
    $sections[$row['year']][] = ['section' => $row['section'], 'course' => $row['course']];
}
$secStmt->close();

/* ================= FETCH TEACHERS PER SECTION (active school year only) =================
   Pulled straight from section_teachers + teachers — the exact same
   tables "Manage Teachers" (in admin.php) reads/writes. Nothing is
   duplicated or cached here, so whatever is assigned/edited/removed in
   Manage Teachers shows up here automatically, already scoped correctly
   per section (each teacher's subject only ever reflects what that
   section was actually assigned). */
$sectionTeachers = [];
$stStmt = $conn->prepare("
    SELECT st.year, st.section, t.id, t.name, t.subject
    FROM section_teachers st
    JOIN teachers t ON t.id = st.teacher_id
    WHERE st.school_year_id <=> ?
    ORDER BY st.year, st.section, t.name
");
$stStmt->bind_param("i", $activeSyId);
$stStmt->execute();
$stResult = $stStmt->get_result();
while ($row = $stResult->fetch_assoc()) {
    $sectionTeachers[$row['year']][$row['section']][] = $row;
}
$stStmt->close();

/* ================= TEACHER COMMENTS (additive) =================
   Reads the comments students already type in Student_dashboard.php when they evaluate
   a teacher (evaluations.comments). Only the active school year, same as the rest of this page.
   Shown anonymously on purpose — only the rating, comment and date. */
$tcTeachers = [];
$tcComments = [];

$tcStmt = $conn->prepare("
    SELECT t.id, t.name, t.subject,
           COUNT(e.id) AS evals,
           COALESCE(SUM(e.comments IS NOT NULL AND TRIM(e.comments) <> ''), 0) AS ccount
    FROM teachers t
    LEFT JOIN evaluations e ON e.teacher_id = t.id AND e.school_year_id <=> ?
    WHERE t.school_year_id <=> ?
    GROUP BY t.id, t.name, t.subject
    ORDER BY t.name
");
$tcStmt->bind_param("ii", $activeSyId, $activeSyId);
$tcStmt->execute();
$tcRes = $tcStmt->get_result();
while ($row = $tcRes->fetch_assoc()) { $tcTeachers[] = $row; }
$tcStmt->close();

$tcCStmt = $conn->prepare("
    SELECT e.teacher_id, e.rating, e.comments, e.submitted_at
    FROM evaluations e
    JOIN teachers t ON t.id = e.teacher_id
    WHERE t.school_year_id <=> ? AND e.school_year_id <=> ?
      AND e.comments IS NOT NULL AND TRIM(e.comments) <> ''
    ORDER BY e.submitted_at DESC
");
$tcCStmt->bind_param("ii", $activeSyId, $activeSyId);
$tcCStmt->execute();
$tcCRes = $tcCStmt->get_result();
while ($row = $tcCRes->fetch_assoc()) { $tcComments[(int)$row['teacher_id']][] = $row; }
$tcCStmt->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Teacher Masterlist — Admin Panel</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
/* ── BASE — identical to admin.php / admin_analytics.php / admin_questions.php ── */
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial; background: #f5f7fb; }

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
.sidebar a.logout { color: #f87171; position: absolute; bottom: 20px; width: 100%; }
.sidebar a.logout:hover { background: #374151; color: #fca5a5; }

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

/* ── TOP BAR ── */
.top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 10px;
}

.top-bar h2 {
    margin: 0;
    font-size: 20px;
    color: #111827;
}

.top-bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.year-select {
    padding: 8px 14px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 13px;
    font-family: Arial;
    background: #fff;
    color: #374151;
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s;
}

.year-select:focus { border-color: #1a7f74; }

.sy-chip {
    font-size: 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    padding: 7px 14px;
    color: #374151;
    font-weight: bold;
}

.hint-select {
    color: #9ca3af;
    font-size: 13px;
    margin-bottom: 10px;
}

/* ── SECTION CARDS GRID (same as admin.php) ── */
.row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 40px;
}

.section-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 20px;
    width: 280px;
    display: none;
    flex-direction: column;
    gap: 14px;
    transition: box-shadow 0.2s;
}

.section-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); }

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    font-size: 15px;
    font-weight: bold;
    color: #111827;
}

.year-badge {
    font-size: 11px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 2px 9px;
    color: #6b7280;
    font-weight: bold;
}

.card-divider {
    border: none;
    border-top: 1px solid #f3f4f6;
    margin: 0;
}

.teacher-label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 4px;
}

/* ── TEACHER ROWS (read-only, live from section_teachers/teachers) ── */
.tm-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-height: 20px;
}

.tm-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 7px 0;
    border-bottom: 1px solid #f3f4f6;
}

.tm-row:last-child { border-bottom: none; }

.tm-name {
    font-size: 13.5px;
    font-weight: bold;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tm-subject {
    font-size: 10.5px;
    background: #eef2ff;
    border: 1px solid #e0e7ff;
    border-radius: 12px;
    padding: 2px 8px;
    color: #4338ca;
    font-weight: bold;
    text-align: right;
    white-space: normal;
    max-width: 150px;
}

.tm-empty {
    font-size: 12.5px;
    color: #9ca3af;
    padding: 6px 0;
}

.btn-view-masterlist {
    background: #fff;
    color: #1a7f74;
    border: 1px solid #1a7f74;
    border-radius: 8px;
    padding: 9px;
    font-size: 13px;
    font-weight: bold;
    font-family: Arial;
    cursor: pointer;
    width: 100%;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    transition: all 0.15s;
    margin-top: auto;
}

.btn-view-masterlist:hover { background: #e6f4f2; border-color: #15665c; color: #15665c; }

.tm-export-row {
    display: flex;
    gap: 8px;
    margin-top: auto;
}

.tm-export-row .btn-view-masterlist {
    margin-top: 0;
}

.empty-state {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 50px;
    text-align: center;
    color: #9ca3af;
    font-size: 14px;
    width: 100%;
}

/* ── TEACHER COMMENTS (additive) ── */
.tc-title { font-size: 15px; font-weight: bold; color: #111827; margin: 6px 0 14px; }
.tc-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 36px; align-items: flex-start; }
.tc-card { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 18px 20px; width: 340px; transition: box-shadow 0.2s; }
.tc-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); }
.tc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.tc-name { font-size: 15px; font-weight: bold; color: #111827; }
.tc-subject { font-size: 10.5px; background: #eef2ff; border: 1px solid #e0e7ff; border-radius: 12px; padding: 2px 8px; color: #4338ca; font-weight: bold; display: inline-block; margin-top: 6px; }
.tc-count { font-size: 11px; font-weight: bold; border-radius: 12px; padding: 3px 10px; white-space: nowrap; background: #d1fae5; color: #065f46; }
.tc-count.none { background: #f3f4f6; color: #9ca3af; }
.tc-card details { margin-top: 12px; border-top: 1px solid #f0f0f0; padding-top: 10px; }
.tc-card summary { cursor: pointer; font-size: 13px; font-weight: bold; color: #0f766e; list-style: none; }
.tc-card summary::-webkit-details-marker { display: none; }
.tc-card summary::before { content: '▸ '; }
.tc-card details[open] summary::before { content: '▾ '; }
.tc-comments { margin-top: 10px; max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
.tc-comment { background: #f9fafb; border: 1px solid #eef0f3; border-radius: 8px; padding: 9px 11px; }
.tc-comment-text { font-size: 13px; color: #1f2937; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.tc-comment-meta { font-size: 11px; color: #9ca3af; margin-top: 5px; display: flex; justify-content: space-between; }
.tc-stars { color: #f59e0b; letter-spacing: 1px; }
.tc-nocomments { font-size: 12.5px; color: #9ca3af; padding: 6px 0; }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="logo.png" alt="NEUST Logo" class="brand-logo">
        <div class="brand-text">
            <div class="brand-school">NEUST</div>
            <h2>Admin Panel</h2>
        </div>
    </div>
    <a href="admin.php">📋 Manage Sections</a>
    <a href="admin_analytics.php">📊 Analytics</a>
    <a href="admin_questions.php">🗒️ Questioner</a>
    <a href="teacher_masterlist.php" class="active">🧑‍🏫 View Comments</a>
    <a href="teacher_scores.php">📈 Teacher Scores</a>
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
    
  View Comments — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <div class="top-bar">
        <h2>View Comments</h2>
        <div class="top-bar-right">
            <select id="teacherSelect" class="year-select">
                <option value="">All Teachers</option>
                <?php foreach ($tcTeachers as $tcOpt): ?>
                <option value="<?= (int)$tcOpt['id'] ?>"><?= htmlspecialchars(guessTeacherTitle($tcOpt['name']) . ' ' . $tcOpt['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="sy-chip">🗓 <?= $activeSyLabel ? htmlspecialchars($activeSyLabel) . ' (Active)' : 'No Active School Year' ?></span>
        </div>
    </div>


    <!-- ── TEACHER COMMENTS (what students wrote when evaluating) ── -->
    <div class="tc-title">💬 Teacher Comments<?= $activeSyLabel ? ' — SY ' . htmlspecialchars($activeSyLabel) : '' ?></div>
    <div class="tc-row">
    <?php if (empty($tcTeachers)): ?>
        <div class="tc-nocomments">No teachers yet for this school year. Add one in <strong>Manage Teachers</strong>.</div>
    <?php endif; ?>
    <?php foreach ($tcTeachers as $tc):
        $tcList  = $tcComments[(int)$tc['id']] ?? [];
        $tcCount = count($tcList);
    ?>
        <div class="tc-card" data-teacher-id="<?= (int)$tc['id'] ?>">
            <div class="tc-head">
                <div>
                    <div class="tc-name"><?= htmlspecialchars(guessTeacherTitle($tc['name']) . ' ' . $tc['name']) ?></div>
                    <?php if (!empty($tc['subject'])): ?><span class="tc-subject"><?= htmlspecialchars($tc['subject']) ?></span><?php endif; ?>
                </div>
                <span class="tc-count <?= $tcCount ? '' : 'none' ?>"><?= $tcCount ?> comment<?= $tcCount === 1 ? '' : 's' ?></span>
            </div>
            <details>
                <summary>View Comments (<?= (int)$tc['evals'] ?> evaluation<?= (int)$tc['evals'] === 1 ? '' : 's' ?>)</summary>
                <div class="tc-comments">
                <?php if (!$tcCount): ?>
                    <div class="tc-nocomments">No comments from students yet.</div>
                <?php else: foreach ($tcList as $c): $stars = max(0, min(5, (int)$c['rating'])); ?>
                    <div class="tc-comment">
                        <div class="tc-comment-text"><?= htmlspecialchars($c['comments']) ?></div>
                        <div class="tc-comment-meta">
                            <span class="tc-stars"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></span>
                            <span><?= htmlspecialchars(date('M j, Y', strtotime($c['submitted_at']))) ?></span>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>
    </div>


    <div class="row" id="cardsRow">

    <?php
    $hasAny = false;
    foreach ($sections as $year => $secs):
        foreach ($secs as $secRow):
            $sec      = $secRow['section'];
            $course   = trim($secRow['course'] ?? '');
            $secUpper = strtoupper($sec);
            $hasAny   = true;
            $rows     = $sectionTeachers[$year][$sec] ?? [];
            $secTeacherIds = implode(',', array_map(function ($r) { return (int)$r['id']; }, $rows));
    ?>
        <div class="section-card" data-year="<?= $year ?>" data-teacher-ids="<?= $secTeacherIds ?>">

            <div class="card-header">
                <div class="card-title"><?= $course !== '' ? htmlspecialchars($course) . ' ' . htmlspecialchars($sec) : 'Section ' . htmlspecialchars($sec) ?></div>
                <span class="year-badge">Year <?= $year ?></span>
            </div>

            <hr class="card-divider">

            <div>
                <label class="teacher-label">Assigned Teacher(s)</label>
                <div class="tm-list">
                    <?php if (empty($rows)): ?>
                        <div class="tm-empty">No teachers assigned yet.</div>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <div class="tm-row">
                            <span class="tm-name"><?= htmlspecialchars(guessTeacherTitle($r['name']) . ' ' . $r['name']) ?></span>
                            <span class="tm-subject"><?= htmlspecialchars($r['subject'] ?: '—') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tm-export-row">
                <button type="button" class="btn-view-masterlist" onclick="printTeacherCard(this.closest('.section-card'))">🖨️ Print</button>
                <button type="button" class="btn-view-masterlist" onclick="downloadTeacherCardPDF(this.closest('.section-card'))">📄 Download PDF</button>
            </div>

        </div>
    <?php
        endforeach;
    endforeach;
    ?>


    </div><!-- /row -->

</div><!-- /main -->

<script>
/* ── Show all sections immediately on load (same behavior as admin.php) ── */
document.querySelectorAll('.section-card').forEach(c => {
    c.style.display = 'flex';
});

/* ── Teacher filter ── */
const teacherSelect = document.getElementById('teacherSelect');

teacherSelect.addEventListener('change', function () {
    const t = this.value;

    document.querySelectorAll('.tc-card').forEach(c => {
        c.style.display = (!t || c.getAttribute('data-teacher-id') === t) ? '' : 'none';
    });

    document.querySelectorAll('.section-card').forEach(c => {
        const ids = (c.getAttribute('data-teacher-ids') || '').split(',');
        c.style.display = (!t || ids.includes(t)) ? 'flex' : 'none';
    });
});

/* ── Print / Save as PDF (per section card) ──
   Purely additive — reads the card that's already rendered on the page
   and opens a clean print window for it. Uses the browser's own
   "Save as PDF" option in the print dialog, so no PDF library is needed
   and nothing about how the cards are built/fetched changes. */
function printTeacherCard(cardEl) {
    const title     = cardEl.querySelector('.card-title')?.innerText ?? 'Section';
    const yearBadge = cardEl.querySelector('.year-badge')?.innerText ?? '';

    const emptyRow = cardEl.querySelector('.tm-empty');
    let rowsHtml = '';

    if (emptyRow) {
        rowsHtml = `<tr><td colspan="2" style="padding:12px 0;color:#9ca3af;">${emptyRow.innerText}</td></tr>`;
    } else {
        cardEl.querySelectorAll('.tm-row').forEach(r => {
            const name    = r.querySelector('.tm-name')?.innerText ?? '';
            const subject = r.querySelector('.tm-subject')?.innerText ?? '';
            rowsHtml += `<tr>
                <td style="padding:10px 14px;border-bottom:1px solid #eee;">${name}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #eee;color:#4338ca;">${subject}</td>
            </tr>`;
        });
    }

    const printWin = window.open('', '_blank', 'width=750,height=900');
    printWin.document.write(`
        <html>
        <head>
            <title>Teacher Comments — ${title}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 34px; color: #111827; }
                h1 { font-size: 19px; margin: 0 0 4px; }
                .sub { color: #6b7280; font-size: 13px; margin-bottom: 22px; }
                table { width: 100%; border-collapse: collapse; }
                th { text-align: left; padding: 10px 14px; font-size: 11px; text-transform: uppercase;
                     letter-spacing: 0.5px; color: #6b7280; border-bottom: 2px solid #e5e7eb; }
            </style>
        </head>
        <body>
            <h1>Teacher Comments — ${title}</h1>
            <div class="sub">${yearBadge} &nbsp;•&nbsp; Nueva Ecija University of Science and Technology</div>
            <table>
                <thead><tr><th>Teacher</th><th>Subject</th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </body>
        </html>
    `);
    printWin.document.close();
    printWin.focus();
    printWin.print();
}

/* ── Download PDF (per section card) ──
   Also purely additive. Builds the same clean layout as the Print
   button, but hands it to html2pdf.js so the browser downloads an
   actual .pdf file directly — no print dialog involved. */
function downloadTeacherCardPDF(cardEl) {
    const title     = cardEl.querySelector('.card-title')?.innerText ?? 'Section';
    const yearBadge = cardEl.querySelector('.year-badge')?.innerText ?? '';

    const emptyRow = cardEl.querySelector('.tm-empty');
    let rowsHtml = '';

    if (emptyRow) {
        rowsHtml = `<tr><td colspan="2" style="padding:12px 0;color:#9ca3af;">${emptyRow.innerText}</td></tr>`;
    } else {
        cardEl.querySelectorAll('.tm-row').forEach(r => {
            const name    = r.querySelector('.tm-name')?.innerText ?? '';
            const subject = r.querySelector('.tm-subject')?.innerText ?? '';
            rowsHtml += `<tr>
                <td style="padding:10px 14px;border-bottom:1px solid #eee;">${name}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #eee;color:#4338ca;">${subject}</td>
            </tr>`;
        });
    }

    const pdfContent = document.createElement('div');
    pdfContent.style.padding = '34px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.color = '#111827';
    pdfContent.innerHTML = `
        <h1 style="font-size:19px;margin:0 0 4px;">Teacher Masterlist — ${title}</h1>
        <div style="color:#6b7280;font-size:13px;margin-bottom:22px;">${yearBadge} &nbsp;•&nbsp; Nueva Ecija University of Science and Technology</div>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#6b7280;border-bottom:2px solid #e5e7eb;">Teacher</th>
                    <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#6b7280;border-bottom:2px solid #e5e7eb;">Subject</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>
    `;

    const safeFileName = 'Teacher_Masterlist_' + title.replace(/[^a-zA-Z0-9]+/g, '_') + '.pdf';

    html2pdf().set({
        margin: 0,
        filename: safeFileName,
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'pt', format: 'a4', orientation: 'portrait' }
    }).from(pdfContent).save();
}
</script>

</body>
</html>