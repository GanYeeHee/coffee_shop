<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the logged in user is an Admin.
 */
function is_admin() {
    return is_logged_in() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Check if the logged in user is a Member.
 */
function is_member() {
    return is_logged_in() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'member';
}

/**
 * Fetch details of the currently logged in user.
 */
function get_logged_in_user($pdo) {
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    // Auto-logout if user is blocked or deleted
    if (!$user || $user['status'] === 'blocked') {
        logout_user();
        return null;
    }
    return $user;
}

/**
 * Restrict page access to logged in users.
 */
function require_login() {
    if (!is_logged_in()) {
        // Detect path to redirect properly
        $redirect_to = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? '../login.php' : 'login.php';
        header("Location: " . $redirect_to);
        exit;
    }
}

/**
 * Restrict page access to admin users.
 */
function require_admin() {
    require_login();
    if (!is_admin()) {
        // Not an admin, redirect to public index
        $redirect_to = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? '../index.php' : 'index.php';
        header("Location: " . $redirect_to);
        exit;
    }
}

/**
 * Register user variables in session on login.
 */
function login_user($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
}

/**
 * Destroy session and logout.
 */
function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
?>
