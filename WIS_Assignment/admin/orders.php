<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/validation.php';

require_admin();

$errors = [];
$filter_status = $_GET['status'] ?? 'All';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Reusable query string that preserves the active filters across action links/forms
$base_params = array_filter([
    'status' => $filter_status !== 'All' ? $filter_status : null,
    'q' => $search !== '' ? $search : null,
    'date_from' => $date_from !== '' ? $date_from : null,
    'date_to' => $date_to !== '' ? $date_to : null,
]);
$base_qs = http_build_query($base_params);
$has_active_filters = !empty($base_params);

// Handle Order Status Transitions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'];
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    // Fetch order details
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if ($order) {
        if ($action === 'mark_processing' && $order['status'] === 'Pending') {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'Processing' WHERE id = ?");
            $stmt->execute([$order_id]);
            $_SESSION['flash_success'] = "Order #{$order_id} marked as Processing.";
        } elseif ($action === 'mark_completed' && $order['status'] === 'Processing') {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'Completed' WHERE id = ?");
            $stmt->execute([$order_id]);
            $_SESSION['flash_success'] = "Order #{$order_id} marked as Completed.";
        } elseif ($action === 'cancel_order' && $order['status'] === 'Pending') {
            try {
                $pdo->beginTransaction();
                
                // 1. Update status
                $stmt = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ?");
                $stmt->execute([$order_id]);
                
                // 2. Refund inventory
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
                $_SESSION['flash_success'] = "Order #{$order_id} cancelled. Stock levels restored.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Failed to cancel order: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = "Invalid status transition.";
        }
    } else {
        $_SESSION['flash_error'] = "Order not found.";
    }
    
    header("Location: orders.php?" . $base_qs);
    exit;
}

// Build list query
$sql = "SELECT o.*, u.username
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id";
$params = [];
$where = [];

if ($filter_status !== 'All') {
    $where[] = "o.status = ?";
    $params[] = $filter_status;
}

