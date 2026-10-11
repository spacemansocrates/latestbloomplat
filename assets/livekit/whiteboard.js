/**
 * assets/livekit/whiteboard.js
 * Collaborative Excalidraw whiteboard for the 1:1 lesson room.
 *
 * - Renders Excalidraw (React UMD) into #whiteboard.
 * - Syncs elements + files to the other participant over LiveKit data messages
 *   (topic "wb", chunked to stay under the ~15KB packet limit).
 * - Saves to api/whiteboard.php, which stores the board against the tutor-student
 *   WORKSPACE rather than the lesson, so the next lesson opens the same board.
 * - Imports PDFs (rendered to images via PDF.js) and image files onto the board.
 *
 * Requires globals: React, ReactDOM, ExcalidrawLib (0.17.x), LivekitClient, pdfjsLib.
 * Usage: initWhiteboard(room, { lessonId, saveUrl, statusEl, uploadBtn, uploadInput })
 *
 * `room` may be null: when the video call could not start the board still loads,
 * saves and imports -- it just has nobody to sync with. Losing LiveKit should not
 * also cost the lesson its whiteboard.
 *
 * ---------------------------------------------------------------------------
 * Durability, and the failure this file is built around.
 *
 * The previous version treated a failed load as an empty board: load() caught every
 * error and returned {elements: [], files: {}}. Nothing bad happened immediately --
 * but the next stroke marked the scene dirty, and the autosave then wrote that empty
 * scene over a board that was fine on the server. A network blip during load cost
 * the pair their work.
 *
 * So loading now has three outcomes, not two, and they are kept distinct:
 *   loaded  - the server answered; the board may legitimately be empty
 *   failed  - we do not know what is on the server; saving is BLOCKED until a
 *             successful reload, and the user is told
 *   (never) - "empty because something went wrong"
 *
 * Saves are revision-aware. Every save states the revision it was based on; the
 * server accepts it only if that is still current, and returns 409 with the newer
 * scene otherwise. A stale tab, a slow retry, or a reconnecting client merges rather
 * than overwrites. Saves are also serialized -- one in flight at a time, with a
 * trailing save coalesced -- so a burst of edits cannot produce out-of-order writes.
 *
 * Leaving flushes explicitly and is awaited (flushNow, exposed as
 * window.lessonWhiteboardFlush) rather than relying on the unload beacon. The beacon
 * remains only as a last-resort fallback, and only for small payloads: a board
 * carrying imported PDF pages exceeds what sendBeacon will accept, and it fails
 * silently when it does.
 */
