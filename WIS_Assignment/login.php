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
        'username' => 'Username',
        'password' => 'Password'
    ];
    $errors = validate_required($_POST, $req_fields);
    
    if (empty($errors)) {
        // Query user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Check if user is blocked
            if ($user['status'] === 'blocked') {
                $errors['general'] = "Your account has been blocked by an administrator.";
            } elseif (password_verify($password, $user['password'])) {
                // Log user in
                login_user($user);
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header("Location: admin/index.php");
                } else {
                    header("Location: index.php");
                }
                exit;
            } else {
                $errors['general'] = "Invalid username or password.";
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
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    
    <form action="login.php" method="POST" novalidate>
        <?= html_input('text', 'username', $username, 'Username', 'Enter your username', $errors) ?>
        
        <div style="position: relative;">
            <?= html_input('password', 'password', '', 'Password', 'Enter your password', $errors) ?>
            <div style="text-align: right; margin-top: -0.5rem; margin-bottom: 1rem;">
                <a href="password_reset.php" style="font-size: 0.85rem; color: var(--text-muted);">Forgot password?</a>
                &middot;
                <a href="email_reset_request.php" style="font-size: 0.85rem; color: var(--text-muted);">Reset via email</a>
            </div>
        </div>
        
        <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Log In</button>
    </form>
    
    <div class="auth-footer">
        New to The Daily Grind? <a href="register.php">Create an account</a>.
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
