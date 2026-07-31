<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$errors = [];
$username = '';
$full_name = '';
$email = '';
$phone = '';
$security_question = 'What was the name of your first pet?';
$security_answer = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize input
    $_POST = sanitize_input($_POST);
    
    $username = $_POST['username'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $security_question = $_POST['security_question'] ?? '';
    $security_answer = $_POST['security_answer'] ?? '';
    
    // Validations
    $req_fields = [
        'username' => 'Username',
        'full_name' => 'Full Name',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Confirm Password',
        'security_question' => 'Security Question',
        'security_answer' => 'Security Answer'
    ];
    $errors = validate_required($_POST, $req_fields);
    
    if (empty($errors['username'])) {
        $username_err = validate_username($username);
        if ($username_err) {
            $errors['username'] = $username_err;
        }
    }
    
    if (empty($errors['email'])) {
        $email_err = validate_email($email);
        if ($email_err) {
            $errors['email'] = $email_err;
        }
    }
    
    if (!empty($phone)) {
        $phone_err = validate_phone($phone);
        if ($phone_err) {
            $errors['phone'] = $phone_err;
        }
    }
    
    if (empty($errors['password'])) {
        if (strlen($password) < 6) {
            $errors['password'] = "Password must be at least 6 characters.";
        } elseif ($password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match.";
        }
    }
    
    // Image validation (Optional)
    $image_err = validate_image($_FILES['photo'] ?? null, false);
    if ($image_err) {
        $errors['photo'] = $image_err;
    }
    
    // Check uniqueness in database
    if (empty($errors)) {
        // Check username
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $errors['username'] = "Username is already taken.";
        }
        
        // Check email
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors['email'] = "Email is already registered.";
        }
    }
    
    // Proceed if no errors
    if (empty($errors)) {
        $photo_name = null;
        
        // Handle image upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file_info = pathinfo($_FILES['photo']['name']);
            $extension = strtolower($file_info['extension']);
            $photo_name = 'user_' . time() . '_' . rand(100, 999) . '.' . $extension;
            $upload_path = __DIR__ . '/uploads/profiles/' . $photo_name;
            
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                $errors['photo'] = "Failed to upload avatar image.";
            }
        }
        
        if (empty($errors)) {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            try {
                $pdo->beginTransaction();

                // Insert user
                $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, phone, role, photo, status) VALUES (?, ?, ?, ?, ?, 'member', ?, 'active')");
                $insert_stmt->execute([
                    $username,
                    $hashed_password,
                    $email,
                    $full_name,
                    $phone !== '' ? $phone : null,
                    $photo_name
                ]);
                $user_id = $pdo->lastInsertId();

                // Store security answer hashed, lowercase-normalized so verification stays case-insensitive
                $answer_hash = password_hash(strtolower(trim($security_answer)), PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO user_security_answers (user_id, security_question, answer_hash) VALUES (?, ?, ?)")
                    ->execute([$user_id, $security_question, $answer_hash]);

                $pdo->commit();

                $_SESSION['flash_success'] = "Registration successful! You can now log in.";
                header("Location: login.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['general'] = "Registration failed. Database error: " . $e->getMessage();
            }
        }
    }
}

// Prepare options for Security Questions
$questions = [
    'What was the name of your first pet?' => 'What was the name of your first pet?',
    'What is your favorite coffee bean?' => 'What is your favorite coffee bean?',
    'What city were you born in?' => 'What city were you born in?',
    'What was your childhood nickname?' => 'What was your childhood nickname?'
];
?>

<div class="auth-container" style="max-width: 600px;">
    <h2>Join The Daily Grind</h2>
    
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    
    <form action="register.php" method="POST" enctype="multipart/form-data" novalidate>
        
        <?= html_input('text', 'username', $username, 'Username', 'Choose a username', $errors) ?>
        <?= html_input('text', 'full_name', $full_name, 'Full Name', 'Enter your full name', $errors) ?>
        <?= html_input('email', 'email', $email, 'Email Address', 'Enter your email address', $errors) ?>
        <?= html_input('text', 'phone', $phone, 'Phone Number (Optional)', 'e.g., 0123456789', $errors) ?>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <?= html_input('password', 'password', '', 'Password', 'Min 6 characters', $errors) ?>
            <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
        </div>
        
        <?= html_select('security_question', $questions, $security_question, 'Security Question (For password reset)', $errors) ?>
        <?= html_input('text', 'security_answer', $security_answer, 'Security Answer', 'Enter answer', $errors) ?>
        
        <div class="photo-upload-container">
            <img src="assets/css/../images/default_avatar.png" class="photo-preview" alt="Avatar Preview" onerror="this.src='https://www.w3schools.com/howto/img_avatar.png'">
            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                <label for="field-photo">Profile Photo (Optional)</label>
                <input type="file" name="photo" id="field-photo" class="form-control image-upload-input" accept="image/*">
                <?= html_error($errors, 'photo') ?>
            </div>
        </div>
        
        <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1.5rem;">Register Account</button>
    </form>
    
    <div class="auth-footer">
        Already have an account? <a href="login.php">Log In here</a>.
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
