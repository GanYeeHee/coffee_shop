<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch all categories for form selections
$cat_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $cat_stmt->fetchAll();
$category_options = [0 => '-- Select Category --'];
foreach ($categories as $cat) {
    $category_options[$cat['id']] = $cat['name'];
}

$name = '';
$category_id = 0;
$description = '';
$price = '';
$stock = '';
$photo_name = '';

// Handle POST Form Submissions (Add / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';
    
    $name = $_POST['name'] ?? '';
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $description = $_POST['description'] ?? '';
    $price = $_POST['price'] ?? '';
    $stock = $_POST['stock'] ?? '';
    
    // Core validations
    $req_fields = [
        'name' => 'Product Name',
        'price' => 'Price',
        'stock' => 'Stock Quantity'
    ];
    $errors = validate_required($_POST, $req_fields);
    
    if ($category_id <= 0) {
        $errors['category_id'] = "Please select a valid category.";
    }
    
    if (empty($errors['price'])) {
        $price_err = validate_price($price);
        if ($price_err) {
            $errors['price'] = $price_err;
        }
    }
    
    if (empty($errors['stock'])) {
        $stock_err = validate_stock($stock);
        if ($stock_err) {
            $errors['stock'] = $stock_err;
        }
    }
    
    // Photo validations
    $photo_uploaded = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK;
    if ($submitted_action === 'add') {
        $image_err = validate_image($_FILES['photo'] ?? null, true); // Required on add
        if ($image_err) {
            $errors['photo'] = $image_err;
        }
    } else {
        if ($photo_uploaded) {
            $image_err = validate_image($_FILES['photo']);
            if ($image_err) {
                $errors['photo'] = $image_err;
            }
        }
    }
    
    // DB Processing
    if (empty($errors)) {
        if ($submitted_action === 'add') {
            // Upload file
            $file_info = pathinfo($_FILES['photo']['name']);
            $extension = strtolower($file_info['extension']);
            $new_photo_name = 'product_' . time() . '_' . rand(100, 999) . '.' . $extension;
            $upload_path = __DIR__ . '/../uploads/products/' . $new_photo_name;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                $stmt = $pdo->prepare("INSERT INTO products (name, category_id, description, price, stock, photo) VALUES (?, ?, ?, ?, ?, ?)");
                try {
                    $stmt->execute([
                        $name,
                        $category_id,
                        $description !== '' ? $description : null,
                        floatval($price),
                        intval($stock),
                        $new_photo_name
                    ]);
                    $_SESSION['flash_success'] = "Product '{$name}' added successfully!";
                    header("Location: products.php");
                    exit;
                } catch (PDOException $e) {
                    $errors['general'] = "Failed to add product. DB Error: " . $e->getMessage();
                }
            } else {
                $errors['photo'] = "Failed to save product image file.";
            }
        } elseif ($submitted_action === 'edit' && $id > 0) {
            // Fetch current photo
            $stmt = $pdo->prepare("SELECT photo FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $current_photo = $stmt->fetchColumn();
            
            $final_photo_name = $current_photo;
            
            // Upload new file if present
            if ($photo_uploaded) {
                $file_info = pathinfo($_FILES['photo']['name']);
                $extension = strtolower($file_info['extension']);
                $new_photo_name = 'product_' . time() . '_' . rand(100, 999) . '.' . $extension;
                $upload_path = __DIR__ . '/../uploads/products/' . $new_photo_name;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    // Remove old image
                    if (!empty($current_photo) && file_exists(__DIR__ . '/../uploads/products/' . $current_photo)) {
                        unlink(__DIR__ . '/../uploads/products/' . $current_photo);
                    }
                    $final_photo_name = $new_photo_name;
                } else {
                    $errors['photo'] = "Failed to upload new product image.";
                }
            }
            
            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, description = ?, price = ?, stock = ?, photo = ? WHERE id = ?");
                try {
                    $stmt->execute([
                        $name,
                        $category_id,
                        $description !== '' ? $description : null,
                        floatval($price),
                        intval($stock),
                        $final_photo_name,
                        $id
                    ]);
                    $_SESSION['flash_success'] = "Product updated successfully!";
                    header("Location: products.php");
                    exit;
                } catch (PDOException $e) {
                    $errors['general'] = "Failed to update product. DB Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Delete Operation
if ($action === 'delete' && $id > 0) {
    // Retrieve product details to delete image
    $stmt = $pdo->prepare("SELECT photo, name FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();
    
    if ($prod) {
        $del_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        try {
            $del_stmt->execute([$id]);
            // Remove image from filesystem
            if (!empty($prod['photo']) && file_exists(__DIR__ . '/../uploads/products/' . $prod['photo'])) {
                unlink(__DIR__ . '/../uploads/products/' . $prod['photo']);
            }
            $_SESSION['flash_success'] = "Product '{$prod['name']}' deleted successfully.";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to delete product: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "Product not found.";
    }
    header("Location: products.php");
    exit;
}

// Fetch single product details (for Edit Form)
if ($action === 'edit' && $id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();
    if ($prod) {
        $name = $prod['name'];
        $category_id = $prod['category_id'];
        $description = $prod['description'] ?? '';
        $price = $prod['price'];
        $stock = $prod['stock'];
        $photo_name = $prod['photo'];
    } else {
        $_SESSION['flash_error'] = "Product not found.";
        header("Location: products.php");
        exit;
    }
}

// Fetch all products (for List Table) with keyword search
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sql = "SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id";
$params = [];

if ($search !== '') {
    $sql .= " WHERE p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql .= " ORDER BY p.id DESC";
$prod_stmt = $pdo->prepare($sql);
$prod_stmt->execute($params);
$all_products = $prod_stmt->fetchAll();

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Products</h1>
    <?php if ($action === 'list'): ?>
        <a href="products.php?action=add" class="btn btn-accent btn-sm">+ Add New Product</a>
    <?php else: ?>
        <a href="products.php" class="btn btn-secondary btn-sm">&larr; Back to List</a>
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

<!-- LIST VIEW -->
<?php if ($action === 'list'): ?>
    <div class="admin-panel" style="margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
            <h3>Product Inventory</h3>
            
            <form action="products.php" method="GET" style="display: flex; gap: 0.5rem; width: 350px;">
                <input type="text" name="q" class="form-control" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.5rem;">
                <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            </form>
        </div>
        
        <?php if (empty($all_products)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No products found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_products as $p): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $photo_path = '../uploads/products/' . $p['photo'];
                                    if (!empty($p['photo']) && file_exists(__DIR__ . '/' . $photo_path)): 
                                    ?>
                                        <img src="<?= $photo_path ?>" class="img-thumbnail" alt="<?= htmlspecialchars($p['name']) ?>">
                                    <?php else: ?>
                                        <div class="img-thumbnail" style="background-color: var(--primary-light); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">☕</div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td><?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?></td>
                                <td style="font-weight: 500; color: var(--accent);">$<?= number_format($p['price'], 2) ?></td>
                                <td><?= $p['stock'] ?></td>
                                <td>
                                    <?php if ($p['stock'] == 0): ?>
                                        <span class="badge badge-cancelled">Sold Out</span>
                                    <?php elseif ($p['stock'] <= 5): ?>
                                        <span class="badge badge-pending">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge badge-active">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="products.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.3rem 0.6rem;">Edit</a>
                                    <a href="products.php?action=delete&id=<?= $p['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to delete product '<?= htmlspecialchars($p['name']) ?>'? This will permanently delete the item and its image file from storage.">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<!-- ADD VIEW -->
<?php elseif ($action === 'add'): ?>
    <div class="admin-panel" style="max-width: 700px; margin: 0 auto;">
        <h3>Add New Coffee Product</h3>
        <form action="products.php?action=add" method="POST" enctype="multipart/form-data" novalidate style="margin-top: 1.5rem;">
            <input type="hidden" name="action" value="add">
            
            <?= html_input('text', 'name', $name, 'Product Name', 'e.g. Mocha Latte', $errors) ?>
            <?= html_select('category_id', $category_options, $category_id, 'Category', $errors) ?>
            <?= html_textarea('description', $description, 'Description', 'Provide description details of the drink/bean...', $errors) ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <?= html_input('text', 'price', $price, 'Price ($)', 'e.g., 4.50', $errors) ?>
                <?= html_input('number', 'stock', $stock, 'Stock Quantity', 'e.g. 20', $errors, ['min' => '0']) ?>
            </div>
            
            <div class="photo-upload-container">
                <div class="img-thumbnail" style="width: 80px; height: 80px; font-size: 2rem; display: flex; align-items: center; justify-content: center; background-color: var(--bg-cream); border: 2px dashed var(--border-color);">☕</div>
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label for="field-photo">Product Photo</label>
                    <input type="file" name="photo" id="field-photo" class="form-control" accept="image/*">
                    <?= html_error($errors, 'photo') ?>
                </div>
            </div>
            
            <button type="submit" class="btn btn-accent btn-block" style="margin-top: 2rem;">Save Product</button>
            <a href="products.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
        </form>
    </div>

<!-- EDIT VIEW -->
<?php elseif ($action === 'edit' && $id > 0): ?>
    <div class="admin-panel" style="max-width: 700px; margin: 0 auto;">
        <h3>Edit Product Details</h3>
        <form action="products.php?action=edit&id=<?= $id ?>" method="POST" enctype="multipart/form-data" novalidate style="margin-top: 1.5rem;">
            <input type="hidden" name="action" value="edit">
            
            <?= html_input('text', 'name', $name, 'Product Name', 'Update product name', $errors) ?>
            <?= html_select('category_id', $category_options, $category_id, 'Category', $errors) ?>
            <?= html_textarea('description', $description, 'Description', 'Update description details...', $errors) ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <?= html_input('text', 'price', $price, 'Price ($)', 'e.g., 4.50', $errors) ?>
                <?= html_input('number', 'stock', $stock, 'Stock Quantity', 'e.g., 20', $errors, ['min' => '0']) ?>
            </div>
            
            <div class="photo-upload-container">
                <?php 
                $photo_url = '../uploads/products/' . $photo_name;
                if (!empty($photo_name) && file_exists(__DIR__ . '/' . $photo_url)): 
                ?>
                    <img src="<?= $photo_url ?>" class="photo-preview" style="border-radius: var(--radius-sm);" alt="Current photo">
                <?php else: ?>
                    <div class="photo-preview" style="border-radius: var(--radius-sm); font-size: 2rem; display: flex; align-items: center; justify-content: center; background-color: var(--bg-cream);">☕</div>
                <?php endif; ?>
                
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <label for="field-photo">Replace Product Photo (Optional)</label>
                    <input type="file" name="photo" id="field-photo" class="form-control image-upload-input" accept="image/*">
                    <?= html_error($errors, 'photo') ?>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 2rem;">Save Changes</button>
            <a href="products.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
