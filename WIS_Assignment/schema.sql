-- Database creation for testing environment
CREATE DATABASE IF NOT EXISTS `coffee_shop`;
USE `coffee_shop`;

-- --------------------------------------------------------
-- RESET DATABASE (Safe Dropping with Foreign Key Checks Disabled)
-- --------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `payments`;              
DROP TABLE IF EXISTS `order_items`;           
DROP TABLE IF EXISTS `orders`;                
DROP TABLE IF EXISTS `reviews`;               
DROP TABLE IF EXISTS `cart`;                  
DROP TABLE IF EXISTS `product_images`;         
DROP TABLE IF EXISTS `user_security_answers`; 
DROP TABLE IF EXISTS `user_addresses`;        
DROP TABLE IF EXISTS `user_details`;          -- Leftover table cleanup

DROP TABLE IF EXISTS `products`;              
DROP TABLE IF EXISTS `categories`;            
DROP TABLE IF EXISTS `vouchers`;              
DROP TABLE IF EXISTS `users`;                 

SET FOREIGN_KEY_CHECKS = 1;


-- --------------------------------------------------------
-- TABLE CREATION
-- --------------------------------------------------------

-- 1. Users Table (Merged Core Credentials & Profile Info)
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT 'default_user.png',
  `role` ENUM('admin', 'member') DEFAULT 'member',
  `status` ENUM('active', 'blocked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. User Addresses Table (1 User = Multiple Addresses)
CREATE TABLE `user_addresses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `address_label` VARCHAR(50) NOT NULL DEFAULT 'Home', 
  `recipient_name` VARCHAR(100) NOT NULL,            
  `recipient_phone` VARCHAR(20) NOT NULL,
  `address_line_1` VARCHAR(255) NOT NULL,
  `address_line_2` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `zip_code` VARCHAR(20) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,          
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  INDEX `idx_user_addresses_lookup` (`user_id`)        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. User Security Answers Table (Account Recovery Security)
CREATE TABLE `user_security_answers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `security_question` VARCHAR(255) NOT NULL,
  `answer_hash` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `user_question` (`user_id`, `security_question`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Discount Vouchers Table
CREATE TABLE `vouchers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `discount_percent` DECIMAL(5, 2) NOT NULL,
  `min_spend` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Categories Table
CREATE TABLE `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Products Table
CREATE TABLE `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `stock` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  INDEX `idx_products_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Product Images Table
CREATE TABLE `product_images` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Cart Table (Supports multiple unique customizations per product)
-- PHP BACKEND IMPLEMENTATION TIP:
-- Always pass an empty string ('') instead of NULL when a product has no customization:
-- Example: $customization = $_POST['customization'] ?? '';
CREATE TABLE `cart` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `customization` VARCHAR(255) NOT NULL DEFAULT '', 
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `user_product_custom` (`user_id`, `product_id`, `customization`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Product Ratings & Reviews Table
CREATE TABLE `reviews` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `rating` TINYINT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Orders Table
CREATE TABLE `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `voucher_id` INT DEFAULT NULL,
  `customer_name` VARCHAR(100) NOT NULL,
  `customer_phone` VARCHAR(20) NOT NULL,
  `fulfillment_type` ENUM('pickup', 'delivery') NOT NULL DEFAULT 'delivery',
  `shipping_address` TEXT DEFAULT NULL, 
  `discount_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('Pending', 'Processing', 'Completed', 'Cancelled') DEFAULT 'Pending',
  `order_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_fulfillment` (`fulfillment_type`), 
  INDEX `idx_orders_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Order Items Table
CREATE TABLE `order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `quantity` INT NOT NULL,
  `customization` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Payments Table
CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `payment_method` ENUM('card', 'e_wallet', 'cash') NOT NULL DEFAULT 'card',
  `transaction_id` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `payment_status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed',
  `paid_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------
-- INSERT SAMPLE DATA
-- --------------------------------------------------------

-- Users with merged profile info (Passwords: admin123 / member123)
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone`, `photo`, `role`, `status`) VALUES
(1, 'admin', 'admin@dailygrind.com', '$2y$10$IkPc6mTn6MXSXEA5LFViBOOgebY38yNN6QEnvCU8d3CntR9AYElwu', 'Admin Manager', '012-3456789', 'default_admin.png', 'admin', 'active'),
(2, 'member', 'member@dailygrind.com', '$2y$10$CRS02ruW77feav/qAostJ.B7BUXt66e0HEn6d/cNYfMo0sDI/dU7S', 'John Member', '019-8765432', 'default_member.png', 'member', 'active');

-- User Saved Addresses
INSERT INTO `user_addresses` (`id`, `user_id`, `address_label`, `recipient_name`, `recipient_phone`, `address_line_1`, `address_line_2`, `city`, `state`, `zip_code`, `is_default`) VALUES
(1, 2, 'Home', 'John Member', '019-8765432', '123 Coffee Lane', 'Suite 4B', 'Kuala Lumpur', 'WPKL', '50000', 1),
(2, 2, 'Office', 'John Member', '019-8765432', 'Level 12, Tech Tower', 'Jalan Sultan Ismail', 'Kuala Lumpur', 'WPKL', '50250', 0);

-- Security Answers
INSERT INTO `user_security_answers` (`id`, `user_id`, `security_question`, `answer_hash`) VALUES
(1, 1, 'What is your favorite coffee bean?', '$2y$10$e.wPqVdY/M6kG1CXZUo8uu1n7vI2fV5W.M9R5s8pGzE6pG3yD3m5O'),
(2, 2, 'What was the name of your first pet?', '$2y$10$8mP.kY8sU1M8a9eQ6sZ7..n7vI2fV5W.M9R5s8pGzE6pG3yD3m5O');

-- Vouchers
INSERT INTO `vouchers` (`id`, `code`, `discount_percent`, `min_spend`, `status`) VALUES
(1, 'COFFEE10', 10.00, 10.00, 'active'),
(2, 'WELCOME20', 20.00, 20.00, 'active');

-- Categories
INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Espresso & Hot Drinks', 'Freshly brewed hot coffees made from premium organic Arabica beans.'),
(2, 'Cold Brews & Iced Coffee', 'Slow-steeped cold beverages and refreshing ice-blended coffee specialties.'),
(3, 'Pastries & Desserts', 'Delicious oven-baked goods, croissants, and cakes to accompany your coffee.'),
(4, 'Coffee Beans & Tumblers', 'Take home our signature roasted blends or premium drinkware.');

-- Products
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `price`, `stock`) VALUES
(1, 1, 'Caramel Macchiato', 'Freshly steamed milk with vanilla-flavored syrup, marked with espresso and topped with caramel drizzle.', 5.50, 15),
(2, 1, 'Flat White', 'Smooth ristretto shots of espresso with perfect microfoam milk, creating a velvety texture.', 4.80, 12),
(3, 2, 'Vanilla Sweet Cream Cold Brew', 'Slow-steeped cold brew coffee accented with vanilla and topped with a float of house-made sweet cream.', 5.20, 3),
(4, 2, 'Iced Matcha Latte', 'Smooth and creamy matcha sweetened and served over ice with choice of milk.', 5.00, 20),
(5, 3, 'Butter Croissant', 'Classic French pastry with flakey, golden-brown crust and a buttery, soft interior.', 3.50, 8),
(6, 3, 'Chocolate Chip Cookie', 'Rich and chewy cookie packed with premium dark chocolate chips, served warm.', 3.00, 2),
(7, 4, 'Signature Blend Beans (500g)', 'Our medium-dark roasted house blend beans featuring chocolatey and nutty flavor profiles.', 18.00, 10),
(8, 4, 'Stainless Steel Tumbler', 'Keep your coffee hot or iced for hours in this double-walled, vacuum-insulated matte black tumbler.', 15.00, 5);

-- Product Images
INSERT INTO `product_images` (`product_id`, `image_path`, `is_primary`) VALUES
(1, 'caramel_macchiato.jpg', 1),
(1, 'caramel_macchiato_topview.jpg', 0),
(2, 'flat_white.jpg', 1),
(3, 'vanilla_cold_brew.jpg', 1),
(4, 'iced_matcha.jpg', 1),
(5, 'butter_croissant.jpg', 1),
(6, 'chocolate_cookie.jpg', 1),
(7, 'signature_beans.jpg', 1),
(8, 'stainless_tumbler.jpg', 1);

-- Active Shopping Cart Items
INSERT INTO `cart` (`user_id`, `product_id`, `quantity`, `customization`) VALUES
(2, 1, 1, 'Less Ice, Oat Milk'),
(2, 1, 1, 'Extra Shot, Almond Milk'),
(2, 5, 2, '');

-- Product Reviews
INSERT INTO `reviews` (`user_id`, `product_id`, `rating`, `comment`) VALUES
(2, 1, 5, 'Best caramel macchiato in town! Super smooth espresso and perfectly sweet.'),
(2, 3, 4, 'Very refreshing cold brew with delicious cream.');

-- Orders
INSERT INTO `orders` (`id`, `user_id`, `voucher_id`, `customer_name`, `customer_phone`, `fulfillment_type`, `shipping_address`, `discount_amount`, `total_price`, `status`) VALUES
(1, 2, 1, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 1.07, 9.63, 'Completed');

INSERT INTO `order_items` (`order_id`, `product_id`, `price`, `quantity`, `customization`) VALUES
(1, 1, 5.50, 1, 'Less Ice, Oat Milk'),
(1, 3, 5.20, 1, 'Regular Ice');

INSERT INTO `payments` (`order_id`, `payment_method`, `transaction_id`, `amount`, `payment_status`) VALUES
(1, 'card', 'TXN_9988776655', 9.63, 'completed');