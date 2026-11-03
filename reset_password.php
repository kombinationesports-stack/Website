<?php
session_start();

$conn = new mysqli("localhost", "root", "", "school_system");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/admin_reset_helper.php';
adminResetEnsureTables($conn);

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$token = is_string($token) ? trim($token) : '';

$tokenValid = false;
$resetId    = 0;
$error      = '';

/* ── Is this reset link real, unused, and not expired? ── */
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $tokenHash = hash('sha256', $token);
        $stmt = $conn->prepare("SELECT id FROM admin_password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $tokenValid = true;
            $resetId    = (int)$row['id'];
        }
    } catch (Throwable $e) {
        $tokenValid = false;
    }
}

/* ── Save the new password ── */
if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $newPass     = $_POST['new_password']     ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);

            $save = $conn->prepare("INSERT INTO admin_settings (id, password_hash) VALUES (1, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
            $save->bind_param("s", $hash);
            $save->execute();
            $save->close();

            // This link (and any other open ones) can't be used again.
            $conn->query("UPDATE admin_password_resets SET used = 1 WHERE used = 0");

            header("Location: login.php?reset=1");
            exit;
        } catch (Throwable $e) {
            $error = 'Could not save the new password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — NEUST Teacher Evaluation System</title>
<style><?= adminResetPageStyles() ?></style>
</head>
<body>

<div class="login-card">

    <div class="login-brand">
        <img src="logo.png" alt="NEUST Logo">
        <div class="school-name">Nueva Ecija University<br>of Science and Technology</div>
        <h1>Reset Admin Password</h1>
        <div class="subtitle">Choose a new password</div>
    </div>

    <?php if ($tokenValid): ?>
    <form method="POST" class="login-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <?php if ($error !== ''): ?>
        <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="login-field">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" placeholder="At least 6 characters" autocomplete="new-password" required minlength="6">
        </div>

        <div class="login-field">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter the new password" autocomplete="new-password" required minlength="6">
        </div>

        <button type="submit" class="btn-login">Save New Password</button>
        <a href="login.php" class="back-link">← Back to Login</a>
    </form>
    <?php else: ?>
    <div class="login-form">
        <div class="msg msg-err">This reset link is invalid or has expired. Please request a new one.</div>
        <a href="forgot_password.php" class="btn-login" style="display:block;text-align:center;text-decoration:none;">Request New Link</a>
        <a href="login.php" class="back-link">← Back to Login</a>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
