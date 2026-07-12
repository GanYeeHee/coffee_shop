<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Force login
require_login();

$user = get_logged_in_user($pdo);
if (!$user) {
    header("Location: logout.php");
    exit;
}

$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $security_question = $_POST['security_question'] ?? '';
        $security_answer = $_POST['security_answer'] ?? '';
        
        // Validation
        $req_fields = [
            'full_name' => 'Full Name',
            'email' => 'Email',
            'security_question' => 'Security Question',
            'security_answer' => 'Security Answer'
        ];
        $errors = validate_required($_POST, $req_fields);
        
        if (empty($errors['email'])) {
            $email_err = validate_email($email);
            if ($email_err) {
                $errors['email'] = $email_err;
            } else {
                // Check if email is taken by someone else
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['email'] = "Email is already taken by another account.";
                }
            }
        }
        
        if (!empty($phone)) {
            $phone_err = validate_phone($phone);
            if ($phone_err) {
                $errors['phone'] = $phone_err;
            }
        }
        
        // Handle avatar upload
        $photo_uploaded = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK;
        if ($photo_uploaded) {
            $image_err = validate_image($_FILES['photo']);
            if ($image_err) {
                $errors['photo'] = $image_err;
            }
        }
        
        if (empty($errors)) {
            $photo_name = $user['photo'];
            
            // Upload new photo and remove old photo
            if ($photo_uploaded) {
                $file_info = pathinfo($_FILES['photo']['name']);
                $extension = strtolower($file_info['extension']);
                $new_photo_name = 'user_' . time() . '_' . rand(100, 999) . '.' . $extension;
                $upload_path = __DIR__ . '/uploads/profiles/' . $new_photo_name;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    // Delete old photo file if it exists and is not a default or placeholder
                    if (!empty($user['photo']) && file_exists(__DIR__ . '/uploads/profiles/' . $user['photo']) && strpos($user['photo'], 'default_') === false) {
                        unlink(__DIR__ . '/uploads/profiles/' . $user['photo']);
                    }
                    $photo_name = $new_photo_name;
                } else {
                    $errors['photo'] = "Failed to upload new profile photo.";
                }
            }
            
            if (empty($errors)) {
                // Update database
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, photo = ?, security_question = ?, security_answer = ? WHERE id = ?");
                
                try {
                    $stmt->execute([
                        $full_name,
                        $email,
                        $phone !== '' ? $phone : null,
                        $photo_name,
                        $security_question,
                        strtolower(trim($security_answer)),
                        $user['id']
                    ]);
                    $success_message = "Profile updated successfully!";
                    // Refresh user data
                    $user = get_logged_in_user($pdo);
                } catch (PDOException $e) {
                    $errors['general'] = "Failed to update profile. DB Error: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $req_fields = [
            'current_password' => 'Current Password',
            'new_password' => 'New Password',
            'confirm_password' => 'Confirm Password'
        ];
        $errors = validate_required($_POST, $req_fields);
        
        if (empty($errors)) {
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $errors['current_password'] = "Incorrect current password.";
            }
            
            // Validate new password
            if (strlen($new_password) < 6) {
                $errors['new_password'] = "New password must be at least 6 characters.";
            } elseif ($new_password === $current_password) {
                $errors['new_password'] = "New password must be different from current password.";
            } elseif ($new_password !== $confirm_password) {
                $errors['confirm_password'] = "New passwords do not match.";
            }
        }
        
        if (empty($errors)) {
            // Hash and update
            $new_hashed_pw = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            
            try {
                $stmt->execute([$new_hashed_pw, $user['id']]);
                $success_message = "Password updated successfully!";
            } catch (PDOException $e) {
                $errors['general'] = "Failed to change password. DB Error: " . $e->getMessage();
            }
        }
    }
}

$questions = [
    'What was the name of your first pet?' => 'What was the name of your first pet?',
    'What is your favorite coffee bean?' => 'What is your favorite coffee bean?',
    'What city were you born in?' => 'What city were you born in?',
    'What was your childhood nickname?' => 'What was your childhood nickname?'
];
?>

<div style="max-width: 1000px; margin: 0 auto;">
    <h1 style="margin-bottom: 2rem;">My Profile</h1>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 3rem; align-items: start;">
        
        <!-- Profile Info Form -->
        <section class="admin-panel">
            <h3>Update Profile Details</h3>
            <form action="profile.php" method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="update_profile">
                
                <div class="photo-upload-container">
                    <?php 
                    $photo_url = 'uploads/profiles/' . $user['photo'];
                    if (!empty($user['photo']) && file_exists(__DIR__ . '/' . $photo_url)): 
                    ?>
                        <img src="<?= $photo_url ?>" class="photo-preview" alt="Avatar">
                    <?php else: ?>
                        <div class="photo-preview" style="background-color: var(--primary-light); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 700; text-transform: uppercase;">
                            <?= substr($user['username'], 0, 2) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="field-photo">Change Profile Picture</label>
                        <input type="file" name="photo" id="field-photo" class="form-control image-upload-input" accept="image/*">
                        <?= html_error($errors, 'photo') ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly style="background-color: var(--bg-cream); cursor: not-allowed;">
                </div>
                
                <?= html_input('text', 'full_name', $user['full_name'], 'Full Name', 'Enter your full name', $errors) ?>
                <?= html_input('email', 'email', $user['email'], 'Email Address', 'Enter your email address', $errors) ?>
                <?= html_input('text', 'phone', $user['phone'] ?? '', 'Phone Number', 'e.g., 0123456789', $errors) ?>
                
                <?= html_select('security_question', $questions, $user['security_question'], 'Security Question (For recovery)', $errors) ?>
                <?= html_input('text', 'security_answer', $user['security_answer'], 'Security Answer', 'Enter answer', $errors) ?>
                
                <button type="submit" class="btn btn-accent" style="margin-top: 1rem;">Save Changes</button>
            </form>
        </section>
        
        <!-- Password Change Form -->
        <section class="admin-panel">
            <h3>Change Password</h3>
            <form action="profile.php" method="POST" novalidate>
                <input type="hidden" name="action" value="change_password">
                
                <?= html_input('password', 'current_password', '', 'Current Password', 'Enter current password', $errors) ?>
                <?= html_input('password', 'new_password', '', 'New Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm New Password', 'Re-enter new password', $errors) ?>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 1rem; width: 100%;">Update Password</button>
            </form>
        </section>
        
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
