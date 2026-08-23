<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    $_SESSION['flash_error'] = "Product not found.";
    header("Location: products.php");
    exit;
}

$errors = [];

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';

    if ($submitted_action === 'add_group') {
        $name = $_POST['name'] ?? '';
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        $errors = validate_required($_POST, ['name' => 'Option Group Name']);

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO product_option_groups (product_id, name, is_required) VALUES (?, ?, ?)");
            $stmt->execute([$product_id, $name, $is_required]);
            $_SESSION['flash_success'] = "Option group '{$name}' added.";
            header("Location: product_options.php?product_id=" . $product_id);
            exit;
        }
    } elseif ($submitted_action === 'add_value') {
        $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
        $label = $_POST['label'] ?? '';
        $price_delta = $_POST['price_delta'] ?? '0';

        $errors = validate_required($_POST, ['label' => 'Option Label']);

        if (!is_numeric($price_delta)) {
            $errors['price_delta'] = "Price adjustment must be a number.";
        }

        // Confirm the group belongs to this product
        $group_stmt = $pdo->prepare("SELECT id FROM product_option_groups WHERE id = ? AND product_id = ?");
        $group_stmt->execute([$group_id, $product_id]);
        if (!$group_stmt->fetch()) {
            $errors['general'] = "Invalid option group.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO product_option_values (group_id, label, price_delta) VALUES (?, ?, ?)");
            $stmt->execute([$group_id, $label, floatval($price_delta)]);
            $_SESSION['flash_success'] = "Option value '{$label}' added.";
            header("Location: product_options.php?product_id=" . $product_id);
            exit;
        }
    } elseif ($submitted_action === 'sync_templates') {
        $checked_ids = isset($_POST['templates']) ? array_map('intval', $_POST['templates']) : [];

        $applied_stmt = $pdo->prepare("SELECT id, template_id FROM product_option_groups WHERE product_id = ? AND template_id IS NOT NULL");
        $applied_stmt->execute([$product_id]);
        $applied = $applied_stmt->fetchAll();
        $applied_by_template = [];
        foreach ($applied as $row) {
            $applied_by_template[$row['template_id']] = $row['id'];
        }

        $pdo->beginTransaction();

        // Remove templates that were unchecked
        foreach ($applied_by_template as $template_id => $group_id) {
            if (!in_array($template_id, $checked_ids, true)) {
                $pdo->prepare("DELETE FROM product_option_groups WHERE id = ?")->execute([$group_id]);
            }
        }

        // Copy in newly checked templates
        $new_template_ids = array_diff($checked_ids, array_keys($applied_by_template));
        if (!empty($new_template_ids)) {
            $template_stmt = $pdo->prepare("SELECT * FROM option_templates WHERE id = ?");
            $template_value_stmt = $pdo->prepare("SELECT * FROM option_template_values WHERE template_id = ?");
            $insert_group_stmt = $pdo->prepare("INSERT INTO product_option_groups (product_id, template_id, name, is_required) VALUES (?, ?, ?, ?)");
            $insert_value_stmt = $pdo->prepare("INSERT INTO product_option_values (group_id, label, price_delta) VALUES (?, ?, ?)");

            foreach ($new_template_ids as $template_id) {
                $template_stmt->execute([$template_id]);
                $template = $template_stmt->fetch();
                if (!$template) {
                    continue;
                }

                $insert_group_stmt->execute([$product_id, $template_id, $template['name'], $template['is_required']]);
                $new_group_id = $pdo->lastInsertId();

                $template_value_stmt->execute([$template_id]);
                foreach ($template_value_stmt->fetchAll() as $tv) {
                    $insert_value_stmt->execute([$new_group_id, $tv['label'], $tv['price_delta']]);
                }
            }
        }

        $pdo->commit();

        $_SESSION['flash_success'] = "Templates updated.";
        header("Location: product_options.php?product_id=" . $product_id);
        exit;
    }
}

// Handle Direct Delete Actions
if (isset($_GET['action']) && $_GET['action'] === 'delete_group' && isset($_GET['group_id'])) {
    $stmt = $pdo->prepare("DELETE FROM product_option_groups WHERE id = ? AND product_id = ?");
    $stmt->execute([intval($_GET['group_id']), $product_id]);
    $_SESSION['flash_success'] = "Option group deleted.";
    header("Location: product_options.php?product_id=" . $product_id);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_value' && isset($_GET['value_id'])) {
    $stmt = $pdo->prepare("DELETE FROM product_option_values WHERE id = ? AND group_id IN (SELECT id FROM product_option_groups WHERE product_id = ?)");
    $stmt->execute([intval($_GET['value_id']), $product_id]);
    $_SESSION['flash_success'] = "Option value deleted.";
    header("Location: product_options.php?product_id=" . $product_id);
    exit;
}

// Fetch the template library, flagging which ones are already applied to this product
$templates_stmt = $pdo->prepare("SELECT t.*, EXISTS(
                                    SELECT 1 FROM product_option_groups
                                    WHERE product_id = ? AND template_id = t.id
                                  ) as is_applied
                                  FROM option_templates t ORDER BY t.id ASC");
