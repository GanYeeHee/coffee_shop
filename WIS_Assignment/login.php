<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Redirect if already logged in
if (is_logged_in()) {
    if (is_admin()) {
        header("Location: admin/index.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

$errors = [];
$username = '';
$show_admin_link = false;

// Check for registration success flash message
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $req_fields = [
        'username' => 'Username or Email',
        'password' => 'Password'
    ];
    $errors = validate_required($_POST, $req_fields);

    if (empty($errors)) {
        // Query user by username or email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Admins have their own login (with an email OTP step) - send them there.
            if (in_array($user['role'], ['admin', 'super_admin'], true)) {
                $errors['general'] = "Administrator accounts must sign in through the admin login.";
                $show_admin_link = true;
            }
            // Check if user is blocked
            elseif ($user['status'] === 'blocked') {
                $errors['general'] = "Your account has been blocked by an administrator.";
            } elseif (is_account_locked($user)) {
                $minutes = get_lockout_minutes_remaining($user);
                $errors['general'] = "Too many failed login attempts. Please try again in $minutes minute(s).";
            } elseif (password_verify($password, $user['password'])) {
                // Members only (admins were redirected to the admin login above).
                reset_login_attempts($pdo, $user['id']);
                login_user($user);
                header("Location: index.php");
                exit;
            } else {
                $remaining = record_failed_login($pdo, $user);
                if ($remaining > 0) {
                    $errors['general'] = "Invalid username or password. $remaining attempt(s) remaining before lockout.";
                } else {
                    $errors['general'] = "Too many failed login attempts. Please try again in " . LOCKOUT_MINUTES . " minute(s).";
                }
            }
        } else {
            $errors['general'] = "Invalid username or password.";
        }
    }
}
?>

<div class="auth-container">
    <h2>Log In</h2>
    
    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($errors['general']) ?>
            <?php if ($show_admin_link): ?>
                <a href="admin/login.php">Go to admin login &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST" novalidate>
        <?= html_input('text', 'username', $username, 'Username or Email', 'Enter your username or email', $errors) ?>
        
        <?= html_input('password', 'password', '', 'Password', 'Enter your password', $errors) ?>
        <div class="auth-links text-right">
            <a href="password_reset.php">Forgot password?</a>
            &middot;
            <a href="email_reset_request.php">Reset via email</a>
        </div>

        <button type="submit" class="btn btn-accent btn-block checkout-submit">Log In</button>
    </form>
    
    <div class="auth-footer">
        New to TAR Coffee? <a href="register.php">Create an account</a>.
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
