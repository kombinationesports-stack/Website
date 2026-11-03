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

require_once __DIR__ . '/recycle_bin_helper.php';
recycleBinEnsureTable($conn);

/* ================= RESTORE / DELETE PERMANENTLY (POST, then redirect) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = null;

    if (isset($_POST['restore_item'])) {
        [$ok, $msg] = recycleBinRestore($conn, intval($_POST['restore_item']));
        $flash = [$ok, $ok ? "✅ " . $msg : "❌ " . $msg];
    } elseif (isset($_POST['purge_item'])) {
        [$ok, $msg] = recycleBinPurge($conn, intval($_POST['purge_item']));
        $flash = [$ok, $ok ? "🗑️ " . $msg : "❌ " . $msg];
    }

    if ($flash !== null) { $_SESSION['recycle_flash'] = $flash; }
    $back = 'recycle_bin.php';
    if (isset($_GET['view']) || isset($_GET['type'])) {
        $back .= '?' . http_build_query(array_filter(['view' => $_GET['view'] ?? '', 'type' => $_GET['type'] ?? ''], 'strlen'));
    }
    header("Location: " . $back);
    exit;
}

$flash = $_SESSION['recycle_flash'] ?? null;
unset($_SESSION['recycle_flash']);

/* ================= FILTERS ================= */
$view   = (($_GET['view'] ?? 'deleted') === 'restored') ? 'restored' : 'deleted';
$fType  = trim($_GET['type'] ?? '');
if (!in_array($fType, ['teacher', 'subject', 'question'], true)) $fType = '';

$typeLabels = ['teacher' => 'Teacher', 'subject' => 'Subject', 'question' => 'Question'];

/* ================= COUNTS (for the tabs) ================= */
$cntDeleted = 0; $cntRestored = 0;
try {
    $cr = $conn->query("SELECT status, COUNT(*) AS c FROM recycle_bin GROUP BY status");
    while ($cr && ($c = $cr->fetch_assoc())) {
        if ($c['status'] === 'deleted')  $cntDeleted  = (int)$c['c'];
        if ($c['status'] === 'restored') $cntRestored = (int)$c['c'];
    }
} catch (Throwable $e) { /* ignore */ }

