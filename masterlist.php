<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';

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

/* Assigned Teacher column can hold more than one name (GROUP_CONCAT'd,
   comma-separated) — prepend a title to each name in the list without
   touching how the list itself is built. */
function formatTeacherNamesWithTitle($namesStr) {
    $namesStr = trim($namesStr ?? '');
    if ($namesStr === '') return $namesStr;
    $names = array_map('trim', explode(',', $namesStr));
    $withTitles = array_map(function ($n) {
        return $n === '' ? $n : (guessTeacherTitle($n) . ' ' . $n);
    }, $names);
    return implode(', ', $withTitles);
}

$year    = $_GET['year']    ?? '';
$section = strtoupper($_GET['section'] ?? '');

// Only show students that belong to the currently active school year, so
// switching school years actually gives a fresh/differentiated roster
// instead of mixing every year's students together. Students that were
// added before school years existed (school_year_id still NULL) are kept
// visible too, so nothing already in the system silently disappears.
$activeSyId = null;
$activeSyRow = $conn->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1");
if ($activeSyRow && ($r = $activeSyRow->fetch_assoc())) {
    $activeSyId = (int)$r['id'];
}

$students = [];
if ($year !== '' && $section !== '') {
    $stmt = $conn->prepare("
        SELECT s.id, s.student_id, s.name, s.email,
               s.registration_no, s.course, s.barangay, s.city_municipality, s.province,
               s.gender, s.birthday, s.contact_number,
               COALESCE(
                   (SELECT GROUP_CONCAT(t2.name SEPARATOR ', ')
                    FROM student_teachers st
                    JOIN teachers t2 ON t2.id = st.teacher_id
                    WHERE st.student_id = s.id),
                   t.name
               ) AS teacher_name
        FROM students s
        LEFT JOIN teachers t ON t.id = s.teacher_id
        WHERE s.year = ? AND s.section = ?
          AND (s.school_year_id = ? OR s.school_year_id IS NULL)
        ORDER BY s.name
    ");
    $stmt->bind_param("ssi", $year, $section, $activeSyId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Masterlist — Admin Panel</title>
<style>
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

.sidebar a {
    display: block;
    padding: 8px 20px;
    color: white;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.15s, color 0.15s;
}

.sidebar a:hover          { background: #374151; color: white; }
.sidebar a.analytics-link { color: #58a6ff; }
.sidebar a.analytics-link:hover { background: #374151; color: #93c5fd; }
.sidebar a.logout         { color: #f87171; position: absolute; bottom: 20px; width: 100%; }
.sidebar a.logout:hover   { background: #374151; color: #fca5a5; }

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

.top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}

.top-bar h2 {
    margin: 0;
    font-size: 20px;
    color: #111827;
}

.year-badge {
    font-size: 11px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 3px 10px;
    color: #6b7280;
    font-weight: bold;
    margin-left: 8px;
}

.btn-back {
    background: #fff;
    color: #374151;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 9px 16px;
    font-size: 13px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.15s;
}

.btn-back:hover { background: #f9fafb; border-color: #d1d5db; }

.masterlist-table {
    width: 100%;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    border-collapse: collapse;
    overflow: hidden;
}

.masterlist-table thead tr { background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
.masterlist-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
}

.masterlist-table tbody tr { border-bottom: 1px solid #f3f4f6; }
.masterlist-table tbody tr:last-child { border-bottom: none; }
.masterlist-table tbody tr:hover { background: #f9fafb; }
.masterlist-table td { padding: 12px 16px; font-size: 13px; color: #111827; vertical-align: middle; }
.masterlist-table td.muted { color: #9ca3af; }

.empty-state {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 50px;
    text-align: center;
    color: #9ca3af;
    font-size: 14px;
}

.count-chip {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 16px;
}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<div class="sidebar">
    <h2>Admin Panel</h2>
    <a href="admin.php">📋 Manage Sections</a>
    <a href="admin_analytics.php" class="analytics-link">📊 Analytics</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">Masterlist</div>

<!-- ── MAIN ── -->
<div class="main">

    <div class="top-bar">
        <h2>
            Masterlist — Section <?= htmlspecialchars($section) ?>
            <span class="year-badge">Year <?= htmlspecialchars($year) ?></span>
        </h2>
        <a href="admin.php" class="btn-back">← Back to Sections</a>
    </div>

    <?php if ($year === '' || $section === ''): ?>

        <div class="empty-state">Missing year or section. Go back and choose "View Masterlist" from a section card.</div>

    <?php elseif (empty($students)): ?>

        <div class="empty-state">No students found yet for Section <?= htmlspecialchars($section) ?>, Year <?= htmlspecialchars($year) ?>.</div>

    <?php else: ?>

        <div class="count-chip"><?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?></div>

        <div style="width:100%;overflow-x:auto;">
        <table class="masterlist-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Registration No.</th>
                    <th>Student No.</th>
                    <th>Student Name</th>
                    <th>Course</th>
                    <th>Barangay</th>
                    <th>City/Municipality</th>
                    <th>Province</th>
                    <th>Date of Birth</th>
                    <th>Sex</th>
                    <th>Mobile #</th>
                    <th>Email Address</th>
                    <th>Assigned Teacher</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $i => $s): ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td><?= $s['registration_no'] ? htmlspecialchars($s['registration_no']) : '<span class="muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($s['student_id']) ?></td>
                    <td style="font-weight:bold;"><?= $s['name'] ? htmlspecialchars($s['name']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['course'] ? htmlspecialchars($s['course']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['barangay'] ? htmlspecialchars($s['barangay']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['city_municipality'] ? htmlspecialchars($s['city_municipality']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['province'] ? htmlspecialchars($s['province']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['birthday'] ? htmlspecialchars(date('M d, Y', strtotime($s['birthday']))) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['gender'] ? htmlspecialchars($s['gender']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $s['contact_number'] ? htmlspecialchars($s['contact_number']) : '<span class="muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td class="<?= $s['teacher_name'] ? '' : 'muted' ?>"><?= htmlspecialchars($s['teacher_name'] ? formatTeacherNamesWithTitle($s['teacher_name']) : 'Unassigned') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php endif; ?>

</div><!-- /main -->

</body>
</html>