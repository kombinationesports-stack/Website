<?php
/* ============================================================
   LOGIN THROTTLE (anti brute-force) — shared helper
   Used by: login.php
   Purely additive — creates its own table and touches nothing else.
   If anything here fails, it "fails open" so it can never stop a
   legitimate person from logging in.
   ============================================================ */

/* Max wrong attempts for the SAME username from the SAME device/IP. */
define('BF_MAX_ATTEMPTS', 5);

/* Max wrong attempts from ONE IP across ALL usernames (stops an attacker
   from rotating usernames to get around the limit above). */
define('BF_MAX_PER_IP', 20);

/* Failures are counted in this window, and the lock lasts this long
   after the last failed attempt (minutes). */
define('BF_LOCK_MIN', 15);

function bfEnsureTable($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45)  NOT NULL,
            username     VARCHAR(100) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ip_user_time (ip, username, attempted_at),
            KEY idx_ip_time (ip, attempted_at)
        ) ENGINE=InnoDB");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function bfIp() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function bfUser($username) {
    return strtolower(substr(trim((string)$username), 0, 100));
}

/* Returns how many seconds the person is still locked out (0 = not locked). */
function bfSecondsLocked($conn, $username) {
    try {
        if (!bfEnsureTable($conn)) return 0;

        $ip   = bfIp();
        $user = bfUser($username);
        $min  = (int)BF_LOCK_MIN;

        // 1) Same IP + same username
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c,
                   GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL $min MINUTE))) AS wait_s
            FROM login_attempts
            WHERE ip = ? AND username = ? AND attempted_at > (NOW() - INTERVAL $min MINUTE)
        ");
        $stmt->bind_param("ss", $ip, $user);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $wait = 0;
        if ((int)$a['c'] >= BF_MAX_ATTEMPTS) {
            $wait = max($wait, (int)$a['wait_s']);
        }

        // 2) Same IP, any username
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c,
                   GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL $min MINUTE))) AS wait_s
            FROM login_attempts
            WHERE ip = ? AND attempted_at > (NOW() - INTERVAL $min MINUTE)
        ");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $b = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)$b['c'] >= BF_MAX_PER_IP) {
            $wait = max($wait, (int)$b['wait_s']);
        }

        return $wait;
    } catch (Throwable $e) {
        return 0;
    }
}

/* Records one failed login. */
function bfRecordFailure($conn, $username) {
    try {
        if (!bfEnsureTable($conn)) return;

        $ip   = bfIp();
        $user = bfUser($username);

        $stmt = $conn->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)");
        $stmt->bind_param("ss", $ip, $user);
        $stmt->execute();
        $stmt->close();

        // Housekeeping: forget attempts older than a day.
        $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Throwable $e) {
        // ignore
    }
}

/* Called after a successful login — wipes that person's failed attempts. */
function bfClearFailures($conn, $username) {
    try {
        if (!bfEnsureTable($conn)) return;

        $ip   = bfIp();
        $user = bfUser($username);

        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ? AND username = ?");
        $stmt->bind_param("ss", $ip, $user);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // ignore
    }
}

/* "5 minutes" / "1 minute" / "45 seconds" for the lock message. */
function bfFormatWait($seconds) {
    $seconds = max(1, (int)$seconds);
    if ($seconds < 60) return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    $m = (int)ceil($seconds / 60);
    return $m . ' minute' . ($m === 1 ? '' : 's');
}