/* ================= LIST ================= */
$records = [];
try {
    $sql    = "SELECT rb.id, rb.item_type, rb.item_label, rb.summary, rb.preview, rb.deleted_by, rb.deleted_at,
                      rb.restored_at, rb.restored_by, sy.label AS sy_label
               FROM recycle_bin rb
               LEFT JOIN school_years sy ON sy.id = rb.school_year_id
               WHERE rb.status = ?";
    $types  = "s";
    $params = [$view];
    if ($fType !== '') { $sql .= " AND rb.item_type = ?"; $types .= "s"; $params[] = $fType; }
    $sql .= ($view === 'restored' ? " ORDER BY rb.restored_at DESC, rb.id DESC" : " ORDER BY rb.deleted_at DESC, rb.id DESC") . " LIMIT 500";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $records[] = $row; }
    $stmt->close();
} catch (Throwable $e) {
    $records = [];
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Recycle Bin — Admin Panel</title>
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

.message-banner {
    padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;
    background: #e6f4f2; color: #15665c; border: 1px solid #b7ddd8;
}
.message-banner.err { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

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
.filter-bar a.clear-filter { color: #1a7f74; text-decoration: none; font-weight: bold; }
.filter-bar a.clear-filter:hover { text-decoration: underline; }

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
.hist-table td { padding: 12px 16px; font-size: 13px; color: #111827; vertical-align: top; }
.hist-table td.muted { color: #9ca3af; }
.hist-table td.item { max-width: 380px; word-break: break-word; line-height: 1.5; }

.type-badge {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: bold; background: #e6f4f2; color: #15665c; white-space: nowrap;
}
.type-badge.subject  { background: #fef3c7; color: #92400e; }
.type-badge.question { background: #e0e7ff; color: #3730a3; }

details.contents { margin-top: 6px; font-size: 12px; color: #374151; }
details.contents summary { cursor: pointer; color: #1a7f74; font-weight: bold; }
details.contents ul { margin: 6px 0 0; padding-left: 18px; max-height: 180px; overflow-y: auto; }

.act-form { display: inline-block; margin: 0 4px 4px 0; }
.btn-restore, .btn-purge {
    font-size: 12px; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-family: Arial;
}
.btn-restore { background: #15665c; color: #fff; border: none; }
.btn-restore:hover { background: #0f4a42; }
.btn-purge { background: #fff; color: #b91c1c; border: 1px solid #fca5a5; }
.btn-purge:hover { background: #fee2e2; border-color: #b91c1c; }

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
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php" class="active">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
   
    Recycle Bin — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <?php if ($flash): ?>
    <div class="message-banner<?= $flash[0] ? '' : ' err' ?>"><?= htmlspecialchars($flash[1]) ?></div>
    <?php endif; ?>

    <div class="view-toggle">
        <a href="recycle_bin.php?view=deleted"<?= $view === 'deleted' ? ' class="on"' : '' ?>>🗑️ In Recycle Bin (<?= $cntDeleted ?>)</a>
        <a href="recycle_bin.php?view=restored"<?= $view === 'restored' ? ' class="on"' : '' ?>>♻️ Restored (<?= $cntRestored ?>)</a>
    </div>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <label for="fType">Type:</label>
        <select name="type" id="fType" onchange="this.form.submit()">
            <option value="">All types</option>
            <?php foreach ($typeLabels as $k => $lbl): ?>
            <option value="<?= $k ?>"<?= $fType === $k ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fType !== ''): ?>
        <a href="recycle_bin.php?view=<?= htmlspecialchars($view) ?>" class="clear-filter">✕ Clear filters</a>
        <?php endif; ?>
    </form>

    <div class="top-bar">
        <div>
            <h2><?= $view === 'restored' ? 'Restored Items' : 'Deleted Items' ?></h2>
            <div class="count-chip">
                <?= count($records) ?> item<?= count($records) !== 1 ? 's' : '' ?>
                <?= $view === 'deleted' ? '— restoring puts the data back exactly where it was' : '' ?>
            </div>
        </div>
        <?php if (!empty($records)): ?>
        <input type="text" class="search-box" id="binSearch" placeholder="Search name, subject or who deleted it…">
        <?php endif; ?>
    </div>

    <?php if (empty($records)): ?>

        <div class="empty-state">
            <?= $view === 'restored' ? 'Nothing has been restored yet.' : 'The Recycle Bin is empty. Deleted teachers, subjects (with their students) and questions will show up here.' ?>
        </div>

    <?php else: ?>

        <div style="width:100%;overflow-x:auto;">
        <table class="hist-table" id="binTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Item</th>
                    <th>School Year</th>
                    <?php if ($view === 'deleted'): ?>
                    <th>Deleted By</th>
                    <th>Deleted On</th>
                    <th>Action</th>
                    <?php else: ?>
                    <th>Deleted On</th>
                    <th>Restored By</th>
                    <th>Restored On</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r):
                    $dts = strtotime($r['deleted_at']);
                    $rts = $r['restored_at'] ? strtotime($r['restored_at']) : false;
                    $prev = $r['preview'] ? json_decode($r['preview'], true) : [];
                    if (!is_array($prev)) $prev = [];
                    $t = $r['item_type'];
                ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td><span class="type-badge <?= htmlspecialchars($t) ?>"><?= htmlspecialchars($typeLabels[$t] ?? ucfirst($t)) ?></span></td>
                    <td class="item">
                        <strong><?= htmlspecialchars($r['item_label']) ?></strong>
                        <?php if (!empty($r['summary'])): ?>
                        <div style="color:#6b7280;font-size:12px;"><?= htmlspecialchars($r['summary']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($prev)): ?>
                        <details class="contents">
                            <summary>View contents (<?= count($prev) ?>)</summary>
                            <ul>
                                <?php foreach ($prev as $line): ?>
                                <li><?= htmlspecialchars($line) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td class="<?= $r['sy_label'] ? '' : 'muted' ?>"><?= $r['sy_label'] ? htmlspecialchars($r['sy_label']) : '—' ?></td>

                    <?php if ($view === 'deleted'): ?>
                    <td style="font-weight:bold;"><?= htmlspecialchars($r['deleted_by']) ?></td>
                    <td><?= $dts ? htmlspecialchars(date('M d, Y h:i A', $dts)) : '—' ?></td>
                    <td>
                        <form method="POST" class="act-form" onsubmit="return confirm('Restore this item? The deleted data will be put back.');">
                            <input type="hidden" name="restore_item" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn-restore">♻️ Restore</button>
                        </form>
                        <form method="POST" class="act-form" onsubmit="return confirm('Delete this PERMANENTLY? It can no longer be restored.');">
                            <input type="hidden" name="purge_item" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn-purge">Delete permanently</button>
                        </form>
                    </td>
                    <?php else: ?>
                    <td><?= $dts ? htmlspecialchars(date('M d, Y h:i A', $dts)) : '—' ?></td>
                    <td style="font-weight:bold;"><?= htmlspecialchars((string)$r['restored_by']) ?></td>
                    <td><?= $rts ? htmlspecialchars(date('M d, Y h:i A', $rts)) : '—' ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php endif; ?>

</div>

<script>
(function () {
    var box = document.getElementById('binSearch');
    if (!box) return;
    box.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('#binTable tbody tr').forEach(function (tr) {
            tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
        });
    });
})();
</script>

</body>
</html>
