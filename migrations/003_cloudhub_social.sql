-- Additive, restartable. Existing profile privacy and comments_policy are preserved.
CREATE TABLE IF NOT EXISTS cloudhub_social_settings (
 steamid CHAR(17) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 friends_visibility ENUM('public','friends','private') NOT NULL DEFAULT 'private',
 invites_policy ENUM('everyone','friends','disabled') NOT NULL DEFAULT 'everyone',
 FOREIGN KEY (steamid) REFERENCES cloudhub_players(steamid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS cloudhub_friendships (
 low_id CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 high_id CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(low_id,high_id), INDEX idx_friend_high(high_id,status),
 CHECK(low_id < high_id), CHECK(sender=low_id OR sender=high_id),
 FOREIGN KEY(low_id) REFERENCES cloudhub_players(steamid),
 FOREIGN KEY(high_id) REFERENCES cloudhub_players(steamid),
 FOREIGN KEY(sender) REFERENCES cloudhub_players(steamid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS cloudhub_blocks (
 actor CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(actor,target), INDEX idx_block_target(target,actor), CHECK(actor<>target),
 FOREIGN KEY(actor) REFERENCES cloudhub_players(steamid), FOREIGN KEY(target) REFERENCES cloudhub_players(steamid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS cloudhub_profile_comments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 profile_id CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 author CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body VARCHAR(1000) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 deleted_at DATETIME(6) NULL,
 FOREIGN KEY(profile_id) REFERENCES cloudhub_players(steamid), FOREIGN KEY(author) REFERENCES cloudhub_players(steamid),
 INDEX idx_comment_profile(profile_id,deleted_at,id), INDEX idx_comment_author(author,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cloudhub_comment_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 comment_id BIGINT UNSIGNED NOT NULL,
 reporter CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 resolved_at DATETIME(6) NULL,
 UNIQUE(comment_id,reporter), INDEX idx_reports_open(resolved_at,id),
 FOREIGN KEY(comment_id) REFERENCES cloudhub_profile_comments(id), FOREIGN KEY(reporter) REFERENCES cloudhub_players(steamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cloudhub_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recipient CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind ENUM('invite','accepted','comment','moderated') NOT NULL,
 profile_id CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 comment_id BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 read_at DATETIME(6) NULL,
 FOREIGN KEY(recipient) REFERENCES cloudhub_players(steamid), FOREIGN KEY(actor) REFERENCES cloudhub_players(steamid),
 FOREIGN KEY(profile_id) REFERENCES cloudhub_players(steamid), FOREIGN KEY(comment_id) REFERENCES cloudhub_profile_comments(id),
 INDEX idx_notifications_recipient(recipient,id), INDEX idx_notifications_unread(recipient,read_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS cloudhub_social_limits (
 actor CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start DATETIME(6) NOT NULL,
 last_at DATETIME(6) NOT NULL,
 attempts INT UNSIGNED NOT NULL,
 PRIMARY KEY(actor,action), FOREIGN KEY(actor) REFERENCES cloudhub_players(steamid)
) ENGINE=InnoDB;
