-- Password reset tokens.
--
-- Only a SHA-256 hash of each token is stored. If this table ever leaks, the hashes
-- cannot be used to reset anyone's password, the same reasoning as hashing passwords.
-- Tokens are single use (used_at) and short lived (expires_at).
--
-- Applied to bloomtutsys on the VPS 2026-09-03.

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)     NOT NULL,
  `token_hash`   CHAR(64)    NOT NULL COMMENT 'sha256 of the emailed token; the token itself is never stored',
  `expires_at`   DATETIME    NOT NULL,
  `used_at`      DATETIME    NULL DEFAULT NULL,
  `requested_ip` VARCHAR(45) NULL DEFAULT NULL,
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`),
  KEY `idx_password_resets_expires` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
