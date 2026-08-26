<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

/** Tiny inline icon set for the row-action chips (14px, currentColor stroke). */
function ua_icon($name) {
    $paths = [
        'eye'    => '<circle cx="12" cy="12" r="3"/><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>',
        'ban'    => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'unlock' => '<rect x="4.5" y="10.5" width="15" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 7.7-1.5"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.5 3 7.6 7 8.8 4-1.2 7-4.3 7-8.8V6Z"/>',
        'key'    => '<circle cx="9" cy="15" r="3.5"/><path d="m11.5 12.5 7.5-7.5M17 7l2.2 2.2M14.6 9.4 16.8 11.6"/>',
        'trash'  => '<path d="M4 7h16M10 7V5h4v2M6.5 7l1 12h9l1-12"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

$is_super = is_super_admin();
$self_id  = $_SESSION['user_id'];

$errors = [];

// ---- Filters / view state --------------------------------------------------
$search      = isset($_GET['q']) ? trim($_GET['q']) : '';
$role_filter = $_GET['role'] ?? 'all';
if (!in_array($role_filter, ['all', 'super_admin', 'admin', 'member'], true)) {
    $role_filter = 'all';
}

$base_params = array_filter([
    'q'    => $search !== '' ? $search : null,
    'role' => $role_filter !== 'all' ? $role_filter : null,
]);
$base_qs       = http_build_query($base_params);
$qs            = $base_qs !== '' ? '?' . $base_qs : '';        // for <form action> / list links
$qs_amp        = $base_qs !== '' ? '&' . $base_qs : '';        // to append after ?detail_id=N
$redirect_self = 'users.php' . $qs;

$view_action = $_GET['action'] ?? 'list';
$view_id     = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Create-account form repopulation
$f_username = $f_full_name = $f_email = $f_phone = '';
$f_role = 'admin';

// ---- POST -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST  = sanitize_input($_POST);
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        if (!$is_super) {
            $_SESSION['flash_error'] = "That action is restricted to the super administrator.";
            header("Location: $redirect_self");
            exit;
        }

        $f_username  = $_POST['username'] ?? '';
        $f_full_name = $_POST['full_name'] ?? '';
        $f_email     = $_POST['email'] ?? '';
        $f_phone     = $_POST['phone'] ?? '';
        $f_role      = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'admin';
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
                 VALUES (?, ?, ?, ?, ?, ?, 'default_admin.png', 'active')"
            );
            try {
                $stmt->execute([
                    $f_username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $f_email,
                    $f_full_name,
                    $f_phone !== '' ? $f_phone : null,
                    $f_role,
                ]);
                $_SESSION['flash_success'] = ucfirst($f_role) . " account '{$f_username}' created.";
                header("Location: $redirect_self");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to create account. DB Error: " . $e->getMessage();
            }
        }
        // fall through -> re-render the create panel with $errors
    } elseif (in_array($action, ['toggle_block', 'unlock', 'set_role', 'delete', 'reset_pw'], true)) {
        $target_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $target = $stmt->fetch();

        // ---- Shared guards ----
        if (!$target) {
            $_SESSION['flash_error'] = "Account not found.";
            header("Location: $redirect_self");
            exit;
        }
        if ($target['id'] === $self_id) {
            $_SESSION['flash_error'] = "You cannot perform that action on your own account.";
            header("Location: $redirect_self");
            exit;
        }
        if ($target['role'] === 'super_admin') {
            $_SESSION['flash_error'] = "The super administrator account is protected.";
            header("Location: $redirect_self");
            exit;
        }

        // set_role / delete / reset_pw are super-admin only; so is moderating an admin account.
        $moderating_admin = in_array($action, ['toggle_block', 'unlock'], true) && $target['role'] === 'admin';
        $super_only       = $moderating_admin || in_array($action, ['set_role', 'delete', 'reset_pw'], true);
        if ($super_only && !$is_super) {
            $_SESSION['flash_error'] = $moderating_admin
                ? "Only the super administrator can moderate admin accounts."
                : "That action is restricted to the super administrator.";
            header("Location: $redirect_self");
            exit;
        }

        if ($action === 'toggle_block') {
            $new_status = ($target['status'] === 'active') ? 'blocked' : 'active';
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $target_id]);
            $_SESSION['flash_success'] = "User '{$target['username']}' has been "
                . ($new_status === 'blocked' ? 'blocked' : 'unblocked') . ".";
            header("Location: $redirect_self");
            exit;
        }

        if ($action === 'unlock') {
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$target_id]);
            $_SESSION['flash_success'] = "User '{$target['username']}' has been unlocked.";
            header("Location: $redirect_self");
            exit;
        }

        if ($action === 'set_role') {
            $new_role = $_POST['new_role'] ?? '';
            if (!in_array($new_role, ['admin', 'member'], true)) {
                $_SESSION['flash_error'] = "Invalid role.";
                header("Location: $redirect_self");
                exit;
            }
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$new_role, $target_id]);
            $_SESSION['flash_success'] = "'{$target['username']}' is now "
                . ($new_role === 'admin' ? 'an admin' : 'a member') . ".";
            header("Location: $redirect_self");
            exit;
        }

        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
            $_SESSION['flash_success'] = "Account '{$target['username']}' has been deleted.";
            header("Location: $redirect_self");
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
            header("Location: $redirect_self");
            exit;
        }

        // Validation failed - keep the reset panel open for this account.
        $view_action = 'reset';
        $view_id     = $target_id;
    }
}

