<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/mailer.php';

// Already signed in: admins go to the dashboard, members to the storefront.
if (is_admin()) {
    header("Location: index.php");
    exit;
}
if (is_logged_in()) {
    header("Location: ../index.php");
    exit;
}

// "Start over" link on the code screen.
if (isset($_GET['restart'])) {
    unset($_SESSION['admin_2fa_pending']);
    header("Location: login.php");
    exit;
}

// Housekeeping: drop stale codes (mirrors email_reset_password.php).
$pdo->exec("DELETE FROM admin_login_otps WHERE expires_at < NOW()");

$TWOFA_WINDOW  = 900; // seconds a pending login may sit on the code screen
$OTP_MAX_TRIES = 5;

$errors   = [];
$notice   = null;
$username = '';
$dev_code = null; // populated only when ADMIN_OTP_DEV_DISPLAY is on

// Issue a fresh 6-digit code for a user: replace any existing row, store only the hash.
$issue_code = function ($pdo, $user_id) {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $pdo->prepare("DELETE FROM admin_login_otps WHERE user_id = ?")->execute([$user_id]);
    $stmt = $pdo->prepare(
        "INSERT INTO admin_login_otps (user_id, code_hash, expires_at)
         VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)"
    );
    $stmt->execute([$user_id, $hash]);
    return $code;
};

$mask_email = function ($email) {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    $name  = $parts[0];
    $first = substr($name, 0, 1);
    return $first . str_repeat('*', max(1, strlen($name) - 1)) . '@' . $parts[1];
};

// A pending 2FA session decides whether we show step 1 (credentials) or step 2 (code).
$pending = $_SESSION['admin_2fa_pending'] ?? null;
if ($pending && (time() - ($pending['started'] ?? 0)) > $TWOFA_WINDOW) {
    unset($_SESSION['admin_2fa_pending']);
    $pending = null;
    $errors['general'] = "Your sign-in timed out. Please start again.";
}
$step = $pending ? 2 : 1;

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST  = sanitize_input($_POST);
    $action = $_POST['action'] ?? '';

    if ($action === 'credentials') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $errors = validate_required($_POST, [
            'username' => 'Username or Email',
            'password' => 'Password',
        ]);

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user || !in_array($user['role'], ['admin', 'super_admin'], true)) {
                // Don't reveal whether the password was wrong or the account isn't an admin.
                $errors['general'] = "Invalid credentials, or this account is not an administrator.";
            } elseif ($user['status'] === 'blocked') {
                $errors['general'] = "Your account has been blocked by an administrator.";
            } elseif (is_account_locked($user)) {
                $minutes = get_lockout_minutes_remaining($user);
                $errors['general'] = "Too many failed login attempts. Please try again in $minutes minute(s).";
            } elseif (password_verify($password, $user['password'])) {
                reset_login_attempts($pdo, $user['id']);
                try {
                    $code = $issue_code($pdo, $user['id']);
                    send_admin_otp($user, $code);

                    $_SESSION['admin_2fa_pending'] = ['user_id' => $user['id'], 'started' => time()];
                    $pending = $_SESSION['admin_2fa_pending'];
                    $step    = 2;
                    if (ADMIN_OTP_DEV_DISPLAY) {
                        $dev_code = $code;
                    }
                } catch (\Exception $e) {
                    $pdo->prepare("DELETE FROM admin_login_otps WHERE user_id = ?")->execute([$user['id']]);
                    $errors['general'] = "We could not send your verification code by email. Please try again.";
                }
            } else {
                $remaining = record_failed_login($pdo, $user);
                if ($remaining > 0) {
                    $errors['general'] = "Invalid credentials. $remaining attempt(s) remaining before lockout.";
                } else {
                    $errors['general'] = "Too many failed login attempts. Please try again in " . LOCKOUT_MINUTES . " minute(s).";
                }
            }
        }
    } elseif ($action === 'resend' || $action === 'verify_otp') {
        if (!$pending) {
            $errors['general'] = "Your sign-in session expired. Please start again.";
            $step = 1;
        } else {
            $user_id = $pending['user_id'];
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if (!$user || !in_array($user['role'], ['admin', 'super_admin'], true) || $user['status'] === 'blocked') {
                unset($_SESSION['admin_2fa_pending']);
                $pending = null;
                $step = 1;
                $errors['general'] = "This account can no longer sign in. Please contact the super administrator.";
            } elseif ($action === 'resend') {
                $step = 2;
                try {
                    $code = $issue_code($pdo, $user_id);
                    send_admin_otp($user, $code);
                    $_SESSION['admin_2fa_pending']['started'] = time();
                    $pending = $_SESSION['admin_2fa_pending'];
                    $notice  = "A new code has been sent to your email.";
                    if (ADMIN_OTP_DEV_DISPLAY) {
                        $dev_code = $code;
                    }
                } catch (\Exception $e) {
                    $errors['general'] = "We could not send a new code. Please try again.";
                }
            } else { // verify_otp
                $step = 2;
                $otp  = $_POST['otp'] ?? '';

                $row = $pdo->prepare("SELECT * FROM admin_login_otps WHERE user_id = ?");
                $row->execute([$user_id]);
                $otp_row = $row->fetch();

                if (!$otp_row || strtotime($otp_row['expires_at']) <= time()) {
                    $pdo->prepare("DELETE FROM admin_login_otps WHERE user_id = ?")->execute([$user_id]);
                    $errors['otp'] = "That code has expired. Request a new one below.";
                } else {
                    $attempts = $otp_row['attempts'] + 1;
                    $pdo->prepare("UPDATE admin_login_otps SET attempts = ? WHERE user_id = ?")
                        ->execute([$attempts, $user_id]);

                    if (password_verify($otp, $otp_row['code_hash'])) {
                        $pdo->prepare("DELETE FROM admin_login_otps WHERE user_id = ?")->execute([$user_id]);
                        unset($_SESSION['admin_2fa_pending']);
                        login_user($user);
                        reset_login_attempts($pdo, $user['id']);
                        header("Location: index.php");
                        exit;
                    } elseif ($attempts >= $OTP_MAX_TRIES) {
                        $pdo->prepare("DELETE FROM admin_login_otps WHERE user_id = ?")->execute([$user_id]);
                        unset($_SESSION['admin_2fa_pending']);
                        $pending = null;
                        $step = 1;
                        $errors['general'] = "Too many incorrect codes. Please sign in again.";
                    } else {
                        $left = $OTP_MAX_TRIES - $attempts;
                        $errors['otp'] = "Incorrect code. $left attempt(s) remaining.";
                    }
                }
            }
        }
    }
}

