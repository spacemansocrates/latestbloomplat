<?php
// students/schedule.php

// 1) Session & auth
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    header('Location: ../login.php');
    exit();
}
$student_id   = (int)$_SESSION['user_id'];
$student_name = ($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? '');

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

$student_timezone = getUserTimezone();

// 3) Fetch this student’s lessons (±60 days around today), include recording if any
try {
    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.tutor_id,
            l.student_id,
            l.subject_id,
            l.scheduled_start_time,
            l.scheduled_end_time,
            l.status,
            l.tutor_notes,
            s.name AS subject_name,
            tu.first_name AS tutor_first_name,
            tu.last_name  AS tutor_last_name,
            COALESCE(l.meeting_url, td.google_meet_link) AS join_url,
            lr.file_url AS recording_url,
            lr.file_size_bytes,
            lr.duration_seconds,
            lr.recorded_at
        FROM lessons l
        JOIN subjects s            ON s.id = l.subject_id
        JOIN users tu              ON tu.id = l.tutor_id
        LEFT JOIN tutor_details td ON td.user_id = l.tutor_id
        LEFT JOIN lesson_recordings lr ON lr.lesson_id = l.id
        WHERE l.student_id = ?
          AND l.scheduled_start_time BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                         AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
        ORDER BY l.scheduled_start_time ASC
    ");
    $stmt->execute([$student_id]);
    $lessons = $stmt->fetchAll();
} catch (PDOException $e) {
    die('Error fetching lessons: ' . htmlspecialchars($e->getMessage()));
}

// The query above only loads +/-60 days, so the calendar must say so when the
// viewer pages outside it -- otherwise an unloaded month looks like an empty one.
$range_start = date('Y-m-d', strtotime('-60 day'));
$range_end   = date('Y-m-d', strtotime('+60 day'));

