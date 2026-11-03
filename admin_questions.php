<?php
session_start();
require_once __DIR__ . '/admin_session_guard.php';
require_once __DIR__ . '/admin_activity_helper.php';
require_once __DIR__ . '/recycle_bin_helper.php';

$conn = new mysqli("localhost", "root", "", "school_system");

/* ================= CREATE evaluation_questions IF NOT EXISTS =================
   Additive only — does not touch any other table. Mirrors the same
   idempotent "CREATE TABLE IF NOT EXISTS" pattern already used in
   admin_backend.php. */
$conn->query("CREATE TABLE IF NOT EXISTS evaluation_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

/* ================= SEED WITH EXISTING 10 QUESTIONS (only if table is empty) =================
   Same wording already used in Student_dashboard.php, so nothing changes
   for students until an admin actually adds or edits a question here. */
$seedCheck = $conn->query("SELECT COUNT(*) AS c FROM evaluation_questions");
$seedRow   = $seedCheck ? $seedCheck->fetch_assoc() : ['c' => 0];
if ((int)$seedRow['c'] === 0) {
    $defaultQuestions = [
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
    $seedStmt = $conn->prepare("INSERT INTO evaluation_questions (question_text, sort_order) VALUES (?, ?)");
    foreach ($defaultQuestions as $i => $qText) {
        $order = $i + 1;
        $seedStmt->bind_param("si", $qText, $order);
        $seedStmt->execute();
    }
    $seedStmt->close();
}

$message = "";

/* ================= ADD QUESTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $newQuestionText = trim($_POST['question_text'] ?? '');

    if ($newQuestionText !== '') {
        $maxRow   = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM evaluation_questions")->fetch_assoc();
        $nextOrder = (int)$maxRow['m'] + 1;

        $insQ = $conn->prepare("INSERT INTO evaluation_questions (question_text, sort_order) VALUES (?, ?)");
        $insQ->bind_param("si", $newQuestionText, $nextOrder);
        $insQ->execute();
        $insQ->close();

        $message = "✓ Question added.";
        logAdminActivity($conn, 'Questioner', 'Added evaluation question', "\"" . $newQuestionText . "\"");
    } else {
        $message = "❌ Question text cannot be empty.";
    }
}

/* ================= EDIT QUESTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    $editId          = intval($_POST['question_id'] ?? 0);
    $editQuestionText = trim($_POST['question_text'] ?? '');

    if ($editId > 0 && $editQuestionText !== '') {
        $oldQForLog = '';
        $oldQStmt = $conn->prepare("SELECT question_text FROM evaluation_questions WHERE id = ?");
        $oldQStmt->bind_param("i", $editId);
        $oldQStmt->execute();
        $oldQRow = $oldQStmt->get_result()->fetch_assoc();
        $oldQStmt->close();
        if ($oldQRow) $oldQForLog = $oldQRow['question_text'];

        $updQ = $conn->prepare("UPDATE evaluation_questions SET question_text = ? WHERE id = ?");
        $updQ->bind_param("si", $editQuestionText, $editId);
        $updQ->execute();
        $updQ->close();

        $message = "✓ Question updated.";
        logAdminActivity($conn, 'Questioner', 'Edited evaluation question', "\"" . $oldQForLog . "\" → \"" . $editQuestionText . "\"");
    } else {
        $message = "❌ Question text cannot be empty.";
    }
}

/* ================= DELETE QUESTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $delId = intval($_POST['question_id'] ?? 0);

    if ($delId > 0) {
        $delQTextForLog = '';
        $delQSel = $conn->prepare("SELECT question_text FROM evaluation_questions WHERE id = ?");
        $delQSel->bind_param("i", $delId);
        $delQSel->execute();
        $delQRow = $delQSel->get_result()->fetch_assoc();
        $delQSel->close();
        if ($delQRow) $delQTextForLog = $delQRow['question_text'];
        recycleBinSaveQuestion($conn, $delId);

        $delQ = $conn->prepare("DELETE FROM evaluation_questions WHERE id = ?");
        $delQ->bind_param("i", $delId);
        $delQ->execute();
        $deleted = $delQ->affected_rows > 0;
        $delQ->close();

        $message = $deleted ? "✓ Question deleted." : "❌ Question not found.";
        if ($deleted) {
            logAdminActivity($conn, 'Questioner', 'Deleted evaluation question', "\"" . $delQTextForLog . "\"");
        }
    } else {
        $message = "❌ Invalid question.";
    }
}

/* ================= FETCH ALL QUESTIONS ================= */
$questions = [];
$qRes = $conn->query("SELECT id, question_text, sort_order, is_active FROM evaluation_questions ORDER BY sort_order ASC, id ASC");
if ($qRes) {
    while ($row = $qRes->fetch_assoc()) {
        $questions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Questioner — Admin Panel</title>
<style>
/* ── BASE — identical to admin.php / admin_analytics.php ── */
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
    max-width: 900px;
}

.message-banner {
    background: #e6f4f2;
    color: #15665c;
    border: 1px solid #b7ddd8;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.questions-panel {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 22px;
    margin-bottom: 22px;
}

.questions-panel h2 {
    margin: 0 0 4px;
    font-size: 18px;
    color: #111827;
}

.questions-panel .sub {
    margin: 0 0 18px;
    font-size: 13px;
    color: #6b7280;
}

.q-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid #f0f1f3;
}

.q-row:last-child { border-bottom: none; }

.q-num-badge {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #15665c;
    color: #fff;
    font-size: 12px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 2px;
}

.q-text {
    flex: 1;
    font-size: 14px;
    color: #1f2937;
    line-height: 1.5;
    padding-top: 3px;
}

.q-edit-btn {
    flex-shrink: 0;
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    color: #374151;
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
}

.q-edit-btn:hover { background: #e6f4f2; border-color: #15665c; color: #15665c; }

.q-delete-form { flex-shrink: 0; margin: 0; }
.q-delete-btn {
    background: #fff;
    border: 1px solid #fca5a5;
    color: #b91c1c;
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
}
.q-delete-btn:hover { background: #fee2e2; border-color: #b91c1c; }

.q-edit-form { flex: 1; display: none; }
.q-edit-form.open { display: block; }
.q-edit-form textarea {
    width: 100%;
    min-height: 60px;
    font-family: Arial;
    font-size: 14px;
    padding: 8px 10px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    resize: vertical;
}
.q-edit-actions { margin-top: 8px; display: flex; gap: 8px; }
.q-edit-actions button {
    font-size: 12px;
    padding: 6px 14px;
    border-radius: 6px;
    cursor: pointer;
    border: none;
}
.q-save-btn { background: #15665c; color: #fff; }
.q-save-btn:hover { background: #0f4a42; }
.q-cancel-btn { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db !important; }

/* ── ADD QUESTION (bottom) ── */
.add-question-panel {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 22px;
}

.add-question-panel h2 {
    margin: 0 0 4px;
    font-size: 16px;
    color: #111827;
}

.add-question-panel .sub {
    margin: 0 0 14px;
    font-size: 13px;
    color: #6b7280;
}

.add-question-panel textarea {
    width: 100%;
    min-height: 70px;
    font-family: Arial;
    font-size: 14px;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    resize: vertical;
    margin-bottom: 12px;
}

.btn-add-question {
    background: #1f2937;
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
}
.btn-add-question:hover { background: #111827; }

/* ── CONFIRM NOTIF (edit / delete question) ── */
.dc-overlay {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(17,24,39,0.55); z-index: 2000; align-items: center; justify-content: center;
}
.dc-overlay.open { display: flex; }
.dc-box {
    background: #fff; border-radius: 14px; width: 92%; max-width: 400px;
    padding: 26px 24px 22px; box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.dc-icon {
    width: 46px; height: 46px; border-radius: 50%; background: #fee2e2;
    display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 12px;
}
.dc-box h3 { margin: 0 0 6px; font-size: 18px; color: #111827; }
.dc-name { margin: 0 0 8px; font-size: 14px; font-weight: bold; color: #b91c1c; word-break: break-word; }
.dc-msg { margin: 0 0 20px; font-size: 13px; line-height: 1.5; color: #6b7280; }
.dc-actions { display: flex; gap: 10px; }
.dc-actions button {
    flex: 1; padding: 10px; border-radius: 8px; font-size: 14px; cursor: pointer;
    border: 1px solid #e5e7eb; background: #fff; color: #374151;
}
.dc-actions button:hover { background: #f3f4f6; }
.dc-actions .dc-confirm { background: #dc2626; border-color: #dc2626; color: #fff; }
.dc-actions .dc-confirm:hover { background: #b91c1c; }
.dc-actions .dc-confirm-ok { background: #15665c; border-color: #15665c; color: #fff; }
.dc-actions .dc-confirm-ok:hover { background: #115248; }
</style>
</head>
<body>

<!-- ── SIDEBAR (matches admin.php / admin_analytics.php, one new link added) ── -->
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
    <a href="admin_questions.php" class="active">🗒️ Questioner</a>
    <a href="teacher_masterlist.php">🧑‍🏫 View Comments</a>
    <a href="teacher_scores.php">📈 Teacher Scores</a>
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">

    Questioner — Nueva Ecija University of Science and Technology
</div>

<!-- ── MAIN ── -->
<div class="main">

    <?php if (!empty($message)): ?>
    <div class="message-banner"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="questions-panel">
        <h2>Evaluation Questions</h2>
        <p class="sub">These are the questions students answer when evaluating a teacher. Editing a question's wording here updates it for future evaluations.</p>

        <?php if (empty($questions)): ?>
            <p style="font-size:13px;color:#9ca3af;">No questions yet — add one below.</p>
        <?php else: ?>
            <?php foreach ($questions as $i => $q): ?>
            <div class="q-row">
                <div class="q-num-badge"><?= $i + 1 ?></div>

                <div class="q-text" id="qView_<?= $q['id'] ?>"><?= htmlspecialchars($q['question_text']) ?></div>
                <button type="button" class="q-edit-btn" id="qEditBtn_<?= $q['id'] ?>" onclick="showEditQuestion(<?= $q['id'] ?>)">Edit</button>

                <form method="POST" class="q-delete-form" id="qDelForm_<?= $q['id'] ?>" onsubmit="return askQuestionConfirm(this, 'delete');">
                    <input type="hidden" name="delete_question" value="1">
                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                    <button type="submit" class="q-delete-btn">Delete</button>
                </form>

                <form method="POST" class="q-edit-form" id="qEditForm_<?= $q['id'] ?>" onsubmit="return askQuestionConfirm(this, 'edit');">
                    <input type="hidden" name="edit_question" value="1">
                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                    <textarea name="question_text" required><?= htmlspecialchars($q['question_text']) ?></textarea>
                    <div class="q-edit-actions">
                        <button type="submit" class="q-save-btn">Save</button>
                        <button type="button" class="q-cancel-btn" onclick="cancelEditQuestion(<?= $q['id'] ?>)">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── ADD QUESTION (bottom, as requested) ── -->
    <div class="add-question-panel">
        <h2>Add Question</h2>
        <p class="sub">Adds a new question to the end of the evaluation form.</p>
        <form method="POST">
            <input type="hidden" name="add_question" value="1">
            <textarea name="question_text" required placeholder="e.g. The teacher provides clear grading criteria."></textarea>
            <button type="submit" class="btn-add-question">➕ Add Question</button>
        </form>
    </div>

</div>

<script>
function showEditQuestion(id) {
    document.getElementById('qView_' + id).style.display    = 'none';
    document.getElementById('qEditBtn_' + id).style.display  = 'none';
    document.getElementById('qDelForm_' + id).style.display  = 'none';
    document.getElementById('qEditForm_' + id).classList.add('open');
}
function cancelEditQuestion(id) {
    document.getElementById('qView_' + id).style.display    = 'block';
    document.getElementById('qEditBtn_' + id).style.display  = 'inline-block';
    document.getElementById('qDelForm_' + id).style.display  = 'block';
    document.getElementById('qEditForm_' + id).classList.remove('open');
}
</script>


<!-- ── EDIT / DELETE QUESTION CONFIRM NOTIF ── -->
<div class="dc-overlay" id="qConfirmModal">
    <div class="dc-box">
        <div class="dc-icon" id="qcIcon">⚠️</div>
        <h3 id="qcTitle"></h3>
        <div class="dc-name" id="qcName"></div>
        <p class="dc-msg" id="qcMsg"></p>
        <div class="dc-actions">
            <button type="button" onclick="closeQConfirm()">Cancel</button>
            <button type="button" id="qcOk" onclick="proceedQConfirm()">Yes</button>
        </div>
    </div>
</div>

<script>
/* ── "Are you sure?" notif before editing / deleting a question ── */
var qcPendingForm = null;
function askQuestionConfirm(form, kind) {
    var id  = form.querySelector('[name="question_id"]').value;
    var ok  = document.getElementById('qcOk');
    var ico = document.getElementById('qcIcon');
    var nm  = document.getElementById('qcName');

    if (kind === 'delete') {
        ico.textContent = '⚠️'; ico.style.background = '#fee2e2';
        document.getElementById('qcTitle').textContent = 'Delete this question?';
        nm.textContent = document.getElementById('qView_' + id).textContent.trim();
        nm.style.color = '#b91c1c';
        document.getElementById('qcMsg').textContent = 'Are you sure you want to delete this question? This cannot be undone.';
        ok.textContent = 'Yes, Delete'; ok.className = 'dc-confirm';
    } else {
        ico.textContent = '✏️'; ico.style.background = '#e6f4f2';
        document.getElementById('qcTitle').textContent = 'Save changes to this question?';
        nm.textContent = form.querySelector('[name="question_text"]').value.trim();
        nm.style.color = '#15665c';
        document.getElementById('qcMsg').textContent = 'Are you sure you want to edit this question? The new wording will be used for future evaluations.';
        ok.textContent = 'Yes, Save'; ok.className = 'dc-confirm-ok';
    }
    qcPendingForm = form;
    document.getElementById('qConfirmModal').classList.add('open');
    return false; // submits only after the Yes button
}
function closeQConfirm() {
    document.getElementById('qConfirmModal').classList.remove('open');
    qcPendingForm = null;
}
function proceedQConfirm() {
    var f = qcPendingForm;
    document.getElementById('qConfirmModal').classList.remove('open');
    qcPendingForm = null;
    if (f) f.submit();
}
document.getElementById('qConfirmModal').addEventListener('click', function (e) {
    if (e.target === this) closeQConfirm();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeQConfirm();
});
</script>

</body>
</html>