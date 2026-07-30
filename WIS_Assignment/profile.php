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

$sec_stmt = $pdo->prepare("SELECT security_question FROM user_security_answers WHERE user_id = ? LIMIT 1");
$sec_stmt->execute([$user['id']]);
$current_security_question = $sec_stmt->fetchColumn() ?: '';

$errors = [];
$success_message = '';

// Saved-address panel state (namespaced GET params to avoid colliding with the POST 'action' field above)
$addr_action = $_GET['addr_action'] ?? 'list';
$addr_id = isset($_GET['addr_id']) ? intval($_GET['addr_id']) : 0;
$addr_label = 'Home';
$addr_recipient_name = $user['full_name'];
$addr_recipient_phone = $user['phone'] ?? '';
$addr_line1 = '';
$addr_line2 = '';
$addr_city = '';
$addr_state = '';
$addr_zip = '';
$addr_is_default = false;

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
            'security_question' => 'Security Question'
        ];
        $errors = validate_required($_POST, $req_fields);

        // Leaving the answer blank keeps the current one, but switching questions requires a fresh answer
        if (trim($security_answer) === '' && $current_security_question !== '' && $security_question !== $current_security_question) {
            $errors['security_answer'] = "Please provide an answer for your new security question.";
        }

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
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, photo = ? WHERE id = ?");
                    $stmt->execute([
                        $full_name,
                        $email,
                        $phone !== '' ? $phone : null,
                        $photo_name,
                        $user['id']
                    ]);

                    // Only touch the security answer when a new one was actually submitted
                    if (trim($security_answer) !== '') {
                        $answer_hash = password_hash(strtolower(trim($security_answer)), PASSWORD_DEFAULT);
                        // UNIQUE KEY is (user_id, security_question); drop any stale row for a different question first
                        $pdo->prepare("DELETE FROM user_security_answers WHERE user_id = ? AND security_question != ?")
                            ->execute([$user['id'], $security_question]);
                        $pdo->prepare("INSERT INTO user_security_answers (user_id, security_question, answer_hash) VALUES (?, ?, ?)
                                       ON DUPLICATE KEY UPDATE security_question = VALUES(security_question), answer_hash = VALUES(answer_hash)")
                            ->execute([$user['id'], $security_question, $answer_hash]);
                    }

                    $pdo->commit();
                    $success_message = "Profile updated successfully!";
                    // Refresh user data
                    $user = get_logged_in_user($pdo);
                    $current_security_question = $security_question;
                } catch (PDOException $e) {
                    $pdo->rollBack();
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
    } elseif ($action === 'add_address' || $action === 'edit_address') {
        $addr_id = intval($_POST['addr_id'] ?? 0);
        $addr_label = $_POST['address_label'] ?? 'Home';
        $addr_recipient_name = $_POST['recipient_name'] ?? '';
        $addr_recipient_phone = $_POST['recipient_phone'] ?? '';
        $addr_line1 = $_POST['address_line_1'] ?? '';
        $addr_line2 = $_POST['address_line_2'] ?? '';
        $addr_city = $_POST['city'] ?? '';
        $addr_state = $_POST['state'] ?? '';
        $addr_zip = $_POST['zip_code'] ?? '';
        $addr_is_default = isset($_POST['is_default']);
        $addr_action = ($action === 'edit_address') ? 'edit' : 'add';

        $req_fields = [
            'recipient_name' => 'Recipient Name',
            'recipient_phone' => 'Recipient Phone',
            'address_line_1' => 'Address Line 1',
            'city' => 'City',
            'zip_code' => 'Zip/Postal Code'
        ];
        $errors = validate_required($_POST, $req_fields);

        if (empty($errors['recipient_phone'])) {
            $phone_err = validate_phone($addr_recipient_phone);
            if ($phone_err) {
                $errors['recipient_phone'] = $phone_err;
            }
        }

        if ($action === 'edit_address' && $addr_id <= 0) {
            $errors['general'] = "Invalid address reference.";
        }

        if (empty($errors)) {
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?");
            $count_stmt->execute([$user['id']]);
            $is_first_address = ((int) $count_stmt->fetchColumn() === 0);
            $force_default = $addr_is_default || $is_first_address;

            try {
                $pdo->beginTransaction();

                if ($force_default) {
                    $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$user['id']]);
                }

                if ($action === 'add_address') {
                    $pdo->prepare("INSERT INTO user_addresses (user_id, address_label, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, zip_code, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([
                            $user['id'],
                            $addr_label !== '' ? $addr_label : 'Home',
                            $addr_recipient_name,
                            $addr_recipient_phone,
                            $addr_line1,
                            $addr_line2 !== '' ? $addr_line2 : null,
                            $addr_city,
                            $addr_state !== '' ? $addr_state : null,
                            $addr_zip,
                            $force_default ? 1 : 0
                        ]);
                    $flash_msg = "Address saved successfully!";
                } else {
                    // Ownership check baked into the WHERE clause
                    $pdo->prepare("UPDATE user_addresses SET address_label = ?, recipient_name = ?, recipient_phone = ?, address_line_1 = ?, address_line_2 = ?, city = ?, state = ?, zip_code = ?, is_default = ? WHERE id = ? AND user_id = ?")
                        ->execute([
                            $addr_label !== '' ? $addr_label : 'Home',
                            $addr_recipient_name,
                            $addr_recipient_phone,
                            $addr_line1,
                            $addr_line2 !== '' ? $addr_line2 : null,
                            $addr_city,
                            $addr_state !== '' ? $addr_state : null,
                            $addr_zip,
                            $force_default ? 1 : 0,
                            $addr_id,
                            $user['id']
                        ]);
                    $flash_msg = "Address updated successfully!";
                }

                $pdo->commit();
                $_SESSION['flash_success'] = $flash_msg;
                header("Location: profile.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['general'] = "Failed to save address. DB Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_address') {
        $del_id = intval($_POST['addr_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->execute([$del_id, $user['id']]);
        $addr_row = $stmt->fetch();

        if ($addr_row) {
            $pdo->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?")->execute([$del_id, $user['id']]);
            // Auto-promote another address to default if the deleted one was it
            if ($addr_row['is_default']) {
                $pdo->prepare("UPDATE user_addresses SET is_default = 1 WHERE user_id = ? ORDER BY id ASC LIMIT 1")->execute([$user['id']]);
            }
            $_SESSION['flash_success'] = "Address deleted.";
        } else {
            $_SESSION['flash_error'] = "Address not found.";
        }
        header("Location: profile.php");
        exit;
    } elseif ($action === 'set_default_address') {
        $set_id = intval($_POST['addr_id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$user['id']]);
        $pdo->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$set_id, $user['id']]);
        $pdo->commit();
        header("Location: profile.php");
        exit;
    }
}

// Fetch single address for editing (GET, non-POST)
if ($addr_action === 'edit' && $addr_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
    $stmt->execute([$addr_id, $user['id']]);
    $addr_row = $stmt->fetch();
    if ($addr_row) {
        $addr_label = $addr_row['address_label'];
        $addr_recipient_name = $addr_row['recipient_name'];
        $addr_recipient_phone = $addr_row['recipient_phone'];
        $addr_line1 = $addr_row['address_line_1'];
        $addr_line2 = $addr_row['address_line_2'] ?? '';
        $addr_city = $addr_row['city'];
        $addr_state = $addr_row['state'] ?? '';
        $addr_zip = $addr_row['zip_code'];
        $addr_is_default = (bool) $addr_row['is_default'];
    } else {
        $addr_action = 'list';
    }
}

// Fetch all saved addresses for display
$addr_list_stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC");
$addr_list_stmt->execute([$user['id']]);
$user_addresses = $addr_list_stmt->fetchAll();

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

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

    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
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
                
                <?= html_select('security_question', $questions, $current_security_question, 'Security Question (For recovery)', $errors) ?>
                <?= html_input('text', 'security_answer', '', 'Security Answer', 'Leave blank to keep your current answer', $errors) ?>
                
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

    <!-- Saved Addresses -->
    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 3rem; align-items: start; margin-top: 3rem;">

        <section class="admin-panel">
            <h3>My Saved Addresses</h3>
            <?php if (empty($user_addresses)): ?>
                <p style="color: var(--text-muted); padding: 1rem 0;">No saved addresses yet. Add one on the right.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Recipient</th>
                                <th>City</th>
                                <th>Default</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_addresses as $a): ?>
                                <tr style="<?= ($addr_action === 'edit' && $addr_id === $a['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                    <td><strong><?= htmlspecialchars($a['address_label']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['recipient_name']) ?><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($a['address_line_1']) ?></span></td>
                                    <td><?= htmlspecialchars($a['city']) ?></td>
                                    <td>
                                        <?php if ($a['is_default']): ?>
                                            <span class="badge badge-active">Default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="display: flex; gap: 0.3rem; flex-wrap: wrap;">
                                        <a href="profile.php?addr_action=edit&addr_id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">Edit</a>
                                        <?php if (!$a['is_default']): ?>
                                            <form action="profile.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="action" value="set_default_address">
                                                <input type="hidden" name="addr_id" value="<?= $a['id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">Set Default</button>
                                            </form>
                                        <?php endif; ?>
                                        <form action="profile.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="delete_address">
                                            <input type="hidden" name="addr_id" value="<?= $a['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete the '<?= htmlspecialchars($a['address_label']) ?>' address?" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <h3><?= $addr_action === 'edit' ? 'Edit Address' : 'Add New Address' ?></h3>
            <form action="profile.php?addr_action=<?= $addr_action === 'edit' ? 'edit&addr_id=' . $addr_id : 'add' ?>" method="POST" novalidate>
                <input type="hidden" name="action" value="<?= $addr_action === 'edit' ? 'edit_address' : 'add_address' ?>">
                <?php if ($addr_action === 'edit'): ?>
                    <input type="hidden" name="addr_id" value="<?= $addr_id ?>">
                <?php endif; ?>

                <?= html_input('text', 'address_label', $addr_label, 'Label', 'e.g. Home, Office', $errors) ?>
                <?= html_input('text', 'recipient_name', $addr_recipient_name, 'Recipient Name', 'Who receives this delivery', $errors) ?>
                <?= html_input('text', 'recipient_phone', $addr_recipient_phone, 'Recipient Phone', 'e.g., 0123456789', $errors) ?>
                <?= html_input('text', 'address_line_1', $addr_line1, 'Address Line 1', 'Street address', $errors) ?>
                <?= html_input('text', 'address_line_2', $addr_line2, 'Address Line 2 (Optional)', 'Unit, floor, etc.', $errors) ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <?= html_input('text', 'city', $addr_city, 'City', 'City', $errors) ?>
                    <?= html_input('text', 'state', $addr_state, 'State (Optional)', 'State/Region', $errors) ?>
                </div>

                <?= html_input('text', 'zip_code', $addr_zip, 'Zip/Postal Code', 'Postal code', $errors) ?>

                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" name="is_default" id="field-is_default" value="1" <?= $addr_is_default ? 'checked' : '' ?>>
                    <label for="field-is_default" style="margin: 0;">Set as default address</label>
                </div>

                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;"><?= $addr_action === 'edit' ? 'Save Changes' : 'Add Address' ?></button>
                <?php if ($addr_action === 'edit'): ?>
                    <a href="profile.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </section>

    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
