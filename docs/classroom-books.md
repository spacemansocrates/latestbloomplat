# Books inside lessons, Feature 2A

Implemented on `master` and deployed to production on 2026-09-14 as
`classroom-books-20260914-071801`. The additive bookmark migration and the 13-file
release manifest were installed. Neither the R2 bucket nor its CORS configuration
was changed.

Continuous tutor following, always-visible thumbnails and SVG lesson controls were
deployed as `classroom-follow-20260914-090720` on the same day. See the deployment
record below for this follow-up release.

## Behavior

The tutor's **Books** button opens published PDF Books from Materials. Subject
matches are shown first. Search includes every published book and its description,
subject and grade. A lesson with no matching subject, including a null subject,
gets the full library. Missing files have an explicit unavailable state.

The main area has **Board**, **Book** and **Shared screen** tabs. The tutor chooses
the view and the student follows continuously. Student tab, page and zoom controls
cannot detach from the tutor. This supersedes the original independent browsing
behavior at Paul's request. Each tutor update includes the selected view, book
page/zoom/scroll and board center/zoom. Students with different window sizes see
the same board center and the same relative book scroll position. Whiteboard
drawing remains collaborative.

A screen share has a preview for the sender as well as the receiver, so the tutor
can select Shared screen on either side of a share. A tutor share takes precedence
if both people share. If the student's video track arrives after the tutor's view
update, that requested view is retained and activated when the track arrives.
An asynchronously loaded PDF never overrides the tutor's newer tab selection.

The viewer offers previous/next, page jump, zoom and fit to width. Arrow keys turn
pages only when the viewer is active and the user has control; inputs, editable
content, the whiteboard and tab-navigation keys are excluded. Thumbnails are rendered
only as they approach the visible part of the strip. Thumbnails are always visible,
including on phones; there is no thumbnail toggle. The thumbnail cache keeps at
most 24 canvases. On phones the toolbar wraps,
and camera tiles collapse when opening Book, with their existing toggle available.
Zoomed pages scroll inside their pane without widening the document. New controls
have explicit light and dark colors.

**Put this page on the board** is available to the tutor. It renders a separate
JPEG at 1,800 pixels on the long edge,
quality 0.85. The whiteboard adds, scrolls to, syncs and saves the image through its
existing mechanisms. The student automatically switches to Board and follows the
tutor's viewport to the imported image, even when it is below previous board work.
Controls use SVG icons from the existing portal sprite, with an inline SVG for fit.

Image provenance survives the scene save and sync:

```json
{"assetKind":"import","origin":"library","materialId":10,"page":2}
```

The eraser continues to remove marks over imported pages without removing the page.
Deliberate selection and Delete still remove an import. The shared size guard counts
the **existing scene and files plus the new image**, allowing up to 24 MB with an
8 MB margin under the API's 32 MB ceiling. PDF uploads reuse that guard. A full
board leaves the user in Book with a clear message, and no image is added.

The next lesson for the same tutor/student pair offers **Continue title, page N**
above Board. It does not open the book automatically. Closing a book retains this
position, the recommended interpretation of the brief. Students do not independently
resume old state before a tutor presents. Unpublished or deleted books disappear
from the resume offer. Demo lesson memory is isolated by lesson.

The tutor's recorder draws the visible book canvas, or the shared screen/whiteboard
when those views are selected. It records the current page canvas scaled to the
recording region; it does not reproduce the viewer's scrolled crop at high zoom.
The viewer, board imports and book-position saves work when the call returns
`room === null`; there is then no real-time book sync or automatic recording.

## Endpoints and storage

`api/lesson_books.php?lesson_id=N` requires an authenticated Tutor or Student who
passes `cw_authorize_lesson()`. Signed-out callers, outsiders, invalid lesson IDs
and nonexistent lessons all receive HTTP 403 with `{"error":"Forbidden"}`.

| Request | Result |
| --- | --- |
| GET | `{books: [...], resume: {...} or null}`; resume is tutor-only |
| GET with `&open=ID` | `{url, expires_at, id, title}` for a published PDF Book |
| GET/HEAD with `&stream=ID` | Local file, reauthorized on every request |
| POST JSON `{material_id,page,zoom}` | Tutor-only save, `{saved:true}` |

All material IDs are validated. File paths come exclusively from the database,
must normalize to one PDF filename under `uploads/materials/`, and are checked
against a published Book. There is no arbitrary file-path parameter. Unknown,
unpublished and non-book IDs have the same 404 response for both roles. A known
book with an absent file reports `This book's file is unavailable`.

