<?php
// students/recordings.php

// 1) Session & auth
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'recordings';
$student_id   = (int)$_SESSION['user_id'];
$student_name = trim(($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? ''));

date_default_timezone_set($_SESSION['timezone'] ?? 'Africa/Blantyre');

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

$student_timezone = getUserTimezone();

// Helpers (mirror admin normalization)
function student_public_url(string $file_url): string {
    $u = trim($file_url);
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    $u = ltrim($u, '/');
    $u = preg_replace('#^(\.?/)+#', '', $u);
    if (stripos($u, 'uploads/') !== 0) {
        $u = 'uploads/' . $u;
    }
    return '/' . $u;
}

// 3) Fetch recordings (DB + lesson details)
$recordings = [];
$topics_by_lesson = [];

try {
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
            s.name AS subject_name,
            u.first_name AS tutor_first,
            u.last_name  AS tutor_last
        FROM lesson_recordings lr
        JOIN lessons l   ON l.id = lr.lesson_id
        JOIN subjects s  ON s.id = l.subject_id
        JOIN users u     ON u.id = l.tutor_id
        WHERE l.student_id = ?
        ORDER BY COALESCE(lr.recorded_at, l.scheduled_end_time) DESC, lr.id DESC
    ");
    $stmt->execute([$student_id]);
    $recordings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($recordings) {
        $lessonIds = array_values(array_unique(array_map(fn($r) => (int)$r['lesson_id'], $recordings)));
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        
        if (!empty($placeholders)) {
            $topicSql = "
                SELECT ltc.lesson_id, t.name AS topic_name
                FROM lesson_topics_covered ltc
                JOIN topics t ON t.id = ltc.topic_id
                WHERE ltc.lesson_id IN ($placeholders)
                ORDER BY t.name ASC
            ";
            $topicStmt = $pdo->prepare($topicSql);
            $topicStmt->execute($lessonIds);
            while ($row = $topicStmt->fetch(PDO::FETCH_ASSOC)) {
                $lid = (int)$row['lesson_id'];
                $topics_by_lesson[$lid][] = $row['topic_name'];
            }
        }
    }
} catch (Throwable $e) {
    die('Error fetching recordings: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function clean_topics(array $topics): array {
    $out = [];
    foreach ($topics as $t) {
        $trim = trim((string)$t);
        if ($trim !== '') $out[] = $trim;
    }
    return array_values(array_unique($out));
}

// Normalize payload for JS
$recordings_json = [];
foreach ($recordings as $row) {
    $lessonId    = (int)$row['lesson_id'];
    $recordedAt  = $row['recorded_at'] ?: $row['scheduled_end_time'];
    
    // Convert UTC times to local timezone
    $startLocal = utcToLocal($row['scheduled_start_time'], $student_timezone);
    $endLocal = utcToLocal($row['scheduled_end_time'], $student_timezone);
    $recordedLocal = $recordedAt ? utcToLocal($recordedAt, $student_timezone) : null;
    
    $startIso = $startLocal->format(DateTime::ATOM);
    $endIso = $endLocal->format(DateTime::ATOM);
    $recordedIso = $recordedLocal ? $recordedLocal->format(DateTime::ATOM) : null;
    
    $recordings_json[] = [
        'recording_id'   => (int)$row['recording_id'],
        'lesson_id'      => $lessonId,
        'subject'        => (string)$row['subject_name'],
        'tutor'          => trim($row['tutor_first'] . ' ' . $row['tutor_last']),
        'start_iso'      => $startIso,
        'end_iso'        => $endIso,
        'recorded_iso'   => $recordedIso,
        'duration'       => isset($row['duration_seconds']) ? (int)$row['duration_seconds'] : 0,
        'size'           => isset($row['file_size_bytes']) ? (int)$row['file_size_bytes'] : 0,
        'url'            => '../api/recording_url.php?lesson_id=' . (int)$lessonId,
        'fallback_url'   => '../api/recording_url.php?lesson_id=' . (int)$lessonId,
        'status'         => $row['recording_status'] ?: 'Ready',
        'lesson_status'  => $row['lesson_status'] ?: '',
        'topics'         => clean_topics($topics_by_lesson[$lessonId] ?? []),
    ];
}

$unique_topics = [];
foreach ($topics_by_lesson as $topics) {
    foreach ($topics as $t) {
        $tt = trim((string)$t);
        if ($tt !== '') $unique_topics[$tt] = true;
    }
}
$unique_topics = array_keys($unique_topics);
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
  <!-- Lucide Icons -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/theme.css">
  <style>
  </style>
</head>
<body class="flex min-h-screen theme-transition">

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    
    <!-- Header Area -->
    <?php
    $header_config = [
        'page_title' => 'Lesson Recordings',
        'page_subtitle' => 'All your lesson recordings in one place.'
    ];
    include __DIR__ . '/components/header.php';
    ?>

    <!-- Filter & Grid Section -->
    <section class="glass rounded-2xl p-5 shadow-sm">
      <div class="flex flex-col space-y-4 md:space-y-0 md:flex-row md:items-center md:justify-between mb-6">
        <!-- Search Bar -->
        <div class="relative flex-1 max-w-2xl">
          <div class="relative rounded-lg shadow-sm">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-4 w-4 text-gray-400" data-lucide="search" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
            </div>
            <input 
              id="search-input" 
              type="text" 
              placeholder="Search by subject, tutor, or topic" 
              class="block w-full pl-10 pr-24 py-3 border border-gray-300 rounded-lg text-gray-900 dark:text-gray-100 search-input placeholder-gray-500transition duration-150"
            />
            <div class="absolute inset-y-0 right-0 flex items-center pr-2">
              <button 
                id="search-btn" 
                class="px-4 py-1.5 btn-primary text-sm font-medium rounded-md transition-colors"
              >
                Search
              </button>
            </div>
          </div>
        </div>
        
        <!-- Filter Buttons -->
        <div class="flex items-center space-x-3">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Filter by:</span>
          <div class="flex space-x-2">
            <button 
              data-filter-tab="topics" 
              class="filter-tab inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md transition-colors"
            >
              <svg class="w-4 h-4 mr-2" data-lucide="tag" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-tag"></use></svg>
              Topics
            </button>
            <button 
              data-filter-tab="date" 
              class="filter-tab inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md transition-colors"
            >
              <svg class="w-4 h-4 mr-2" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
              Date
            </button>
          </div>
        </div>
      </div>

      <!-- Topics Filter Panel -->
      <div id="filter-panel-topics" class="glass border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6 shadow-sm hidden">
        <h3 class="text-sm font-semibold mb-3 flex items-center">
          <svg class="w-4 h-4 mr-2" data-lucide="filter" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-filter"></use></svg>
          Filter by Topic
        </h3>
        <?php if ($unique_topics): ?>
          <div class="flex flex-wrap gap-2.5">
            <?php foreach ($unique_topics as $topic): ?>
              <button 
                type="button" 
                class="topic-pill pill px-4 py-2 rounded-full text-sm font-medium transition-all duration-200 flex items-center"
                data-topic="<?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?>"
              >
                <svg class="w-3.5 h-3.5 mr-1.5 opacity-70" data-lucide="hash" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-hash"></use></svg>
                <?= htmlspecialchars($topic) ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="flex items-center text-gray-400 dark:text-gray-500 text-sm py-2">
            <svg class="w-4 h-4 mr-2" data-lucide="info" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-info"></use></svg>
            No topics available for filtering
          </div>
        <?php endif; ?>
      </div>

      <!-- Date Range Filter Panel -->
      <div id="filter-panel-date" class="glass border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6 shadow-sm hidden">
        <h3 class="text-sm font-semibold mb-4 flex items-center">
          <svg class="w-4 h-4 mr-2" data-lucide="calendar-range" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar-range"></use></svg>
          Filter by Date Range
        </h3>
        <div class="grid grid-row-2 gap-4">
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="space-y-1">
                  <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">From Date</label>
                  <div class="relative">
                    <input 
                      id="date-from" 
                      type="date" 
                      class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg bg-white search-input transition"
                    >
                    <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                  </div>
                </div>
                
                <div class="space-y-1">
                  <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">To Date</label>
                  <div class="relative">
                    <input 
                      id="date-to" 
                      type="date" 
                      class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg bg-white search-input transition"
                    >
                    <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                  </div>
                </div>
          </div>
          
          <div class="flex items-end space-x-3">
            <button 
              id="date-apply" 
              class="px-4 py-2.5 text-sm font-medium rounded-lg btn-primary flex items-center justify-center"
            >
              <svg class="w-4 h-4 mr-1.5" data-lucide="check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg>
              Apply
            </button>
            <button 
              id="date-clear" 
              class="px-4 py-2.5 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-lg btn-clear transition-colors flex items-center"
            >
              <svg class="w-4 h-4 mr-1.5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
              Clear
            </button>
          </div>
        </div>
      </div>

      <div id="recordings-empty" class="hidden text-center text-gray-500 dark:text-gray-400 py-10 border border-dashed border-gray-300 dark:border-gray-700 rounded-xl">
        <svg class="w-12 h-12 text-gray-400 dark:text-gray-500 mx-auto mb-3" data-lucide="video-off" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video-off"></use></svg>
        <p class="text-lg font-semibold text-gray-900 dark:text-white">No recordings found</p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Recordings will appear here after your lessons.</p>
      </div>

      <div id="recordings-grid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4"></div>
    </section>
  </main>

  <script>
    const recordings = <?php echo json_encode($recordings_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const state = {
      search: '',
      topics: new Set(),
      dateFrom: null,
      dateTo: null,
    };

    function fmtDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtTimeRange(startIso, endIso) {
      if (!startIso || !endIso) return '—';
      const s = new Date(startIso);
      const e = new Date(endIso);
      const opts = { hour: 'numeric', minute: '2-digit' };
      return `${s.toLocaleTimeString([], opts)} - ${e.toLocaleTimeString([], opts)}`;
    }
    function fmtDuration(sec) {
      if (!sec || sec <= 0) return '—';
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return `${m}m ${s.toString().padStart(2,'0')}s`;
    }
    function badgeClass(status) {
      const s = (status || '').toLowerCase();
      if (s === 'ready') return 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-500/30';
      if (s === 'pending') return 'bg-amber-100 text-amber-800 ring-1 ring-amber-500/30';
      if (s === 'failed') return 'bg-red-100 text-red-800 ring-1 ring-red-500/30';
      return 'bg-gray-200 text-gray-800 ring-1 ring-gray-300';
    }

    function render() {
      const grid = document.getElementById('recordings-grid');
      const empty = document.getElementById('recordings-empty');
      if (!grid) return;

      const search = state.search.trim().toLowerCase();
      const topicSel = state.topics;
      const from = state.dateFrom ? new Date(state.dateFrom) : null;
      const to = state.dateTo ? new Date(state.dateTo) : null;
      if (to) to.setHours(23, 59, 59, 999);

      const filtered = recordings.filter(r => {
        const haystack = [
          r.subject || '',
          r.tutor || '',
          (r.topics || []).join(' ')
        ].join(' ').toLowerCase();
        if (search && !haystack.includes(search)) return false;

        if (topicSel.size > 0) {
          const topics = new Set((r.topics || []).map(t => t.toLowerCase()));
          let match = false;
          topicSel.forEach(t => { if (topics.has(t.toLowerCase())) match = true; });
          if (!match) return false;
        }

        if (from || to) {
          const recDate = r.recorded_iso ? new Date(r.recorded_iso) : null;
          if (!recDate) return false;
          if (from && recDate < from) return false;
          if (to && recDate > to) return false;
        }
        return true;
      });

      grid.innerHTML = '';
      if (filtered.length === 0) {
        empty.classList.remove('hidden');
        return;
      }
      empty.classList.add('hidden');

      filtered.forEach(rec => {
        const topics = (rec.topics || []).map(t => `<span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-600 border border-gray-200">${t}</span>`).join(' ');
        const url = rec.url || rec.fallback_url;
        // Recordings are served via the gateway (302 → Cloudflare R2 webm); always inline.
        const canInline = !!url;
        
        const card = document.createElement('article');
        card.className = 'video-card border rounded-2xl p-4 shadow-sm flex flex-col gap-3 transition-transform hover:-translate-y-1';
        
        // Recordings are served by api/recording_url.php, which 302s to the
        // bucket. The external-provider and iframe branches that used to sit here
        // could never run, because the URL is always our own gateway.
        const embed = url
          ? `<video class="w-full rounded-xl border border-gray-200 dark:border-gray-800" controls preload="none" src="${url}"></video>`
          : '<div class="text-sm text-red-400 bg-red-500/10 p-4 rounded-xl text-center">Recording URL missing</div>';
        
        card.innerHTML = `
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-lg font-semibold">${rec.tutor || 'Tutor'}</p>
              <p class="text-sm text-gray-500 dark:text-gray-400">${fmtDate(rec.recorded_iso)}</p>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full ${badgeClass(rec.status)}">${rec.status || 'Ready'}</span>
          </div>
          
          ${embed}
          
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="glass rounded-xl p-3 border border-gray-200 dark:border-gray-700">
              <p class="text-xs">Subject</p>
              <p class="text-xs uppercase tracking-wide">${rec.subject || 'Lesson'}</p>
            </div>
            <div class="glass rounded-xl p-3 border border-gray-200 dark:border-gray-700">
              <p class="text-xs">Time</p>
              <p class="font-semibold text-xs whitespace-nowrap pt-1">${fmtTimeRange(rec.start_iso, rec.end_iso)}</p>
            </div>
          </div>
          
          <div class="flex flex-wrap gap-2 mt-1">${topics || '<span class="text-gray-400 text-xs italic">No topics tagged</span>'}</div>
          
          <div class="flex items-center justify-between gap-3 mt-auto pt-2">
            <div class="text-xs text-gray-400">
              ID: ${rec.lesson_id}
            </div>
            ${url ? `<a href="${url}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg btn-primary font-semibold hover:bg-indigo-500 transition shadow-md shadow-indigo-500/20">
              <svg class="w-4 h-4" data-lucide="play-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-play-circle"></use></svg> Watch Recording
            </a>` : ''}
          </div>
        `;
        // Older lessons still point at recordings that were never migrated off
        // the previous provider, so the gateway answers 404 (or 302s to a dead
        // external link). Without this the student just gets a blank player.
        const video = card.querySelector('video');
        if (video) {
          video.addEventListener('error', () => {
            const note = document.createElement('div');
            note.className = 'flex items-start gap-2 text-sm p-4 rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 text-amber-900 dark:text-amber-200';
            note.innerHTML = '<svg class="w-4 h-4 flex-shrink-0 mt-0.5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>'
              + '<span>This recording can no longer be played \u2014 it was made on the old system and the file was not migrated.</span>';
            video.replaceWith(note);
          }, { once: true });
        }

        grid.appendChild(card);
      });
      
    }

    // Theme toggling is handled by components/header.php + ui_head_boot.php

    function wireFilters() {
      const searchInput = document.getElementById('search-input');
      const searchBtn = document.getElementById('search-btn');
      if (searchInput && searchBtn) {
        const applySearch = () => { state.search = searchInput.value || ''; render(); };
        searchBtn.addEventListener('click', applySearch);
        searchInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') applySearch(); });
      }

      document.querySelectorAll('.topic-pill').forEach(btn => {
        btn.addEventListener('click', () => {
          const topic = btn.dataset.topic || '';
          if (state.topics.has(topic)) {
            state.topics.delete(topic);
            btn.classList.remove('border-indigo-500','text-indigo-600','dark:text-white', 'ring-1', 'ring-indigo-500');
            btn.classList.add('text-gray-700','dark:text-gray-100');
          } else {
            state.topics.add(topic);
            btn.classList.add('border-indigo-500','text-indigo-600','dark:text-white', 'ring-1', 'ring-indigo-500');
            btn.classList.remove('text-gray-700','dark:text-gray-100');
          }
          render();
        });
      });

      const dateFrom = document.getElementById('date-from');
      const dateTo = document.getElementById('date-to');
      const applyDate = document.getElementById('date-apply');
      const clearDate = document.getElementById('date-clear');
      if (applyDate && dateFrom && dateTo) {
        applyDate.addEventListener('click', () => {
          state.dateFrom = dateFrom.value || null;
          state.dateTo = dateTo.value || null;
          render();
        });
      }
      if (clearDate && dateFrom && dateTo) {
        clearDate.addEventListener('click', () => {
          dateFrom.value = '';
          dateTo.value = '';
          state.dateFrom = null;
          state.dateTo = null;
          render();
        });
      }

      const tabs = document.querySelectorAll('[data-filter-tab]');
      const panels = {
        topics: document.getElementById('filter-panel-topics'),
        date: document.getElementById('filter-panel-date'),
      };
      tabs.forEach(tab => {
        tab.addEventListener('click', () => {
          const key = tab.dataset.filterTab;
          tabs.forEach(t => t.classList.remove('border-indigo-500','text-indigo-600','dark:text-white','bg-indigo-50','dark:bg-indigo-600/10'));
          tab.classList.add('border-indigo-500','text-indigo-600','dark:text-white','bg-indigo-50','dark:bg-indigo-600/10');
          Object.keys(panels).forEach(k => {
            if (panels[k]) panels[k].classList.toggle('hidden', k !== key);
          });
        });
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      wireFilters();
      render();
      });
  </script>

</body>
</html>
