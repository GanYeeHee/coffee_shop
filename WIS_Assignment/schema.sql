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
DROP TABLE IF EXISTS `product_option_values`;
DROP TABLE IF EXISTS `product_option_groups`;
DROP TABLE IF EXISTS `option_template_values`;
DROP TABLE IF EXISTS `option_templates`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `admin_login_otps`;
DROP TABLE IF EXISTS `password_reset_tokens`;
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
  `role` ENUM('super_admin', 'admin', 'member') DEFAULT 'member',
  `status` ENUM('active', 'blocked') DEFAULT 'active',
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
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

CREATE TABLE `password_reset_tokens` (
  `token` VARCHAR(64) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3b. Admin Login OTP Codes (6-digit second factor for the separate admin login at /admin/login.php).
-- One active code per admin; the row is replaced on each request and deleted once used.
CREATE TABLE `admin_login_otps` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `code_hash` VARCHAR(255) NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
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

-- 8. Option Templates (global reusable library, e.g. "Temperature", "Size", "Sugar Level", "Color" -
-- defined once with correct spelling/values, then opted into per product via checkboxes in admin)
CREATE TABLE `option_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Option Template Values (the choices within a reusable template)
CREATE TABLE `option_template_values` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT NOT NULL,
  `label` VARCHAR(50) NOT NULL,
  `price_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`template_id`) REFERENCES `option_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Product Option Groups (admin-defined per product, e.g. "Temperature", "Size", "Sugar Level".
-- Copied in from an option_template when admin checks that template's box for this product,
-- or created manually for a one-off group; template_id is NULL for manual groups)
CREATE TABLE `product_option_groups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `template_id` INT DEFAULT NULL,
  `name` VARCHAR(50) NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`template_id`) REFERENCES `option_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Product Option Values (selectable choices within a group, with optional price delta)
CREATE TABLE `product_option_values` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `group_id` INT NOT NULL,
  `label` VARCHAR(50) NOT NULL,
  `price_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`group_id`) REFERENCES `product_option_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Cart Table (Supports multiple unique customizations/option selections per product)
-- PHP BACKEND IMPLEMENTATION TIP:
-- Always pass an empty string ('') instead of NULL when a product has no customization:
-- Example: $customization = $_POST['customization'] ?? '';
CREATE TABLE `cart` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `customization` VARCHAR(255) NOT NULL DEFAULT '',
  `option_signature` VARCHAR(255) NOT NULL DEFAULT '',
  `options_summary` VARCHAR(500) NOT NULL DEFAULT '',
  `options_price_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `user_product_custom` (`user_id`, `product_id`, `customization`, `option_signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Product Ratings & Reviews Table
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

-- 14. Orders Table
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

-- 15. Order Items Table
CREATE TABLE `order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `quantity` INT NOT NULL,
  `customization` VARCHAR(255) DEFAULT NULL,
  `options_summary` VARCHAR(500) DEFAULT NULL,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Payments Table
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

-- Users with merged profile info (Passwords: admin123 / member123 / superadmin: CoffeeShop123)
-- The superadmin uses the shared project Gmail (same account as SMTP_USERNAME in
-- includes/mailer.php) so the admin-login OTP codes are actually delivered to an inbox.
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone`, `photo`, `role`, `status`) VALUES
(1, 'admin', 'admin@dailygrind.com', '$2y$10$IkPc6mTn6MXSXEA5LFViBOOgebY38yNN6QEnvCU8d3CntR9AYElwu', 'Admin Manager', '012-3456789', 'default_admin.png', 'admin', 'active'),
(2, 'member', 'member@dailygrind.com', '$2y$10$CRS02ruW77feav/qAostJ.B7BUXt66e0HEn6d/cNYfMo0sDI/dU7S', 'John Member', '019-8765432', 'default_member.png', 'member', 'active'),
(3, 'superadmin', 'coffeeshopadminacc@gmail.com', '$2y$10$1lwrOq2QD98nW19LRJETB.20uXg0RnX1/lUY.Kd0D2T6sjZjdHg7i', 'Super Administrator', '012-0000000', 'default_admin.png', 'super_admin', 'active');

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
(1, 'Coffee', 'Freshly brewed hot coffees and refreshing cold brews & iced coffee, all made from premium organic Arabica beans — choose your preferred temperature per drink.'),
(3, 'Pastries & Desserts', 'Delicious oven-baked goods, croissants, and cakes to accompany your coffee.'),
(4, 'Coffee Beans & Tumblers', 'Take home our signature roasted blends or premium drinkware.'),
(5, 'Non-Coffee Drinks', 'Teas, hot chocolate, milk-based lattes and iced refreshers - no espresso required.'),
(6, 'Breakfast & Savoury', 'Sandwiches, toasts, quiche and hot breakfast plates, made fresh to order.');

-- Products
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `price`, `stock`) VALUES
(1, 1, 'Caramel Macchiato', 'Freshly steamed milk with vanilla-flavored syrup, marked with espresso and topped with caramel drizzle.', 7.50, 15),
(2, 1, 'Flat White', 'Smooth ristretto shots of espresso with perfect microfoam milk, creating a velvety texture.', 6.80, 12),
(4, 5, 'Matcha Latte', 'Smooth and creamy matcha sweetened with choice of milk.', 7.00, 20),
(5, 3, 'Butter Croissant', 'Classic French pastry with flakey, golden-brown crust and a buttery, soft interior.', 5.50, 8),
(6, 3, 'Chocolate Chip Cookie', 'Rich and chewy cookie packed with premium dark chocolate chips, served warm.', 5.00, 2),
(7, 4, 'Signature Blend Beans (500g)', 'Our medium-dark roasted house blend beans featuring chocolatey and nutty flavor profiles.', 20.00, 10),
(8, 4, 'Stainless Steel Tumbler', 'Keep your coffee hot or iced for hours in this double-walled, vacuum-insulated matte black tumbler.', 17.00, 5),
(9, 1, 'Cappuccino', 'Espresso topped with steamed milk and a thick layer of foam, a coffeehouse classic.', 6.80, 15),
-- Coffee (category 1)
(10, 1, 'Espresso', 'A single concentrated shot pulled fresh, with a thick golden crema and a bold, full flavour.', 5.80, 30),
(11, 1, 'Doppio', 'A double shot of espresso for mornings that need an extra kick.', 6.50, 30),
(12, 1, 'Americano', 'Espresso lengthened with hot water for a smooth, full-bodied black coffee.', 6.50, 25),
(13, 1, 'Cafe Latte', 'Espresso with plenty of steamed milk and a light layer of silky foam.', 7.00, 25),
(14, 1, 'Mocha', 'Espresso and steamed milk blended with rich chocolate, finished with whipped cream.', 7.80, 20),
(15, 1, 'Vanilla Latte', 'A classic latte sweetened with real vanilla syrup.', 7.50, 20),
(16, 1, 'Hazelnut Latte', 'A smooth latte with warm, nutty hazelnut flavour throughout.', 7.80, 20),
(17, 1, 'Spanish Latte', 'Espresso with milk and condensed milk for a sweet, creamy finish.', 8.00, 20),
(18, 1, 'Cortado', 'Equal parts espresso and warm milk, served short to keep the coffee forward.', 6.80, 20),
(19, 1, 'Affogato', 'A scoop of vanilla ice cream drowned in a hot shot of espresso.', 8.50, 15),
(20, 1, 'Cold Brew', 'Steeped for 18 hours for a naturally sweet, low-acidity iced coffee.', 8.00, 20),
(21, 1, 'Iced Shaken Espresso', 'Espresso shaken hard over ice with a splash of milk for a frothy chill.', 8.50, 20),
-- Pastries & Desserts (category 3)
(22, 3, 'Pain au Chocolat', 'Buttery, flaky pastry wrapped around two batons of dark chocolate.', 6.50, 12),
(23, 3, 'Almond Croissant', 'Croissant filled with almond cream and topped with toasted almond flakes.', 7.00, 12),
(24, 3, 'Cinnamon Roll', 'A soft swirled roll with cinnamon sugar and a cream cheese glaze.', 7.50, 10),
(25, 3, 'Blueberry Muffin', 'A moist muffin loaded with juicy blueberries and a crunchy sugar top.', 6.50, 12),
(26, 3, 'Banana Walnut Bread', 'A thick slice of moist banana bread studded with toasted walnuts.', 6.00, 10),
(27, 3, 'Carrot Cake Slice', 'Spiced carrot cake layered with smooth cream cheese frosting.', 8.50, 8),
(28, 3, 'New York Cheesecake', 'Dense, creamy baked cheesecake on a buttery biscuit base.', 9.50, 8),
(29, 3, 'Chocolate Fudge Brownie', 'A rich, gooey brownie with a delicate crackly top.', 6.50, 15),
(30, 3, 'Red Velvet Cake Slice', 'Cocoa-kissed sponge with a tangy cream cheese frosting.', 9.00, 8),
(31, 3, 'Lemon Tart', 'A crisp pastry shell filled with sharp, silky lemon curd.', 8.00, 8),
(32, 3, 'Portuguese Egg Tart', 'A warm flaky tart with a caramelised custard centre.', 5.50, 20),
(33, 3, 'Scone with Jam & Cream', 'A classic buttermilk scone served with strawberry jam and clotted cream.', 7.50, 10),
(34, 3, 'Tiramisu Cup', 'Coffee-soaked sponge layered with mascarpone cream and cocoa.', 9.50, 8),
(35, 3, 'Double Chocolate Cookie', 'A chewy cocoa cookie packed with melting chocolate chunks.', 5.50, 20),
-- Coffee Beans & Tumblers (category 4)
(36, 4, 'House Blend Beans (250g)', 'An everyday medium roast with notes of chocolate and caramel.', 14.00, 25),
(37, 4, 'Ethiopia Yirgacheffe (250g)', 'A single origin light roast with bright floral and citrus notes.', 24.00, 15),
(38, 4, 'Colombia Supremo (250g)', 'A single origin medium roast, balanced with a smooth nutty sweetness.', 22.00, 15),
(39, 4, 'Brazil Santos (250g)', 'A single origin bean, low in acidity with a heavy chocolatey body.', 20.00, 15),
(40, 4, 'Dark Roast Espresso Beans (500g)', 'Bold, smoky beans roasted dark for rich espresso extraction.', 21.00, 20),
(41, 4, 'Decaf Blend Beans (250g)', 'Swiss Water decaffeinated beans with a full, rounded flavour.', 18.00, 15),
(42, 4, 'Cold Brew Coarse Grind (500g)', 'A coarse-ground blend made for smooth, low-acid cold brewing.', 22.00, 15),
(43, 4, 'Drip Coffee Bags (10-pack)', 'Single-serve pour-over bags for good coffee anywhere.', 17.00, 30),
(44, 4, 'Ceramic Coffee Mug (350ml)', 'A stoneware mug with a comfortable handle, microwave and dishwasher safe.', 20.00, 20),
(45, 4, 'Double-Wall Glass Cup (250ml)', 'Insulated borosilicate glass that keeps drinks hot without burning your hands.', 24.00, 20),
(46, 4, 'Bamboo Fiber Reusable Cup (400ml)', 'A lightweight eco cup with a silicone lid and protective sleeve.', 27.00, 20),
(47, 4, 'Insulated Travel Flask (500ml)', 'A vacuum flask that holds temperature for up to 12 hours.', 47.00, 15),
(48, 4, 'Cold Cup Tumbler with Straw (700ml)', 'A large iced-drink tumbler with a reusable straw.', 30.00, 20),
(49, 4, 'French Press (600ml)', 'A stainless steel press for full-bodied, sediment-rich home brewing.', 57.00, 10),
(50, 4, 'V60 Pour-Over Dripper Set', 'A ceramic cone dripper supplied with 40 paper filters.', 50.00, 10),
-- Non-Coffee Drinks (category 5)
(51, 5, 'Hot Chocolate', 'Rich Belgian chocolate melted into steamed milk, dusted with cocoa.', 7.00, 25),
(52, 5, 'Chai Latte', 'Spiced black tea brewed with steamed milk, cinnamon and cardamom.', 7.00, 25),
(53, 5, 'Hojicha Latte', 'Roasted Japanese green tea with a nutty, toasty flavour and milk.', 7.50, 20),
(54, 5, 'Taro Latte', 'Creamy taro root blended with milk, naturally sweet and earthy.', 7.50, 20),
(55, 5, 'Strawberry Milk', 'Cold fresh milk blended with real strawberry puree.', 7.00, 20),
(56, 5, 'Earl Grey Tea', 'Black tea scented with bergamot, served by the pot.', 5.50, 30),
(57, 5, 'English Breakfast Tea', 'A full-bodied classic black tea, best with a splash of milk.', 5.50, 30),
(58, 5, 'Jasmine Green Tea', 'Fragrant green tea with a natural jasmine blossom aroma.', 5.50, 30),
(59, 5, 'Chamomile Herbal Tea', 'A caffeine-free floral infusion, soothing and light.', 5.50, 25),
(60, 5, 'Peppermint Herbal Tea', 'Caffeine-free and crisp, made from pure peppermint leaves.', 5.50, 25),
(61, 5, 'Ginger Honey Tea', 'Fresh ginger steeped with honey and a squeeze of lemon.', 6.50, 20),
(62, 5, 'Iced Lemon Tea', 'Freshly brewed black tea over ice with fresh lemon.', 6.00, 25),
(63, 5, 'Iced Peach Tea', 'Black tea with sweet peach, served iced.', 6.50, 25),
(64, 5, 'Fresh Orange Juice', 'Cold-pressed oranges, with nothing else added.', 8.00, 15),
(65, 5, 'Sparkling Yuzu Soda', 'Sparkling water with tangy, aromatic Japanese yuzu.', 7.50, 15),
(66, 5, 'Babyccino', 'A small cup of frothy steamed milk with a cocoa dusting, made for kids.', 3.50, 20),
-- Breakfast & Savoury (category 6)
(67, 6, 'Ham & Cheese Croissant', 'A fresh croissant filled with shaved ham, Swiss cheese and Dijon butter.', 12.00, 15),
(68, 6, 'Chicken & Mushroom Quiche', 'A buttery short-crust tart with chicken, mushroom and egg custard.', 11.00, 12),
(69, 6, 'Smashed Avocado Toast', 'Sourdough topped with smashed avocado, chilli flakes, lemon and olive oil.', 14.00, 12),
(70, 6, 'Smoked Salmon Bagel', 'A toasted bagel with cream cheese, smoked salmon, capers and red onion.', 16.00, 10),
(71, 6, 'Tuna Melt Sandwich', 'Grilled sourdough with tuna mayo and melted cheddar.', 13.00, 12),
(72, 6, 'Egg Mayo Sandwich', 'Soft white bread with creamy free-range egg mayo.', 9.00, 15),
(73, 6, 'Chicken Pesto Panini', 'Pressed ciabatta with grilled chicken, basil pesto and mozzarella.', 14.00, 12),
(74, 6, 'Croque Monsieur', 'A baked ham and cheese sandwich topped with bechamel sauce.', 15.00, 10),
(75, 6, 'Breakfast Wrap', 'Scrambled egg, chicken sausage, cheese and hash brown rolled in a tortilla.', 13.00, 12),
(76, 6, 'Big Breakfast Plate', 'Eggs, chicken sausage, grilled tomato, mushroom, hash brown and toast.', 22.00, 10),
(77, 6, 'Shakshuka', 'Poached eggs in a spiced tomato and pepper sauce, served with bread.', 18.00, 10),
(78, 6, 'Mushroom Soup with Toast', 'Creamy roasted mushroom soup served with garlic toast.', 10.00, 12),
(79, 6, 'Caesar Salad Bowl', 'Romaine, parmesan and croutons tossed in a classic Caesar dressing.', 15.00, 12),
(80, 6, 'Beef Sliders (2 pcs)', 'Two mini beef burgers with cheddar and caramelised onion.', 16.00, 10),
(81, 6, 'Sausage Roll', 'Flaky puff pastry wrapped around a seasoned chicken sausage.', 8.00, 15),
(82, 6, 'Pancake Stack', 'Three fluffy buttermilk pancakes with maple syrup and butter.', 15.00, 10);

-- Product Images
INSERT INTO `product_images` (`product_id`, `image_path`, `is_primary`) VALUES
(1, 'caramel_macchiato.jpg', 1),
(1, 'caramel_macchiato_topview.jpg', 0),
(2, 'flat_white.jpg', 1),
(4, 'iced_matcha.jpg', 1),
(5, 'butter_croissant.jpg', 1),
(6, 'chocolate_cookie.jpg', 1),
(7, 'signature_beans.jpg', 1),
(8, 'stainless_tumbler.jpg', 1);

-- Option Templates: the reusable library admin can opt individual products into via checkboxes,
-- instead of retyping (and mistyping) the same option sets on every product.
INSERT INTO `option_templates` (`id`, `name`, `is_required`) VALUES
(1, 'Temperature', 1),
(2, 'Size', 1),
(3, 'Color', 0),
(4, 'Sugar Level', 0);

INSERT INTO `option_template_values` (`template_id`, `label`, `price_delta`) VALUES
(1, 'Hot', 0.00),
(1, 'Iced', 0.00),
(2, '12oz', 0.00),
(2, '16oz', 2.00),
(3, 'Matte Black', 0.00),
(3, 'White', 0.00),
(4, 'Less Sugar', 0.00),
(4, 'Normal Sugar', 0.00);

-- Product Option Groups & Values (demonstrates per-product flexibility: every Coffee-category
-- drink gets required Temperature/Size choices plus an optional Sugar Level, copied from the
-- templates above, merchandise like the tumbler gets an optional Color choice, and non-drink
-- items like pastries/beans have no options at all)
INSERT INTO `product_option_groups` (`id`, `product_id`, `template_id`, `name`, `is_required`) VALUES
(1, 1, 1, 'Temperature', 1),
(2, 1, 2, 'Size', 1),
(3, 8, 3, 'Color', 0),
(4, 1, 4, 'Sugar Level', 0),
(5, 2, 1, 'Temperature', 1),
(6, 2, 2, 'Size', 1),
(7, 2, 4, 'Sugar Level', 0),
(8, 4, 1, 'Temperature', 1),
(9, 4, 2, 'Size', 1),
(10, 4, 4, 'Sugar Level', 0),
(11, 9, 1, 'Temperature', 1),
(12, 9, 2, 'Size', 1),
(13, 9, 4, 'Sugar Level', 0);

INSERT INTO `product_option_values` (`group_id`, `label`, `price_delta`) VALUES
(1, 'Hot', 0.00),
(1, 'Iced', 0.00),
(2, '12oz', 0.00),
(2, '16oz', 2.00),
(3, 'Matte Black', 0.00),
(3, 'White', 0.00),
(4, 'Less Sugar', 0.00),
(4, 'Normal Sugar', 0.00),
(5, 'Hot', 0.00),
(5, 'Iced', 0.00),
(6, '12oz', 0.00),
(6, '16oz', 2.00),
(7, 'Less Sugar', 0.00),
(7, 'Normal Sugar', 0.00),
(8, 'Hot', 0.00),
(8, 'Iced', 0.00),
(9, '12oz', 0.00),
(9, '16oz', 2.00),
(10, 'Less Sugar', 0.00),
(10, 'Normal Sugar', 0.00),
(11, 'Hot', 0.00),
(11, 'Iced', 0.00),
(12, '12oz', 0.00),
(12, '16oz', 2.00),
(13, 'Less Sugar', 0.00),
(13, 'Normal Sugar', 0.00);

-- New drink option groups (ids 14-75): Temperature/Size/Sugar Level, copied from templates 1/2/4.
-- Coffee drinks 12-18 and non-coffee drinks 51-61 get the full Temperature + Size + Sugar Level set;
-- iced-only drinks 20, 21, 62, 63 get Size + Sugar Level.
INSERT INTO `product_option_groups` (`id`, `product_id`, `template_id`, `name`, `is_required`) VALUES
(14, 12, 1, 'Temperature', 1),
(15, 12, 2, 'Size', 1),
(16, 12, 4, 'Sugar Level', 0),
(17, 13, 1, 'Temperature', 1),
(18, 13, 2, 'Size', 1),
(19, 13, 4, 'Sugar Level', 0),
(20, 14, 1, 'Temperature', 1),
(21, 14, 2, 'Size', 1),
(22, 14, 4, 'Sugar Level', 0),
(23, 15, 1, 'Temperature', 1),
(24, 15, 2, 'Size', 1),
(25, 15, 4, 'Sugar Level', 0),
(26, 16, 1, 'Temperature', 1),
(27, 16, 2, 'Size', 1),
(28, 16, 4, 'Sugar Level', 0),
(29, 17, 1, 'Temperature', 1),
(30, 17, 2, 'Size', 1),
(31, 17, 4, 'Sugar Level', 0),
(32, 18, 1, 'Temperature', 1),
(33, 18, 2, 'Size', 1),
(34, 18, 4, 'Sugar Level', 0),
(35, 51, 1, 'Temperature', 1),
(36, 51, 2, 'Size', 1),
(37, 51, 4, 'Sugar Level', 0),
(38, 52, 1, 'Temperature', 1),
(39, 52, 2, 'Size', 1),
(40, 52, 4, 'Sugar Level', 0),
(41, 53, 1, 'Temperature', 1),
(42, 53, 2, 'Size', 1),
(43, 53, 4, 'Sugar Level', 0),
(44, 54, 1, 'Temperature', 1),
(45, 54, 2, 'Size', 1),
(46, 54, 4, 'Sugar Level', 0),
(47, 55, 1, 'Temperature', 1),
(48, 55, 2, 'Size', 1),
(49, 55, 4, 'Sugar Level', 0),
(50, 56, 1, 'Temperature', 1),
(51, 56, 2, 'Size', 1),
(52, 56, 4, 'Sugar Level', 0),
(53, 57, 1, 'Temperature', 1),
(54, 57, 2, 'Size', 1),
(55, 57, 4, 'Sugar Level', 0),
(56, 58, 1, 'Temperature', 1),
(57, 58, 2, 'Size', 1),
(58, 58, 4, 'Sugar Level', 0),
(59, 59, 1, 'Temperature', 1),
(60, 59, 2, 'Size', 1),
(61, 59, 4, 'Sugar Level', 0),
(62, 60, 1, 'Temperature', 1),
(63, 60, 2, 'Size', 1),
(64, 60, 4, 'Sugar Level', 0),
(65, 61, 1, 'Temperature', 1),
(66, 61, 2, 'Size', 1),
(67, 61, 4, 'Sugar Level', 0),
(68, 20, 2, 'Size', 1),
(69, 20, 4, 'Sugar Level', 0),
(70, 21, 2, 'Size', 1),
(71, 21, 4, 'Sugar Level', 0),
(72, 62, 2, 'Size', 1),
(73, 62, 4, 'Sugar Level', 0),
(74, 63, 2, 'Size', 1),
(75, 63, 4, 'Sugar Level', 0);

-- New drink option values (124 rows)
INSERT INTO `product_option_values` (`group_id`, `label`, `price_delta`) VALUES
(14, 'Hot', 0.00),
(14, 'Iced', 0.00),
(15, '12oz', 0.00),
(15, '16oz', 2.00),
(16, 'Less Sugar', 0.00),
(16, 'Normal Sugar', 0.00),
(17, 'Hot', 0.00),
(17, 'Iced', 0.00),
(18, '12oz', 0.00),
(18, '16oz', 2.00),
(19, 'Less Sugar', 0.00),
(19, 'Normal Sugar', 0.00),
(20, 'Hot', 0.00),
(20, 'Iced', 0.00),
(21, '12oz', 0.00),
(21, '16oz', 2.00),
(22, 'Less Sugar', 0.00),
(22, 'Normal Sugar', 0.00),
(23, 'Hot', 0.00),
(23, 'Iced', 0.00),
(24, '12oz', 0.00),
(24, '16oz', 2.00),
(25, 'Less Sugar', 0.00),
(25, 'Normal Sugar', 0.00),
(26, 'Hot', 0.00),
(26, 'Iced', 0.00),
(27, '12oz', 0.00),
(27, '16oz', 2.00),
(28, 'Less Sugar', 0.00),
(28, 'Normal Sugar', 0.00),
(29, 'Hot', 0.00),
(29, 'Iced', 0.00),
(30, '12oz', 0.00),
(30, '16oz', 2.00),
(31, 'Less Sugar', 0.00),
(31, 'Normal Sugar', 0.00),
(32, 'Hot', 0.00),
(32, 'Iced', 0.00),
(33, '12oz', 0.00),
(33, '16oz', 2.00),
(34, 'Less Sugar', 0.00),
(34, 'Normal Sugar', 0.00),
(35, 'Hot', 0.00),
(35, 'Iced', 0.00),
(36, '12oz', 0.00),
(36, '16oz', 2.00),
(37, 'Less Sugar', 0.00),
(37, 'Normal Sugar', 0.00),
(38, 'Hot', 0.00),
(38, 'Iced', 0.00),
(39, '12oz', 0.00),
(39, '16oz', 2.00),
(40, 'Less Sugar', 0.00),
(40, 'Normal Sugar', 0.00),
(41, 'Hot', 0.00),
(41, 'Iced', 0.00),
(42, '12oz', 0.00),
(42, '16oz', 2.00),
(43, 'Less Sugar', 0.00),
(43, 'Normal Sugar', 0.00),
(44, 'Hot', 0.00),
(44, 'Iced', 0.00),
(45, '12oz', 0.00),
(45, '16oz', 2.00),
(46, 'Less Sugar', 0.00),
(46, 'Normal Sugar', 0.00),
(47, 'Hot', 0.00),
(47, 'Iced', 0.00),
(48, '12oz', 0.00),
(48, '16oz', 2.00),
(49, 'Less Sugar', 0.00),
(49, 'Normal Sugar', 0.00),
(50, 'Hot', 0.00),
(50, 'Iced', 0.00),
(51, '12oz', 0.00),
(51, '16oz', 2.00),
(52, 'Less Sugar', 0.00),
(52, 'Normal Sugar', 0.00),
(53, 'Hot', 0.00),
(53, 'Iced', 0.00),
(54, '12oz', 0.00),
(54, '16oz', 2.00),
(55, 'Less Sugar', 0.00),
(55, 'Normal Sugar', 0.00),
(56, 'Hot', 0.00),
(56, 'Iced', 0.00),
(57, '12oz', 0.00),
(57, '16oz', 2.00),
(58, 'Less Sugar', 0.00),
(58, 'Normal Sugar', 0.00),
(59, 'Hot', 0.00),
(59, 'Iced', 0.00),
(60, '12oz', 0.00),
(60, '16oz', 2.00),
(61, 'Less Sugar', 0.00),
(61, 'Normal Sugar', 0.00),
(62, 'Hot', 0.00),
(62, 'Iced', 0.00),
(63, '12oz', 0.00),
(63, '16oz', 2.00),
(64, 'Less Sugar', 0.00),
(64, 'Normal Sugar', 0.00),
(65, 'Hot', 0.00),
(65, 'Iced', 0.00),
(66, '12oz', 0.00),
(66, '16oz', 2.00),
(67, 'Less Sugar', 0.00),
(67, 'Normal Sugar', 0.00),
(68, '12oz', 0.00),
(68, '16oz', 2.00),
(69, 'Less Sugar', 0.00),
(69, 'Normal Sugar', 0.00),
(70, '12oz', 0.00),
(70, '16oz', 2.00),
(71, 'Less Sugar', 0.00),
(71, 'Normal Sugar', 0.00),
(72, '12oz', 0.00),
(72, '16oz', 2.00),
(73, 'Less Sugar', 0.00),
(73, 'Normal Sugar', 0.00),
(74, '12oz', 0.00),
(74, '16oz', 2.00),
(75, 'Less Sugar', 0.00),
(75, 'Normal Sugar', 0.00);

-- Active Shopping Cart Items
INSERT INTO `cart` (`user_id`, `product_id`, `quantity`, `customization`) VALUES
(2, 1, 1, 'Less Ice, Oat Milk'),
(2, 1, 1, 'Extra Shot, Almond Milk'),
(2, 5, 2, '');

-- Product Reviews
INSERT INTO `reviews` (`user_id`, `product_id`, `rating`, `comment`) VALUES
(2, 1, 5, 'Best caramel macchiato in town! Super smooth espresso and perfectly sweet.'),
(2, 9, 5, 'Perfect foam every time. My regular morning order.'),
(2, 5, 4, 'Buttery and flaky, though it sells out fast in the mornings.'),
(2, 13, 5, 'Silky and well balanced - a proper cafe latte.'),
(2, 20, 4, 'Really smooth cold brew, not bitter at all. Wish the cup was bigger.'),
(2, 67, 5, 'Generous filling and the croissant stays crisp. Great value.'),
(2, 29, 3, 'Tasty but a little dry the day I got it.');

-- Orders
INSERT INTO `orders` (`id`, `user_id`, `voucher_id`, `customer_name`, `customer_phone`, `fulfillment_type`, `shipping_address`, `discount_amount`, `total_price`, `status`) VALUES
(1, 2, 1, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 1.07, 9.63, 'Completed');

INSERT INTO `order_items` (`order_id`, `product_id`, `price`, `quantity`, `customization`) VALUES
(1, 1, 5.50, 1, 'Less Ice, Oat Milk'),
(1, NULL, 5.20, 1, 'Regular Ice');

INSERT INTO `payments` (`order_id`, `payment_method`, `transaction_id`, `amount`, `payment_status`) VALUES
(1, 'card', 'TXN_9988776655', 9.63, 'completed');

-- Seeded order history (ids 2-19) - spread across ~8 weeks so the reports pages and the
-- storefront "Best Sellers" list (top 5 by units sold, excluding Cancelled) have real data.
INSERT INTO `orders` (`id`, `user_id`, `voucher_id`, `customer_name`, `customer_phone`, `fulfillment_type`, `shipping_address`, `discount_amount`, `total_price`, `status`, `order_date`) VALUES
(2, 2, NULL, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 0.00, 20.50, 'Completed', '2026-06-30 08:45:00'),
(3, NULL, NULL, 'Sarah Lim', '012-3344556', 'pickup', NULL, 0.00, 20.10, 'Completed', '2026-07-02 09:15:00'),
(4, 2, 1, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 2.90, 26.10, 'Completed', '2026-07-05 14:20:00'),
(5, 2, NULL, 'John Member', '019-8765432', 'pickup', NULL, 0.00, 25.30, 'Completed', '2026-07-07 07:55:00'),
(6, NULL, NULL, 'David Tan', '013-5566778', 'delivery', '88 Jalan Ampang, Kuala Lumpur, WPKL, 50450', 0.00, 28.00, 'Completed', '2026-07-10 16:05:00'),
(7, 2, 2, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 6.40, 25.60, 'Completed', '2026-07-12 10:30:00'),
(8, 2, NULL, 'John Member', '019-8765432', 'pickup', NULL, 0.00, 27.10, 'Completed', '2026-07-15 13:10:00'),
(9, NULL, NULL, 'Priya Nair', '017-2233445', 'delivery', '88 Jalan Ampang, Kuala Lumpur, WPKL, 50450', 0.00, 23.30, 'Completed', '2026-07-18 08:20:00'),
(10, 2, NULL, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 0.00, 29.50, 'Completed', '2026-07-21 15:40:00'),
(11, 2, NULL, 'John Member', '019-8765432', 'pickup', NULL, 0.00, 18.60, 'Cancelled', '2026-07-24 12:00:00'),
(12, 2, NULL, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 0.00, 34.60, 'Completed', '2026-07-27 09:50:00'),
(13, NULL, NULL, 'Aaron Koh', '014-6677889', 'pickup', NULL, 0.00, 20.50, 'Completed', '2026-07-30 11:25:00'),
(14, 2, 1, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 3.10, 27.90, 'Completed', '2026-08-02 17:15:00'),
(15, 2, NULL, 'John Member', '019-8765432', 'pickup', NULL, 0.00, 25.40, 'Completed', '2026-08-06 08:35:00'),
(16, 2, NULL, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 0.00, 27.50, 'Completed', '2026-08-10 14:45:00'),
(17, NULL, NULL, 'Nurul Aziz', '018-9988776', 'delivery', '88 Jalan Ampang, Kuala Lumpur, WPKL, 50450', 0.00, 21.00, 'Cancelled', '2026-08-14 19:05:00'),
(18, 2, NULL, 'John Member', '019-8765432', 'pickup', NULL, 0.00, 25.30, 'Pending', '2026-08-18 07:40:00'),
(19, 2, NULL, 'John Member', '019-8765432', 'delivery', '123 Coffee Lane, Suite 4B, Kuala Lumpur, WPKL, 50000', 0.00, 35.50, 'Processing', '2026-08-22 12:30:00');

INSERT INTO `order_items` (`order_id`, `product_id`, `price`, `quantity`, `customization`, `options_summary`) VALUES
(2, 1, 7.50, 2, 'Less ice', 'Temperature: Iced, Size: 12oz'),
(2, 5, 5.50, 1, NULL, NULL),
(3, 9, 6.80, 2, NULL, NULL),
(3, 29, 6.50, 1, NULL, NULL),
(4, 13, 7.00, 3, NULL, 'Temperature: Hot, Size: 12oz'),
(4, 20, 8.00, 1, NULL, 'Size: 16oz'),
(5, 1, 7.50, 1, NULL, NULL),
(5, 9, 6.80, 1, NULL, NULL),
(5, 5, 5.50, 2, NULL, NULL),
(6, 20, 8.00, 2, NULL, NULL),
(6, 67, 12.00, 1, NULL, NULL),
(7, 1, 7.50, 3, 'Oat milk', NULL),
(7, 34, 9.50, 1, NULL, NULL),
(8, 9, 6.80, 2, NULL, NULL),
(8, 13, 7.00, 1, NULL, NULL),
(8, 22, 6.50, 1, NULL, NULL),
(9, 5, 5.50, 3, NULL, NULL),
(9, 2, 6.80, 1, NULL, NULL),
(10, 1, 7.50, 2, NULL, NULL),
(10, 20, 8.00, 1, NULL, NULL),
(10, 25, 6.50, 1, NULL, NULL),
(11, 9, 6.80, 2, NULL, NULL),
(11, NULL, 5.00, 1, 'Discontinued item', NULL),
(12, 13, 7.00, 3, NULL, NULL),
(12, 9, 6.80, 2, NULL, NULL),
(13, 1, 7.50, 2, NULL, NULL),
(13, 5, 5.50, 1, NULL, NULL),
(14, 20, 8.00, 2, NULL, NULL),
(14, 13, 7.00, 1, NULL, NULL),
(14, 81, 8.00, 1, NULL, NULL),
(15, 9, 6.80, 3, NULL, NULL),
(15, 6, 5.00, 1, NULL, NULL),
(16, 1, 7.50, 2, NULL, NULL),
(16, 13, 7.00, 1, NULL, NULL),
(16, 5, 5.50, 1, NULL, NULL),
(17, NULL, 7.00, 2, 'Removed from menu', NULL),
(17, 51, 7.00, 1, NULL, NULL),
(18, 1, 7.50, 1, NULL, NULL),
(18, 9, 6.80, 1, NULL, NULL),
(18, 5, 5.50, 2, NULL, NULL),
(19, 20, 8.00, 2, NULL, NULL),
(19, 1, 7.50, 1, NULL, NULL),
(19, 67, 12.00, 1, NULL, NULL);

INSERT INTO `payments` (`order_id`, `payment_method`, `transaction_id`, `amount`, `payment_status`) VALUES
(2, 'card', '**** **** **** 4002', 20.50, 'completed'),
(3, 'e_wallet', 'EWALLET_0000333333', 20.10, 'completed'),
(4, 'card', '**** **** **** 4004', 26.10, 'completed'),
(5, 'cash', NULL, 25.30, 'pending'),
(6, 'card', '**** **** **** 4006', 28.00, 'completed'),
(7, 'e_wallet', 'EWALLET_0000777777', 25.60, 'completed'),
(8, 'card', '**** **** **** 4008', 27.10, 'completed'),
(9, 'card', '**** **** **** 4009', 23.30, 'completed'),
(10, 'card', '**** **** **** 4010', 29.50, 'completed'),
(11, 'cash', NULL, 18.60, 'pending'),
(12, 'e_wallet', 'EWALLET_0001333332', 34.60, 'completed'),
(13, 'card', '**** **** **** 4013', 20.50, 'completed'),
(14, 'card', '**** **** **** 4014', 27.90, 'completed'),
(15, 'card', '**** **** **** 4015', 25.40, 'completed'),
(16, 'e_wallet', 'EWALLET_0001777776', 27.50, 'completed'),
(17, 'card', '**** **** **** 4017', 21.00, 'completed'),
(18, 'cash', NULL, 25.30, 'pending'),
(19, 'card', '**** **** **** 4019', 35.50, 'completed');