Writes require `application/json`, reject cross-site Fetch Metadata, and expose no
cross-origin CORS permission. Page numbers must be positive integers and zoom must
be between 0.25 and 3. Requests are capped at 4 KB. Clients never choose a workspace;
it is resolved with the existing pair/demo helpers. Book titles and descriptions
are inserted into the DOM with `textContent`, never `innerHTML`.

For R2, the endpoint returns a presigned URL as JSON, and pdf.js receives that URL
directly. It never relies on the existing gateway's redirect for ranged reads.
Expiry is the greater of two hours or the planned lesson time remaining plus
30 minutes, capped at three hours. The viewer refreshes a URL before the next render
when fewer than 60 seconds remain, and attempts one document reopen after a range
or rendering failure, preserving the requested page. A repeated failure exposes
Retry. An endpoint authorization failure is not retried as a signed-URL failure.

With R2 disabled, the JSON URL points back to the new endpoint's `stream` action.
It supports HEAD and single byte ranges: closed, open-ended and suffix. Valid
requests return 206 with Content-Range/Length and Accept-Ranges. Unsupported or
unsatisfiable ranges return 416. The session lock is released before I/O.
`storage_stream_local()` gained an opt-in Range argument; existing callers and
`api/file_url.php` retain their previous non-range behavior and access policy.

The existing vendored pdf.js **3.11.174** and worker are reused. Documents load with
`disableAutoFetch: true`, `disableStream: true`, `rangeChunkSize: 65536` and
`isEvalSupported: false`. The current page renders before the next page is prefetched.
Canceled/stale render results cannot replace a newer page. Display canvases are
capped at 2,400 pixels on the long edge. The room's pre-existing Tailwind CDN script
was replaced with local `vendor/room-utilities.css`; no new CDN dependency or server
build step was added.

## Sync protocol

Reliable LiveKit data uses topic `book`. Each packet is a small JSON object; incoming
packets over 4 KB are rejected. No file bytes, presigned URLs or titles are broadcast.

```javascript
// Tutor control, coalesced to at most approximately five sends per second:
{t:'state', materialId, page, zoom, browse: false, view, scrollX, scrollY, board: {x,y,zoom}, rev}
{t:'close', view, board: {x,y,zoom}, rev}
// Student requests the current state on joining or reconnecting:
{t:'req'}
```

`view` is Board, Book or Screen in lowercase. Scroll values are fractions from zero
to one, and board coordinates represent the viewport center in scene units.
`browse` is retained for wire compatibility but no longer enables student browsing.
Malformed views, scroll values and board positions are rejected before consuming
a revision. Presentation changes are coalesced with page changes, including when
there is no book open. The additional revision on `close` prevents a delayed state from reopening a closed
book. Revisions monotonically increase in the tutor client, initialized from the
current millisecond timestamp so normal reloads start after the previous session.
Students accept control only from the exact `hostIdentity` returned by the existing
token endpoint and carried on the connected room by `room.js`. Equal or lower
revisions, malformed fields, missing identities and other topics are ignored.
The tutor ignores all incoming state/close packets, regardless of sender. A `req`
can only cause the tutor to resend their own current state. Reconnection and peer
arrival trigger resynchronization. Student position reporting is not implemented;
the optional tutor-side following indicator is omitted.

Book position saves are debounced for two seconds and serialized, coalescing newer
positions while a request is in flight. Finish/Leave and the header Back action await
the book save; a small JSON unload beacon is a best-effort fallback. A refused save
does not end the peer's lesson until the tutor chooses to leave anyway. Abrupt
browser/process termination can still lose the last unsaved position. Multiple
simultaneous tutor tabs use last-completed database write semantics; this table is
a bookmark, not the whiteboard's revision-controlled scene store.

## Files and migration

The exact production file list is [classroom-books-deploy-files.txt](classroom-books-deploy-files.txt).

| File | Purpose |
| --- | --- |
| `api/lesson_books.php` | Lesson authorization, picker, direct URL/local stream and position saves |
| `migrations/2026_09_13_classroom_book_state.sql` | Additive bookmark table |
| `integrations/file_storage.php` | Opt-in local byte ranges |
| `assets/livekit/book_sync.js` | Identity, revision and throttle rules |
| `assets/livekit/books.js`, `books.css` | Viewer, picker, thumbnails, following and memory |
| `assets/livekit/views.js` | Main-view ownership with or without LiveKit |
| `assets/livekit/vendor/room-utilities.css` | Local CSS replacing the runtime CDN |
| `components/lesson_book_pane.php` | Shared book toolbar and canvas markup |
| `components/lesson_room_live_shell.php` | Books button, dialog, tabs, initialization and leave saves |
| `assets/livekit/room.js` | Pass host identity, share/view integration, save before ending peer |
| `assets/livekit/whiteboard.js` | Deliberate async image API, provenance and existing-scene size guard |
| `assets/livekit/recorder.js` | Composite the book page while Book is visible |