(function () {
  'use strict';

  var CHUNK = 13000;              // bytes per data message chunk (limit is ~15KB)
  var SAVE_INTERVAL_MS = 5000;
  var BEACON_LIMIT = 60 * 1024;   // sendBeacon is unreliable above ~64KB; stay under

  function throttle(fn, ms) {
    var t = null, pending = false;
    return function () {
      if (t) { pending = true; return; }
      fn();
      t = setTimeout(function () { t = null; if (pending) { pending = false; fn(); } }, ms);
    };
  }

  function randomId(len) {
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789', s = '';
    for (var i = 0; i < len; i++) s += chars[Math.floor(Math.random() * chars.length)];
    return s;
  }

  // Minimal Excalidraw image element (v0.17 schema).
  //
  // customData carries our own marker. Excalidraw round-trips customData untouched,
  // so it survives save/load and peer sync, which is what makes the eraser rule below
  // work on a restored board and on the other participant's screen -- not just on the
  // client that did the importing.
  function makeImageElement(fileId, x, y, w, h, origin) {
    return {
      id: randomId(21), type: 'image', x: x, y: y, width: w, height: h, angle: 0,
      strokeColor: 'transparent', backgroundColor: 'transparent', fillStyle: 'hachure',
      strokeWidth: 1, strokeStyle: 'solid', roughness: 1, opacity: 100,
      groupIds: [], frameId: null, roundness: null,
      seed: Math.floor(Math.random() * 2 ** 31), version: 1,
      versionNonce: Math.floor(Math.random() * 2 ** 31),
      isDeleted: false, boundElements: null, updated: Date.now(), link: null, locked: false,
      status: 'saved', fileId: fileId, scale: [1, 1],
      customData: Object.assign({ assetKind: 'import' },
        typeof origin === 'object' ? origin : { origin: origin || 'upload' }),
    };
  }

  // Is this element imported content (a PDF page or an uploaded image) rather than
  // something drawn in the lesson?
  //
  // Every image element is, in this app: the only routes onto the board are the
  // upload button and the PDF importer, and nothing the drawing tools produce is of
  // type 'image'. So the test is the element type, which also covers boards restored
  // from the old lesson-keyed storage whose images predate the customData marker --
  // checking only for the marker would leave every historic PDF page erasable and
  // make the fix look like it worked when it only worked on new imports.
  //
  // customData is still written on import, because it carries the provenance the
  // materials work will need to tell a library page from an ad-hoc upload.
  function isImportedAsset(el) {
    return !!el && el.type === 'image';
  }

  window.initWhiteboard = async function (room, cfg) {
    var LK = (typeof LivekitClient !== 'undefined') ? LivekitClient : null;
    var synced = !!(LK && room && room.localParticipant);
    var api = null;
    var applyingRemote = false;
    var lastVersions = new Map();   // element id -> last seen/broadcast version
    var sentFiles = new Set();      // fileIds already shared with the peer
    var dirtySinceSave = false;
    var incoming = {};              // chunk reassembly buffers

    // Board identity, from the server. pageId/revision are what make a save legal.
    var pageId = null;
    var revision = null;
    var workspaceId = null;
    var pages = [];

    // The load-failure latch. While true, nothing is written to the server: we do
    // not know what is there, so anything we send could destroy it.
    var loadFailed = false;

    // Save serialization.
    var saveInFlight = false;
    var savePendingAgain = false;

    var SPRITE = window.LESSON_SPRITE || '../assets/icons/sprite.svg';

    function setSaveStatus(text, kind) {
      if (!cfg.statusEl) return;
      if (!text) { cfg.statusEl.textContent = ''; return; }
      if (!kind) {
        if (/…$/.test(text)) kind = 'busy';
        else if (/fail|error|not saving/i.test(text)) kind = 'error';
        else kind = 'done';
      }
      var icon = kind === 'busy' ? 'loader-2' : (kind === 'error' ? 'alert-circle' : 'check');
      var spin = kind === 'busy' ? ' spin' : '';
      cfg.statusEl.className = 'status-chip text-xs ' +
        (kind === 'error' ? 'text-red-300' : (kind === 'busy' ? 'text-gray-400' : 'text-emerald-300'));
      cfg.statusEl.innerHTML =
        '<svg class="' + spin.trim() + '" aria-hidden="true"><use href="' + SPRITE + '#i-' + icon + '"></use></svg>' +
        '<span></span>';
      cfg.statusEl.lastChild.textContent = text;
    }

    // ---------- transport: chunked JSON over LiveKit data messages ----------
    function sendWb(obj) {
      if (!synced) return;
      var str = JSON.stringify(obj);
      var id = randomId(8);
      var total = Math.ceil(str.length / CHUNK) || 1;
      for (var i = 0; i < total; i++) {
        var frame = JSON.stringify({ c: id, s: i, n: total, d: str.slice(i * CHUNK, (i + 1) * CHUNK) });
        room.localParticipant.publishData(new TextEncoder().encode(frame), { reliable: true, topic: 'wb' });
      }
    }

    function onData(payload, participant, kind, topic) {
      if (topic !== 'wb') return;
      var frame;
      try { frame = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
      if (!frame || typeof frame.c !== 'string' || typeof frame.n !== 'number') return;
      var buf = incoming[frame.c] || (incoming[frame.c] = { parts: [], got: 0 });
      if (buf.parts[frame.s] === undefined) { buf.parts[frame.s] = frame.d; buf.got++; }
      if (buf.got < frame.n) return;
      delete incoming[frame.c];
      var msg;
      try { msg = JSON.parse(buf.parts.join('')); } catch (e) { return; }
      handleMessage(msg);
    }

    // ---------- sync protocol ----------
    function handleMessage(msg) {
      if (!api) return;
      if (msg.t === 'req') {
        broadcastFull();
      } else if (msg.t === 'els') {
        mergeRemoteElements(msg.els);
      } else if (msg.t === 'file') {
        sentFiles.add(msg.file.id);
        api.addFiles([msg.file]);
      } else if (msg.t === 'full') {
        Object.values(msg.files || {}).forEach(function (f) { sentFiles.add(f.id); });
        api.addFiles(Object.values(msg.files || {}));
        mergeRemoteElements(msg.els || []);
      }
    }

    function mergeRemoteElements(remote) {
      if (!Array.isArray(remote)) return;
      var local = api.getSceneElementsIncludingDeleted();
      var map = new Map(local.map(function (e) { return [e.id, e]; }));
      var changed = false;
      remote.forEach(function (el) {
        if (!el || !el.id) return;
        var cur = map.get(el.id);
        if (!cur || el.version > cur.version ||
            (el.version === cur.version && el.versionNonce !== cur.versionNonce && el.versionNonce < cur.versionNonce)) {
          map.set(el.id, el);
          lastVersions.set(el.id, el.version);
          changed = true;
        }
      });
      if (!changed) return;
      applyingRemote = true;
      api.updateScene({ elements: Array.from(map.values()) });
      applyingRemote = false;
      dirtySinceSave = true;
    }

    function broadcastDirty() {
      if (!api || applyingRemote) return;
      var els = api.getSceneElementsIncludingDeleted();
      var dirty = els.filter(function (e) { return lastVersions.get(e.id) !== e.version; });
      if (dirty.length) {
        dirty.forEach(function (e) { lastVersions.set(e.id, e.version); });
        sendWb({ t: 'els', els: dirty });
        dirtySinceSave = true;
      }
      var files = api.getFiles();
      Object.keys(files).forEach(function (id) {
        if (!sentFiles.has(id)) {
          sentFiles.add(id);
          sendWb({ t: 'file', file: files[id] });
          dirtySinceSave = true;
        }
      });
    }

    function broadcastFull() {
      if (!api || !synced) return;
      var files = api.getFiles();
      Object.keys(files).forEach(function (id) { sentFiles.add(id); });
      sendWb({ t: 'full', els: api.getSceneElementsIncludingDeleted(), files: files });
    }

    // ---------- eraser protection for imported content ----------
    //
    // What the eraser should do over an imported PDF page or photo: rub out the marks
    // drawn on top, leave the page itself. What it did: deleted the page, because an
    // imported image is an ordinary Excalidraw element and the eraser deletes whatever
    // it touches.
    //
    // The obvious fix -- locked: true -- is wrong. A locked element cannot be selected,
    // moved, resized or deleted at all, so "erase the marks, keep the page" would
    // become "the page is welded to the board forever", and a wrongly-placed import
    // could never be removed.
    //
    // So the distinction drawn here is INTENT, not capability, which is exactly the
    // distinction the user makes: erasing is not deleting. Excalidraw 0.17 reports the
    // active tool in the appState passed to onChange, so a deletion that happens while
    // the eraser is the active tool can be told apart from Delete-key or context-menu
    // removal and reverted. Selection + Delete still works, dragging still works,
    // resizing still works, and a deliberate removal still syncs and stays removed.
    //
    // The version bump on restore matters: it is what makes the revival win the
    // version comparison in mergeRemoteElements, so the image does not flicker back
    // out when the peer echoes the erased state.
    var assetDeletedState = new Map();  // image element id -> isDeleted at last check

    function protectAssetsFromEraser(elements, appState) {
      var usingEraser = !!(appState && appState.activeTool && appState.activeTool.type === 'eraser');
      var restored = [];

      for (var i = 0; i < elements.length; i++) {
        var el = elements[i];
        if (!isImportedAsset(el)) continue;
        var was = assetDeletedState.get(el.id);
        if (usingEraser && was === false && el.isDeleted === true) {
          restored.push(el.id);
        }
        assetDeletedState.set(el.id, !!el.isDeleted);
      }

      if (!restored.length) return false;

      var next = elements.map(function (el) {
        if (restored.indexOf(el.id) === -1) return el;
        assetDeletedState.set(el.id, false);
        return Object.assign({}, el, {
          isDeleted: false,
          version: (el.version || 1) + 1,
          versionNonce: Math.floor(Math.random() * 2 ** 31),
          updated: Date.now(),
        });
      });

      applyingRemote = true;
      api.updateScene({ elements: next });
      applyingRemote = false;
      return true;
    }

    // ---------- persistence ----------
    //
    // Returns {ok: true, ...} or {ok: false}. It never invents an empty board: the
    // caller must be able to tell "the server says this board is empty" apart from
    // "we could not reach the server", because writing on the second is destructive.
    async function load(requestPageId) {
      var url = cfg.saveUrl + (requestPageId ? (cfg.saveUrl.indexOf('?') === -1 ? '?' : '&') + 'page_id=' + requestPageId : '');
      try {
        var res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) {
          console.warn('whiteboard load: HTTP ' + res.status);
          return { ok: false };
        }
        var body = await res.json();
        if (!body || !body.success || !body.data || !body.data.page) {
          console.warn('whiteboard load: unexpected response', body);
          return { ok: false };
        }
        var d = body.data;
        var files = d.files;
        // PHP re-encodes an empty {} as [] -- Excalidraw needs an object.
        if (!files || Array.isArray(files)) files = {};
        return {
          ok: true,
          elements: Array.isArray(d.scene) ? d.scene : [],
          files: files,
          pageId: d.page.id,
          revision: d.page.revision,
          workspaceId: d.workspace_id,
          pages: d.pages || [],
        };
      } catch (e) {
        console.warn('whiteboard load failed', e);
        return { ok: false };
      }
    }

    function currentPayload() {
      return {
        page_id: pageId,
        revision: revision,
        scene: api.getSceneElementsIncludingDeleted(),
        files: api.getFiles(),
      };
    }

    /**
     * Write the board. Serialized: one request at a time, with at most one trailing
     * save queued, so a burst of edits produces an ordered sequence of writes rather
     * than a race whose winner is whichever response happens to land last.
     */
    async function save(force) {
      if (!api) return false;
      if (loadFailed) return false;                 // we do not know what we would overwrite
      if (pageId === null || revision === null) return false;
      if (!dirtySinceSave && !force) return true;

      if (saveInFlight) { savePendingAgain = true; return false; }
      saveInFlight = true;
      dirtySinceSave = false;

      var ok = false;
      try {
        var res = await fetch(cfg.saveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(currentPayload()),
        });
        var body = await res.json().catch(function () { return null; });

        if (res.ok && body && body.success) {
          revision = body.data.revision;
          var now = new Date();
          setSaveStatus('Saved ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2, '0'));
          ok = true;

        } else if (res.status === 409 && body && body.data && body.data.reason === 'conflict') {
          // Somebody else saved first. Take their scene as the new base, merge our
          // elements into it by the same version rule the live sync uses, and let the
          // next tick save the result. Nothing is discarded on either side.
          revision = body.data.revision;
          if (Array.isArray(body.data.scene)) {
            applyingRemote = true;
            mergeRemoteElements(body.data.scene);
            applyingRemote = false;
          }
          dirtySinceSave = true;
          setSaveStatus('Merging changes…');

        } else if (res.status === 409) {
          // stale_client: this tab cannot prove what it was based on.
          loadFailed = true;
          setSaveStatus('Out of date. Reload the page', 'error');

        } else {
          dirtySinceSave = true;
          setSaveStatus('Save failed', 'error');
        }
      } catch (e) {
        dirtySinceSave = true;
        setSaveStatus('Save failed. Will retry', 'error');
      } finally {
        saveInFlight = false;
      }

      if (savePendingAgain) {
        savePendingAgain = false;
        return await save(true);
      }
      return ok;
    }

    /**
     * Flush before leaving, and tell the caller whether the work is safely stored.
     * room.js awaits this from the Finish/Leave path, so "I pressed End and my work
     * vanished" cannot happen because an unload beacon was dropped.
     */
    async function flushNow() {
      if (loadFailed) return false;
      if (pageId === null || revision === null) return false;

      // Deliberately does NOT check dirtySinceSave and return early.
      //
      // That flag is set by onChange, which is throttled by 200ms. A user who draws a
      // last stroke and immediately presses Finish gets here inside that window, with
      // the flag still false and the stroke only in Excalidraw's memory -- so an
      // early return would report "saved" and then navigate away from work that was
      // never sent. A browser test caught exactly that.
      //
      // Always writing costs one redundant POST when nothing changed, which is
      // nothing next to silently losing the last thing someone wrote.
      setSaveStatus('Saving…');
      var ok = await save(true);
      if (!ok) {
        // One retry: the common case is a single transient failure, and the user is
        // about to navigate away.
        ok = await save(true);
      }
      setSaveStatus(ok ? 'Saved' : 'Could not save. Stay on the page', ok ? 'done' : 'error');
      return ok;
    }
    window.lessonWhiteboardFlush = flushNow;

    // ---------- content import ----------
    function canAddImage(dataURL) {
      // Reserve 8 MB beneath the API's 32 MB ceiling for later annotations and
      // JSON overhead. Count the existing scene too, including previous imports.
      return api && new Blob([JSON.stringify(currentPayload())]).size + dataURL.length + 4096 <= 24 * 1024 * 1024;
    }

    window.lessonWhiteboardAddImage = async function (image) {
      if (!api || loadFailed) throw new Error('The whiteboard is not ready. Retry loading the board first.');
      if (!image || image.mimeType !== 'image/jpeg' || !/^data:image\/jpeg;base64,/.test(image.dataURL)
          || !Number.isFinite(image.width) || !Number.isFinite(image.height) || image.width <= 0 || image.height <= 0
          || !image.provenance || image.provenance.origin !== 'library'
          || !Number.isInteger(image.provenance.materialId) || image.provenance.materialId <= 0
          || !Number.isInteger(image.provenance.page) || image.provenance.page <= 0) {
        throw new Error('This book page could not be added. Please retry.');
      }
      if (!canAddImage(image.dataURL)) throw new Error('This board is full. Start a new board page or remove some imported images.');
      // Validate before changing views, so a full board leaves the error visible
      // in the book toolbar. Then let Excalidraw measure before scrolling to it.
      if (window.lessonViews) window.lessonViews.select('board');
      await new Promise(function (resolve) { requestAnimationFrame(function () { requestAnimationFrame(resolve); }); });
      // A peer can add content during those frames, so enforce the limit again at
      // the mutation point instead of relying only on the earlier UI check.
      if (!canAddImage(image.dataURL)) throw new Error('This board is full. Start a new board page or remove some imported images.');
      addImageToBoard(image.dataURL, image.mimeType, image.width, image.height, undefined, {
        origin: 'library', materialId: image.provenance.materialId, page: image.provenance.page,
      });
      setSaveStatus('Book page added');
      return true;
    };

    function addImageToBoard(dataURL, mimeType, w, h, yOffset, origin) {
      var fileId = randomId(40);
      var width = Math.min(800, w);
      var height = h * (width / w);
      api.addFiles([{ id: fileId, dataURL: dataURL, mimeType: mimeType, created: Date.now() }]);
      var els = api.getSceneElementsIncludingDeleted();
      var maxY = yOffset;
      if (maxY === undefined) {
        maxY = 0;
        els.forEach(function (e) { if (!e.isDeleted) maxY = Math.max(maxY, e.y + e.height); });
        maxY += 40;
      }
      var img = makeImageElement(fileId, 40, maxY, width, height, origin);
      assetDeletedState.set(img.id, false);
      applyingRemote = false;
      api.updateScene({ elements: els.concat([img]) });
      api.scrollToContent([img], { fitToContent: true });
      return maxY + height;
    }

    function importImageFile(file) {
      return new Promise(function (resolve) {
        setSaveStatus('Adding image…');
        var reader = new FileReader();
        reader.onerror = function () { setSaveStatus('Could not read that image', 'error'); resolve(); };
        reader.onload = function () {
          var image = new Image();
          image.onerror = function () { setSaveStatus('Could not read that image', 'error'); resolve(); };
          image.onload = function () {
            addImageToBoard(reader.result, file.type, image.width, image.height, undefined, 'upload');
            setSaveStatus('Image added');
            resolve();
          };
          image.src = reader.result;
        };
        reader.readAsDataURL(file);
      });
    }

    // PDF import renders pages one at a time and yields to the event loop between
    // them, so a long book does not freeze the room while it imports. The old hard
    // cap of 20 pages (with an alert saying so) is gone; what remains is a guard
    // against a single import pushing the board past what the server will accept,
    // which stops with a clear message rather than a failed save later on.
    async function importPdfFile(file) {
      if (typeof pdfjsLib === 'undefined') {
        setSaveStatus('PDF support failed to load', 'error');
        alert('PDF support failed to load (check your internet connection and reload the page).');
        return;
      }
      setSaveStatus('Importing PDF…');
      try {
        var buf = await file.arrayBuffer();
        var pdf = await pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise;
        var y;
        var imported = 0;

        for (var p = 1; p <= pdf.numPages; p++) {
          var page = await pdf.getPage(p);
          var viewport = page.getViewport({ scale: 1.5 });
          var canvas = document.createElement('canvas');
          canvas.width = viewport.width; canvas.height = viewport.height;
          await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
          var dataURL = canvas.toDataURL('image/jpeg', 0.8);

          if (!canAddImage(dataURL)) {
            alert('Imported the first ' + imported + ' page(s). The rest would make this board too large to save '
                + '. Start a new page and continue the import there.');
            break;
          }

          y = addImageToBoard(dataURL, 'image/jpeg', viewport.width, viewport.height, y, 'pdf');
          y += 30;
          imported++;
          setSaveStatus('Importing PDF… page ' + imported + ' of ' + pdf.numPages);

          // Let the browser paint and the sync loop run between pages.
          await new Promise(function (r) { setTimeout(r, 0); });
        }
        setSaveStatus('PDF imported (' + imported + ' page' + (imported === 1 ? '' : 's') + ')');
      } catch (e) {
        console.error('PDF import failed', e);
        alert('Could not import that PDF: ' + e.message);
        setSaveStatus('PDF import failed', 'error');
      }
    }

    // ---------- boot ----------
    var initial = await load();

    if (!initial.ok) {
      // Refuse to start a writable board we cannot trust. Excalidraw still mounts --
      // losing the board entirely would be worse -- but saving stays blocked and the
      // user is told plainly, with a way to try again.
      loadFailed = true;
      initial = { elements: [], files: {}, pages: [] };
      setSaveStatus('Could not load the board. Not saving', 'error');
      if (cfg.statusEl) {
        cfg.statusEl.style.cursor = 'pointer';
        cfg.statusEl.title = 'Click to try loading the board again';
        cfg.statusEl.addEventListener('click', async function () {
          setSaveStatus('Retrying…');
          var retry = await load();
          if (!retry.ok) { setSaveStatus('Still cannot load the board. Not saving', 'error'); return; }
          loadFailed = false;
          pageId = retry.pageId; revision = retry.revision;
          workspaceId = retry.workspaceId; pages = retry.pages;
          applyingRemote = true;
          api.updateScene({ elements: retry.elements });
          api.addFiles(Object.values(retry.files || {}));
          applyingRemote = false;
          retry.elements.forEach(function (e) { lastVersions.set(e.id, e.version); });
          setSaveStatus('Board loaded');
        });
      }
    } else {
      pageId = initial.pageId;
      revision = initial.revision;
      workspaceId = initial.workspaceId;
      pages = initial.pages;
    }

    initial.elements = (initial.elements || []).filter(function (e) { return e && e.id; });
    initial.elements.forEach(function (e) {
      lastVersions.set(e.id, e.version);
      if (isImportedAsset(e)) assetDeletedState.set(e.id, !!e.isDeleted);
    });
    Object.keys(initial.files || {}).forEach(function (id) { sentFiles.add(id); });

    var throttledBroadcast = throttle(broadcastDirty, 200);

    function onChange(elements, appState) {
      window.dispatchEvent(new Event('lessonboardviewportchange'));
      // Asset protection runs first and synchronously: if it restores an image it
      // calls updateScene, which re-enters onChange with the corrected elements, and
      // that pass is the one that gets broadcast and saved. Returning early avoids
      // shipping the erased-but-about-to-be-restored state to the peer.
      if (api && !applyingRemote && protectAssetsFromEraser(elements, appState)) return;
      throttledBroadcast();
    }

    var root = ReactDOM.createRoot(document.getElementById('whiteboard'));
    root.render(React.createElement(ExcalidrawLib.Excalidraw, {
      excalidrawAPI: function (a) { api = a; },
      initialData: {
        elements: initial.elements,
        files: initial.files || {},
        appState: { viewBackgroundColor: '#ffffff', theme: 'light' },
        scrollToContent: true,
      },
      onChange: onChange,
    }));

    // Excalidraw chooses its desktop or phone UI from the container size at mount and
    // never re-measures on its own. A room that mounts while the tab is in the
    // background -- or before the flex layout has settled -- measures zero and then
    // keeps the phone toolbar on a full desktop screen. Nudge it once the first frames
    // are painted, and again whenever the tab is brought forward.
    var remeasure = function () { window.dispatchEvent(new Event('resize')); };
    requestAnimationFrame(function () { requestAnimationFrame(remeasure); });
    setTimeout(remeasure, 500);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) remeasure();
    });

    if (synced) {
      room.on(LK.RoomEvent.DataReceived, onData);
      room.on(LK.RoomEvent.ParticipantConnected, function () {
        setTimeout(broadcastFull, 800);
      });
      setTimeout(function () { sendWb({ t: 'req' }); }, 800);
    } else {
      setSaveStatus('Offline board. Not shared', 'error');
    }

    // Autosave loop. flushNow() is the guarantee; this is the convenience.
    setInterval(function () { save(false); }, SAVE_INTERVAL_MS);

    // Last-resort fallback only. sendBeacon silently drops large payloads, so a board
    // with imported pages would not be saved this way -- which is precisely why the
    // Finish/Leave path awaits flushNow() instead of relying on this.
    window.addEventListener('beforeunload', function () {
      if (!api || !dirtySinceSave || loadFailed || pageId === null) return;
      if (!navigator.sendBeacon) return;
      var payload = JSON.stringify(currentPayload());
      if (payload.length > BEACON_LIMIT) return;
      navigator.sendBeacon(cfg.saveUrl, new Blob([payload], { type: 'application/json' }));
    });

    // upload button
    if (cfg.uploadBtn && cfg.uploadInput) {
      cfg.uploadBtn.addEventListener('click', function () { cfg.uploadInput.click(); });
      cfg.uploadInput.addEventListener('change', async function () {
        var file = cfg.uploadInput.files[0];
        cfg.uploadInput.value = '';
        if (!file) return;
        var isPdf = file.type === 'application/pdf';
        var isImg = file.type.indexOf('image/') === 0;
        if (!isPdf && !isImg) { alert('Please choose a PDF or an image file.'); return; }
        if (window.setBtnBusy) window.setBtnBusy(cfg.uploadBtn, true);
        try { await (isPdf ? importPdfFile(file) : importImageFile(file)); }
        finally { if (window.setBtnBusy) window.setBtnBusy(cfg.uploadBtn, false); }
      });
    }

    // Small surface for the room shell and tests: enough to drive pages and to assert
    // on real state rather than on DOM guesses.
    window.lessonWhiteboard = {
      // The live Excalidraw API. Exposed so tests can assert on real scene state
      // instead of scraping canvas pixels, and so the materials/chat work can place
      // content on the board without reaching back into this closure.
      getApi: function () { return api; },
      getPageId: function () { return pageId; },
      getRevision: function () { return revision; },
      getWorkspaceId: function () { return workspaceId; },
      getPages: function () { return pages.slice(); },
      isLoadFailed: function () { return loadFailed; },
      flush: flushNow,
      save: save,
      newPage: async function (title) {
        await flushNow();
        var res = await fetch(cfg.saveUrl + (cfg.saveUrl.indexOf('?') === -1 ? '?' : '&') + 'action=new_page', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ title: title || null }),
        });
        var body = await res.json();
        if (!body.success) return false;
        pages = body.data.pages || [];
        return await openPage(body.data.page.id);
      },
      openPage: function (id) { return openPage(id); },
    };

    async function openPage(id) {
      await flushNow();
      var next = await load(id);
      if (!next.ok) { setSaveStatus('Could not open that page', 'error'); return false; }
      pageId = next.pageId;
      revision = next.revision;
      pages = next.pages;
      lastVersions.clear();
      assetDeletedState.clear();
      applyingRemote = true;
      api.updateScene({ elements: next.elements });
      api.addFiles(Object.values(next.files || {}));
      applyingRemote = false;
      next.elements.forEach(function (e) {
        lastVersions.set(e.id, e.version);
        if (isImportedAsset(e)) assetDeletedState.set(e.id, !!e.isDeleted);
      });
      dirtySinceSave = false;
      broadcastFull();
      setSaveStatus('Page opened');
      return true;
    }
  };
})();
