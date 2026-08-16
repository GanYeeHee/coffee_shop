<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

require_login();
if (is_admin()) {
    echo '<div class="alert alert-danger">Administrators manage orders from the Admin Dashboard.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle order cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $_POST = sanitize_input($_POST);
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    // Fetch order to verify it belongs to user and is Pending
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch();
    
    if ($order) {
        if ($order['status'] === 'Pending') {
            try {
                $pdo->beginTransaction();
                
                // 1. Update order status to 'Cancelled'
                $upd_stmt = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ?");
                $upd_stmt->execute([$order_id]);
                
                // 2. Return items to product stock
                // Get order items
                $items_stmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $items_stmt->execute([$order_id]);
                $items = $items_stmt->fetchAll();
                
                $stock_stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($items as $item) {
                    if (!empty($item['product_id'])) {
                        $stock_stmt->execute([$item['quantity'], $item['product_id']]);
                    }
                }
                
                $pdo->commit();
                $_SESSION['flash_success'] = "Order #{$order_id} has been cancelled successfully and stock has been restored.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Failed to cancel order: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = "Only Pending orders can be cancelled. This order is currently '{$order['status']}'.";
        }
    } else {
        $_SESSION['flash_error'] = "Order not found.";
    }
    
    header("Location: orders.php");
    exit;
}

// Handle review submission (only for items in a Completed order that belongs to this user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    $_POST = sanitize_input($_POST);
    $review_order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $review_product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $comment = $_POST['comment'] ?? '';

    // Verify the order belongs to this user and is Completed
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'Completed'");
    $stmt->execute([$review_order_id, $user_id]);
    $owns_completed_order = $stmt->fetch();

    // Verify the product was actually part of that order
    $item_stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND product_id = ?");
    $item_stmt->execute([$review_order_id, $review_product_id]);
    $product_in_order = $item_stmt->fetch();

    if ($owns_completed_order && $product_in_order && $rating >= 1 && $rating <= 5) {
        $existing_stmt = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ?");
        $existing_stmt->execute([$user_id, $review_product_id]);
        $existing_review_id = $existing_stmt->fetchColumn();

        if ($existing_review_id) {
            $pdo->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ?")
                ->execute([$rating, $comment !== '' ? $comment : null, $existing_review_id]);
        } else {
            $pdo->prepare("INSERT INTO reviews (user_id, product_id, rating, comment) VALUES (?, ?, ?, ?)")
                ->execute([$user_id, $review_product_id, $rating, $comment !== '' ? $comment : null]);
        }
        $_SESSION['flash_success'] = "Thank you for your review!";
    } else {
        $_SESSION['flash_error'] = "Unable to submit review for this item.";
    }

    header("Location: orders.php?detail_id=" . $review_order_id);
    exit;
}

// Fetch all orders for this user
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// Handle flash session messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

// Handle specific order detail expansion
$expand_order_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$expand_order = null;
$expand_items = [];

if ($expand_order_id > 0) {
    // Verify order belongs to member
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$expand_order_id, $user_id]);
    $expand_order = $stmt->fetch();
    
    if ($expand_order) {
        $stmt = $pdo->prepare("SELECT oi.quantity, oi.price as checkout_price, oi.customization, p.name, p.id as product_id, cat.name as category_name,
                               (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image
                               FROM order_items oi
                               LEFT JOIN products p ON oi.product_id = p.id
                               LEFT JOIN categories cat ON p.category_id = cat.id
                               WHERE oi.order_id = ?");
        $stmt->execute([$expand_order_id]);
        $expand_items = $stmt->fetchAll();

        $pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $pay_stmt->execute([$expand_order_id]);
        $expand_payment = $pay_stmt->fetch();
    }
}

// Load this user's existing reviews for the items in the expanded order (Completed orders only)
$my_reviews = [];
if ($expand_order && $expand_order['status'] === 'Completed' && !empty($expand_items)) {
    $product_ids = array_unique(array_filter(array_column($expand_items, 'product_id')));
    if ($product_ids) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $rev_stmt = $pdo->prepare("SELECT product_id, rating, comment FROM reviews WHERE user_id = ? AND product_id IN ($placeholders)");
        $rev_stmt->execute([$user_id, ...$product_ids]);
        foreach ($rev_stmt->fetchAll() as $r) {
            $my_reviews[$r['product_id']] = $r;
        }
    }
}
?>

<h1 style="margin-bottom: 2rem;">Order History</h1>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if (empty($orders)): ?>
    <div class="alert alert-warning" style="text-align: center; padding: 3rem;">
        <p style="font-size: 1.1rem; margin-bottom: 1rem;">You have not placed any orders yet.</p>
        <a href="index.php" class="btn btn-accent">Order Your First Coffee</a>
    </div>