`classroom_book_state` stores `workspace_id` (primary key), `material_id`, `page`,
`zoom`, `updated_by` and `updated_at`. It uses `CREATE TABLE IF NOT EXISTS`. No
existing tables or columns are altered. It has no foreign keys to users or lessons,
for the engine/type compatibility reasons in the existing workspace migration.
The workspace tables from the earlier durable-whiteboard release must already exist.

Tests add a raw-PDF fixture generator, published/unpublished/missing books, a complete
room-shell harness, book HTTP/browser suites and protocol tests. The runners use
array-form `proc_open` for servers, private scratch session directories (with quoted
INI paths for Windows), port-occupancy checks and clean server shutdown. The old
board browser harness now signs in via the same real authentication endpoint without
visiting unrelated dashboards. No customer uploads or production `.env` are copied.

## Validation and limits

Full outputs are in `docs/test-results/classroom-books/`:

- `follow.txt`: complete classroom regression run for continuous tutor following,
  including **81 book HTTP**, **17 protocol**, **25 book browser** and the existing
  workspace, API, storage and whiteboard checks. Screen-share tests run the real
  room handlers with substituted local and remote media tracks.
- `classroom.txt`: workspace/migration, old HTTP/file gateway, old board browser,
  **81 book HTTP**, **14 book protocol** and **23 book browser** checks. The run also
  rejects PHP warnings/errors in the disposable server log.
- `whatsapp.txt`: **36 unit** and **80 HTTP** checks.
- `tutor.txt`: **149 tutor regression** assertions.
- `lint.txt`: syntax checks for changed PHP and JavaScript.

The browser suite loads the real shell and inline scripts in fresh Chromium tabs.
It exercises search and hostile text, page turns/jump/zoom, stale render cancellation,
thumbnails, unavailable files, network Retry, a simulated expired-range failure,
whiteboard import/provenance/save/full-board refusal, cross-lesson Continue, both
phone themes without horizontal overflow, fake-participant following and close,
screen-tab ownership and recorder canvas selection. Two independent browser sessions
also relay the real book and whiteboard packets to verify tab following, image
imports, different-sized board viewports, book scrolling, reconnects and slow PDF
loads. This relay exercises the application handlers without a live LiveKit server.
It measures the initial PDF
transfer rather than treating PHP lint as JavaScript validation. The synthetic
4 MB range fixture required about 67 KB for initial rendering and adjacent-page
prefetch. This is not a measurement of the live 84 MB book. Phone screenshots are
written into the classroom scratch directory for visual inspection.

Not covered here: two real LiveKit clients, real packet loss or a tutor clock moving
backward across reloads, real R2 preflight/ranges/URL expiry, all six production PDFs,
Safari/iOS, audio quality, or the live recording upload/playback pipeline. The
recording browser test checks the canvas source with a local upload stub. The
long-running URL refresh test simulates a 403; it does not wait two or three hours.
Complete the manual checks below in fresh tutor and student browser sessions.

## Run locally

Never run application entry points or migrations in the project directory, where
`.env` points at production. These test preparation commands use only the disposable
MariaDB at `127.0.0.1:33316` and write an isolated app configuration.

```powershell
# Start only if the throwaway MariaDB is not already listening on 33316.
# Launch hidden when running this in the background.
$scratch = Join-Path $env:TEMP 'tutsys-tutor-20260913-110825'
Start-Process 'C:\xampp\mysql\bin\mysqld.exe' -WindowStyle Hidden -ArgumentList @(
  '--no-defaults', ('--datadir=' + (Join-Path $scratch 'mysql-data')),
  '--port=33316', '--bind-address=127.0.0.1'
)

& C:\xampp\php\php.exe tests/classroom/run.php
& C:\xampp\php\php.exe tests/whatsapp/run.php
python tests/tutor/prepare.py

# Serve the tutor disposable copy, not C:\xampp\htdocs\tutsys3.1.
$scratch = $scratch.Replace('KAMEHA~1', 'Kamehameha')
$sessions = Join-Path $scratch 'sessions-books'
New-Item -ItemType Directory -Force -Path $sessions | Out-Null
$server = Start-Process C:\xampp\php\php.exe -WindowStyle Hidden -PassThru -ArgumentList @(
  '-d', ('session.save_path=' + $sessions.Replace('\','/')),
  '-S', '127.0.0.1:8088', '-t', (Join-Path $scratch 'www')
)
try { python tests/tutor/regression.py }
finally { Stop-Process -Id $server.Id }
```

