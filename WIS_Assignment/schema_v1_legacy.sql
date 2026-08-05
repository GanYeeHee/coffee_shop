-- DEPRECATED: superseded by schema.sql (v2). Kept for historical/diff reference only. Do not import.
-- Database creation (optional but helpful for local importing)
CREATE DATABASE IF NOT EXISTS `coffee_shop`;
USE `coffee_shop`;

-- Drop tables if they exist to allow clean resets
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `cart`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

-- 1. Users Table
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` ENUM('admin', 'member') DEFAULT 'member',
  `photo` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active', 'blocked') DEFAULT 'active',
  `security_question` VARCHAR(255) NOT NULL,
  `security_answer` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. Password Reset Tokens Table (email-based reset)
CREATE TABLE `password_reset_tokens` (
  `token` VARCHAR(64) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Categories Table
CREATE TABLE `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Products Table
CREATE TABLE `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `photo` VARCHAR(255) DEFAULT NULL,
  `stock` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Cart Table (Persistent for logged-in members)
CREATE TABLE `cart` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Orders Table
CREATE TABLE `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `order_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `total_price` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('Pending', 'Processing', 'Completed', 'Cancelled') DEFAULT 'Pending',
  `shipping_address` TEXT NOT NULL,
  `card_name` VARCHAR(100) NOT NULL,
  `card_number` VARCHAR(20) NOT NULL -- Stored as masked (e.g., **** **** **** 1234)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Order Items Table
CREATE TABLE `order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `price` DECIMAL(10, 2) NOT NULL, -- Price at checkout
  `quantity` INT NOT NULL,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Sample Data
-- Passwords: admin123 (for admin), member123 (for member)
INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `phone`, `role`, `photo`, `status`, `security_question`, `security_answer`) VALUES
(1, 'admin', '$2y$10$IkPc6mTn6MXSXEA5LFViBOOgebY38yNN6QEnvCU8d3CntR9AYElwu', 'admin@dailygrind.com', 'Admin Manager', '012-3456789', 'admin', 'default_admin.png', 'active', 'What is your favorite coffee bean?', 'arabica'),
(2, 'member', '$2y$10$CRS02ruW77feav/qAostJ.B7BUXt66e0HEn6d/cNYfMo0sDI/dU7S', 'member@dailygrind.com', 'John Member', '019-8765432', 'member', 'default_member.png', 'active', 'What was the name of your first pet?', 'buddy');

INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Espresso & Hot Drinks', 'Freshly brewed hot coffees made from premium organic Arabica beans.'),
(2, 'Cold Brews & Iced Coffee', 'Slow-steeped cold beverages and refreshing ice-blended coffee specialties.'),
(3, 'Pastries & Desserts', 'Delicious oven-baked goods, croissants, and cakes to accompany your coffee.'),
(4, 'Coffee Beans & Tumblers', 'Take home our signature roasted blends or premium drinkware.');

INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `price`, `photo`, `stock`) VALUES
(1, 1, 'Caramel Macchiato', 'Freshly steamed milk with vanilla-flavored syrup, marked with espresso and topped with caramel drizzle.', 5.50, 'caramel_macchiato.jpg', 15),
(2, 1, 'Flat White', 'Smooth ristretto shots of espresso with perfect microfoam milk, creating a velvety texture.', 4.80, 'flat_white.jpg', 12),
(3, 2, 'Vanilla Sweet Cream Cold Brew', 'Slow-steeped cold brew coffee accented with vanilla and topped with a float of house-made sweet cream.', 5.20, 'vanilla_cold_brew.jpg', 3), -- Low stock!
(4, 2, 'Iced Matcha Latte', 'Smooth and creamy matcha sweetened and served over ice with choice of milk.', 5.00, 'iced_matcha.jpg', 20),
(5, 3, 'Butter Croissant', 'Classic French pastry with flakey, golden-brown crust and a buttery, soft interior.', 3.50, 'butter_croissant.jpg', 8),
(6, 3, 'Chocolate Chip Cookie', 'Rich and chewy cookie packed with premium dark chocolate chips, served warm.', 3.00, 'chocolate_cookie.jpg', 2), -- Low stock!
(7, 4, 'Signature Blend Beans (500g)', 'Our medium-dark roasted house blend beans featuring chocolatey and nutty flavor profiles.', 18.00, 'signature_beans.jpg', 10),
(8, 4, 'Stainless Steel Tumbler', 'Keep your coffee hot or iced for hours in this double-walled, vacuum-insulated matte black tumbler.', 15.00, 'stainless_tumbler.jpg', 5);
