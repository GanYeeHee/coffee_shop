<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

/**
 * Validate a batch of files from a photos[] multi-file input.
 * Returns the first validation error message found, or null if all valid.
 */
function validate_multi_images($files) {
    if (!isset($files['name'])) {
        return null;
    }
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $file = [
            'name' => $name,
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
        $err = validate_image($file);
        if ($err) {
            return $err;
        }
    }
    return null;
}

/**
 * Upload every file present in a photos[] multi-file input and insert
 * a product_images row for each. The first uploaded photo becomes primary
 * only when $make_first_primary is true (i.e. the product had no images yet).
 */
function upload_product_photos(PDO $pdo, $product_id, $files, $make_first_primary) {
    if (!isset($files['name'])) {
        return;
    }
    $inserted = 0;
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $file_info = pathinfo($name);
        $extension = strtolower($file_info['extension'] ?? '');
        $new_photo_name = 'product_' . time() . '_' . $product_id . '_' . rand(100, 999) . '.' . $extension;
        $upload_path = __DIR__ . '/../uploads/products/' . $new_photo_name;

        if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
            $is_primary = ($make_first_primary && $inserted === 0) ? 1 : 0;
            $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, ?)")
                ->execute([$product_id, $new_photo_name, $is_primary]);
            $inserted++;
        }
    }
}

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

