<?php
// components/lesson_room_live_shell.php
// Shared HTML shell for the LiveKit lesson room (whiteboard + video sidebar).
// Expects: $lesson (row with subject_name + peer names), $lesson_id (int),
//          $ROOM_ROLE ('tutor'|'student'), $PEER_LABEL (string), h() helper.
//
// Layout: at >=1024px the cameras sit in a right-hand sidebar. Below that the
// board takes the whole width and the cameras float over it as a small strip,
// because a fixed 16rem sidebar left the board ~119px wide on a phone — narrow
// enough that Excalidraw dropped its toolbar entirely. The recorder draws whichever
// main pane is visible, including the book page canvas.
$IS_TUTOR = ($ROOM_ROLE === 'tutor');
$lkDir = __DIR__ . '/../assets/livekit/';
$v = function ($f) use ($lkDir) { return @filemtime($lkDir . $f) ?: 1; };
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($lesson['subject_name']) ?> - Lesson</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <link rel="stylesheet" href="../assets/livekit/vendor/room-utilities.css?v=<?= $v('vendor/room-utilities.css') ?>">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="../assets/livekit/books.css?v=<?= $v('books.css') ?>">
  <!-- Vendored locally so the room never depends on CDN availability -->
  <script>window.EXCALIDRAW_ASSET_PATH = "../assets/livekit/vendor/";
  // One place for the icon sprite path, used by room.js and whiteboard.js too.
  window.LESSON_SPRITE = "../assets/icons/sprite.svg";

  // Put a button into a loading state: its icon becomes a spinner and it stops taking
  // clicks. Defined here, not inside the room's async setup, because that setup returns
  // early if the call cannot connect and the upload button would then have no spinner.
  window.setBtnBusy = function (btn, busy) {
    if (!btn) return;
    btn.classList.toggle('is-busy', !!busy);
    if (busy) { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }
    else { btn.removeAttribute('aria-busy'); btn.disabled = false; }
  };</script>
  <script src="../assets/livekit/vendor/react.production.min.js"></script>
  <script src="../assets/livekit/vendor/react-dom.production.min.js"></script>
  <script src="../assets/livekit/vendor/excalidraw.production.min.js"></script>
  <script src="../assets/livekit/vendor/pdf.min.js"></script>
  <script src="../assets/livekit/vendor/livekit-client.umd.min.js"></script>
  <style>
    /* dvh keeps the room exactly one screen tall while a mobile address bar
       shows and hides; vh alone leaves the controls under the browser chrome. */
    html,body{height:100%;background:#0b1020;margin:0}
    .shell{height:100vh;height:100dvh;display:flex;flex-direction:column;overflow:hidden}
    .main{flex:1;min-height:0;min-width:0;display:flex;position:relative}
    #boardPane,#screenPane{flex:1;min-width:0;min-height:0;position:relative}
    video{background:#111827}
    .cam-tile{position:relative;border-radius:.5rem;overflow:hidden;border:1px solid #374151;background:#111827}
    .cam-tile video{width:100%;height:100%;object-fit:cover}
    #localVideo{transform:scaleX(-1)}
    #remoteScreen{width:100%;height:100%;object-fit:contain}
    .ctl-on{background:#374151}.ctl-on:hover{background:#4b5563}
    .ctl-off{background:#dc2626}.ctl-off:hover{background:#b91c1c}
    .ctl-share-on{background:#059669}.ctl-share-on:hover{background:#047857}
    .icon{width:1.25rem;height:1.25rem;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .hidden{display:none!important}
    /* .hidden above is !important (room.js toggles it onto elements that already
       carry a display class), which also outranks Tailwind's `sm:inline` and
       `lg:inline`. Responsive show/hide in this page uses these instead. */
    .at-sm,.at-lg,.at-lg-flex{display:none!important}
    @media (min-width:640px){.at-sm{display:inline!important}}
    @media (min-width:1024px){
      .at-lg{display:inline!important}
      .at-lg-flex{display:flex!important}
    }

    /* ---------- sidebar (>=1024px) ---------- */
    .side{width:17rem;flex:none;display:flex;flex-direction:column;gap:.75rem;
          padding:.75rem;background:#111827;border-left:1px solid #1f2937}
    /* The tiles used to sit at the top of a full-height column, leaving a large
       dead area above the controls. Centring the pair and letting it grow uses
       the space for bigger faces instead. */
    .side-tiles{flex:1;min-height:0;display:flex;flex-direction:column;
                justify-content:center;gap:.75rem}
    .side-tiles .cam-tile{flex:0 1 auto;aspect-ratio:16/10;max-height:15rem}
    .controls{display:flex;align-items:center;justify-content:center;gap:.5rem}
    .ctl-btn{width:2.75rem;height:2.75rem;border-radius:9999px;display:flex;
             align-items:center;justify-content:center;color:#fff;flex:none}
    #hdrControls{display:none}

    /* ---------- narrow: board full width, cameras float over it ---------- */
    @media (max-width:1023px){
      /* Anchored below Excalidraw's tool strip rather than centred, so the tiles
         sit out of the middle of the canvas where the writing happens. */
      .side{position:absolute;left:.5rem;top:5.5rem;width:auto;padding:0;gap:.4rem;
            background:transparent;border-left:0;z-index:30;pointer-events:none}
      .side > *{pointer-events:auto}
      .side-tiles{flex:none;gap:.4rem}
      .side .controls{flex-wrap:wrap;justify-content:flex-start}
      .side-tiles .cam-tile{width:5.25rem;aspect-ratio:16/10;max-height:none;
                            box-shadow:0 4px 14px rgba(0,0,0,.55)}
      #hdrControls{display:flex}
      .ctl-btn{width:2.25rem;height:2.25rem}
      .ctl-btn .icon{width:1.05rem;height:1.05rem}
      #hdrControls{gap:.25rem}
      .side .tile-label{font-size:8px;line-height:1.35;padding:.1rem .2rem;bottom:.15rem;left:.15rem}
      /* On an 84px tile the name and the "Waiting for …" line land on top of each
         other; the spinner alone already says the peer has not arrived. */
      #waitingText{display:none}
      /* Excalidraw puts its own tool strip along the top and a menu bar along the
         bottom on narrow screens, so the floating tiles hug the left edge and the
         room controls live in the header — neither overlaps its chrome. */
    }
    @media (max-width:639px){
      /* Phone: every pixel the chrome gives up goes to the lesson title, which was
         otherwise squeezed to a single letter on the tutor's side (it carries the
         recording chip and Finish button as well). */
      .ctl-btn{width:2rem;height:2rem}
      .ctl-btn .icon{width:.95rem;height:.95rem}
      #hdrControls{gap:.2rem}
      #btnShare{display:none!important}  /* unusable at this width even where the API exists */
      .room-hdr{gap:.35rem;padding-left:.35rem;padding-right:.35rem}
      .room-hdr #btnUpload,.room-hdr #btnFinish,.room-hdr .hdr-back{
        padding-left:.4rem;padding-right:.4rem}
    }

    /* Collapse the camera strip out of the way (narrow screens only). */
    .side.is-collapsed .side-tiles{display:none}
    #camToggle{display:none}
    @media (max-width:1023px){
      #camToggle{display:flex;align-items:center;justify-content:center;
                 width:1.5rem;height:1.5rem;border-radius:9999px;
                 background:rgba(17,24,39,.85);border:1px solid #374151;color:#9ca3af}
      #camToggle .icon{width:.8rem;height:.8rem}
    }

    /* Busy state: the button swaps its own icon for a spinner, so the feedback
       appears exactly where the person clicked rather than elsewhere on screen. */
    @keyframes lk-spin{to{transform:rotate(360deg)}}
    .btn-spin{display:none;animation:lk-spin .75s linear infinite;transform-origin:50% 50%}
    .is-busy .btn-spin{display:block}
    .is-busy .btn-face{display:none}
    button.is-busy{opacity:.85;cursor:progress}
    button:disabled{opacity:.55;cursor:not-allowed}
    button:focus-visible,a:focus-visible{outline:2px solid #60a5fa;outline-offset:2px}
    /* Save/import status, with its own small spinner or tick */
    .status-chip{display:inline-flex;align-items:center;gap:.35rem}
    .status-chip svg{width:.9rem;height:.9rem;flex:none}
    .status-chip .spin{animation:lk-spin .75s linear infinite;transform-origin:50% 50%}
    @media (prefers-reduced-motion: reduce){
      .btn-spin,.status-chip .spin{animation-duration:2.4s}
    }
  </style>
</head>
<body>
  <div class="shell">
    <!-- Top bar -->
    <div class="room-hdr px-2 sm:px-4 py-2 flex items-center gap-2 sm:gap-3 bg-gray-900 border-b border-gray-800 flex-none">
      <a href="dashboard.php" class="hdr-back flex-none flex items-center gap-1.5 text-sm bg-gray-800 hover:bg-gray-700 text-white px-2 sm:px-3 py-1.5 rounded" title="Back to dashboard">
        <svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg><span class="at-sm">Back</span>
      </a>
      <div class="min-w-0 flex-1 text-sm text-gray-300 truncate">
        <?= h($lesson['subject_name']) ?> &middot; <?= h($PEER_LABEL) ?>
      </div>

      <?php if ($IS_TUTOR): ?>
      <button type="button" id="btnBooks" class="flex-none flex items-center gap-2 text-xs font-semibold px-2 sm:px-3 py-1.5 rounded bg-gray-800 hover:bg-gray-700 text-white">
        <svg class="icon btn-face" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
        <svg class="icon btn-spin" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
        Books
      </button>
      <?php endif; ?>
      <button id="btnUpload" class="flex-none flex items-center gap-2 text-xs font-semibold px-2 sm:px-3 py-1.5 rounded bg-gray-800 hover:bg-gray-700 text-white" title="Add a PDF or image to the whiteboard" aria-label="Add a PDF or image to the whiteboard">
        <svg class="icon btn-face"><use href="../assets/icons/sprite.svg#i-upload"></use></svg><svg class="icon btn-spin"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
        <span class="at-lg">Add PDF / image</span>
      </button>
      <input id="uploadInput" type="file" accept="application/pdf,image/*" class="hidden">
      <span class="at-lg-flex flex-none items-center" role="status" aria-live="polite"><span id="saveStatus" class="text-xs text-gray-500"></span></span>

      <div class="flex-none flex items-center gap-2 sm:gap-3">
        <div class="flex items-center gap-2 text-xs text-gray-400" role="status" aria-live="polite">
          <span id="statusDot" class="inline-block w-2 h-2 rounded-full flex-none bg-gray-500"></span>
          <span id="statusText" class="at-lg">Loading…</span>
        </div>
        <?php if ($IS_TUTOR): ?>
        <span id="recIndicator" class="hidden items-center gap-2 text-xs font-semibold px-2 sm:px-3 py-1.5 rounded bg-red-900/60 text-red-200" style="display:flex" title="This lesson is being recorded automatically">
          <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse flex-none"></span>
          <span class="at-sm">REC</span>
          <span data-timer class="font-mono at-sm"></span>
        </span>
        <button type="button" id="btnFinish"
           data-finish-url="finish_lesson.php?lesson_id=<?= (int)$lesson['id'] ?>"
           class="inline-flex items-center gap-2 text-xs font-semibold px-2 sm:px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm">
          <svg class="icon btn-spin" style="width:.9rem;height:.9rem"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
          <span>Finish<span class="at-sm"> Lesson</span></span>
        </button>
        <?php else: ?>
        <span class="flex items-center gap-2 text-xs text-gray-400" title="Lessons are recorded so you can review them any time">
          <span class="inline-block w-2 h-2 rounded-full bg-red-500 flex-none"></span>
          <span class="at-lg">Lesson is recorded</span>
        </span>
        <?php endif; ?>
        <!-- On narrow screens the room controls are moved in here by relocateControls() -->
        <div id="hdrControls" class="items-center gap-1.5"></div>
      </div>
    </div>

    <div id="bookResumeNotice" class="hidden"><button type="button" id="bookContinue" class="book-btn"></button></div>
    <div id="viewToggle" class="hidden" role="tablist" aria-label="Lesson view">
      <button type="button" id="view-board" class="book-btn" role="tab" data-view="board" aria-controls="boardPane" aria-selected="true"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-presentation"></use></svg>Board</button>
      <button type="button" id="view-book" class="book-btn hidden" role="tab" data-view="book" aria-controls="bookPane" aria-selected="false" tabindex="-1"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>Book</button>
      <button type="button" id="view-screen" class="book-btn hidden" role="tab" data-view="screen" aria-controls="screenPane" aria-selected="false" tabindex="-1"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-screen-share"></use></svg>Shared screen</button>
    </div>
    <!-- Main area: whiteboard, book or shared screen, plus cameras -->
    <div class="main">
      <div id="boardPane" class="bg-white" role="tabpanel" aria-labelledby="view-board">
        <div id="whiteboard" class="absolute inset-0"></div>
      </div>
      <?php require __DIR__ . '/lesson_book_pane.php'; ?>
      <div id="screenPane" class="hidden bg-black" role="tabpanel" aria-labelledby="view-screen">
        <video id="remoteScreen" autoplay playsinline muted></video>
      </div>

      <aside id="camPanel" class="side">
        <button id="camToggle" class="self-start" type="button" title="Hide the cameras" aria-label="Hide the cameras" aria-expanded="true">
          <svg class="icon"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg>
        </button>

        <div class="side-tiles">
          <!-- Remote tile -->
          <div class="cam-tile">
            <video id="remoteCamera" class="hidden" autoplay playsinline></video>
            <div id="remotePlaceholder" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-1 text-center">
              <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-full border-2 border-gray-600 border-t-emerald-400 animate-spin flex-none"></div>
              <p id="waitingText" class="text-gray-400 text-[10px] lg:text-xs leading-tight">Waiting…</p>
            </div>
            <!-- Here, but not sending video. Without this the empty <video> just read
                 as a dead black rectangle with no clue whose it was. -->
            <div id="remoteOff" class="hidden absolute inset-0 flex flex-col items-center justify-center gap-1 bg-gray-800">
              <div class="rounded-full bg-gray-700 text-gray-200 font-semibold flex items-center justify-center w-7 h-7 text-[11px] lg:w-12 lg:h-12 lg:text-base">
                <span id="remoteOffInitials">?</span>
              </div>
              <span class="at-lg-flex items-center gap-1 text-[11px] text-gray-400">
                <svg class="icon" style="width:.8rem;height:.8rem"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
                Camera off
              </span>
            </div>
            <div id="peerLabel" class="tile-label absolute bottom-1 left-1 text-[11px] text-gray-200 bg-black/60 px-1.5 py-0.5 rounded max-w-[90%] truncate"></div>
          </div>
          <!-- Local tile -->
          <div class="cam-tile">
            <video id="localVideo" class="hidden" autoplay playsinline muted></video>
            <div id="localOff" class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-gray-800">
              <div class="rounded-full bg-gray-700 text-gray-200 font-semibold flex items-center justify-center w-7 h-7 text-[11px] lg:w-12 lg:h-12 lg:text-base">
                <span id="localOffInitials">?</span>
              </div>
              <span class="at-lg-flex items-center gap-1 text-[11px] text-gray-400">
                <svg class="icon" style="width:.8rem;height:.8rem"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
                Camera off
              </span>
            </div>
            <div class="tile-label absolute bottom-1 left-1 text-[11px] text-gray-200 bg-black/60 px-1.5 py-0.5 rounded">You</div>
          </div>
        </div>

        <!-- You are sharing: the sharer only ever sees their own board, so without
             this there is nothing on screen confirming the share is actually live. -->
        <div id="shareChip" class="hidden items-center justify-center gap-2 bg-emerald-600/90 text-white text-xs font-semibold px-3 py-1.5 rounded-lg" style="display:flex">
          <svg class="icon flex-none" style="width:.9rem;height:.9rem"><use href="../assets/icons/sprite.svg#i-screen-share"></use></svg>
          You are sharing your screen
        </div>


        <audio id="remoteAudio" autoplay></audio>

        <!-- Controls (relocated into the header on narrow screens) -->
        <div id="roomControls" class="controls">
          <button id="btnMic" class="ctl-btn ctl-on" title="Microphone" aria-label="Microphone">
            <span data-on><svg class="icon"><use href="../assets/icons/sprite.svg#i-mic"></use></svg></span>
            <span data-off class="hidden"><svg class="icon"><use href="../assets/icons/sprite.svg#i-mic-off"></use></svg></span>
          </button>
          <button id="btnCam" class="ctl-btn ctl-on" title="Camera" aria-label="Camera">
            <span data-on><svg class="icon"><use href="../assets/icons/sprite.svg#i-video"></use></svg></span>
            <span data-off class="hidden"><svg class="icon"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg></span>
          </button>
          <button id="btnShare" class="ctl-btn ctl-on" title="Share screen" aria-label="Share your screen">
            <span data-on><svg class="icon"><use href="../assets/icons/sprite.svg#i-screen-share"></use></svg></span>
            <span data-off class="hidden"><svg class="icon"><use href="../assets/icons/sprite.svg#i-screen-share-off"></use></svg></span>
          </button>
          <button id="btnLeave" class="ctl-btn bg-red-600 hover:bg-red-500" title="Leave lesson" aria-label="Leave lesson">
            <svg class="icon btn-face"><use href="../assets/icons/sprite.svg#i-phone-off"></use></svg><svg class="icon btn-spin"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
          </button>
        </div>
      </aside>

    </div>

    <?php if ($IS_TUTOR): ?>
    <dialog id="bookPicker" aria-labelledby="bookPickerTitle">
      <div class="book-picker-heading"><h2 id="bookPickerTitle">Books for this lesson</h2><button type="button" id="bookPickerClose" class="book-btn">Close</button></div>
      <label for="bookSearch">Search all published books</label>
      <input type="search" id="bookSearch" placeholder="Title, subject, grade or description" autocomplete="off">
      <p id="bookPickerStatus" role="status"></p><button type="button" id="bookPickerRetry" class="book-btn hidden">Retry</button>
      <div id="bookList"></div>
    </dialog>
    <?php endif; ?>
    <!-- Overlays -->
    <div id="deviceNotice" style="width:min(28rem,calc(100% - 1.5rem))" class="hidden fixed bottom-3 left-1/2 -translate-x-1/2 z-40 bg-gray-900 border border-amber-700/70 rounded-xl p-3 shadow-lg" role="alert">
      <div class="flex items-start gap-2">
        <svg class="icon text-amber-400 flex-none mt-0.5" style="width:1rem;height:1rem"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>
        <p id="deviceNoticeText" class="text-xs text-amber-100 leading-relaxed"></p>
      </div>
      <div class="mt-2 flex items-center justify-end gap-2">
        <button id="deviceDismissBtn" class="text-xs text-gray-400 hover:text-gray-200 px-2 py-1">Dismiss</button>
        <button id="deviceRetryBtn" class="flex items-center gap-1.5 text-xs font-semibold bg-amber-600 hover:bg-amber-500 text-white px-3 py-1.5 rounded-lg">
          <svg class="icon btn-face" style="width:.85rem;height:.85rem"><use href="../assets/icons/sprite.svg#i-refresh-cw"></use></svg><svg class="icon btn-spin" style="width:.85rem;height:.85rem"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
          Try again
        </button>
      </div>
    </div>

    <div id="errorOverlay" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-950/90 p-6">
      <div class="max-w-md w-full bg-gray-900 border border-gray-700 rounded-xl p-6 text-center">
        <p id="errorText" class="text-sm text-red-300 mb-4"></p>
        <div class="flex flex-wrap items-center justify-center gap-2">
          <button id="errorReloadBtn" onclick="location.reload()" class="bg-gray-700 hover:bg-gray-600 text-white text-sm font-semibold py-2 px-4 rounded-lg">Reload</button>
          <!-- Shown only when the call never started: the board still works, and
               losing video should not cost the lesson its whiteboard as well. -->
          <button id="errorContinueBtn" class="hidden bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold py-2 px-4 rounded-lg">Use the whiteboard anyway</button>
        </div>
      </div>
    </div>
    <button id="audioUnlock" class="hidden fixed top-14 right-3 z-50 flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-3 py-2 rounded-lg shadow">
      <svg class="icon btn-face"><use href="../assets/icons/sprite.svg#i-volume-2"></use></svg><svg class="icon btn-spin"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
      Click to enable audio
    </button>
  </div>

  <script>
    // The controls are one set of buttons that lives in two places: the sidebar on
    // a wide screen, the header on a narrow one (where Excalidraw owns the bottom
    // of the board). Moving the node keeps the ids unique — a duplicated bar would
    // give every control button a twin. Runs before the room boots so the buttons
    // are already in place if the call is slow or fails.
    (function () {
      var mq = window.matchMedia('(max-width: 1023px)');
      var panel = document.getElementById('camPanel');
      function relocateControls() {
        var ctl = document.getElementById('roomControls');
        var host = mq.matches ? document.getElementById('hdrControls')
                              : document.getElementById('camPanel');
        if (ctl && ctl.parentNode !== host) host.appendChild(ctl);
        if (!mq.matches) panel.classList.remove('is-collapsed');
      }
      relocateControls();
      // Both signals: matchMedia 'change' is the precise one, but it does not fire
      // in every engine on a window resize, and a stranded control bar means a
      // lesson with no mute or leave button. resize is cheap and relocate is a no-op
      // once the bar is already in the right place.
      if (mq.addEventListener) mq.addEventListener('change', relocateControls);
      else if (mq.addListener) mq.addListener(relocateControls);
      window.addEventListener('resize', relocateControls);
      window.addEventListener('orientationchange', relocateControls);
      // The observer is the one that cannot be missed: it fires on the layout
      // change itself rather than on an event someone has to dispatch.
      if (window.ResizeObserver) new ResizeObserver(relocateControls).observe(document.documentElement);

      document.getElementById('camToggle').addEventListener('click', function () {
        var hidden = panel.classList.toggle('is-collapsed');
        this.setAttribute('aria-expanded', hidden ? 'false' : 'true');
        var label = hidden ? 'Show the cameras' : 'Hide the cameras';
        this.title = label; this.setAttribute('aria-label', label);
        this.querySelector('use').setAttribute('href',
          window.LESSON_SPRITE + (hidden ? '#i-chevron-right' : '#i-chevron-left'));
      });
    })();
  </script>

  <!-- App scripts and styles use the filemtime helper declared above. -->
  <script src="../assets/livekit/room.js?v=<?= $v('room.js') ?>"></script>
  <script src="../assets/livekit/views.js?v=<?= $v('views.js') ?>"></script>
  <script src="../assets/livekit/book_sync.js?v=<?= $v('book_sync.js') ?>"></script>
  <script src="../assets/livekit/books.js?v=<?= $v('books.js') ?>"></script>
  <script src="../assets/livekit/whiteboard.js?v=<?= $v('whiteboard.js') ?>"></script>
  <?php if ($IS_TUTOR): ?><script src="../assets/livekit/recorder.js?v=<?= $v('recorder.js') ?>"></script><?php endif; ?>
  <script>
    if (window.pdfjsLib) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = "../assets/livekit/vendor/pdf.worker.min.js";
    }
    (async () => {
      initLessonViews();
      let room = null;
      try {
        room = await initLessonRoom({
          tokenUrl: 'api/livekit_token.php?lesson_id=<?= (int)$lesson_id ?>',
          leaveUrl: 'dashboard.php',
          isHost: <?= $IS_TUTOR ? 'true' : 'false' ?>
        });
      } catch (e) {
        console.error('room init failed', e);
        document.getElementById('errorText').textContent = 'Could not start the lesson: ' + e.message;
        document.getElementById('errorContinueBtn').classList.remove('hidden');
        document.getElementById('errorContinueBtn').onclick = function () {
          document.getElementById('errorOverlay').classList.add('hidden');
        };
        document.getElementById('errorOverlay').classList.remove('hidden');
      }
      // The board is booted whether or not the call came up. It used to return
      // early on a null room, so anything that stopped LiveKit — a blocked
      // WebSocket, an expired token — left both sides staring at a blank page
      // instead of a whiteboard they could still have taught on.
      try {
        await initWhiteboard(room, {
          lessonId: <?= (int)$lesson_id ?>,
          saveUrl: '../api/whiteboard.php?lesson_id=<?= (int)$lesson_id ?>',
          statusEl: document.getElementById('saveStatus'),
          uploadBtn: document.getElementById('btnUpload'),
          uploadInput: document.getElementById('uploadInput')
        });
      } catch (e) {
        // whiteboard failure must never take down the video call
        console.error('whiteboard init failed', e);
        document.getElementById('saveStatus').textContent = 'Whiteboard failed to load';
      }
      try {
        await initLessonBooks(room, {
          isTutor: <?= $IS_TUTOR ? 'true' : 'false' ?>,
          url: '../api/lesson_books.php?lesson_id=<?= (int)$lesson_id ?>'
        });
      } catch (e) {
        console.error('book viewer init failed', e);
        if (document.getElementById('btnBooks')) document.getElementById('btnBooks').disabled = true;
      }
      if (room && window.initRecorder) {
        initRecorder(room, {
          lessonId: <?= (int)$lesson_id ?>,
          uploadUrl: 'api/upload_recording.php',
          indicator: document.getElementById('recIndicator')
        });
      }

      // Finish Lesson: confirm, end the lesson for both sides, then go to the wrap-up page.
      var finishBtn = document.getElementById('btnFinish');
      if (finishBtn) {
        finishBtn.addEventListener('click', async function () {
          if (!confirm('End this lesson for you and the student? This will close the room for both of you.')) return;
          window.setBtnBusy(finishBtn, true);
          if (window.lessonRoomEnd) {
            // room.js owns the ending sequence and already flushes the board,
            // finalizes the recording and tells the peer.
            window.lessonRoomEnd(finishBtn.getAttribute('data-finish-url'));
            return;
          }
          // The call never came up, so this is a board-only lesson and nothing else
          // is going to save it on the way out. Flush here rather than navigating
          // straight away and leaving the last few minutes of work to the beacon.
          if (window.lessonBookFlush && !await window.lessonBookFlush()) {
            if (!confirm('The book position could not be saved. Leave anyway?')) {
              window.setBtnBusy(finishBtn, false); return;
            }
          }
          if (window.lessonWhiteboardFlush) {
            try {
              var saved = await window.lessonWhiteboardFlush();
              if (!saved && !confirm('The whiteboard could not be saved. Leave anyway and lose the latest changes?')) {
                window.setBtnBusy(finishBtn, false);
                return;
              }
            } catch (e) { /* leaving is still the instruction */ }
          }
          window.location.href = finishBtn.getAttribute('data-finish-url');
        });
      }
    })();
  </script>
</body>
</html>
