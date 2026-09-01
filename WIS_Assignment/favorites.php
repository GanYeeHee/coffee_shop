<?php
// My Favorites Page for members
require_once __DIR__ . '/includes/header.php';
require_login();

$user_id = $_SESSION['user_id'];

// Fetch all available products favorited by the logged-in user
$card_cols = "p.*, c.name as category_name,
    (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image,
    (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating,
    (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
    EXISTS(SELECT 1 FROM product_option_groups WHERE product_id = p.id AND is_required = 1) as has_required_options";

$sql = "SELECT $card_cols 
        FROM user_favorites f
        JOIN products p ON f.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE f.user_id = ? AND p.status = 'available'
        ORDER BY f.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();

$user_favorites = array_column($products, 'id');
?>

<div class="container" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 2rem;">
        <div>
            <h1 style="margin: 0; font-size: 1.8rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>My Favorites</span> <span style="color: #e53935;">❤️</span>
            </h1>
            <p style="color: var(--text-muted); margin-top: 0.4rem; margin-bottom: 0;">
                All your saved coffee drinks and beans in one place.
            </p>
        </div>
        <a href="index.php" class="btn btn-secondary btn-sm">&larr; Back to Menu</a>
    </div>

    <?php if (empty($products)): ?>
        <div class="alert alert-info" style="text-align: center; padding: 3rem 1rem;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">☕🤍</div>
            <h3>No favorites saved yet!</h3>
            <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
                Browse our coffee menu and click the heart icon (🤍) on any drink to save your favorites here.
            </p>
            <a href="index.php" class="btn btn-accent">Explore Full Menu</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
                <article class="product-card" id="product-card-<?= $product['id'] ?>">
                    <div class="product-img-wrapper">
                        <?php
                        $photo_path = 'uploads/products/' . $product['primary_image'];
                        if (!empty($product['primary_image']) && file_exists(__DIR__ . '/' . $photo_path)):
                        ?>
                            <img src="<?= htmlspecialchars($photo_path) ?>" class="product-img" alt="<?= htmlspecialchars($product['name']) ?>">
                        <?php else: ?>
                            <div class="product-img-placeholder">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M6 10h11v5a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-5z"/><path d="M17 11.5h1.2a2.3 2.3 0 0 1 0 4.6H17"/></svg>
                            </div>
                        <?php endif; ?>

                        <!-- Favorite Heart Button -->
                        <button type="button" class="btn-favorite is-favorited" data-product-id="<?= $product['id'] ?>" title="Remove from favorites">
                            <span class="heart-icon">❤️</span>
                        </button>

                        <!-- Stock Indicators -->
                        <?php if ($product['stock'] == 0): ?>
                            <span class="stock-badge out-of-stock">Sold Out</span>
                        <?php elseif ($product['stock'] <= 5): ?>
                            <span class="stock-badge low-stock">Only <?= $product['stock'] ?> Left</span>
                        <?php endif; ?>
                    </div>

                    <div class="product-info">
                        <span class="product-category"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
                        <h3 class="product-name">
                            <a href="product_detail.php?id=<?= $product['id'] ?>">
                                <?= htmlspecialchars($product['name']) ?>
                            </a>
                        </h3>
                        <?php if ($product['review_count'] > 0): ?>
                            <span class="star-rating"><span class="stars">&#9733;</span> <?= number_format($product['avg_rating'], 1) ?> (<?= $product['review_count'] ?>)</span>
                        <?php endif; ?>
                        <div class="product-spacer"></div>

                        <div class="product-price-action">
                            <span class="product-price">RM<?= number_format($product['price'], 2) ?></span>

                            <?php if ($product['stock'] > 0 && $product['has_required_options']): ?>
                                <a href="product_detail.php?id=<?= $product['id'] ?>" class="btn btn-accent btn-sm">Select Options</a>
                            <?php elseif ($product['stock'] > 0): ?>
                                <a href="product_detail.php?id=<?= $product['id'] ?>" class="btn btn-accent btn-sm">Add to Cart</a>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
