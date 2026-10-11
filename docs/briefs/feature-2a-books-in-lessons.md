# Brief: Books inside lessons (Feature 2A)

You are implementing one feature in the Bloom Education tutoring portal: **opening books
from the Materials library inside a live lesson, presenting them page by page to the
student, and putting a page onto the shared whiteboard.** Classroom chat is a separate
feature and is out of scope.

Read this whole brief before touching code. Then read the files it points to. Ask the
owner (Paul) before doing anything listed under "Needs Paul".

---

## 1. Where things are

- **Codebase:** `C:\xampp\htdocs\tutsys3.1` (git repo, branch `master`). This is the only
  Bloom portal codebase. `C:\xampp\htdocs\bloomtutsys` is a dead older copy; ignore it.
- **Live site:** https://portal.bloomeducationonline.com, on VPS `89.116.22.96`, docroot
  `/var/www/portal.bloomeducationonline.com/public_html` (not a git clone), database
  `bloomtutsys` on the VPS.
- **Recent history:** `7dda15c` durable whiteboards (read `docs/classroom-workspaces.md`),
  `a7ebf14` WhatsApp admin, `f1af35c` student CSRF fix. All three are live.

## 2. Rules you must not break

1. **Never run app code against the project `.env`.** It points at a production database.
   All testing uses the disposable copy built by `tests/classroom/prepare_app.php` and the
   throwaway MariaDB on `127.0.0.1:33316`. Start it if it is not running:
   `C:\xampp\mysql\bin\mysqld.exe --no-defaults --datadir=<scratch dir> --port=33316 --bind-address=127.0.0.1`
   (an existing datadir is at `%TEMP%\tutsys-tutor-20260913-110825\mysql-data`).
2. **Do not deploy.** When you are done, hand back with deploy steps. Paul approves deploys.
3. **No CDN code.** Everything the room loads is self-hosted in `assets/livekit/vendor/`.
   pdf.js **3.11.174** is already vendored (`pdf.min.js` + `pdf.worker.min.js`) and the room
   shell already sets its worker path. Do not add a newer pdf.js or a second copy. (Note:
   `tutor/materials.php` still loads a pdf.js worker from jsdelivr; do not copy that.)
4. **Icons** come only from `assets/icons/sprite.svg`:
   `<svg class="w-4 h-4" data-lucide="NAME" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-NAME"></use></svg>`.
   Check the icon exists in the sprite; a missing `<use>` target renders nothing with no error.
5. **Escape everything you put into `innerHTML`.** Book titles and descriptions are
   admin-entered text. Prefer `textContent`. `json_encode()` into an HTML attribute needs
   `JSON_HEX_APOS|JSON_HEX_QUOT`.
6. **No em dashes in any user-facing text.** Paul does not want them. Use a comma or full stop.
7. Commit in logical steps. End every commit message with
   `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (or your own model line).
8. There is no Node on the server. Node 24 exists on this Windows machine for tests only.
9. `php -l` proves nothing about inline `<script>` blocks. Verify JS by loading the page in a
   browser and reading the console, in a fresh tab (the console persists across navigations).

## 3. Decisions already made by Paul

- The **tutor controls** the book. The student follows the tutor's page. The tutor can
  switch on "let the student browse", and the student then gets a "Back to tutor's page"
  button. When browsing is off, the student cannot move away from the tutor's page.
- Books offered first are those matching the **lesson's subject**, with a search across all
  published books. If the subject has none, show the whole library instead of an empty list.
- Students only ever see **published** materials (`learning_materials.is_published = 1`).
- **Drawing happens on the whiteboard**, not on the book. "Put this page on the board" copies
  the page onto the board, where both people can annotate it and it is saved.
- The next lesson with the same tutor-student pair reopens the **same book at the same page**.

## 4. Facts about the current system (verified 2026-09-13)

**The library.** Table `learning_materials`: `id, title, description, material_type
enum('Book','PastPaper','PPT','Video','Worksheet','Audio','Other'), curriculum, grade_level,
subject_id, file_url, external_url, tags, is_published, created_by_admin_id, created_at`.
Live data: **6 books, all PDF, all Mathematics**, `file_url` like
`/uploads/materials/7ce1860dba11_Maths_GCSE_Higher.pdf`. Sizes: **17, 26, 28, 29, 40 and 84 MB.**
Six other material rows point at files that were never migrated; handle a missing file
with a clear "This book's file is unavailable" state, not a spinner.

**Where the bytes live.** The PDFs are in the Cloudflare R2 bucket `bloom-recordings` under
`uploads/materials/...`. Helpers:
- `integrations/file_storage.php`: `storage_key_from_file_url()`, `storage_gateway_url()`
  (returns `../api/file_url.php?p=materials/<file>`), `storage_find_local()`,
  `storage_stream_local()`.
- `integrations/s3_client.php`: `s3_configured()`, `s3_presign_get($key, $expires)`.
- `api/file_url.php`: authenticated gateway. It 302-redirects to a presigned R2 URL (1 hour),
  or streams a local copy. It already requires the filename to match a real
  `learning_materials` row and blocks unpublished ones for students.
- R2 has a **CORS rule** allowing `https://portal.bloomeducationonline.com` for GET/HEAD,
  allowing the `Range` request header, and exposing `Content-Length, Content-Range,
  Accept-Ranges`. Ranged reads were verified to return 206.

