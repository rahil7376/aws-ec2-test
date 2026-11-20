-- Stateless Goods
--
-- Import this once into the shared database (RDS), not once per EC2 instance:
--   mysql -h <rds-endpoint> -u <user> -p shop < sql/schema.sql
--
-- Re-importing drops the shop tables and the session table.
--
-- Product photos live in products.image, not on the instance disk.
-- A file written on one instance is invisible to the others.
--
-- Login and cart state live in `sessions`. PHP's default files under
-- /var/lib/php/session would be stranded on whichever instance handled the login.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  image MEDIUMBLOB NULL,
  image_type VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_name VARCHAR(200) NOT NULL,
  customer_email VARCHAR(200) NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  product_name VARCHAR(200) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY order_items_order_id (order_id),
  CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  data MEDIUMBLOB NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lab password is admin123. Change it before this app is reachable from the public internet.
INSERT INTO users (username, password_hash) VALUES
  ('admin', '$2y$10$fJUZH.8PBRIFjwlHM0caBuG.H4B.WFdgdW3p7qYtkep3SWQkEzq7.');

INSERT INTO products (name, description, price, stock) VALUES
  (
    'Canvas tote',
    'Heavy unbleached canvas with flat handles. Wide enough for a laptop and a small grocery run.',
    24.00,
    30
  ),
  (
    'Stoneware mug',
    'Speckled glaze, 12 oz, comfortable handle. Safe in the dishwasher.',
    18.00,
    40
  ),
  (
    'Grid notebook',
    'A5, 160 pages, lay-flat binding. Pale grey grid so a pencil sketch still shows.',
    12.00,
    55
  ),
  (
    'Wool beanie',
    'Merino wool, one size, folded brim. Knit tight enough for a windy platform.',
    28.00,
    22
  );
