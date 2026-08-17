<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';

// AJAX: live voucher preview. Cosmetic only - the real discount is always
// recomputed server-side on submission below, never trusted from the client.
if (isset($_POST['action']) && $_POST['action'] === 'validate_voucher') {
    header('Content-Type: application/json');

    if (!is_logged_in() || is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $code = strtoupper(trim($_POST['code'] ?? ''));

    $cart_stmt = $pdo->prepare("SELECT c.quantity, p.price FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $cart_stmt->execute([$user_id]);
    $subtotal = 0;
    foreach ($cart_stmt->fetchAll() as $row) {
        $subtotal += $row['price'] * $row['quantity'];
    }

    $v_stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? AND status = 'active'");
    $v_stmt->execute([$code]);
    $voucher = $v_stmt->fetch();

    if (!$voucher) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive voucher code.']);
        exit;
    }
    if ($subtotal < $voucher['min_spend']) {
        echo json_encode(['success' => false, 'message' => 'Minimum spend of RM' . number_format($voucher['min_spend'], 2) . ' required.']);
        exit;
    }

    $discount_amount = round($subtotal * $voucher['discount_percent'] / 100, 2);
    echo json_encode([
        'success' => true,
        'discount_amount' => $discount_amount,
        'new_total' => round($subtotal - $discount_amount, 2)
    ]);
    exit;
}

require_once __DIR__ . '/includes/header.php';

// Access restrictions
require_login();
if (is_admin()) {
    echo '<div class="alert alert-danger">Administrators cannot make purchases.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user cart items
$stmt = $pdo->prepare("SELECT c.quantity, c.customization, c.options_summary, c.options_price_delta, p.*,
                       (p.price + c.options_price_delta) as unit_price
                       FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

if (empty($cart_items)) {
    header("Location: cart.php");
    exit;
}

// Calculate total
$grand_total = 0;
foreach ($cart_items as $item) {
    $grand_total += $item['unit_price'] * $item['quantity'];
}

// Saved addresses for the delivery-address picker
$addr_stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC");
$addr_stmt->execute([$user_id]);
$saved_addresses = $addr_stmt->fetchAll();
$default_address_id = 0;
foreach ($saved_addresses as $sa) {
    if ($sa['is_default']) {
        $default_address_id = $sa['id'];
        break;
    }
}

$errors = [];
$fulfillment_type = 'delivery';
$customer_name = $current_user['full_name'] ?? '';
$customer_phone = $current_user['phone'] ?? '';
$address_id = $default_address_id;
$shipping_address = '';
$voucher_code = '';
$payment_method = 'card';
$card_name = '';
$card_number = '';
$card_expiry = '';
$card_cvv = '';
$discount_amount = 0.00;
$final_total = $grand_total;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);

    $fulfillment_type = ($_POST['fulfillment_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $address_id = isset($_POST['address_id']) ? intval($_POST['address_id']) : 0;
    $shipping_address = $_POST['shipping_address'] ?? '';
    $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
    $payment_method = in_array($_POST['payment_method'] ?? '', ['card', 'e_wallet', 'cash'], true) ? $_POST['payment_method'] : 'card';
    $card_name = $_POST['card_name'] ?? '';
    $card_number = $_POST['card_number'] ?? '';
    $card_expiry = $_POST['card_expiry'] ?? '';
    $card_cvv = $_POST['card_cvv'] ?? '';

    // Server-side Validations
    $req_fields = [
        'customer_name' => 'Full Name',
        'customer_phone' => 'Phone Number'
    ];

    $resolved_shipping_address = null;
    if ($fulfillment_type === 'delivery') {
        if ($address_id > 0) {
            $chosen_stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
            $chosen_stmt->execute([$address_id, $user_id]);
            $chosen_addr = $chosen_stmt->fetch();
            if ($chosen_addr) {
                $resolved_shipping_address = $chosen_addr['recipient_name'] . ', ' . $chosen_addr['recipient_phone'] . "\n" . $chosen_addr['address_line_1']
                    . (!empty($chosen_addr['address_line_2']) ? ', ' . $chosen_addr['address_line_2'] : '')
                    . ', ' . $chosen_addr['city']
                    . (!empty($chosen_addr['state']) ? ', ' . $chosen_addr['state'] : '')
                    . ' ' . $chosen_addr['zip_code'];
            } else {
                $errors['shipping_address'] = "Selected address could not be found.";
            }
        } else {
            $req_fields['shipping_address'] = 'Shipping Address';
        }
    }

    if ($payment_method === 'card') {
        $req_fields['card_name'] = 'Cardholder Name';
        $req_fields['card_number'] = 'Credit Card Number';
        $req_fields['card_expiry'] = 'Expiration Date (MM/YY)';
        $req_fields['card_cvv'] = 'CVV Code';
    }

    $errors = array_merge($errors, validate_required($_POST, $req_fields));

    if (empty($errors['customer_phone'])) {
        $phone_err = validate_phone($customer_phone);
        if ($phone_err) {
            $errors['customer_phone'] = $phone_err;
        }
    }

    // Validate Card details only when paying by card
    $clean_card = '';
    if ($payment_method === 'card') {
        $clean_card = str_replace(' ', '', $card_number);
        if (empty($errors['card_number']) && !preg_match('/^[0-9]{13,16}$/', $clean_card)) {
            $errors['card_number'] = "Please enter a valid credit card number (13-16 digits).";
        }
        if (empty($errors['card_expiry']) && !preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/', $card_expiry)) {
            $errors['card_expiry'] = "Must be in MM/YY format.";
        }
        if (empty($errors['card_cvv']) && !preg_match('/^[0-9]{3,4}$/', $card_cvv)) {
            $errors['card_cvv'] = "Must be 3 or 4 digits.";
        }
    }

    // Voucher: always recomputed server-side, never trusted from the client
    $voucher_id = null;
    if ($voucher_code !== '') {
        $v_stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? AND status = 'active'");
        $v_stmt->execute([$voucher_code]);
        $voucher = $v_stmt->fetch();
        if (!$voucher) {
            $errors['voucher_code'] = "Invalid or inactive voucher code.";
        } elseif ($grand_total < $voucher['min_spend']) {
            $errors['voucher_code'] = "Minimum spend of RM" . number_format($voucher['min_spend'], 2) . " required for this voucher.";
        } else {
            $voucher_id = $voucher['id'];
            $discount_amount = round($grand_total * $voucher['discount_percent'] / 100, 2);
        }
    }
    $final_total = round($grand_total - $discount_amount, 2);

    // Transaction Stock Verification
    if (empty($errors)) {
        foreach ($cart_items as $item) {
            $check_stmt = $pdo->prepare("SELECT stock, name FROM products WHERE id = ?");
            $check_stmt->execute([$item['id']]);
            $current_product = $check_stmt->fetch();

            if (!$current_product || $current_product['stock'] < $item['quantity']) {
                $errors['general'] = "Sorry, '{$item['name']}' only has {$current_product['stock']} units left. Please adjust your cart.";
                break;
            }
        }
    }

    // Proceed with purchase transaction
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1. Create order
            $order_stmt = $pdo->prepare("INSERT INTO orders (user_id, voucher_id, customer_name, customer_phone, fulfillment_type, shipping_address, discount_amount, total_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $order_stmt->execute([
                $user_id,
                $voucher_id,
                $customer_name,
                $customer_phone,
                $fulfillment_type,
                $fulfillment_type === 'delivery' ? $resolved_shipping_address : null,
                $discount_amount,
                $final_total
            ]);

            $order_id = $pdo->lastInsertId();

            // 2. Insert order items & Decrement stock levels
            $item_stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, price, quantity, customization, options_summary) VALUES (?, ?, ?, ?, ?, ?)");
            $stock_stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");

            foreach ($cart_items as $item) {
                $item_stmt->execute([
                    $order_id,
                    $item['id'],
                    $item['unit_price'],
                    $item['quantity'],
                    $item['customization'],
                    $item['options_summary']
                ]);

                $stock_stmt->execute([
                    $item['quantity'],
                    $item['id']
                ]);
            }

            // 3. Record payment
            if ($payment_method === 'card') {
                $transaction_id = '**** **** **** ' . substr($clean_card, -4);
                $payment_status = 'completed';
            } elseif ($payment_method === 'e_wallet') {
                $transaction_id = 'EWALLET_' . strtoupper(substr(md5(uniqid()), 0, 10));
                $payment_status = 'completed';
            } else {
                $transaction_id = null;
                $payment_status = 'pending';
            }

            $pdo->prepare("INSERT INTO payments (order_id, payment_method, transaction_id, amount, payment_status) VALUES (?, ?, ?, ?, ?)")
                ->execute([$order_id, $payment_method, $transaction_id, $final_total, $payment_status]);

            // 4. Clear user cart
            $clear_cart_stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
            $clear_cart_stmt->execute([$user_id]);

            $pdo->commit();

            // Redirect with success message
            $_SESSION['flash_success'] = "Thank you! Your order #{$order_id} has been placed successfully and is pending preparation.";
            header("Location: orders.php");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = "Checkout transaction failed: " . $e->getMessage();
        }
    }
}

