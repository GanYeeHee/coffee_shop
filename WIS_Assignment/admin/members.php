<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Handle Block / Unblock Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'];
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    // Safety check: Cannot block yourself
    if ($user_id === $_SESSION['user_id']) {
        $_SESSION['flash_error'] = "You cannot block your own administrator account!";
    } else {
        // Fetch user status
        $stmt = $pdo->prepare("SELECT username, status, role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $target_user = $stmt->fetch();
        
        if ($target_user) {
            if ($action === 'toggle_block') {
                $new_status = ($target_user['status'] === 'active') ? 'blocked' : 'active';
                
                $upd_stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                try {
                    $upd_stmt->execute([$new_status, $user_id]);
                    $message = ($new_status === 'blocked') ? "blocked" : "unblocked";
                    $_SESSION['flash_success'] = "User '{$target_user['username']}' has been successfully {$message}.";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Failed to update user status: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['flash_error'] = "User not found.";
        }
    }
    
    header("Location: members.php" . ($search !== '' ? '?q=' . urlencode($search) : ''));
    exit;
}

// Build Search Query for Users
$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql .= " ORDER BY role ASC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Handle Expand Detail View
$expand_user_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$expand_user = null;

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

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Members</h1>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: <?= ($expand_user) ? '1.5fr 1fr' : '1fr' ?>; gap: 2.5rem; align-items: start;">
    
    <!-- Left Column: User Table List -->
    <section class="admin-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.2rem;">
            <h3>User Accounts Registry</h3>
            
            <form action="members.php" method="GET" style="display: flex; gap: 0.5rem; width: 320px;">
                <input type="text" name="q" class="form-control" placeholder="Search members..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.5rem;">
                <button type="submit" class="btn btn-secondary btn-sm">Search</button>
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
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr style="<?= ($expand_user_id === $u['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($u['username']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($u['full_name']) ?></td>
                                <td>
                                    <span style="text-transform: capitalize; font-weight: 500; color: <?= ($u['role'] === 'admin') ? 'var(--accent)' : 'var(--text-muted)' ?>;">
                                        <?= $u['role'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= ($u['status'] === 'active') ? 'active' : 'blocked' ?>">
                                        <?= htmlspecialchars($u['status']) ?>
                                    </span>
                                </td>
                                <td style="display: flex; gap: 0.4rem;">
                                    <a href="members.php?detail_id=<?= $u['id'] ?><?= ($search !== '') ? '&q=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">Details</a>
                                    
                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                        <form action="members.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="toggle_block">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn <?= ($u['status'] === 'active') ? 'btn-danger' : 'btn-primary' ?> btn-sm confirm-action" data-confirm-message="Are you sure you want to <?= ($u['status'] === 'active') ? 'BLOCK' : 'UNBLOCK' ?> user '<?= htmlspecialchars($u['username']) ?>'?" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">
                                                <?= ($u['status'] === 'active') ? 'Block' : 'Unblock' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    
    <!-- Right Column: User Details Card -->
    <?php if ($expand_user): ?>
        <section class="admin-panel dialog-container" style="margin-top: 0; text-align: center;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem; margin-bottom: 1.5rem;">
                <h3 style="border-bottom: none; margin: 0;">Account Details</h3>
                <a href="members.php<?= ($search !== '') ? '?q=' . urlencode($search) : '' ?>" style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">&times;</a>
            </div>
            
            <div style="margin-bottom: 1.5rem; display: flex; justify-content: center;">
                <?php 
                $photo_url = '../uploads/profiles/' . $expand_user['photo'];
                if (!empty($expand_user['photo']) && file_exists(__DIR__ . '/' . $photo_url)): 
                ?>
                    <img src="<?= $photo_url ?>" class="photo-preview" style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid var(--primary-light);" alt="Profile Image">
                <?php else: ?>
                    <div style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid var(--primary-light); background-color: var(--primary-dark); color: var(--bg-cream); display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: 700; text-transform: uppercase; margin: 0 auto;">
                        <?= substr($expand_user['username'], 0, 2) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <h2 style="font-size: 1.5rem; margin-bottom: 0.2rem;"><?= htmlspecialchars($expand_user['full_name']) ?></h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">@<?= htmlspecialchars($expand_user['username']) ?> &bull; <?= htmlspecialchars($expand_user['role']) ?></p>
            
            <div class="table-responsive" style="margin-bottom: 1.5rem; text-align: left;">
                <table class="table" style="box-shadow: none; border: none; font-size: 0.9rem;">
                    <tbody>
                        <tr>
                            <td style="font-weight: 600; padding: 0.5rem 0;">Email:</td>
                            <td style="padding: 0.5rem 0;"><?= htmlspecialchars($expand_user['email']) ?></td>
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
                            <td style="font-weight: 600; padding: 0.5rem 0;">Registered:</td>
                            <td style="padding: 0.5rem 0;"><?= date('d F Y, h:i A', strtotime($expand_user['created_at'])) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600; padding: 0.5rem 0;">Sec. Question:</td>
                            <td style="padding: 0.5rem 0; font-size: 0.85rem; color: var(--text-muted); font-style: italic;"><?= htmlspecialchars($expand_user['security_question']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <a href="members.php<?= ($search !== '') ? '?q=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-sm" style="width: 100%;">Close Details</a>
        </section>
    <?php endif; ?>
    
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
