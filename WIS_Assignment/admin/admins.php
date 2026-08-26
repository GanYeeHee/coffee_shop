<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_super_admin();

$errors  = [];
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? intval($_GET['id']) : 0;
$self_id = $_SESSION['user_id'];

// Create-admin form repopulation
$f_username  = '';
$f_full_name = '';
$f_email     = '';
$f_phone     = '';
// Promote form repopulation
$p_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST     = sanitize_input($_POST);
    $submitted = $_POST['action'] ?? '';

    if ($submitted === 'add') {
        $f_username  = $_POST['username'] ?? '';
        $f_full_name = $_POST['full_name'] ?? '';
        $f_email     = $_POST['email'] ?? '';
        $f_phone     = $_POST['phone'] ?? '';
        $password    = $_POST['password'] ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';

        $errors = validate_required($_POST, [
            'username'         => 'Username',
            'full_name'        => 'Full Name',
            'email'            => 'Email',
            'password'         => 'Password',
            'confirm_password' => 'Confirm Password',
        ]);

        if (empty($errors['username']) && ($e = validate_username($f_username))) {
            $errors['username'] = $e;
        }
        if (empty($errors['email']) && ($e = validate_email($f_email))) {
            $errors['email'] = $e;
        }
        if ($f_phone !== '' && ($e = validate_phone($f_phone))) {
            $errors['phone'] = $e;
        }
        if (empty($errors['password'])) {
            if (strlen($password) < 6) {
                $errors['password'] = "Password must be at least 6 characters.";
            } elseif ($password !== $confirm) {
                $errors['confirm_password'] = "Passwords do not match.";
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$f_username]);
            if ($stmt->fetchColumn() > 0) {
                $errors['username'] = "Username is already taken.";
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$f_email]);
            if ($stmt->fetchColumn() > 0) {
                $errors['email'] = "Email is already registered.";
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, email, full_name, phone, role, photo, status)
                 VALUES (?, ?, ?, ?, ?, 'admin', 'default_admin.png', 'active')"
            );
            try {
                $stmt->execute([
                    $f_username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $f_email,
                    $f_full_name,
                    $f_phone !== '' ? $f_phone : null,
                ]);
                $_SESSION['flash_success'] = "Admin account '{$f_username}' created. They will be asked for an email code at first login.";
                header("Location: admins.php");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to create admin. DB Error: " . $e->getMessage();
            }
        }
    } elseif ($submitted === 'promote') {
        $p_username = $_POST['promote_username'] ?? '';

        if ($p_username === '') {
            $errors['promote_username'] = "Enter the username of the member to promote.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$p_username]);
            $target = $stmt->fetch();

            if (!$target) {
                $errors['promote_username'] = "No account found with that username.";
            } elseif ($target['role'] !== 'member') {
                $errors['promote_username'] = "That account is already an administrator.";
            } else {
                $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$target['id']]);
                $_SESSION['flash_success'] = "'{$target['username']}' has been promoted to admin.";
                header("Location: admins.php");
                exit;
            }
        }
    } elseif (in_array($submitted, ['demote', 'delete', 'reset_pw'], true)) {
        $target_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $target = $stmt->fetch();

        // Shared guards: must be an existing plain admin that isn't you.
        if (!$target) {
            $_SESSION['flash_error'] = "Account not found.";
            header("Location: admins.php");
            exit;
        }
        if ($target_id === $self_id) {
            $_SESSION['flash_error'] = "You cannot do that to your own account.";
            header("Location: admins.php");
            exit;
        }
        if ($target['role'] === 'super_admin') {
            $_SESSION['flash_error'] = "The super administrator account is protected.";
            header("Location: admins.php");
            exit;
        }
        if ($target['role'] !== 'admin') {
            $_SESSION['flash_error'] = "That account is not an administrator.";
            header("Location: admins.php");
            exit;
        }

        if ($submitted === 'demote') {
            $pdo->prepare("UPDATE users SET role = 'member' WHERE id = ?")->execute([$target_id]);
            $_SESSION['flash_success'] = "'{$target['username']}' is now a regular member.";
            header("Location: admins.php");
            exit;
        }

        if ($submitted === 'delete') {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
            $_SESSION['flash_success'] = "Admin account '{$target['username']}' has been deleted.";
            header("Location: admins.php");
            exit;
        }

        // reset_pw
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 6) {
            $errors['new_password'] = "Password must be at least 6 characters.";
        } elseif ($new !== $conf) {
            $errors['confirm_password'] = "Passwords do not match.";
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $target_id]);
            $_SESSION['flash_success'] = "Password reset for '{$target['username']}'.";
            header("Location: admins.php");
            exit;
        }

        // Validation failed - stay on the reset form for this admin.
        $action = 'reset';
        $id     = $target_id;
    }
}