<?php else: ?>
    
    <!-- Split Layout if Details are Open -->
    <div style="display: grid; grid-template-columns: <?= ($expand_order) ? '1.1fr 1fr' : '1fr' ?>; gap: 2.5rem; align-items: start;">
        
        <!-- Orders List Table -->
        <section class="admin-panel">
            <h3>My Orders List</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $ord): ?>
                            <tr class="<?= ($expand_order_id === $ord['id']) ? 'active-row' : '' ?>" style="<?= ($expand_order_id === $ord['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td><strong>#<?= $ord['id'] ?></strong></td>
                                <td><?= date('d M Y, h:i A', strtotime($ord['order_date'])) ?></td>
                                <td style="font-weight: 600; color: var(--primary-dark);">RM<?= number_format($ord['total_price'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($ord['status']) ?>">
                                        <?= htmlspecialchars($ord['status']) ?>
                                    </span>
                                </td>
                                <td style="display: flex; gap: 0.5rem;">
                                    <a href="orders.php?detail_id=<?= $ord['id'] ?>" class="btn btn-secondary btn-sm">Receipt</a>
                                    
                                    <?php if ($ord['status'] === 'Pending'): ?>
                                        <form action="orders.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Are you sure you want to cancel order #<?= $ord['id'] ?>? This will refund items back into stock immediately.">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        
        <!-- Expanded Order Receipt details -->
        <?php if ($expand_order): ?>
            <section class="admin-panel dialog-container" style="margin-top: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem; margin-bottom: 1.5rem;">
                    <h3 style="border-bottom: none; margin: 0;">Order Receipt #<?= $expand_order['id'] ?></h3>
                    <a href="orders.php" style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">&times;</a>
                </div>
                
                <div style="font-size: 0.95rem; display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1.5rem;">
                    <div><strong>Order Status:</strong> <span class="badge badge-<?= strtolower($expand_order['status']) ?>"><?= htmlspecialchars($expand_order['status']) ?></span></div>
                    <div><strong>Date Ordered:</strong> <?= date('d M Y, h:i A', strtotime($expand_order['order_date'])) ?></div>
                    <div><strong>Customer:</strong> <?= htmlspecialchars($expand_order['customer_name']) ?> (<?= htmlspecialchars($expand_order['customer_phone']) ?>)</div>
                    <div><strong>Payment Method:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $expand_payment['payment_method'] ?? 'N/A'))) ?></div>
                    <div><strong>Payment Reference:</strong> <?= htmlspecialchars($expand_payment['transaction_id'] ?? 'Pay on pickup/delivery') ?></div>
                    <div><strong>Payment Status:</strong> <span class="badge badge-<?= strtolower($expand_payment['payment_status'] ?? '') ?>"><?= htmlspecialchars(ucfirst($expand_payment['payment_status'] ?? 'N/A')) ?></span></div>
                    <?php if ($expand_order['fulfillment_type'] === 'delivery'): ?>
                        <div><strong>Deliver To:</strong><br><span style="color: var(--text-muted); display: block; padding-left: 0.5rem; border-left: 2px solid var(--border-color); margin-top: 0.2rem;"><?= nl2br(htmlspecialchars($expand_order['shipping_address'])) ?></span></div>
                    <?php else: ?>
                        <div><strong>Fulfillment:</strong> Pickup at Store</div>
                    <?php endif; ?>
                    <?php if ($expand_order['discount_amount'] > 0): ?>
                        <div><strong>Voucher Discount:</strong> -RM<?= number_format($expand_order['discount_amount'], 2) ?></div>
                    <?php endif; ?>
                </div>
                
                <table class="table" style="font-size: 0.9rem; margin-bottom: 1.5rem;">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expand_items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($item['name'] ?? 'Removed Product') ?></strong><br>
                                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($item['category_name'] ?? '') ?></span>
                                    <?php if (!empty($item['customization'])): ?>
                                        <br><span style="font-size: 0.75rem; color: var(--text-muted);">Note: <?= htmlspecialchars($item['customization']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($expand_order['status'] === 'Completed' && !empty($item['product_id'])): ?>
                                        <?php $existing = $my_reviews[$item['product_id']] ?? null; ?>
                                        <details style="margin-top: 0.5rem;">
                                            <summary style="cursor: pointer; color: var(--accent); font-size: 0.8rem;">
                                                <?= $existing ? "★ {$existing['rating']}/5 &mdash; Update your review" : 'Rate &amp; review this item' ?>
                                            </summary>
                                            <form action="orders.php?detail_id=<?= $expand_order['id'] ?>" method="POST" style="margin-top: 0.6rem; min-width: 220px;">
                                                <input type="hidden" name="action" value="submit_review">
                                                <input type="hidden" name="order_id" value="<?= $expand_order['id'] ?>">
                                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                                <?= html_select('rating', [1 => '1 - Poor', 2 => '2 - Fair', 3 => '3 - Good', 4 => '4 - Very Good', 5 => '5 - Excellent'], $existing['rating'] ?? 5, 'Your Rating') ?>
                                                <?= html_textarea('comment', $existing['comment'] ?? '', 'Your Review (Optional)', 'Share your thoughts about this product...') ?>
                                                <button type="submit" class="btn btn-accent btn-sm"><?= $existing ? 'Update Review' : 'Submit Review' ?></button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?= $item['quantity'] ?></td>
                                <td>RM<?= number_format($item['checkout_price'], 2) ?></td>
                                <td>RM<?= number_format($item['checkout_price'] * $item['quantity'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background-color: var(--bg-cream); font-weight: 700; font-size: 1rem;">
                            <td colspan="3" style="text-align: right; border-top: 2px solid var(--border-color);">Total:</td>
                            <td style="border-top: 2px solid var(--border-color); color: var(--accent);">RM<?= number_format($expand_order['total_price'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="text-align: center;">
                    <a href="orders.php" class="btn btn-secondary btn-sm" style="width: 100%;">Close Details</a>
                </div>
            </section>
        <?php endif; ?>
        
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
