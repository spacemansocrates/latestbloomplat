<?php
// tutor/recordings.php - a tutor's own lesson recordings, filterable by student,
// subject, topic and date, and groupable by topic.
require_once __DIR__ . '/auth_tutor_only.php';
$current_page = 'recordings';

date_default_timezone_set($_SESSION['timezone'] ?? 'Africa/Blantyre');

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

$tutor_timezone = getUserTimezone();

// Recordings arrive on their own: the LiveKit room uploads them when a lesson
// ends, so this page only ever reads. There is nothing to sync and no button to
// press. It used to call a third-party API once per lesson before printing a
// byte, which cost roughly 20s of blank screen on every visit.





$recordings     = [];
$students_index = [];
$subjects_index = [];
$topics_index   = [];

try {
    // Topics come from a correlated subquery rather than a join. A lesson can cover
    // several topics, and joining lesson_topics_covered here would multiply the
    // recording rows by the number of topics on it.
    $stmt = $pdo->prepare("
        SELECT
            lr.id AS recording_id,
            lr.lesson_id,
            lr.file_url,
            COALESCE(lr.playback_url, lr.file_url) AS playback_url,
            lr.file_size_bytes,
            lr.duration_seconds,
            lr.recorded_at,
            lr.status AS recording_status,
            l.scheduled_start_time,
            l.scheduled_end_time,
            l.status AS lesson_status,
            l.student_id,
            COALESCE(s.name, 'Unassigned') AS subject_name,
            stu.first_name AS student_first,
            stu.last_name  AS student_last,
            stu.profile_photo_url AS student_photo_url,
            (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR '\\n')
               FROM lesson_topics_covered ltc
               JOIN topics t ON t.id = ltc.topic_id
              WHERE ltc.lesson_id = l.id) AS topic_names
        FROM lesson_recordings lr
        JOIN lessons l       ON l.id = lr.lesson_id
        -- Both of these are LEFT joins on purpose. lessons.student_id is nullable and
        -- at least one recorded lesson has no student on it, so an inner join here
        -- made that recording invisible to the tutor who made it rather than showing
        -- it with a placeholder name.
        LEFT JOIN subjects s ON s.id = l.subject_id
        LEFT JOIN users stu  ON stu.id = l.student_id
        WHERE l.tutor_id = ?
        ORDER BY COALESCE(lr.recorded_at, l.scheduled_end_time) DESC, lr.id DESC
    ");
    $stmt->execute([$tutor_id]);
    $recordings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die('Error fetching recordings: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$recordings_json = [];
foreach ($recordings as $row) {
    $lessonId    = (int)$row['lesson_id'];
    $studentId   = (int)$row['student_id'];
    $studentName = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
    if ($studentName === '') $studentName = 'Unknown student';
    $recordedAt  = $row['recorded_at'] ?: $row['scheduled_end_time'];

    $startLocal    = utcToLocal($row['scheduled_start_time'], $tutor_timezone);
    $endLocal      = utcToLocal($row['scheduled_end_time'], $tutor_timezone);
    $recordedLocal = $recordedAt ? utcToLocal($recordedAt, $tutor_timezone) : null;

    $topics = [];
    if (!empty($row['topic_names'])) {
        $topics = array_values(array_filter(array_map('trim', explode("\n", (string)$row['topic_names']))));
    }

    $photo = resolve_photo($row['student_photo_url'] ?? '', $studentName);

    $recordings_json[] = [
        'recording_id'  => (int)$row['recording_id'],
        'lesson_id'     => $lessonId,
        'student_id'    => $studentId,
        'subject'       => (string)$row['subject_name'],
        'topics'        => $topics,
        'student'       => $studentName,
        'student_photo' => $photo,
        'start_iso'     => $startLocal->format(DateTime::ATOM),
        'end_iso'       => $endLocal->format(DateTime::ATOM),
        'recorded_iso'  => $recordedLocal ? $recordedLocal->format(DateTime::ATOM) : null,
        'duration'      => isset($row['duration_seconds']) ? (int)$row['duration_seconds'] : 0,
        'size'          => isset($row['file_size_bytes']) ? (int)$row['file_size_bytes'] : 0,
        // Always our own gateway: it checks the viewer is a party to the lesson and
        // then redirects to a bucket link that expires in an hour.
        'url'           => '../api/recording_url.php?lesson_id=' . $lessonId,
        'status'        => $row['recording_status'] ?: 'Ready',
        'lesson_status' => $row['lesson_status'] ?: '',
    ];

    if (!isset($students_index[$studentId])) {
        $students_index[$studentId] = ['id' => $studentId, 'name' => $studentName];
    }
    $subjects_index[$row['subject_name']] = true;
    foreach ($topics as $t) { $topics_index[$t] = true; }
}

$students_list = array_values($students_index);
usort($students_list, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$subjects_list = array_keys($subjects_index);
sort($subjects_list, SORT_NATURAL | SORT_FLAG_CASE);

$topics_list = array_keys($topics_index);
sort($topics_list, SORT_NATURAL | SORT_FLAG_CASE);

$untagged_count  = count(array_filter($recordings_json, fn($r) => empty($r['topics'])));
$recording_count = count($recordings_json);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lesson Recordings</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
  <link rel="stylesheet" href="css/theme.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }

    /* A poster covers each video until someone asks to play, so opening the page
       does not fire one presigned-URL request per card. */
    .rec-poster {
      background-image:
        radial-gradient(circle at 30% 20%, rgba(99,102,241,0.45), transparent 55%),
        radial-gradient(circle at 75% 80%, rgba(168,85,247,0.40), transparent 55%),
        linear-gradient(135deg, #1e1b4b, #0f172a);
    }
    .rec-poster:hover .rec-play { transform: scale(1.08); }
    .rec-play { transition: transform .18s ease; }

    .chip {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .15rem .55rem; border-radius: 9999px;
      font-size: .7rem; font-weight: 600; line-height: 1.45;
    }

    /* Filter controls. A native select ignores most utility classes, so the closed
       control is styled here and both themes are spelled out. */
    .rec-select {
      appearance: none;
      border-radius: .75rem;
      font-size: .75rem; font-weight: 600;
      padding: .55rem 1.9rem .55rem .7rem;
      cursor: pointer; outline: none; border: 1px solid transparent;
    }
    .theme-light .rec-select { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
    .theme-light .rec-select:hover { background:#f1f5f9; }
    body:not(.theme-light) .rec-select { background:rgba(255,255,255,.05); color:#cbd5e1; border-color:rgba(255,255,255,.08); }
    body:not(.theme-light) .rec-select:hover { background:rgba(255,255,255,.1); }
    .rec-select:focus-visible { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.3); }
    .rec-select option { color:#0f172a; background:#fff; }

    .rec-card { transition: transform .18s ease; }
    .rec-card:hover { transform: translateY(-2px); }

    .rec-rule { height:1px; flex:1; }
    .theme-light .rec-rule { background:#e2e8f0; }
    body:not(.theme-light) .rec-rule { background:rgba(255,255,255,.08); }

    @media (prefers-reduced-motion: reduce) {
      .rec-play, .rec-card { transition: none !important; }
    }
  </style>
</head>
<body class="flex min-h-screen theme-transition">

  <?php include 'components/sidebar.php'; ?>

  <!-- min-w-0: main is a flex item, so it defaults to min-width:auto and refuses
       to shrink below the min-content width of what is inside it. The card header
       rows hold nowrap truncating text, so on a 375px phone that pushed the whole
       page 10px wide and the body scrolled sideways. -->
  <main class="flex-1 min-w-0 p-6 lg:p-8">

    <?php
    $header_title = "Lesson Recordings";
    $header_subtitle = "Every lesson you have recorded, searchable by student, subject or topic.";
    include 'components/header.php';
    ?>

    <div class="max-w-7xl mx-auto">

      <!-- Toolbar -->
      <section class="mb-6 card-base rounded-2xl shadow-sm" style="padding:1rem">

        <div class="flex flex-col lg:flex-row lg:items-center gap-3">
          <!-- Filters as you type. There is no button to press. -->
          <div class="relative flex-1">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500 dark:text-slate-400">
              <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
            </div>
            <label for="search-input" class="sr-only">Search recordings</label>
            <input type="search" id="search-input" autocomplete="off"
                   class="w-full bg-transparent border rounded-xl py-2.5 pl-10 pr-3 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-500 dark:placeholder-slate-400 border-slate-200 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                   placeholder="Search by student, subject or topic">
          </div>

          <button id="group-toggle" type="button" aria-pressed="false"
                  class="inline-flex items-center justify-center gap-2 text-xs font-semibold px-3 py-2.5 rounded-xl border border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition-colors">
            <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-tag"></use></svg>
            <span>Group by topic</span>
          </button>
        </div>

        <div class="flex flex-wrap items-center gap-2 mt-3">
          <label for="student-filter" class="sr-only">Filter by student</label>
          <div class="relative">
            <select id="student-filter" class="rec-select">
              <option value="">All students</option>
              <?php foreach ($students_list as $stu): ?>
                <option value="<?= (int)$stu['id'] ?>"><?= h($stu['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <svg class="w-3.5 h-3.5 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500 dark:text-slate-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
          </div>

          <label for="subject-filter" class="sr-only">Filter by subject</label>
          <div class="relative">
            <select id="subject-filter" class="rec-select">
              <option value="">All subjects</option>
              <?php foreach ($subjects_list as $subj): ?>
                <option value="<?= h($subj) ?>"><?= h($subj) ?></option>
              <?php endforeach; ?>
            </select>
            <svg class="w-3.5 h-3.5 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500 dark:text-slate-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
          </div>

          <label for="topic-filter" class="sr-only">Filter by topic</label>
          <div class="relative">
            <select id="topic-filter" class="rec-select">
              <option value="">All topics</option>
              <?php foreach ($topics_list as $t): ?>
                <option value="<?= h($t) ?>"><?= h($t) ?></option>
              <?php endforeach; ?>
              <?php if ($untagged_count > 0): ?>
                <option value="__none__">No topic recorded (<?= (int)$untagged_count ?>)</option>
              <?php endif; ?>
            </select>
            <svg class="w-3.5 h-3.5 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500 dark:text-slate-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
          </div>

          <label for="sort-select" class="sr-only">Sort recordings</label>
          <div class="relative">
            <select id="sort-select" class="rec-select">
              <option value="recent">Newest first</option>
              <option value="oldest">Oldest first</option>
              <option value="topic">Topic A to Z</option>
              <option value="subject">Subject A to Z</option>
              <option value="student">Student A to Z</option>
              <option value="longest">Longest first</option>
            </select>
            <svg class="w-3.5 h-3.5 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500 dark:text-slate-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
          </div>

          <div class="flex items-center gap-1.5">
            <label for="date-from" class="sr-only">Recorded on or after</label>
            <input id="date-from" type="date" class="rec-select" style="padding-right:.7rem" title="Recorded on or after">
            <span class="text-xs text-slate-500 dark:text-slate-400">to</span>
            <label for="date-to" class="sr-only">Recorded on or before</label>
            <input id="date-to" type="date" class="rec-select" style="padding-right:.7rem" title="Recorded on or before">
          </div>

          <button id="clear-filters" type="button"
                  class="hidden items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">
            <svg class="w-3.5 h-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            Clear filters
          </button>
        </div>
      </section>

      <p id="result-summary" class="text-sm text-slate-600 dark:text-slate-400 mb-4"></p>

      <div id="recordings-empty" class="hidden text-center py-14 border border-dashed border-slate-300 dark:border-white/10 rounded-2xl">
        <svg class="w-10 h-10 text-slate-400 dark:text-slate-500 mx-auto mb-3" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-camera-off"></use></svg>
        <p class="text-lg font-semibold text-slate-900 dark:text-white" id="empty-title">No recordings yet</p>
        <p class="text-sm text-slate-600 dark:text-slate-400" id="empty-note">Recordings appear here after your lessons.</p>
      </div>

      <div id="recordings-out"></div>
    </div>
  </main>

  <script>
    const recordings = <?php echo json_encode($recordings_json,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const NO_TOPIC = '__none__';

    const state = {
      search: '', student: '', subject: '', topic: '',
      dateFrom: null, dateTo: null,
      sort: 'recent', grouped: false,
    };

    // Student names, subject names and topic names are all free text out of the
    // database, and every one of them is interpolated into markup below.
    function esc(v) {
      return String(v ?? '').replace(/[&<>"']/g, c => (
        { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
      ));
    }

    function fmtDate(iso) {
      if (!iso) return 'Date unknown';
      return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtTimeRange(startIso, endIso) {
      if (!startIso || !endIso) return '';
      const opts = { hour: 'numeric', minute: '2-digit' };
      return `${new Date(startIso).toLocaleTimeString([], opts)} - ${new Date(endIso).toLocaleTimeString([], opts)}`;
    }
    // Clock format, so a 7 minute clip and a 70 minute one are told apart at a glance.
    function fmtClock(sec) {
      if (!sec || sec <= 0) return null;
      const h = Math.floor(sec / 3600);
      const m = Math.floor((sec % 3600) / 60);
      const s = Math.floor(sec % 60);
      return h > 0
        ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${m}:${String(s).padStart(2,'0')}`;
    }
    function fmtTotal(sec) {
      if (!sec || sec <= 0) return null;
      const h = Math.floor(sec / 3600);
      const m = Math.round((sec % 3600) / 60);
      return h > 0 ? `${h}h ${m}m` : `${m}m`;
    }
    function fmtSize(bytes) {
      if (!bytes || bytes <= 0) return null;
      const mb = bytes / (1024 * 1024);
      return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    function statusChip(status) {
      const s = (status || '').toLowerCase();
      if (s === 'ready')   return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300';
      if (s === 'pending') return 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300';
      if (s === 'failed')  return 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300';
      return 'bg-slate-200 text-slate-800 dark:bg-white/10 dark:text-slate-300';
    }

    function matches(r) {
      const search = state.search.trim().toLowerCase();
      if (search) {
        const hay = [r.student, r.subject, ...(r.topics || [])].join(' ').toLowerCase();
        if (!hay.includes(search)) return false;
      }
      if (state.student && String(r.student_id) !== state.student) return false;
      if (state.subject && (r.subject || '').toLowerCase() !== state.subject.toLowerCase()) return false;

      if (state.topic === NO_TOPIC) {
        if ((r.topics || []).length > 0) return false;
      } else if (state.topic) {
        const want = state.topic.toLowerCase();
        if (!(r.topics || []).some(t => t.toLowerCase() === want)) return false;
      }

      if (state.dateFrom || state.dateTo) {
        // Recordings with no recorded_at fall back to the lesson end, set in PHP.
        const d = r.recorded_iso ? new Date(r.recorded_iso) : null;
        if (!d) return false;
        if (state.dateFrom && d < new Date(state.dateFrom)) return false;
        if (state.dateTo) {
          const to = new Date(state.dateTo);
          to.setHours(23, 59, 59, 999);
          if (d > to) return false;
        }
      }
      return true;
    }

    function firstTopic(r) {
      // topics arrive alphabetically from the query, so [0] is a stable key.
      return (r.topics && r.topics.length) ? r.topics[0].toLowerCase() : null;
    }

    function sortRows(rows) {
      const byDate = (a, b) => new Date(b.recorded_iso || 0) - new Date(a.recorded_iso || 0);
      const out = rows.slice();
      switch (state.sort) {
        case 'oldest':  out.sort((a, b) => -byDate(a, b)); break;
        case 'student': out.sort((a, b) => a.student.localeCompare(b.student) || byDate(a, b)); break;
        case 'subject': out.sort((a, b) => a.subject.localeCompare(b.subject) || byDate(a, b)); break;
        case 'longest': out.sort((a, b) => (b.duration || 0) - (a.duration || 0)); break;
        case 'topic':
          // Untagged recordings sort last rather than first, so the ones that
          // actually carry a topic are what you see at the top.
          out.sort((a, b) => {
            const ta = firstTopic(a), tb = firstTopic(b);
            if (ta === null && tb === null) return byDate(a, b);
            if (ta === null) return 1;
            if (tb === null) return -1;
            return ta.localeCompare(tb) || byDate(a, b);
          });
          break;
        default: out.sort(byDate);
      }
      return out;
    }

    function card(rec) {
      const clock  = fmtClock(rec.duration);
      const size   = fmtSize(rec.size);
      const times  = fmtTimeRange(rec.start_iso, rec.end_iso);
      const topics = rec.topics || [];

      const topicChips = topics.length
        ? topics.map(t => `<span class="chip bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300">
             <svg class="w-3 h-3" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-tag"></use></svg>${esc(t)}
           </span>`).join('')
        : `<span class="chip bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-400">No topic recorded</span>`;

      const meta = [
        clock ? `<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clock"></use></svg>${clock}</span>` : '',
        size  ? `<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-hard-drive"></use></svg>${size}</span>` : '',
        `<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-hash"></use></svg>Lesson ${rec.lesson_id}</span>`,
      ].filter(Boolean).join('');

      const el = document.createElement('article');
      el.className = 'rec-card card-base rounded-2xl shadow-sm flex flex-col overflow-hidden';
      el.style.padding = '0';
      el.innerHTML = `
        <div class="rec-poster relative aspect-video flex items-center justify-center cursor-pointer"
             role="button" tabindex="0"
             aria-label="Play the recording of ${esc(rec.subject)} with ${esc(rec.student)}"
             data-url="${esc(rec.url)}">
          <div class="rec-play w-14 h-14 rounded-full bg-white/15 backdrop-blur flex items-center justify-center ring-1 ring-white/30">
            <svg class="w-7 h-7 text-white" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-play"></use></svg>
          </div>
          ${clock ? `<span class="absolute bottom-2 right-2 text-[11px] font-semibold text-white bg-black/60 px-1.5 py-0.5 rounded">${clock}</span>` : ''}
        </div>

        <div class="p-4 flex flex-col gap-3 flex-1">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <img src="${esc(rec.student_photo)}" alt=""
                   class="w-10 h-10 rounded-full object-cover flex-shrink-0 ring-1 ring-slate-200 dark:ring-white/10"
                   onerror="this.onerror=null;this.src='../assets/avatar.php?s=96&amp;bg=1f2937&amp;fg=ffffff&amp;text=U'">
              <div class="min-w-0">
                <p class="font-semibold text-slate-900 dark:text-white truncate">${esc(rec.student)}</p>
                <p class="text-xs text-slate-600 dark:text-slate-400 truncate">
                  ${esc(rec.subject)} &middot; ${fmtDate(rec.recorded_iso)}${times ? ' &middot; ' + times : ''}
                </p>
              </div>
            </div>
            <span class="chip flex-shrink-0 ${statusChip(rec.status)}">${esc(rec.status)}</span>
          </div>

          <div class="flex flex-wrap gap-1.5">${topicChips}</div>

          <div class="flex items-center gap-4 text-xs text-slate-600 dark:text-slate-400 mt-auto pt-1">${meta}</div>
        </div>`;

      const poster = el.querySelector('.rec-poster');
      poster.addEventListener('click', () => play(poster, rec));
      poster.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); play(poster, rec); }
      });
      return el;
    }

    // Swap the poster for a real player only when asked. Each recording is served
    // through api/recording_url.php, which 302s to a link that expires in an hour,
    // so loading every card up front would be a request per card for videos nobody
    // has asked to watch.
    function play(poster, rec) {
      const video = document.createElement('video');
      video.className = 'w-full aspect-video bg-black';
      video.controls = true;
      video.autoplay = true;
      video.preload = 'metadata';
      video.src = rec.url;

      const fail = document.createElement('div');
      fail.className = 'aspect-video flex flex-col items-center justify-center gap-2 text-center px-4 bg-slate-100 dark:bg-white/5';
      const failNote = () => fail.querySelector('p:last-child');
      fail.innerHTML = `
        <svg class="w-8 h-8 text-slate-400 dark:text-slate-500" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
        <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">This recording would not play</p>
        <p class="text-xs text-slate-600 dark:text-slate-400">Checking why...</p>`;

      // A <video> error tells us nothing about why. The gateway does know, and says
      // so in the response body, so ask it rather than guessing at the reason.
      // redirect:'manual' means a working recording comes back opaque (status 0)
      // instead of being followed out to the bucket.
      async function explain() {
        try {
          const res = await fetch(rec.url, { method: 'HEAD', redirect: 'manual' });
          if (res.status === 404) return 'The video file was never migrated from the old system, so there is nothing to play.';
          if (res.status === 403) return 'You are not allowed to open this recording.';
          if (res.status === 0 || res.ok) return 'The link may have expired. Reload the page and try again.';
          return `The server answered ${res.status} when asked for this file.`;
        } catch (e) {
          return 'The link may have expired. Reload the page and try again.';
        }
      }

      video.addEventListener('error', async () => {
        video.replaceWith(fail);
        failNote().textContent = await explain();
      }, { once: true });
      poster.replaceWith(video);
    }

    function groupByTopic(rows) {
      // A lesson can cover several topics, and it belongs under each of them, so a
      // recording deliberately appears more than once in this view.
      const map = new Map();
      const untagged = [];
      rows.forEach(r => {
        const topics = r.topics || [];
        if (!topics.length) { untagged.push(r); return; }
        topics.forEach(t => {
          if (!map.has(t)) map.set(t, []);
          map.get(t).push(r);
        });
      });
      const groups = [...map.entries()]
        .sort((a, b) => a[0].localeCompare(b[0], undefined, { sensitivity: 'base' }))
        .map(([name, items]) => ({ name, items }));
      if (untagged.length) groups.push({ name: 'No topic recorded', items: untagged, untagged: true });
      return groups;
    }

    function grid() {
      const g = document.createElement('div');
      g.className = 'grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-5';
      return g;
    }

    function render() {
      const out     = document.getElementById('recordings-out');
      const empty   = document.getElementById('recordings-empty');
      const summary = document.getElementById('result-summary');
      const clear   = document.getElementById('clear-filters');
      out.innerHTML = '';

      const rows = sortRows(recordings.filter(matches));
      const filtering = !!(state.search || state.student || state.subject || state.topic || state.dateFrom || state.dateTo);
      clear.classList.toggle('hidden', !filtering);
      clear.classList.toggle('inline-flex', filtering);

      if (!rows.length) {
        empty.classList.remove('hidden');
        document.getElementById('empty-title').textContent =
          recordings.length ? 'Nothing matches those filters' : 'No recordings yet';
        document.getElementById('empty-note').textContent =
          recordings.length ? 'Try widening the date range or clearing a filter.'
                            : 'Recordings appear here after your lessons.';
        summary.textContent = '';
        return;
      }
      empty.classList.add('hidden');

      const total = fmtTotal(rows.reduce((n, r) => n + (r.duration || 0), 0));
      const shown = rows.length === recordings.length
        ? `${rows.length} recording${rows.length === 1 ? '' : 's'}`
        : `${rows.length} of ${recordings.length} recordings`;
      summary.textContent = total ? `${shown} · ${total} of footage` : shown;

      if (!state.grouped) {
        const g = grid();
        rows.forEach(r => g.appendChild(card(r)));
        out.appendChild(g);
        return;
      }

      groupByTopic(rows).forEach(group => {
        const head = document.createElement('div');
        head.className = 'flex items-center gap-3 mb-4 mt-8 first:mt-0';
        head.innerHTML = `
          <h2 class="text-sm font-bold uppercase tracking-wide ${group.untagged ? 'text-slate-600 dark:text-slate-400' : 'text-slate-800 dark:text-slate-200'}">${esc(group.name)}</h2>
          <span class="chip bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300">${group.items.length}</span>
          <span class="rec-rule"></span>`;
        out.appendChild(head);

        const g = grid();
        group.items.forEach(r => g.appendChild(card(r)));
        out.appendChild(g);
      });
    }

    function debounce(fn, ms) {
      let t;
      return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }

    function wire() {
      const search = document.getElementById('search-input');
      search.addEventListener('input', debounce(() => { state.search = search.value; render(); }, 150));

      const bind = (id, key) => {
        const el = document.getElementById(id);
        el.addEventListener('change', () => {
          state[key] = el.value || (key.indexOf('date') === 0 ? null : '');
          render();
        });
      };
      bind('student-filter', 'student');
      bind('subject-filter', 'subject');
      bind('topic-filter',   'topic');
      bind('sort-select',    'sort');
      bind('date-from',      'dateFrom');
      bind('date-to',        'dateTo');

      const groupBtn = document.getElementById('group-toggle');
      groupBtn.addEventListener('click', () => {
        state.grouped = !state.grouped;
        groupBtn.setAttribute('aria-pressed', String(state.grouped));
        groupBtn.classList.toggle('bg-indigo-600', state.grouped);
        groupBtn.classList.toggle('text-white', state.grouped);
        groupBtn.classList.toggle('border-indigo-600', state.grouped);
        groupBtn.classList.toggle('text-slate-600', !state.grouped);
        groupBtn.classList.toggle('dark:text-slate-300', !state.grouped);
        render();
      });

      document.getElementById('clear-filters').addEventListener('click', () => {
        Object.assign(state, { search: '', student: '', subject: '', topic: '', dateFrom: null, dateTo: null });
        search.value = '';
        ['student-filter','subject-filter','topic-filter','date-from','date-to']
          .forEach(id => { document.getElementById(id).value = ''; });
        render();
      });
    }

    document.addEventListener('DOMContentLoaded', () => { wire(); render(); });
  </script>
</body>
</html>
