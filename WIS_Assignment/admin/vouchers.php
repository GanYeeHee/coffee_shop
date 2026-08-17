<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$code = '';
$discount_percent = '';
$min_spend = '0';
$status = 'active';
$status_options = ['active' => 'Active', 'inactive' => 'Inactive'];

// Handle Voucher CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';

    if ($submitted_action === 'add' || $submitted_action === 'edit') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $discount_percent = $_POST['discount_percent'] ?? '';
        $min_spend = $_POST['min_spend'] ?? '0';
        $status = $_POST['status'] ?? 'active';

        $errors = validate_required($_POST, [
            'code' => 'Voucher Code',
            'discount_percent' => 'Discount Percent'
        ]);

        if (empty($errors['discount_percent'])) {
            if (!is_numeric($discount_percent) || floatval($discount_percent) <= 0 || floatval($discount_percent) > 100) {
                $errors['discount_percent'] = "Discount must be a number between 0 and 100.";
            }
        }

        if (!is_numeric($min_spend) || floatval($min_spend) < 0) {
            $errors['min_spend'] = "Minimum spend must be a non-negative number.";
        }

        if (!array_key_exists($status, $status_options)) {
            $status = 'active';
        }

        if (empty($errors)) {
            // Check for code uniqueness (excluding current row on edit)
            $check_sql = "SELECT COUNT(*) FROM vouchers WHERE code = ?" . ($submitted_action === 'edit' ? " AND id != ?" : "");
            $check_params = ($submitted_action === 'edit') ? [$code, $id] : [$code];
            $stmt = $pdo->prepare($check_sql);
            $stmt->execute($check_params);
            if ($stmt->fetchColumn() > 0) {
                $errors['code'] = "A voucher with this code already exists.";
            }
        }

        if (empty($errors)) {
            try {
                if ($submitted_action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO vouchers (code, discount_percent, min_spend, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$code, floatval($discount_percent), floatval($min_spend), $status]);
                    $_SESSION['flash_success'] = "Voucher '{$code}' created successfully!";
                } else {
                    $stmt = $pdo->prepare("UPDATE vouchers SET code = ?, discount_percent = ?, min_spend = ?, status = ? WHERE id = ?");
                    $stmt->execute([$code, floatval($discount_percent), floatval($min_spend), $status, $id]);
                    $_SESSION['flash_success'] = "Voucher updated successfully!";
                }
                header("Location: vouchers.php");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to save voucher. DB Error: " . $e->getMessage();
            }
        }
    }
}

// Handle Direct Delete Action
if ($action === 'delete' && $id > 0) {
    // orders.voucher_id FK is ON DELETE SET NULL, so historical orders keep their discount_amount
    $stmt = $pdo->prepare("DELETE FROM vouchers WHERE id = ?");
    try {
        $stmt->execute([$id]);
        $_SESSION['flash_success'] = "Voucher deleted successfully.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Failed to delete voucher: " . $e->getMessage();
    }
    header("Location: vouchers.php");
    exit;
}

// Fetch single voucher for editing
if ($action === 'edit' && $id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stmt->execute([$id]);
    $v = $stmt->fetch();
    if ($v) {
        $code = $v['code'];
        $discount_percent = $v['discount_percent'];
        $min_spend = $v['min_spend'];
        $status = $v['status'];
    } else {
        $_SESSION['flash_error'] = "Voucher not found.";
        header("Location: vouchers.php");
        exit;
    }
}

// Fetch all vouchers for table list
$all_vouchers = $pdo->query("SELECT * FROM vouchers ORDER BY created_at DESC")->fetchAll();

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Vouchers</h1>
    <?php if ($action !== 'list'): ?>
        <a href="vouchers.php" class="btn btn-secondary btn-sm">&larr; Back to List</a>
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

<div style="display: grid; grid-template-columns: <?= ($action === 'list' || $action === 'edit') ? '1.5fr 1fr' : '1fr' ?>; gap: 2.5rem; align-items: start;">

    <!-- Left Column: Vouchers List Table -->
    <section class="admin-panel">
        <h3>All Vouchers</h3>
        <?php if (empty($all_vouchers)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No vouchers found. Create one on the right.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Discount</th>
                            <th>Min. Spend</th>
                            <th>Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_vouchers as $v): ?>
                            <tr style="<?= ($id === $v['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td><strong><?= htmlspecialchars($v['code']) ?></strong></td>
                                <td><?= number_format($v['discount_percent'], 2) ?>%</td>
                                <td>RM<?= number_format($v['min_spend'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= $v['status'] === 'active' ? 'active' : 'blocked' ?>">
                                        <?= htmlspecialchars(ucfirst($v['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="vouchers.php?action=edit&id=<?= $v['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.3rem 0.6rem;">Edit</a>
                                    <a href="vouchers.php?action=delete&id=<?= $v['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to delete voucher '<?= htmlspecialchars($v['code']) ?>'? Past orders that used it will keep their recorded discount.">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Right Column: Form Container (Add / Edit) -->
    <?php if ($action === 'list'): ?>
        <!-- ADD VOUCHER FORM -->
        <section class="admin-panel">
            <h3>Add New Voucher</h3>
            <form action="vouchers.php" method="POST" novalidate>
                <input type="hidden" name="action" value="add">

                <?= html_input('text', 'code', $code, 'Voucher Code', 'e.g. COFFEE10', $errors, ['maxlength' => '20']) ?>
                <?= html_input('text', 'discount_percent', $discount_percent, 'Discount Percent (%)', 'e.g. 10', $errors) ?>
                <?= html_input('text', 'min_spend', $min_spend, 'Minimum Spend (RM)', 'e.g. 10.00', $errors) ?>
                <?= html_select('status', $status_options, $status, 'Status', $errors) ?>

                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Create Voucher</button>
            </form>
        </section>
    <?php elseif ($action === 'edit' && $id > 0): ?>
        <!-- EDIT VOUCHER FORM -->
        <section class="admin-panel" style="border-color: var(--primary-light);">
            <h3>Edit Voucher Details</h3>
            <form action="vouchers.php?action=edit&id=<?= $id ?>" method="POST" novalidate>
                <input type="hidden" name="action" value="edit">

                <?= html_input('text', 'code', $code, 'Voucher Code', 'Update voucher code', $errors, ['maxlength' => '20']) ?>
                <?= html_input('text', 'discount_percent', $discount_percent, 'Discount Percent (%)', 'e.g. 10', $errors) ?>
                <?= html_input('text', 'min_spend', $min_spend, 'Minimum Spend (RM)', 'e.g. 10.00', $errors) ?>
                <?= html_select('status', $status_options, $status, 'Status', $errors) ?>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Save Changes</button>
                <a href="vouchers.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel Edit</a>
            </form>
        </section>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
