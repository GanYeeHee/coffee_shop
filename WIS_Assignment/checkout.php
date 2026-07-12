<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/validation.php';

// Access restrictions
require_login();
if (is_admin()) {
    echo '<div class="alert alert-danger">Administrators cannot make purchases.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user cart items
$stmt = $pdo->prepare("SELECT c.quantity, p.* FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

if (empty($cart_items)) {
    header("Location: cart.php");
    exit;
}

// Calculate total
$grand_total = 0;
foreach ($cart_items as $item) {
    $grand_total += $item['price'] * $item['quantity'];
}

$errors = [];
$shipping_address = '';
$card_name = '';
$card_number = '';
$card_expiry = '';
$card_cvv = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = sanitize_input($_POST);
    
    $shipping_address = $_POST['shipping_address'] ?? '';
    $card_name = $_POST['card_name'] ?? '';
    $card_number = $_POST['card_number'] ?? '';
    $card_expiry = $_POST['card_expiry'] ?? '';
    $card_cvv = $_POST['card_cvv'] ?? '';
    
    // Server-side Validations
    $req_fields = [
        'shipping_address' => 'Shipping Address',
        'card_name' => 'Cardholder Name',
        'card_number' => 'Credit Card Number',
        'card_expiry' => 'Expiration Date (MM/YY)',
        'card_cvv' => 'CVV Code'
    ];
    $errors = validate_required($_POST, $req_fields);
    
    // Validate Card digits (16 digits after stripping spaces)
    $clean_card = str_replace(' ', '', $card_number);
    if (empty($errors['card_number'])) {
        if (!preg_match('/^[0-9]{13,16}$/', $clean_card)) {
            $errors['card_number'] = "Please enter a valid credit card number (13-16 digits).";
        }
    }
    
    // Validate Expiry (MM/YY format)
    if (empty($errors['card_expiry'])) {
        if (!preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/', $card_expiry)) {
            $errors['card_expiry'] = "Must be in MM/YY format.";
        }
    }
    
    // Validate CVV (3 or 4 digits)
    if (empty($errors['card_cvv'])) {
        if (!preg_match('/^[0-9]{3,4}$/', $card_cvv)) {
            $errors['card_cvv'] = "Must be 3 or 4 digits.";
        }
    }
    
    // Transaction Stock Verification
    if (empty($errors)) {
        // Double check stock levels for each item
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
            
            // Mask card number for security (last 4 digits only)
            $masked_card = '**** **** **** ' . substr($clean_card, -4);
            
            // 1. Create order
            $order_stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, shipping_address, card_name, card_number) VALUES (?, ?, 'Pending', ?, ?, ?)");
            $order_stmt->execute([
                $user_id,
                $grand_total,
                $shipping_address,
                $card_name,
                $masked_card
            ]);
            
            $order_id = $pdo->lastInsertId();
            
            // 2. Insert order items & Decrement stock levels
            $item_stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, price, quantity) VALUES (?, ?, ?, ?)");
            $stock_stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            
            foreach ($cart_items as $item) {
                // Save item details
                $item_stmt->execute([
                    $order_id,
                    $item['id'],
                    $item['price'],
                    $item['quantity']
                ]);
                
                // Decrement inventory
                $stock_stmt->execute([
                    $item['quantity'],
                    $item['id']
                ]);
            }
            
            // 3. Clear user cart
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
?>

<div style="max-width: 900px; margin: 0 auto;">
    <h1 style="margin-bottom: 2rem;">Checkout</h1>
    
    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 3rem;">
        
        <!-- Billing / Payment Form -->
        <section class="admin-panel">
            <h3>Shipping &amp; Payment Details</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                * This is a simulated university checkout. Please do NOT enter real credit card numbers.
            </p>
            
            <form action="checkout.php" method="POST" novalidate>
                
                <?= html_textarea('shipping_address', $shipping_address, 'Shipping Address', 'Enter your delivery address', $errors) ?>
                
                <h4 style="margin-top: 1.5rem; margin-bottom: 1rem; font-family: 'Outfit', sans-serif; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">
                    Payment Method: Fake Credit Card
                </h4>
                
                <?= html_input('text', 'card_name', $card_name, 'Cardholder Name', 'e.g. John Doe', $errors) ?>
                <?= html_input('text', 'card_number', $card_number, 'Credit Card Number', '1234 5678 1234 5678', $errors, ['maxlength' => '19']) ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <?= html_input('text', 'card_expiry', $card_expiry, 'Expiration Date', 'MM/YY', $errors, ['maxlength' => '5']) ?>
                    <?= html_input('password', 'card_cvv', $card_cvv, 'CVV Code', '123', $errors, ['maxlength' => '4']) ?>
                </div>
                
                <button type="submit" class="btn btn-accent btn-block" style="margin-top: 1.5rem; text-align: center;">
                    Place Order ($<?= number_format($grand_total, 2) ?>)
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
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Qty: <?= $item['quantity'] ?> @ $<?= number_format($item['price'], 2) ?> each</span>
                        </div>
                        <span style="font-weight: 500;">$<?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <div style="margin-top: 1.5rem; font-size: 1.3rem; display: flex; justify-content: space-between; font-weight: 700; color: var(--primary-dark);">
                <span>Grand Total:</span>
                <span>$<?= number_format($grand_total, 2) ?></span>
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
