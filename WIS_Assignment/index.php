<?php
require_once __DIR__ . '/includes/header.php';

// Fetch all categories for the sidebar filter
$cat_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $cat_stmt->fetchAll();

// Handle search and filtering
$cat_id = isset($_GET['cat_id']) ? intval($_GET['cat_id']) : 0;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build the product query
$where = [];
$params = [];

if ($cat_id > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $cat_id;
}

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_sql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

$count_sql = "SELECT COUNT(*) FROM products p LEFT JOIN categories c ON p.category_id = c.id" . $where_sql;
$per_page = 12;
$pg = paginate_query($pdo, $count_sql, $params, $per_page);

$sql = "SELECT p.*, c.name as category_name,
        (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image,
        (SELECT AVG(rating) FROM reviews WHERE product_id = p.id) as avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count,
        EXISTS(SELECT 1 FROM product_option_groups WHERE product_id = p.id AND is_required = 1) as has_required_options
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id"
        . $where_sql
        . " ORDER BY p.id DESC LIMIT " . (int) $pg['per_page'] . " OFFSET " . (int) $pg['offset'];
$prod_stmt = $pdo->prepare($sql);
$prod_stmt->execute($params);
$products = $prod_stmt->fetchAll();

$pager_params = array_filter(['cat_id' => $cat_id > 0 ? $cat_id : null, 'q' => $search !== '' ? $search : null]);
?>

<!-- Hero Banner Section -->
<section class="hero">
    <div class="hero-content">
        <span class="hero-eyebrow">Freshly roasted, every morning</span>
        <h1>Experience Coffee Perfection</h1>
        <div class="hero-actions">
            <a href="#products" class="btn btn-accent">View the Menu</a>
        </div>
    </div>
</section>

<div class="quick-search">
    <div class="search-container">
        <form action="index.php" method="GET" class="search-form">
            <?php if ($cat_id > 0): ?>
                <input type="hidden" name="cat_id" value="<?= $cat_id ?>">
            <?php endif; ?>
            <input type="text" name="q" class="form-control" maxlength="100" placeholder="Search for your favorite brew..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-accent">Search</button>
        </form>
    </div>
</div>

<div class="shop-layout" id="products">
    <!-- Sidebar Filters -->
    <aside class="sidebar">
        <h3>Categories</h3>
        <ul class="filter-list">
            <li>
                <a href="index.php<?= !empty($search) ? '?q=' . urlencode($search) : '' ?>" class="<?= $cat_id === 0 ? 'active' : '' ?>">
                    All Products
                </a>
            </li>
            <?php foreach ($categories as $cat): ?>
                <li>
                    <a href="index.php?cat_id=<?= $cat['id'] ?><?= !empty($search) ? '&q=' . urlencode($search) : '' ?>" class="<?= $cat_id === $cat['id'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <!-- Main Products Listing -->
    <section class="products-section">
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
                            if (!empty($product['primary_image']) && file_exists(__DIR__ . '/' . $photo_path)):
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
                                <span class="star-rating"><span class="stars">★</span> <?= number_format($product['avg_rating'], 1) ?> (<?= $product['review_count'] ?>)</span>
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
    </section>
</div>

<section class="story-band">
    <div class="story-band-content">
        <span class="hero-eyebrow">Behind the counter</span>
        <h2>Brewed to Order, One Cup at a Time</h2>
        <p>Every pour-over is timed and weighed by hand &mdash; no batch brewing, no shortcuts. It's slower, but it's the only way we'll serve it.</p>
    </div>
</section>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