**Two traps with those URLs that you must design for:**
1. **Redirect plus CORS.** pdf.js range requests carry a `Range` header, which triggers a CORS
   preflight. A same-origin request redirected to a cross-origin R2 URL may be refused by the
   browser. Do not rely on the 302 for the viewer. Add an endpoint that returns the presigned
   URL as JSON after the same checks, and pass that URL straight to pdf.js.
2. **Expiry.** Presigned URLs last 1 hour and lessons last up to an hour or more, while pdf.js
   fetches ranges lazily all lesson long. Either presign for the lesson's remaining time plus
   a margin (cap it, e.g. 3 hours), or detect a 403 on a range fetch and reopen the document
   with a fresh URL at the same page. Doing both is best.

With R2 switched off (`s3_configured()` false) the file is served locally. Your endpoint must
still work then; the local fallback must support HTTP Range (check `storage_stream_local()`;
add Range support there if it lacks it, or large books will download whole).

**The lesson room.**
- `student/lesson_room_live.php` and `tutor/join_lesson_live.php` both render
  `components/lesson_room_live_shell.php`. `$IS_TUTOR` is available there. The shell boots
  `initLessonRoom(...)` (from `assets/livekit/room.js`), then `initWhiteboard(room, cfg)` (from
  `assets/livekit/whiteboard.js`), then, for the tutor only, `initRecorder(...)`
  (`assets/livekit/recorder.js`). The whiteboard boots even when the call fails
  (`room === null`); your viewer must do the same.
- JS is cache-busted by `filemtime` via `$v('file.js')` in the shell.
- The main area has `#boardPane` and `#screenPane`, with `#camPanel` beside them. `room.js`
  `layout()` toggles board vs shared screen and drives `#viewToggle`. You will add a third
  view, the book, and should turn the toggle into Board / Book / Shared screen tabs, showing
  only the views that exist. A new remote screen share still switches the main view to the
  screen, as it does today.
- `.hidden` in the shell is `display:none!important`. `window.setBtnBusy(btn, on)` gives any
  button a spinner; use it for the Books button and "Put on board".
- **Data messages:** LiveKit `publishData` with topics. `'wb'` is the board and `'lesson'`
  is lesson control. Use a new topic **`'book'`**. Copy the rule `room.js` uses for `'lesson'`:
  control messages are accepted **only from `data.hostIdentity`** (the tutor, from the token
  endpoint), so a student cannot drive the tutor's view. Packets must stay well under ~15 KB.
- `utils/classroom_workspace.php`: `cw_authorize_lesson($pdo, $lessonId, $userId, $role)`
  returns the lesson row plus `viewer_role` ('tutor'|'student') or null, with the same answer
  for "no such lesson" and "not yours". `cw_get_or_create_workspace($pdo, $lesson)` gives the
  pair's workspace (`pair:<tutor>:<student>`, or `demo:<lesson>` for demos). Use these for
  authorization and for the per-pair "last book and page" memory.
- **Putting an image on the board:** inside `initWhiteboard`,
  `addImageToBoard(dataURL, mimeType, w, h, yOffset, origin)` adds an image element via
  `makeImageElement(..., origin)`, which writes `customData: { assetKind: 'import', origin }`.
  Imported assets are protected from the eraser (marks over them erase, the page does not).
  These functions are private to the closure today. Expose a small, deliberate API, e.g.
  `window.lessonWhiteboardAddImage({ dataURL, mimeType, width, height, provenance })`, and
  record provenance as `{ origin: 'library', materialId, page }`. Keep the existing upload
  path working unchanged.
