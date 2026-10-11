-- migrations/2026_09_14_whatsapp_admin.sql
--
-- WhatsApp administration: delivery tracking, per-user notification preferences,
-- and an audit trail for settings changes.
--
-- Requires migrations/2026_09_07_notification_dispatch_log.sql to have run first.
-- Additive only: no existing table or column is modified, and every statement is
-- IF NOT EXISTS, so re-running it is harmless.

-- app_settings already exists on production (admin/admin_settings.php creates it on
-- first load). Declared here so a fresh database gets it without that page.
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`        varchar(100) NOT NULL,
  `value`      text,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- One row per WhatsApp message handed to Twilio, whatever sent it (a reminder, the
-- digest, or an admin's test). notification_dispatch_log answers "did we try to send
-- this?"; this table answers "what happened to it afterwards?", fed by Twilio's
-- status callbacks (api/twilio_status.php). Joined on provider_sid = provider_ref.
CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `provider_sid`  varchar(64)      NULL,          -- Twilio SM.../MM...; null when Twilio refused it outright
  `user_id`       int(11)          NULL,          -- recipient, when they are a portal user
  `to_e164`       varchar(20)  NOT NULL,
  `kind`          varchar(32)  NOT NULL,          -- lesson_reminder | daily_digest | test
  `template_sid`  varchar(64)      NULL,          -- null for a plain-text test
  `dispatch_key`  varchar(191)     NULL,          -- notification_dispatch_log.dedupe_key
  `status`        varchar(16)  NOT NULL,          -- queued|sent|delivered|read|failed|undelivered
  `error_code`    varchar(16)      NULL,
  `error_message` varchar(255)     NULL,
  `created_by`    int(11)          NULL,          -- the admin, for tests
  `sent_at`       datetime         NULL,
  `delivered_at`  datetime         NULL,
  `read_at`       datetime         NULL,
  `failed_at`     datetime         NULL,
  `created_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_provider_sid` (`provider_sid`),
  KEY `idx_wa_created` (`created_at`),
  KEY `idx_wa_status` (`status`),
  KEY `idx_wa_user` (`user_id`),
  KEY `idx_wa_creator_kind` (`created_by`, `kind`, `created_at`),
  CONSTRAINT `fk_wa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every status callback as received, in arrival order. Twilio does not promise to
-- deliver callbacks in order, so whatsapp_messages.status is a ranked "furthest it
-- got" while this keeps the raw history for tracing a complaint.
CREATE TABLE IF NOT EXISTS `whatsapp_message_events` (
  `id`           int(11)     NOT NULL AUTO_INCREMENT,
  `provider_sid` varchar(64) NOT NULL,
  `status`       varchar(16) NOT NULL,
  `error_code`   varchar(16)     NULL,
  `received_at`  timestamp   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wae_sid` (`provider_sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A user's own choice per (notification kind, channel). No row means "use the
-- admin default" (app_settings key notify.default_prefs), so changing a default
-- reaches everyone who has not chosen for themselves.
CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `user_id`    int(11)     NOT NULL,
  `kind`       varchar(32) NOT NULL,              -- lesson_reminder | daily_digest
  `channel`    varchar(16) NOT NULL,              -- email | whatsapp
  `enabled`    tinyint(1)  NOT NULL,
  `updated_at` timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`, `kind`, `channel`),
  CONSTRAINT `fk_np_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who changed which setting, and when. Never the value: for a credential the value
-- is exactly what must not be copied anywhere else.
CREATE TABLE IF NOT EXISTS `settings_audit` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `action`      varchar(16)  NOT NULL,            -- set | cleared | verified | test
  `changed_by`  int(11)          NULL,
  `ip`          varchar(45)      NULL,
  `created_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sa_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