// Handle POST Form Submissions (Add / Edit / Set Primary / Delete Image)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    $submitted_action = $_POST['action'] ?? '';

    if ($submitted_action === 'set_primary') {
        $image_id = intval($_POST['image_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($image_id > 0 && $product_id > 0) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$product_id]);
            $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?")->execute([$image_id, $product_id]);
            $pdo->commit();
        }
        header("Location: products.php?action=edit&id=" . $product_id);
        exit;
    }

    if ($submitted_action === 'delete_image') {
        $image_id = intval($_POST['image_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($image_id > 0 && $product_id > 0) {
            $img_stmt = $pdo->prepare("SELECT image_path, is_primary FROM product_images WHERE id = ? AND product_id = ?");
            $img_stmt->execute([$image_id, $product_id]);
            $img = $img_stmt->fetch();
            if ($img) {
                $pdo->prepare("DELETE FROM product_images WHERE id = ?")->execute([$image_id]);
                if (!empty($img['image_path']) && file_exists(__DIR__ . '/../uploads/products/' . $img['image_path'])) {
                    unlink(__DIR__ . '/../uploads/products/' . $img['image_path']);
                }
                // Auto-promote the next remaining image if the deleted one was primary
                if ($img['is_primary']) {
                    $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY id ASC LIMIT 1")->execute([$product_id]);
                }
            }
        }
        header("Location: products.php?action=edit&id=" . $product_id);
        exit;
    }

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
    $has_new_photos = isset($_FILES['photos']) && count(array_filter($_FILES['photos']['name'] ?? [])) > 0;
    if ($submitted_action === 'add' && !$has_new_photos) {
        $errors['photos'] = "Please upload at least one product photo.";
    } elseif ($has_new_photos) {
        $image_err = validate_multi_images($_FILES['photos']);
        if ($image_err) {
            $errors['photos'] = $image_err;
        }
    }

    // DB Processing
    if (empty($errors)) {
        if ($submitted_action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, description, price, stock) VALUES (?, ?, ?, ?, ?)");
            try {
                $pdo->beginTransaction();
                $stmt->execute([
                    $name,
                    $category_id,
                    $description !== '' ? $description : null,
                    floatval($price),
                    intval($stock)
                ]);
                $product_id = $pdo->lastInsertId();
                upload_product_photos($pdo, $product_id, $_FILES['photos'], true);
                $pdo->commit();
                $_SESSION['flash_success'] = "Product '{$name}' added successfully!";
                header("Location: products.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['general'] = "Failed to add product. DB Error: " . $e->getMessage();
            }
        } elseif ($submitted_action === 'edit' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, description = ?, price = ?, stock = ? WHERE id = ?");
            try {
                $stmt->execute([
                    $name,
                    $category_id,
                    $description !== '' ? $description : null,
                    floatval($price),
                    intval($stock),
                    $id
                ]);
                if ($has_new_photos) {
                    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ?");
                    $count_stmt->execute([$id]);
                    $existing_image_count = (int) $count_stmt->fetchColumn();
                    upload_product_photos($pdo, $id, $_FILES['photos'], $existing_image_count === 0);
                }
                $_SESSION['flash_success'] = "Product updated successfully!";
                header("Location: products.php");
                exit;
            } catch (PDOException $e) {
                $errors['general'] = "Failed to update product. DB Error: " . $e->getMessage();
            }
        }
    }
}

// Handle Delete Operation
if ($action === 'delete' && $id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();

    if ($prod) {
        // Grab image paths before the cascading delete removes the product_images rows
        $img_stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
        $img_stmt->execute([$id]);
        $images_to_remove = $img_stmt->fetchAll(PDO::FETCH_COLUMN);

        $del_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        try {
            $del_stmt->execute([$id]);
            foreach ($images_to_remove as $path) {
                if (!empty($path) && file_exists(__DIR__ . '/../uploads/products/' . $path)) {
                    unlink(__DIR__ . '/../uploads/products/' . $path);
                }
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

// Fetch single product details + images (for Edit Form)
$product_images = [];
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

        $img_stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC");
        $img_stmt->execute([$id]);
        $product_images = $img_stmt->fetchAll();
    } else {
        $_SESSION['flash_error'] = "Product not found.";
        header("Location: products.php");
        exit;
    }
}

// Fetch all products (for List Table) with keyword search
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sql = "SELECT p.*, c.name as category_name,
        (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image
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
                                    $photo_path = '../uploads/products/' . $p['primary_image'];
                                    if (!empty($p['primary_image']) && file_exists(__DIR__ . '/' . $photo_path)):
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
                                    <a href="products.php?action=delete&id=<?= $p['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to delete product '<?= htmlspecialchars($p['name']) ?>'? This will permanently delete the item and all its image files from storage.">Delete</a>
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

            <div class="form-group">
                <label for="field-photos">Product Photos (select one or more)</label>
                <input type="file" name="photos[]" id="field-photos" class="form-control" accept="image/*" multiple>
                <?= html_error($errors, 'photos') ?>
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

            <div class="form-group">
                <label for="field-photos">Add Additional Photos</label>
                <input type="file" name="photos[]" id="field-photos" class="form-control" accept="image/*" multiple>
                <?= html_error($errors, 'photos') ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1.5rem;">Save Changes</button>
            <a href="products.php" class="btn btn-secondary btn-block" style="margin-top: 0.5rem; text-align: center;">Cancel</a>
        </form>

        <h4 style="margin-top: 2rem; margin-bottom: 1rem; font-family: 'Outfit', sans-serif; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">
            Current Photos
        </h4>

        <?php if (empty($product_images)): ?>
            <p style="color: var(--text-muted);">No photos uploaded yet.</p>
        <?php else: ?>
            <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                <?php foreach ($product_images as $img): ?>
                    <div style="text-align: center;">
                        <img src="../uploads/products/<?= htmlspecialchars($img['image_path']) ?>" class="photo-preview" style="border-radius: var(--radius-sm); border-color: <?= $img['is_primary'] ? 'var(--accent)' : 'var(--primary-light)' ?>;" alt="Product photo">
                        <?php if ($img['is_primary']): ?>
                            <div><span class="badge badge-active" style="margin-top: 0.4rem;">Primary</span></div>
                        <?php else: ?>
                            <form method="POST" style="margin-top: 0.4rem;">
                                <input type="hidden" name="action" value="set_primary">
                                <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                <input type="hidden" name="product_id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">Set Primary</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="margin-top: 0.3rem;">
                            <input type="hidden" name="action" value="delete_image">
                            <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Delete this photo?" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
