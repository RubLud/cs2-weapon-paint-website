-- Additive, restartable. WeaponPaints wp_* tables and saved skins are not touched.
-- Legacy ownership keeps what players already had in WeaponPaints before enforcement.
-- Rows come only from an OWNER-run snapshot (bin/cloudhub-ownership.php --apply) and are never removed automatically.
-- No foreign key to cloudhub_players: WeaponPaints players may never have logged in to CloudHub.
CREATE TABLE IF NOT EXISTS cloudhub_ownership_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reason VARCHAR(500) NOT NULL,
 players INT UNSIGNED NOT NULL,
 items INT UNSIGNED NOT NULL,
 added INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cloudhub_legacy_ownership (
 steamid CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 weapon_defindex INT UNSIGNED NOT NULL,
 paint_id INT UNSIGNED NOT NULL,
 origin ENUM('equipped','knife','loadout') NOT NULL,
 snapshot_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(steamid,weapon_defindex,paint_id),
 FOREIGN KEY (snapshot_id) REFERENCES cloudhub_ownership_snapshots(id),
 INDEX idx_legacy_item(weapon_defindex,paint_id)
) ENGINE=InnoDB;
-- Knife names as WeaponPaints stores them, for the optional database guard. Filled by the OWNER CLI.
CREATE TABLE IF NOT EXISTS cloudhub_knife_models (
 name VARCHAR(64) NOT NULL PRIMARY KEY,
 weapon_defindex INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
