<?php
/* ============================================================
   RECYCLE BIN — shared helper
   Used by: admin_backend.php, admin_questions.php (save a copy BEFORE
            something is deleted), recycle_bin.php (restore / view)
   Purely additive — creates its own table and touches nothing else.
   Saving a copy never throws and never stops the original delete.
   ============================================================ */

require_once __DIR__ . '/admin_activity_helper.php';

function recycleBinEnsureTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS recycle_bin (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            item_type      VARCHAR(20)  NOT NULL,
            item_label     VARCHAR(255) NOT NULL,
            summary        VARCHAR(255) NULL,
            preview        MEDIUMTEXT   NULL,
            payload        LONGTEXT     NOT NULL,
            school_year_id INT NULL,
            deleted_by     VARCHAR(100) NOT NULL,
            deleted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status         VARCHAR(10)  NOT NULL DEFAULT 'deleted',
            restored_at    DATETIME NULL,
            restored_by    VARCHAR(100) NULL,
            KEY idx_status_time (status, deleted_at)
        ) ENGINE=InnoDB");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------- small utilities ---------- */

/* Runs a SELECT and returns all rows (array of assoc arrays). */
function recycleBinFetch($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    return $rows;
}

function recycleBinEncode($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return null;
    if (function_exists('gzcompress')) {
        return 'gz:' . base64_encode(gzcompress($json, 6));
    }
    return 'js:' . $json;
}

