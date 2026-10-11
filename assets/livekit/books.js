(function () {
  'use strict';
  window.initLessonBooks = async function (room, cfg) {
    var $ = function (id) { return document.getElementById(id); };
    var views = window.initLessonViews();
    var endpoint = new URL(cfg.url, location.href);
    var isTutor = !!cfg.isTutor;
    var current = null, tutorState = null, tutorBoard = null;
    if (!isTutor) views.follow('board');
    var pdf = null, loadingTask = null, fetchController = null, expiresAt = null;
    var loadId = 0, renderId = 0, renderTask = null, renderedPage = null;
    var list = [], resume = null, pickerLoading = false, pickerReady = false;
    var thumbObserver = null, thumbTask = null, thumbQueue = [], thumbBusy = false, thumbCache = [];
    var memory = null, savedMemory = '', saveTimer = null, savePromise = null;
    var retryAction = null, disposed = false, importBusy = false;
    var lastPresentation = '';
    function busy(button, on) { if (window.setBtnBusy) window.setBtnBusy(button, on); }
    function status(message, retry, hideCanvas) {
      $('bookStatusText').textContent = message || '';
      $('bookStatus').classList.toggle('hidden', !message);
      $('bookRetry').classList.toggle('hidden', !retry);
      retryAction = retry || null;
      if (hideCanvas) { $('bookCanvas').classList.add('hidden'); renderedPage = null; }
    }
    function mayBrowse() { return isTutor; }
    function controls() {
      var ready = !!pdf, allowed = mayBrowse();
      $('bookPageLabel').textContent = 'Page ' + (current ? current.page : 1) + ' of ' + (pdf ? pdf.numPages : '?');
      // A resize or zoom can finish rendering while the user is typing a page.
      // Do not overwrite that in-progress entry with the current page number.
      if (document.activeElement !== $('bookJump')) $('bookJump').value = current ? current.page : 1;
      $('bookJump').max = pdf ? pdf.numPages : 1;
      $('bookZoomLabel').textContent = Math.round((current ? current.zoom : 1) * 100) + '%';
      ['bookJump', 'bookGo', 'bookFit', 'bookZoomIn', 'bookZoomOut'].forEach(function (id) { $(id).disabled = !ready || !allowed; });
      $('bookPrev').disabled = !ready || !allowed || current.page <= 1;
      $('bookNext').disabled = !ready || !allowed || current.page >= pdf.numPages;
      $('bookToBoard').classList.toggle('hidden', !allowed);
      $('bookToBoard').disabled = importBusy || !ready || !renderedPage || !allowed;
      $('bookFollowing').classList.toggle('hidden', isTutor || !current);
      $('bookThumbnails').querySelectorAll('button').forEach(function (button) {
        button.disabled = !allowed;
        if (current && Number(button.dataset.page) === current.page) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
      });
    }
    async function getJSON(url, options) {
      var response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options));
      var data;
      try { data = await response.json(); } catch (e) { throw new Error('Network lost. Check your connection and retry.'); }
      if (!response.ok) {
        var error = new Error(data.error || 'Could not load this book.'); error.status = response.status; error.gateway = true; throw error;
      }
      return data;
    }
    function remember() {
      if (!isTutor || !current || !pdf) return;
      memory = { material_id: current.materialId, page: current.page, zoom: current.zoom };
      resume = { materialId: current.materialId, title: $('bookTitle').textContent, page: current.page, zoom: current.zoom };
      clearTimeout(saveTimer); saveTimer = setTimeout(flush, 2000);
    }
    async function flush() {
      clearTimeout(saveTimer);
      if (!isTutor || !memory) return true;
      if (savePromise) { await savePromise; return savedMemory === JSON.stringify(memory) ? true : flush(); }
      var payload = JSON.stringify(memory);
      if (payload === savedMemory) return true;
      savePromise = (async function () {
        try {
          await getJSON(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
          savedMemory = payload; return true;
        } catch (e) {
          status('Book position could not be saved. Check your connection and retry.', function () { return flush().then(function (ok) { if (ok) status(''); }); });
          return false;
        }
      })();
      var ok = await savePromise; savePromise = null;
      if (ok && JSON.stringify(memory) !== payload) return flush();
      return ok;
    }
    window.lessonBookFlush = flush;
    function boardApi() { return window.lessonWhiteboard && window.lessonWhiteboard.getApi(); }
    function publishView() {
      if (!isTutor || !sync) return;
      var viewport = $('bookViewport');
      var presentation = { view: views.current(),
        scrollX: viewport.scrollLeft / Math.max(1, viewport.scrollWidth - viewport.clientWidth),
        scrollY: viewport.scrollTop / Math.max(1, viewport.scrollHeight - viewport.clientHeight) };
      var api = boardApi(), board = api && api.getAppState();
      if (board && board.width > 0 && board.height > 0) {
        presentation.board = { x: board.width / (2 * board.zoom.value) - board.scrollX,
          y: board.height / (2 * board.zoom.value) - board.scrollY, zoom: board.zoom.value };
      }
      var serialized = JSON.stringify([current, presentation]);
      if (serialized === lastPresentation) return;
      lastPresentation = serialized;
      sync.update(current, presentation);
    }
    function followBookScroll() {
      if (isTutor || !tutorState || !current || tutorState.materialId !== current.materialId) return;
      var viewport = $('bookViewport');
      viewport.scrollLeft = tutorState.scrollX * Math.max(0, viewport.scrollWidth - viewport.clientWidth);
      viewport.scrollTop = tutorState.scrollY * Math.max(0, viewport.scrollHeight - viewport.clientHeight);
    }
    function followBoard() {
      if (isTutor || !tutorBoard || views.current() !== 'board') return;
      var api = boardApi(), board = api && api.getAppState();
      if (!board || !board.width || !board.height) return;
      var x = board.width / (2 * tutorBoard.zoom) - tutorBoard.x;
      var y = board.height / (2 * tutorBoard.zoom) - tutorBoard.y;
      if (Math.abs(board.scrollX - x) > 0.5 || Math.abs(board.scrollY - y) > 0.5 || board.zoom.value !== tutorBoard.zoom) {
        api.updateScene({ appState: { scrollX: x, scrollY: y, zoom: { value: tutorBoard.zoom } } });
      }
    }
    function changed() { publishView(); remember(); controls(); }
    function cancelRenders() {
      renderId++;
      if (renderTask) { renderTask.cancel(); renderTask = null; }
      if (thumbTask) { thumbTask.cancel(); thumbTask = null; }
      thumbQueue = [];
      if (thumbObserver) { thumbObserver.disconnect(); thumbObserver = null; }
    }
    function destroyDocument() {
      cancelRenders();
      pdf = null; renderedPage = null;
      if (fetchController) fetchController.abort();
      fetchController = null;
      if (loadingTask) { loadingTask.destroy().catch(function () {}); loadingTask = null; }
    }
    function errorState(error) {
      if (error && (error.status === 404 || error.name === 'MissingPDFException')) {
        status("This book's file is unavailable", function () { return loadDocument(true); }, true);
      } else if (error && error.gateway && error.status === 403) {
        status('You no longer have access to this lesson. Reload the lesson to continue.', null, true);
      } else if (error && error.name === 'InvalidPDFException') {
        status("This book's file is unavailable", null, true);
      } else {
        status('Network lost. Check your connection and retry.', function () { return loadDocument(true); }, true);
      }
      controls();
    }
    async function loadDocument(retried) {
      if (!current || disposed) return;
      var id = ++loadId;
      var materialId = current.materialId;
      destroyDocument();
      $('bookThumbnails').replaceChildren(); thumbCache = [];
      status('Loading ' + $('bookTitle').textContent + '...', null, true); controls();
      fetchController = new AbortController();
      try {
        var url = new URL(endpoint); url.searchParams.set('open', materialId);
        var opened = await getJSON(url, { signal: fetchController.signal });
        if (id !== loadId || !current) return;
        $('bookTitle').textContent = opened.title; $('bookTitle').title = opened.title;
        expiresAt = opened.expires_at;
        status('Loading ' + opened.title + '...', null, true);
        var task = pdfjsLib.getDocument({
          url: new URL(opened.url, endpoint).href,
          disableAutoFetch: true, disableStream: true, rangeChunkSize: 65536,
          isEvalSupported: false, useSystemFonts: true,
        });
        loadingTask = task;
        var doc = await task.promise;
        if (id !== loadId || !current) { await task.destroy(); return; }
        pdf = doc;
        current.page = Math.min(current.page, doc.numPages);
        buildThumbnails();
        await renderPage(!!retried);
      } catch (error) {
        if (id !== loadId || error.name === 'AbortError') return;
        // Refresh once, including when a previously signed URL returned 403.
        if (!retried && error.status !== 404 && !(error.gateway && error.status === 403) && error.name !== 'InvalidPDFException') return loadDocument(true);
        errorState(error);
      }
    }
    async function renderPage(retried) {
      if (!pdf || !current) return;
      if (expiresAt && expiresAt * 1000 < Date.now() + 60000) return loadDocument(true);
      if (views.current() !== 'book') { controls(); remember(); return; }
      var id = ++renderId, doc = pdf, pageNumber = current.page, zoom = current.zoom;
      if (renderTask) { renderTask.cancel(); renderTask = null; }
      // Keep the same page visible while zooming/resizing. Removing its layout
      // can toggle scrollbars, trigger another resize and swallow toolbar clicks.
      status('Loading ' + $('bookTitle').textContent + ', page ' + pageNumber + '...', null, renderedPage !== pageNumber); controls();
      try {
        var page = await doc.getPage(pageNumber);
        if (id !== renderId || doc !== pdf) return;
        var base = page.getViewport({ scale: 1 });
        var availableWidth = Math.max(100, $('bookViewport').clientWidth - (innerWidth < 640 ? 16 : 24));
        var cssWidth = availableWidth * zoom;
        // Zoom affects layout; cap pixels to keep large pages and phones bounded.
        var scale = Math.min(cssWidth / base.width * Math.min(devicePixelRatio || 1, 2), 2400 / Math.max(base.width, base.height));
        var viewport = page.getViewport({ scale: scale });
        var offscreen = document.createElement('canvas');
        offscreen.width = Math.ceil(viewport.width); offscreen.height = Math.ceil(viewport.height);
        var task = page.render({ canvasContext: offscreen.getContext('2d'), viewport: viewport });
        renderTask = task; await task.promise;
        if (id !== renderId || doc !== pdf) return;
        renderTask = null;
        var canvas = $('bookCanvas');
        canvas.width = offscreen.width; canvas.height = offscreen.height;
        canvas.getContext('2d').drawImage(offscreen, 0, 0);
        offscreen.width = offscreen.height = 0;
        canvas.style.width = Math.round(cssWidth) + 'px';
        canvas.style.height = Math.round(cssWidth * base.height / base.width) + 'px';
        canvas.dataset.page = String(pageNumber); canvas.dataset.materialId = String(current.materialId);
        canvas.dataset.zoom = String(zoom);
        canvas.setAttribute('aria-label', $('bookTitle').textContent + ', page ' + pageNumber);
        canvas.classList.remove('hidden'); renderedPage = pageNumber;
        followBookScroll();
        status(''); controls(); remember();
        startThumbnails();
        // Only the adjacent page is prefetched, after the current page is painted.
        if (pageNumber < doc.numPages) doc.getPage(pageNumber + 1).catch(function () {});
      } catch (error) {
        if (id !== renderId || doc !== pdf || error.name === 'RenderingCancelledException') return;
        if (!retried && error.name !== 'InvalidPDFException') return loadDocument(true);
        errorState(error);
      }
    }
    function showBook(state, title) {
      current = { materialId: state.materialId, page: state.page || 1, zoom: state.zoom || 1, browse: false };
      $('bookTitle').textContent = title || 'Book'; $('bookTitle').title = title || 'Book';
      $('bookResumeNotice').classList.add('hidden');
      // Invalidate the previous PDF before the visibility event can render it.
      loadId++; destroyDocument();
      views.available('book', true, true);
      controls();
      if (isTutor) { $('bookViewport').scrollTop = 0; $('bookViewport').scrollLeft = 0; publishView(); }
      return loadDocument(false);
    }
    function showResume() {
      if (!isTutor || !resume || current) return;
      $('bookContinue').textContent = 'Continue ' + resume.title + ', page ' + resume.page;
      $('bookResumeNotice').classList.remove('hidden');
    }
    function closeBook() {
      if (isTutor) { remember(); flush(); }
      loadId++; current = null; tutorState = null;
      destroyDocument();
      $('bookCanvas').classList.add('hidden');
      $('bookThumbnails').replaceChildren();
      views.available('book', false);
      publishView();
      showResume();
    }
    var sync = window.createLessonBookSync({ room: room, LK: window.LivekitClient, isTutor: isTutor,
      hostIdentity: room && room.lessonHostIdentity,
      onPresentation: function (message) {
        tutorBoard = message.board || null;
        views.follow(message.view || (message.t === 'state' ? 'book' : 'board'));
        requestAnimationFrame(followBoard);
      },
      onState: function (state) {
        var switchedBook = !current || current.materialId !== state.materialId;
        tutorState = state;
        if (switchedBook) { showBook(state); return; }
        var moved = current.page !== state.page || current.zoom !== state.zoom;
        current.page = pdf ? Math.min(pdf.numPages, state.page) : state.page;
        current.zoom = state.zoom;
        if (moved && pdf) renderPage(false);
        else followBookScroll();
        controls();
      }, onClose: closeBook,
    });
    function move(page, zoom) {
      if (!pdf || !current || !mayBrowse()) return;
      page = Math.max(1, Math.min(pdf.numPages, Math.trunc(page)));
      zoom = Math.max(0.25, Math.min(3, Math.round(zoom * 100) / 100));
      if (!Number.isFinite(page) || !Number.isFinite(zoom)) return;
      current.page = page; current.zoom = zoom;
      $('bookViewport').scrollTop = 0;
      $('bookViewport').scrollLeft = 0;
      changed();
      renderPage(false);
    }
    function buildThumbnails() {
      var fragment = document.createDocumentFragment();
      for (var n = 1; n <= pdf.numPages; n++) {
        var button = document.createElement('button'); button.type = 'button'; button.className = 'book-btn book-thumb';
        button.dataset.page = n; button.setAttribute('aria-label', 'Page ' + n);
        var label = document.createElement('span'); label.textContent = 'Page ' + n; button.appendChild(label);
        button.addEventListener('click', function () { move(Number(this.dataset.page), current.zoom); });
        fragment.appendChild(button);
      }
      $('bookThumbnails').replaceChildren(fragment); controls();
    }
    async function drainThumbnails() {
      if (thumbBusy) return;
      thumbBusy = true;
      try {
        while (thumbQueue.length && pdf && views.current() === 'book') {
          var button = thumbQueue.shift(), doc = pdf;
          if (!button.isConnected || button.querySelector('canvas')) continue;
          var page = await doc.getPage(Number(button.dataset.page));
          if (doc !== pdf || !button.isConnected) continue;
          var base = page.getViewport({ scale: 1 });
          var viewport = page.getViewport({ scale: 90 / base.width });
          var canvas = document.createElement('canvas'); canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
          var task = page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport });
          thumbTask = task; await task.promise; thumbTask = null;
          if (doc !== pdf || !button.isConnected) continue;
          button.prepend(canvas); thumbCache.push(button);
          if (thumbCache.length > 24) {
            var oldest = thumbCache.shift();
            var oldCanvas = oldest.querySelector('canvas');
            if (oldCanvas) { oldCanvas.width = oldCanvas.height = 0; oldCanvas.remove(); }
          }
        }
      } catch (e) { /* Thumbnails are optional. Main-page rendering owns retry UI. */ }
      finally {
        thumbBusy = false;
        if (thumbQueue.length && pdf && views.current() === 'book') drainThumbnails();
      }
    }
    function startThumbnails() {
      if (!pdf || views.current() !== 'book') return;
      // Observe only after the pane and strip have dimensions.
      requestAnimationFrame(function () {
        if (!pdf || views.current() !== 'book') return;
        if (!thumbObserver) {
          thumbObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting && !entry.target.querySelector('canvas') && !thumbQueue.includes(entry.target)) thumbQueue.push(entry.target);
            });
            drainThumbnails();
          }, { root: $('bookThumbnails'), rootMargin: '80px' });
          $('bookThumbnails').querySelectorAll('button').forEach(function (button) { thumbObserver.observe(button); });
        }
      });
    }
    function renderList() {
      var search = $('bookSearch').value.trim().toLocaleLowerCase();
      var filtered = list.filter(function (book) {
        return [book.title, book.description, book.grade_level, book.subject_name].join(' ').toLocaleLowerCase().includes(search);
      });
      $('bookList').replaceChildren();
      var hasSubject = list.some(function (book) { return book.matches_subject; });
      var groups = search || !hasSubject ? [['All books', filtered]] : [
        ['For this subject', filtered.filter(function (book) { return book.matches_subject; })],
        ['All books', filtered.filter(function (book) { return !book.matches_subject; })],
      ];
      if (!filtered.length) { var empty = document.createElement('p'); empty.textContent = search ? 'No books match your search.' : 'No published PDF books are available yet.'; $('bookList').appendChild(empty); }
      groups.forEach(function (group) {
        if (!group[1].length) return;
        var heading = document.createElement('h3'); heading.textContent = group[0]; $('bookList').appendChild(heading);
        group[1].forEach(function (book) {
          var button = document.createElement('button'); button.type = 'button'; button.className = 'book-btn book-choice'; button.dataset.materialId = book.id;
          var title = document.createElement('strong'); title.textContent = book.title; button.appendChild(title);
          var metadata = document.createElement('span'); metadata.textContent = [book.subject_name, book.grade_level ? 'Grade ' + book.grade_level : null].filter(Boolean).join(', '); button.appendChild(metadata);
          if (book.description) { var description = document.createElement('span'); description.textContent = book.description; button.appendChild(description); }
          button.addEventListener('click', function () {
            $('bookPicker').close();
            showBook({ materialId: book.id, page: 1, zoom: 1 }, book.title);
          });
          $('bookList').appendChild(button);
        });
      });
    }
    async function loadList() {
      if (pickerLoading) return;
      pickerLoading = true; busy($('btnBooks'), true);
      $('bookPickerStatus').textContent = 'Loading books...'; $('bookPickerRetry').classList.add('hidden');
      try {
        var result = await getJSON(endpoint);
        list = result.books; if (!memory) resume = result.resume;
        pickerReady = true; $('bookPickerStatus').textContent = ''; renderList(); showResume();
      } catch (e) {
        $('bookPickerStatus').textContent = 'Could not load books. Check your connection and retry.';
        $('bookPickerRetry').classList.remove('hidden');
      } finally { pickerLoading = false; busy($('btnBooks'), false); }
    }
    if (isTutor) {
      $('btnBooks').addEventListener('click', function () { $('bookPicker').showModal(); $('bookSearch').focus(); if (!pickerReady) loadList(); else renderList(); });
      $('bookPickerClose').addEventListener('click', function () { $('bookPicker').close(); });
      $('bookSearch').addEventListener('input', renderList);
      $('bookPickerRetry').addEventListener('click', loadList);
      $('bookContinue').addEventListener('click', function () { if (resume) showBook(resume, resume.title); });
      $('bookClose').addEventListener('click', closeBook);
      loadList();
    }
    $('bookPrev').addEventListener('click', function () { move(current.page - 1, current.zoom); });
    $('bookNext').addEventListener('click', function () { move(current.page + 1, current.zoom); });
    $('bookJumpForm').addEventListener('submit', function (event) { event.preventDefault(); if (current) move(Number($('bookJump').value), current.zoom); });
    $('bookZoomIn').addEventListener('click', function () { move(current.page, current.zoom + 0.25); });
    $('bookZoomOut').addEventListener('click', function () { move(current.page, current.zoom - 0.25); });
    $('bookFit').addEventListener('click', function () { move(current.page, 1); });
    $('bookRetry').addEventListener('click', function () { if (retryAction) retryAction(); });
    $('bookToBoard').addEventListener('click', async function () {
      if (!pdf || !current || !mayBrowse() || renderedPage !== current.page) return;
      var button = this, doc = pdf, bookId = current.materialId, pageNumber = current.page;
      if (importBusy) return;
      importBusy = true; busy(button, true);
      try {
        var page = await doc.getPage(pageNumber);
        var base = page.getViewport({ scale: 1 });
        var viewport = page.getViewport({ scale: 1800 / Math.max(base.width, base.height) });
        var canvas = document.createElement('canvas'); canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
        if (pdf !== doc || !current || current.materialId !== bookId || !mayBrowse()) return;
        var image = { dataURL: canvas.toDataURL('image/jpeg', 0.85), mimeType: 'image/jpeg', width: canvas.width, height: canvas.height,
          provenance: { origin: 'library', materialId: bookId, page: pageNumber } };
        canvas.width = canvas.height = 0;
        if (!window.lessonWhiteboardAddImage) throw new Error('The whiteboard is not ready. Retry loading the board first.');
        await window.lessonWhiteboardAddImage(image);
        status('');
      } catch (e) {
        if (views.current() !== 'book') views.select('book');
        status(e.message || 'This page could not be added. Please retry.', null, false);
      } finally { importBusy = false; busy(button, false); controls(); }
    });
    document.addEventListener('keydown', function (event) {
      if (!current || !mayBrowse() || views.current() !== 'book' || event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) return;
      if (event.target.closest('input,textarea,select,[contenteditable],#whiteboard,dialog,[role=tablist]')) return;
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault(); move(current.page + (event.key === 'ArrowRight' ? 1 : -1), current.zoom);
      }
    });
    var resizeTimer = null, lastWidth = 0;
    new ResizeObserver(function () {
      var width = $('bookViewport').getBoundingClientRect().width;
      if (width <= 0 || width === lastWidth) return;
      lastWidth = width; clearTimeout(resizeTimer); resizeTimer = setTimeout(function () { renderPage(false); }, 120);
    }).observe($('bookViewport'));
    window.addEventListener('lessonviewchange', function (event) {
      if (event.detail === 'book' && pdf) renderPage(false);
      else if (event.detail !== 'book') cancelRenders();
      publishView();
      requestAnimationFrame(followBoard);
    });
    $('bookViewport').addEventListener('scroll', function () { if (isTutor) publishView(); else followBookScroll(); }, { passive: true });
    window.addEventListener('lessonboardviewportchange', function () { if (isTutor) publishView(); else followBoard(); });
    window.addEventListener('resize', followBoard);
    window.addEventListener('beforeunload', function () {
      if (isTutor && memory && !savePromise && savedMemory !== JSON.stringify(memory)) {
        navigator.sendBeacon(endpoint.href, new Blob([JSON.stringify(memory)], { type: 'application/json' }));
      }
    });
    var backLink = document.querySelector('.hdr-back');
    if (backLink && isTutor) backLink.addEventListener('click', async function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (await flush() || confirm('The book position could not be saved. Leave anyway?')) location.href = backLink.href;
    });
    controls();
    publishView();
    window.lessonBooks = { getState: function () { return current ? Object.assign({ renderedPage: renderedPage }, current) : null; }, flush: flush };
  };
})();