// Masked destination address for the code screen.
$pending_email_hint = null;
if ($step === 2 && $pending) {
    $pe = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $pe->execute([$pending['user_id']]);
    $addr = $pe->fetchColumn();
    if ($addr) {
        $pending_email_hint = $mask_email($addr);
    }
}
?>

<div class="auth-container">
    <h2>Admin Login</h2>

    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            Enter your administrator credentials. We'll email you a 6-digit code to finish signing in.
        </p>

        <form action="login.php" method="POST" novalidate>
            <input type="hidden" name="action" value="credentials">
            <?= html_input('text', 'username', $username, 'Username or Email', 'Enter your username or email', $errors) ?>
            <?= html_input('password', 'password', '', 'Password', 'Enter your password', $errors) ?>

            <button type="submit" class="btn btn-accent btn-block">Continue &rarr;</button>
        </form>

        <div class="auth-footer">
            Not an administrator? <a href="../login.php">Member login</a>.
        </div>
    <?php else: ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            We've emailed a 6-digit verification code<?= $pending_email_hint ? ' to ' . htmlspecialchars($pending_email_hint) : '' ?>.
            It expires in 10 minutes.
        </p>

        <?php if ($dev_code !== null): ?>
            <div class="alert alert-warning">
                <strong>Dev mode:</strong> your code is <strong><?= htmlspecialchars($dev_code) ?></strong>.
                Disable <code>ADMIN_OTP_DEV_DISPLAY</code> in <code>includes/mailer.php</code> before deploying.
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" novalidate>
            <input type="hidden" name="action" value="verify_otp">
            <?= html_input('text', 'otp', '', 'Verification Code', 'Enter the 6-digit code', $errors, [
                'inputmode'    => 'numeric',
                'maxlength'    => '6',
                'autocomplete' => 'one-time-code',
                'autofocus'    => 'autofocus',
            ]) ?>

            <button type="submit" class="btn btn-accent btn-block">Verify &amp; Sign In</button>
        </form>

        <form action="login.php" method="POST" novalidate style="margin-top: 0.75rem;">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-secondary btn-block">Resend code</button>
        </form>

        <div class="auth-footer">
            <a href="login.php?restart=1">&larr; Start over</a>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
