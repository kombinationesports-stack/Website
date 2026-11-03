<?php
// Huwag i-cache ang login page, para laging dumaan sa server pag Back/Forward
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

session_start();

$conn = new mysqli("localhost", "root", "", "school_system");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error = "";

// "Forgot password" support (admin) — see admin_reset_helper.php
require_once __DIR__ . '/admin_reset_helper.php';

// Login history (records who logged in, when, and from what device)
require_once __DIR__ . '/login_history_helper.php';

// Brute-force protection (limits wrong login attempts) — see login_throttle_helper.php
require_once __DIR__ . '/login_throttle_helper.php';

/* Kapag napunta sa login page ang naka-login na admin (hal. Back button),
   i-logout siya para kailangan ulit mag-login pag Forward. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SESSION['role'] ?? '') === 'admin') {
    logAdminLogout($conn, 'Back to login page');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    /* ================= BRUTE-FORCE LOCK CHECK =================
       Too many wrong attempts -> blocked for a while. Normal login code is below. */
    $bfWait = bfSecondsLocked($conn, $username);
    if ($bfWait > 0) {
        $error = "Too many failed attempts. Please try again in " . bfFormatWait($bfWait) . ".";
    } else {

    /* ================= ADMIN — password set via "Forgot password" =================
       Only applies after the admin has reset the password at least once.
       Until then, nothing here runs and the original admin login below
       works exactly as before. After a reset, the new password is the one
       that works (the old default is blocked by clearing $password). */
    $adminCustomHash = adminGetCustomPasswordHash($conn);
    if ($username == "admin" && $adminCustomHash !== null) {
        if (password_verify($password, $adminCustomHash)) {
            $_SESSION['role'] = "admin";
            bfClearFailures($conn, $username);
            logLoginHistory($conn, 'admin', 'admin', 'Administrator');
            header("Location: admin.php");
            exit;
        }
        $password = "";
    }

    /* ================= ADMIN ================= */
    if ($username == "admin" && $password == "1234") {

        $_SESSION['role'] = "admin";
        bfClearFailures($conn, $username);
        logLoginHistory($conn, 'admin', 'admin', 'Administrator');
        header("Location: admin.php");
        exit;
    }

    /* ================= STUDENT ================= */
    // Only match a student row that belongs to the CURRENTLY ACTIVE school
    // year. This is what makes activating/deactivating a school year act
    // like enabling/disabling logins: each school year's import creates its
    // own account (its own email + password), so switching the active
    // school year automatically switches which account can log in — no
    // separate "disabled" flag needed. Rows from before school years
    // existed (school_year_id still NULL) are still allowed to log in.
    $stmt = $conn->prepare("
        SELECT s.* FROM students s
        WHERE LOWER(TRIM(s.student_id)) = LOWER(?)
          AND (s.school_year_id = (SELECT id FROM school_years WHERE is_active = 1 LIMIT 1)
               OR s.school_year_id IS NULL)
    ");
    if ($stmt === false) {
        die("Database error: " . $conn->error);
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $student = $stmt->get_result()->fetch_assoc();

    if ($student) {
        // Normal case: password stored as a bcrypt hash (created with password_hash())
        $passwordOk = password_verify($password, $student['password']);

        // Fallback: password stored as plain text in the database
        // (this is what causes "Invalid login" even with correct credentials)
        if (!$passwordOk && $password === $student['password']) {
            $passwordOk = true;
        }
    } else {
        $passwordOk = false;
    }

    /* ================= TEMP DEBUG (only visible with ?debug=1 in the URL) ================= */
    if (isset($_GET['debug'])) {
        echo "<pre style='background:#111827;color:#0f0;padding:20px;font-size:13px;'>";
        echo "DEBUG MODE\n";
        echo "-----------\n";
        echo "Username submitted: " . htmlspecialchars($username) . "\n";
        echo "Student row found in DB: " . ($student ? "YES" : "NO") . "\n";
        if ($student) {
            echo "student_id in DB: " . htmlspecialchars($student['student_id']) . "\n";
            echo "Stored password looks like: " . (str_starts_with($student['password'], '$2y$') ? "bcrypt hash (correct format)" : "PLAIN TEXT or unknown format") . "\n";
            echo "Stored password length: " . strlen($student['password']) . " characters\n";
            echo "password_verify() result: " . (password_verify($password, $student['password']) ? "TRUE" : "FALSE") . "\n";
            echo "Plain-text match result: " . ($password === $student['password'] ? "TRUE" : "FALSE") . "\n";
        } else {
            echo "-> No row matched student_id = '" . htmlspecialchars($username) . "' in the students table.\n";
            echo "-> Check for typos, extra spaces, or a different column name than 'student_id'.\n";
        }
        echo "</pre>";
    }
    /* ================= END TEMP DEBUG ================= */

    if ($student && $passwordOk) {

        $_SESSION['role'] = "student";
        $_SESSION['student_id'] = $student['student_id'];
        bfClearFailures($conn, $username);
        logLoginHistory(
            $conn, 'student', $student['student_id'], $student['name'] ?? null,
            $student['course'] ?? null,
            $student['section'] ?? null,
            loginHistorySchoolYearLabel($conn, $student['school_year_id'] ?? null)
        );

        // [CHANGED] was student.php → now Student_dashboard.php
        header("Location: Student_dashboard.php");
        exit;
    }

    $error = "Invalid login";
    bfRecordFailure($conn, $username);
    $bfWait = bfSecondsLocked($conn, $username);
    if ($bfWait > 0) {
        $error = "Too many failed attempts. Please try again in " . bfFormatWait($bfWait) . ".";
    }
    } // end of brute-force lock check
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — NEUST Teacher Evaluation System</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, 'Segoe UI', sans-serif;
    background: #f5f7fb;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

/* ── LOGIN CARD ── */
.login-card {
    width: 380px;
    max-width: 100%;
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,0.12);
}

/* ── BRAND STRIP ── */
.login-brand {
    background: #111827;
    padding: 34px 30px 26px;
    text-align: center;
    border-bottom: 4px solid #d4a843;
}

.login-brand img {
    width: 74px;
    height: 74px;
    border-radius: 50%;
    background: #fff;
    padding: 4px;
    object-fit: contain;
    margin-bottom: 14px;
}

.login-brand .school-name {
    color: #d4a843;
    font-size: 10.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    line-height: 1.5;
    margin-bottom: 6px;
}

.login-brand h1 {
    color: #fff;
    font-size: 19px;
    font-weight: bold;
    letter-spacing: 0.2px;
}

.login-brand .subtitle {
    color: #9ca3af;
    font-size: 12.5px;
    margin-top: 4px;
}

/* ── FORM ── */
.login-form {
    padding: 30px 32px 34px;
}

.login-field {
    margin-bottom: 18px;
}

.login-field label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 7px;
}

