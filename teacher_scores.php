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

/* ================= TEACHER SCORES (read-only) =================
   Total evaluation score of each teacher, per school year, per subject they handle.
   Only READS what already exists (teachers, teacher_subjects, evaluations, school_years) —
   nothing is created, changed or deleted by this page.
   Score = the 1–5 rating each student already gave in Student_dashboard.php. */

$syLabels = [];
try {
    $q = $conn->query("SELECT id, label FROM school_years");
    while ($q && ($r = $q->fetch_assoc())) { $syLabels[(int)$r['id']] = $r['label']; }
} catch (Throwable $e) {}

$teacherRows = [];
try {
    $q = $conn->query("SELECT id, name FROM teachers");
    while ($q && ($r = $q->fetch_assoc())) { $teacherRows[(int)$r['id']] = $r['name']; }
} catch (Throwable $e) {}

/* the subjects each teacher handles */
$subjectRows = [];
try {
    $q = $conn->query("
        SELECT ts.id, ts.teacher_id, ts.subject, COALESCE(ts.school_year_id, t.school_year_id) AS sy_id
        FROM teacher_subjects ts
        JOIN teachers t ON t.id = ts.teacher_id
    ");
    while ($q && ($r = $q->fetch_assoc())) { $subjectRows[(int)$r['id']] = $r; }
} catch (Throwable $e) {}

/* evaluation totals, per teacher + subject + school year */
$hasTsCol = false;
try {
    $c = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'teacher_subject_id'");
    $hasTsCol = ($c && $c->num_rows > 0);
} catch (Throwable $e) {}

$evalRows = [];
try {
    $tsCol = $hasTsCol ? "teacher_subject_id" : "NULL";
    $q = $conn->query("
        SELECT teacher_id, $tsCol AS ts_id, school_year_id, COUNT(*) AS c, SUM(rating) AS total
        FROM evaluations
        GROUP BY teacher_id, " . ($hasTsCol ? "teacher_subject_id, " : "") . "school_year_id
    ");
    while ($q && ($r = $q->fetch_assoc())) { $evalRows[] = $r; }
} catch (Throwable $e) {}

/* how many different students evaluated the teacher, per school year */
$uniqRows = [];
try {
    $q = $conn->query("SELECT teacher_id, school_year_id, COUNT(DISTINCT student_id) AS u FROM evaluations GROUP BY teacher_id, school_year_id");
    while ($q && ($r = $q->fetch_assoc())) { $uniqRows[] = $r; }
} catch (Throwable $e) {}

/* one card per teacher name (a teacher added again in a new school year shows under the same card) */
$cards = [];
function tsCardKey(&$cards, $teacherRows, $tid) {
    if (!isset($teacherRows[$tid])) return null;
    $k = strtolower(trim($teacherRows[$tid]));
    if (!isset($cards[$k])) $cards[$k] = ['name' => $teacherRows[$tid], 'rows' => [], 'uniq' => []];
    return $k;
}
function tsSyLabel($syLabels, $id) {
    return ($id && isset($syLabels[(int)$id])) ? $syLabels[(int)$id] : 'No school year';
}

foreach ($subjectRows as $tsId => $ts) {
    $k = tsCardKey($cards, $teacherRows, (int)$ts['teacher_id']);
    if ($k === null) continue;
    $rk = (int)$ts['sy_id'] . '|s' . $tsId;
    $cards[$k]['rows'][$rk] = ['sy_id' => (int)$ts['sy_id'], 'sy_label' => tsSyLabel($syLabels, $ts['sy_id']), 'subject' => $ts['subject'], 'count' => 0, 'total' => 0];
}
foreach ($evalRows as $e) {
    $k = tsCardKey($cards, $teacherRows, (int)$e['teacher_id']);
    if ($k === null) continue;
    if (!empty($e['ts_id'])) {
        $tsId    = (int)$e['ts_id'];
        $subject = isset($subjectRows[$tsId]) ? $subjectRows[$tsId]['subject'] : '(removed subject)';
        $rk      = (int)$e['school_year_id'] . '|s' . $tsId;
    } else {
        $subject = 'All subjects (evaluated before per-subject evaluation)';
        $rk      = (int)$e['school_year_id'] . '|all';
    }
    if (!isset($cards[$k]['rows'][$rk])) {
        $cards[$k]['rows'][$rk] = ['sy_id' => (int)$e['school_year_id'], 'sy_label' => tsSyLabel($syLabels, $e['school_year_id']), 'subject' => $subject, 'count' => 0, 'total' => 0];
    }
    $cards[$k]['rows'][$rk]['count'] += (int)$e['c'];
    $cards[$k]['rows'][$rk]['total'] += (int)$e['total'];
}

foreach ($uniqRows as $u) {
    $k = tsCardKey($cards, $teacherRows, (int)$u['teacher_id']);
    if ($k === null) continue;
    $sy = (int)$u['school_year_id'];
    $cards[$k]['uniq'][$sy] = ($cards[$k]['uniq'][$sy] ?? 0) + (int)$u['u'];
}

/* sort: teachers A–Z; rows newest school year first, then subject */
uasort($cards, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
foreach ($cards as &$cd) {
    uasort($cd['rows'], function ($a, $b) {
        $c = strcmp($b['sy_label'], $a['sy_label']);
        return $c !== 0 ? $c : strcasecmp($a['subject'], $b['subject']);
    });
}
unset($cd);

$syFilterOptions = [];
foreach ($cards as $cd) foreach ($cd['rows'] as $r) $syFilterOptions[$r['sy_label']] = true;
krsort($syFilterOptions);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Teacher Scores — Admin Panel</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
/* ── BASE — identical to the other admin pages ── */
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial; background: #f5f7fb; }

.sidebar {
    width: 230px; height: 100vh; background: #1f2937; position: fixed;
    top: 0; left: 0; color: white; padding-top: 20px;
}
.sidebar-brand {
    display: flex; align-items: center; gap: 12px; padding: 0 20px 16px;
    margin-bottom: 12px; border-bottom: 1px solid #374151;
}
.sidebar-brand .brand-logo {
    width: 44px; height: 44px; border-radius: 50%; background: #fff;
    padding: 3px; flex-shrink: 0; object-fit: contain;
}
.sidebar-brand .brand-text { line-height: 1.3; min-width: 0; }
.sidebar-brand .brand-school {
    font-size: 9.5px; font-weight: bold; color: #d4a843;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.sidebar-brand h2 { padding: 0; margin: 2px 0 0; font-size: 15px; }
.sidebar a {
    display: block; padding: 10px 20px; color: #9ca3af; text-decoration: none;
    font-size: 14px; transition: background 0.15s, color 0.15s;
}
.sidebar a:hover  { background: #374151; color: white; }
.sidebar a.active { background: #374151; color: white; }
.sidebar a.logout { color: #f87171; position: absolute; bottom: 20px; width: 100%; }
.sidebar a.logout:hover { background: #374151; color: #fca5a5; }

.header {
    margin-left: 230px; background: #111827; padding: 20px;
    color: white; font-size: 18px; font-weight: bold;
}
.main { margin-left: 230px; padding: 30px; }

.top-bar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 6px;
}
.top-bar h2 { margin: 0; font-size: 20px; color: #111827; }
.top-bar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sub-note { font-size: 13px; color: #6b7280; margin: 0 0 20px; }

.sc-select, .sc-search {
    padding: 8px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px;
    font-family: Arial; background: #fff; color: #374151; outline: none;
}
.sc-search { width: 240px; }
.sc-select:focus, .sc-search:focus { border-color: #1a7f74; }

.sc-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    margin-bottom: 18px; overflow: hidden;
}
.sc-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid #f0f1f3;
}
.sc-name { font-size: 16px; font-weight: bold; color: #111827; }
.sc-chip {
    font-size: 12px; font-weight: bold; background: #e6f4f2; color: #15665c;
    border-radius: 20px; padding: 4px 12px;
}
.sc-head-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sc-btn {
    background: #f3f4f6; border: 1px solid #d1d5db; color: #374151; font-size: 12px;
    padding: 6px 12px; border-radius: 6px; cursor: pointer; font-family: Arial;
}
.sc-btn:hover { background: #e6f4f2; border-color: #15665c; color: #15665c; }
.sc-table { width: 100%; border-collapse: collapse; }
.sc-table th {
    text-align: left; padding: 10px 18px; font-size: 11px; font-weight: bold;
    text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; background: #f9fafb;
}
.sc-table td { padding: 11px 18px; font-size: 13px; color: #111827; border-top: 1px solid #f3f4f6; }
.sc-table td.num, .sc-table th.num { text-align: right; }
.sc-table td.muted { color: #9ca3af; }
.sc-avg { font-weight: bold; }
.sc-table tr.sc-final td { background: #f0fdf4; font-weight: bold; color: #15665c; border-top: 2px solid #b7ddd8; }
.sc-empty { background:#fff; border:1px solid #ddd; border-radius:10px; padding:50px; text-align:center; color:#9ca3af; font-size:14px; }
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
    <a href="teacher_masterlist.php">🧑‍🏫 View Comments</a>
    <a href="teacher_scores.php" class="active">📈 Teacher Scores</a>
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
    
    Teacher Scores — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <div class="top-bar">
        <h2>Teacher Evaluation Scores</h2>
        <div class="top-bar-right">
            <select id="syFilter" class="sc-select">
                <option value="">All school years</option>
                <?php foreach (array_keys($syFilterOptions) as $lbl): ?>
                <option value="<?= htmlspecialchars($lbl) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="scSearch" class="sc-search" placeholder="Search teacher or subject…">
        </div>
    </div>
    <p class="sub-note">Evaluators = how many students evaluated. Total Points = the sum of their 1–5 ratings (out of 5 per student). Final Score = Total Points ÷ Evaluators, shown per subject and as one final score for the whole school year.</p>

    <?php if (empty($cards)): ?>
        <div class="sc-empty">No teachers yet.</div>
    <?php endif; ?>

    <?php foreach ($cards as $cd):
        $cardEvals = 0;
        foreach ($cd['rows'] as $r) $cardEvals += $r['count'];
    ?>
    <div class="sc-card">
        <div class="sc-card-head">
            <div class="sc-name"><?= htmlspecialchars(guessTeacherTitle($cd['name']) . ' ' . $cd['name']) ?></div>
            <div class="sc-head-right">
                <span class="sc-chip"><?= $cardEvals ?> evaluation<?= $cardEvals === 1 ? '' : 's' ?></span>
                <button type="button" class="sc-btn" onclick="printTeacherScore(this.closest('.sc-card'))">🖨️ Print</button>
                <button type="button" class="sc-btn" onclick="downloadTeacherScorePDF(this.closest('.sc-card'))">📄 Download PDF</button>
            </div>
        </div>
        <?php if (empty($cd['rows'])): ?>
            <table class="sc-table"><tbody><tr><td class="muted">No subjects or evaluations yet.</td></tr></tbody></table>
        <?php else: ?>
        <?php
        /* group the rows by school year so each year ends with its FINAL SCORE */
        $groups = [];
        foreach ($cd['rows'] as $r) {
            $g = $r['sy_label'];
            if (!isset($groups[$g])) $groups[$g] = ['sy_id' => $r['sy_id'], 'rows' => [], 'count' => 0, 'total' => 0];
            $groups[$g]['rows'][]  = $r;
            $groups[$g]['count']  += $r['count'];
            $groups[$g]['total']  += $r['total'];
        }
        ?>
        <table class="sc-table">
            <thead>
                <tr>
                    <th>School Year</th>
                    <th>Subject</th>
                    <th class="num">Evaluators</th>
                    <th class="num">Total Points</th>
                    <th class="num">Final Score (/5)</th>
                    <th class="num">Percentage</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $gLabel => $g): ?>
                <?php foreach ($g['rows'] as $r): ?>
                <tr data-sy="<?= htmlspecialchars($gLabel) ?>">
                    <td><?= htmlspecialchars($r['sy_label']) ?></td>
                    <td><?= htmlspecialchars($r['subject']) ?></td>
                    <td class="num"><?= (int)$r['count'] ?></td>
                    <?php if ($r['count'] > 0): $rAvg = $r['total'] / $r['count']; ?>
                    <td class="num"><?= (int)$r['total'] ?> / <?= (int)$r['count'] * 5 ?></td>
                    <td class="num sc-avg"><?= number_format($rAvg, 2) ?></td>
                    <td class="num"><?= number_format($rAvg / 5 * 100, 1) ?>%</td>
                    <?php else: ?>
                    <td class="num muted">—</td>
                    <td class="num muted">—</td>
                    <td class="num muted">—</td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($g['count'] > 0): $gAvg = $g['total'] / $g['count']; ?>
                <tr class="sc-final" data-sy="<?= htmlspecialchars($gLabel) ?>">
                    <td colspan="2">Final score — SY <?= htmlspecialchars($gLabel) ?> (all subjects)</td>
                    <td class="num"><?= (int)($cd['uniq'][$g['sy_id']] ?? $g['count']) ?> student<?= ($cd['uniq'][$g['sy_id']] ?? $g['count']) == 1 ? '' : 's' ?></td>
                    <td class="num"><?= (int)$g['total'] ?> / <?= (int)$g['count'] * 5 ?></td>
                    <td class="num sc-avg"><?= number_format($gAvg, 2) ?></td>
                    <td class="num"><?= number_format($gAvg / 5 * 100, 1) ?>%</td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div>

<script>
(function () {
    var sy = document.getElementById('syFilter');
    var q  = document.getElementById('scSearch');

    function apply() {
        var y = sy.value;
        var s = q.value.toLowerCase().trim();
        document.querySelectorAll('.sc-card').forEach(function (card) {
            var nameMatch = card.querySelector('.sc-name').textContent.toLowerCase().indexOf(s) !== -1;
            var shown = 0;
            card.querySelectorAll('tbody tr[data-sy]').forEach(function (tr) {
                var okYear = !y || tr.getAttribute('data-sy') === y;
                var okText = !s || nameMatch || tr.textContent.toLowerCase().indexOf(s) !== -1;
                tr.style.display = (okYear && okText) ? '' : 'none';
                if (okYear && okText) shown++;
            });
            var hasRows = card.querySelectorAll('tbody tr[data-sy]').length > 0;
            card.style.display = (!hasRows ? (!y && (nameMatch || !s)) : shown > 0) ? '' : 'none';
        });
    }
    sy.addEventListener('change', apply);
    q.addEventListener('input', apply);
})();

/* ── Print / Download PDF (one teacher) ──
   Reads the teacher card already on the page (all of that teacher's school years and
   subjects) and builds a clean report. Nothing is fetched or changed. */
function scEsc(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function scBuildReport(card) {
    var name  = card.querySelector('.sc-name').textContent.trim();
    var chip  = card.querySelector('.sc-chip').textContent.trim();
    var table = card.querySelector('table.sc-table');
    var thStyle = 'text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#6b7280;border-bottom:2px solid #e5e7eb;';
    var head = '', body = '';

    table.querySelectorAll('tr').forEach(function (tr) {
        var cells = tr.querySelectorAll('th, td');
        if (tr.querySelector('th')) {
            cells.forEach(function (c) {
                var al = c.classList.contains('num') ? 'right' : 'left';
                head += '<th style="' + thStyle + 'text-align:' + al + ';">' + scEsc(c.textContent.trim()) + '</th>';
            });
        } else {
            var isFinal = tr.classList.contains('sc-final');
            var rowCss = isFinal ? 'background:#f0fdf4;font-weight:bold;color:#15665c;' : '';
            var html = '';
            cells.forEach(function (c) {
                var al   = c.classList.contains('num') ? 'right' : 'left';
                var span = c.getAttribute('colspan') ? ' colspan="' + c.getAttribute('colspan') + '"' : '';
                html += '<td' + span + ' style="padding:9px 12px;border-bottom:1px solid #eee;text-align:' + al + ';">' + scEsc(c.textContent.trim()) + '</td>';
            });
            body += '<tr style="' + rowCss + '">' + html + '</tr>';
        }
    });

    var when = new Date().toLocaleString();
    return {
        name: name,
        title: 'Teacher Evaluation Scores — ' + name,
        inner:
            '<h1 style="font-size:19px;margin:0 0 4px;">Teacher Evaluation Scores — ' + scEsc(name) + '</h1>' +
            '<div style="color:#6b7280;font-size:13px;margin-bottom:6px;">' + scEsc(chip) + ' &nbsp;•&nbsp; Nueva Ecija University of Science and Technology</div>' +
            '<div style="color:#6b7280;font-size:12px;margin-bottom:20px;">Generated ' + scEsc(when) + '. Final Score = Total Points ÷ Evaluators (out of 5).</div>' +
            '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>'
    };
}

function printTeacherScore(card) {
    var r = scBuildReport(card);
    var w = window.open('', '_blank', 'width=900,height=900');
    w.document.write('<html><head><title>' + scEsc(r.title) + '</title></head>' +
        '<body style="font-family:Arial,sans-serif;padding:34px;color:#111827;">' + r.inner + '</body></html>');
    w.document.close();
    w.focus();
    w.print();
}

function downloadTeacherScorePDF(card) {
    var r   = scBuildReport(card);
    var box = document.createElement('div');
    /* fixed width = the same layout every time, whatever the screen size */
    box.style.width      = '1060px';
    box.style.boxSizing  = 'border-box';
    box.style.padding    = '34px';
    box.style.background = '#ffffff';
    box.style.fontFamily = 'Arial, sans-serif';
    box.style.color      = '#111827';
    box.innerHTML        = r.inner;
    html2pdf().set({
        margin: [20, 0, 20, 0],
        filename: 'Teacher_Scores_' + r.name.replace(/[^a-zA-Z0-9]+/g, '_') + '.pdf',
        /* scrollX/scrollY = 0: without this, a card lower on the page (e.g. the 2nd teacher)
           is captured with the page's scroll offset and the PDF comes out cut / wrong */
        html2canvas: { scale: 2, scrollX: 0, scrollY: 0, backgroundColor: '#ffffff', windowWidth: 1100 },
        jsPDF: { unit: 'pt', format: 'a4', orientation: 'landscape' },
        pagebreak: { mode: ['css', 'legacy'], avoid: 'tr' }
    }).from(box).save();
}
</script>

</body>
</html>