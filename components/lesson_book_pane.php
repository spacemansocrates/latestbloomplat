<?php // Included by the shared room shell. All library strings are set using textContent in books.js. ?>
<section id="bookPane" class="hidden" role="tabpanel" aria-labelledby="view-book">
  <div class="book-heading">
    <strong id="bookTitle">Book</strong>
    <span id="bookFollowing" class="book-chip hidden">Following tutor</span>
    <?php if ($IS_TUTOR): ?>
    <button type="button" id="bookClose" class="book-btn"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>Close book</button>
    <?php endif; ?>
  </div>
  <div class="book-toolbar" role="group" aria-label="Book controls">
    <button type="button" id="bookPrev" class="book-btn" aria-label="Previous page" title="Previous page"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg></button>
    <span id="bookPageLabel" aria-live="polite">Page 1 of ?</span>
    <button type="button" id="bookNext" class="book-btn" aria-label="Next page" title="Next page"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg></button>
    <form id="bookJumpForm" class="book-jump">
      <label for="bookJump">Go to</label><input id="bookJump" type="number" min="1" step="1" value="1" inputmode="numeric">
      <button type="submit" id="bookGo" class="book-btn">Go</button>
    </form>
    <div class="book-zoom" role="group" aria-label="Zoom">
      <button type="button" id="bookZoomOut" class="book-btn" aria-label="Zoom out" title="Zoom out"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-minus"></use></svg></button>
      <span id="bookZoomLabel">100%</span>
      <button type="button" id="bookZoomIn" class="book-btn" aria-label="Zoom in" title="Zoom in"><svg class="icon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-plus"></use></svg></button>
      <button type="button" id="bookFit" class="book-btn" title="Fit page to width"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5v14M21 5v14M7 12h10M9 9l-3 3 3 3M15 9l3 3-3 3"/></svg>Fit</button>
    </div>
    <button type="button" id="bookToBoard" class="book-btn book-primary">
      <svg class="icon btn-spin" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
      <svg class="icon btn-face" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-presentation"></use></svg>
      Put this page on the board
    </button>
  </div>
  <div id="bookStatus" role="status" aria-live="polite" class="book-status hidden">
    <span id="bookStatusText"></span><button type="button" id="bookRetry" class="book-btn hidden">Retry</button>
  </div>
  <div class="book-body">
    <nav id="bookThumbnails" aria-label="Book pages"></nav>
    <div id="bookViewport" tabindex="0" aria-label="Book page">
      <canvas id="bookCanvas" class="hidden" aria-label="Current book page"></canvas>
    </div>
  </div>
</section>
