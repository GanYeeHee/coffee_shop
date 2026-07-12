<?php
// Initialize from includes folder
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

// Force admin check
require_admin();

// 1. Compute Statistics
// Total Sales (excluding Cancelled)
$sales_stmt = $pdo->query("SELECT SUM(total_price) as total_sales FROM orders WHERE status != 'Cancelled'");
$total_sales = floatval($sales_stmt->fetchColumn() ?? 0);

// Total Orders
$orders_count_stmt = $pdo->query("SELECT COUNT(*) FROM orders");
$total_orders = intval($orders_count_stmt->fetchColumn() ?? 0);

// Total Products
$prod_count_stmt = $pdo->query("SELECT COUNT(*) FROM products");
$total_products = intval($prod_count_stmt->fetchColumn() ?? 0);

// Total Members (role = member)
$member_count_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'");
$total_members = intval($member_count_stmt->fetchColumn() ?? 0);

// 2. Fetch Low Stock Products (stock <= 5)
$low_stock_stmt = $pdo->query("SELECT p.*, c.name as category_name 
                               FROM products p 
                               LEFT JOIN categories c ON p.category_id = c.id 
                               WHERE p.stock <= 5 
                               ORDER BY p.stock ASC");
$low_stock_items = $low_stock_stmt->fetchAll();

// 3. Fetch Recent Orders (last 5 orders)
$recent_orders_stmt = $pdo->query("SELECT o.*, u.username 
                                   FROM orders o 
                                   LEFT JOIN users u ON o.user_id = u.id 
                                   ORDER BY o.order_date DESC 
                                   LIMIT 5");
$recent_orders = $recent_orders_stmt->fetchAll();
?>

<h1 style="margin-bottom: 2rem;">Admin Dashboard</h1>

<div class="admin-dashboard">
    <!-- Stats Cards Grid -->
    <div class="stat-grid">
        <div class="stat-card">
            <span class="title">Total Revenue</span>
            <span class="value" style="color: var(--success);">$<?= number_format($total_sales, 2) ?></span>
        </div>
        <div class="stat-card">
            <span class="title">Total Orders</span>
            <span class="value"><?= $total_orders ?></span>
        </div>
        <div class="stat-card">
            <span class="title">Total Products</span>
            <span class="value"><?= $total_products ?></span>
        </div>
        <div class="stat-card">
            <span class="title">Registered Members</span>
            <span class="value"><?= $total_members ?></span>
        </div>
    </div>
    
    <!-- Split panels for Stock warnings and Recent orders -->
    <div class="admin-columns">
        <!-- Recent Orders Panel -->
        <div class="admin-panel">
            <h3>Recent Orders</h3>
            <?php if (empty($recent_orders)): ?>
                <p style="color: var(--text-muted);">No orders placed yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 0.9rem;">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>User</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $ord): ?>
                                <tr>
                                    <td><strong>#<?= $ord['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($ord['username'] ?? 'Guest/Deleted') ?></td>
                                    <td><?= date('d M Y', strtotime($ord['order_date'])) ?></td>
                                    <td>$<?= number_format($ord['total_price'], 2) ?></td>
                                    <td>
                                        <span class="badge badge-<?= strtolower($ord['status']) ?>">
                                            <?= htmlspecialchars($ord['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="orders.php?detail_id=<?= $ord['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 1rem; text-align: right;">
                    <a href="orders.php" style="font-size: 0.9rem; font-weight: 600;">Manage All Orders &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Inventory Stock Alert Panel -->
        <div class="admin-panel" style="border-color: var(--warning);">
            <h3 style="color: var(--warning); border-bottom-color: var(--warning);">⚠️ Inventory Alerts</h3>
            <?php if (empty($low_stock_items)): ?>
                <div class="alert alert-success" style="box-shadow: none; border-left: none; margin-bottom: 0;">
                    All products are fully stocked.
                </div>
            <?php else: ?>
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                    The following items have less than 5 units left. Please restock immediately:
                </p>
                <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                    <table class="table" style="font-size: 0.85rem; box-shadow: none; border: none;">
                        <tbody>
                            <?php foreach ($low_stock_items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></span>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($item['stock'] == 0): ?>
                                            <span class="badge badge-cancelled" style="font-size: 0.7rem;">Sold Out</span>
                                        <?php else: ?>
                                            <span class="badge badge-pending" style="font-size: 0.7rem;"><?= $item['stock'] ?> Left</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="products.php?action=edit&id=<?= $item['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">Restock</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
