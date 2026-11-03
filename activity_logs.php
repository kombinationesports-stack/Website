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

require_once __DIR__ . '/admin_activity_helper.php';
activityLogEnsureTable($conn);

/* ── Filters (all optional) ── */
$fCat  = trim($_GET['cat']  ?? '');
$fFrom = trim($_GET['from'] ?? '');
$fTo   = trim($_GET['to']   ?? '');
if ($fFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) $fFrom = '';
if ($fTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   $fTo   = '';

$catOptions = [];
try {
    $r1 = $conn->query("SELECT DISTINCT category FROM admin_activity_logs ORDER BY category ASC");
    while ($r1 && ($o = $r1->fetch_assoc())) { $catOptions[] = $o['category']; }
} catch (Throwable $e) {
    // filter just stays empty
}

$records = [];
try {
    $sql    = "SELECT admin_name, category, action, details, status, ip_address, device, created_at FROM admin_activity_logs WHERE 1=1";
    $types  = "";
    $params = [];
    if ($fCat  !== '') { $sql .= " AND category = ?";            $types .= "s"; $params[] = $fCat; }
    if ($fFrom !== '') { $sql .= " AND created_at >= ?";         $types .= "s"; $params[] = $fFrom . ' 00:00:00'; }
    if ($fTo   !== '') { $sql .= " AND created_at <= ?";         $types .= "s"; $params[] = $fTo   . ' 23:59:59'; }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 1000";

    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $records = [];
}

$hasFilter = ($fCat !== '' || $fFrom !== '' || $fTo !== '');

function alShowIp($ip) {
    if ($ip === null || $ip === '') return '—';
    return ($ip === '::1' || $ip === '127.0.0.1') ? 'Localhost' : $ip;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Activity Logs — Admin Panel</title>
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

.filter-bar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
    margin-bottom: 18px; font-size: 13px; color: #6b7280;
}
.filter-bar select, .filter-bar input[type=date] {
    padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; background: #fff; font-family: Arial; outline: none; color: #111827;
}
.filter-bar select:focus, .filter-bar input[type=date]:focus { border-color: #1a7f74; }
.filter-bar button {
    padding: 8px 16px; border: none; border-radius: 8px; background: #1f2937;
    color: #fff; font-size: 13px; cursor: pointer;
}
.filter-bar button:hover { background: #111827; }
.filter-bar a.clear-filter { color: #1a7f74; text-decoration: none; font-weight: bold; }
.filter-bar a.clear-filter:hover { text-decoration: underline; }

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
.hist-table td { padding: 12px 16px; font-size: 13px; color: #111827; vertical-align: top; }
.hist-table td.muted { color: #9ca3af; }
.hist-table td.details { max-width: 380px; line-height: 1.5; word-break: break-word; }

.cat-badge {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: bold; background: #e6f4f2; color: #15665c; white-space: nowrap;
}
.st-ok     { color: #15803d; font-weight: bold; }
.st-failed { color: #b91c1c; font-weight: bold; }

.empty-state {
    background: #fff; border: 1px solid #ddd; border-radius: 10px;
    padding: 50px; text-align: center; color: #9ca3af; font-size: 14px;
}
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
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php" class="active">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
    
    Activity Logs — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <form method="GET" class="filter-bar">
        <label for="fCat">Category:</label>
        <select name="cat" id="fCat" onchange="this.form.submit()">
            <option value="">All categories</option>
            <?php foreach ($catOptions as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>"<?= $fCat === $o ? ' selected' : '' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="fFrom">From:</label>
        <input type="date" name="from" id="fFrom" value="<?= htmlspecialchars($fFrom) ?>">
        <label for="fTo">To:</label>
        <input type="date" name="to" id="fTo" value="<?= htmlspecialchars($fTo) ?>">
        <button type="submit">Apply</button>
        <?php if ($hasFilter): ?>
        <a href="activity_logs.php" class="clear-filter">✕ Clear filters</a>
        <?php endif; ?>
    </form>

    <div class="top-bar">
        <div>
            <h2>Admin Activity Logs</h2>
            <div class="count-chip"><?= count($records) ?> activit<?= count($records) !== 1 ? 'ies' : 'y' ?> recorded (latest first, max 1000)</div>
        </div>
        <?php if (!empty($records)): ?>
        <input type="text" class="search-box" id="histSearch" placeholder="Search admin, action or details…">
        <?php endif; ?>
    </div>

    <?php if (empty($records)): ?>

        <div class="empty-state"><?= $hasFilter ? 'No activity matches the selected filters.' : 'No activity recorded yet. Admin actions appear here starting from the next time something is done.' ?></div>

    <?php else: ?>

        <div style="width:100%;overflow-x:auto;">
        <table class="hist-table" id="histTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Admin</th>
                    <th>Category</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>Status</th>
                    <th>Device</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r):
                    $ts = strtotime($r['created_at']);
                ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td><?= $ts ? htmlspecialchars(date('M d, Y', $ts)) : '—' ?></td>
                    <td><?= $ts ? htmlspecialchars(date('h:i:s A', $ts)) : '—' ?></td>
                    <td style="font-weight:bold;"><?= htmlspecialchars($r['admin_name']) ?></td>
                    <td><span class="cat-badge"><?= htmlspecialchars($r['category']) ?></span></td>
                    <td style="font-weight:bold;"><?= htmlspecialchars($r['action']) ?></td>
                    <td class="details<?= ($r['details'] === null || $r['details'] === '') ? ' muted' : '' ?>"><?= ($r['details'] !== null && $r['details'] !== '') ? htmlspecialchars($r['details']) : '—' ?></td>
                    <td class="<?= $r['status'] === 'failed' ? 'st-failed' : 'st-ok' ?>"><?= $r['status'] === 'failed' ? 'Failed' : 'Success' ?></td>
                    <td class="<?= $r['device'] ? '' : 'muted' ?>"><?= $r['device'] ? htmlspecialchars($r['device']) : '—' ?></td>
                    <td><?= htmlspecialchars(alShowIp($r['ip_address'])) ?></td>
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