<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Lockout policy: block login after this many consecutive failed attempts,
// for this many minutes.
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);

/**
 * Check if a user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the logged in user is an Admin (a plain admin OR a super admin -
 * super admins can reach everything a normal admin can).
 */
function is_admin() {
    return is_logged_in() && in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'], true);
}

/**
 * Check if the logged in user is a Super Admin (can manage other admin accounts).
 */
function is_super_admin() {
    return is_logged_in() && ($_SESSION['user_role'] ?? '') === 'super_admin';
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
        // "login.php" resolves within the current folder: admin-area pages land
        // on the dedicated /admin/login.php, public/member pages on /login.php.
        header("Location: login.php");
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
 * Restrict page access to super admin users (admin account management).
 * A plain admin is sent back to the dashboard with an explanatory flash.
 */
function require_super_admin() {
    require_admin();
    if (!is_super_admin()) {
        $_SESSION['flash_error'] = "That area is restricted to the super administrator.";
        $redirect_to = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? 'index.php' : 'admin/index.php';
        header("Location: " . $redirect_to);
        exit;
    }
}

/**
 * Check if a user's account is currently locked out from login attempts.
 */
function is_account_locked($user) {
    return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
}

/**
 * Get remaining lockout time in whole minutes (minimum 1).
 */
function get_lockout_minutes_remaining($user) {
    $seconds_left = strtotime($user['locked_until']) - time();
    return max(1, (int) ceil($seconds_left / 60));
}

/**
 * Record a failed login attempt. Locks the account once the attempt
 * threshold is reached. Returns the number of attempts remaining before
 * lockout (0 means the account just got locked).
 */
function record_failed_login($pdo, $user) {
    $attempts = $user['failed_attempts'] + 1;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $locked_until = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
        $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?");
        $stmt->execute([$locked_until, $user['id']]);
        return 0;
    }

    $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
    $stmt->execute([$attempts, $user['id']]);
    return MAX_LOGIN_ATTEMPTS - $attempts;
}

/**
 * Clear failed attempt count and lockout on successful login.
 */
function reset_login_attempts($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
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
