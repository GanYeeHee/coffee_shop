<?php
// AJAX batch operations: return JSON and stop before any layout is rendered.
// Mirrors the pre-header AJAX branches in cart.php / checkout.php.
$batch_actions = ['batch_price', 'batch_delete'];
if (isset($_POST['action']) && in_array($_POST['action'], $batch_actions, true)) {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/validation.php';
    header('Content-Type: application/json');

    if (!is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Not authorized.', 'redirect' => 'login.php']);
        exit;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    if (!$ids) {
        echo json_encode(['success' => false, 'message' => 'No products selected.']);
        exit;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));

    if ($_POST['action'] === 'batch_delete') {
        try {
            // Batch action: Mark selected products as unavailable without deleting records or photos to preserve order history
            $pdo->prepare("UPDATE products SET status = 'unavailable' WHERE id IN ($ph)")->execute($ids);

            echo json_encode([
                'success' => true,
                'deleted' => $ids,
                'message' => count($ids) . ' product(s) marked as unavailable.'
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // batch_price: adjust the price of every selected product with one UPDATE.
    $mode = $_POST['mode'] ?? '';   // increase | decrease | set
    $kind = $_POST['kind'] ?? '';   // percent | amount
    $value = $_POST['value'] ?? '';

    if (!is_numeric($value) || $value + 0 <= 0) {
        echo json_encode(['success' => false, 'message' => 'Enter a positive number.']);
        exit;
    }
    $v = round($value + 0, 2);

    switch ("$mode:$kind") {
        case 'increase:percent': $expr = "ROUND(price * (1 + ? / 100), 2)"; break;
        case 'decrease:percent': $expr = "GREATEST(0.01, ROUND(price * (1 - ? / 100), 2))"; break;
        case 'increase:amount':  $expr = "ROUND(price + ?, 2)"; break;
        case 'decrease:amount':  $expr = "GREATEST(0.01, ROUND(price - ?, 2))"; break;
        case 'set:amount':
        case 'set:percent':      $expr = "?"; break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid update option.']);
            exit;
    }

    try {
        $pdo->prepare("UPDATE products SET price = $expr WHERE id IN ($ph)")
            ->execute(array_merge([$v], $ids));

        $new_stmt = $pdo->prepare("SELECT id, price FROM products WHERE id IN ($ph)");
        $new_stmt->execute($ids);
        echo json_encode([
            'success' => true,
            'prices'  => $new_stmt->fetchAll(PDO::FETCH_KEY_PAIR),
            'message' => count($ids) . ' product(s) repriced.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit;
}

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

// Handle Delete (Make Unavailable) Operation
if ($action === 'delete' && $id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();

    if ($prod) {
        // Soft delete: Mark product as unavailable instead of deleting from database to keep past receipts intact
        $del_stmt = $pdo->prepare("UPDATE products SET status = 'unavailable' WHERE id = ?");
        try {
            $del_stmt->execute([$id]);
            $_SESSION['flash_success'] = "Product '{$prod['name']}' has been marked as unavailable.";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to update product: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "Product not found.";
    }
    header("Location: products.php");
    exit;
}

// Re-activate product: Restore status from unavailable back to available
if ($action === 'restore' && $id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();

    if ($prod) {
        $upd_stmt = $pdo->prepare("UPDATE products SET status = 'available' WHERE id = ?");
        try {
            $upd_stmt->execute([$id]);
            $_SESSION['flash_success'] = "Product '{$prod['name']}' is now available.";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to update product: " . $e->getMessage();
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

// Fetch all products (for List Table) with keyword search + filters
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = "SELECT p.*, c.name as category_name,
        (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id";
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($filter_category > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $filter_category;
}

if ($filter_status === 'in_stock') {
    $where[] = "p.stock > 5 AND p.status = 'available'";
} elseif ($filter_status === 'low_stock') {
    $where[] = "p.stock > 0 AND p.stock <= 5 AND p.status = 'available'";
} elseif ($filter_status === 'sold_out') {
    $where[] = "p.stock = 0 AND p.status = 'available'";
} elseif ($filter_status === 'unavailable') {
    // Filter for unavailable products
    $where[] = "p.status = 'unavailable'";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$count_sql = "SELECT COUNT(*) FROM products p LEFT JOIN categories c ON p.category_id = c.id";
if (!empty($where)) {
    $count_sql .= " WHERE " . implode(" AND ", $where);
}
$per_page = 20;
$pg = paginate_query($pdo, $count_sql, $params, $per_page);

$sql .= " ORDER BY p.id DESC LIMIT " . (int) $pg['per_page'] . " OFFSET " . (int) $pg['offset'];
$prod_stmt = $pdo->prepare($sql);
$prod_stmt->execute($params);
$all_products = $prod_stmt->fetchAll();
$has_active_filters = ($search !== '' || $filter_category > 0 || $filter_status !== '');
$pager_params = array_filter([
    'q' => $search !== '' ? $search : null,
    'category' => $filter_category > 0 ? $filter_category : null,
    'status' => $filter_status !== '' ? $filter_status : null,
]);

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

            <form action="products.php" method="GET" class="filter-bar">
                <input type="text" name="q" class="form-control" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">

                <select name="category" class="form-control">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="in_stock" <?= $filter_status === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $filter_status === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="sold_out" <?= $filter_status === 'sold_out' ? 'selected' : '' ?>>Sold Out</option>
                    <!-- Option to filter unavailable products -->
                    <option value="unavailable" <?= $filter_status === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                </select>

                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                <?php if ($has_active_filters): ?>
                    <a href="products.php" class="btn btn-sm" style="color: var(--text-muted); text-decoration: underline;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($all_products)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No products found.</p>
        <?php else: ?>
            <form id="bulk-form" action="products.php" method="POST">
                <!-- Batch-action toolbar: hidden until at least one row is checked (assets/js/app.js #15) -->
                <div class="bulk-bar is-hidden" id="bulk-bar">
                    <span class="badge" id="bulk-count">0 selected</span>

                    <select id="bulk-mode" class="form-control">
                        <option value="increase">Increase price</option>
                        <option value="decrease">Decrease price</option>
                        <option value="set">Set price to</option>
                    </select>
                    <select id="bulk-kind" class="form-control">
                        <option value="percent">by %</option>
                        <option value="amount">by RM</option>
                    </select>
                    <input type="number" id="bulk-value" class="form-control" min="0" step="0.01" placeholder="0.00">
                    <button type="button" id="bulk-price-apply" class="btn btn-sm btn-accent">Apply</button>

                    <button type="button" id="bulk-delete" class="btn btn-sm btn-danger">Delete selected</button>
                </div>
                <div id="bulk-msg"></div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" id="bulk-select-all" title="Select all"></th>
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
                                <tr data-product-id="<?= $p['id'] ?>">
                                    <td class="col-check"><input type="checkbox" class="bulk-cb" name="ids[]" value="<?= $p['id'] ?>"></td>
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
                                    <td class="product-price-cell" style="font-weight: 500; color: var(--accent);">RM<?= number_format($p['price'], 2) ?></td>
                                    <td><?= $p['stock'] ?></td>
                                    <td>
                                        <?php if ($p['status'] === 'unavailable'): ?>
                                            <!-- Display Unavailable badge if product is unavailable -->
                                            <span class="stock-badge inline out-of-stock">Unavailable</span>
                                        <?php elseif ($p['stock'] == 0): ?>
                                            <span class="stock-badge inline out-of-stock">Sold Out</span>
                                        <?php elseif ($p['stock'] <= 5): ?>
                                            <span class="stock-badge inline low-stock">Low Stock</span>
                                        <?php else: ?>
                                            <span class="stock-badge inline in-stock">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="products.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                        <a href="product_options.php?product_id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">Options</a>
                                        <?php if ($p['status'] === 'unavailable'): ?>
                                            <!-- Provide Make Available button for unavailable products -->
                                            <a href="products.php?action=restore&id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Make Available</a>
                                        <?php else: ?>
                                            <!-- Prompt to mark product as unavailable rather than deleting permanently -->
                                            <a href="products.php?action=delete&id=<?= $p['id'] ?>" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to make product '<?= htmlspecialchars($p['name']) ?>' unavailable?">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?= render_pagination($pg, 'products.php', $pager_params) ?>
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
                <?= html_input('text', 'price', $price, 'Price (RM)', 'e.g., 4.50', $errors) ?>
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
                <?= html_input('text', 'price', $price, 'Price (RM)', 'e.g., 4.50', $errors) ?>
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
                                <button type="submit" class="btn btn-secondary btn-sm btn-xs">Set Primary</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="margin-top: 0.3rem;">
                            <input type="hidden" name="action" value="delete_image">
                            <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-danger btn-sm btn-xs confirm-action" data-confirm-message="Delete this photo?">Delete</button>
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
