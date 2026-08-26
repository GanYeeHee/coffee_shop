-- ============================================================================
-- Incremental migration: Separate Admin Login (email OTP) + Super Admin role
-- ----------------------------------------------------------------------------
-- Run this ONCE against an existing `coffee_shop` database that was created
-- with an older schema.sql. If you are re-importing the full schema.sql you
-- do NOT need this file.
--
-- AFTER RUNNING:
--   * Log in at /WIS_Assignment/admin/login.php as  superadmin / super123
--   * Immediately change the super admin password (admin/admins.php > Reset Password)
--   * Change the super admin email to a real inbox so OTP codes are delivered:
--       UPDATE `users` SET `email` = 'you@example.com' WHERE `username` = 'superadmin';
-- ============================================================================

USE `coffee_shop`;

-- 1. Add the new top-tier role to the enum (existing rows are unaffected).
ALTER TABLE `users`
  MODIFY `role` ENUM('super_admin', 'admin', 'member') NOT NULL DEFAULT 'member';

-- 2. Storage for the 6-digit admin login codes (one active code per admin).
CREATE TABLE IF NOT EXISTS `admin_login_otps` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `code_hash` VARCHAR(255) NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Seed one super admin account (password: super123).
--    ON DUPLICATE KEY UPDATE keeps this script safe to re-run: it will not
--    clobber an existing account but will (re)assert the super_admin role.
INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `phone`, `photo`, `role`, `status`)
VALUES ('superadmin', 'superadmin@dailygrind.com',
        '$2y$10$5T88GslyJvXUoslF2B.WH.8mp1Eps148SrVe0rQ5rjzs/yoyGjn8K',
        'Super Administrator', '012-0000000', 'default_admin.png', 'super_admin', 'active')
ON DUPLICATE KEY UPDATE `role` = 'super_admin', `status` = 'active';
