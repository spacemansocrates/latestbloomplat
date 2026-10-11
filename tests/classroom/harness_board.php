<?php
/**
 * tests/classroom/harness_board.php
 *
 * A bare page that boots the REAL whiteboard client (assets/livekit/whiteboard.js)
 * with no video call, so browser tests can drive it.
 *
 * Why the room page itself is not used: components/lesson_room_live_shell.php calls
 * initLessonRoom() first, which needs LiveKit credentials. The test environment has
 * none on purpose -- a test must not be able to open a real room -- so the shell
 * would stop at the connection error before the board ever mounted. whiteboard.js is
 * explicitly built to run with room === null, which is the same code path a lesson
 * takes when LiveKit is blocked, so this exercises production code rather than a
 * reimplementation of it.
 *
 * NOT PART OF THE APPLICATION. tests/classroom/prepare_app.php copies this into the
 * disposable app directory only; it is never deployed. It still requires a real
 * signed-in session, so it cannot be used to read a board the caller has no right to.
 */

session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('sign in first');
}
$lessonId = isset($_GET['lesson_id']) && ctype_digit((string)$_GET['lesson_id'])
    ? (int)$_GET['lesson_id'] : 0;
$failLoad = !empty($_GET['fail_load']);
?>
<!doctype html>
<meta charset="utf-8">
<title>board harness</title>
<style>html,body{margin:0;height:100%}#whiteboard{position:absolute;inset:0}</style>
<div id="whiteboard"></div>
<span id="saveStatus"></span>

<script>
  window.EXCALIDRAW_ASSET_PATH = "assets/livekit/vendor/";
  window.LESSON_SPRITE = "assets/icons/sprite.svg";
  window.__saveUrl = "api/whiteboard.php?lesson_id=<?= $lessonId ?>";

<?php if ($failLoad): ?>
  // Simulate the network failing during the initial board load -- the case that used
  // to produce an "empty" board the client would then happily save over the top of
  // the real one. Only the GET is broken; saves are left alone so the test can prove
  // the client refuses to send them of its own accord.
  (function () {
    var realFetch = window.fetch;
    window.fetch = function (url, opts) {
      var isLoad = String(url).indexOf('whiteboard.php') !== -1 && (!opts || !opts.method || opts.method === 'GET');
      if (isLoad) return Promise.reject(new TypeError('simulated network failure'));
      return realFetch.apply(this, arguments);
    };
  })();
<?php endif; ?>
</script>

<script src="assets/livekit/vendor/react.production.min.js"></script>
<script src="assets/livekit/vendor/react-dom.production.min.js"></script>
<script src="assets/livekit/vendor/excalidraw.production.min.js"></script>
<script src="assets/livekit/vendor/pdf.min.js"></script>
<!-- LiveKit is deliberately NOT loaded: whiteboard.js then runs its room === null path. -->
<script src="assets/livekit/whiteboard.js"></script>
<script>
  (async () => {
    await initWhiteboard(null, {
      lessonId: <?= $lessonId ?>,
      saveUrl: window.__saveUrl,
      statusEl: document.getElementById('saveStatus'),
    });
    // Excalidraw hands its API back asynchronously through the render callback, so
    // wait for it rather than reading it on the next line and getting null.
    for (var i = 0; i < 200 && !window.lessonWhiteboard.getApi(); i++) {
      await new Promise((r) => setTimeout(r, 25));
    }
    window.__boardApi = window.lessonWhiteboard.getApi();
  })();
</script>
