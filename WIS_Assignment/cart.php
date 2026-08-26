<?php
// Initialize database connection & authentication helpers first
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';

/**
 * Add or merge a quantity into the cart, keyed on (user_id, product_id, customization, option_signature)
 * to match the cart table's unique key. Caps the resulting quantity at available stock.
 */
function cart_upsert(PDO $pdo, $user_id, $product_id, $add_qty, $customization, $stock, $option_signature = '', $options_summary = '', $options_price_delta = 0.00) {
    $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND customization = ? AND option_signature = ?");
    $stmt->execute([$user_id, $product_id, $customization, $option_signature]);
    $existing = $stmt->fetch();

    $requested_qty = ($existing ? $existing['quantity'] : 0) + $add_qty;
    $new_qty = min($requested_qty, $stock);

    if ($existing) {
        $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?")->execute([$new_qty, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity, customization, option_signature, options_summary, options_price_delta) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$user_id, $product_id, $new_qty, $customization, $option_signature, $options_summary, $options_price_delta]);
    }

    return ['new_qty' => $new_qty, 'capped' => $new_qty < $requested_qty];
}

/**
 * Validate the customer's selected option values against a product's admin-defined
 * option groups. Returns null and a normalized selection on success, or an error message.
 */
function resolve_selected_options(PDO $pdo, $product_id, $selected) {
    $groups_stmt = $pdo->prepare("SELECT id, name, is_required FROM product_option_groups WHERE product_id = ? ORDER BY id ASC");
    $groups_stmt->execute([$product_id]);
    $groups = $groups_stmt->fetchAll();

    $summary_parts = [];
    $signature_ids = [];
    $price_delta = 0.00;

    foreach ($groups as $group) {
        $value_id = isset($selected[$group['id']]) ? intval($selected[$group['id']]) : 0;

        if ($value_id <= 0) {
            if ($group['is_required']) {
                return ['error' => "Please select an option for \"{$group['name']}\"."];
            }
            continue;
        }

        $value_stmt = $pdo->prepare("SELECT id, label, price_delta FROM product_option_values WHERE id = ? AND group_id = ?");
        $value_stmt->execute([$value_id, $group['id']]);
        $value = $value_stmt->fetch();

        if (!$value) {
            return ['error' => "Invalid selection for \"{$group['name']}\"."];
        }

        $summary_parts[] = $value['price_delta'] > 0
            ? "{$group['name']}: {$value['label']} (+RM" . number_format($value['price_delta'], 2) . ")"
            : "{$group['name']}: {$value['label']}";
        $signature_ids[] = $value['id'];
        $price_delta += $value['price_delta'];
    }

    sort($signature_ids);

    return [
        'error' => null,
        'option_signature' => implode(',', $signature_ids),
        'options_summary' => implode('; ', $summary_parts),
        'options_price_delta' => $price_delta
    ];
}

// Handle AJAX quantity stepper (+/-) separately to avoid rendering layouts
if (isset($_POST['action']) && $_POST['action'] === 'ajax_update_qty') {
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        echo json_encode([
            'success' => false,
            'message' => 'Please log in to update your cart.',
            'redirect' => 'login.php'
        ]);
        exit;
    }

    $cart_id = isset($_POST['cart_id']) ? intval($_POST['cart_id']) : 0;
    $change = isset($_POST['change']) ? intval($_POST['change']) : 0;
    $user_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT c.quantity as cart_qty, p.stock, (p.price + c.options_price_delta) as unit_price
                           FROM cart c
                           JOIN products p ON c.product_id = p.id
                           WHERE c.id = ? AND c.user_id = ?");
    $stmt->execute([$cart_id, $user_id]);
    $cart_item = $stmt->fetch();

    if (!$cart_item) {
        echo json_encode(['success' => false, 'message' => 'Cart item not found.']);
        exit;
    }

    $requested_qty = $cart_item['cart_qty'] + $change;
    $new_qty = max(1, min($requested_qty, $cart_item['stock']));

    $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?")->execute([$new_qty, $cart_id]);

    $stmt = $pdo->prepare("SELECT SUM(c.quantity * (p.price + c.options_price_delta)) as total, SUM(c.quantity) as item_count
                           FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
    $totals = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'quantity' => $new_qty,
        'subtotal' => round($cart_item['unit_price'] * $new_qty, 2),
        'grand_total' => round($totals['total'] ?? 0, 2),
        'cart_count' => intval($totals['item_count'] ?? 0),
        'capped' => $new_qty < $requested_qty,
        'at_min' => $new_qty <= 1,
        'at_max' => $new_qty >= $cart_item['stock']
    ]);
    exit;
}