.login-field input {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    color: #111827;
    background: #f9fafb;
    outline: none;
    transition: border-color 0.15s, background 0.15s;
}

.login-field input:focus {
    border-color: #1a7f74;
    background: #fff;
}

.btn-login {
    width: 100%;
    background: #1a7f74;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 12px;
    font-size: 14px;
    font-weight: bold;
    font-family: inherit;
    cursor: pointer;
    margin-top: 6px;
    transition: background 0.15s;
}

.btn-login:hover { background: #15665c; }

.login-error {
    margin-top: 14px;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 13px;
    text-align: center;
}

.login-footer {
    margin-top: 22px;
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
}

.login-forgot { margin-top: 14px; text-align: center; font-size: 12.5px; }
.login-forgot a { color: #1a7f74; text-decoration: none; }
.login-forgot a:hover { text-decoration: underline; }

.login-success {
    margin-top: 14px;
    background: #e6f4f2;
    border: 1px solid #b7ddd8;
    color: #15665c;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 13px;
    text-align: center;
}
</style>
</head>
<body>

<div class="login-card">

    <div class="login-brand">
        <img src="logo.png" alt="NEUST Logo">
        <div class="school-name">Nueva Ecija University<br>of Science and Technology</div>
        <h1>Teacher Evaluation System</h1>
        <div class="subtitle">Sign in to continue</div>
    </div>

    <form method="POST" class="login-form">

        <div class="login-field">
            <label for="username">Admin / Student ID</label>
            <input type="text" id="username" name="username" placeholder="Enter your ID" autocomplete="username" required>
        </div>

        <div class="login-field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn-login">Login</button>

        <div class="login-forgot"><a href="forgot_password.php">Forgot Password?</a></div>

        <?php if (isset($_GET['reset'])): ?>
        <div class="login-success">Password updated. You can now log in with your new password.</div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
        <div class="login-error">You were logged out because of 3 minutes of inactivity.</div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

    </form>

    <div class="login-footer">© <?= date('Y') ?> NEUST Teacher Evaluation System</div>

</div>

<script>
// Kapag galing sa Back/Forward cache ang page, i-reload para dumaan sa server
window.addEventListener('pageshow', function (e) {
    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (e.persisted || (nav && nav.type === 'back_forward')) { location.reload(); }
});
</script>

</body>
</html>