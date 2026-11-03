<?php
/* ============================================================
   ADMIN "FORGOT PASSWORD" — shared helper
   Used by: login.php, forgot_password.php, reset_password.php
   Purely additive — does not touch any existing table or file logic.
   ============================================================ */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/* The admin's recovery Gmail — the reset link is sent HERE. */
define('ADMIN_RECOVERY_EMAIL', 'nvmm2152@gmail.com');

/* How long a reset link stays valid (minutes). */
define('ADMIN_RESET_EXPIRY_MIN', 30);

/* SMTP account the system SENDS the email from.
   These are the same Gmail sender settings already used by
   admin_backend.php (the one that emails student accounts), so no new
   setup is needed. If you ever want the email to be sent FROM
   nvmm2152@gmail.com instead, put that Gmail's "App Password" here. */
define('ADMIN_RESET_SMTP_USER', 'donglopez041@gmail.com');
define('ADMIN_RESET_SMTP_PASS', 'dbqzacfmoyjarngg');

/* Creates the two small tables this feature needs (safe to call every time). */
function adminResetEnsureTables($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS admin_settings (
            id            TINYINT NOT NULL PRIMARY KEY,
            password_hash VARCHAR(255) NOT NULL,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $conn->query("CREATE TABLE IF NOT EXISTS admin_password_resets (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            token_hash  CHAR(64) NOT NULL,
            expires_at  DATETIME NOT NULL,
            used        TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_token_hash (token_hash)
        ) ENGINE=InnoDB");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* Returns the admin's NEW password hash (set through "Forgot password"),
   or null if the admin has never reset it (then the original login keeps
   working exactly as before). */
function adminGetCustomPasswordHash($conn) {
    try {
        if (!adminResetEnsureTables($conn)) return null;
        $res = $conn->query("SELECT password_hash FROM admin_settings WHERE id = 1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['password_hash'];
        }
    } catch (Throwable $e) {
        // fall through
    }
    return null;
}

/* e.g. nvmm2152@gmail.com -> n*******2@gmail.com */
function adminMaskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    if (strlen($local) <= 2) return $local[0] . '*@' . $parts[1];
    return $local[0] . str_repeat('*', strlen($local) - 2) . substr($local, -1) . '@' . $parts[1];
}

/* Builds http(s)://host/folder/reset_password.php?token=... */
function adminBuildResetLink($token) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $dir . '/reset_password.php?token=' . $token;
}

/* Sends the reset email. Returns [true, ''] on success or [false, 'reason']. */
function adminSendResetEmail($toEmail, $link) {
    $base = __DIR__ . '/PHPMailer/';
    foreach (['PHPMailer.php', 'SMTP.php', 'Exception.php'] as $f) {
        if (!file_exists($base . $f)) {
            return [false, 'PHPMailer files were not found in the PHPMailer folder.'];
        }
    }
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';
    require_once $base . 'Exception.php';

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = ADMIN_RESET_SMTP_USER;
        $mail->Password   = ADMIN_RESET_SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom(ADMIN_RESET_SMTP_USER, 'NEUST Teacher Evaluation System');
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Admin Password Reset';

        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $mins     = (int)ADMIN_RESET_EXPIRY_MIN;
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:480px;\">
                <h3>Admin Password Reset</h3>
                <p>We received a request to reset the admin password for the
                   NEUST Teacher Evaluation System.</p>
                <p><a href=\"$safeLink\" style=\"display:inline-block;background:#1a7f74;color:#fff;
                   padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;\">
                   Reset Password</a></p>
                <p style=\"font-size:13px;color:#555;\">This link expires in $mins minutes and can be used once.
                   If the button doesn't work, copy this link into your browser:<br>$safeLink</p>
                <p style=\"font-size:13px;color:#555;\">If you didn't request this, you can ignore this email
                   — your password will stay the same.</p>
            </div>";
        $mail->AltBody = "Reset the admin password using this link (valid $mins minutes, one use): $link";

        $mail->send();
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/* Shared card styling so both new pages match login.php */
function adminResetPageStyles() {
    return '
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, "Segoe UI", sans-serif; background: #f5f7fb; min-height: 100vh;
       display: flex; align-items: center; justify-content: center; padding: 20px; }
.login-card { width: 380px; max-width: 100%; background: #fff; border-radius: 14px; overflow: hidden;
              box-shadow: 0 10px 40px rgba(0,0,0,0.12); }
.login-brand { background: #111827; padding: 30px 30px 22px; text-align: center; border-bottom: 4px solid #d4a843; }
.login-brand img { width: 64px; height: 64px; border-radius: 50%; background: #fff; padding: 4px;
                   object-fit: contain; margin-bottom: 12px; }
.login-brand .school-name { color: #d4a843; font-size: 10.5px; font-weight: bold; text-transform: uppercase;
                            letter-spacing: 0.7px; line-height: 1.5; margin-bottom: 6px; }
.login-brand h1 { color: #fff; font-size: 18px; font-weight: bold; }
.login-brand .subtitle { color: #9ca3af; font-size: 12.5px; margin-top: 4px; }
.login-form { padding: 26px 32px 30px; }
.login-field { margin-bottom: 18px; }
.login-field label { display: block; font-size: 11px; font-weight: bold; text-transform: uppercase;
                     letter-spacing: 0.6px; color: #6b7280; margin-bottom: 7px; }
.login-field input { width: 100%; padding: 11px 14px; border: 1px solid #e5e7eb; border-radius: 8px;
                     font-size: 14px; font-family: inherit; color: #111827; background: #f9fafb; outline: none; }
.login-field input:focus { border-color: #1a7f74; background: #fff; }
.btn-login { width: 100%; background: #1a7f74; color: #fff; border: none; border-radius: 8px; padding: 12px;
             font-size: 14px; font-weight: bold; font-family: inherit; cursor: pointer; margin-top: 4px; }
.btn-login:hover { background: #15665c; }
.msg { margin-bottom: 16px; border-radius: 8px; padding: 10px 12px; font-size: 13px; line-height: 1.5; }
.msg-ok  { background: #e6f4f2; border: 1px solid #b7ddd8; color: #15665c; }
.msg-err { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
.back-link { display: block; margin-top: 18px; text-align: center; font-size: 13px; color: #1a7f74; text-decoration: none; }
.back-link:hover { text-decoration: underline; }
.hint { font-size: 12px; color: #6b7280; margin-bottom: 16px; line-height: 1.5; }
';
}
