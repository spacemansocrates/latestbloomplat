# Payment providers — go-live checklist

The code is fully in place and **dormant until you add credentials**. Today, checkout
redirects to the static hosted links configured per-package in the admin
(`app_settings.checkout_url_pkg_*` / `stripe_checkout_url_pkg_*` / `paychangu_checkout_url_pkg_*`),
and the confirmation endpoints reject anything unverified. Adding the keys below is all
that's needed to make activation automatic and secure — no code changes.

Settings resolve from `.env` first (UPPER_SNAKE names), then the `app_settings` table
(lower_snake names). Either works.

---

## Stripe

1. **Webhook**: Stripe Dashboard → Developers → Webhooks → *Add endpoint*
   `https://<your-domain>/student/api/stripe_webhook.php`
   Subscribe to **`checkout.session.completed`**.
2. Copy that endpoint's **Signing secret** (`whsec_…`) →
   `STRIPE_WEBHOOK_SECRET` in `.env` (or `stripe_webhook_secret` in app_settings).
3. (Optional, for dynamic checkout instead of static Payment Links) put your
   **Secret key** (`sk_live_…`) in `STRIPE_SECRET_KEY`.
4. Package reference: `pay_start.php` already appends `?client_reference_id=<sp_id>` to
   Stripe links, and the webhook reads it back — nothing else to do.

Once `STRIPE_WEBHOOK_SECRET` is set, the webhook verifies each event's signature
(HMAC-SHA256, replay-protected) and activates the package on a paid session, with an
amount check against the package price. Until then it returns 503 and activates nothing.

## PayChangu

1. Put your **Secret key** (Bearer token for the PayChangu API) in
   `PAYCHANGU_SECRET_KEY` (`.env`) or `paychangu_secret_key` (app_settings).
2. Point PayChangu's callback/return at
   `https://<your-domain>/student/api/payment_callback.php` (POST, with `sp_id` and
   `txn`/`tx_ref`).

With the key set, `payment_callback.php` calls PayChangu's
`GET /verify-payment/{tx_ref}` to confirm status + amount server-side (unforgeable).
Without it, the callback falls back to a POST-only shared secret
(`payment_callback_secret`) and honours the reported status — but the API key is the
recommended, tamper-proof path.

> Note: static hosted links don't let us pre-assign a PayChangu `tx_ref`. For fully
> automatic reconciliation, initiate transactions via PayChangu's API at checkout time
> (generate a `tx_ref`, set `meta.sp_id`) instead of static links. The verify + activate
> plumbing is already done; only `pay_start.php`'s redirect would change.

---

## What's guaranteed regardless of provider

Both paths funnel through `payments_activate_success()` in
`integrations/payments.php`, which:
- activates **only** on a verified success,
- checks the paid amount against the package price (rejects underpayment),
- records the **DB-derived** amount, never a caller-supplied one,
- is **idempotent** (a replayed callback/webhook won't double-charge or double-activate).
