# Persistent tutor–student whiteboards

How the lesson whiteboard became durable across lessons, what changed, how to deploy
it, and how to roll it back.

## The problem

`lesson_whiteboards` was keyed by `lesson_id`. A board therefore lived and died with
one lesson row: when the same tutor and student met again under a new lesson id they
got a blank board, and the previous week's work stayed attached to a lesson nobody
opened again.

Three further faults made that worse:

- **`api/whiteboard.php` had no concurrency control.** Every `POST` wrote
  unconditionally. Two clients, a stale tab, or a slow request that landed late all
  simply overwrote whatever was there.
- **A failed load looked identical to an empty board.** `whiteboard.js`'s `load()`
  caught every error and returned `{elements: [], files: {}}`. Nothing broke at that
  moment, but the next stroke marked the scene dirty and the autosave wrote that empty
  scene over a perfectly good server-side board. A network blip during load cost the
  pair their work.
- **Leaving relied on `sendBeacon`.** It silently drops payloads over roughly 64KB,
  which any board holding an imported PDF page exceeds.

## What it is now

Continuity belongs to the **relationship**, not the lesson.

```
classroom_workspaces          one per tutor-student pair (or per demo lesson)
  └── classroom_workspace_pages   many pages, each one Excalidraw scene
```

`workspace_key` is deterministic, so resolving it is a pure function of the lesson row
rather than a search:

| Lesson | Key |
| --- | --- |
| tutor 7, student 12 | `pair:7:12` |
| demo lesson 88 (no student) | `demo:88` |

Demos are keyed by lesson on purpose. Every demo has `student_id NULL`, so keying them
by pair would pour every demo a tutor ever ran into one shared board.

Lessons keep their own LiveKit room, attendance and recording exactly as before. Only
the board moved.

### Revision-aware saves

Every save states the revision it was based on:

```
POST api/whiteboard.php?lesson_id=N   {page_id, revision, scene, files}
  200  {saved: true, revision: N+1}
  409  {reason: 'conflict', revision, scene, files}     -> merge and retry
  409  {reason: 'stale_client'}                         -> reload required
```

The `UPDATE` carries the expected revision in its `WHERE` clause, so of two racing
saves the second matches zero rows and is told to reconcile instead of winning. A save
that cannot say what it was based on — the shape the old client sent, and the shape a
client whose load failed would send — is refused outright.

### Three load outcomes, not two

`loaded` (the board may legitimately be empty), `failed` (we do not know what is on the
server, so **saving is blocked** and the user is told, with a retry), and never
"empty because something went wrong".

### Leaving flushes explicitly

`window.lessonWhiteboardFlush()` is awaited by both the Finish button and
`room.js`'s leave path, and the user is asked before leaving if it fails. The
`beforeunload` beacon remains only as a fallback for small payloads.

## Eraser behaviour

The eraser removes marks drawn over an imported PDF page or photo, and leaves the page
itself alone. Deliberate removal — select, then Delete — still works, as do moving and
resizing.

This is **not** implemented with `locked: true`. A locked element cannot be selected,
moved, resized or deleted at all, which would weld a wrongly-placed import to the board
forever. Instead the distinction drawn is *intent*: Excalidraw 0.17 reports the active
tool in the `appState` passed to `onChange`, so a deletion that happens while the eraser
is active is told apart from a Delete-key removal and reverted, with a version bump so
the revival wins the sync comparison and the image does not flicker back out.

## Files changed

| File | Change |
| --- | --- |
| `migrations/2026_09_13_classroom_workspaces.sql` | **new** — the two tables |
| `migrations/2026_09_13_migrate_lesson_whiteboards.php` | **new** — idempotent import |
| `utils/classroom_workspace.php` | **new** — identity, authorization, revision saves |
| `api/whiteboard.php` | rewritten — workspace-aware, revision-checked |
| `api/file_url.php` | rewritten — materials only (see the security note below) |
| `assets/livekit/whiteboard.js` | load-failure latch, revision saves, flush, eraser rule, lazy PDF import |
| `assets/livekit/room.js` | host-only lesson end; awaits the board flush before leaving |
| `components/lesson_room_live_shell.php` | Finish flushes the board when there is no call |
| `tutor/api/livekit_token.php`, `student/api/livekit_token.php` | return `hostIdentity` |

