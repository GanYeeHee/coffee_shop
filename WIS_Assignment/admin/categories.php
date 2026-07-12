<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];
$success_msg = '';

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$name = '';
$description = '';

// Handle Category CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';
    
    if ($submitted_action === 'add') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        
        $errors = validate_required($_POST, ['name' => 'Category Name']);
        
        if (empty($errors)) {
            // Check for uniqueness
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $errors['name'] = "A category with this name already exists.";
            }
        }
        
        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            try {
                $stmt->execute([$name, $description !== '' ? $description : null]);
                $_SESSION['flash_success'] = "Category '{$name}' created successfully!";
                header("Location: categories.php");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to create category. DB Error: " . $e->getMessage();
            }
        }
    } elseif ($submitted_action === 'edit') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        
        $errors = validate_required($_POST, ['name' => 'Category Name']);
        
        if (empty($errors)) {
            // Check for uniqueness (excluding current)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors['name'] = "A category with this name already exists.";
            }
        }
        
        if (empty($errors)) {
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
            try {
                $stmt->execute([$name, $description !== '' ? $description : null, $id]);
                $_SESSION['flash_success'] = "Category updated successfully!";
                header("Location: categories.php");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to update category. DB Error: " . $e->getMessage();
            }
        }
    }
}

// Handle Direct Delete Action
if ($action === 'delete' && $id > 0) {
    // Delete category - database FK is ON DELETE SET NULL, so products will safely be preserved
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    try {
        $stmt->execute([$id]);
        $_SESSION['flash_success'] = "Category deleted successfully. Associated products were set to Uncategorized.";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Failed to delete category: " . $e->getMessage();
    }
    header("Location: categories.php");
    exit;
}

// Fetch single category for editing
if ($action === 'edit' && $id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if ($cat) {
        $name = $cat['name'];
        $description = $cat['description'] ?? '';
    } else {
        $_SESSION['flash_error'] = "Category not found.";
        header("Location: categories.php");
        exit;
    }
}

// Fetch all categories for table list
$categories_stmt = $pdo->query("SELECT c.*, COUNT(p.id) as product_count 
                                FROM categories c 
                                LEFT JOIN products p ON c.id = p.category_id 
                                GROUP BY c.id 
                                ORDER BY c.name ASC");
$all_categories = $categories_stmt->fetchAll();

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Categories</h1>
    <?php if ($action !== 'list'): ?>
        <a href="categories.php" class="btn btn-secondary btn-sm">&larr; Back to List</a>
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
    
    <!-- Left Column: Categories List Table -->
    <section class="admin-panel">
        <h3>All Categories</h3>
        <?php if (empty($all_categories)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No categories found. Create one on the right.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th style="width: 100px; text-align: center;">Products</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_categories as $c): ?>
                            <tr style="<?= ($id === $c['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                <td style="font-size: 0.9rem; color: var(--text-muted);"><?= htmlspecialchars($c['description'] ?? 'No description.') ?></td>
                                <td style="text-align: center;"><span class="badge badge-processing"><?= $c['product_count'] ?></span></td>
                                <td>
                                    <a href="categories.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.3rem 0.6rem;">Edit</a>
                                    <a href="categories.php?action=delete&id=<?= $c['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to delete category '<?= htmlspecialchars($c['name']) ?>'? Associated products will NOT be deleted, they will become uncategorized.">Delete</a>
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
        <!-- ADD CATEGORY FORM -->
        <section class="admin-panel">
            <h3>Add New Category</h3>
            <form action="categories.php" method="POST" novalidate>
                <input type="hidden" name="action" value="add">
                
                <?= html_input('text', 'name', $name, 'Category Name', 'e.g. Espresso Drinks', $errors) ?>
                <?= html_textarea('description', $description, 'Description (Optional)', 'Brief summary of this category...', $errors) ?>
                
                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1rem;">Create Category</button>
            </form>
        </section>
    <?php elseif ($action === 'edit' && $id > 0): ?>
        <!-- EDIT CATEGORY FORM -->
        <section class="admin-panel" style="border-color: var(--primary-light);">
            <h3>Edit Category Details</h3>
            <form action="categories.php?id=<?= $id ?>" method="POST" novalidate>
                <input type="hidden" name="action" value="edit">
                
                <?= html_input('text', 'name', $name, 'Category Name', 'Update category name', $errors) ?>
                <?= html_textarea('description', $description, 'Description (Optional)', 'Update description...', $errors) ?>
                
                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Save Changes</button>
                <a href="categories.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel Edit</a>
            </form>
        </section>
    <?php endif; ?>
    
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