// ---- Expandable detail card ----------------------------------------------
$expand_user_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$expand_user    = null;
if ($expand_user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$expand_user_id]);
    $expand_user = $stmt->fetch();
    if ($expand_user) {
        $sec_stmt = $pdo->prepare("SELECT security_question FROM user_security_answers WHERE user_id = ? LIMIT 1");
        $sec_stmt->execute([$expand_user_id]);
        $expand_user['security_question'] = $sec_stmt->fetchColumn() ?: 'Not set';
    }
}

// ---- Reset-password panel target (super admin only) ----------------------
$reset_target = null;
if ($is_super && $view_action === 'reset' && $view_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$view_id]);
    $reset_target = $stmt->fetch();
    if (!$reset_target || $reset_target['role'] === 'super_admin' || $reset_target['id'] === $self_id) {
        $_SESSION['flash_error'] = "That account's password cannot be reset here.";
        header("Location: $redirect_self");
        exit;
    }
}

// ---- User list ----------------------------------------------------------
$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($role_filter !== 'all') {
    $where[]  = "role = ?";
    $params[] = $role_filter;
}
$where_sql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

$per_page = 20;
$pg = paginate_query($pdo, "SELECT COUNT(*) FROM users" . $where_sql, $params, $per_page);

$sql = "SELECT * FROM users" . $where_sql
     . " ORDER BY FIELD(role, 'super_admin', 'admin', 'member'), id DESC"
     . " LIMIT " . (int) $pg['per_page'] . " OFFSET " . (int) $pg['offset'];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// ---- Which contextual drawer (if any) is open --------------------------
// The create form posts back to ?panel=create so it re-opens on validation error.
if ($expand_user) {
    $drawer = 'detail';
} elseif ($reset_target) {
    $drawer = 'reset';
} elseif ($is_super && ($_GET['panel'] ?? '') === 'create') {
    $drawer = 'create';
} else {
    $drawer = 'none';
}

// Flash
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Users</h1>
    <?php if ($is_super && $drawer === 'none'): ?>
        <a href="users.php?panel=create<?= htmlspecialchars($qs_amp) ?>" class="btn btn-accent btn-sm">+ New Account</a>
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

