<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';

// Admin-only page
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

$conn = new mysqli("localhost", "root", "", "school_system");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/login_history_helper.php';
loginHistoryEnsureTable($conn);

$type      = (($_GET['type'] ?? 'student') === 'admin') ? 'admin' : 'student';
$isStudent = ($type === 'student');
$pageTitle = $isStudent ? 'Student Login History' : 'Admin Login History';

/* NEW: filter (student view only) — school year */
$fSy  = $isStudent ? trim($_GET['sy']  ?? '') : '';

$syOptions  = [];
if ($isStudent) {
    try {
        $r1 = $conn->query("SELECT DISTINCT school_year FROM login_history WHERE role = 'student' AND school_year IS NOT NULL AND school_year <> '' ORDER BY school_year DESC");
        while ($r1 && ($o = $r1->fetch_assoc())) { $syOptions[] = $o['school_year']; }
    } catch (Throwable $e) {
        // filters just stay empty
    }
}

$records = [];
try {
    $sql    = "SELECT user_id, user_name, course, section, school_year, device, ip_address, logged_in_at FROM login_history WHERE role = ?";
    $types  = "s";
    $params = [$type];
    if ($fSy !== '')  { $sql .= " AND school_year = ?";                       $types .= "s"; $params[] = $fSy; }
    $sql .= " ORDER BY logged_in_at DESC, id DESC LIMIT 1000";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $records = [];
}

function lhShowIp($ip) {
    if ($ip === null || $ip === '') return '—';
    return ($ip === '::1' || $ip === '127.0.0.1') ? 'Localhost' : $ip;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?> — Admin Panel</title>
<style>
* { box-sizing: border-box; }
body { margin:0; font-family:Arial; background:#f5f7fb; }

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
    flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
}
.top-bar h2 { margin: 0; font-size: 20px; color: #111827; }
.count-chip { font-size: 13px; color: #6b7280; margin-top: 4px; }

.search-box {
    padding: 9px 14px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; width: 260px; background: #fff; outline: none; font-family: Arial;
}
.search-box:focus { border-color: #1a7f74; }

.hist-table {
    width: 100%; background: #fff; border: 1px solid #ddd; border-radius: 10px;
    border-collapse: collapse; overflow: hidden;
}
.hist-table thead tr { background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
.hist-table th {
    text-align: left; padding: 12px 16px; font-size: 11px; font-weight: bold;
    text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280;
}
.hist-table tbody tr { border-bottom: 1px solid #f3f4f6; }
.hist-table tbody tr:last-child { border-bottom: none; }
.hist-table tbody tr:hover { background: #f9fafb; }
.hist-table td { padding: 12px 16px; font-size: 13px; color: #111827; vertical-align: middle; }
.hist-table td.muted { color: #9ca3af; }

.empty-state {
    background: #fff; border: 1px solid #ddd; border-radius: 10px;
    padding: 50px; text-align: center; color: #9ca3af; font-size: 14px;
}

.view-toggle {
    display: inline-flex; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 10px; padding: 4px; gap: 4px; margin-bottom: 18px;
}
.view-toggle a {
    padding: 8px 18px; font-size: 13px; font-weight: bold; color: #6b7280;
    text-decoration: none; border-radius: 7px; transition: all 0.15s;
}
.view-toggle a:hover { background: #f3f4f6; color: #111827; }
.view-toggle a.on { background: #1f2937; color: #fff; }

.filter-bar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
    margin-bottom: 18px; font-size: 13px; color: #6b7280;
}
.filter-bar select {
    padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; background: #fff; font-family: Arial; outline: none; color: #111827;
}
.filter-bar select:focus { border-color: #1a7f74; }
.filter-bar a.clear-filter { color: #1a7f74; text-decoration: none; font-weight: bold; }
.filter-bar a.clear-filter:hover { text-decoration: underline; }
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
    <a href="teacher_scores.php">📈 Teacher Scores</a>
    <a href="login_history.php" class="active">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
    
    <?= htmlspecialchars($pageTitle) ?> — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <div class="view-toggle">
        <a href="login_history.php?type=student"<?= $isStudent ? ' class="on"' : '' ?>>🎓 Student Login History</a>
        <a href="login_history.php?type=admin"<?= !$isStudent ? ' class="on"' : '' ?>>🔐 Admin Login History</a>
    </div>

    <?php if ($isStudent): ?>
    <form method="GET" class="filter-bar">
        <input type="hidden" name="type" value="student">
        <label for="fSy">School Year:</label>
        <select name="sy" id="fSy" onchange="this.form.submit()">
            <option value="">All school years</option>
            <?php foreach ($syOptions as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>"<?= $fSy === $o ? ' selected' : '' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fSy !== ''): ?>
        <a href="login_history.php?type=student" class="clear-filter">✕ Clear filters</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="top-bar">
        <div>
            <h2><?= htmlspecialchars($pageTitle) ?></h2>
            <div class="count-chip"><?= count($records) ?> login<?= count($records) !== 1 ? 's' : '' ?> recorded (latest first)</div>
        </div>
        <?php if (!empty($records)): ?>
        <input type="text" class="search-box" id="histSearch" placeholder="Search <?= $isStudent ? 'student no., name, section or year' : 'username or device' ?>…">
        <?php endif; ?>
    </div>

    <?php if (empty($records)): ?>

        <div class="empty-state"><?= ($fSy !== '') ? 'No login records match the selected filters.' : 'No login records yet. Records appear here starting from the next time someone logs in.' ?></div>

    <?php else: ?>

        <div style="width:100%;overflow-x:auto;">
        <table class="hist-table" id="histTable">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($isStudent): ?>
                    <th>Student No.</th>
                    <th>Student Name</th>
                    <th>Section</th>
                    <th>School Year</th>
                    <?php else: ?>
                    <th>Username</th>
                    <?php endif; ?>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Device</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r):
                    $ts = strtotime($r['logged_in_at']);
                ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td<?= $isStudent ? '' : ' style="font-weight:bold;"' ?>><?= htmlspecialchars($r['user_id']) ?></td>
                    <?php if ($isStudent): ?>
                    <td style="font-weight:bold;"><?= $r['user_name'] ? htmlspecialchars($r['user_name']) : '<span class="muted">—</span>' ?></td>
                    <?php
                        $secLabel = trim(($r['course'] ?? '') . ' ' . ($r['section'] ?? ''));
                    ?>
                    <td><?= $secLabel !== '' ? htmlspecialchars($secLabel) : '<span class="muted">—</span>' ?></td>
                    <td><?= !empty($r['school_year']) ? htmlspecialchars($r['school_year']) : '<span class="muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td><?= $ts ? htmlspecialchars(date('M d, Y', $ts)) : '—' ?></td>
                    <td><?= $ts ? htmlspecialchars(date('h:i:s A', $ts)) : '—' ?></td>
                    <td class="<?= $r['device'] ? '' : 'muted' ?>"><?= $r['device'] ? htmlspecialchars($r['device']) : '—' ?></td>
                    <td><?= htmlspecialchars(lhShowIp($r['ip_address'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php endif; ?>

</div>

<script>
(function () {
    var box = document.getElementById('histSearch');
    if (!box) return;
    box.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('#histTable tbody tr').forEach(function (tr) {
            tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
        });
    });
})();
</script>

</body>
</html>