if ($search !== '') {
    $where[] = "(CAST(o.id AS CHAR) LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from !== '') {
    $where[] = "DATE(o.order_date) >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[] = "DATE(o.order_date) <= ?";
    $params[] = $date_to;
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$count_sql = "SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id = u.id";
if (!empty($where)) {
    $count_sql .= " WHERE " . implode(" AND ", $where);
}
$per_page = 20;
$pg = paginate_query($pdo, $count_sql, $params, $per_page);

$sql .= " ORDER BY o.order_date DESC LIMIT " . (int) $pg['per_page'] . " OFFSET " . (int) $pg['offset'];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Handle Expand Details View
$expand_order_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$expand_order = null;
$expand_items = [];

if ($expand_order_id > 0) {
    $stmt = $pdo->prepare("SELECT o.*, u.username, u.email as user_email, u.phone as user_phone 
                           FROM orders o 
                           LEFT JOIN users u ON o.user_id = u.id 
                           WHERE o.id = ?");
    $stmt->execute([$expand_order_id]);
    $expand_order = $stmt->fetch();
    
    if ($expand_order) {
        $stmt = $pdo->prepare("SELECT oi.quantity, oi.price as checkout_price, oi.customization, oi.options_summary, p.name, p.id as product_id, cat.name as category_name,
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

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header-actions">
    <h1>Manage Orders</h1>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Search & Date Filter -->
<form action="orders.php" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
    <?php if ($filter_status !== 'All'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
    <?php endif; ?>
    <input type="text" name="q" class="form-control" placeholder="Search by Order ID or member email..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.5rem; width: 240px;">
    <label style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
        From <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="padding: 0.4rem;">
    </label>
    <label style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
        To <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="padding: 0.4rem;">
    </label>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($has_active_filters): ?>
        <a href="orders.php" class="btn btn-sm" style="color: var(--text-muted);">Clear</a>
    <?php endif; ?>
</form>

<!-- Status Filtering Navigation -->
<div style="margin-bottom: 2rem;">
    <ul class="filter-list" style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <?php foreach (['All', 'Pending', 'Processing', 'Completed', 'Cancelled'] as $status_opt): ?>
            <?php
                $tab_params = $base_params;
                if ($status_opt !== 'All') {
                    $tab_params['status'] = $status_opt;
                } else {
                    unset($tab_params['status']);
                }
                $tab_qs = http_build_query($tab_params);
            ?>
            <li>
                <a href="orders.php<?= $tab_qs !== '' ? '?' . $tab_qs : '' ?>" class="btn btn-secondary btn-sm status-tab <?= ($filter_status === $status_opt) ? 'btn-accent' : '' ?>">
                    <?= $status_opt ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="list-detail-columns" style="grid-template-columns: <?= ($expand_order) ? '1.5fr 1fr' : '1fr' ?>;">
    
    <!-- Left Column: Orders Registry Table -->
    <section class="admin-panel">
        <h3>Orders Registry (<?= htmlspecialchars($filter_status) ?>)</h3>
        <?php if (empty($orders)): ?>
            <p style="color: var(--text-muted); padding: 1rem 0;">No orders found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Date Ordered</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $ord): ?>
                            <tr style="<?= ($expand_order_id === $ord['id']) ? 'background-color: #FAF6F0;' : '' ?>">
                                <td><strong>#<?= $ord['id'] ?></strong></td>
                                <td><?= htmlspecialchars($ord['username'] ?? 'Guest/Deleted') ?></td>
                                <td><?= date('d M Y, h:i A', strtotime($ord['order_date'])) ?></td>
                                <td style="font-weight: 600; color: var(--primary-dark);">RM<?= number_format($ord['total_price'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($ord['status']) ?>">
                                        <?= htmlspecialchars($ord['status']) ?>
                                    </span>
                                </td>
                                <td style="display: flex; gap: 0.3rem;">
                                    <a href="orders.php?<?= $base_qs !== '' ? $base_qs . '&' : '' ?>detail_id=<?= $ord['id'] ?>" class="btn btn-secondary btn-sm btn-xs">Details</a>
                                    
                                    <?php if ($ord['status'] === 'Pending'): ?>
                                        <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="mark_processing">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <button type="submit" class="btn btn-accent btn-sm btn-xs">Accept</button>
                                        </form>
                                        <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm btn-xs confirm-action" data-confirm-message="Are you sure you want to cancel order #<?= $ord['id'] ?>? Stock will be refunded.">Cancel</button>
                                        </form>
                                    <?php elseif ($ord['status'] === 'Processing'): ?>
                                        <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="mark_completed">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm btn-xs" style="background-color: var(--success);">Complete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($pg, 'orders.php', $base_params) ?>
        <?php endif; ?>
    </section>

    <!-- Right Column: Order Details Receipt -->
    <?php if ($expand_order): ?>
        <section class="admin-panel dialog-container" style="margin-top: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem; margin-bottom: 1.5rem;">
                <h3 style="border-bottom: none; margin: 0;">Order Receipt #<?= $expand_order['id'] ?></h3>
                <a href="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">&times;</a>
            </div>
            
            <div style="font-size: 0.9rem; display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.5rem;">
                <div><strong>Status:</strong> <span class="badge badge-<?= strtolower($expand_order['status']) ?>"><?= htmlspecialchars($expand_order['status']) ?></span></div>
                <div><strong>Customer:</strong> <?= htmlspecialchars($expand_order['username'] ?? 'Guest/Deleted') ?> (<?= htmlspecialchars($expand_order['user_email'] ?? 'N/A') ?>)</div>
                <div><strong>Phone:</strong> <?= htmlspecialchars($expand_order['user_phone'] ?? 'N/A') ?></div>
                <div><strong>Date Ordered:</strong> <?= date('d M Y, h:i A', strtotime($expand_order['order_date'])) ?></div>
                <div><strong>Order Contact:</strong> <?= htmlspecialchars($expand_order['customer_name']) ?> (<?= htmlspecialchars($expand_order['customer_phone']) ?>)</div>
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
            
            <table class="table" style="font-size: 0.85rem; margin-bottom: 1.5rem;">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expand_items as $item): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($item['name'] ?? 'Removed Product') ?></strong><br>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($item['category_name'] ?? '') ?></span>
                                <?php if (!empty($item['options_summary'])): ?>
                                    <br><span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($item['options_summary']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['customization'])): ?>
                                    <br><span style="font-size: 0.75rem; color: var(--text-muted);">Note: <?= htmlspecialchars($item['customization']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $item['quantity'] ?></td>
                            <td>RM<?= number_format($item['checkout_price'] * $item['quantity'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background-color: var(--bg-cream); font-weight: 700;">
                        <td colspan="2" style="text-align: right;">Total Amount:</td>
                        <td style="color: var(--accent);">RM<?= number_format($expand_order['total_price'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php if ($expand_order['status'] === 'Pending'): ?>
                    <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="mark_processing">
                        <input type="hidden" name="order_id" value="<?= $expand_order['id'] ?>">
                        <button type="submit" class="btn btn-accent btn-block" style="text-align: center;">Accept Order (Mark as Processing)</button>
                    </form>
                    <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="cancel_order">
                        <input type="hidden" name="order_id" value="<?= $expand_order['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-block confirm-action" data-confirm-message="Are you sure you want to cancel order #<?= $expand_order['id'] ?>? Stock will be refunded." style="text-align: center;">Cancel Order</button>
                    </form>
                <?php elseif ($expand_order['status'] === 'Processing'): ?>
                    <form action="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="mark_completed">
                        <input type="hidden" name="order_id" value="<?= $expand_order['id'] ?>">
                        <button type="submit" class="btn btn-block" style="text-align: center; background-color: var(--success); color: white;">Mark as Completed</button>
                    </form>
                <?php endif; ?>
                
                <a href="orders.php<?= $base_qs !== '' ? '?' . $base_qs : '' ?>" class="btn btn-secondary btn-block" style="text-align: center;">Close Receipt</a>
            </div>
        </section>
    <?php endif; ?>
    
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
