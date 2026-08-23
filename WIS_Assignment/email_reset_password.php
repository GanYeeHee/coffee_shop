<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

// Clean up expired tokens first
$pdo->exec("DELETE FROM password_reset_tokens WHERE expires_at < NOW()");

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$errors = [];
$success = false;

$stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = ?");
$stmt->execute([$token]);
$reset_token = $stmt->fetch();

if ($reset_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $req_fields = [
        'new_password' => 'New Password',
        'confirm_password' => 'Confirm Password',
    ];
    $errors = validate_required($_POST, $req_fields);

    if (empty($errors['new_password'])) {
        if (strlen($new_password) < 6) {
            $errors['new_password'] = "Password must be at least 6 characters.";
        } elseif ($new_password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match.";
        }
    }

    if (empty($errors)) {
        $hashed_pw = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_pw, $reset_token['user_id']]);

        // Proving identity via the emailed token also clears any login lockout
        reset_login_attempts($pdo, $reset_token['user_id']);

        $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE token = ?");
        $stmt->execute([$token]);

        $_SESSION['flash_success'] = "Password reset successful! You can now log in.";
        header("Location: login.php");
        exit;
    }
}
?>

<div class="auth-container">
    <h2>Reset Your Password</h2>

    <?php if (!$reset_token): ?>
        <div class="alert alert-danger">
            This reset link is invalid or has expired.
        </div>
        <div class="auth-footer">
            <a href="email_reset_request.php">Request a new reset link</a>.
        </div>
    <?php else: ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            Choose a new password for your account.
        </p>

        <form action="email_reset_password.php" method="POST" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <?= html_input('password', 'new_password', '', 'New Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
            </div>

            <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Reset Password</button>
        </form>

        <div class="auth-footer">
            Remembered your password? <a href="login.php">Log In here</a>.
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
