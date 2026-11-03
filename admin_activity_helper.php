<?php
/* ============================================================
   ADMIN ACTIVITY LOGS — shared helper
   Used by: admin_backend.php, admin_questions.php,
            login_history_helper.php (admin login), activity_logs.php (viewer)
   Purely additive — creates its own table and touches nothing else.
   Never throws: a problem here must never stop an admin action.
   ============================================================ */

/* Creates the table the first time (safe to call every time). */
function activityLogEnsureTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            admin_name  VARCHAR(100) NOT NULL,
            category    VARCHAR(30)  NOT NULL DEFAULT 'General',
            action      VARCHAR(100) NOT NULL,
            details     TEXT NULL,
            status      VARCHAR(10)  NOT NULL DEFAULT 'success',
            ip_address  VARCHAR(45)  NULL,
            device      VARCHAR(255) NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created (created_at),
            KEY idx_category_time (category, created_at)
        ) ENGINE=InnoDB");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* Who is the admin? Tries the common session keys, falls back to "admin". */
function activityLogAdminName() {
    foreach (['username', 'admin_username', 'admin_name', 'user_name', 'user_id', 'admin_id', 'name'] as $k) {
        if (!empty($_SESSION[$k]) && is_scalar($_SESSION[$k])) {
            return substr(trim((string)$_SESSION[$k]), 0, 100);
        }
    }
    return 'admin';
}

/* Records ONE admin action.
   $category : School Year | Teacher | Subject | Student | Questioner | Login
   $action   : short title, e.g. "Added teacher"
   $details  : what exactly was done (names, counts, old -> new values)
   $status   : 'success' or 'failed'
   $adminName: optional — only pass it when the session isn't set yet (e.g. right at login) */
function logAdminActivity($conn, $category, $action, $details = '', $status = 'success', $adminName = null) {
    try {
        if (!activityLogEnsureTable($conn)) return;

        $name = ($adminName !== null && $adminName !== '') ? substr((string)$adminName, 0, 100) : activityLogAdminName();
        $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

        if (!function_exists('loginHistoryParseDevice')) {
            @require_once __DIR__ . '/login_history_helper.php';
        }
        $device = function_exists('loginHistoryParseDevice')
            ? loginHistoryParseDevice($_SERVER['HTTP_USER_AGENT'] ?? '')
            : null;

        $category = substr((string)$category, 0, 30);
        $action   = substr((string)$action, 0, 100);
        $details  = (string)$details;
        $status   = ($status === 'failed') ? 'failed' : 'success';

        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_name, category, action, details, status, ip_address, device) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $category, $action, $details, $status, $ip, $device);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // ignore — logging must never break the page
    }
}

/* Returns the school-year label for an id (or "#id" if not found). Never throws. */
function activityLogSyLabel($conn, $schoolYearId) {
    try {
        $id   = (int)$schoolYearId;
        $stmt = $conn->prepare("SELECT label FROM school_years WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row['label'] : ('#' . $id);
    } catch (Throwable $e) {
        return '#' . (int)$schoolYearId;
    }
}
