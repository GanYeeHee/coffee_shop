<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';

    if ($submitted_action === 'add_template') {
        $name = $_POST['name'] ?? '';
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        $errors = validate_required($_POST, ['name' => 'Template Name']);

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM option_templates WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $errors['name'] = "A template with this name already exists.";
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO option_templates (name, is_required) VALUES (?, ?)");
            $stmt->execute([$name, $is_required]);
            $_SESSION['flash_success'] = "Template '{$name}' created.";
            header("Location: option_templates.php");
            exit;
        }
    } elseif ($submitted_action === 'add_value') {
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $label = $_POST['label'] ?? '';
        $price_delta = $_POST['price_delta'] ?? '0';

        $errors = validate_required($_POST, ['label' => 'Value Label']);

        if (!is_numeric($price_delta)) {
            $errors['price_delta'] = "Price adjustment must be a number.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO option_template_values (template_id, label, price_delta) VALUES (?, ?, ?)");
            $stmt->execute([$template_id, $label, floatval($price_delta)]);
            $_SESSION['flash_success'] = "Value '{$label}' added.";
            header("Location: option_templates.php");
            exit;
        }
    }
}

// Handle Direct Delete Actions
if (isset($_GET['action']) && $_GET['action'] === 'delete_template' && isset($_GET['template_id'])) {
    $stmt = $pdo->prepare("DELETE FROM option_templates WHERE id = ?");
    $stmt->execute([intval($_GET['template_id'])]);
    $_SESSION['flash_success'] = "Template deleted.";
    header("Location: option_templates.php");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_value' && isset($_GET['value_id'])) {
    $stmt = $pdo->prepare("DELETE FROM option_template_values WHERE id = ?");
    $stmt->execute([intval($_GET['value_id'])]);
    $_SESSION['flash_success'] = "Value deleted.";
    header("Location: option_templates.php");
    exit;
}

// Fetch templates + values
$templates_stmt = $pdo->query("SELECT * FROM option_templates ORDER BY id ASC");
$templates = $templates_stmt->fetchAll();

$values_stmt = $pdo->prepare("SELECT * FROM option_template_values WHERE template_id = ? ORDER BY id ASC");
foreach ($templates as &$template) {
    $values_stmt->execute([$template['id']]);
    $template['values'] = $values_stmt->fetchAll();
}
unset($template);

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Option Templates</h1>
    <a href="products.php" class="btn btn-secondary btn-sm">&larr; Back to Products</a>
</div>

<p style="color: var(--text-muted); margin-bottom: 1.5rem;">
    A shared library of reusable option groups (e.g. Temperature, Size, Sugar Level, Color). Define them once here
    with the correct spelling and values, then go to a specific product's "Options" page to check which templates apply to it.
    Not every product needs to use any of these — a template only affects a product once its checkbox is ticked there.
</p>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if (empty($templates)): ?>
    <div class="admin-panel">
        <p style="color: var(--text-muted);">No templates yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($templates as $template): ?>
        <div class="admin-panel" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3>
                    <?= htmlspecialchars($template['name']) ?>
                    <?php if ($template['is_required']): ?>
                        <span class="badge badge-flag-yes">Required by default</span>
                    <?php else: ?>
                        <span class="badge badge-flag-no">Optional by default</span>
                    <?php endif; ?>
                </h3>
                <a href="option_templates.php?action=delete_template&template_id=<?= $template['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete template '<?= htmlspecialchars($template['name']) ?>' and all its values? Products that already applied this template keep their own copy.">Delete Template</a>
            </div>

            <?php if (empty($template['values'])): ?>
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
                        <?php foreach ($template['values'] as $value): ?>
                            <tr>
                                <td><?= htmlspecialchars($value['label']) ?></td>
                                <td><?= $value['price_delta'] > 0 ? '+RM' . number_format($value['price_delta'], 2) : 'No change' ?></td>
                                <td>
                                    <a href="option_templates.php?action=delete_value&value_id=<?= $value['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete value '<?= htmlspecialchars($value['label']) ?>'?">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form action="option_templates.php" method="POST" novalidate style="margin-top: 1rem; display: grid; grid-template-columns: 2fr 1fr auto; gap: 0.75rem; align-items: start;">
                <input type="hidden" name="action" value="add_value">
                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                <?= html_input('text', 'label', '', 'New Value Label', 'e.g. 16oz', $errors) ?>
                <?= html_input('text', 'price_delta', '', 'Price Adjustment (RM)', 'e.g. 2.00 or 0', $errors) ?>
                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top: 1.9rem;">Add Value</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="admin-panel" style="max-width: 500px;">
    <h3>Add New Template</h3>
    <form action="option_templates.php" method="POST" novalidate>
        <input type="hidden" name="action" value="add_template">
        <?= html_input('text', 'name', '', 'Template Name', 'e.g. Temperature, Size, Sugar Level', $errors) ?>
        <div class="form-group">
            <label><input type="checkbox" name="is_required" checked style="width: auto; margin-right: 0.5rem;">Required by default when applied to a product</label>
        </div>
        <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Add Template</button>
    </form>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
