<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$was_admin = is_admin();

logout_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['flash_success'] = "You have been logged out successfully.";
header("Location: " . ($was_admin ? "admin/login.php" : "login.php"));
exit;
?>
