-- migrations/2026_09_07_notification_dispatch_log.sql
--
-- Ledger of every reminder we try to send, and the thing that stops duplicates.
--
-- The old lesson_notification_daemon deduplicated by pattern-matching the text of
-- rows in `notifications` (message LIKE '%#<id>%'), which matched lesson #1 against
-- #10 and #100, and which silently stopped working altogether on production because
-- its INSERT omitted the NOT NULL `type` column. A dedicated table with a unique
-- key removes both problems and keeps the in-app notification bell out of it.
--
-- dedupe_key is the contract. It is built by the cron jobs, one row per
-- (what, who, which occurrence, which channel):
--   lesson_reminder:<lesson_id>:<user_id>:<channel>
--   daily_digest:<YYYY-MM-DD>:<user_id>:<channel>
-- so re-running a job, or two runs overlapping, cannot send the same thing twice.
--
-- Additive only: no existing table or column is touched.

CREATE TABLE IF NOT EXISTS `notification_dispatch_log` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `dedupe_key`   varchar(191) NOT NULL,
  `user_id`      int(11)      NOT NULL,
  `kind`         varchar(32)  NOT NULL,           -- 'lesson_reminder' | 'daily_digest'
  `channel`      varchar(16)  NOT NULL,           -- 'email' | 'whatsapp'
  `lesson_id`    int(11)          NULL,           -- null for the daily digest
  `status`       varchar(16)  NOT NULL DEFAULT 'sending',  -- sending|sent|failed|skipped
  `attempts`     int(11)      NOT NULL DEFAULT 0,
  `detail`       varchar(255)     NULL,           -- failure reason, or why it was skipped
  `provider_ref` varchar(64)      NULL,           -- Twilio message SID, when there is one
  `created_at`   timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`   timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dispatch_dedupe` (`dedupe_key`),
  KEY `idx_dispatch_user_kind` (`user_id`, `kind`),
  KEY `idx_dispatch_lesson` (`lesson_id`),
  KEY `idx_dispatch_created` (`created_at`),
  CONSTRAINT `fk_dispatch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