The classroom runner expects Node/Playwright in
`%TEMP%\tutsys-classroom-test\node_modules\playwright`, as the existing suite does.
Node and Playwright are test dependencies on Windows only. Run the classroom and
WhatsApp suites sequentially because both use the app copy on port 8099.

## Deployment record

The continuous-following release `classroom-follow-20260914-090720` installed the
seven files in [classroom-follow-deploy-files.txt](classroom-follow-deploy-files.txt)
after the complete classroom suite passed. No database change was needed. The
previous live files matched the original release, and there were zero active
lesson windows. Its SHA-256 is
`609e9a739b187acc1acd89c8208f28b4bda15e44f1b3a029c76ee8255b3b67e5`.
The stage is `/root/classroom-follow-20260914-090720-stage` and the prior-file backup
is `/root/backups/portal-before-classroom-follow-20260914-090720.tar.gz`.
All seven installed files matched the staged release; all five JavaScript files
also matched over HTTPS. PHP lint, the homepage and signed-out API guard passed.
Both participants must reload existing lesson tabs to load the new client code.

The production bundle SHA-256 was
`209e5592f0a2fea1c7b8bc01a23572f5aae7e90b5074782c21a73f4c03d97d1b`.
Deployment ran with no active lesson window. The retained rollback artifacts are:

- stage and file lists: `/root/classroom-books-20260914-071801-stage`
- prior-file archive: `/root/backups/portal-before-classroom-books-20260914-071801.tar.gz`
- database dump: `/var/backups/bloomtutsys/bloomtutsys-before-classroom-books-20260914-071801.sql.gz`

The first guarded attempt, `classroom-books-20260914-071628`, rolled back before
acceptance because its deployment verifier expected seven columns instead of the
six defined by the migration. The rollback was checked byte-for-byte: five replaced
files were restored, eight new files were removed, and the new table was absent.

The accepted release passed staged and installed PHP lint, exact installed-file
comparisons, HTTPS asset comparisons, the signed-out API guard, database schema and
book-count checks, ownership/mode checks, and Apache error-log inspection. Apache
uses `mod_php`; `opcache.validate_timestamps=1`, so no service reload was required.

## Deployment procedure

Paul must approve deployment. Use a quiet window with no active lessons, because
the room shell and its shared scripts change together. No R2 or CORS edit is needed.
Do not copy `.env`, uploads, test harnesses, test outputs or the Node modules.

From the local repository, after reviewing the commits and approving the release:

```powershell
$bundle = Join-Path $env:TEMP 'classroom-books-20260914.tar.gz'
tar -czf $bundle -T docs/classroom-books-deploy-files.txt docs/classroom-books-deploy-files.txt
scp $bundle root@89.116.22.96:/root/
```

Then on the VPS, as root. These commands stage and lint before backing up and
installing; the schema is applied before new code is made live. The manifest puts
the shared room shell last.

```bash
set -Eeuo pipefail
umask 077
name="classroom-books-$(date +%Y%m%d-%H%M%S)"
stage="/root/$name-stage"
docroot=/var/www/portal.bloomeducationonline.com/public_html
mkdir -p "$stage" /root/backups /var/backups/bloomtutsys
tar -xzf /root/classroom-books-20260914.tar.gz -C "$stage"
tr -d '\r' < "$stage/docs/classroom-books-deploy-files.txt" > "$stage/files.list"

while IFS= read -r file; do
  test -f "$stage/$file"
  case "$file" in *.php) php -l "$stage/$file" ;; esac
done < "$stage/files.list"

: > "$stage/replaced-files.list"
: > "$stage/new-files.list"
while IFS= read -r file; do
  if test -f "$docroot/$file"; then
    printf '%s\n' "$file" >> "$stage/replaced-files.list"
  else
    printf '%s\n' "$file" >> "$stage/new-files.list"
  fi
done < "$stage/files.list"
tar -czf "/root/backups/portal-before-$name.tar.gz" -C "$docroot" -T "$stage/replaced-files.list"

export MYSQL_PWD="$(cat /root/.bloomtutsys_db_pass)"
mysqldump --single-transaction --user=bloomtutsys bloomtutsys \
  | gzip > "/var/backups/bloomtutsys/bloomtutsys-before-$name.sql.gz"
mysql --user=bloomtutsys bloomtutsys \
  < "$stage/migrations/2026_09_13_classroom_book_state.sql"
unset MYSQL_PWD

while IFS= read -r file; do
  install -D -o root -g www-data -m 0640 "$stage/$file" "$docroot/$file"
  cmp "$stage/$file" "$docroot/$file"
done < "$stage/files.list"
printf 'Release: %s\nStage and rollback lists: %s\n' "$name" "$stage"
```

