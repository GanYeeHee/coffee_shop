<?php
// Toggle favorite/unfavorite endpoint
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
    || isset($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!is_logged_in()) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please log in to save favorites.', 'redirect' => 'login.php']);
        exit;
    }
    $_SESSION['flash_error'] = "Please log in to save favorites.";
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : (isset($_GET['product_id']) ? intval($_GET['product_id']) : 0);

if ($product_id <= 0) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }
    header("Location: index.php");
    exit;
}

// Check if product is already favorited by the current user
$check_stmt = $pdo->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND product_id = ?");
$check_stmt->execute([$user_id, $product_id]);
$existing = $check_stmt->fetch();

if ($existing) {
    // Already favorited: remove from favorites
    $del_stmt = $pdo->prepare("DELETE FROM user_favorites WHERE user_id = ? AND product_id = ?");
    $del_stmt->execute([$user_id, $product_id]);
    $is_favorited = false;
    $message = "Removed from your favorites.";
} else {
    // Not favorited: add to favorites
    $add_stmt = $pdo->prepare("INSERT INTO user_favorites (user_id, product_id) VALUES (?, ?)");
    $add_stmt->execute([$user_id, $product_id]);
    $is_favorited = true;
    $message = "Added to your favorites! ❤️";
}

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'is_favorited' => $is_favorited,
        'message' => $message
    ]);
    exit;
}

$_SESSION['flash_success'] = $message;
$redirect = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header("Location: " . $redirect);
exit;
