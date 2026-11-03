<?php
session_start();

$conn = new mysqli("localhost", "root", "", "school_system");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/admin_reset_helper.php';
adminResetEnsureTables($conn);

$status  = '';   // '', 'sent', 'error'
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '') {
        $status  = 'error';
        $message = 'Please enter the admin email.';
    } else {
        // Same response whether or not the email matched, so this page
        // can't be used to guess the admin's email.
        $status  = 'sent';
        $message = 'If that email is registered to the admin account, a password reset link has been sent. Please check your inbox (and spam folder).';

        if (hash_equals(strtolower(ADMIN_RECOVERY_EMAIL), $email)) {
            try {
                // Throttle: at most one email per 60 seconds.
                $recent = $conn->query("SELECT COUNT(*) AS c FROM admin_password_resets WHERE created_at > (NOW() - INTERVAL 60 SECOND)");
                $recentCount = $recent ? (int)$recent->fetch_assoc()['c'] : 0;

                if ($recentCount === 0) {
                    $token     = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $mins      = (int)ADMIN_RESET_EXPIRY_MIN;

                    // Any older unused links stop working.
                    $conn->query("UPDATE admin_password_resets SET used = 1 WHERE used = 0");

                    $ins = $conn->prepare("INSERT INTO admin_password_resets (token_hash, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL $mins MINUTE))");
                    $ins->bind_param("s", $tokenHash);
                    $ins->execute();
                    $newId = $ins->insert_id;
                    $ins->close();

                    [$ok, $err] = adminSendResetEmail(ADMIN_RECOVERY_EMAIL, adminBuildResetLink($token));

                    if (!$ok) {
                        // Email couldn't go out — remove the unusable link and tell the admin.
                        $del = $conn->prepare("DELETE FROM admin_password_resets WHERE id = ?");
                        $del->bind_param("i", $newId);
                        $del->execute();
                        $del->close();

                        $status  = 'error';
                        $message = 'The reset email could not be sent right now. Please try again later. (' . $err . ')';
                    }
                }
            } catch (Throwable $e) {
                $status  = 'error';
                $message = 'Something went wrong while preparing the reset link. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — NEUST Teacher Evaluation System</title>
<style><?= adminResetPageStyles() ?></style>
</head>
<body>

<div class="login-card">

    <div class="login-brand">
        <img src="logo.png" alt="NEUST Logo">
        <div class="school-name">Nueva Ecija University<br>of Science and Technology</div>
        <h1>Forgot Admin Password</h1>
        <div class="subtitle">We'll email you a reset link</div>
    </div>

    <form method="POST" class="login-form">

        <?php if ($message !== ''): ?>
        <div class="msg <?= $status === 'sent' ? 'msg-ok' : 'msg-err' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($status !== 'sent'): ?>
        <p class="hint">Enter the admin's registered Gmail (<?= htmlspecialchars(adminMaskEmail(ADMIN_RECOVERY_EMAIL)) ?>) and we'll send a link to set a new password.</p>

        <div class="login-field">
            <label for="email">Admin Email</label>
            <input type="email" id="email" name="email" placeholder="Enter the admin Gmail" autocomplete="email" required>
        </div>

        <button type="submit" class="btn-login">Send Reset Link</button>
        <?php endif; ?>

        <a href="login.php" class="back-link">← Back to Login</a>
    </form>

</div>

</body>
</html>