function recycleBinDecode($payload) {
    if (strpos($payload, 'gz:') === 0 && function_exists('gzuncompress')) {
        $json = @gzuncompress(base64_decode(substr($payload, 3)));
    } elseif (strpos($payload, 'js:') === 0) {
        $json = substr($payload, 3);
    } else {
        $json = false;
    }
    if ($json === false || $json === null) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function recycleBinColumnExists($conn, $table, $column) {
    try {
        $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
        return $r && $r->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/* Which of these ids still exist in $table (by its id column)? Returns [id => true]. */
function recycleBinExistingIds($conn, $table, $ids, $col = 'id') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $found = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $in  = implode(',', $chunk);
        $res = $conn->query("SELECT `$col` AS k FROM `$table` WHERE `$col` IN ($in)");
        while ($res && ($r = $res->fetch_assoc())) { $found[(int)$r['k']] = true; }
    }
    return $found;
}

function recycleBinColumn($rows, $col) {
    $out = [];
    foreach ($rows as $r) { if (isset($r[$col])) $out[] = (int)$r[$col]; }
    return $out;
}

/* INSERT IGNORE every row into $table (only columns that really exist in the table).
   Returns how many rows were actually inserted. */
function recycleBinInsertRows($conn, $table, $rows) {
    if (empty($rows)) return 0;

    $real = [];
    $res  = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($res && ($c = $res->fetch_assoc())) { $real[$c['Field']] = true; }

    $cols = [];
    foreach (array_keys($rows[0]) as $k) {
        if (isset($real[$k])) $cols[] = $k;
    }
    if (empty($cols)) return 0;

    $names = implode(', ', array_map(function ($c) { return "`$c`"; }, $cols));
    $marks = implode(', ', array_fill(0, count($cols), '?'));
    $stmt  = $conn->prepare("INSERT IGNORE INTO `$table` ($names) VALUES ($marks)");
    $types = str_repeat('s', count($cols));

    $inserted = 0;
    foreach ($rows as $row) {
        $vals = [];
        foreach ($cols as $c) { $vals[] = $row[$c] ?? null; }
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $inserted++;
    }
    $stmt->close();
    return $inserted;
}

/* Same logic admin_backend.php uses to keep teachers.subject = "FIL-1, GE-04". */
function recycleBinSyncTeacherSubjects($conn, $teacherId) {
    $rows = recycleBinFetch($conn, "SELECT subject FROM teacher_subjects WHERE teacher_id = ? ORDER BY year_level, subject", "i", [(int)$teacherId]);
    $joined = implode(', ', array_column($rows, 'subject'));
    $upd = $conn->prepare("UPDATE teachers SET subject = ? WHERE id = ?");
    $tid = (int)$teacherId;
    $upd->bind_param("si", $joined, $tid);
    $upd->execute();
    $upd->close();
}

/* Stores one recycle-bin entry. */
function recycleBinStore($conn, $type, $label, $summary, $preview, $data, $schoolYearId = null) {
    try {
        if (!recycleBinEnsureTable($conn)) return false;

        $payload = recycleBinEncode($data);
        if ($payload === null) return false;

        $by      = activityLogAdminName();
        $label   = substr((string)$label, 0, 255);
        $summary = substr((string)$summary, 0, 255);
        $prev    = json_encode(array_slice($preview, 0, 300), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $syId    = ($schoolYearId === null || $schoolYearId === '') ? null : (int)$schoolYearId;

        $stmt = $conn->prepare("INSERT INTO recycle_bin (item_type, item_label, summary, preview, payload, school_year_id, deleted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssis", $type, $label, $summary, $prev, $payload, $syId, $by);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
   SAVE A COPY (call these BEFORE the delete happens)
   ============================================================ */

/* Teacher + all subjects, enrolled students, links, section assignments,
   evaluations and per-question answers that the delete removes. */
function recycleBinSaveTeacher($conn, $teacherId) {
    try {
        $teacherId = (int)$teacherId;
        $t = recycleBinFetch($conn, "SELECT * FROM teachers WHERE id = ?", "i", [$teacherId]);
        if (empty($t)) return false;
        $teacher = $t[0];

        $tsRows  = recycleBinFetch($conn, "SELECT * FROM teacher_subjects WHERE teacher_id = ?", "i", [$teacherId]);
        $ssRows  = recycleBinFetch($conn, "SELECT ss.* FROM subject_students ss JOIN teacher_subjects ts ON ts.id = ss.teacher_subject_id WHERE ts.teacher_id = ?", "i", [$teacherId]);
        $stRows  = recycleBinFetch($conn, "SELECT * FROM student_teachers WHERE teacher_id = ?", "i", [$teacherId]);
        $secRows = recycleBinFetch($conn, "SELECT * FROM section_teachers WHERE teacher_id = ?", "i", [$teacherId]);
        $evRows  = recycleBinFetch($conn, "SELECT * FROM evaluations WHERE teacher_id = ?", "i", [$teacherId]);
        $ansRows = [];
        try {
            $ansRows = recycleBinFetch($conn, "SELECT ea.* FROM evaluation_answers ea JOIN evaluations e ON e.id = ea.evaluation_id WHERE e.teacher_id = ?", "i", [$teacherId]);
        } catch (Throwable $e) { /* table may not exist yet */ }

        // Readable preview: each subject + how many students were in it
        $perSubject = [];
        foreach ($ssRows as $ss) {
            $k = (int)$ss['teacher_subject_id'];
            $perSubject[$k] = ($perSubject[$k] ?? 0) + 1;
        }
        $preview = [];
        foreach ($tsRows as $ts) {
            $preview[] = $ts['subject'] . ' — ' . ($perSubject[(int)$ts['id']] ?? 0) . ' student(s)';
        }

        $summary = count($tsRows) . ' subject(s) · ' . count($ssRows) . ' student enrollment(s) · ' . count($evRows) . ' evaluation(s)';

        return recycleBinStore($conn, 'teacher', $teacher['name'], $summary, $preview, [
            'teacher'            => $teacher,
            'teacher_subjects'   => $tsRows,
            'subject_students'   => $ssRows,
            'student_teachers'   => $stRows,
            'section_teachers'   => $secRows,
            'evaluations'        => $evRows,
            'evaluation_answers' => $ansRows,
        ], $teacher['school_year_id'] ?? null);
    } catch (Throwable $e) {
        return false;
    }
}

/* One subject of a teacher + the students that were enrolled in it. */
function recycleBinSaveSubject($conn, $tsId) {
    try {
        $tsId = (int)$tsId;
        $r = recycleBinFetch($conn, "SELECT ts.*, t.name AS _teacher_name, t.school_year_id AS _teacher_sy FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id WHERE ts.id = ?", "i", [$tsId]);
        if (empty($r)) return false;
        $row = $r[0];

        $teacherName = $row['_teacher_name'];
        $teacherSy   = $row['_teacher_sy'];
        unset($row['_teacher_name'], $row['_teacher_sy']);
        $teacherId = (int)$row['teacher_id'];

        $ssRows = recycleBinFetch($conn, "SELECT * FROM subject_students WHERE teacher_subject_id = ?", "i", [$tsId]);
        $studentIds = recycleBinColumn($ssRows, 'student_id');

        $stRows = [];
        $names  = [];
        if (!empty($studentIds)) {
            $in     = implode(',', array_map('intval', $studentIds));
            $stRows = recycleBinFetch($conn, "SELECT * FROM student_teachers WHERE teacher_id = ? AND student_id IN ($in)", "i", [$teacherId]);
            $nr = $conn->query("SELECT student_id, name FROM students WHERE id IN ($in) ORDER BY name");
            while ($nr && ($n = $nr->fetch_assoc())) {
                $names[] = $n['name'] . ' (' . $n['student_id'] . ')';
            }
        }

        // Normally evaluations stay when a subject is removed; copied anyway in case the database removes them with it.
        $evRows = [];
        $ansRows = [];
        if (recycleBinColumnExists($conn, 'evaluations', 'teacher_subject_id')) {
            $evRows = recycleBinFetch($conn, "SELECT * FROM evaluations WHERE teacher_subject_id = ?", "i", [$tsId]);
            try {
                $ansRows = recycleBinFetch($conn, "SELECT ea.* FROM evaluation_answers ea JOIN evaluations e ON e.id = ea.evaluation_id WHERE e.teacher_subject_id = ?", "i", [$tsId]);
            } catch (Throwable $e) { /* table may not exist yet */ }
        }

        $label   = $row['subject'] . ' (' . $teacherName . ')';
        $summary = count($ssRows) . ' student(s) enrolled';

        return recycleBinStore($conn, 'subject', $label, $summary, $names, [
            'teacher_id'         => $teacherId,
            'teacher_name'       => $teacherName,
            'teacher_subject'    => $row,
            'subject_students'   => $ssRows,
            'student_teachers'   => $stRows,
            'evaluations'        => $evRows,
            'evaluation_answers' => $ansRows,
        ], $teacherSy);
    } catch (Throwable $e) {
        return false;
    }
}

/* One evaluation question. */
function recycleBinSaveQuestion($conn, $questionId) {
    try {
        $r = recycleBinFetch($conn, "SELECT * FROM evaluation_questions WHERE id = ?", "i", [(int)$questionId]);
        if (empty($r)) return false;
        $q = $r[0];
        return recycleBinStore($conn, 'question', $q['question_text'], 'Evaluation question #' . (int)$q['sort_order'], [], ['question' => $q], null);
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
   RESTORE
   Returns [true|false, "message"]
   ============================================================ */
function recycleBinRestore($conn, $binId) {
    $binId = (int)$binId;
    try {
        $rows = recycleBinFetch($conn, "SELECT id, item_type, item_label, payload, status FROM recycle_bin WHERE id = ?", "i", [$binId]);
        if (empty($rows))                    return [false, "That item is no longer in the Recycle Bin."];
        $item = $rows[0];
        if ($item['status'] !== 'deleted')   return [false, "That item was already restored."];

        $data = recycleBinDecode($item['payload']);
        if ($data === null)                  return [false, "The saved copy of this item could not be read."];

        $conn->begin_transaction();
        try {
            if     ($item['item_type'] === 'teacher')  $result = recycleBinRestoreTeacher($conn, $data);
            elseif ($item['item_type'] === 'subject')  $result = recycleBinRestoreSubject($conn, $data);
            elseif ($item['item_type'] === 'question') $result = recycleBinRestoreQuestion($conn, $data);
            else                                       $result = [false, "Unknown item type."];

            if (!$result[0]) { $conn->rollback(); return $result; }

            $by  = activityLogAdminName();
            $upd = $conn->prepare("UPDATE recycle_bin SET status = 'restored', restored_at = NOW(), restored_by = ? WHERE id = ?");
            $upd->bind_param("si", $by, $binId);
            $upd->execute();
            $upd->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, "Restore failed: " . $e->getMessage()];
        }

        logAdminActivity($conn, 'Recycle Bin', 'Restored ' . $item['item_type'], "\"" . $item['item_label'] . "\" — " . $result[1]);
        return $result;
    } catch (Throwable $e) {
        return [false, "Restore failed: " . $e->getMessage()];
    }
}

function recycleBinRestoreTeacher($conn, $d) {
    $t = $d['teacher'] ?? null;
    if (!$t) return [false, "Saved copy is incomplete."];

    $tid = (int)$t['id'];
    $sy  = $t['school_year_id'] ?? null;

    if (!empty(recycleBinExistingIds($conn, 'teachers', [$tid]))) {
        return [false, "Cannot restore: a teacher record with the same ID already exists."];
    }
    $dup = recycleBinFetch($conn, "SELECT id FROM teachers WHERE LOWER(name) = LOWER(?) AND school_year_id <=> ? AND semester = ? LIMIT 1", "sii", [$t['name'], $sy, (int)($t['semester'] ?? 1)]);
    if (!empty($dup)) {
        return [false, "Cannot restore: a teacher named \"" . $t['name'] . "\" already exists for that school year."];
    }

    recycleBinInsertRows($conn, 'teachers', [$t]);

    $tsRows = $d['teacher_subjects'] ?? [];
    recycleBinInsertRows($conn, 'teacher_subjects', $tsRows);

    // Only bring back rows whose student account still exists
    $studentIds = array_merge(
        recycleBinColumn($d['subject_students'] ?? [], 'student_id'),
        recycleBinColumn($d['student_teachers'] ?? [], 'student_id'),
        recycleBinColumn($d['evaluations'] ?? [], 'student_id')
    );
    $alive = recycleBinExistingIds($conn, 'students', $studentIds);
    $keep  = function ($rows) use ($alive) {
        return array_values(array_filter($rows, function ($r) use ($alive) { return isset($alive[(int)($r['student_id'] ?? 0)]); }));
    };

    $ss = $keep($d['subject_students'] ?? []);
    $st = $keep($d['student_teachers'] ?? []);
    $ev = $keep($d['evaluations'] ?? []);

    $nSs = recycleBinInsertRows($conn, 'subject_students', $ss);
    recycleBinInsertRows($conn, 'student_teachers', $st);
    recycleBinInsertRows($conn, 'section_teachers', $d['section_teachers'] ?? []);
    $nEv = recycleBinInsertRows($conn, 'evaluations', $ev);

    $evAlive = recycleBinExistingIds($conn, 'evaluations', recycleBinColumn($ev, 'id'));
    $ans = array_values(array_filter($d['evaluation_answers'] ?? [], function ($r) use ($evAlive) { return isset($evAlive[(int)($r['evaluation_id'] ?? 0)]); }));
    try { recycleBinInsertRows($conn, 'evaluation_answers', $ans); } catch (Throwable $e) { /* table may not exist */ }

    recycleBinSyncTeacherSubjects($conn, $tid);

    $skipped = (count($d['subject_students'] ?? []) - count($ss));
    $msg = "Teacher \"" . $t['name'] . "\" restored with " . count($tsRows) . " subject(s), " . $nSs . " student enrollment(s) and " . $nEv . " evaluation(s)";
    if ($skipped > 0) $msg .= " ($skipped enrollment(s) skipped because that student account no longer exists)";
    return [true, $msg];
}

function recycleBinRestoreSubject($conn, $d) {
    $ts = $d['teacher_subject'] ?? null;
    if (!$ts) return [false, "Saved copy is incomplete."];

    $teacherId = (int)($d['teacher_id'] ?? 0);
    if (empty(recycleBinExistingIds($conn, 'teachers', [$teacherId]))) {
        return [false, "Cannot restore: the teacher \"" . ($d['teacher_name'] ?? '') . "\" no longer exists. Restore the teacher from the Recycle Bin first (if they were deleted)."];
    }
    if (!empty(recycleBinExistingIds($conn, 'teacher_subjects', [(int)$ts['id']]))) {
        return [false, "Cannot restore: this subject entry already exists again."];
    }
    $dup = recycleBinFetch($conn, "SELECT id FROM teacher_subjects WHERE teacher_id = ? AND subject = ? AND semester = ? LIMIT 1", "isi", [$teacherId, $ts['subject'], (int)($ts['semester'] ?? 1)]);
    if (!empty($dup)) {
        return [false, "Cannot restore: \"" . $ts['subject'] . "\" was already added to this teacher again."];
    }

    recycleBinInsertRows($conn, 'teacher_subjects', [$ts]);

    $studentIds = array_merge(
        recycleBinColumn($d['subject_students'] ?? [], 'student_id'),
        recycleBinColumn($d['student_teachers'] ?? [], 'student_id'),
        recycleBinColumn($d['evaluations'] ?? [], 'student_id')
    );
    $alive = recycleBinExistingIds($conn, 'students', $studentIds);
    $keep  = function ($rows) use ($alive) {
        return array_values(array_filter($rows, function ($r) use ($alive) { return isset($alive[(int)($r['student_id'] ?? 0)]); }));
    };

    $ss = $keep($d['subject_students'] ?? []);
    $st = $keep($d['student_teachers'] ?? []);
    $ev = $keep($d['evaluations'] ?? []);

    $nSs = recycleBinInsertRows($conn, 'subject_students', $ss);
    recycleBinInsertRows($conn, 'student_teachers', $st);
    recycleBinInsertRows($conn, 'evaluations', $ev);

    $evAlive = recycleBinExistingIds($conn, 'evaluations', recycleBinColumn($ev, 'id'));
    $ans = array_values(array_filter($d['evaluation_answers'] ?? [], function ($r) use ($evAlive) { return isset($evAlive[(int)($r['evaluation_id'] ?? 0)]); }));
    try { recycleBinInsertRows($conn, 'evaluation_answers', $ans); } catch (Throwable $e) { /* table may not exist */ }

    recycleBinSyncTeacherSubjects($conn, $teacherId);

    $skipped = (count($d['subject_students'] ?? []) - count($ss));
    $msg = "Subject \"" . $ts['subject'] . "\" restored to " . ($d['teacher_name'] ?? 'the teacher') . " with " . $nSs . " student(s)";
    if ($skipped > 0) $msg .= " ($skipped skipped because that student account no longer exists)";
    return [true, $msg];
}

function recycleBinRestoreQuestion($conn, $d) {
    $q = $d['question'] ?? null;
    if (!$q) return [false, "Saved copy is incomplete."];

    $note = '';
    if (!empty(recycleBinExistingIds($conn, 'evaluation_questions', [(int)$q['id']]))) {
        // Original ID is taken — put it back at the end of the list with a new ID.
        unset($q['id']);
        $m = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM evaluation_questions")->fetch_assoc();
        $q['sort_order'] = (int)$m['m'] + 1;
        $note = " (added at the end of the list)";
    }
    $n = recycleBinInsertRows($conn, 'evaluation_questions', [$q]);
    if ($n < 1) return [false, "The question could not be restored."];
    return [true, "Question restored" . $note];
}

/* Removes ONE entry from the Recycle Bin for good (only the saved copy). */
function recycleBinPurge($conn, $binId) {
    try {
        $binId = (int)$binId;
        $rows = recycleBinFetch($conn, "SELECT item_type, item_label, status FROM recycle_bin WHERE id = ?", "i", [$binId]);
        if (empty($rows)) return [false, "That item is no longer in the Recycle Bin."];

        $del = $conn->prepare("DELETE FROM recycle_bin WHERE id = ?");
        $del->bind_param("i", $binId);
        $del->execute();
        $del->close();

        if ($rows[0]['status'] === 'deleted') {
            logAdminActivity($conn, 'Recycle Bin', 'Permanently deleted ' . $rows[0]['item_type'], "\"" . $rows[0]['item_label'] . "\" can no longer be restored");
        }
        return [true, "Permanently deleted."];
    } catch (Throwable $e) {
        return [false, "Could not delete: " . $e->getMessage()];
    }
}