- **Board size limit.** Board scenes, including image data URLs, are saved as JSON through
  `api/whiteboard.php`, and `importPdfFile()` stops importing when the board would become too
  large to save. Reuse that guard. Render a page at a sensible size (about 1600 to 2000 px on
  the long edge) as **JPEG around 0.85**, not PNG, so one page is a few hundred KB.
- **Recorder.** `recorder.js` draws the main region every frame: the shared screen when
  `#screenPane` is visible, otherwise the `#whiteboard canvas` layers. Add a branch: when the
  book pane is visible, draw the book's page canvas. Recording is tutor-side only, so the
  tutor's view is what gets recorded.
- `components/page_loader.php` lazy-loads images and skips anything inside
  `.modal-backdrop, .hidden, [hidden]`; the room does not use it, but do not copy its lazy
  logic into a hidden pane (an IntersectionObserver in a hidden or zero-height box never fires).

## 5. What to build

### A1. Book list for a lesson
New endpoint, e.g. `api/lesson_books.php?lesson_id=N` (GET, JSON):
- Session required, and `cw_authorize_lesson()` must pass. Otherwise 403 with the same body.
- Returns published books (`material_type = 'Book'`, and PDFs only, by `file_url` ending
  `.pdf`), subject matches first (`lessons.subject_id`, which is nullable), then the rest.
  Each item: `id, title, description, grade_level, subject_name, matches_subject`.
- A second action, e.g. `?lesson_id=N&open=<material_id>`, returns `{ url, expires_at }`, the
  presigned URL (see the traps above), plus `page_count` if known. Students may only open
  published books. Log nothing sensitive.

### A2. Book viewer in the room
- A **Books** button in the room header for the tutor. It opens a picker (modal or drawer)
  with the list, a search box, and "For this subject" and "All books" groupings.
- A `#bookPane` main view: the current page rendered to a canvas (fit to width, with zoom in,
  zoom out and fit), previous and next buttons, "Page X of Y" with jump-to-page, and a lazily
  rendered thumbnail strip that can be collapsed.
- pdf.js must load **lazily and by range**: `getDocument({ url, disableAutoFetch: true,
  disableStream: true, rangeChunkSize: 65536 or larger })`. Render the current page first,
  then prefetch the next page. Cancel stale render tasks when pages change quickly. Never load
  the whole 84 MB file.
- Clear states: loading (with the book title), file unavailable, and network lost with Retry.
- Keyboard: left and right arrow keys turn pages for whoever is in control, but only when
  focus is not in an input or on the whiteboard.

### A3. Presenting and following
Messages on topic `'book'` (JSON, small):
- Tutor to student: `{ t:'state', materialId, page, zoom, browse: bool, rev }` whenever the
  book, page, zoom or browse permission changes (throttle to at most about 5 per second).
  `{ t:'close' }` when the tutor closes the book.
- Student to tutor: `{ t:'req' }` on join, so a late joiner receives the current state.
- The student ignores `'state'` from anyone who is not `hostIdentity`. `rev` increases
  monotonically, so out-of-order packets are dropped.
- Student UI: a "Following tutor" chip while following. With browse off, navigation controls
  are hidden or disabled. With browse on, the student can move freely and sees "Back to
  tutor's page". The tutor sees whether the student is following (optional: the student
  sends `{ t:'where', page }`, throttled).
- When the tutor opens a book, the student's main view switches to the book, the same way a
  screen share switches it today.
- The book view must work with `room === null` (no call). It then simply does not sync.

### A4. Remember the last book per pair
- Migration `migrations/2026_09_1x_classroom_book_state.sql` (additive, `IF NOT EXISTS`, no
  foreign keys to `users` or `lessons`, for the same reason given in
  `migrations/2026_09_13_classroom_workspaces.sql`): e.g. table `classroom_book_state`
  (`workspace_id` PK, `material_id`, `page`, `zoom`, `updated_by`, `updated_at`).
- The tutor's client saves it, throttled (for example on page change after 2 seconds idle,
  and on leave). Only the tutor can write it.
- When the room opens, the tutor is offered "Continue <title>, page N" rather than having
  the book auto-open over the board.

### A5. Put this page on the board
- A button in the book toolbar, available to the tutor, and to the student when browse is on
  (drawing on the board is already allowed for both).
- Render the current page offscreen at the chosen size, convert to JPEG, and add it via the
  new whiteboard API with provenance `{ origin:'library', materialId, page }`. Then switch the
  view to the board and scroll to the image. The board's own sync and save carry it to the
  other side; do not send the image over `'book'`.
- Respect the board size guard and tell the user plainly if the board is full.

### A6. Recording
- `recorder.js` draws the book page canvas when the book pane is the visible main view.

