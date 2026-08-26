<?php
// An ?ajax=products request returns just the product-list fragment (no page
// layout) so the category filter, search, and pagination can refresh the grid
// in place. The plain links and GET form below still work with JavaScript off.
$shop_ajax = isset($_GET['ajax']) && $_GET['ajax'] === 'products';

if ($shop_ajax) {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/html_helpers.php';
} else {
    require_once __DIR__ . '/includes/header.php';
}

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

// AJAX request: emit only the product-list fragment and stop before the layout.
if ($shop_ajax) {
    require __DIR__ . '/includes/product_list.php';
    exit;
}
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

    <!-- Main Products Listing (refreshed in place via assets/js/app.js) -->
    <section class="products-section">
        <?php require __DIR__ . '/includes/product_list.php'; ?>
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
