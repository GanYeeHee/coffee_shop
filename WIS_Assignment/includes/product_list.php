<?php
/**
 * Filtered product listing: section heading + product grid + pagination.
 *
 * Shared by index.php (rendered inside <section class="products-section">) and
 * its own AJAX branch (index.php?ajax=products), which echoes this fragment on
 * its own so the category / search / pagination controls can refresh the grid
 * without a full page reload.
 *
 * Expects in scope: $products, $categories, $cat_id, $search, $pg, $pager_params.
 */
?>
<h2 class="section-title">
    <?php
    if ($cat_id > 0) {
        // Find category name
        $cat_name = 'Products';
        foreach ($categories as $c) {
            if ($c['id'] == $cat_id) {
                $cat_name = $c['name'];
                break;
            }
        }
        echo htmlspecialchars($cat_name);
    } else {
        echo 'Our Menu';
    }
    if ($search !== '') {
        echo ' - Search results for "' . htmlspecialchars($search) . '"';
    }
    ?>
</h2>

<?php if (empty($products)): ?>
    <div class="alert alert-info">
        No products found matching your criteria. Try adjusting your search query or filters.
    </div>
<?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $product): ?>
            <article class="product-card">
                <div class="product-img-wrapper">
                    <?php
                    $photo_path = 'uploads/products/' . $product['primary_image'];
                    if (!empty($product['primary_image']) && file_exists(__DIR__ . '/../' . $photo_path)):
                    ?>
                        <img src="<?= htmlspecialchars($photo_path) ?>" class="product-img" alt="<?= htmlspecialchars($product['name']) ?>">
                    <?php else: ?>
                        <div class="product-img-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M6 10h11v5a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-5z"/><path d="M17 11.5h1.2a2.3 2.3 0 0 1 0 4.6H17"/></svg>
                        </div>
                    <?php endif; ?>

                    <!-- Stock Indicators -->
                    <?php if ($product['stock'] == 0): ?>
                        <span class="stock-badge out-of-stock">Sold Out</span>
                    <?php elseif ($product['stock'] <= 5): ?>
                        <span class="stock-badge low-stock">Low Stock (<?= $product['stock'] ?> left)</span>
                    <?php else: ?>
                        <span class="stock-badge in-stock">In Stock</span>
                    <?php endif; ?>
                </div>

                <div class="product-info">
                    <span class="product-cat"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
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

                        <?php if (is_admin()): ?>
                            <a href="admin/products.php?action=edit&id=<?= $product['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <?php else: ?>
                            <?php if ($product['stock'] > 0 && $product['has_required_options']): ?>
                                <a href="product_detail.php?id=<?= $product['id'] ?>" class="btn btn-accent btn-sm">Select Options</a>
                            <?php elseif ($product['stock'] > 0): ?>
                                <a href="product_detail.php?id=<?= $product['id'] ?>" class="btn btn-accent btn-sm">Add to Cart</a>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>Unavailable</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?= render_pagination($pg, 'index.php', $pager_params) ?>
<?php endif; ?>
