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
$user_questions = [];
$selected_question = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_user') {
        $username = trim($_POST['username'] ?? '');

        if (empty($username)) {
            $errors['username'] = "Username is required.";
        } else {
            // Find all security questions on file for this user
            $stmt = $pdo->prepare("SELECT usa.security_question FROM users u JOIN user_security_answers usa ON usa.user_id = u.id WHERE u.username = ?");
            $stmt->execute([$username]);
            $user_questions = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($user_questions)) {
                $step = 2; // Advance to answering a question
            } else {
                $errors['username'] = "No account found with this username.";
            }
        }
    } elseif ($action === 'reset_password') {
        $username = $_POST['username'] ?? '';
        $selected_question = $_POST['security_question'] ?? '';
        $answer = trim($_POST['security_answer'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Re-fetch this account's own questions so the submitted choice can be validated against them
        $stmt = $pdo->prepare("SELECT usa.security_question FROM users u JOIN user_security_answers usa ON usa.user_id = u.id WHERE u.username = ?");
        $stmt->execute([$username]);
        $user_questions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($user_questions)) {
            $step = 2; // Retain state in case of validation error

            // Validate answers and password matching
            $req_fields = [
                'security_question' => 'Security Question',
                'security_answer' => 'Security Answer',
                'new_password' => 'New Password',
                'confirm_password' => 'Confirm Password'
            ];
            $errors = validate_required($_POST, $req_fields);

            if (empty($errors['security_question']) && !in_array($selected_question, $user_questions)) {
                $errors['security_question'] = "Please select one of your security questions.";
            }

            if (empty($errors['security_question']) && empty($errors['security_answer'])) {
                $stmt = $pdo->prepare("SELECT u.id as user_id, usa.answer_hash FROM users u JOIN user_security_answers usa ON usa.user_id = u.id WHERE u.username = ? AND usa.security_question = ?");
                $stmt->execute([$username, $selected_question]);
                $user = $stmt->fetch();

                if (!$user || !password_verify(strtolower(trim($answer)), $user['answer_hash'])) {
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

                // Proving identity via security question also clears any login lockout
                reset_login_attempts($pdo, $user['user_id']);

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
            Please choose one of your security questions, answer it, and choose a new password.
        </p>

        <form action="password_reset.php" method="POST" novalidate>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">

            <?= html_select('security_question', array_combine($user_questions, $user_questions), $selected_question, 'Security Question', $errors) ?>
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
