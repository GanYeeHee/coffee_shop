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

// The homepage hero is a full-bleed photo, so the nav starts transparent
// there and turns solid on scroll (see assets/js/app.js); every other page
// has no hero to sit over, so the nav is solid from the start.
$has_hero = ($current_page === 'index.php' && !$is_admin_dir);

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
    <title>TAR Coffee - Online Coffee Shop</title>
    
    <!-- Custom CSS (?v= busts the browser cache whenever the file changes) -->
    <link rel="stylesheet" href="<?= $base_path ?>assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>

<header id="site-header" class="<?= $has_hero ? 'nav-transparent' : '' ?>">
    <div class="nav-container">
        <div class="logo">
            <a href="<?= $base_path ?>index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 10h11v5a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-5z"/><path d="M17 11.5h1.2a2.3 2.3 0 0 1 0 4.6H17"/><path d="M9 5c-.5.8-.5 1.3 0 2.1M13 5c-.5.8-.5 1.3 0 2.1" stroke-width="1.2"/></svg>
                TAR C<em>offee</em>
            </a>
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
                    <li class="nav-item"><a href="<?= $base_path ?>admin/users.php" class="<?= trim(nav_active('users.php', true, $current_page, $is_admin_dir)) ?>">Users</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/orders.php" class="<?= trim(nav_active('orders.php', true, $current_page, $is_admin_dir)) ?>">Orders</a></li>
                    <li class="nav-item"><a href="<?= $base_path ?>admin/reports.php" class="<?= trim(nav_active('reports.php', true, $current_page, $is_admin_dir)) ?>">Reports</a></li>
                <?php elseif (is_member()): ?>
                    <!-- Member specific links -->
                    <li class="nav-item"><a href="<?= $base_path ?>orders.php" class="<?= trim(nav_active('orders.php', false, $current_page, $is_admin_dir)) ?>">My Orders</a></li>
                    <li class="nav-item">
                        <a href="<?= $base_path ?>cart.php" class="cart-link<?= nav_active('cart.php', false, $current_page, $is_admin_dir) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1.4 9.2a2 2 0 0 1-2 1.8H8.4a2 2 0 0 1-2-1.8L5 8z"/><path d="M8.5 8V6a3.5 3.5 0 0 1 7 0v2"/></svg>
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
                    <li class="nav-item"><a href="<?= $base_path ?>logout.php" class="nav-btn nav-btn-ghost">Logout</a></li>
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
