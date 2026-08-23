<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/mailer.php';

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

            // Send order confirmation email (best-effort; must never block checkout success)
            try {
                $mail = get_mail();
                $mail->addAddress($current_user['email'] ?? '', $current_user['full_name'] ?? '');
                $mail->Subject = "Order Confirmation #{$order_id} - The Daily Grind";
                $mail->isHTML(true);

                // Brand tokens (mirrors assets/css/style.css :root, since email clients can't read external CSS)
                $c_canvas  = '#3E2723'; // Espresso
                $c_card    = '#FFFFFF';
                $c_panel   = '#FAF6F0'; // Cappuccino Cream
                $c_border  = '#E2D9D2'; // Cream Border
                $c_amber   = '#E65100'; // Caramel Amber
                $c_ink     = '#3E2723'; // Espresso (headings)
                $c_muted   = '#6D5C59'; // Soft Brown
                $c_success = '#2E7D32'; // Matcha Green
                $f_display = "Georgia, 'Times New Roman', serif";
                $f_body    = "'Segoe UI', Helvetica, Arial, sans-serif";

                $detail_row = function ($label, $value) use ($f_body, $c_muted, $c_ink) {
                    return '<tr>'
                        . '<td style="padding:6px 0;font-family:' . $f_body . ';font-size:13px;font-weight:600;color:' . $c_muted . ';white-space:nowrap;">' . $label . '</td>'
                        . '<td style="padding:6px 0 6px 16px;font-family:' . $f_body . ';font-size:13px;color:' . $c_ink . ';text-align:right;">' . $value . '</td>'
                        . '</tr>';
                };

                $details_rows = $detail_row('Placed On', htmlspecialchars(date('d M Y, h:i A')));
                if ($fulfillment_type === 'delivery') {
                    $details_rows .= $detail_row('Fulfillment', 'Delivery');
                    $details_rows .= $detail_row('Deliver To', nl2br(htmlspecialchars($resolved_shipping_address)));
                } else {
                    $details_rows .= $detail_row('Fulfillment', 'Pickup at Store');
                }
                if ($payment_method === 'card') {
                    $payment_label = 'Card &bull;&bull;&bull;&bull; ' . substr($clean_card, -4);
                } elseif ($payment_method === 'e_wallet') {
                    $payment_label = 'E-Wallet';
                } else {
                    $payment_label = 'Cash';
                }
                $details_rows .= $detail_row('Payment', $payment_label);
                $details_rows .= $detail_row('Email', htmlspecialchars($current_user['email'] ?? ''));

                $items_html = '';
                foreach ($cart_items as $item) {
                    $items_html .= '<tr>'
                        . '<td style="padding:8px 0;border-bottom:1px solid ' . $c_border . ';font-family:' . $f_body . ';font-size:13px;color:' . $c_ink . ';">' . htmlspecialchars($item['name'])
                        . ' <span style="color:' . $c_muted . ';">x' . intval($item['quantity']) . '</span>'
                        . (!empty($item['options_summary']) ? '<br><span style="font-size:12px;color:' . $c_muted . ';">' . htmlspecialchars($item['options_summary']) . '</span>' : '')
                        . (!empty($item['customization']) ? '<br><span style="font-size:12px;color:' . $c_muted . ';">Note: ' . htmlspecialchars($item['customization']) . '</span>' : '')
                        . '</td>'
                        . '<td style="padding:8px 0;border-bottom:1px solid ' . $c_border . ';font-family:' . $f_body . ';font-size:13px;color:' . $c_ink . ';text-align:right;white-space:nowrap;">RM' . number_format($item['unit_price'] * $item['quantity'], 2) . '</td>'
                        . '</tr>';
                }
                if ($discount_amount > 0) {
                    $items_html .= '<tr>'
                        . '<td style="padding:8px 0;font-family:' . $f_body . ';font-size:13px;color:' . $c_success . ';">Voucher Discount</td>'
                        . '<td style="padding:8px 0;font-family:' . $f_body . ';font-size:13px;color:' . $c_success . ';text-align:right;">-RM' . number_format($discount_amount, 2) . '</td>'
                        . '</tr>';
                }

                $final_total_formatted = number_format($final_total, 2);

                $mail->Body = <<<HTML
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{$c_canvas};padding:32px 16px;">
                    <tr><td align="center">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:{$c_card};border-radius:8px;overflow:hidden;">

                            <!-- Header: wordmark + status pill -->
                            <tr><td style="padding:28px 32px 0 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                    <td style="font-family:{$f_display};font-size:20px;font-weight:700;color:{$c_ink};">&#9749; The Daily Grind</td>
                                    <td align="right"><span style="display:inline-block;background-color:{$c_success};color:#ffffff;font-family:{$f_body};font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;padding:5px 12px;border-radius:20px;">Confirmed</span></td>
                                </tr></table>
                            </td></tr>

                            <!-- Amber rule -->
                            <tr><td style="padding:14px 32px 0 32px;"><div style="height:2px;background-color:{$c_amber};line-height:2px;font-size:2px;">&nbsp;</div></td></tr>

                            <!-- Eyebrow + order id -->
                            <tr><td style="padding:16px 32px 0 32px;">
                                <p style="margin:0;font-family:{$f_body};font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{$c_muted};">Order Receipt</p>
                                <p style="margin:2px 0 0 0;font-family:{$f_body};font-size:14px;color:{$c_ink};">#{$order_id}</p>
                            </td></tr>

                            <!-- Total paid -->
                            <tr><td style="padding:18px 32px 0 32px;">
                                <p style="margin:0;font-family:{$f_body};font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{$c_muted};">Total Paid</p>
                                <p style="margin:2px 0 0 0;font-family:{$f_display};font-size:36px;font-weight:700;color:{$c_ink};">RM {$final_total_formatted}</p>
                            </td></tr>

                            <!-- Order details panel -->
                            <tr><td style="padding:24px 32px 0 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{$c_panel};border:1px solid {$c_border};border-radius:6px;">
                                    <tr><td style="padding:16px 18px;">
                                        <p style="margin:0 0 4px 0;font-family:{$f_body};font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{$c_muted};">Order Details</p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            {$details_rows}
                                        </table>
                                    </td></tr>
                                </table>
                            </td></tr>

                            <!-- Items -->
                            <tr><td style="padding:24px 32px 0 32px;">
                                <p style="margin:0 0 4px 0;font-family:{$f_body};font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{$c_muted};">Your Items</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                    {$items_html}
                                </table>
                            </td></tr>

                            <!-- Perforated tear line -->
                            <tr><td style="padding:24px 32px 0 32px;"><div style="border-top:2px dashed {$c_border};line-height:0;font-size:0;">&nbsp;</div></td></tr>

                            <!-- Footer -->
                            <tr><td style="padding:20px 32px;background-color:{$c_canvas};margin-top:20px;">
                                <p style="margin:0;font-family:{$f_display};font-size:15px;font-weight:700;color:#FAF6F0;">&#9749; The Daily Grind</p>
                                <p style="margin:6px 0 0 0;font-family:{$f_body};font-size:12px;color:#C9B8AF;">Questions about your order? Just reply to this email.</p>
                            </td></tr>

                        </table>
                    </td></tr>
                </table>
                HTML;

                $mail->send();
            } catch (\Exception $e) {
                // Swallow send failures so a flaky mail server never blocks a placed order
            }

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

