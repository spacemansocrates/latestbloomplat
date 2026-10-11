# WhatsApp reminders — setup

WhatsApp is **switched off by default**. Email works the moment the cron jobs are
installed; WhatsApp only starts sending once everything below is in place.

Nothing here can be done from the code. A message a business sends *first* — which
every reminder is — must use a template that Meta has approved, and only you can
create and submit those from your own Twilio account.

---

## 1. Why it is off

At the time of writing, of 55 users on the portal:

| | count |
|---|---|
| have a phone number of any kind | 33 |
| number has 8 or more digits | 11 |
| number is a single digit (`1`, `6`) | ~20 |
| have a `guardian_phone` | 0 |

So roughly **seven people have a number that can actually be dialled**, and almost
none of them are students. Turning WhatsApp on before numbers are collected produces
Twilio delivery errors, not messages. The new phone fields on the student and tutor
settings pages (and the admin user editor) validate to E.164, so numbers gathered
from now on are usable.

Run `php cron/report_phone_coverage.php` for the current picture.

---

## 2. Sandbox vs. a real sender

- **Sandbox** (`whatsapp:+14155238886`): fine for your own testing, useless for
  parents and students — every recipient must first message a join code to the
  sandbox number, and the link expires. Templates are not required.
- **Approved WhatsApp sender**: your own number, registered through Twilio to a
  Meta WhatsApp Business Account. This is what real reminders need, and it is where
  the approved templates live.

---

## 3. Templates to create

In the Twilio Console: **Messaging → Content Template Builder → Create new**,
type **Text**, category **Utility** (these are transactional reminders, not
marketing — utility is both cheaper and far more likely to be approved).

Submit each for WhatsApp approval, then copy its **Content SID** (`HX…`).

### Template A — daily digest

Name: `bloom_daily_lessons`

```
Good morning {{1}}! You have {{2}} lesson(s) with Bloom Education today: {{3}}. Open your portal: {{4}}
```

| Variable | Filled with | Example |
|---|---|---|
| `{{1}}` | First name | `Grace` |
| `{{2}}` | Number of lessons | `2` |
| `{{3}}` | The lessons, one line | `09:00 Mathematics with Angie Banda; 14:30 English with John Phiri` |
| `{{4}}` | Dashboard link | `https://portal.bloomeducationonline.com/student/dashboard.php` |

Sample values matter — Meta rejects submissions whose examples look like
placeholders. Paste the examples above into the sample fields.

### Template B — 15-minute reminder

Name: `bloom_lesson_starting`

```
Hi {{1}}, your {{2}} lesson with {{3}} starts at {{4}}. Join here: {{5}}
```

| Variable | Filled with | Example |
|---|---|---|
| `{{1}}` | First name | `Grace` |
| `{{2}}` | Subject | `Mathematics` |
| `{{3}}` | The other person | `Angie Banda` |
| `{{4}}` | Start time + zone, in the reader's timezone | `14:30 CAT` |
| `{{5}}` | Direct link into the lesson room | `https://portal.bloomeducationonline.com/student/lesson_room_live.php?lesson_id=412` |

The time is sent as a clock time rather than "in 15 minutes", so a delayed message
is never wrong — only late.

---

## 4. Configuration

### The admin screen (normal way)

**Admin → WhatsApp** (`admin/whatsapp.php`, full admins only) holds everything:
Account SID, Auth Token, sender, both template IDs, the on/off switch, a test
button, the default reminder choices and the delivery log.

- The Auth Token is encrypted (AES-256-GCM) before it is stored in `app_settings`
  and is never sent back to the browser, only its last four characters. The key is
  `APP_ENCRYPTION_KEY` in the server `.env`, so a database dump or backup on its own
  does not reveal the token. Create it once per server:
  ```
  php tools/generate_encryption_key.php --write
  ```
  It refuses to overwrite an existing key, because every stored secret was
  encrypted with it. Losing or changing the key means re-entering the token.
