<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine relative paths based on folder depth
$is_admin_dir = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false);
$base_path = $is_admin_dir ? '../' : './';

// Determine current page for nav "active" state highlighting
$current_page = basename($_SERVER['SCRIPT_NAME']);
function nav_active(string $page, bool $in_admin_dir, string $current_page, bool $is_admin_dir): string {
    return ($in_admin_dir === $is_admin_dir && $current_page === $page) ? ' active' : '';
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/html_helpers.php';

// Fetch user status if logged in
$current_user = null;
if (is_logged_in()) {
    $current_user = get_logged_in_user($pdo);
}

// Calculate dynamic cart item count for members
$cart_count = 0;
if (is_logged_in() && is_member() && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT SUM(quantity) as total_qty FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_result = $stmt->fetch();
    $cart_count = intval($cart_result['total_qty'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Daily Grind - Online Coffee Shop</title>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= $base_path ?>assets/css/style.css">
</head>
<body>

<header>
    <div class="nav-container">
        <div class="logo">
            <a href="<?= $base_path ?>index.php">☕ The Daily <span>Grind</span></a>
        </div>
        
        <nav>
            <ul class="nav-menu">
                <!-- Public / Shared links -->
                <li class="nav-item"><a href="<?= $base_path ?>index.php" class="<?= trim(nav_active('index.php', false, $current_page, $is_admin_dir)) ?>">Home</a></li>

                <?php if (is_admin()): ?>
                    <!-- Admin specific links -->
                    <li class="nav-item"><a href="<?= $base_path ?>admin/index.php" class="<?= trim(nav_active('index.php', true, $current_page, $is_admin_dir)) ?>">Dashboard</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/categories.php" class="<?= trim(nav_active('categories.php', true, $current_page, $is_admin_dir)) ?>">Categories</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/products.php" class="<?= trim(nav_active('products.php', true, $current_page, $is_admin_dir)) ?>">Products</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/option_templates.php" class="<?= trim(nav_active('option_templates.php', true, $current_page, $is_admin_dir)) ?>">Option Templates</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/vouchers.php" class="<?= trim(nav_active('vouchers.php', true, $current_page, $is_admin_dir)) ?>">Vouchers</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/members.php" class="<?= trim(nav_active('members.php', true, $current_page, $is_admin_dir)) ?>">Members</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/orders.php" class="<?= trim(nav_active('orders.php', true, $current_page, $is_admin_dir)) ?>">Orders</a></li>
                <?php elseif (is_member()): ?>
                    <!-- Member specific links -->
                    <li class="nav-item"><a href="<?= $base_path ?>orders.php" class="<?= trim(nav_active('orders.php', false, $current_page, $is_admin_dir)) ?>">My Orders</a></li>
                    <li class="nav-item">
                        <a href="<?= $base_path ?>cart.php" class="cart-link<?= nav_active('cart.php', false, $current_page, $is_admin_dir) ?>">
                            Cart
                            <?php if ($cart_count > 0): ?>
                                <span class="cart-count"><?= $cart_count ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php if (is_logged_in() && $current_user): ?>
                    <!-- Profile and Logout -->
                    <li class="nav-profile">
                        <?php 
                        $avatar = !empty($current_user['photo']) ? $base_path . 'uploads/profiles/' . $current_user['photo'] : '';
                        if (!empty($current_user['photo']) && file_exists(__DIR__ . '/../uploads/profiles/' . $current_user['photo'])): 
                        ?>
                            <img src="<?= $avatar ?>" class="nav-avatar" alt="Avatar">
                        <?php else: ?>
                            <div class="nav-avatar" style="background-color: var(--primary-light); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">
                                <?= substr($current_user['username'], 0, 2) ?>
                            </div>
                        <?php endif; ?>
                        <a href="<?= $base_path ?>profile.php" style="color: var(--bg-cream); font-weight: 500;">
                            <?= htmlspecialchars($current_user['username']) ?>
                        </a>
                    </li>
                    <li class="nav-item"><a href="<?= $base_path ?>logout.php" class="nav-btn">Logout</a></li>
                <?php else: ?>
                    <!-- Guest links -->
                    <li class="nav-item"><a href="<?= $base_path ?>login.php">Login</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>register.php" class="nav-btn">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<main>