<div class="checkout-page">
    <h1 class="page-title">Checkout</h1>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <div class="checkout-grid">

        <!-- Order Details Form -->
        <section class="admin-panel">
            <h3>Order Details</h3>

            <form action="checkout.php" method="POST" novalidate>

                <?= html_input('text', 'customer_name', $customer_name, 'Full Name', 'e.g. John Doe', $errors) ?>
                <?= html_input('text', 'customer_phone', $customer_phone, 'Phone Number', 'e.g., 0123456789', $errors) ?>

                <div class="form-group">
                    <label>Fulfillment</label>
                    <div class="fulfil-options">
                        <div class="fulfil-card">
                            <input type="radio" name="fulfillment_type" value="delivery" id="fulfillment-delivery" <?= $fulfillment_type === 'delivery' ? 'checked' : '' ?>>
                            <label for="fulfillment-delivery"><span class="radio-dot"></span>Delivery</label>
                        </div>
                        <div class="fulfil-card">
                            <input type="radio" name="fulfillment_type" value="pickup" id="fulfillment-pickup" <?= $fulfillment_type === 'pickup' ? 'checked' : '' ?>>
                            <label for="fulfillment-pickup"><span class="radio-dot"></span>Pickup at Store</label>
                        </div>
                    </div>
                </div>

                <div id="delivery-section" class="<?= $fulfillment_type === 'pickup' ? 'is-hidden' : '' ?>">
                    <?php if (!empty($saved_addresses)): ?>
                        <?= html_select('address_id', $address_options, ($address_id > 0 ? $address_id : 'new'), 'Delivery Address', $errors) ?>
                    <?php endif; ?>

                    <div id="new-address-section" class="<?= (!empty($saved_addresses) && $address_id > 0) ? 'is-hidden' : '' ?>">
                        <?= html_textarea('shipping_address', $shipping_address, 'Shipping Address', 'Enter your delivery address', $errors) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="field-voucher_code">Voucher Code (Optional)</label>
                    <div class="inline-field-group">
                        <input type="text" name="voucher_code" id="field-voucher_code" class="form-control" value="<?= htmlspecialchars($voucher_code) ?>" placeholder="e.g. COFFEE10">
                        <button type="button" id="apply-voucher-btn" class="btn btn-secondary btn-sm">Apply</button>
                    </div>
                    <span id="voucher-message" class="voucher-message"></span>
                    <?= html_error($errors, 'voucher_code') ?>
                </div>

                <h4 class="panel-subheading">Payment Method</h4>

                <div class="fulfil-options fulfil-options--tight">
                    <div class="fulfil-card">
                        <input type="radio" name="payment_method" value="card" id="payment-card" <?= $payment_method === 'card' ? 'checked' : '' ?>>
                        <label for="payment-card"><span class="radio-dot"></span>Card</label>
                    </div>
                    <div class="fulfil-card">
                        <input type="radio" name="payment_method" value="e_wallet" id="payment-ewallet" <?= $payment_method === 'e_wallet' ? 'checked' : '' ?>>
                        <label for="payment-ewallet"><span class="radio-dot"></span>E-Wallet</label>
                    </div>
                    <div class="fulfil-card">
                        <input type="radio" name="payment_method" value="cash" id="payment-cash" <?= $payment_method === 'cash' ? 'checked' : '' ?>>
                        <label for="payment-cash"><span class="radio-dot"></span>Cash</label>
                    </div>
                </div>

                <div id="card-payment-section" class="<?= $payment_method !== 'card' ? 'is-hidden' : '' ?>">
                    <p class="checkout-disclaimer">
                        * This is a simulated university checkout. Please do NOT enter real credit card numbers.
                    </p>
                    <?= html_input('text', 'card_name', $card_name, 'Cardholder Name', 'e.g. John Doe', $errors) ?>
                    <?= html_input('text', 'card_number', $card_number, 'Credit Card Number', '1234 5678 1234 5678', $errors, ['maxlength' => '19']) ?>

                    <div class="field-row-2">
                        <?= html_input('text', 'card_expiry', $card_expiry, 'Expiration Date', 'MM/YY', $errors, ['maxlength' => '5']) ?>
                        <?= html_input('password', 'card_cvv', $card_cvv, 'CVV Code', '123', $errors, ['maxlength' => '4']) ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-accent btn-block checkout-submit">
                    Place Order
                </button>
            </form>
        </section>

        <!-- Order Summary Sidebar -->
        <section class="admin-panel order-summary-panel">
            <h3>Order Summary</h3>

            <?php foreach ($cart_items as $item): ?>
                <div class="receipt-line">
                    <div>
                        <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                        <span class="item-meta">
                            Qty: <?= $item['quantity'] ?> @ RM<?= number_format($item['unit_price'], 2) ?> each
                            <?php if (!empty($item['options_summary'])): ?>
                                <br><?= htmlspecialchars($item['options_summary']) ?>
                            <?php endif; ?>
                            <?php if (!empty($item['customization'])): ?>
                                <br>Note: <?= htmlspecialchars($item['customization']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="receipt-dots"></span>
                    <span class="amount">RM<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></span>
                </div>
            <?php endforeach; ?>

            <div class="receipt-summary-line">
                <span>Subtotal:</span>
                <strong>RM<?= number_format($grand_total, 2) ?></strong>
            </div>

            <div id="discount-line" class="receipt-summary-line receipt-summary-line--discount" style="display: <?= $discount_amount > 0 ? 'flex' : 'none' ?>;">
                <span>Voucher Discount:</span>
                <strong id="discount-amount">-RM<?= number_format($discount_amount, 2) ?></strong>
            </div>

            <div class="receipt-total">
                <span>Total</span>
                <span id="grand-total-amount">RM<?= number_format($final_total, 2) ?></span>
            </div>

            <div class="panel-action">
                <a href="cart.php" class="btn btn-secondary btn-sm btn-block">Modify Shopping Cart</a>
            </div>
        </section>

    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
