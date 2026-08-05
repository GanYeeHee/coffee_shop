<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/mailer.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$email = '';
$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $errors['email'] = "Email is required.";
    } else {
        $email_error = validate_email($email);
        if ($email_error) {
            $errors['email'] = $email_error;
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // One active token per user
            $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
            $stmt->execute([$user['id']]);

            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (token, user_id, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$token, $user['id'], $expires_at]);

            $link = base_url('email_reset_password.php?token=' . $token);

            try {
                $mail = get_mail();
                $mail->addAddress($user['email'], $user['full_name']);

                $photo_path = __DIR__ . '/uploads/profiles/' . $user['photo'];
                $has_photo = !empty($user['photo']) && file_exists($photo_path);
                if ($has_photo) {
                    $mail->addEmbeddedImage($photo_path, 'photo');
                }

                $mail->Subject = 'Reset Your Password - The Daily Grind';
                $mail->isHTML(true);
                $mail->Body = "
                    <p>Hi " . htmlspecialchars($user['full_name']) . ",</p>
                    " . ($has_photo ? '<img src="cid:photo" width="80" style="border-radius: 50%;">' : '') . "
                    <p>Click the link below to reset your password (valid for 15 minutes):</p>
                    <p><a href=\"$link\">$link</a></p>
                    <p>If you did not request this, you can safely ignore this email.</p>
                ";

                $mail->send();
            } catch (\Exception $e) {
                // Swallow send failures so we don't reveal whether the account exists
            }
        }

        // Always show the same message, whether or not the email was registered
        $sent = true;
    }
}
?>

<div class="auth-container">
    <h2>Reset Password via Email</h2>

    <?php if ($sent): ?>
        <div class="alert alert-success">
            If that email address is registered, a password reset link has been sent to it.
        </div>
    <?php else: ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            Enter your account email and we'll send you a link to reset your password.
        </p>

        <form action="email_reset_request.php" method="POST" novalidate>
            <?= html_input('email', 'email', $email, 'Email Address', 'Enter your account email', $errors) ?>

            <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Send Reset Link</button>
        </form>
    <?php endif; ?>

    <div class="auth-footer">
        Remembered your password? <a href="login.php">Log In here</a>.
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
