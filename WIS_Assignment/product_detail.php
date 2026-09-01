<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Validate product ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$product = null;

$gallery_images = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name,
                           (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image
                           FROM products p
                           LEFT JOIN categories c ON p.category_id = c.id
                           WHERE p.id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if ($product) {
        $gallery_stmt = $pdo->prepare("SELECT id, image_path, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC");
        $gallery_stmt->execute([$id]);
        $gallery_images = $gallery_stmt->fetchAll();

        $option_groups_stmt = $pdo->prepare("SELECT id, name, is_required FROM product_option_groups WHERE product_id = ? ORDER BY id ASC");
        $option_groups_stmt->execute([$id]);
        $option_groups = $option_groups_stmt->fetchAll();

        $option_values_stmt = $pdo->prepare("SELECT id, group_id, label, price_delta FROM product_option_values WHERE group_id = ? ORDER BY id ASC");
        foreach ($option_groups as &$group) {
            $option_values_stmt->execute([$group['id']]);
            $group['values'] = $option_values_stmt->fetchAll();
        }
        unset($group);

        // Check if the logged-in user has favorited this product
        $is_favorited = false;
        if (is_logged_in()) {
            $fav_stmt = $pdo->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND product_id = ?");
            $fav_stmt->execute([$_SESSION['user_id'], $id]);
            $is_favorited = (bool) $fav_stmt->fetch();
        }
    }
}

