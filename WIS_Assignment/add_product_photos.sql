-- =============================================================================
--  ADD PRODUCT PHOTOS - assigns one primary image per product WITHOUT dropping
--  any table. Safe to run more than once.
--
--  The image files (uploads/products/product-{id}.jpg) are committed to the repo,
--  so after a `git pull` a teammate only needs to run this file - no uploading.
--  A fresh rebuild from schema.sql already includes the same seeding.
--
--    "C:/xampp/mysql/bin/mysql.exe" -u root < add_product_photos.sql
-- =============================================================================
USE `coffee_shop`;

DELETE FROM `product_images`;

INSERT INTO `product_images` (`product_id`, `image_path`, `is_primary`)
SELECT `id`, CONCAT('product-', `id`, '.jpg'), 1 FROM `products`;