## Deployment

Order matters: the new client cannot save against the old endpoint, and the old client
cannot save against the new one (it is refused as `stale_client` rather than allowed to
overwrite). Deploy the schema first, then the code, in one window.

```bash
# 1. Back up. lesson_whiteboards is the one that matters.
mysqldump -h <host> -u <user> -p <db> lesson_whiteboards > lesson_whiteboards.backup.sql

# 2. Schema. Additive only; no existing table is modified.
mysql -h <host> -u <user> -p <db> < migrations/2026_09_13_classroom_workspaces.sql

# 3. See what the import would do, without writing.
php migrations/2026_09_13_migrate_lesson_whiteboards.php

# 4. Import. Idempotent: safe to re-run, and it never modifies lesson_whiteboards.
php migrations/2026_09_13_migrate_lesson_whiteboards.php --apply

# 5. Deploy the application files listed above.
```

Cache-busting is automatic — the shell versions the JS by `filemtime`.

### Rollback

The migration only ever adds rows, so rolling back is a code revert:

```bash
# 1. Revert the application files. lesson_whiteboards is untouched and still current
#    for every lesson up to the cutover, so the old endpoint works immediately.
# 2. Optionally drop the new tables (they are otherwise inert):
#    DROP TABLE classroom_workspace_pages;
#    DROP TABLE classroom_workspaces;
```

**What is lost on rollback:** board work done *after* the cutover, because it was
written to workspace pages and not to `lesson_whiteboards`. Everything from before the
cutover is intact. To recover post-cutover work, copy each page's `scene`/`files` back
into `lesson_whiteboards` for its `source_lesson_id` before dropping the tables.

## Security fix in the same change set

`api/file_url.php` accepted `homework/<file>` and `submissions/<file>` for **any**
signed-in session with no ownership check, so any student could read any other
student's submitted work by knowing or guessing a filename — bypassing
`student/api/download.php`, the downloader that actually checks whose file it is.
Verified against the pre-fix file: it returned **HTTP 200 with the submission body** to
an unrelated account.

No caller needed those prefixes (`storage_gateway_url()` is only handed
`learning_materials.file_url`; `admin/materials.php` hardcodes `materials/`), so they
were removed rather than patched. The gateway now also requires the filename to match a
real `learning_materials` row, and serves unpublished material to staff only.

`assets/livekit/room.js` also accepted the `{t:'ended'}` control message from any
participant, so a student could end the lesson for the tutor. Both token endpoints now
return `hostIdentity` from the lessons row, and the client ignores lesson control
messages from anyone else. LiveKit signs participant identity into the token, so
neither end is client-controlled.

## Tests

```bash
# The throwaway MariaDB (never the production database in .env):
mysqld --no-defaults --datadir=<scratch dir> --port=33316 --bind-address=127.0.0.1

# Everything, in order:
php tests/classroom/run.php
```

| Suite | Covers |
| --- | --- |
| `test_workspace.php` | identity, authorization, revisions, cross-workspace access |
| `test_migration.php` | import correctness, ordering, isolation, source table untouched |
| `test_api_http.php` | the API over real HTTP with real sessions |
| `test_file_gateway.php` | the file-gateway regression |
| `browser_board.mjs` | the real client in Chromium (Playwright) |

Browser tests need Playwright in the temp project directory:

```bash
cd %TEMP%/tutsys-classroom-test && npm install playwright && npx playwright install chromium
```

### What these tests do NOT cover

- **Anything needing LiveKit.** The test environment has no LiveKit credentials on
  purpose, so peer-to-peer sync, remote erase echo, simultaneous editing between two
  live clients, and reconnect behaviour are **not** covered. The board is exercised
  because it is built to run with `room === null`, which is the same path a lesson
  takes when LiveKit is blocked.
- **The lesson-end spoofing fix end to end.** That the token endpoints return
  `hostIdentity` is tested; that a student's forged `ended` packet is ignored needs two
  real browsers in a real LiveKit room.
- **The real `lesson_whiteboards` DDL.** It is not in the repository — it exists only
  on production. `tests/classroom/schema_additions.sql` reconstructs it from how the
  code used it. Column types, engine and indexes on production are unverified from
  here, so re-check the migration against a real dump before release.
- Recording, attendance, screen share, and the mobile layout.