### A7. Layout
- Works at 375 px wide (phone) and on desktop. On a phone the thumbnail strip collapses and
  the toolbar wraps. `<main>` style flex children need `min-w-0` or long titles push the page
  sideways.
- Light and dark contrast must pass WCAG AA for all new text.

## 6. Security checklist (each needs a test)

- Only the lesson's tutor or student can list or open books through the lesson endpoints.
  Everyone else gets 403, with the same response for a non-existent lesson.
- Students cannot open unpublished materials, even by guessing an id.
- `material_id` must be an integer that exists, is a Book, and is a PDF. No path is ever
  taken from the client.
- Only the tutor can write `classroom_book_state`.
- Titles and descriptions are rendered with `textContent` (test with a title containing
  `<img src=x onerror=alert(1)>` and an apostrophe).
- A student's `'book'` packet cannot move the tutor's view (unit-test the handler logic with
  a fake participant identity if you cannot run two real clients).

## 7. Tests (required)

Follow the existing pattern in `tests/classroom/` (`run.php` orchestrates; each suite gets a
fresh database from `prepare.php`; the app runs from the disposable copy on port 8099).
Add `learning_materials` fixture rows (published and unpublished, subject match and not, one
with a missing file) and small synthetic multi-page PDFs as fixture files. The existing
`prepare_app.php` writes placeholder PDFs; add a real 3 to 5 page PDF (generate it with a few
lines of raw PDF, or commit a tiny one).

- **PHP HTTP tests:** access control (tutor, student, other tutor, other student, signed out),
  unpublished blocked for students, subject-first ordering, the whole library when the subject
  has none, open returns a usable URL (in tests R2 is off, so the local path with Range
  support must return 206 for a ranged GET), book state writes by the tutor only.
- **Browser tests (Playwright, as `tests/classroom/browser_board.mjs` does, using a harness
  page that boots the room with `room === null`):** open the picker, open a book, page
  forward and back, zoom, jump to page, the unavailable file state, put a page on the board
  and confirm a new image element with the right `customData` provenance appears, the board
  still saves after that, and the 375 px layout has no horizontal overflow.
- **Not testable here:** two real clients syncing over LiveKit. Write the exact manual check
  for Paul instead (section 9).
- The existing suites must still pass: `php tests/classroom/run.php`,
  `php tests/whatsapp/run.php`, and the tutor regression (`python tests/tutor/prepare.py`,
  serve it on port 8088, `python tests/tutor/regression.py`).
- Windows trap: in `run.php`-style runners, start servers with the **array form** of
  `proc_open([...])`. The string form goes through cmd.exe, `proc_terminate` kills only the
  wrapper, and the orphaned `php -S` holds the output pipe open so the run appears to hang.

## 8. Out of scope

Classroom chat. Drawing directly on the book. Uploading or editing materials. Non-PDF
materials (PPT, video, audio). A student-side library outside lessons. Changing
`api/file_url.php` behaviour for existing pages.

## 9. What to hand back

1. Commits on `master`, one per coherent step, with a summary of each.
2. `docs/classroom-books.md`: what it does, the sync protocol, the files changed, the
   migration, deploy steps and rollback, and what the tests do not cover.
3. Test output from every suite.
4. **Deploy steps, not executed.** Use the proven route: tar the changed files, `scp` to
   `/root`, stage in `/root/<name>-stage`, `php -l` each PHP file, back up the replaced files to
   `/root/backups/portal-before-<name>.tar.gz` and the database with `mysqldump
   --single-transaction` to `/var/backups/bloomtutsys/` (the password is in
   `/root/.bloomtutsys_db_pass`, user `bloomtutsys`), apply the migration, then
   `install -D -o root -g www-data -m 0640` each file and `cmp` it. The previous deploy
   scripts in `/root` on the server are working examples.
5. A **manual check for Paul** with the two test accounts, which share lesson 363:
   tutor `test@bloom.com` and student `student@example.com` in two browsers. Cover opening a
   book, the student following page turns, browse on and off, "Back to tutor's page", a late
   join landing on the current page, "Put this page on the board" appearing for both, the
   recording showing the book, and reopening the lesson resuming the same book and page.

## 10. Needs Paul (ask, do not assume)

- Deploying anything to the live site.
- Any change to the R2 bucket or its CORS rule.
- Any change to existing tables (additive new tables are fine).

Time budget: about 5 to 7 hours of focused work. If it is clearly going to run well over,
stop at a working, tested subset (A1, A2, A5 first, then A3, A4, A6, A7) and hand back.
