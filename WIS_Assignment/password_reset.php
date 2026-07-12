<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$step = 1;
$username = '';
$question = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_user') {
        $username = trim($_POST['username'] ?? '');
        
        if (empty($username)) {
            $errors['username'] = "Username is required.";
        } else {
            // Find user security question
            $stmt = $pdo->prepare("SELECT security_question FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $question = $stmt->fetchColumn();
            
            if ($question) {
                $step = 2; // Advance to answering the question
            } else {
                $errors['username'] = "No account found with this username.";
            }
        }
    } elseif ($action === 'reset_password') {
        $username = $_POST['username'] ?? '';
        $answer = trim($_POST['security_answer'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Fetch security answer from database
        $stmt = $pdo->prepare("SELECT security_question, security_answer FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user) {
            $question = $user['security_question'];
            $step = 2; // Retain state in case of validation error
            
            // Validate answers and password matching
            $req_fields = [
                'security_answer' => 'Security Answer',
                'new_password' => 'New Password',
                'confirm_password' => 'Confirm Password'
            ];
            $errors = validate_required($_POST, $req_fields);
            
            if (empty($errors['security_answer'])) {
                if (strtolower($answer) !== strtolower($user['security_answer'])) {
                    $errors['security_answer'] = "Incorrect answer to security question.";
                }
            }
            
            if (empty($errors['new_password'])) {
                if (strlen($new_password) < 6) {
                    $errors['new_password'] = "Password must be at least 6 characters.";
                } elseif ($new_password !== $confirm_password) {
                    $errors['confirm_password'] = "Passwords do not match.";
                }
            }
            
            if (empty($errors)) {
                // Perform password update
                $hashed_pw = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                $update_stmt->execute([$hashed_pw, $username]);
                
                $_SESSION['flash_success'] = "Password reset successful! You can now log in.";
                header("Location: login.php");
                exit;
            }
        } else {
            $errors['general'] = "An error occurred. User session expired.";
            $step = 1;
        }
    }
}
?>

<div class="auth-container">
    <h2>Password Recovery</h2>
    
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    
    <?php if ($step === 1): ?>
        <!-- Step 1: Identify Username -->
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            Please enter your username. We will ask you your security question to reset your password.
        </p>
        
        <form action="password_reset.php" method="POST" novalidate>
            <input type="hidden" name="action" value="verify_user">
            
            <?= html_input('text', 'username', $username, 'Username', 'Enter your username', $errors) ?>
            
            <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Continue &rarr;</button>
        </form>
    <?php else: ?>
        <!-- Step 2: Answer question & reset -->
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; text-align: center;">
            Please answer your security question and choose a new password.
        </p>
        
        <form action="password_reset.php" method="POST" novalidate>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
            
            <div style="background-color: var(--bg-cream); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <strong>Security Question:</strong><br>
                <span style="font-style: italic; color: var(--primary);"><?= htmlspecialchars($question) ?></span>
            </div>
            
            <?= html_input('text', 'security_answer', '', 'Your Answer', 'Answer is case-insensitive', $errors) ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <?= html_input('password', 'new_password', '', 'New Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
            </div>
            
            <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Reset Password</button>
        </form>
    <?php endif; ?>
    
    <div class="auth-footer">
        Remembered your password? <a href="login.php">Log In here</a>.
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
