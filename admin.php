<?php
$data = require "admin_backend.php";

$message          = $data["message"]          ?? "";
$teachers         = $data["teachers"]         ?? [];
$allTeacherNames  = $data["allTeacherNames"]  ?? [];
$teacherSubjects  = $data["teacherSubjects"]  ?? [];
$subjectStudents  = $data["subjectStudents"]  ?? [];
$importSections   = $data["importSections"]   ?? ['A', 'B', 'C', 'D', 'E'];
$subjectsByYear   = $data["subjectsByYear"]   ?? [];
$schoolYears      = $data["schoolYears"]      ?? [];
$activeSchoolYear = $data["activeSchoolYear"] ?? null;
$syPresentStart  = (int)date('Y'); // present year from the real-time calendar
$activeSemester   = $data["activeSemester"]   ?? 1;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Manage Sections — Admin Panel</title>
<style>
/* ── BASE ── */
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial; background: #f5f7fb; }

/* ── SIDEBAR ── */
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

/* ── SIDEBAR BRAND (logo + school name) ── */
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
    padding: 8px 20px;
    color: white;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.15s, color 0.15s;
}

.sidebar a:hover         { background: #374151; color: white; }
.sidebar a.active        { background: #374151; color: white; }
.sidebar a.analytics-link{ color: }
.sidebar a.analytics-link:hover { background: #374151; color: #93c5fd; }
.sidebar a.logout        { color: #f87171; position: absolute; bottom: 20px; width: 100%; }
.sidebar a.logout:hover  { background: #374151; color: #fca5a5; }

/* ── HEADER ── */
.header {
    margin-left: 230px;
    background: #111827;
    padding: 20px;
    color: white;
    font-size: 18px;
    font-weight: bold;
}

/* ── MAIN ── */
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
    margin-bottom: 24px;
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

/* ── YEAR SELECT ── */
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

/* ── ADD SECTION BUTTON ── */
.btn-add {
    background: #111827;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 13px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.15s;
}

.btn-add:hover { background: #1f2937; }

/* ── SCHOOL YEAR BUTTON ── */
.btn-schoolyear {
    background: #fff;
    color: #1a7f74;
    border: 1px solid #1a7f74;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-schoolyear:hover { background: #e6f4f2; border-color: #15665c; color: #15665c; }

/* ── SCHOOL YEAR LIST (inside manage modal) ── */
.sy-list {
    max-height: 220px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.sy-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 2px;
    border-bottom: 1px solid #f3f4f6;
}

.sy-row:last-child { border-bottom: none; }

.sy-label { font-size: 13.5px; color: #111827; font-weight: bold; }

.sy-badge-active {
    font-size: 10.5px;
    background: #d1fae5;
    color: #065f46;
    border-radius: 10px;
    padding: 2px 9px;
    margin-left: 8px;
    font-weight: bold;
}

.sy-row form { margin: 0; }

.btn-sy-activate {
    background: #1a7f74;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
}
.btn-sy-activate:hover { background: #15665c; }

.btn-sy-stop {
    background: #fff;
    color: #ef4444;
    border: 1px solid #fca5a5;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-family: Arial;
    cursor: pointer;
}
.btn-sy-stop:hover { background: #fee2e2; }

/* ── SECTION CARDS GRID ── */
.row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 40px;
}

/* ── SECTION CARD ── */
.section-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 20px;
    width: 260px;
    display: none;
    flex-direction: column;
    gap: 14px;
    transition: box-shadow 0.2s;
    position: relative;
}

.section-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); }

/* Card header */
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

/* Divider */
.card-divider {
    border: none;
    border-top: 1px solid #f3f4f6;
    margin: 0;
}

/* ── TEACHER ASSIGN SECTION ── */
.teacher-label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 7px;
}

.teacher-list {
    max-height: 130px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 6px 8px;
    background: #f9fafb;
}

.teacher-list::-webkit-scrollbar { width: 4px; }
.teacher-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

.teacher-list label {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 4px 2px;
    font-size: 13px;
    color: #374151;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.1s;
}

.teacher-list label:hover { background: #f3f4f6; color: #1a7f74; }

.teacher-list input[type="checkbox"] {
    accent-color: #1a7f74;
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}

/* ── BUTTONS ── */
.btn-save-teachers {
    background: #1a7f74;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px;
    font-size: 13px;
    font-weight: bold;
    font-family: Arial;
    cursor: pointer;
    width: 100%;
    transition: background 0.15s;
}

.btn-save-teachers:hover { background: #15665c; }

.btn-upload {
    background: #111827;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px;
    font-size: 13px;
    font-weight: bold;
    font-family: Arial;
    cursor: pointer;
    width: 100%;
    margin-top: 6px;
    transition: background 0.15s;
}

.btn-upload:hover { background: #1f2937; }

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
}

.btn-view-masterlist:hover { background: #e6f4f2; border-color: #15665c; color: #15665c; }

.btn-add-student {
    background: #1a7f74;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px;
    font-size: 13px;
    font-weight: bold;
    font-family: Arial;
    cursor: pointer;
    width: 100%;
    transition: background 0.15s;
}

.btn-add-student:hover { background: #15665c; }

.btn-delete {
    background: #fff;
    color: #ef4444;
    border: 1px solid #fca5a5;
    border-radius: 8px;
    padding: 8px;
    font-size: 13px;
    font-family: Arial;
    cursor: pointer;
    width: 100%;
    transition: all 0.15s;
}

.btn-delete:hover { background: #fee2e2; border-color: #ef4444; }

/* ── FILE INPUT ── */
.file-input-wrap {
    position: relative;
}

.file-input-wrap input[type="file"] {
    display: block;
    width: 100%;
    font-size: 12px;
    color: #6b7280;
    background: #f9fafb;
    border: 1px dashed #d1d5db;
    border-radius: 8px;
    padding: 7px 10px;
    cursor: pointer;
    outline: none;
}

/* ── MESSAGE BANNER ── */
.message-banner {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    border-radius: 8px;
    padding: 10px 16px;
    font-size: 13px;
    color: #065f46;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── EMPTY STATE ── */
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

/* ── IMPORT: SECTION PICKER ── */
.import-section-select,
.new-section-input {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    font-family: Arial;
    color: #111827;
    background: #fff;
    outline: none;
    margin-bottom: 6px;
}
.import-section-select:focus,
.new-section-input:focus { border-color: #1a7f74; }

/* ── DELETE CONFIRM NOTIF ── */
.dc-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(17,24,39,0.55);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}
.dc-overlay.open { display: flex; }
.dc-box {
    background: #fff;
    border-radius: 14px;
    width: 92%;
    max-width: 400px;
    padding: 26px 24px 22px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.dc-icon {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: #fee2e2;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    margin-bottom: 12px;
}
.dc-box h3 { margin: 0 0 6px; font-size: 18px; color: #111827; }
.dc-name { margin: 0 0 8px; font-size: 14px; font-weight: bold; color: #b91c1c; word-break: break-word; }
.dc-msg { margin: 0 0 20px; font-size: 13px; line-height: 1.5; color: #6b7280; }
.dc-actions { display: flex; gap: 10px; }
.dc-actions button {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151;
}
.dc-actions button:hover { background: #f3f4f6; }
.dc-actions .dc-confirm { background: #dc2626; border-color: #dc2626; color: #fff; }
.dc-actions .dc-confirm:hover { background: #b91c1c; }
.dc-actions .dc-confirm-ok { background: #15665c; border-color: #15665c; color: #fff; }
.dc-actions .dc-confirm-ok:hover { background: #115248; }

/* ── MODAL OVERLAY ── */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal-overlay.open { display: flex; }

.modal-box {
    background: white;
    border-radius: 12px;
    padding: 28px 24px;
    width: 320px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    position: relative;
}

.modal-box h3 {
    margin: 0 0 20px 0;
    font-size: 17px;
    color: #111827;
}

.modal-field {
    margin-bottom: 14px;
}

.modal-field label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 6px;
}

.modal-field select,
.modal-field input[type="text"] {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    font-family: Arial;
    color: #111827;
    background: #fff;
    outline: none;
    transition: border-color 0.15s;
}

.modal-field select:focus,
.modal-field input[type="text"]:focus { border-color: #1a7f74; }

.modal-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.btn-modal-submit {
    flex: 1;
    background: #111827;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px;
    font-size: 14px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.15s;
}

.btn-modal-submit:hover { background: #1f2937; }

.btn-modal-cancel {
    flex: 1;
    background: #fff;
    color: #6b7280;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
    font-size: 14px;
    font-family: Arial;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-modal-cancel:hover { background: #f9fafb; border-color: #d1d5db; color: #374151; }

/* ── ADD SECTION MODAL — repeatable teacher rows ── */
.new-teacher-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.new-teacher-row .new-teacher-name {
    flex: 1.3;
    padding: 9px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: Arial;
    color: #111827;
    background: #fff;
    outline: none;
    min-width: 0;
}

.new-teacher-row .new-teacher-name:focus { border-color: #1a7f74; }

.new-teacher-row .new-teacher-subject {
    flex: 1;
    padding: 9px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: Arial;
    color: #111827;
    background: #fff;
    outline: none;
    min-width: 0;
}

.new-teacher-row .new-teacher-subject:focus { border-color: #1a7f74; }

.btn-add-teacher-row {
    background: #fff;
    color: #1a7f74;
    border: 1px dashed #1a7f74;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 12.5px;
    font-family: Arial;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    transition: all 0.15s;
}

.btn-add-teacher-row:hover { background: #e6f4f2; }

/* ── NO YEAR SELECTED HINT ── */
.hint-select {
    color: #9ca3af;
    font-size: 13px;
    margin-bottom: 10px;
}

/* ══════════════════════════════════════════════════════════════
   ADD STUDENT MODAL — formal record-card styling
   Scoped entirely under #addStudentModal so nothing else is affected.
   ══════════════════════════════════════════════════════════════ */

#addStudentModal .modal-box.student-modal {
    width: 560px;
    max-width: 92vw;
    padding: 0;
    border-radius: 14px;
    max-height: 86vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

#addStudentModal .student-modal-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 24px 30px;
    background: #111827;
    border-bottom: 3px solid #1a7f74;
    flex-shrink: 0;
}

#addStudentModal .student-modal-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: rgba(26, 127, 116, 0.25);
    border: 1px solid rgba(26, 127, 116, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

#addStudentModal .student-modal-heading h3 {
    margin: 0;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 19px;
    font-weight: bold;
    color: #fff;
    letter-spacing: 0.2px;
}

#addStudentModal .student-modal-subtitle {
    margin: 4px 0 0 0;
    font-size: 12px;
    color: #9ca3af;
}

#addStudentModal .student-modal-body {
    padding: 24px 30px 8px;
    overflow-y: auto;
}

#addStudentModal .student-modal-body::-webkit-scrollbar { width: 6px; }
#addStudentModal .student-modal-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

#addStudentModal .form-section {
    margin-bottom: 22px;
}

#addStudentModal .form-section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #1a7f74;
    margin-bottom: 14px;
}

#addStudentModal .form-section-title::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}

#addStudentModal .field-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

#addStudentModal .modal-field {
    margin-bottom: 14px;
}

#addStudentModal .field-grid-2 .modal-field {
    margin-bottom: 0;
}

#addStudentModal .modal-field input[type="text"],
#addStudentModal .modal-field input[type="date"],
#addStudentModal .modal-field select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13.5px;
    font-family: Arial;
    color: #111827;
    background: #f9fafb;
    outline: none;
    transition: border-color 0.15s, background 0.15s;
}

#addStudentModal .modal-field input[type="text"]:focus,
#addStudentModal .modal-field input[type="date"]:focus,
#addStudentModal .modal-field select:focus {
    border-color: #1a7f74;
    background: #fff;
}

#addStudentModal .teacher-list {
    background: #f9fafb;
    max-height: 110px;
}

#addStudentModal .student-modal-footer {
    padding: 18px 30px 24px;
    border-top: 1px solid #f3f4f6;
    background: #fff;
    flex-shrink: 0;
}

#addStudentModal .student-modal-footer .modal-actions {
    margin-top: 0;
}

/* ── TEACHER CARDS (replace section cards) ── */
.teacher-card { display: flex; width: 300px; }
.teacher-fullname { font-size: 12px; color: #6b7280; margin-top: 3px; }
.chip-list { display: flex; flex-direction: column; gap: 8px; }
.subject-chip {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    width: 100%; text-align: left; padding: 9px 12px; border-radius: 8px;
    font-size: 13px; font-weight: bold; font-family: Arial; cursor: pointer;
    border: 1px solid; transition: all 0.15s;
}
.subject-chip .chip-left { display: flex; align-items: center; gap: 8px; }
.subject-chip .chip-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.subject-chip .chip-meta { font-size: 11px; font-weight: normal; }
.subject-chip.filled { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.subject-chip.filled .chip-dot { background: #10b981; }
.subject-chip.filled:hover { background: #d1fae5; }
.subject-chip.empty  { background: #fef2f2; border-color: #fca5a5; color: #b91c1c; }
.subject-chip.empty .chip-dot { background: #ef4444; box-shadow: 0 0 0 3px #fee2e2; }
.subject-chip.empty:hover { background: #fee2e2; }
.no-subjects { font-size: 12px; color: #9ca3af; padding: 2px; }
.card-actions { display: flex; gap: 8px; }
.card-actions form { flex: 1; margin: 0; }
.card-actions .btn-edit-teacher {
    flex: 1; background: #fff; color: #374151; border: 1px solid #d1d5db; border-radius: 8px;
    padding: 8px; font-size: 13px; font-family: Arial; cursor: pointer; transition: all 0.15s;
}
.card-actions .btn-edit-teacher:hover { background: #f3f4f6; }

/* ── SUBJECT MODAL ── */
.subject-modal-stat {
    display: inline-block; font-size: 12px; font-weight: bold; border-radius: 12px;
    padding: 3px 10px; margin-bottom: 14px;
}
.subject-modal-stat.filled { background: #d1fae5; color: #065f46; }
.subject-modal-stat.empty  { background: #fee2e2; color: #b91c1c; }
.subject-students {
    max-height: 180px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;
    margin: 12px 0 16px; background: #f9fafb;
}
.subject-students table { width: 100%; border-collapse: collapse; font-size: 12px; }
.subject-students th { text-align: left; padding: 7px 10px; color: #6b7280; font-size: 10.5px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: #f3f4f6; }
.subject-students td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; color: #111827; }
.year-group-title { font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; padding: 6px 2px 2px; }
.message-banner { display: block; }

/* ── Scrollable modals (Add Subject / Subject import) ──
   Box is capped to the screen height; only the middle part scrolls,
   so the buttons at the bottom are always reachable. */
.modal-box.scroll-modal {
    display: flex; flex-direction: column;
    max-height: 90vh; max-height: 90dvh;
    overflow: hidden;
}
.modal-box.scroll-modal > h3 { flex-shrink: 0; }
.modal-box.scroll-modal .scroll-body {
    flex: 1 1 auto; min-height: 0;
    overflow-y: auto; overscroll-behavior: contain;
    padding-right: 6px;
}
.modal-box.scroll-modal form.scroll-form {
    display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; overflow: hidden;
}
.modal-box.scroll-modal .scroll-footer { flex-shrink: 0; padding-top: 12px; }
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
    <a href="admin.php" class="active">📋 Manage Sections</a>
    <a href="admin_analytics.php" class="analytics-link">📊 Analytics</a>
    <a href="admin_questions.php">🗒️ Questioner</a>
    <a href="teacher_masterlist.php">🧑‍🏫 View Comments</a>
    <a href="teacher_scores.php">📈 Teacher Scores</a>
    <a href="login_history.php">🕘 Login History</a>
    <a href="activity_logs.php">📝 Activity Logs</a>
    <a href="recycle_bin.php">🗑️ Recycle Bin</a>
    <a href="logout.php" class="logout">⎋ Logout</a>
</div>

<!-- ── HEADER ── -->
<div class="header">
    Admin Panel — Nueva Ecija University of Science and Technology

</div>

<!-- ── MAIN ── -->
<div class="main">

    <!-- TOP BAR -->
    <div class="top-bar">
        <h2>Manage Teachers</h2>
        <div class="top-bar-right">
            <select class="year-select" id="semesterSelect" onchange="window.location.href='?semester=' + this.value" title="Semester">
                <option value="1" <?= (int)$activeSemester === 1 ? 'selected' : '' ?>>1st Semester</option>
                <option value="2" <?= (int)$activeSemester === 2 ? 'selected' : '' ?>>2nd Semester</option>
            </select>
            <button class="btn-add" onclick="openAddTeacherModal()">+ Add Teacher</button>
            <button class="btn-add" onclick="openAddStudentModal()">➕ Add Student</button>
            <button class="btn-schoolyear" onclick="openSchoolYearModal()">
                🗓 <?= $activeSchoolYear ? htmlspecialchars($activeSchoolYear['label']) . ' (Active)' : 'No Active School Year' ?>
            </button>
        </div>
    </div>

    <!-- MESSAGE BANNER (messages are built/escaped in admin_backend.php) -->
    <?php if (!empty($message)): ?>
    <div class="message-banner"><?= $message ?></div>
    <?php endif; ?>

    <!-- TEACHER CARDS -->
    <div class="row" id="cardsRow">

    <?php foreach ($teachers as $t):
        $tid      = (int)$t['id'];
        $subs     = $teacherSubjects[$tid] ?? [];
        $display  = trim(($t['title'] ?? '') . ' ' . getTeacherLastName($t['name']));
    ?>
    <div class="section-card teacher-card">

        <div class="card-header">
            <div>
                <div class="card-title"><?= htmlspecialchars($display) ?></div>
                <div class="teacher-fullname"><?= htmlspecialchars($t['name']) ?></div>
            </div>
            <span class="year-badge"><?= count($subs) ?> subject<?= count($subs) === 1 ? '' : 's' ?></span>
        </div>

        <hr class="card-divider">

        <div>
            <label class="teacher-label">Subjects</label>
            <?php if (empty($subs)): ?>
                <div class="no-subjects">No subjects yet. Click “Add Subject”.</div>
            <?php else: ?>
            <div class="chip-list">
                <?php foreach ($subs as $s): $has = $s['count'] > 0; ?>
                <button type="button"
                        class="subject-chip <?= $has ? 'filled' : 'empty' ?>"
                        onclick="openSubjectModal(<?= $s['id'] ?>)"
                        title="<?= $has ? $s['count'] . ' student(s) imported' : 'No students imported yet' ?>">
                    <span class="chip-left">
                        <span class="chip-dot"></span>
                        <span><?= htmlspecialchars($s['subject']) ?></span>
                    </span>
                    <span class="chip-meta">Y<?= $s['year_level'] ?> · <?= $has ? $s['count'] . ' student' . ($s['count'] === 1 ? '' : 's') : 'No students yet' ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <button type="button" class="btn-view-masterlist" onclick="openAddSubjectModal(<?= $tid ?>)">📚 Add Subject</button>

        <div class="card-actions">
            <button type="button" class="btn-edit-teacher"
                    onclick="openEditTeacherModal(<?= $tid ?>, <?= htmlspecialchars(json_encode($t['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($t['title'] ?? ''), ENT_QUOTES) ?>)">✏️ Edit</button>
            <form method="POST" onsubmit="return askDeleteConfirm(this, 'teacher', '<?= htmlspecialchars(addslashes($display), ENT_QUOTES) ?>')">
                <input type="hidden" name="delete_teacher" value="<?= $tid ?>">
                <button type="submit" class="btn-delete">🗑 Delete</button>
            </form>
        </div>

    </div>

    <!-- ── ADD SUBJECT MODAL (<?= htmlspecialchars($display) ?>) ── -->
    <div class="modal-overlay" id="addSubjectModal_<?= $tid ?>">
        <div class="modal-box scroll-modal" style="width:380px;">
            <h3>Add Subject — <?= htmlspecialchars($display) ?></h3>
            <form method="POST" class="scroll-form" onsubmit="return askActionConfirm(this, 'addSubject', '<?= htmlspecialchars(addslashes($display), ENT_QUOTES) ?>')">
                <input type="hidden" name="add_subjects" value="<?= $tid ?>">
                <div class="scroll-body">
                <?php
                    $already = array_column($subs, 'subject');
                    foreach ($subjectsByYear as $lvl => $list):
                ?>
                <div class="year-group-title">Year <?= $lvl ?></div>
                <div class="teacher-list" style="max-height:none;margin-bottom:8px;">
                    <?php foreach ($list as $subj): $isIn = in_array($subj, $already, true); ?>
                    <label style="<?= $isIn ? 'opacity:.55;' : '' ?>">
                        <input type="checkbox" name="subjects[]" value="<?= htmlspecialchars($subj) ?>" <?= $isIn ? 'checked disabled' : '' ?>>
                        <?= htmlspecialchars($subj) ?><?= $isIn ? ' (added)' : '' ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="modal-actions scroll-footer">
                    <button type="submit" class="btn-modal-submit">Add Subject</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeModalById('addSubjectModal_<?= $tid ?>')">Close</button>
                </div>
            </form>
        </div>
    </div>

    <?php foreach ($subs as $s):
        $has  = $s['count'] > 0;
        $studs = $subjectStudents[$s['id']] ?? [];
    ?>
    <!-- ── SUBJECT MODAL: Excel import (<?= htmlspecialchars($s['subject']) ?> / <?= htmlspecialchars($display) ?>) ── -->
    <div class="modal-overlay" id="subjectModal_<?= $s['id'] ?>">
        <div class="modal-box scroll-modal" style="width:460px;">
            <h3><?= htmlspecialchars($s['subject']) ?> — <?= htmlspecialchars($display) ?> <span class="year-badge">Year <?= $s['year_level'] ?></span></h3>

            <div class="scroll-body">
            <div class="subject-modal-stat <?= $has ? 'filled' : 'empty' ?>">
                <?= $has ? $s['count'] . ' student' . ($s['count'] === 1 ? '' : 's') . ' imported' : '● No students imported yet' ?>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="import_subject" value="<?= $s['id'] ?>">

                <label class="teacher-label">Section</label>
                <select name="section" class="import-section-select" required onchange="toggleNewSection(this)">
                    <option value="">Select section…</option>
                    <?php foreach ($importSections as $secName): ?>
                    <option value="<?= htmlspecialchars($secName) ?>">Section <?= htmlspecialchars($secName) ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">＋ Add new section…</option>
                </select>
                <input type="text" name="new_section" class="new-section-input" maxlength="30" placeholder="New section name (e.g. F)" style="display:none;">

                <label class="teacher-label" style="margin-top:12px;">Upload Excel</label>
                <div class="file-input-wrap">
                    <input type="file" name="student_file" accept=".xlsx" required>
                </div>
                <button type="submit" class="btn-upload" style="margin-top:10px;">Upload</button>
            </form>

            <?php if ($has):
                // sections that actually exist among this subject's students (for the "view" picker)
                $viewSecs = []; $viewHasNone = false;
                foreach ($studs as $vSt) {
                    if (($vSt['section'] ?? '') === '') $viewHasNone = true;
                    else $viewSecs[$vSt['section']] = true;
                }
            ?>
            <div class="view-wrap">
            <label class="teacher-label">View Students By Section</label>
            <select class="import-section-select" onchange="filterSubjectStudents(this)">
                <option value="">Select section to view…</option>
                <?php foreach (array_keys($viewSecs) as $vSec): ?>
                <option value="<?= htmlspecialchars($vSec) ?>">Section <?= htmlspecialchars($vSec) ?></option>
                <?php endforeach; ?>
                <?php if ($viewHasNone): ?><option value="__none__">No section</option><?php endif; ?>
                <option value="__all__">All sections</option>
            </select>
            <div class="view-hint" style="font-size:12px;color:#9ca3af;padding:10px 2px;">Pick a section above to see its students.</div>
            <div class="subject-students" style="display:none;">
                <table>
                    <thead><tr><th>Student No.</th><th>Name</th><th>Section</th><th>Email</th></tr></thead>
                    <tbody>
                    <?php foreach ($studs as $st): ?>
                        <tr data-section="<?= htmlspecialchars($st['section'] ?? '') ?>">
                            <td><?= htmlspecialchars($st['student_id']) ?></td>
                            <td><?= htmlspecialchars($st['name']) ?></td>
                            <td><?= htmlspecialchars($st['section'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($st['email']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
            <?php endif; ?>

            </div>

            <div class="modal-actions scroll-footer" style="margin-top:0;">
                <form method="POST" style="margin:0;" onsubmit="return askDeleteConfirm(this, 'subject', '<?= htmlspecialchars(addslashes($s['subject']), ENT_QUOTES) ?>')">
                    <input type="hidden" name="remove_subject" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn-sy-stop">Remove Subject</button>
                </form>
                <button type="button" class="btn-modal-cancel" onclick="closeModalById('subjectModal_<?= $s['id'] ?>')">Close</button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endforeach; ?>

    <?php if (empty($teachers)): ?>
        <div class="empty-state">
            <?php if ($activeSchoolYear): ?>
                No teachers yet for <?= htmlspecialchars($activeSchoolYear['label']) ?>. Click <strong>+ Add Teacher</strong> to get started.
            <?php else: ?>
                No active school year. Click the school year button (top right) to activate one.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    </div><!-- /row -->

</div><!-- /main -->

<!-- ── ADD TEACHER MODAL ── -->
<div class="modal-overlay" id="addTeacherModal">
    <div class="modal-box">
        <h3>Add Teacher</h3>
        <form method="POST" onsubmit="return askActionConfirm(this, 'addTeacher')">
            <input type="hidden" name="add_teacher" value="1">
            <div class="modal-field">
                <label>Title</label>
                <select name="teacher_title">
                    <option value="Mr.">Mr.</option>
                    <option value="Mrs.">Mrs.</option>
                    <option value="Ms.">Ms.</option>
                </select>
            </div>
            <div class="modal-field">
                <label>Teacher Name</label>
                <input type="text" name="teacher_name" required placeholder="Full name, e.g. Juan Dela Cruz Navarro" list="knownTeacherNames" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn-modal-submit">Add Teacher</button>
                <button type="button" class="btn-modal-cancel" onclick="closeModalById('addTeacherModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT TEACHER MODAL ── -->
<div class="modal-overlay" id="editTeacherModal">
    <div class="modal-box">
        <h3>Edit Teacher</h3>
        <form method="POST" onsubmit="return askActionConfirm(this, 'editTeacher')">
            <input type="hidden" name="edit_teacher" id="editTeacherId" value="">
            <div class="modal-field">
                <label>Title</label>
                <select name="teacher_title" id="editTeacherTitle">
                    <option value="Mr.">Mr.</option>
                    <option value="Mrs.">Mrs.</option>
                    <option value="Ms.">Ms.</option>
                </select>
            </div>
            <div class="modal-field">
                <label>Teacher Name</label>
                <input type="text" name="teacher_name" id="editTeacherName" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn-modal-submit">Save</button>
                <button type="button" class="btn-modal-cancel" onclick="closeModalById('editTeacherModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SCHOOL YEAR MODAL ── -->
<div class="modal-overlay" id="schoolYearModal">
    <div class="modal-box" style="width:360px;">
        <h3>Manage School Years</h3>

        <div class="sy-list">
            <?php if (empty($schoolYears)): ?>
                <div style="font-size:12px;color:#9ca3af;padding:4px 2px;">No school years yet.</div>
            <?php else: ?>
                <?php foreach ($schoolYears as $sy): ?>
                <div class="sy-row">
                    <div>
                        <span class="sy-label"><?= htmlspecialchars($sy['label']) ?></span>
                        <?php if ($sy['is_active']): ?>
                            <span class="sy-badge-active">Active</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($sy['is_active']): ?>
                        <form method="POST">
                            <input type="hidden" name="stop_school_year" value="<?= $sy['id'] ?>">
                            <button type="submit" class="btn-sy-stop"
                                    onclick="return confirm('Stop evaluations for <?= htmlspecialchars($sy['label']) ?>? Students will no longer be able to submit new evaluations for this school year. Past evaluations stay saved.')">
                                Stop
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="activate_school_year" value="<?= $sy['id'] ?>">
                            <button type="submit" class="btn-sy-activate">Activate</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" id="addSyForm" onsubmit="return askAddSchoolYear(this)">
            <input type="hidden" name="add_school_year" value="1">
            <div class="modal-field">
                <label>New School Year</label>
                <select name="new_school_year" id="newSyInput" required>
                    <option value="">Select school year…</option>
                    <?php
                    $syExistingLabels = array_column($schoolYears, 'label');
                    for ($syOpt = $syPresentStart; $syOpt <= $syPresentStart + 4; $syOpt++):
                        $syOptLabel = $syOpt . '-' . ($syOpt + 1);
                        $syTaken    = in_array($syOptLabel, $syExistingLabels, true);
                    ?>
                    <option value="<?= $syOptLabel ?>"<?= $syTaken ? ' disabled' : '' ?>><?= $syOptLabel ?><?= $syTaken ? ' (already added)' : '' ?></option>
                    <?php endfor; ?>
                </select>
                <div id="newSyError" style="display:none;color:#b91c1c;font-size:12px;margin-top:6px;"></div>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn-modal-submit">Add</button>
                <button type="button" class="btn-modal-cancel" onclick="closeSchoolYearModal()">Close</button>
            </div>
        </form>
    </div>
</div>

<!-- ── ADD STUDENT MODAL ── -->
<div class="modal-overlay" id="addStudentModal">
    <div class="modal-box student-modal">

        <div class="student-modal-header">
            <div class="student-modal-icon">🎓</div>
            <div class="student-modal-heading">
                <h3>Add Student</h3>
                <p class="student-modal-subtitle">Enter the student's official record details.</p>
            </div>
        </div>

        <form method="POST" style="display:flex; flex-direction:column; overflow:hidden;">
            <input type="hidden" name="add_student" value="1">

            <div class="student-modal-body">

                <div class="form-section">
                    <div class="form-section-title">Student Identification</div>

                    <div class="modal-field">
                        <label>Subject (Teacher)</label>
                        <select name="subject_id" id="addStudentSubjectPicker" required>
                            <option value="">Select subject…</option>
                            <?php foreach ($teachers as $pt):
                                $pSubs = $teacherSubjects[(int)$pt['id']] ?? [];
                                if (empty($pSubs)) continue; ?>
                            <optgroup label="<?= htmlspecialchars(trim(($pt['title'] ?? '') . ' ' . getTeacherLastName($pt['name']))) ?>">
                                <?php foreach ($pSubs as $ps): ?>
                                <option value="<?= $ps['id'] ?>"><?= htmlspecialchars($ps['subject']) ?> (Year <?= $ps['year_level'] ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-field">
                        <label>Student ID</label>
                        <input type="text" name="student_id" required placeholder="e.g. 2023-00123">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Personal Information</div>

                    <div class="field-grid-2" style="margin-bottom:14px;">
                        <div class="modal-field">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required placeholder="e.g. Dela Cruz">
                        </div>
                        <div class="modal-field">
                            <label>First Name</label>
                            <input type="text" name="first_name" required placeholder="e.g. Juan">
                        </div>
                    </div>

                    <div class="field-grid-2" style="margin-bottom:14px;">
                        <div class="modal-field">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" placeholder="e.g. Santos">
                        </div>
                        <div class="modal-field">
                            <label>Gender</label>
                            <select name="gender" required>
                                <option value="">Select gender…</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-field">
                        <label>Birthday</label>
                        <input type="date" name="birthday" required>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Contact Information</div>

                    <div class="modal-field">
                        <label>Address</label>
                        <input type="text" name="address" required placeholder="e.g. 123 Rizal St., San Jose del Monte, Bulacan">
                    </div>

                    <div class="field-grid-2">
                        <div class="modal-field">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" required placeholder="e.g. 09171234567">
                        </div>
                        <div class="modal-field">
                            <label>Gmail Address</label>
                            <input type="text" name="student_email" required placeholder="e.g. juan.delacruz@gmail.com" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" title="Please enter a valid Gmail address (must end in @gmail.com)">
                        </div>
                    </div>
                </div>

                <?php /* ── Academic Assignment (Assigned Teacher checklist) removed from
                         Add Student per request — student_teacher_ids is simply not
                         submitted anymore. Nothing in the backend was deleted; it
                         just no longer requires/receives this field. ── */ ?>

            </div><!-- /student-modal-body -->

            <div class="student-modal-footer">
                <div class="modal-actions">
                    <button type="submit" class="btn-modal-submit">Add Student</button>
                    <button type="button" class="btn-modal-cancel" onclick="closeAddStudentModal()">Cancel</button>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- ── KNOWN TEACHER NAMES (reuse suggestions across school years) ── -->
<datalist id="knownTeacherNames">
    <?php foreach ($allTeacherNames as $tName): ?>
    <option value="<?= htmlspecialchars($tName) ?>">
    <?php endforeach; ?>
</datalist>

<!-- ── ADD SCHOOL YEAR CONFIRM NOTIF MODAL ── -->
<div class="dc-overlay" id="addSyConfirmModal">
    <div class="dc-box">
        <div class="dc-icon">🗓️</div>
        <h3>Create a new school year?</h3>
        <div class="dc-name" id="addSyConfirmName" style="color:#15665c;"></div>
        <p class="dc-msg">Are you sure you want to create this new school year? It will be added to the list and you can activate it afterwards.</p>
        <div class="dc-actions">
            <button type="button" onclick="closeAddSyConfirm()">Cancel</button>
            <button type="button" class="dc-confirm-ok" onclick="proceedAddSyConfirm()">Yes, Create</button>
        </div>
    </div>
</div>

<script>
/* ── Add school year: no past years + confirmation notif ── */
var SY_PRESENT_START = <?= $syPresentStart ?>;
var syPendingForm = null;
function askAddSchoolYear(form) {
    var val = document.getElementById('newSyInput').value.trim();
    var err = document.getElementById('newSyError');
    var present = SY_PRESENT_START + '-' + (SY_PRESENT_START + 1);
    var m = /^(\d{4})-(\d{4})$/.exec(val);
    err.style.display = 'none';
    if (!m || parseInt(m[2], 10) !== parseInt(m[1], 10) + 1) {
        err.textContent = 'Invalid format. Use YYYY-YYYY (e.g. ' + present + ').';
        err.style.display = 'block';
        return false;
    }
    if (parseInt(m[1], 10) < SY_PRESENT_START) {
        err.textContent = 'Past school years are not allowed. The earliest you can add is ' + present + '.';
        err.style.display = 'block';
        return false;
    }
    document.getElementById('addSyConfirmName').textContent = 'School Year ' + val;
    syPendingForm = form;
    document.getElementById('addSyConfirmModal').classList.add('open');
    return false; // submits only after "Yes, Create"
}
function closeAddSyConfirm() {
    document.getElementById('addSyConfirmModal').classList.remove('open');
    syPendingForm = null;
}
function proceedAddSyConfirm() {
    var f = syPendingForm;
    document.getElementById('addSyConfirmModal').classList.remove('open');
    syPendingForm = null;
    if (f) f.submit();
}
document.getElementById('addSyConfirmModal').addEventListener('click', function (e) {
    if (e.target === this) closeAddSyConfirm();
});
</script>

<!-- ── DELETE CONFIRM NOTIF MODAL ── -->
<div class="dc-overlay" id="deleteConfirmModal">
    <div class="dc-box">
        <div class="dc-icon">⚠️</div>
        <h3 id="dcTitle"></h3>
        <div class="dc-name" id="dcName"></div>
        <p class="dc-msg" id="dcMsg"></p>
        <div class="dc-actions">
            <button type="button" onclick="closeDeleteConfirm()">Cancel</button>
            <button type="button" class="dc-confirm" onclick="proceedDeleteConfirm()">Yes, Delete</button>
        </div>
    </div>
</div>

<script>
/* ── Delete confirmation notif (teacher / subject) ── */
var dcPendingForm = null;
function askDeleteConfirm(form, kind, name) {
    var isTeacher = (kind === 'teacher');
    document.getElementById('dcTitle').textContent = isTeacher ? 'Delete this teacher?' : 'Remove this subject?';
    document.getElementById('dcName').textContent  = name;
    document.getElementById('dcMsg').textContent   = isTeacher
        ? 'This will also delete all of their subjects, student links and evaluations. Student accounts are not deleted. This cannot be undone.'
        : 'This will remove the subject and its student enrollments from this teacher. This cannot be undone.';
    dcPendingForm = form;
    document.getElementById('deleteConfirmModal').classList.add('open');
    return false; // stop the normal submit; it continues only after "Yes, Delete"
}
function closeDeleteConfirm() {
    document.getElementById('deleteConfirmModal').classList.remove('open');
    dcPendingForm = null;
}
function proceedDeleteConfirm() {
    var f = dcPendingForm;
    document.getElementById('deleteConfirmModal').classList.remove('open');
    dcPendingForm = null;
    if (f) f.submit();
}
document.getElementById('deleteConfirmModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteConfirm();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDeleteConfirm();
});
</script>

<script>
/* ── Generic modal helpers ── */
function openModalById(id)  { document.getElementById(id).classList.add('open'); }
function closeModalById(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(function (m) { m.classList.remove('open'); });
});

/* ── Import: show the "new section" box only when "Add new section…" is picked ── */
function toggleNewSection(sel) {
    var box = sel.form.querySelector('.new-section-input');
    var isNew = (sel.value === '__new__');
    box.style.display = isNew ? 'block' : 'none';
    box.required = isNew;
    if (isNew) box.focus(); else box.value = '';
}

/* ── Subject modal: only show the students of the section the admin picked ── */
function filterSubjectStudents(sel) {
    var wrap = sel.closest('.view-wrap');
    var list = wrap.querySelector('.subject-students');
    var hint = wrap.querySelector('.view-hint');
    var v = sel.value;
    if (v === '') { list.style.display = 'none'; hint.style.display = 'block'; return; }
    hint.style.display = 'none';
    list.style.display = '';
    list.querySelectorAll('tbody tr').forEach(function (tr) {
        var sec = tr.getAttribute('data-section') || '';
        var show = (v === '__all__') || (v === '__none__' ? sec === '' : sec === v);
        tr.style.display = show ? '' : 'none';
    });
}

/* ── Teachers ── */
function openAddTeacherModal() { openModalById('addTeacherModal'); }
function openEditTeacherModal(id, name, title) {
    document.getElementById('editTeacherId').value   = id;
    document.getElementById('editTeacherName').value = name;
    document.getElementById('editTeacherTitle').value = title || 'Mr.';
    openModalById('editTeacherModal');
}

/* ── Subjects ── */
function openAddSubjectModal(teacherId) { openModalById('addSubjectModal_' + teacherId); }
function openSubjectModal(subjectId)    { openModalById('subjectModal_' + subjectId); }

/* ── School Year Modal ── */
function openSchoolYearModal()  { openModalById('schoolYearModal'); }
function closeSchoolYearModal() { closeModalById('schoolYearModal'); }

/* ── Add Student Modal ── */
function openAddStudentModal() {
    document.getElementById('addStudentSubjectPicker').value = '';
    openModalById('addStudentModal');
}
function closeAddStudentModal() { closeModalById('addStudentModal'); }
</script>


<!-- ── ACTION CONFIRM NOTIF MODAL (Add Teacher / Add Subject / Edit Teacher) ── -->
<div class="dc-overlay" id="actionConfirmModal">
    <div class="dc-box">
        <div class="dc-icon" style="background:#e6f4f2;" id="acIcon">✔️</div>
        <h3 id="acTitle"></h3>
        <div class="dc-name" id="acName" style="color:#15665c;"></div>
        <p class="dc-msg" id="acMsg"></p>
        <div class="dc-actions">
            <button type="button" onclick="closeActionConfirm()">Cancel</button>
            <button type="button" class="dc-confirm-ok" id="acOk" onclick="proceedActionConfirm()">Yes</button>
        </div>
    </div>
</div>

<script>
/* ── "Are you sure?" notif before Add Teacher / Add Subject / Edit Teacher ── */
var acPendingForm = null;
function askActionConfirm(form, kind, teacherName) {
    var icon = '➕', title = '', name = '', msg = '', ok = 'Yes';

    if (kind === 'addTeacher' || kind === 'editTeacher') {
        var t = form.querySelector('[name="teacher_title"]').value;
        var n = form.querySelector('[name="teacher_name"]').value.trim();
        name = (t + ' ' + n).trim();
        if (kind === 'addTeacher') {
            icon = '➕'; title = 'Add this teacher?';
            msg  = 'Are you sure you want to add this teacher?';
            ok   = 'Yes, Add';
        } else {
            icon = '✏️'; title = 'Save changes to this teacher?';
            msg  = 'Are you sure you want to edit this teacher\u2019s details?';
            ok   = 'Yes, Save';
        }
    } else if (kind === 'addSubject') {
        var picked = [];
        form.querySelectorAll('input[name="subjects[]"]:checked:not(:disabled)').forEach(function (c) {
            picked.push(c.value);
        });
        if (picked.length === 0) return true; // nothing selected: behave exactly as before
        icon = '📚'; title = 'Add these subjects?';
        name = picked.join(', ');
        msg  = 'Are you sure you want to add the selected subject' + (picked.length > 1 ? 's' : '') + ' to ' + teacherName + '?';
        ok   = 'Yes, Add';
    } else {
        return true;
    }

    document.getElementById('acIcon').textContent  = icon;
    document.getElementById('acTitle').textContent = title;
    document.getElementById('acName').textContent  = name;
    document.getElementById('acMsg').textContent   = msg;
    document.getElementById('acOk').textContent    = ok;
    acPendingForm = form;
    document.getElementById('actionConfirmModal').classList.add('open');
    return false; // submits only after the Yes button
}
function closeActionConfirm() {
    document.getElementById('actionConfirmModal').classList.remove('open');
    acPendingForm = null;
}
function proceedActionConfirm() {
    var f = acPendingForm;
    document.getElementById('actionConfirmModal').classList.remove('open');
    acPendingForm = null;
    if (f) f.submit();
}
document.getElementById('actionConfirmModal').addEventListener('click', function (e) {
    if (e.target === this) closeActionConfirm();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeActionConfirm();
});
</script>

</body>
</html>