- Saving a new SID or token checks it against Twilio straight away (no message is
  sent). **Check connection** repeats that check.
- Every change is recorded in `settings_audit` (who, when, which setting, never the
  value) and listed under **Recent changes**.
- **Send a test** works whether reminders are on or off, is limited to 5 per admin
  per hour, and appears in the delivery log like any other message. Plain-text tests
  only arrive if that number messaged your sender in the last 24 hours; use a
  template test otherwise.

Delivery tracking needs no setup in Twilio: every send passes
`StatusCallback=<SITE_URL>/api/twilio_status.php`, and that endpoint accepts only
requests carrying a valid `X-Twilio-Signature`. `SITE_URL` must therefore be the
public https address of the portal.

Database changes for all of this: `migrations/2026_09_14_whatsapp_admin.sql`
(additive, safe to re-run; needs `2026_09_07_notification_dispatch_log.sql` first).

### Reminder preferences

Students and tutors choose Email and/or WhatsApp for each reminder type on their
own settings page (Notifications tab). Anyone who has not chosen follows the admin
defaults on the WhatsApp screen. An opt-out writes nothing to the dispatch log, so
switching a channel back on works from the next reminder.

### Or in `.env`

A value in `.env` always wins over the admin screen, and the screen shows that
field read-only. Add to `.env` on the server (never commit these):

```
TWILIO_ACCOUNT_SID=AC................................
TWILIO_AUTH_TOKEN=.................................
TWILIO_WHATSAPP_FROM=+265...........
WHATSAPP_TEMPLATE_DIGEST_SID=HX................................
WHATSAPP_TEMPLATE_REMINDER_SID=HX................................
WHATSAPP_ENABLED=false
```

Notes:

- `TWILIO_ACCOUNT_SID` must be the Account SID (`AC…`). An API Key (`SK…`) will not
  authenticate this endpoint.
- `TWILIO_WHATSAPP_FROM` accepts `+265…`, `whatsapp:+265…`, or a Messaging Service
  SID (`MG…`) — a Messaging Service is sent as `MessagingServiceSid` instead of `From`.
- Every one of these can also live in the `app_settings` table
  (`twilio_account_sid`, `twilio_auth_token`, `twilio_whatsapp_from`,
  `whatsapp_template_digest_sid`, `whatsapp_template_reminder_sid`,
  `whatsapp_enabled`) if you would rather change them without a deploy. `.env` wins
  where both are set — the same order `livekit_client.php` uses.

Optional, both with sensible defaults:

```
SCHOOL_TIMEZONE=Africa/Blantyre   # digest day boundaries, and the fallback for users with no timezone
DEFAULT_COUNTRY_CODE=265          # only applied to numbers written in local form, e.g. 0999123456
```

---

## 5. Switching it on

1. Collect numbers. Ask people to update their settings page, or fix them in the
   admin user editor. Watch progress with `php cron/report_phone_coverage.php`.
2. Set `WHATSAPP_ENABLED=true`.
3. Send yourself one first:
   ```
   php cron/send_lesson_reminders.php --lesson=<id> --dry-run
   ```
   The dry run prints the exact E.164 number each person would be messaged on, and
   says `(country assumed)` where a leading `0` was expanded using
   `DEFAULT_COUNTRY_CODE` — check those before a first real send, because a UK
   number typed as `07700…` would otherwise be read as Malawian.
4. Remove `--dry-run`.

Failures never repeat forever: each (lesson, person, channel) is claimed in
`notification_dispatch_log` and retried at most three times.

## 6. Checking what happened

```sql
SELECT created_at, kind, channel, status, detail, provider_ref
FROM notification_dispatch_log
ORDER BY id DESC
LIMIT 50;
```

`status` is `sent`, `failed`, `skipped` (no usable address or number — `detail`
says which) or `sending` (in flight, or a run that died mid-send).
`provider_ref` holds the Twilio message SID, which is what to quote in the Twilio
console when tracing a delivery.
