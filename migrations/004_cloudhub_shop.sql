-- Additive, restartable. WeaponPaints wp_* tables and saved skins are not touched.
-- Products start inactive; prices are proposals until the OWNER activates them.
CREATE TABLE IF NOT EXISTS cloudhub_shop_products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('weapon_skin','knife','glove') NOT NULL,
 weapon_defindex INT UNSIGNED NOT NULL,
 paint_id INT UNSIGNED NOT NULL,
 name VARCHAR(128) NOT NULL,
 price_cc INT UNSIGNED NOT NULL CHECK (price_cc BETWEEN 1 AND 1000000),
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_shop_item(weapon_defindex,paint_id),
 INDEX idx_shop_listing(is_active,kind,weapon_defindex,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cloudhub_shop_price_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 old_price_cc INT UNSIGNED NULL,
 new_price_cc INT UNSIGNED NOT NULL,
 old_active TINYINT(1) NULL,
 new_active TINYINT(1) NOT NULL,
 actor_steamid CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_price_request(actor_steamid,request_id),
 FOREIGN KEY (product_id) REFERENCES cloudhub_shop_products(id),
 FOREIGN KEY (actor_steamid) REFERENCES cloudhub_players(steamid),
 INDEX idx_price_history(product_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cloudhub_shop_purchases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 steamid CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 price_cc INT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 wallet_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_purchase_request(steamid,request_id),
 FOREIGN KEY (steamid) REFERENCES cloudhub_players(steamid),
 FOREIGN KEY (product_id) REFERENCES cloudhub_shop_products(id),
 FOREIGN KEY (wallet_transaction_id) REFERENCES cloudhub_wallet_transactions(id),
 INDEX idx_purchase_history(steamid,id)
) ENGINE=InnoDB;
-- Ownership only. Cosmetic configuration stays in wp_player_skins and is always free.
CREATE TABLE IF NOT EXISTS cloudhub_player_inventory (
 steamid CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 source ENUM('purchase','grant','migration') NOT NULL,
 purchase_id BIGINT UNSIGNED NULL UNIQUE,
 acquired_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(steamid,product_id),
 FOREIGN KEY (steamid) REFERENCES cloudhub_players(steamid),
 FOREIGN KEY (product_id) REFERENCES cloudhub_shop_products(id),
 FOREIGN KEY (purchase_id) REFERENCES cloudhub_shop_purchases(id),
 INDEX idx_inventory_product(product_id),
 INDEX idx_inventory_recent(steamid,acquired_at)
) ENGINE=InnoDB;
