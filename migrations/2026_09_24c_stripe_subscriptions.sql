-- migrations/2026_09_24c_stripe_subscriptions.sql
--
-- Stripe subscriptions (the 30-day plans sold through Payment Links) mapped onto
-- the portal's own package model. Every paid Stripe invoice becomes one
-- student_packages row plus one Completed payments row, exactly like a package an
-- admin assigns by hand, so the rest of the portal needs no changes.
--
--   stripe_subscriptions  one row per Stripe subscription: who it belongs to, which
--                         plan, and its live status. student_id is NULL until the
--                         payment is matched to a student (automatically, or by an
--                         admin on admin/subscriptions.php); a payment that arrives
--                         while unmatched waits in pending_invoice_id.
--   stripe_events         every webhook event id processed, so a redelivered event
--                         is never applied twice, and an audit trail for the admin page.
--
-- Additive and safe to re-run.

CREATE TABLE IF NOT EXISTS stripe_subscriptions (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  subscription_id       VARCHAR(64) NOT NULL,
  customer_id           VARCHAR(64) NULL,
  student_id            INT NULL,
  attempt_id            INT NULL,
  plan                  VARCHAR(20) NULL,
  status                VARCHAR(20) NOT NULL DEFAULT 'active',
  cancel_at_period_end  TINYINT(1) NOT NULL DEFAULT 0,
  current_period_end    DATETIME NULL,
  customer_email        VARCHAR(190) NULL,
  customer_name         VARCHAR(190) NULL,
  pending_invoice_id    VARCHAR(64) NULL,
  pending_amount        DECIMAL(10,2) NULL,
  last_error            VARCHAR(500) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subscription (subscription_id),
  KEY idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
  event_id      VARCHAR(80) NOT NULL PRIMARY KEY,
  type          VARCHAR(80) NOT NULL,
  result        VARCHAR(255) NULL,
  received_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
