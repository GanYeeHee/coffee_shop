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
    }
}

if (!$product) {
    echo '<div class="alert alert-danger">Product not found. <a href="index.php">Return to Menu</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Handle review submission (one review per member per product; resubmitting updates it)
$review_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    $_POST = sanitize_input($_POST);

    if (!is_member()) {
        $review_errors['general'] = "Please log in as a member to submit a review.";
    } else {
        $rating = intval($_POST['rating'] ?? 0);
        $comment = $_POST['comment'] ?? '';

        if ($rating < 1 || $rating > 5) {
            $review_errors['rating'] = "Please select a rating between 1 and 5.";
        }

        if (empty($review_errors)) {
            $existing_stmt = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ?");
            $existing_stmt->execute([$_SESSION['user_id'], $id]);
            $existing_review_id = $existing_stmt->fetchColumn();

            if ($existing_review_id) {
                $pdo->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ?")
                    ->execute([$rating, $comment !== '' ? $comment : null, $existing_review_id]);
                $_SESSION['flash_success'] = "Your review has been updated!";
            } else {
                $pdo->prepare("INSERT INTO reviews (user_id, product_id, rating, comment) VALUES (?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], $id, $rating, $comment !== '' ? $comment : null]);
                $_SESSION['flash_success'] = "Thank you for your review!";
            }
            header("Location: product_detail.php?id=" . $id);
            exit;
        }
    }
}

// Rating summary + review list
$rating_stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as review_count FROM reviews WHERE product_id = ?");
$rating_stmt->execute([$id]);
$rating_summary = $rating_stmt->fetch();

$reviews_stmt = $pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
$reviews_stmt->execute([$id]);
$reviews = $reviews_stmt->fetchAll();

$my_review = null;
if (is_member()) {
    $my_stmt = $pdo->prepare("SELECT * FROM reviews WHERE user_id = ? AND product_id = ?");
    $my_stmt->execute([$_SESSION['user_id'], $id]);
    $my_review = $my_stmt->fetch();
}

$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>

<div style="margin-bottom: 2rem;">
    <a href="index.php" class="btn btn-secondary btn-sm">&larr; Back to Menu</a>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
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
        <span class="product-cat"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
        <h1><?= htmlspecialchars($product['name']) ?></h1>
        <div class="detail-price">$<?= number_format($product['price'], 2) ?></div>

        <?php if ($rating_summary['review_count'] > 0): ?>
            <div style="margin-bottom: 1rem; color: var(--text-muted); font-size: 0.95rem;">
                ★ <?= number_format($rating_summary['avg_rating'], 1) ?> (<?= $rating_summary['review_count'] ?> review<?= $rating_summary['review_count'] == 1 ? '' : 's' ?>)
            </div>
        <?php endif; ?>

        <p class="detail-desc"><?= htmlspecialchars($product['description']) ?></p>
        
        <!-- Stock Details -->
        <div style="margin-bottom: 1.5rem;">
            <strong>Availability: </strong>
            <?php if ($product['stock'] == 0): ?>
                <span class="badge badge-cancelled">Temporarily Sold Out</span>
            <?php elseif ($product['stock'] <= 5): ?>
                <span class="badge badge-pending">Low Stock (Only <?= $product['stock'] ?> left!)</span>
            <?php else: ?>
                <span class="badge badge-active">In Stock (<?= $product['stock'] ?> units available)</span>
            <?php endif; ?>
        </div>

        <?php if (!is_admin()): ?>
            <form action="cart.php" method="POST" class="detail-action-form">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                
                <?php if ($product['stock'] > 0): ?>
                    <div class="form-group">
                        <label for="field-customization">Customization (Optional)</label>
                        <input type="text" name="customization" id="field-customization" class="form-control" placeholder="e.g. Oat milk, Extra shot, Less ice">
                    </div>

                    <div class="quantity-picker">
                        <label for="quantity">Quantity:</label>
                        <input type="number" name="quantity" id="field-quantity" class="form-control" value="1" min="1" max="<?= $product['stock'] ?>" required>
                    </div>

                    <button type="submit" class="btn btn-accent btn-block">Add to Shopping Cart</button>
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
        <form action="product_detail.php?id=<?= $id ?>" method="POST" novalidate style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
            <input type="hidden" name="action" value="submit_review">

            <?php if (isset($review_errors['general'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($review_errors['general']) ?></div>
            <?php endif; ?>

            <?= html_select('rating', [1 => '1 - Poor', 2 => '2 - Fair', 3 => '3 - Good', 4 => '4 - Very Good', 5 => '5 - Excellent'], $my_review['rating'] ?? 5, 'Your Rating', $review_errors) ?>
            <?= html_textarea('comment', $my_review['comment'] ?? '', 'Your Review (Optional)', 'Share your thoughts about this product...', $review_errors) ?>

            <button type="submit" class="btn btn-accent btn-sm"><?= $my_review ? 'Update My Review' : 'Submit Review' ?></button>
        </form>
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
                    <span style="color: var(--accent); font-weight: 600;">★ <?= $r['rating'] ?>/5</span>
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