// Build JSON payload for the calendar
$lessons_json = [];
foreach ($lessons as $row) {
    // Convert UTC to local timezone
    $start = utcToLocal($row['scheduled_start_time'], $student_timezone);
    $end   = utcToLocal($row['scheduled_end_time'], $student_timezone);
    $lessons_json[] = [
        'id'        => (int)$row['id'],
        'date'      => $start->format('Y-m-d'),
        'start_iso' => $start->format(DateTime::ATOM),
        'end_iso'   => $end->format(DateTime::ATOM),
        'time'      => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
        'subject'   => $row['subject_name'],
        'status'    => $row['status'],
        'tutor'     => $row['tutor_first_name'] . ' ' . $row['tutor_last_name'],
        'meet'      => $row['join_url'],
        'notes'     => $row['tutor_notes'] ?? '',
        'rec_url'   => $row['recording_url'] ?: null,
        'rec_dur'   => isset($row['duration_seconds']) ? (int)$row['duration_seconds'] : null,
        'rec_at'    => $row['recorded_at'] ? utcToLocal($row['recorded_at'], $student_timezone)->format(DateTime::ATOM) : null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student – Schedule</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
</head>
<body class="flex min-h-screen">
  <?php 
  // Include centralized page loader component (if not already included by sidebar)
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  ?>

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <?php
      $header_config = [
          'page_title' => 'My Schedule',
          'page_subtitle' => 'See your lessons and join when it\'s time.'
      ];
      include __DIR__ . '/components/header.php';
    ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Calendar -->
       <!-- card-surface -->
      <section class="lg:col-span-2 glass p-6 rounded-xl">
        <div class="flex items-center justify-between mb-6">
          <button id="prev-month" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg text-gray-600 dark:text-gray-400">
            <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg>
          </button>
          <h2 id="month-year" class="text-xl font-bold text-slate-900 dark:text-white"></h2>
          <button id="next-month" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg text-gray-600 dark:text-gray-400">
            <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg>
          </button>
        </div>

        <div class="grid grid-cols-7 gap-2 text-center text-sm text-gray-500 uppercase dark:text-gray-400 mb-4">
          <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
        </div>
        <div id="calendar-grid" class="grid grid-cols-7 gap-2"></div>
        <div id="range-notice" class="hidden mt-4 flex items-start gap-2 text-xs text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <svg class="w-4 h-4 flex-shrink-0 mt-px" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-info"></use></svg>
          <span>Only lessons within 60 days of today are loaded, so part of this month isn&rsquo;t shown here.</span>
        </div>
        <div class="flex flex-wrap gap-4 mt-5 text-xs text-gray-500 dark:text-gray-400">
          <div class="flex items-center gap-2"><span class="dot dot-scheduled"></span> Scheduled</div>
          <div class="flex items-center gap-2"><span class="dot dot-completed"></span> Completed</div>
          <div class="flex items-center gap-2"><span class="dot dot-cancelled"></span> Cancelled</div>
          <div class="flex items-center gap-2"><span class="dot dot-noshow"></span> No-Show</div>
        </div>
      </section>

      <!-- Day’s lessons -->
      <aside class=" glass p-6 rounded-xl">
        <h3 id="list-title" class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Select a date</h3>
        <div id="lesson-list" class="space-y-3">
          <div class="text-center text-gray-500 dark:text-gray-400 py-10">
            <svg class="mx-auto mb-2 text-gray-400 dark:text-gray-500" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
            Click on a date to view lessons.
          </div>
        </div>
      </aside> 
    </div>
  </main>

 <!-- Detail modal -->
<div id="detail-modal" class="modal">
  <div class="modal-panel">
    <button id="close-modal" class="modal-close" aria-label="Close">
      <svg class="w-5 h-5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
    </button>
    <h3 id="md-title" class="modal-title"></h3>
    <p id="md-sub" class="modal-subtitle"></p>
    <div class="modal-body space-y-2 text-sm">
      <div><span class="text-gray-500 dark:text-gray-400">Tutor:</span> <span id="md-tutor" class="font-medium"></span></div>
      <div><span class="text-gray-500 dark:text-gray-400">Date:</span> <span id="md-date" class="font-medium"></span></div>
      <div><span class="text-gray-500 dark:text-gray-400">Time:</span> <span id="md-time" class="font-medium"></span></div>
      <div id="md-notes-wrap" class="mt-3 hidden">
        <div class="text-gray-500 dark:text-gray-400">Notes:</div>
        <div id="md-notes" class="text-gray-700 dark:text-gray-300"></div>
      </div>

      <!-- Recording block -->
      <div id="md-recording-wrap" class="mt-5 hidden">
        <p class="font-semibold mb-2">Recording</p>
        <div id="md-recording" class="space-y-2"></div>
        <p id="md-recording-meta" class="text-xs text-gray-500 dark:text-gray-400"></p>
      </div>
    </div>

    <div id="md-actions" class="modal-footer">
      <!-- filled in by JS -->
    </div>
  </div>
</div>

<script>
// Sync theme with dashboard toggle (localStorage 'theme')
(function(){
  // Default to dark for students
  const saved = localStorage.getItem('theme') || 'dark';
  const isDark = saved === 'dark';
  document.documentElement.classList.toggle('dark', isDark);
  document.body.classList.toggle('theme-light', !isDark);
  document.body.classList.toggle('dark', isDark);
})();

const lessons = <?php echo json_encode($lessons_json, JSON_UNESCAPED_SLASHES); ?>;

const rangeStart  = '<?php echo $range_start; ?>';
const rangeEnd    = '<?php echo $range_end; ?>';
const rangeNotice = document.getElementById('range-notice');

const monthYearEl = document.getElementById('month-year');
const gridEl      = document.getElementById('calendar-grid');
const listEl      = document.getElementById('lesson-list');
const listTitleEl = document.getElementById('list-title');

let current   = new Date(); // today
let viewYear  = current.getFullYear();
let viewMonth = current.getMonth(); // 0-11

const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function statusToClass(status){
  if (status === 'Scheduled') return 'dot-scheduled';
  if (status === 'Completed') return 'dot-completed';
  if (status && status.startsWith('Cancelled')) return 'dot-cancelled';
  if (status === 'NoShow') return 'dot-noshow';
  return 'dot-scheduled';
}

function renderCalendar(){
  gridEl.innerHTML = '';
  monthYearEl.textContent = `${monthNames[viewMonth]} ${viewYear}`;

  // Warn whenever the visible month is not FULLY covered by the loaded window --
  // a partly-loaded month is the misleading case, since the missing days look
  // identical to days that genuinely had no lessons.
  const pad = (n) => String(n).padStart(2, '0');
  const monthFirst = `${viewYear}-${pad(viewMonth + 1)}-01`;
  const monthLast  = `${viewYear}-${pad(viewMonth + 1)}-${pad(new Date(viewYear, viewMonth + 1, 0).getDate())}`;
  if (rangeNotice) rangeNotice.classList.toggle('hidden', monthFirst >= rangeStart && monthLast <= rangeEnd);

  const firstDay = new Date(viewYear, viewMonth, 1);
  const firstWeekday = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1; // Mon=0
  const daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();

  // leading blanks
  for (let i=0; i<firstWeekday; i++) {
    gridEl.appendChild(document.createElement('div'));
  }

  const todayKey = new Date().toISOString().slice(0,10);

  for (let d=1; d<=daysInMonth; d++){
    const dateKey = `${viewYear}-${String(viewMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const dayLessons = lessons.filter(l => l.date === dateKey);

    const cell = document.createElement('div');
    cell.className = 'calendar-day';
    if (dateKey === todayKey) {
      cell.classList.add('today');
    }

    let dots = '';
    if (dayLessons.length){
      const maxDots = Math.min(3, dayLessons.length);
      for (let i=0; i<maxDots; i++){
        dots += `<span class="dot ${statusToClass(dayLessons[i].status)}"></span>`;
      }
      if (dayLessons.length > 3) {
  dots += `<span class="text-[10px] text-gray-400 dark:text-gray-200 ml-1">+${dayLessons.length-3}</span>`;
}

    }

    cell.innerHTML = `
      <div class="flex items-start justify-between">
        <span class="text-slate-900 dark:text-white font-semibold">${d}</span>
      </div>
      <div class="mt-auto flex items-center gap-1">${dots}</div>
    `;

    cell.addEventListener('click', ()=> {
      document.querySelectorAll('.calendar-day').forEach(el=> el.classList.remove('active'));
      cell.classList.add('active');
      renderDay(dateKey);
    });

    gridEl.appendChild(cell);
  }
}

function renderDay(dateKey){
  const pretty = new Date(dateKey + 'T00:00:00').toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'});
  listTitleEl.textContent = `Lessons for ${pretty}`;
  const dayLessons = lessons.filter(l => l.date === dateKey);
  listEl.innerHTML = '';

  if (!dayLessons.length){
    listEl.innerHTML = `<div class="text-center text-gray-500 dark:text-gray-400 py-8">No lessons on this date.</div>`;
    return;
  }

  dayLessons.forEach(l => {
    const row = document.createElement('div');
    row.className = 'lesson-card p-4 rounded-lg border transition-colors';
    const badge =
      l.status === 'Scheduled' ? 'bg-indigo-500/15 text-indigo-300' :
      l.status === 'Completed' ? 'bg-green-500/15 text-green-900 dark:text-green-300' :
      (l.status && l.status.startsWith('Cancelled')) ? 'bg-red-500/15 text-red-300' :
      'bg-yellow-500/15 text-yellow-300';

    row.innerHTML = `
      <div class="flex items-center justify-between">
        <div>
          <div class="text-slate-900 dark:text-white font-semibold">${l.subject}</div>
          <div class="text-sm text-gray-500 dark:text-gray-400">with ${l.tutor}</div>
        </div>
        <span class="text-[11px] px-2 py-1 rounded ${badge}">${l.status}</span>
      </div>
      <div class="text-sm text-indigo-500 dark:text-indigo-300 mt-2">${l.time}</div>
      <div class="mt-3 flex justify-end">
        <button class="detail-btn text-sm font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-800 dark:hover:text-indigo-200" data-id="${l.id}">View details</button>
      </div>
    `;
    listEl.appendChild(row);
  });

  document.querySelectorAll('.detail-btn').forEach(btn=>{
    btn.addEventListener('click', e=>{
      const id = parseInt(e.currentTarget.dataset.id,10);
      openDetail(id);
    });
  });
}

function formatDuration(sec){
  if (!sec || sec <= 0) return '';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}m ${String(s).padStart(2,'0')}s`;
}

function openDetail(id) {
  const l = lessons.find(x => x.id === id);
  if (!l) return;

  const modal = document.getElementById('detail-modal');
  document.getElementById('md-title').textContent = l.subject;
  document.getElementById('md-sub').textContent   = `Lesson #${l.id}`;
  document.getElementById('md-tutor').textContent = l.tutor;
  document.getElementById('md-date').textContent  = new Date(l.date).toLocaleDateString();
  document.getElementById('md-time').textContent  = l.time;

  if (l.notes && l.notes.trim().length) {
    document.getElementById('md-notes-wrap').classList.remove('hidden');
    document.getElementById('md-notes').textContent = l.notes;
  } else {
    document.getElementById('md-notes-wrap').classList.add('hidden');
  }

  // Recording block
  const recWrap = document.getElementById('md-recording-wrap');
  const recBox  = document.getElementById('md-recording');
  const recMeta = document.getElementById('md-recording-meta');

  recBox.innerHTML = '';
  recMeta.textContent = '';

  if (l.rec_url) {
    recWrap.classList.remove('hidden');
    const url = l.rec_url;
    const canInline = /\.(mp4|webm|ogg)$/i.test(url);

    if (canInline) {
      const v = document.createElement('video');
      v.src = url;
      v.controls = true;
      v.className = 'w-full rounded-lg bg-black/30';
      recBox.appendChild(v);
    } else {
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.className = 'text-indigo-500 dark:text-indigo-300 hover:underline';
      a.textContent = 'Open recording';
      recBox.appendChild(a);
    }

    const dur = formatDuration(Number(l.rec_dur || 0));
    const when = l.rec_at ? new Date(l.rec_at.replace(' ', 'T')).toLocaleString() : '';
    recMeta.textContent = `${dur ? 'Duration: ' + dur : ''}${dur && when ? ' · ' : ''}${when ? 'Recorded: ' + when : ''}`;
  } else {
    recWrap.classList.add('hidden');
  }

  // Actions
  const actions = document.getElementById('md-actions');
  actions.innerHTML = '';

  if (l.status === 'Scheduled') {
    const now   = new Date();
    const start = new Date(l.start_iso);
    const end   = new Date(l.end_iso);

    const early = new Date(start.getTime() - 10*60000);
    const late  = new Date(end.getTime() + 15*60000);

    if (now >= early && now <= late) {
      const join = document.createElement('a');
      join.href = `api/join_lesson.php?lesson_id=${encodeURIComponent(l.id)}`;
      join.className = 'btn btn-primary';
      join.textContent = 'Join Lesson';
      actions.appendChild(join);
    }
  }

  // Add close button to actions area
  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'btn btn-clear';
  closeBtn.textContent = 'Close';
  closeBtn.addEventListener('click', () => modal.classList.remove('show'));
  actions.appendChild(closeBtn);

  // Show the modal
  modal.classList.add('show');
}

// Add click handler for detail buttons
document.addEventListener('click', (e) => {
  const detailBtn = e.target.closest('.detail-btn');
  if (detailBtn) {
    const id = parseInt(detailBtn.dataset.id, 10);
    openDetail(id);
  }
});

// Close modal when clicking outside content or on close button
document.getElementById('close-modal')?.addEventListener('click', () => {
  document.getElementById('detail-modal')?.classList.remove('show');
});


// Month nav
document.getElementById('prev-month').addEventListener('click', ()=>{
  viewMonth--;
  if (viewMonth < 0) { viewMonth = 11; viewYear--; }
  renderCalendar();
});
document.getElementById('next-month').addEventListener('click', ()=>{
  viewMonth++;
  if (viewMonth > 11) { viewMonth = 0; viewYear++; }
  renderCalendar();
});

// Initial
renderCalendar();
</script>
</body>
</html>
