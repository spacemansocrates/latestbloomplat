<?php
// admin/lessonspace_api_check.php
// Automatic health check for Lessonspace API: verifies key, fetches sessions, and tries launching a lesson (mirrors tutor/join_lesson flow).

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? '';

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../integrations/lessonspace_client.php';

// Organisation ID helper (from env/constant/app_settings just like API key, but lightweight here)
function lessonspace_get_org_id(PDO $pdo): ?string {
    $k = getenv('LESSONSPACE_ORG_ID');
    if ($k && trim($k) !== '') return trim($k);
    if (defined('LESSONSPACE_ORG_ID') && LESSONSPACE_ORG_ID) return trim((string)LESSONSPACE_ORG_ID);
    try {
        $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'lessonspace_org_id' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v && trim($v) !== '') return trim($v);
    } catch (Throwable $e) {}
    // Fallback known org ID (Bloom Education)
    $fallback = '27628';
    return $fallback !== '' ? $fallback : null;
}

// Only admins / admin_details roles
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
    $st = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
    $st->execute([$user_id]);
    $is_admin = (bool)$st->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $s): string {
    if (!$s) return 'N/A';
    try { return (new DateTime($s))->format('M j, Y g:i a'); } catch (Throwable $e) { return $s; }
}

// --- Helpers to choose a lesson for auto launch ---
function pick_lesson_for_check(PDO $pdo): ?array {
    // Prefer Scheduled/InProgress lessons near now; fallback to most recent completed
    $sql = "
      SELECT l.id, l.tutor_id, l.student_id, l.status,
             s.name AS subject_name,
             tu.first_name AS tutor_first, tu.last_name AS tutor_last, tu.email AS tutor_email
      FROM lessons l
      JOIN subjects s ON s.id = l.subject_id
      LEFT JOIN users tu ON tu.id = l.tutor_id
      WHERE l.status IN ('Scheduled','InProgress')
      ORDER BY ABS(TIMESTAMPDIFF(SECOND, l.scheduled_start_time, NOW())) ASC
      LIMIT 1
    ";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $sql2 = "
      SELECT l.id, l.tutor_id, l.student_id, l.status,
             s.name AS subject_name,
             tu.first_name AS tutor_first, tu.last_name AS tutor_last, tu.email AS tutor_email
      FROM lessons l
      JOIN subjects s ON s.id = l.subject_id
      LEFT JOIN users tu ON tu.id = l.tutor_id
      ORDER BY l.scheduled_start_time DESC
      LIMIT 1
    ";
    $row = $pdo->query($sql2)->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// --- Step 1: API key ---
$api_key = lessonspace_get_api_key($pdo);
$api_status = $api_key ? 'loaded' : 'missing';
$org_id = lessonspace_get_org_id($pdo);

// --- Step 2: List recent sessions ---
$sessions = [];
$sessions_error = null;
$sessions_error_detail = null;
// Cache for lesson lookups
$lesson_info_cache = [];
function lesson_info(PDO $pdo, int $lessonId, array &$cache): ?array {
    if (isset($cache[$lessonId])) return $cache[$lessonId];
    $q = $pdo->prepare("
        SELECT l.id, l.student_id, l.tutor_id,
               s.name AS subject_name,
               stu.first_name AS stu_first, stu.last_name AS stu_last,
               tut.first_name AS tut_first, tut.last_name AS tut_last
        FROM lessons l
        JOIN subjects s ON s.id = l.subject_id
        LEFT JOIN users stu ON stu.id = l.student_id
        LEFT JOIN users tut ON tut.id = l.tutor_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $q->execute([$lessonId]);
    $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $cache[$lessonId] = $row;
    return $row;
}
// Fallback: map a playback/file URL to lesson_id via lesson_recordings table
function lesson_id_from_recording_url(PDO $pdo, string $url): ?int {
    if ($url === '') return null;
    $q = $pdo->prepare("SELECT lesson_id FROM lesson_recordings WHERE file_url = ? OR playback_url = ? LIMIT 1");
    $q->execute([$url, $url]);
    $lid = $q->fetchColumn();
    return $lid ? (int)$lid : null;
}
if ($api_key) {
    $get_created = function(array $s) {
        return $s['created'] ?? $s['created_at'] ?? $s['start_time'] ?? null;
    };
    $get_space_id = function(array $s) {
        return $s['space']['id'] ?? $s['space_id'] ?? $s['spaceId'] ?? null;
    };

    // Prefer org-scoped endpoint if org_id is available (more reliable than legacy sessions route)
    $baseSessionsUrl = $org_id
        ? "https://api.thelessonspace.com/v2/organisations/{$org_id}/sessions/?limit=20&order=-created"
        : "https://api.thelessonspace.com/v2/sessions/?limit=20&order=-created";
    $ch = curl_init($baseSessionsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Organisation $api_key"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $http >= 400) {
        $sessions_error = "HTTP $http: " . ($err ?: 'Unexpected response');
        $sessions_error_detail = trim($raw ?? '');
    } else {
        $json = json_decode($raw, true);
        $sessions = is_array($json['results'] ?? null) ? $json['results'] : [];
        // Enrich with lesson id + names when space id follows lesson-{id}
        foreach ($sessions as &$s) {
            $spaceId = $get_space_id($s) ?? '';
            if (is_string($spaceId) && preg_match('/^lesson-(\d+)$/', $spaceId, $m)) {
                $lid = (int)$m[1];
                $s['_lesson_id'] = $lid;
                if ($info = lesson_info($pdo, $lid, $lesson_info_cache)) {
                    $s['_lesson_subject'] = $info['subject_name'] ?? '';
                    $s['_student_name'] = trim(($info['stu_first'] ?? '') . ' ' . ($info['stu_last'] ?? ''));
                    $s['_tutor_name'] = trim(($info['tut_first'] ?? '') . ' ' . ($info['tut_last'] ?? ''));
                }
            } else {
                // Fallback: try metadata.lesson_id if present
                $metaLesson = $s['metadata']['lesson_id'] ?? $s['lesson_id'] ?? null;
                $s['_lesson_id'] = is_numeric($metaLesson) ? (int)$metaLesson : null;
                if ($s['_lesson_id']) {
                    if ($info = lesson_info($pdo, $s['_lesson_id'], $lesson_info_cache)) {
                        $s['_lesson_subject'] = $info['subject_name'] ?? '';
                        $s['_student_name'] = trim(($info['stu_first'] ?? '') . ' ' . ($info['stu_last'] ?? ''));
                        $s['_tutor_name'] = trim(($info['tut_first'] ?? '') . ' ' . ($info['tut_last'] ?? ''));
                    }
                }
            }
            // Final fallback: map lesson by playback URL in lesson_recordings
            if (!($s['_lesson_id'] ?? null) && !empty($s['playback_url'])) {
                $mapped = lesson_id_from_recording_url($pdo, (string)$s['playback_url']);
                if ($mapped) {
                    $s['_lesson_id'] = $mapped;
                    if ($info = lesson_info($pdo, $mapped, $lesson_info_cache)) {
                        $s['_lesson_subject'] = $info['subject_name'] ?? '';
                        $s['_student_name'] = trim(($info['stu_first'] ?? '') . ' ' . ($info['stu_last'] ?? ''));
                        $s['_tutor_name'] = trim(($info['tut_first'] ?? '') . ' ' . ($info['tut_last'] ?? ''));
                    }
                }
            }
            // Normalize created timestamp
            $s['_created_norm'] = $get_created($s);
        }
        unset($s);
    }
}

// --- Step 3: Auto launch a lesson (mirrors tutor/join_lesson) ---
$launch = [
    'status' => 'skipped',
    'message' => '',
    'lesson_id' => null,
    'space_id' => null,
    'client_url' => null,
];
if ($api_key) {
    $lesson = pick_lesson_for_check($pdo);
    if ($lesson) {
        $lesson_id = (int)$lesson['id'];
        $launch['lesson_id'] = $lesson_id;
        try {
            $spaceId = lessonspace_space_id_for_lesson($lesson_id);
            $launch_payload = [
                'id'   => $spaceId,
                'name' => ($lesson['subject_name'] ?? 'Lesson') . ' #' . $lesson_id,
                'user' => [
                    'name'   => trim(($lesson['tutor_first'] ?? '') . ' ' . ($lesson['tutor_last'] ?? '')) ?: 'Admin',
                    'id'     => 'admin-check-' . $user_id,
                    'email'  => $_SESSION['user_email'] ?? ($lesson['tutor_email'] ?? null),
                    'leader' => true,
                ],
                'allow_guests'   => true,
                'record_av'      => true,
                'record_content' => true,
            ];
            $res = lessonspace_launch($pdo, $launch_payload);
            $launch['status'] = 'ok';
            $launch['space_id'] = $spaceId;
            $launch['client_url'] = $res['client_url'] ?? null;
            if (!$launch['client_url']) {
                $launch['status'] = 'error';
                $launch['message'] = 'Launch returned no client_url.';
            }
        } catch (Throwable $e) {
            $launch['status'] = 'error';
            $launch['message'] = $e->getMessage();
        }
    } else {
        $launch['status'] = 'error';
        $launch['message'] = 'No lessons available to test launch.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lessonspace API Health Check</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; background: #0f172a; color: #e5e7eb; }
  </style>
</head>
<body class="flex min-h-screen">
  <?php $current_page = 'recordings'; include __DIR__ . '/admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800/70 p-6 lg:p-8">
    <header class="flex items-center justify-between mb-6">
      <div>
        <p class="text-sm text-gray-400">Admin / Lessonspace</p>
        <h1 class="text-2xl font-bold text-white">API Health Check</h1>
        <p class="text-gray-400 text-sm">Automatically verifies key, reads sessions, and tries launching a space (same flow as tutor join).</p>
      </div>
      <a href="admin_dashboard.php" class="text-sm text-indigo-300 hover:text-indigo-200">Back to dashboard</a>
    </header>

    <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
        <p class="text-sm text-gray-400 mb-1">API Key</p>
        <p class="text-lg font-semibold <?= $api_status === 'loaded' ? 'text-emerald-300' : 'text-rose-300'; ?>">
          <?= $api_status === 'loaded' ? 'Loaded' : 'Missing'; ?>
        </p>
        <p class="text-xs text-gray-400 mt-1">Org ID: <?= h($org_id ?: 'not set'); ?></p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
        <p class="text-sm text-gray-400 mb-1">Sessions fetch</p>
        <?php if ($sessions_error): ?>
          <p class="text-rose-300 text-sm">Failed: <?= h($sessions_error) ?></p>
          <?php if ($sessions_error_detail): ?>
            <pre class="mt-2 text-xs text-rose-200 bg-rose-900/40 border border-rose-700 rounded p-2 whitespace-pre-wrap break-all"><?= h($sessions_error_detail) ?></pre>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-lg font-semibold text-emerald-300">OK</p>
          <p class="text-sm text-gray-400"><?= count($sessions) ?> sessions</p>
        <?php endif; ?>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
        <p class="text-sm text-gray-400 mb-1">Launch test</p>
        <?php if ($launch['status'] === 'ok'): ?>
          <p class="text-lg font-semibold text-emerald-300">Success</p>
          <p class="text-sm text-gray-400">Lesson #<?= h((string)$launch['lesson_id']) ?> · Space <?= h((string)$launch['space_id']) ?></p>
        <?php else: ?>
          <p class="text-lg font-semibold text-rose-300">Failed</p>
          <p class="text-sm text-gray-400"><?= h($launch['message'] ?: 'Skipped') ?></p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($launch['client_url']): ?>
      <div class="bg-emerald-900/30 border border-emerald-700 text-emerald-100 px-4 py-3 rounded-lg mb-6">
        Launch link ready:
        <a href="<?= h($launch['client_url']); ?>" target="_blank" class="underline text-emerald-100">Open Lessonspace</a>
      </div>
    <?php elseif ($launch['status'] === 'error' && $launch['message']): ?>
      <div class="bg-rose-900/30 border border-rose-700 text-rose-100 px-4 py-3 rounded-lg mb-6">
        Launch error: <?= h($launch['message']); ?>
      </div>
    <?php endif; ?>

    <section class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-800 text-gray-300">
            <tr>
              <th class="px-4 py-3 text-left">Created</th>
              <th class="px-4 py-3 text-left">Lesson</th>
              <th class="px-4 py-3 text-left">Student</th>
              <th class="px-4 py-3 text-left">Tutor</th>
              <th class="px-4 py-3 text-left">Space ID</th>
              <th class="px-4 py-3 text-left">Session ID</th>
              <th class="px-4 py-3 text-left">Recording</th>
              <th class="px-4 py-3 text-left">Playback URL</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$api_key): ?>
              <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">API key missing.</td></tr>
            <?php elseif ($sessions_error): ?>
              <tr><td colspan="5" class="px-4 py-8 text-center text-rose-300">
                Failed to fetch sessions: <?= h($sessions_error) ?>
                <?php if ($sessions_error_detail): ?>
                  <div class="mt-2 text-xs text-rose-200 bg-rose-900/30 border border-rose-700 rounded p-2 whitespace-pre-wrap break-all"><?= h($sessions_error_detail) ?></div>
                <?php endif; ?>
              </td></tr>
            <?php elseif (empty($sessions)): ?>
              <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No sessions returned from API.</td></tr>
            <?php else: foreach ($sessions as $s):
              $spaceId = $s['space']['id'] ?? '';
              $playback = $s['playback_url'] ?? '';
              if (!$playback && !empty($s['recordings']) && is_array($s['recordings'])) {
                  foreach ($s['recordings'] as $rec) {
                      if (!empty($rec['playback_url'])) { $playback = $rec['playback_url']; break; }
                  }
              }
              $hasRecording = $playback ? 'Yes' : 'No';
              $lessonId = $s['_lesson_id'] ?? null;
              $studentName = $s['_student_name'] ?? '';
              $tutorName = $s['_tutor_name'] ?? '';
              $subject = $s['_lesson_subject'] ?? '';
            ?>
              <tr class="border-t border-gray-800 hover:bg-gray-800/60">
                <td class="px-4 py-3"><?= h(fmt_dt($s['_created_norm'] ?? $s['created'] ?? null)); ?></td>
                <td class="px-4 py-3">
                  <?= $lessonId ? 'Lesson #' . h((string)$lessonId) : '—'; ?>
                  <?php if ($subject): ?><div class="text-xs text-gray-400"><?= h($subject); ?></div><?php endif; ?>
                </td>
                <td class="px-4 py-3"><?= $studentName ? h($studentName) : '—'; ?></td>
                <td class="px-4 py-3"><?= $tutorName ? h($tutorName) : '—'; ?></td>
                <td class="px-4 py-3"><?= h($spaceId); ?></td>
                <td class="px-4 py-3 text-xs text-gray-200"><?= h($s['id'] ?? ''); ?></td>
                <td class="px-4 py-3">
                  <span class="inline-flex px-2 py-1 rounded-full text-xs <?= $playback ? 'bg-emerald-500/15 text-emerald-200' : 'bg-gray-700 text-gray-300'; ?>">
                    <?= h($hasRecording); ?>
                  </span>
                </td>
                <td class="px-4 py-3 space-y-2">
                  <?php if ($playback): ?>
                    <?php
                      $canInline = preg_match('~\\.(mp4|webm|ogg|m4v)(\\?|$)~i', $playback);
                    ?>
                    <?php if ($canInline): ?>
                      <video controls class="w-48 max-w-full rounded border border-gray-700 bg-black" src="<?= h($playback); ?>"></video>
                    <?php else: ?>
                      <div class="w-48 max-w-full aspect-video rounded border border-gray-700 overflow-hidden">
                        <iframe src="<?= h($playback); ?>" allow="camera; microphone; display-capture; fullscreen;" frameborder="0" class="w-full h-full bg-black"></iframe>
                      </div>
                    <?php endif; ?>
                    <div><a href="<?= h($playback); ?>" target="_blank" class="text-indigo-300 hover:text-indigo-200 break-all text-xs">Open in new tab</a></div>
                  <?php else: ?>
                    <span class="text-gray-500">N/A</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