// Handle AJAX batch removal ("remove selected items"), same pattern as above
if (isset($_POST['action']) && $_POST['action'] === 'ajax_batch_remove') {
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        echo json_encode([
            'success' => false,
            'message' => 'Please log in to update your cart.',
            'redirect' => 'login.php'
        ]);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

    if (!$ids) {
        echo json_encode(['success' => false, 'message' => 'No items selected.']);
        exit;
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM cart WHERE id IN ($ph) AND user_id = ?")
        ->execute(array_merge($ids, [$user_id]));

    $totals_stmt = $pdo->prepare("SELECT SUM(c.quantity * (p.price + c.options_price_delta)) as total, SUM(c.quantity) as item_count
                                  FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $totals_stmt->execute([$user_id]);
    $totals = $totals_stmt->fetch();

    echo json_encode([
        'success' => true,
        'removed' => $ids,
        'grand_total' => round($totals['total'] ?? 0, 2),
        'cart_count' => intval($totals['item_count'] ?? 0),
        'message' => count($ids) . ' item(s) removed.'
    ]);
    exit;
}

// Below handles standard page requests
require_once __DIR__ . '/includes/header.php';

// Require login for members
require_login();
if (is_admin()) {
    echo '<div class="alert alert-danger">Administrators cannot access the shopping cart.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle normal page actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $_POST = sanitize_input($_POST);
    $action = $_POST['action'];

    if ($action === 'add') {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $customization = trim($_POST['customization'] ?? '');
        $selected_options = $_POST['options'] ?? [];

        // Fetch product
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product && $product['stock'] > 0) {
            $resolved = resolve_selected_options($pdo, $product_id, $selected_options);

            if ($resolved['error']) {
                $_SESSION['flash_error'] = $resolved['error'];
                header("Location: product_detail.php?id=" . $product_id);
                exit;
            }

            $result = cart_upsert(
                $pdo, $user_id, $product_id, $quantity, $customization, $product['stock'],
                $resolved['option_signature'], $resolved['options_summary'], $resolved['options_price_delta']
            );

            if ($result['capped']) {
                $_SESSION['flash_error'] = "You cannot add more than {$product['stock']} units of this item.";
            } else {
                $_SESSION['flash_success'] = "Added to cart.";
            }

            // Redirect to cart page to prevent form resubmission
            header("Location: cart.php");
            exit;
        } else {
            $errors[] = "Item is unavailable or out of stock.";
        }
    } elseif ($action === 'edit') {
        $edit_cart_id = isset($_POST['edit_cart_id']) ? intval($_POST['edit_cart_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $customization = trim($_POST['customization'] ?? '');
        $selected_options = $_POST['options'] ?? [];

        $stmt = $pdo->prepare("SELECT id FROM cart WHERE id = ? AND user_id = ? AND product_id = ?");
        $stmt->execute([$edit_cart_id, $user_id, $product_id]);
        $owned = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!$owned || !$product) {
            $_SESSION['flash_error'] = "Cart item not found.";
            header("Location: cart.php");
            exit;
        }

        $resolved = resolve_selected_options($pdo, $product_id, $selected_options);

        if ($resolved['error']) {
            $_SESSION['flash_error'] = $resolved['error'];
            header("Location: product_detail.php?id=" . $product_id . "&edit_cart_id=" . $edit_cart_id);
            exit;
        }

        // The cart's unique key is (user_id, product_id, customization, option_signature) - if the
        // edited combination already matches another row, merge quantities into it instead of colliding.
        $dup_stmt = $pdo->prepare("SELECT id, quantity FROM cart
                                   WHERE user_id = ? AND product_id = ? AND customization = ? AND option_signature = ? AND id != ?");
        $dup_stmt->execute([$user_id, $product_id, $customization, $resolved['option_signature'], $edit_cart_id]);
        $duplicate = $dup_stmt->fetch();

        if ($duplicate) {
            $new_qty = min($duplicate['quantity'] + $quantity, $product['stock']);
            $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?")->execute([$new_qty, $duplicate['id']]);
            $pdo->prepare("DELETE FROM cart WHERE id = ?")->execute([$edit_cart_id]);
        } else {
            $new_qty = min($quantity, $product['stock']);
            $pdo->prepare("UPDATE cart SET customization = ?, option_signature = ?, options_summary = ?, options_price_delta = ?, quantity = ? WHERE id = ?")
                ->execute([$customization, $resolved['option_signature'], $resolved['options_summary'], $resolved['options_price_delta'], $new_qty, $edit_cart_id]);
        }

        $_SESSION['flash_success'] = ($new_qty < $quantity)
            ? "Cart item updated. Quantity capped at {$product['stock']} due to available stock."
            : "Cart item updated.";
        header("Location: cart.php");
        exit;
    } elseif ($action === 'update') {
        $cart_id = isset($_POST['cart_id']) ? intval($_POST['cart_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

        // Retrieve product stock based on cart item
        $stmt = $pdo->prepare("SELECT c.quantity as cart_qty, p.stock, p.name
                               FROM cart c
                               JOIN products p ON c.product_id = p.id
                               WHERE c.id = ? AND c.user_id = ?");
        $stmt->execute([$cart_id, $user_id]);
        $cart_item = $stmt->fetch();

        if ($cart_item) {
            if ($quantity <= 0) {
                // Delete if quantity is set to 0 or less
                $del_stmt = $pdo->prepare("DELETE FROM cart WHERE id = ?");
                $del_stmt->execute([$cart_id]);
                $_SESSION['flash_success'] = "Item removed from cart.";
            } elseif ($quantity > $cart_item['stock']) {
                $_SESSION['flash_error'] = "Cannot update quantity. Only {$cart_item['stock']} units of '{$cart_item['name']}' are in stock.";
                // Cap quantity at available stock
                $upd_stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                $upd_stmt->execute([$cart_item['stock'], $cart_id]);
            } else {
                $upd_stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                $upd_stmt->execute([$quantity, $cart_id]);
                $_SESSION['flash_success'] = "Cart updated.";
            }
        }
        header("Location: cart.php");
        exit;
    } elseif ($action === 'remove') {
        $cart_id = isset($_POST['cart_id']) ? intval($_POST['cart_id']) : 0;
        $stmt = $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
        $stmt->execute([$cart_id, $user_id]);
        $_SESSION['flash_success'] = "Item removed from cart.";
        header("Location: cart.php");
        exit;
    }
}

// Fetch all cart items for display
$stmt = $pdo->prepare("SELECT c.id as cart_id, c.quantity, c.customization, c.options_summary, c.options_price_delta, p.*, cat.name as category_name,
                       (p.price + c.options_price_delta) as unit_price,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as primary_image
                       FROM cart c
                       JOIN products p ON c.product_id = p.id
                       LEFT JOIN categories cat ON p.category_id = cat.id
                       WHERE c.user_id = ?
                       ORDER BY c.created_at ASC");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

// Handle flash session messages
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<h1 class="page-title">Shopping Cart</h1>

<?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if (empty($cart_items)): ?>
    <div class="alert alert-info" style="text-align: center; padding: 3rem;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">🛒</div>
        <p style="font-size: 1.2rem; margin-bottom: 1.5rem;">Your shopping cart is empty.</p>
        <a href="index.php" class="btn btn-accent">Browse Coffee Menu</a>
    </div>
<?php else: ?>
    <!-- Batch remove: JS collects checked .cart-cb values and posts them via $.ajax (app.js #15b).
         Intentionally NOT a <form> wrapper - each row already has its own per-item Remove form. -->
    <div class="bulk-bar is-hidden" id="cart-bulk-bar">
        <span class="badge" id="cart-bulk-count">0 selected</span>
        <button type="button" id="cart-bulk-remove" class="btn btn-sm btn-danger">Remove selected</button>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="cart-select-all" title="Select all"></th>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Subtotal</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grand_total = 0;
                foreach ($cart_items as $item):
                    $subtotal = $item['unit_price'] * $item['quantity'];
                    $grand_total += $subtotal;
                ?>
                    <tr data-cart-row="<?= $item['cart_id'] ?>">
                        <td class="col-check"><input type="checkbox" class="cart-cb" name="ids[]" value="<?= $item['cart_id'] ?>"></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <?php
                                $photo_path = 'uploads/products/' . $item['primary_image'];
                                if (!empty($item['primary_image']) && file_exists(__DIR__ . '/' . $photo_path)):
                                ?>
                                    <img src="<?= htmlspecialchars($photo_path) ?>" class="img-thumbnail" alt="<?= htmlspecialchars($item['name']) ?>">
                                <?php else: ?>
                                    <div class="img-thumbnail" style="background-color: var(--primary-light); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">☕</div>
                                <?php endif; ?>
                                <div>
                                    <h4 style="margin: 0; font-family: 'Jost', sans-serif; font-size: 1.05rem;">
                                        <a href="product_detail.php?id=<?= $item['id'] ?>"><?= htmlspecialchars($item['name']) ?></a>
                                    </h4>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></span>
                                    <?php if ($item['options_summary'] !== ''): ?>
                                        <br><span style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($item['options_summary']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['customization'] !== ''): ?>
                                        <br><span style="font-size: 0.8rem; color: var(--text-muted);">Note: <?= htmlspecialchars($item['customization']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['stock'] <= 5): ?>
                                        <br><span style="font-size: 0.78rem; color: var(--warning); font-weight: 600;">Only <?= $item['stock'] ?> left in stock!</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>RM<?= number_format($item['unit_price'], 2) ?></td>
                        <td>
                            <div class="qty-stepper" data-cart-id="<?= $item['cart_id'] ?>" data-stock="<?= $item['stock'] ?>">
                                <button type="button" class="qty-btn qty-decrease" title="Decrease quantity" <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>&minus;</button>
                                <span class="qty-value"><?= $item['quantity'] ?></span>
                                <button type="button" class="qty-btn qty-increase" title="Increase quantity" <?= $item['quantity'] >= $item['stock'] ? 'disabled' : '' ?>>+</button>
                            </div>
                        </td>
                        <td id="subtotal-<?= $item['cart_id'] ?>" style="font-weight: 600; color: var(--primary-dark);">RM<?= number_format($subtotal, 2) ?></td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                                <a href="product_detail.php?id=<?= $item['id'] ?>&edit_cart_id=<?= $item['cart_id'] ?>" class="btn btn-secondary btn-sm" title="Edit options / note">Edit</a>
                                <form action="cart.php" method="POST">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm confirm-action" data-confirm-message="Remove '<?= htmlspecialchars($item['name']) ?>' from cart?">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="cart-total-box">
        <div class="cart-total-line">
            <span>Subtotal:</span>
            <strong id="cart-subtotal-value">RM<?= number_format($grand_total, 2) ?></strong>
        </div>
        <div class="cart-total-line">
            <span>Estimated Shipping:</span>
            <strong style="color: var(--success);">FREE</strong>
        </div>
        <div class="cart-total-line final">
            <span>Total:</span>
            <span id="cart-total-value">RM<?= number_format($grand_total, 2) ?></span>
        </div>
        <a href="checkout.php" class="btn btn-accent btn-block" style="text-align: center;">Proceed to Checkout &rarr;</a>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