if (!$product || ($product['status'] === 'unavailable' && !is_admin())) {
    // If product is marked as unavailable, block non-admin customers and prevent purchasing
    echo '<div class="alert alert-danger">This product is currently unavailable. <a href="index.php">Return to Menu</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// If arriving from the cart's "Edit" link, pre-fill the form with that cart item's current
// selections instead of starting a fresh "Add to Cart" (only when it's this member's own item).
$edit_cart_id = 0;
$edit_customization = '';
$edit_quantity = 1;
$edit_selected_ids = [];

if (isset($_GET['edit_cart_id']) && is_logged_in()) {
    $candidate_id = intval($_GET['edit_cart_id']);
    $edit_stmt = $pdo->prepare("SELECT id, quantity, customization, option_signature FROM cart WHERE id = ? AND user_id = ? AND product_id = ?");
    $edit_stmt->execute([$candidate_id, $_SESSION['user_id'], $id]);
    $edit_item = $edit_stmt->fetch();

    if ($edit_item) {
        $edit_cart_id = $edit_item['id'];
        $edit_customization = $edit_item['customization'];
        $edit_quantity = $edit_item['quantity'];
        $edit_selected_ids = $edit_item['option_signature'] !== '' ? explode(',', $edit_item['option_signature']) : [];
    }
}

// Rating summary + review list
$rating_stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as review_count FROM reviews WHERE product_id = ?");
$rating_stmt->execute([$id]);
$rating_summary = $rating_stmt->fetch();

$reviews_stmt = $pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
$reviews_stmt->execute([$id]);
$reviews = $reviews_stmt->fetchAll();

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div style="margin-bottom: 2rem;">
    <a href="index.php" class="btn btn-secondary btn-sm">&larr; Back to Menu</a>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<div class="detail-layout">
    <!-- Image section -->
    <div>
        <div class="detail-img-container">
            <?php
            $photo_path = 'uploads/products/' . $product['primary_image'];
            if (!empty($product['primary_image']) && file_exists(__DIR__ . '/' . $photo_path)):
            ?>
                <img src="<?= htmlspecialchars($photo_path) ?>" class="detail-img" alt="<?= htmlspecialchars($product['name']) ?>">
            <?php else: ?>
                <div class="product-img-placeholder" style="position: static; height: 100%; font-size: 5rem;">☕</div>
            <?php endif; ?>
        </div>

        <?php if (count($gallery_images) > 1): ?>
            <div class="gallery-thumb-strip">
                <?php foreach ($gallery_images as $i => $img): ?>
                    <img src="uploads/products/<?= htmlspecialchars($img['image_path']) ?>"
                         class="gallery-thumb<?= $i === 0 ? ' active' : '' ?>"
                         data-full="uploads/products/<?= htmlspecialchars($img['image_path']) ?>"
                         alt="<?= htmlspecialchars($product['name']) ?> photo <?= $i + 1 ?>">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Product Info -->
    <div class="detail-info">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.5rem;">
            <h1 style="margin: 0;"><?= htmlspecialchars($product['name']) ?></h1>
            <?php if (is_logged_in() && !is_admin()): ?>
                <button type="button" class="btn-favorite btn-favorite-detail <?= $is_favorited ? 'is-favorited' : '' ?>" data-product-id="<?= $product['id'] ?>" title="<?= $is_favorited ? 'Remove from favorites' : 'Add to favorites' ?>">
                    <span class="heart-icon"><?= $is_favorited ? '❤️' : '🤍' ?></span> <span class="fav-text" style="font-size: 0.85rem; font-weight: 600;"><?= $is_favorited ? 'Favorited' : 'Favorite' ?></span>
                </button>
            <?php elseif (!is_logged_in()): ?>
                <a href="login.php" class="btn-favorite btn-favorite-detail" title="Login to save favorites">
                    <span class="heart-icon">🤍</span> <span class="fav-text" style="font-size: 0.85rem; font-weight: 600;">Favorite</span>
                </a>
            <?php endif; ?>
        </div>
        <div class="detail-price">RM<?= number_format($product['price'], 2) ?></div>

        <?php if ($rating_summary['review_count'] > 0): ?>
            <div class="star-rating" style="margin-bottom: 1rem;">
                <span class="stars">★</span> <?= number_format($rating_summary['avg_rating'], 1) ?> (<?= $rating_summary['review_count'] ?> review<?= $rating_summary['review_count'] == 1 ? '' : 's' ?>)
            </div>
        <?php endif; ?>

        <p class="detail-desc"><?= htmlspecialchars($product['description']) ?></p>
        
        <!-- Stock Details -->
        <div style="margin-bottom: 1.5rem;">
            <strong>Availability: </strong>
            <?php if ($product['stock'] == 0): ?>
                <span class="stock-badge inline out-of-stock">Temporarily Sold Out</span>
            <?php elseif ($product['stock'] <= 5): ?>
                <span class="stock-badge inline low-stock">Low Stock (Only <?= $product['stock'] ?> left!)</span>
            <?php else: ?>
                <span class="stock-badge inline in-stock">In Stock (<?= $product['stock'] ?> units available)</span>
            <?php endif; ?>
        </div>

        <?php if (!is_admin()): ?>
            <?php if ($edit_cart_id): ?>
                <div class="alert alert-info">Editing an item already in your cart. <a href="cart.php">Cancel</a></div>
            <?php endif; ?>
            <form action="cart.php" method="POST" class="detail-action-form">
                <input type="hidden" name="action" value="<?= $edit_cart_id ? 'edit' : 'add' ?>">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <?php if ($edit_cart_id): ?>
                    <input type="hidden" name="edit_cart_id" value="<?= $edit_cart_id ?>">
                <?php endif; ?>

                <?php if ($product['stock'] > 0): ?>
                    <?php foreach ($option_groups as $group): ?>
                        <?php
                        $value_options = $group['is_required'] ? ['' => '-- Select --'] : ['' => 'None'];
                        $selected_value = '';
                        foreach ($group['values'] as $value) {
                            $option_label = $value['price_delta'] > 0
                                ? $value['label'] . ' (+RM' . number_format($value['price_delta'], 2) . ')'
                                : $value['label'];
                            $value_options[$value['id']] = $option_label;
                            if (in_array((string)$value['id'], $edit_selected_ids, true)) {
                                $selected_value = $value['id'];
                            }
                        }
                        ?>
                        <?= html_select("options[{$group['id']}]", $value_options, $selected_value, $group['name'] . ($group['is_required'] ? ' *' : ''), [], $group['is_required'] ? ['required' => 'required'] : []) ?>
                    <?php endforeach; ?>

                    <?= html_input('text', 'customization', $edit_customization, 'Customization (Optional)', 'e.g. Oat milk, Extra shot, Less ice') ?>

                    <div class="quantity-picker">
                        <label for="field-quantity">Quantity:</label>
                        <input type="number" name="quantity" id="field-quantity" class="form-control" value="<?= $edit_quantity ?>" min="1" max="<?= $product['stock'] ?>" required>
                    </div>

                    <button type="submit" class="btn btn-accent btn-block"><?= $edit_cart_id ? 'Update Cart Item' : 'Add to Shopping Cart' ?></button>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin-top: 1rem; border-left: none; text-align: center;">
                        This item is currently out of stock and cannot be ordered.
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div style="border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <p style="color: var(--text-muted); margin-bottom: 1rem;">You are viewing this page as an Administrator.</p>
                <a href="admin/products.php?action=edit&id=<?= $product['id'] ?>" class="btn btn-primary">Edit Product Details</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reviews Section -->
<div class="admin-panel" style="max-width: 900px; margin: 2.5rem auto 0;">
    <h3>Customer Reviews</h3>

    <?php if (is_member()): ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
            Purchased this item? Rate it from your <a href="orders.php">Order History</a> page once your order is marked Completed.
        </p>
    <?php elseif (!is_admin()): ?>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
            <a href="login.php">Log in</a> as a member to write a review.
        </p>
    <?php endif; ?>

    <?php if (empty($reviews)): ?>
        <p style="color: var(--text-muted);">No reviews yet. Be the first to share your thoughts!</p>
    <?php else: ?>
        <?php foreach ($reviews as $r): ?>
            <div style="padding: 1rem 0; border-bottom: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <strong><?= htmlspecialchars($r['username']) ?></strong>
                    <span class="star-rating"><span class="stars">★</span> <?= $r['rating'] ?>/5</span>
                </div>
                <?php if (!empty($r['comment'])): ?>
                    <p style="margin-top: 0.4rem; color: var(--text-muted); font-size: 0.95rem;"><?= nl2br(htmlspecialchars($r['comment'])) ?></p>
                <?php endif; ?>
                <span style="font-size: 0.78rem; color: var(--text-muted);"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