$templates_stmt->execute([$product_id]);
$templates = $templates_stmt->fetchAll();

// Fetch groups + values for this product
$groups_stmt = $pdo->prepare("SELECT * FROM product_option_groups WHERE product_id = ? ORDER BY id ASC");
$groups_stmt->execute([$product_id]);
$groups = $groups_stmt->fetchAll();

$values_stmt = $pdo->prepare("SELECT * FROM product_option_values WHERE group_id = ? ORDER BY id ASC");
foreach ($groups as &$group) {
    $values_stmt->execute([$group['id']]);
    $group['values'] = $values_stmt->fetchAll();
}
unset($group);

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Options: <?= htmlspecialchars($product['name']) ?></h1>
    <a href="products.php" class="btn btn-secondary btn-sm">&larr; Back to Products</a>
</div>

<p style="color: var(--text-muted); margin-bottom: 1.5rem;">
    Define the choices customers must (or may) pick on this product's page — e.g. Temperature, Size, Sugar Level for drinks,
    or Color for merchandise. Products with no groups here show no options at all.
</p>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if (isset($errors['general'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
<?php endif; ?>

<div class="admin-panel" style="margin-bottom: 1.5rem;">
    <h3>Apply Templates</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">
        Check the templates that apply to this product. Not every product is a beverage — leave everything
        unchecked for a product that needs no options at all.
        <a href="option_templates.php">Manage the template library &rarr;</a>
    </p>

    <?php if (empty($templates)): ?>
        <p style="color: var(--text-muted);">No templates defined yet. <a href="option_templates.php">Create one</a>.</p>
    <?php else: ?>
        <form action="product_options.php?product_id=<?= $product_id ?>" method="POST" novalidate>
            <input type="hidden" name="action" value="sync_templates">
            <?php foreach ($templates as $template): ?>
                <div class="form-group" style="margin-bottom: 0.5rem;">
                    <label>
                        <input type="checkbox" name="templates[]" value="<?= $template['id'] ?>" style="width: auto; margin-right: 0.5rem;" <?= $template['is_applied'] ? 'checked' : '' ?>>
                        <?= htmlspecialchars($template['name']) ?>
                        <?= $template['is_required'] ? '<span class="badge badge-flag-yes">Required</span>' : '<span class="badge badge-flag-no">Optional</span>' ?>
                    </label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-accent btn-sm" style="margin-top: 0.5rem;">Save Templates</button>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($groups)): ?>
    <div class="admin-panel">
        <p style="color: var(--text-muted);">No option groups yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <div class="admin-panel" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3>
                    <?= htmlspecialchars($group['name']) ?>
                    <?php if ($group['is_required']): ?>
                        <span class="badge badge-flag-yes">Required</span>
                    <?php else: ?>
                        <span class="badge badge-flag-no">Optional</span>
                    <?php endif; ?>
                </h3>
                <a href="product_options.php?product_id=<?= $product_id ?>&action=delete_group&group_id=<?= $group['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete option group '<?= htmlspecialchars($group['name']) ?>' and all its values?">Delete Group</a>
            </div>

            <?php if (empty($group['values'])): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.5rem;">No values yet.</p>
            <?php else: ?>
                <table class="table" style="margin-top: 1rem;">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Price Adjustment</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['values'] as $value): ?>
                            <tr>
                                <td><?= htmlspecialchars($value['label']) ?></td>
                                <td><?= $value['price_delta'] > 0 ? '+RM' . number_format($value['price_delta'], 2) : 'No change' ?></td>
                                <td>
                                    <a href="product_options.php?product_id=<?= $product_id ?>&action=delete_value&value_id=<?= $value['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete option value '<?= htmlspecialchars($value['label']) ?>'?">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form action="product_options.php?product_id=<?= $product_id ?>" method="POST" novalidate style="margin-top: 1rem; display: grid; grid-template-columns: 2fr 1fr auto; gap: 0.75rem; align-items: start;">
                <input type="hidden" name="action" value="add_value">
                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                <?= html_input('text', 'label', '', 'New Value Label', 'e.g. 16oz', $errors) ?>
                <?= html_input('text', 'price_delta', '', 'Price Adjustment (RM)', 'e.g. 2.00 or 0', $errors) ?>
                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top: 1.9rem;">Add Value</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="admin-panel" style="max-width: 500px;">
    <h3>Add New Option Group</h3>
    <form action="product_options.php?product_id=<?= $product_id ?>" method="POST" novalidate>
        <input type="hidden" name="action" value="add_group">
        <?= html_input('text', 'name', '', 'Group Name', 'e.g. Temperature, Size, Sugar Level', $errors) ?>
        <div class="form-group">
            <label><input type="checkbox" name="is_required" checked style="width: auto; margin-right: 0.5rem;">Customer must choose an option in this group</label>
        </div>
        <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Add Group</button>
    </form>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
