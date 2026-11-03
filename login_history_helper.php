<?php
/* ============================================================
   LOGIN HISTORY — shared helper
   Used by: login.php (records a login), login_history.php (shows them)
   Purely additive — creates its own table and touches nothing else.
   ============================================================ */

/* Creates the table the first time (safe to call every time). */
function loginHistoryEnsureTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS login_history (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            role         VARCHAR(10)  NOT NULL,
            user_id      VARCHAR(50)  NOT NULL,
            user_name    VARCHAR(150) NULL,
            device       VARCHAR(255) NULL,
            ip_address   VARCHAR(45)  NULL,
            logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_role_time (role, logged_in_at)
        ) ENGINE=InnoDB");

        /* NEW: course / section / school year of the account used.
           Adds the columns to an already-existing login_history table
           (old rows keep NULL and just show "—"). Safe to run every time. */
        $newCols = [
            'course'      => 'VARCHAR(100) NULL',
            'section'     => 'VARCHAR(10)  NULL',
            'school_year' => 'VARCHAR(20)  NULL',
        ];
        foreach ($newCols as $col => $def) {
            $chk = $conn->query("SHOW COLUMNS FROM login_history LIKE '$col'");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("ALTER TABLE login_history ADD COLUMN $col $def");
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* Turns the browser's User-Agent into something readable,
   e.g. "Chrome on Windows • Desktop" or "Chrome on Android • Mobile (SM-A546E)".
   Returns null if the browser didn't send anything. */
function loginHistoryParseDevice($ua) {
    $ua = trim($ua ?? '');
    if ($ua === '') return null;

    // Browser
    if     (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgA/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';
    else $browser = 'Unknown browser';

    // Operating system
    if     (stripos($ua, 'Windows') !== false)                                  $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false)                                  $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua))                             $os = 'iOS';
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($ua, 'CrOS') !== false)                                     $os = 'ChromeOS';
    elseif (stripos($ua, 'Linux') !== false)                                    $os = 'Linux';
    else $os = 'Unknown OS';

    // Device type
    if     (preg_match('/iPad|Tablet/i', $ua))                  $type = 'Tablet';
    elseif (preg_match('/Mobi|Android|iPhone|iPod/i', $ua))     $type = 'Mobile';
    else                                                        $type = 'Desktop';

    $device = $browser . ' on ' . $os . ' • ' . $type;

    // Phone model when the browser shares it (Android only)
    if ($os === 'Android' && preg_match('/Android [\d\.]+; ([^;\)]+)/', $ua, $m)) {
        $model = trim($m[1]);
        if ($model !== '' && $model !== 'K' && stripos($model, 'Linux') === false) {
            $device .= ' (' . $model . ')';
        }
    }
    return $device;
}

/* Records one successful login. Never throws — a problem here must
   never stop someone from logging in.
   Duplicate guard: if the same user was already recorded in the last
   10 seconds (double-submit / duplicate request), this does nothing, so
   one login = one history row. A short lock makes this safe even when
   the two requests arrive at the same moment. */
function logLoginHistory($conn, $role, $userId, $userName = null, $course = null, $section = null, $schoolYear = null) {
    $lockName = null;
    try {
        if (!loginHistoryEnsureTable($conn)) return;

        $lockName = 'login_history_' . md5($role . '|' . $userId);
        $lockStmt = $conn->prepare("SELECT GET_LOCK(?, 3)");
        $lockStmt->bind_param("s", $lockName);
        $lockStmt->execute();
        $lockStmt->close();

        $chk = $conn->prepare("SELECT id FROM login_history WHERE role = ? AND user_id = ? AND logged_in_at >= (NOW() - INTERVAL 10 SECOND) LIMIT 1");
        $chk->bind_param("ss", $role, $userId);
        $chk->execute();
        $already = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$already) {
            $device = loginHistoryParseDevice($_SERVER['HTTP_USER_AGENT'] ?? '');
            $ip     = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

            $stmt = $conn->prepare("INSERT INTO login_history (role, user_id, user_name, device, ip_address, course, section, school_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $role, $userId, $userName, $device, $ip, $course, $section, $schoolYear);
            $stmt->execute();
            $stmt->close();

            /* NEW: an admin login is also written to the Activity Logs. */
            if ($role === 'admin') {
                require_once __DIR__ . '/admin_activity_helper.php';
                logAdminActivity($conn, 'Login', 'Admin logged in', 'Successful admin login', 'success', $userId);
            }
        }
    } catch (Throwable $e) {
        // ignore
    } finally {
        try {
            if ($lockName !== null) {
                $rel = $conn->prepare("SELECT RELEASE_LOCK(?)");
                $rel->bind_param("s", $lockName);
                $rel->execute();
                $rel->close();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/* NEW: returns the school-year label (e.g. "2026-2027") for a school_years id,
   or null if the id is empty / not found. Never throws. */
function loginHistorySchoolYearLabel($conn, $schoolYearId) {
    try {
        if ($schoolYearId === null || $schoolYearId === '') return null;
        $id   = (int)$schoolYearId;
        $stmt = $conn->prepare("SELECT label FROM school_years WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row['label'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ============================================================
   NEW: ADMIN LOGOUT HISTORY
   Used by: logout.php (manual logout), admin_session_guard.php (idle auto-logout),
            logout_history.php (viewer)
   ============================================================ */

function logoutHistoryEnsureTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS admin_logout_history (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       VARCHAR(50)  NOT NULL,
            reason        VARCHAR(30)  NOT NULL DEFAULT 'Manual logout',
            device        VARCHAR(255) NULL,
            ip_address    VARCHAR(45)  NULL,
            logged_out_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_time (logged_out_at)
        ) ENGINE=InnoDB");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* Records ONE admin logout. Call it BEFORE session_destroy().
   $reason: 'Manual logout' or 'Idle timeout'. Never throws.
   $conn can be null — it will open its own connection. */
function logAdminLogout($conn = null, $reason = 'Manual logout') {
    try {
        if (($_SESSION['role'] ?? '') !== 'admin') return;

        $userId = 'admin';
        foreach (['username', 'admin_username', 'admin_name', 'user_name', 'user_id', 'admin_id', 'name'] as $k) {
            if (!empty($_SESSION[$k]) && is_scalar($_SESSION[$k])) {
                $userId = substr(trim((string)$_SESSION[$k]), 0, 50);
                break;
            }
        }

        if ($conn === null) {
            $conn = new mysqli("localhost", "root", "", "school_system");
            if ($conn->connect_error) return;
        }
        if (!logoutHistoryEnsureTable($conn)) return;

        // Duplicate guard: same admin logged out in the last 10 seconds → skip
        $chk = $conn->prepare("SELECT id FROM admin_logout_history WHERE user_id = ? AND logged_out_at >= (NOW() - INTERVAL 10 SECOND) LIMIT 1");
        $chk->bind_param("s", $userId);
        $chk->execute();
        $already = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($already) return;

        $device = loginHistoryParseDevice($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip     = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $reason = substr((string)$reason, 0, 30);

        $stmt = $conn->prepare("INSERT INTO admin_logout_history (user_id, reason, device, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $userId, $reason, $device, $ip);
        $stmt->execute();
        $stmt->close();

        // Also show it in Activity Logs (same as admin login)
        require_once __DIR__ . '/admin_activity_helper.php';
        logAdminActivity($conn, 'Login', 'Admin logged out', $reason, 'success', $userId);
    } catch (Throwable $e) {
        // ignore — must never stop a logout
    }
}