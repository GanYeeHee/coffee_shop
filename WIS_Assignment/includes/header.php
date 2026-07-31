<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine relative paths based on folder depth
$is_admin_dir = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false);
$base_path = $is_admin_dir ? '../' : './';

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
                <li class="nav-item"><a href="<?= $base_path ?>index.php">Home</a></li>
                
                <?php if (is_admin()): ?>
                    <!-- Admin specific links -->
                    <li class="nav-item"><a href="<?= $base_path ?>admin/index.php">Dashboard</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/categories.php">Categories</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/products.php">Products</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/vouchers.php">Vouchers</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/members.php">Members</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/orders.php">Orders</a></li>
                <?php elseif (is_member()): ?>
                    <!-- Member specific links -->
                    <li class="nav-item"><a href="<?= $base_path ?>orders.php">My Orders</a></li>
                    <li class="nav-item">
                        <a href="<?= $base_path ?>cart.php" class="cart-link">
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
