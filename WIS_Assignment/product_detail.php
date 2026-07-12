<?php
require_once __DIR__ . '/includes/header.php';

// Validate product ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$product = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name 
                           FROM products p 
                           LEFT JOIN categories c ON p.category_id = c.id 
                           WHERE p.id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
}

if (!$product) {
    echo '<div class="alert alert-danger">Product not found. <a href="index.php">Return to Menu</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div style="margin-bottom: 2rem;">
    <a href="index.php" class="btn btn-secondary btn-sm">&larr; Back to Menu</a>
</div>

<div class="detail-layout">
    <!-- Image section -->
    <div class="detail-img-container">
        <?php 
        $photo_path = 'uploads/products/' . $product['photo'];
        if (!empty($product['photo']) && file_exists(__DIR__ . '/' . $photo_path)): 
        ?>
            <img src="<?= htmlspecialchars($photo_path) ?>" class="detail-img" alt="<?= htmlspecialchars($product['name']) ?>">
        <?php else: ?>
            <div class="product-img-placeholder" style="position: static; height: 100%; font-size: 5rem;">☕</div>
        <?php endif; ?>
    </div>

    <!-- Product Info -->
    <div class="detail-info">
        <span class="product-cat"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
        <h1><?= htmlspecialchars($product['name']) ?></h1>
        <div class="detail-price">$<?= number_format($product['price'], 2) ?></div>
        
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

<?php
require_once __DIR__ . '/includes/footer.php';
?>