<!-- User list (always full width) -->
<section class="admin-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.2rem;">
            <h3>User Accounts</h3>
            <form action="users.php" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <input type="text" name="q" class="form-control" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.5rem; width: 200px;">
                <select name="role" class="form-control" style="padding: 0.5rem; width: auto;">
                    <option value="all"<?= $role_filter === 'all' ? ' selected' : '' ?>>All roles</option>
                    <option value="super_admin"<?= $role_filter === 'super_admin' ? ' selected' : '' ?>>Super Admin</option>
                    <option value="admin"<?= $role_filter === 'admin' ? ' selected' : '' ?>>Admin</option>
                    <option value="member"<?= $role_filter === 'member' ? ' selected' : '' ?>>Member</option>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>

        <?php if (empty($users)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No matching accounts found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="font-size: 0.95rem;">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $is_self_row    = ($u['id'] === $self_id);
                            $is_super_row   = ($u['role'] === 'super_admin');
                            $is_admin_row   = ($u['role'] === 'admin');
                            $can_moderate   = !$is_self_row && !$is_super_row && ($is_super || !$is_admin_row);
                            $can_manage     = $is_super && !$is_self_row && !$is_super_row;
                            ?>
                            <tr style="<?= ($expand_user_id === $u['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td class="col-nowrap">
                                    <strong><?= htmlspecialchars($u['username']) ?></strong>
                                    <?php if ($is_self_row): ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">(you)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-nowrap"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="col-email" style="font-size: 0.9rem;" title="<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <span style="text-transform: capitalize; font-weight: 500; color: <?= $is_super_row ? 'var(--accent)' : ($is_admin_row ? 'var(--primary-dark)' : 'var(--text-muted)') ?>;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= ($u['status'] === 'active') ? 'active' : 'blocked' ?>">
                                        <?= htmlspecialchars($u['status']) ?>
                                    </span>
                                    <?php if (is_account_locked($u)): ?>
                                        <span class="badge badge-locked" title="Locked until <?= htmlspecialchars($u['locked_until']) ?>">
                                            Locked (<?= get_lockout_minutes_remaining($u) ?>m)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions">
                                    <div class="row-menu">
                                        <button type="button" class="row-menu-toggle" aria-haspopup="true" aria-expanded="false"
                                                aria-label="Actions for <?= htmlspecialchars($u['username']) ?>">
                                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                                        </button>
                                        <div class="row-menu-panel" role="menu" hidden>
                                            <a href="users.php?detail_id=<?= $u['id'] ?><?= htmlspecialchars($qs_amp) ?>" class="row-menu-item" role="menuitem">
                                                <?= ua_icon('eye') ?>View details
                                            </a>

                                            <?php if ($can_moderate): ?>
                                                <?php $blocking = ($u['status'] === 'active'); ?>
                                                <form action="users.php<?= htmlspecialchars($qs) ?>" method="POST">
                                                    <input type="hidden" name="action" value="toggle_block">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="row-menu-item <?= $blocking ? 'is-danger' : '' ?> confirm-action" role="menuitem"
                                                            data-confirm-message="<?= $blocking ? 'BLOCK' : 'UNBLOCK' ?> user '<?= htmlspecialchars($u['username']) ?>'?">
                                                        <?= $blocking ? ua_icon('ban') . 'Block user' : ua_icon('check') . 'Unblock user' ?>
                                                    </button>
                                                </form>
                                                <?php if (is_account_locked($u)): ?>
                                                    <form action="users.php<?= htmlspecialchars($qs) ?>" method="POST">
                                                        <input type="hidden" name="action" value="unlock">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="row-menu-item confirm-action" role="menuitem"
                                                                data-confirm-message="Clear the login lockout on '<?= htmlspecialchars($u['username']) ?>'?">
                                                            <?= ua_icon('unlock') ?>Clear login lockout
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($can_manage): ?>
                                                <?php if ($can_moderate): ?><div class="row-menu-sep" role="separator"></div><?php endif; ?>
                                                <?php $new_role = $is_admin_row ? 'member' : 'admin'; ?>
                                                <form action="users.php<?= htmlspecialchars($qs) ?>" method="POST">
                                                    <input type="hidden" name="action" value="set_role">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="new_role" value="<?= $new_role ?>">
                                                    <button type="submit" class="row-menu-item confirm-action" role="menuitem"
                                                            data-confirm-message="<?= $is_admin_row
                                                                ? "Demote '" . htmlspecialchars($u['username']) . "' to a regular member?"
                                                                : "Promote '" . htmlspecialchars($u['username']) . "' to admin? They will need an email code to log in." ?>">
                                                        <?= ua_icon('shield') ?><?= $is_admin_row ? 'Demote to member' : 'Promote to admin' ?>
                                                    </button>
                                                </form>
                                                <a href="users.php?action=reset&id=<?= $u['id'] ?><?= htmlspecialchars($qs_amp) ?>" class="row-menu-item" role="menuitem">
                                                    <?= ua_icon('key') ?>Reset password
                                                </a>
                                                <form action="users.php<?= htmlspecialchars($qs) ?>" method="POST">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="row-menu-item is-danger confirm-action" role="menuitem"
                                                            data-confirm-message="Permanently DELETE account '<?= htmlspecialchars($u['username']) ?>'? This cannot be undone.">
                                                        <?= ua_icon('trash') ?>Delete account
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($pg, 'users.php', $base_params) ?>
        <?php endif; ?>

        <?php if (!$is_super): ?>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 1rem;">
                Role changes, account creation/deletion and admin-account moderation are handled by the super administrator.
            </p>
        <?php endif; ?>
    </section>

<?php if ($drawer !== 'none'): ?>
    <a href="<?= htmlspecialchars($redirect_self) ?>" class="admin-drawer-scrim" aria-label="Close panel"></a>
    <aside class="admin-drawer" role="dialog" aria-modal="true"
           aria-label="<?= $drawer === 'detail' ? 'Account details' : ($drawer === 'reset' ? 'Reset password' : 'Create account') ?>">

        <?php if ($drawer === 'detail'): ?>
            <div class="admin-drawer-head">
                <h3>Account Details</h3>
                <a href="<?= htmlspecialchars($redirect_self) ?>" class="admin-drawer-close" aria-label="Close">&times;</a>
            </div>

            <div style="text-align: center;">
                <div style="margin-bottom: 1.5rem; display: flex; justify-content: center;">
                    <?php
                    $photo_url = '../uploads/profiles/' . $expand_user['photo'];
                    if (!empty($expand_user['photo']) && file_exists(__DIR__ . '/' . $photo_url)):
                    ?>
                        <img src="<?= htmlspecialchars($photo_url) ?>" class="photo-preview" style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid var(--primary-light);" alt="Profile Image">
                    <?php else: ?>
                        <div style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid var(--primary-light); background-color: var(--primary-dark); color: var(--bg-cream); display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: 700; text-transform: uppercase;">
                            <?= htmlspecialchars(substr($expand_user['username'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h2 style="font-size: 1.5rem; margin-bottom: 0.2rem;"><?= htmlspecialchars($expand_user['full_name']) ?></h2>
                <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">@<?= htmlspecialchars($expand_user['username']) ?></p>
            </div>

            <table class="table" style="box-shadow: none; border: none; font-size: 0.9rem; margin-bottom: 1.5rem;">
                <tbody>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Role:</td>
                        <td style="padding: 0.5rem 0; text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $expand_user['role'])) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Email:</td>
                        <td style="padding: 0.5rem 0; word-break: break-all;"><?= htmlspecialchars($expand_user['email']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Phone:</td>
                        <td style="padding: 0.5rem 0;"><?= htmlspecialchars($expand_user['phone'] ?? 'Not provided') ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Status:</td>
                        <td style="padding: 0.5rem 0;">
                            <span class="badge badge-<?= ($expand_user['status'] === 'active') ? 'active' : 'blocked' ?>">
                                <?= htmlspecialchars($expand_user['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Login Lockout:</td>
                        <td style="padding: 0.5rem 0;">
                            <?php if (is_account_locked($expand_user)): ?>
                                <span class="badge badge-locked">
                                    Locked (<?= get_lockout_minutes_remaining($expand_user) ?>m left)
                                </span>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">
                                    Until <?= date('d F Y, h:i A', strtotime($expand_user['locked_until'])) ?>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-active">Not locked</span>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">
                                    Failed attempts: <?= (int) $expand_user['failed_attempts'] ?> / <?= MAX_LOGIN_ATTEMPTS ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Registered:</td>
                        <td style="padding: 0.5rem 0;"><?= date('d F Y, h:i A', strtotime($expand_user['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600; padding: 0.5rem 0;">Sec. Question:</td>
                        <td style="padding: 0.5rem 0; font-size: 0.85rem; color: var(--text-muted); font-style: italic;"><?= htmlspecialchars($expand_user['security_question']) ?></td>
                    </tr>
                </tbody>
            </table>

            <a href="<?= htmlspecialchars($redirect_self) ?>" class="btn btn-secondary btn-block">Close</a>

        <?php elseif ($drawer === 'reset'): ?>
            <div class="admin-drawer-head">
                <h3>Reset Password</h3>
                <a href="<?= htmlspecialchars($redirect_self) ?>" class="admin-drawer-close" aria-label="Close">&times;</a>
            </div>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Set a new password for <strong>@<?= htmlspecialchars($reset_target['username']) ?></strong>.
                Any active login lockout on this account is also cleared.
            </p>
            <form action="users.php<?= htmlspecialchars($qs) ?>" method="POST" novalidate>
                <input type="hidden" name="action" value="reset_pw">
                <input type="hidden" name="user_id" value="<?= $reset_target['id'] ?>">
                <?= html_input('password', 'new_password', '', 'New Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Save New Password</button>
                <a href="<?= htmlspecialchars($redirect_self) ?>" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
            </form>

        <?php elseif ($drawer === 'create'): ?>
            <div class="admin-drawer-head">
                <h3>Create Account</h3>
                <a href="<?= htmlspecialchars($redirect_self) ?>" class="admin-drawer-close" aria-label="Close">&times;</a>
            </div>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Admin accounts sign in through <code>/admin/login.php</code> with an email code.
            </p>
            <form action="users.php?panel=create<?= htmlspecialchars($qs_amp) ?>" method="POST" novalidate>
                <input type="hidden" name="action" value="add">
                <?= html_input('text', 'username', $f_username, 'Username', 'Choose a username', $errors) ?>
                <?= html_input('text', 'full_name', $f_full_name, 'Full Name', 'Enter full name', $errors) ?>
                <?= html_input('email', 'email', $f_email, 'Email Address', 'Where login codes are sent', $errors) ?>
                <?= html_input('text', 'phone', $f_phone, 'Phone (Optional)', 'e.g. 0123456789', $errors) ?>
                <?= html_select('role', ['admin' => 'Admin', 'member' => 'Member'], $f_role, 'Role', $errors) ?>
                <?= html_input('password', 'password', '', 'Password', 'Min 6 characters', $errors) ?>
                <?= html_input('password', 'confirm_password', '', 'Confirm Password', 'Re-enter password', $errors) ?>
                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Create Account</button>
                <a href="<?= htmlspecialchars($redirect_self) ?>" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
            </form>
        <?php endif; ?>

    </aside>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
