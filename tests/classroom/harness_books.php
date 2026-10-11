<?php
// Test-only, copied under tutor/ in the disposable app. Uses the actual room shell
// and all its inline scripts. Only video connection is substituted before boot.
session_start();
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/classroom_workspace.php';
$lesson_id = (int)($_GET['lesson_id'] ?? 103);
$lesson = cw_authorize_lesson($pdo, $lesson_id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['user_role'] ?? ''));
if (!$lesson) { http_response_code(403); exit('Forbidden'); }
$lesson['subject_name'] = 'Classroom book fixture';
$ROOM_ROLE = $lesson['viewer_role'];
$PEER_LABEL = 'Test peer';
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
ob_start();
require __DIR__ . '/../components/lesson_room_live_shell.php';
$html = str_replace("\r\n", "\n", ob_get_clean());
$setup = <<<'JS'
  <script>
    window.__bookPackets = [];
    window.__bookHandlers = {};
    window.__realInitLessonRoom = window.initLessonRoom;
    window.__emit = function (name, ...args) { (window.__bookHandlers[name] || []).forEach(fn => fn(...args)); };
    window.initLessonRoom = async function () {
      if (!new URLSearchParams(location.search).has('sync')) return null;
      window.initRecorder = null;
      const room = {
        lessonHostIdentity: 'host-1', remoteParticipants: new Map(),
        localParticipant: { publishData: async function (bytes, opts) {
          if (opts.topic === 'book') window.__bookPackets.push({time: Date.now(), ...JSON.parse(new TextDecoder().decode(bytes))});
          if (window.__relay) await window.__relay(Array.from(bytes), opts.topic);
        } },
        on: function (event, fn) { (window.__bookHandlers[event] ||= []).push(fn); return this; },
        off: function (event, fn) { window.__bookHandlers[event] = (window.__bookHandlers[event] || []).filter(f => f !== fn); },
      };
      return room;
    };
  </script>
JS;
// Insert before the shell's final inline bootstrap, after all production scripts.
$marker = '  <script>' . "\n" . '    if (window.pdfjsLib)';
if (!str_contains($html, $marker)) throw new RuntimeException('Room boot marker changed');
echo str_replace($marker, $setup . "\n" . $marker, $html);