If PHP OPcache timestamp checks are disabled, invalidate the affected scripts via
the established PHP/Apache service procedure for this VPS before testing. Do not
add a public OPcache-reset endpoint. Open fresh browser tabs for the checks below;
app script/style URLs are cache-busted by file modification time. Keep the stage,
lists, tar backup and database backup until the release is accepted.

### Rollback

Restore the pre-release code archive and remove only files recorded as new by that
same deployment. Substitute the exact release name printed above:

```bash
set -Eeuo pipefail
name=classroom-books-YYYYMMDD-HHMMSS
stage="/root/$name-stage"
docroot=/var/www/portal.bloomeducationonline.com/public_html
tar -xzf "/root/backups/portal-before-$name.tar.gz" -C "$docroot"
while IFS= read -r file; do
  # Manifest paths are relative and limited to this release's app directories.
  case "$file" in api/*|assets/livekit/*|components/*|integrations/*|migrations/*) ;; *) exit 1 ;; esac
  case "$file" in *..*) exit 1 ;; esac
  rm -f -- "$docroot/$file"
done < "$stage/new-files.list"
```

Leave `classroom_book_state` in place, unused; no database restore or table drop is
needed. Previously placed book pages remain normal images in the durable board and
continue to work with the preceding whiteboard client. Routine rollback must not
restore the full database backup over lesson or board work created since deployment.

## Paul's manual check

Use fresh tabs in two separate browsers: tutor `test@bloom.com` and student
`student@example.com`, sharing lesson **363**. Use the existing account passwords.
Tutor URL: `/tutor/join_lesson_live.php?lesson_id=363`.
Student URL: `/student/lesson_room_live.php?lesson_id=363`.

1. Verify Board and cameras initially work. Open Books as tutor, search across the
   library, then open a book. Both browsers should switch to Book. In DevTools
   Network, confirm direct R2 range requests return 206 without a redirected-CORS
   error; the large book should become readable without an 84 MB full download.
2. Turn several pages, jump and zoom as tutor. The student follows and cannot turn
   pages independently. Confirm the book title and page labels match.
3. Switch between Book and Board as tutor. The student switches automatically.
   Scroll and zoom in both views, and verify the student follows the tutor's
   position. Student navigation cannot detach from the tutor. Switch books and
   verify both browsers show the new tutor book/page.
4. Close the student's tab. Turn the tutor to a later page, then rejoin as student.
   The student should receive that current page. Also test a LiveKit reconnect.
   Close the book as tutor and verify the student's Book tab disappears.
5. Reopen a book and put a page on Board. Both browsers switch to Board automatically.
   Both should see the image. Write on it from each browser, erase marks, and confirm
   the page remains. Import another page below existing content and verify that both
   browsers move to that new image. Refresh the
   room and verify the imported pages and annotations were saved.
6. Share a screen from either browser. Both should show the same Shared screen.
   Tutor switches back to Board or Book must also switch the student. Stop the
   share and confirm its tab disappears. Check cameras and microphone controls.
7. Spend time on a distinctive book page during a recording, then switch to Board.
   Complete the lesson through the normal approved test workflow and inspect the
   recording: book/page turns and Board content should both be present, with audio.
8. Reopen a joinable lesson for this same pair. Board remains the initial view and
   Continue offers the last book and page. Click it to resume. Closing the book
   before leaving should not erase that offer.
9. Test at 375 px, including a long title, both themes, zoom, always-visible thumbnails,
   and the camera toggle. Check for sideways document scrolling. Toggle the network
   offline while requesting a new page, then restore it and Retry. Check an absent
   file produces the unavailable message. For a long-session check, leave the room
   open past its original signed URL's expiry and navigate to an uncached page.

These checks use real lesson actions. Paul should choose an appropriate joinable
test lesson/time for steps that finish the lesson; no fixture or production status
is edited by the implementation or its automated tests.