$address_options = [];
foreach ($saved_addresses as $sa) {
    $address_options[$sa['id']] = $sa['address_label'] . ' - ' . $sa['address_line_1'] . ', ' . $sa['city'] . ($sa['is_default'] ? ' (Default)' : '');
}
$address_options['new'] = '+ Enter a new address';
?>

<div style="max-width: 900px; margin: 0 auto;">
    <h1 style="margin-bottom: 2rem;">Checkout</h1>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 3rem;">

        <!-- Order Details Form -->
        <section class="admin-panel">
            <h3>Order Details</h3>

            <form action="checkout.php" method="POST" novalidate>

                <?= html_input('text', 'customer_name', $customer_name, 'Full Name', 'e.g. John Doe', $errors) ?>
                <?= html_input('text', 'customer_phone', $customer_phone, 'Phone Number', 'e.g., 0123456789', $errors) ?>

                <div class="form-group">
                    <label>Fulfillment</label>
                    <div style="display: flex; gap: 1.5rem; margin-top: 0.4rem;">
                        <label style="font-weight: 400; display: flex; align-items: center; gap: 0.4rem;">
                            <input type="radio" name="fulfillment_type" value="delivery" <?= $fulfillment_type === 'delivery' ? 'checked' : '' ?>> Delivery
                        </label>
                        <label style="font-weight: 400; display: flex; align-items: center; gap: 0.4rem;">
                            <input type="radio" name="fulfillment_type" value="pickup" <?= $fulfillment_type === 'pickup' ? 'checked' : '' ?>> Pickup at Store
                        </label>
                    </div>
                </div>

                <div id="delivery-section" style="<?= $fulfillment_type === 'pickup' ? 'display: none;' : '' ?>">
                    <?php if (!empty($saved_addresses)): ?>
                        <?= html_select('address_id', $address_options, ($address_id > 0 ? $address_id : 'new'), 'Delivery Address', $errors) ?>
                    <?php endif; ?>

                    <div id="new-address-section" style="<?= (!empty($saved_addresses) && $address_id > 0) ? 'display: none;' : '' ?>">
                        <?= html_textarea('shipping_address', $shipping_address, 'Shipping Address', 'Enter your delivery address', $errors) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="field-voucher_code">Voucher Code (Optional)</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" name="voucher_code" id="field-voucher_code" class="form-control" value="<?= htmlspecialchars($voucher_code) ?>" placeholder="e.g. COFFEE10">
                        <button type="button" id="apply-voucher-btn" class="btn btn-secondary btn-sm">Apply</button>
                    </div>
                    <span id="voucher-message" style="font-size: 0.85rem; display: block; margin-top: 0.3rem;"></span>
                    <?= html_error($errors, 'voucher_code') ?>
                </div>

                <h4 style="margin-top: 1.5rem; margin-bottom: 1rem; font-family: 'Outfit', sans-serif; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">
                    Payment Method
                </h4>

                <div style="display: flex; gap: 1.5rem; margin-bottom: 1rem;">
                    <label style="font-weight: 400; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="radio" name="payment_method" value="card" <?= $payment_method === 'card' ? 'checked' : '' ?>> Card
                    </label>
                    <label style="font-weight: 400; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="radio" name="payment_method" value="e_wallet" <?= $payment_method === 'e_wallet' ? 'checked' : '' ?>> E-Wallet
                    </label>
                    <label style="font-weight: 400; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="radio" name="payment_method" value="cash" <?= $payment_method === 'cash' ? 'checked' : '' ?>> Cash
                    </label>
                </div>

                <div id="card-payment-section" style="<?= $payment_method !== 'card' ? 'display: none;' : '' ?>">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                        * This is a simulated university checkout. Please do NOT enter real credit card numbers.
                    </p>
                    <?= html_input('text', 'card_name', $card_name, 'Cardholder Name', 'e.g. John Doe', $errors) ?>
                    <?= html_input('text', 'card_number', $card_number, 'Credit Card Number', '1234 5678 1234 5678', $errors, ['maxlength' => '19']) ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <?= html_input('text', 'card_expiry', $card_expiry, 'Expiration Date', 'MM/YY', $errors, ['maxlength' => '5']) ?>
                        <?= html_input('password', 'card_cvv', $card_cvv, 'CVV Code', '123', $errors, ['maxlength' => '4']) ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1.5rem; text-align: center;">
                    Place Order
                </button>
            </form>
        </section>

        <!-- Order Summary Sidebar -->
        <section class="admin-panel" style="height: fit-content;">
            <h3>Order Summary</h3>
            <ul style="list-style: none;">
                <?php foreach ($cart_items as $item): ?>
                    <li style="display: flex; justify-content: space-between; padding: 0.8rem 0; border-bottom: 1px solid var(--border-color);">
                        <div>
                            <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Qty: <?= $item['quantity'] ?> @ RM<?= number_format($item['unit_price'], 2) ?> each</span>
                            <?php if (!empty($item['options_summary'])): ?>
                                <br><span style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($item['options_summary']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['customization'])): ?>
                                <br><span style="font-size: 0.8rem; color: var(--text-muted);">Note: <?= htmlspecialchars($item['customization']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span style="font-weight: 500;">RM<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div style="margin-top: 1.5rem; display: flex; justify-content: space-between;">
                <span>Subtotal:</span>
                <strong>RM<?= number_format($grand_total, 2) ?></strong>
            </div>

            <div id="discount-line" style="display: <?= $discount_amount > 0 ? 'flex' : 'none' ?>; justify-content: space-between; color: var(--success); margin-top: 0.4rem;">
                <span>Voucher Discount:</span>
                <strong id="discount-amount">-RM<?= number_format($discount_amount, 2) ?></strong>
            </div>

            <div style="margin-top: 0.8rem; font-size: 1.3rem; display: flex; justify-content: space-between; font-weight: 700; color: var(--primary-dark);">
                <span>Total:</span>
                <span id="grand-total-amount">RM<?= number_format($final_total, 2) ?></span>
            </div>

            <div style="margin-top: 1.5rem;">
                <a href="cart.php" class="btn btn-secondary btn-sm" style="width: 100%; text-align: center;">Modify Shopping Cart</a>
            </div>
        </section>

    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