// Resolve the target of the "reset password" panel.
$reset_target = null;
if ($action === 'reset' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $reset_target = $stmt->fetch();
    if (!$reset_target || $reset_target['role'] !== 'admin' || $reset_target['id'] === $self_id) {
        $_SESSION['flash_error'] = "That account's password cannot be reset here.";
        header("Location: admins.php");
        exit;
    }
}

// Admin roster
$admins = $pdo->query(
    "SELECT * FROM users
     WHERE role IN ('super_admin', 'admin')
     ORDER BY FIELD(role, 'super_admin', 'admin'), username ASC"
)->fetchAll();

// Flash messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Admins</h1>
    <?php if ($action !== 'list'): ?>
        <a href="admins.php" class="btn btn-secondary btn-sm">&larr; Back to List</a>
    <?php endif; ?>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if (isset($errors['general'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
<?php endif; ?>

<div class="list-detail-columns" style="grid-template-columns: 1.5fr 1fr;">

    <!-- Left: admin accounts -->
    <section class="admin-panel">
        <h3>Administrator Accounts</h3>
        <div class="table-responsive">
            <table class="table" style="font-size: 0.95rem;">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                        <?php
                        $is_self  = ($a['id'] === $self_id);
                        $is_super = ($a['role'] === 'super_admin');
                        $protected = $is_self || $is_super;
                        ?>
                        <tr style="<?= ($action === 'reset' && $id === $a['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                            <td>
                                <strong><?= htmlspecialchars($a['username']) ?></strong>
                                <?php if ($is_self): ?>
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">(you)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($a['full_name']) ?></td>
                            <td style="font-size: 0.9rem;"><?= htmlspecialchars($a['email']) ?></td>
                            <td>
                                <span style="text-transform: capitalize; font-weight: 500; color: <?= $is_super ? 'var(--accent)' : 'var(--text-muted)' ?>;">
                                    <?= str_replace('_', ' ', $a['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= ($a['status'] === 'active') ? 'active' : 'blocked' ?>">
                                    <?= htmlspecialchars($a['status']) ?>
                                </span>
                                <?php if (is_account_locked($a)): ?>
                                    <span class="badge badge-locked" title="Locked until <?= htmlspecialchars($a['locked_until']) ?>">
                                        Locked (<?= get_lockout_minutes_remaining($a) ?>m)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                <?php if ($protected): ?>
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">&mdash;</span>
                                <?php else: ?>
                                    <a href="admins.php?action=reset&id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm btn-xs">Reset PW</a>
                                    <form action="admins.php" method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="demote">
                                        <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm btn-xs confirm-action"
                                                data-confirm-message="Demote '<?= htmlspecialchars($a['username']) ?>' to a regular member?">
                                            Demote
                                        </button>
                                    </form>
                                    <form action="admins.php" method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm btn-xs confirm-action"
                                                data-confirm-message="Permanently DELETE admin account '<?= htmlspecialchars($a['username']) ?>'? This cannot be undone.">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 1rem;">
            The super administrator account and your own account are protected from demotion and deletion.
        </p>
    </section>

    <!-- Right: reset panel OR create + promote -->
    <?php if ($action === 'reset' && $reset_target): ?>
        <section class="admin-panel" style="border-color: var(--primary-light);">
            <h3>Reset Password</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Set a new password for <strong>@<?= htmlspecialchars($reset_target['username']) ?></strong>.
                Any active login lockout on this account will also be cleared.
            </p>
            <form action="admins.php" method="POST" novalidate>
                <input type="hidden" name="action" value="reset_pw">
                <input type="hidden" name="user_id" value="<?= $reset_target['id'] ?>">
                <?= html_input('password', 'new_password', '', 'New Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Save New Password</button>
                <a href="admins.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
            </form>
        </section>
    <?php else: ?>
        <div>
            <section class="admin-panel">
                <h3>Create New Admin</h3>
                <form action="admins.php" method="POST" novalidate>
                    <input type="hidden" name="action" value="add">
                    <?= html_input('text', 'username', $f_username, 'Username', 'Choose a username', $errors) ?>
                    <?= html_input('text', 'full_name', $f_full_name, 'Full Name', 'Enter full name', $errors) ?>
                    <?= html_input('email', 'email', $f_email, 'Email Address', 'Where login codes are sent', $errors) ?>
                    <?= html_input('text', 'phone', $f_phone, 'Phone (Optional)', 'e.g. 0123456789', $errors) ?>
                    <?= html_input('password', 'password', '', 'Password', 'Min 6 characters', $errors) ?>
                    <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
                    <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Create Admin</button>
                </form>
            </section>

            <section class="admin-panel" style="margin-top: 1.5rem;">
                <h3>Promote a Member</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    Give an existing member account administrator access.
                </p>
                <form action="admins.php" method="POST" novalidate>
                    <input type="hidden" name="action" value="promote">
                    <?= html_input('text', 'promote_username', $p_username, 'Member Username', 'Existing member username', $errors) ?>
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Promote to Admin</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
