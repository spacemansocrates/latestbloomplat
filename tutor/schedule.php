<?php
// schedule.php  — Calendar + Availability Planner

// 1) Session & Auth
require_once __DIR__ . '/auth_tutor_only.php';

$current_page = 'schedule';

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

$tutor_timezone = getUserTimezone();

function clampDayOfWeek($d) {
    $d = (int)$d;
    if ($d < 1) return 1;
    if ($d > 7) return 7;
    return $d;
}

/* =========================
   3) AJAX: Lessons (month)
   ========================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $y = (int)($_GET['year'] ?? date('Y'));
    $m = (int)($_GET['month'] ?? date('n')); // 1..12
    if ($m < 1 || $m > 12) { $m = date('n'); }
    if ($y < 2000 || $y > 2100) { $y = date('Y'); }

    $firstDay = sprintf('%04d-%02d-01 00:00:00', $y, $m);
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $firstDay);
    $nextMonth = (clone $dt)->modify('first day of next month')->format('Y-m-d H:i:s');

    // Rows are selected on the stored time but dated for the calendar after
    // utcToLocal, so a lesson within the tutor's offset of a month edge would be
    // fetched for one month and land on a date the other month owns, showing in
    // neither. Reaching a day past each edge means the row is always fetched; the
    // calendar only renders cells for the month itself, so the extra rows are
    // simply ignored.
    $fetchFrom = (clone $dt)->modify('-1 day')->format('Y-m-d H:i:s');
    $fetchTo   = (new DateTime($nextMonth))->modify('+1 day')->format('Y-m-d H:i:s');

    $sql = "
        SELECT
          l.id, l.scheduled_start_time, l.scheduled_end_time, l.status, l.tutor_notes,
          l.student_id, s.name AS subject_name,
          u.first_name, u.last_name, u.profile_photo_url,
          (lr.id IS NOT NULL) AS has_recording
        FROM lessons l
        JOIN subjects s ON s.id = l.subject_id
        LEFT JOIN users u ON u.id = l.student_id
        LEFT JOIN lesson_recordings lr
               ON lr.lesson_id = l.id AND lr.status <> 'Failed'
        WHERE l.tutor_id = ?
          AND l.scheduled_start_time >= ?
          AND l.scheduled_start_time <  ?
        ORDER BY l.scheduled_start_time ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$tutor_id, $fetchFrom, $fetchTo]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        // Convert UTC to local timezone
        $stDT = utcToLocal($r['scheduled_start_time'], $tutor_timezone);
        $enDT = utcToLocal($r['scheduled_end_time'], $tutor_timezone);
        $dateYmd = $stDT->format('Y-m-d');

        $studentName = $r['student_id'] ? trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) : '— Demo —';
        $avatar = $r['student_id']
            ? strtoupper(substr($r['first_name'] ?? '', 0, 1) . substr($r['last_name'] ?? '', 0, 1))
            : 'DM';

        $out[] = [
            'id'            => (int)$r['id'],
            'date'          => $dateYmd,
            'start_ts'      => $stDT->format(DateTime::ATOM),
            'end_ts'        => $enDT->format(DateTime::ATOM),
            'time'          => $stDT->format('g:i A') . ' - ' . $enDT->format('g:i A'),
            'title'         => $r['subject_name'],
            'student'       => $studentName,
            'student_id'    => (int)($r['student_id'] ?? 0),
            'status'        => $r['status'],
            'notes'         => $r['tutor_notes'] ?? '',
            'avatar'        => $avatar,
            'profile_photo' => resolve_photo($r['profile_photo_url'] ?? null, trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: 'Demo Lesson'),
            'has_recording' => (bool)$r['has_recording'],
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['success'=>true, 'data'=>$out]);
    exit();
}

/* =======================================
   4) AJAX: Load / Save Availability (weekly)
   ======================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'availability_load') {
    $st = $pdo->prepare("
        SELECT day_of_week, start_time, end_time
        FROM tutor_availability
        WHERE tutor_id = ?
        ORDER BY day_of_week, start_time
    ");
    $st->execute([$tutor_id]);
    $rows = $st->fetchAll();
    header('Content-Type: application/json');
    echo json_encode(['success'=>true, 'data'=>$rows]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'availability_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Expected JSON: { "items": [ {day:1..7, start:"HH:MM", end:"HH:MM"}, ... ] }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Invalid payload']);
        exit();
    }

    // sanitize & compress
    $items = [];
    foreach ($payload['items'] as $it) {
        $d = clampDayOfWeek($it['day'] ?? 1);
        $s = preg_match('/^\d{2}:\d{2}$/', $it['start'] ?? '') ? ($it['start'].':00') : null;
        $e = preg_match('/^\d{2}:\d{2}$/', $it['end'] ?? '')   ? ($it['end']  .':00') : null;
        if (!$s || !$e || $s>=$e) continue;
        $items[] = ['day'=>$d, 'start'=>$s, 'end'=>$e];
    }

    // Merge overlapping intervals per day
    $byDay = [];
    foreach ($items as $it) { $byDay[$it['day']][] = [$it['start'], $it['end']]; }
    foreach ($byDay as $day => &$arr) {
        usort($arr, fn($a,$b)=>strcmp($a[0],$b[0]));
        $merged = [];
        foreach ($arr as $r) {
            if (empty($merged) || $r[0] > $merged[count($merged)-1][1]) {
                $merged[] = $r;
            } else {
                // overlap/contiguous → extend
                if ($r[1] > $merged[count($merged)-1][1]) {
                    $merged[count($merged)-1][1] = $r[1];
                }
            }
        }
        $byDay[$day] = $merged;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tutor_availability WHERE tutor_id = ?")->execute([$tutor_id]);
        $ins = $pdo->prepare("
            INSERT INTO tutor_availability (tutor_id, day_of_week, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($byDay as $day=>$ranges) {
            foreach ($ranges as [$s,$e]) {
                $ins->execute([$tutor_id, $day, $s, $e]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success'=>false, 'message'=>'Save failed']);
        exit();
    }

    header('Content-Type: application/json');
    echo json_encode(['success'=>true]);
    exit();
}

/* ============================================
   5) AJAX: Available Slots (date range)
   ============================================ */
function getDayAvailability($pdo, $tutor_id, $dow1to7) {
    $st = $pdo->prepare("
        SELECT start_time, end_time
        FROM tutor_availability
        WHERE tutor_id = ? AND day_of_week = ?
        ORDER BY start_time
    ");
    $st->execute([$tutor_id, $dow1to7]);
    return $st->fetchAll(); // rows of start_time, end_time (HH:MM:SS)
}
function getLessonsForDate($pdo, $tutor_id, $dateYmd) {
    // Build [start, end) range for the date
    $start = $dateYmd . ' 00:00:00';
    $end   = (new DateTime($start))->modify('+1 day')->format('Y-m-d H:i:s');

    $st = $pdo->prepare("
        SELECT scheduled_start_time, scheduled_end_time
        FROM lessons
        WHERE tutor_id = ?
          AND scheduled_start_time >= ?
          AND scheduled_start_time <  ?
        ORDER BY scheduled_start_time
    ");
    $st->execute([$tutor_id, $start, $end]);
    return $st->fetchAll();
}

function sliceSlots($startDT, $endDT, $slotMinutes) {
    $slots = [];
    $cur = clone $startDT;
    while ($cur < $endDT) {
        $next = (clone $cur)->modify("+{$slotMinutes} minutes");
        if ($next <= $endDT) {
            $slots[] = [$cur, $next];
        }
        $cur = $next;
    }
    return $slots;
}
function subtractBusy($freeIntervals, $busyIntervals) {
    // free/busy are arrays of [DateTime, DateTime]
    $out = [];
    foreach ($freeIntervals as [$fs, $fe]) {
        $parts = [[clone $fs, clone $fe]];
        foreach ($busyIntervals as [$bs, $be]) {
            $newParts = [];
            foreach ($parts as [$ps, $pe]) {
                if (!($ps < $be && $bs < $pe)) { // no overlap
                    $newParts[] = [$ps,$pe];
                    continue;
                }
                // overlap → cut
                if ($bs > $ps) $newParts[] = [$ps, min($bs, $pe)];
                if ($be < $pe) $newParts[] = [max($be, $ps), $pe];
            }
            $parts = array_filter($newParts, fn($p)=>$p[0] < $p[1]);
        }
        foreach ($parts as $p) { $out[] = $p; }
    }
    return $out;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'available_slots_range') {
    $start = DateTime::createFromFormat('Y-m-d', $_GET['start'] ?? '');
    $end   = DateTime::createFromFormat('Y-m-d', $_GET['end'] ?? '');
    $slot_minutes = max(15, (int)($_GET['slot_minutes'] ?? 60));

    if (!$start || !$end) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Invalid range']);
        exit();
    }
    if ($end < $start) { [$start,$end] = [$end,$start]; }

    $cur = clone $start;
    $all = [];
    while ($cur <= $end) {
        // This used to `include __FILE__` here to reuse the single-day handler.
        // Re-entering the file redeclares clampDayOfWeek() and the request dies
        // with a fatal before it ever reaches the code below, which does the
        // same work directly.
        $dow = (int)$cur->format('N');
        $availRows = getDayAvailability($pdo, $tutor_id, $dow);
        $busyRows  = getLessonsForDate($pdo, $tutor_id, $cur->format('Y-m-d'));
        $free = [];
        foreach ($availRows as $r) {
            [$h1,$m1,$s1] = array_map('intval', explode(':', $r['start_time']));
            [$h2,$m2,$s2] = array_map('intval', explode(':', $r['end_time']));
            $s = (clone $cur)->setTime($h1,$m1,$s1);
            $e = (clone $cur)->setTime($h2,$m2,$s2);
            if ($s < $e) $free[] = [$s,$e];
        }
        $busy = [];
        foreach ($busyRows as $r) {
            $s = new DateTime($r['scheduled_start_time']);
            $e = new DateTime($r['scheduled_end_time']);
            if ($s < $e) $busy[] = [$s,$e];
        }
        $net = subtractBusy($free, $busy);
        foreach ($net as [$s,$e]) {
            foreach (sliceSlots($s, $e, $slot_minutes) as [$a,$b]) {
                $all[] = [
                    'date'  => $cur->format('Y-m-d'),
                    'start' => $a->format('Y-m-d H:i:s'),
                    'end'   => $b->format('Y-m-d H:i:s'),
                    'label' => $a->format('M j, g:i A') . ' - ' . $b->format('g:i A'),
                ];
            }
        }
        $cur->modify('+1 day');
    }

    header('Content-Type: application/json');
    echo json_encode(['success'=>true, 'data'=>$all]);
    exit();
}

/* =========================
   6) Tutor meta
   ========================= */
$st = $pdo->prepare("
  SELECT s.id, s.name 
  FROM subjects s
  JOIN tutor_subjects ts ON s.id = ts.subject_id
  WHERE ts.tutor_id = ?
  ORDER BY s.name
");
$st->execute([$tutor_id]);
$tutor_subjects = $st->fetchAll();

$st = $pdo->prepare("
  SELECT DISTINCT u.id, u.first_name, u.last_name, u.profile_photo_url
  FROM users u
  JOIN tutor_student_assignments tsa ON u.id = tsa.student_id
  WHERE tsa.tutor_id = ?
    AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE())
  ORDER BY u.first_name, u.last_name
");
$st->execute([$tutor_id]);
$assigned_students = $st->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
    <title>Tutor Dashboard - Schedule</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <link rel="stylesheet" href="css/theme.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        /* ---- Full-page load skeleton (critical inline: shows before Tailwind loads) ---- */
        #page-skeleton {
            position: fixed; inset: 0; z-index: 100000;
            background: #171717; padding: 20px; overflow: hidden; display: flex; gap: 20px;
        }
        html[data-theme="light"] #page-skeleton { background: #f7f7fb; }
        #page-skeleton.hide { opacity: 0; visibility: hidden; transition: opacity .35s ease, visibility .35s ease; }
        #page-skeleton .sk { background: rgba(148,163,184,0.14); border-radius: 12px; position: relative; overflow: hidden; }
        #page-skeleton .sk::after {
            content: ''; position: absolute; inset: 0; transform: translateX(-100%);
            background: linear-gradient(90deg, transparent 0, rgba(148,163,184,0.22) 50%, transparent 100%);
            animation: sk-shimmer 1.3s infinite;
        }
        @keyframes sk-shimmer { 100% { transform: translateX(100%); } }
        #page-skeleton .ps-side { width: 240px; flex-shrink: 0; display: none; }
        @media (min-width: 1024px){ #page-skeleton .ps-side { display: block; } }
        #page-skeleton .ps-main { flex: 1; display: flex; flex-direction: column; gap: 18px; }

        /* skeleton shimmer for in-page loading (modal, calendar) */
        .skeleton { position: relative; overflow: hidden; background: rgba(148,163,184,0.12); border-radius: 0.5rem; }
        .skeleton::after {
            content: ''; position: absolute; inset: 0; transform: translateX(-100%);
            background: linear-gradient(90deg, transparent 0, rgba(148,163,184,0.18) 50%, transparent 100%);
            animation: sk-shimmer 1.3s infinite;
        }
        .btn-spinner {
            display:inline-block; width:1rem; height:1rem; border:2px solid currentColor;
            border-top-color:transparent; border-radius:9999px; animation:btn-spin .6s linear infinite; vertical-align:-2px;
        }
        @keyframes btn-spin { to { transform: rotate(360deg); } }
        button[data-loading="1"], a[data-loading="1"] { opacity:.75; pointer-events:none; }

        /* ---- Light-mode colour remap for this dark-first page ----
           Scoped to the page content (<main>) and the two modals so it never touches
           the sidebar/header (which theme themselves). Handles every hardcoded dark
           utility at once, then re-asserts white text on coloured buttons. ---- */
        html[data-theme="light"] main .bg-gray-900,
        html[data-theme="light"] #schedule-modal .bg-gray-900,
        html[data-theme="light"] #lesson-detail-modal .bg-gray-900 { background-color:#ffffff !important; }

        html[data-theme="light"] main .bg-gray-800,
        html[data-theme="light"] #schedule-modal .bg-gray-800,
        html[data-theme="light"] #lesson-detail-modal .bg-gray-800 { background-color:#f1f5f9 !important; }

        html[data-theme="light"] main .bg-gray-700,
        html[data-theme="light"] #schedule-modal .bg-gray-700,
        html[data-theme="light"] #lesson-detail-modal .bg-gray-700 { background-color:#ffffff !important; }

        html[data-theme="light"] main .bg-gray-600,
        html[data-theme="light"] #schedule-modal .bg-gray-600 { background-color:#e2e8f0 !important; }

        html[data-theme="light"] main .border-gray-700, html[data-theme="light"] main .border-gray-600,
        html[data-theme="light"] #schedule-modal .border-gray-600,
        html[data-theme="light"] #lesson-detail-modal .border-gray-700 { border-color:#e5e7eb !important; }

        html[data-theme="light"] main .text-white,
        html[data-theme="light"] #schedule-modal .text-white,
        html[data-theme="light"] #lesson-detail-modal .text-white { color:#111827 !important; }

        html[data-theme="light"] main .text-gray-300,
        html[data-theme="light"] #schedule-modal .text-gray-300,
        html[data-theme="light"] #lesson-detail-modal .text-gray-300 { color:#374151 !important; }

        html[data-theme="light"] main .text-gray-400,
        html[data-theme="light"] #schedule-modal .text-gray-400,
        html[data-theme="light"] #lesson-detail-modal .text-gray-400 { color:#6b7280 !important; }

        /* keep white text on coloured buttons (higher specificity wins) */
        html[data-theme="light"] main .bg-indigo-600.text-white,
        html[data-theme="light"] main .bg-indigo-500.text-white,
        html[data-theme="light"] main .bg-emerald-700.text-white,
        html[data-theme="light"] main .bg-emerald-600.text-white,
        html[data-theme="light"] main .bg-emerald-500.text-white,
        html[data-theme="light"] main .bg-green-600.text-white,
        html[data-theme="light"] main .bg-green-500.text-white,
        html[data-theme="light"] #schedule-modal .bg-indigo-600.text-white,
        html[data-theme="light"] #lesson-detail-modal .bg-green-600.text-white,
        html[data-theme="light"] #lesson-detail-modal .bg-indigo-600.text-white { color:#ffffff !important; }
    ::-webkit-scrollbar{ width:8px } ::-webkit-scrollbar-track{ background:#1f2937 } ::-webkit-scrollbar-thumb{ background:#4b5563; border-radius:4px }
    .sidebar-icon{ stroke-width:1.5 }
    .calendar-day{ min-height:130px }
    /* At 375px a 7-column grid gives ~33px-wide cells, so the desktop 130px
       min-height renders them as tall thin slots and the month runs to ~1450px
       of scroll. Matches the student calendar's breakpoint. */
    @media (max-width:640px){ .calendar-day{ min-height:52px; padding:.25rem; border-radius:.5rem } }
    .calendar-day.active{ background:#312e81; border-color:#4f46e5 }
    .lesson-dot{ width:7px; height:7px; border-radius:50% }
    .calendar-grid{ grid-auto-rows:1fr }
    /* Availability grid. Every colour is spelled out for both themes: the old
       rules were dark-only, and in light mode the selected tiles came out at
       1.12:1 (emerald-200 on an emerald tint) which is a blank box. */
    .av-wrap { overflow:auto; border-radius:.75rem; border:1px solid; }
    .theme-light .av-wrap { border-color:#e2e8f0; background:#fff; }
    body:not(.theme-light) .av-wrap { border-color:#374151; background:#111827; }

    .av-grid { display:grid; gap:2px; padding:2px; user-select:none; min-width:340px; }

    .av-head, .av-time {
      font-size:.65rem; font-weight:600; letter-spacing:.02em;
      display:flex; align-items:center; justify-content:center;
    }
    .av-time { justify-content:flex-end; padding-right:.4rem; font-variant-numeric:tabular-nums; }
    .theme-light .av-head, .theme-light .av-time { color:#475569; }
    body:not(.theme-light) .av-head, body:not(.theme-light) .av-time { color:#9ca3af; }

    /* The day header and the hour label are buttons: they toggle a whole column
       or a whole row, which beats clicking sixteen cells. */
    .av-head, .av-time { background:none; border:0; cursor:pointer; }
    .av-head:hover, .av-time:hover { text-decoration:underline; }

    .av-cell {
      height:18px; border-radius:3px; border:1px solid transparent;
      cursor:pointer; transition:background-color .1s ease;
    }
    .theme-light .av-cell { background:#f1f5f9; }
    .theme-light .av-cell:hover { background:#e2e8f0; }
    body:not(.theme-light) .av-cell { background:#1f2937; }
    body:not(.theme-light) .av-cell:hover { background:#374151; }

    .theme-light .av-cell.on { background:#059669; border-color:#047857; }
    body:not(.theme-light) .av-cell.on { background:#10b981; border-color:#34d399; }

    /* Half an hour selected inside a one-hour cell. Without this the hour reads
       as empty and the next save throws the half away. */
    .av-cell.half { background-image:linear-gradient(to bottom, currentColor 50%, transparent 50%); }
    .theme-light .av-cell.half { color:#059669; }
    body:not(.theme-light) .av-cell.half { color:#10b981; }

    .av-cell:focus-visible { outline:2px solid #6366f1; outline-offset:1px; }

    .av-select, .av-btn {
      font-size:.7rem; font-weight:600; border-radius:.5rem;
      padding:.35rem .6rem; border:1px solid; cursor:pointer;
    }
    .theme-light .av-select, .theme-light .av-btn { background:#f8fafc; color:#334155; border-color:#e2e8f0; }
    .theme-light .av-select:hover, .theme-light .av-btn:hover { background:#f1f5f9; }
    body:not(.theme-light) .av-select, body:not(.theme-light) .av-btn { background:rgba(255,255,255,.05); color:#cbd5e1; border-color:rgba(255,255,255,.1); }
    body:not(.theme-light) .av-select:hover, body:not(.theme-light) .av-btn:hover { background:rgba(255,255,255,.1); }

    /* Lessons arrive in a short stagger so a filter change reads as a change,
       rather than the list silently becoming a different list. */
    @keyframes sched-rise { from { opacity:0; transform: translateY(6px) } to { opacity:1; transform:none } }
    .lesson-item { animation: sched-rise .26s ease both; }

    @keyframes sched-spin { to { transform: rotate(360deg) } }
    .sched-spin { animation: sched-spin .8s linear infinite; }

    /* Buttons that fetch something swap their face for a spinner in place, so the
       row does not change size while it waits. */
    .btn-spin { display:none }
    .is-busy .btn-spin { display:inline-block }
    .is-busy .btn-face { display:none }
    .is-busy { opacity:.85; pointer-events:none }

    .video-wrap { position:relative }
    .video-wrap .video-veil {
      position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
      gap:.5rem; background:rgba(2,6,23,.72); border-radius:.5rem; color:#cbd5e1; font-size:.8rem;
    }
    .video-wrap.ready .video-veil { display:none }

    .filter-chip { transition: background-color .15s ease, color .15s ease, border-color .15s ease }

    .rec-badge { display:inline-flex; align-items:center; gap:.25rem }

    @media (prefers-reduced-motion: reduce) {
      .lesson-item { animation: none }
      .sched-spin  { animation-duration: 2.4s }
      .filter-chip { transition: none }
    }
  </style>
</head>
<body class="flex min-h-screen">
  <!-- Page-load skeleton: shows instantly while the tab loads Tailwind/fonts/calendar. -->
  <div id="page-skeleton" aria-hidden="true">
    <div class="ps-side"><div class="sk" style="height:100%"></div></div>
    <div class="ps-main">
      <div class="sk" style="height:56px"></div>
      <div class="sk" style="height:44px; width:60%"></div>
      <div class="sk" style="height:520px"></div>
    </div>
  </div>
  <script>
    (function(){
      var sk = document.getElementById('page-skeleton');
      if (!sk) return;
      function done(){ sk.classList.add('hide'); setTimeout(function(){ sk.remove(); }, 400); }
      if (document.readyState === 'complete') { done(); }
      else { window.addEventListener('load', done); setTimeout(done, 4000); }
    })();
  </script>
  <?php
  // Include centralized page loader component (if not already included by sidebar)
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  ?>

  <!-- Include Sidebar -->
  <?php include 'components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
      <!-- header -->
      <?php
        $header_config = [
            'page_title' => 'My Schedule',
            'page_subtitle' => 'Manage your calendar and availability.'
        ];
        include __DIR__ . '/components/header.php';
      ?>

    <!-- Calendar Navigation & Actions -->
    <section class="flex flex-wrap items-center justify-between gap-4 mb-8">
      <div class="flex items-center gap-4">
        <h2 class="text-2xl font-bold" id="month-year"></h2>
        <div class="flex gap-2">
          <button id="prev-month" class="p-2 bg-gray-700 rounded-lg hover:bg-gray-600">
            <svg class="text-gray-300" width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg>
          </button>
          <button id="next-month" class="p-2 bg-gray-700 rounded-lg hover:bg-gray-600">
            <svg class="text-gray-300" width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg>
          </button>
        </div>
      </div>

      <div class="flex items-center gap-2" role="group" aria-label="Filter lessons">
        <button type="button" data-filter="all" class="filter-chip px-3 py-1.5 rounded-full text-sm border">All</button>
        <button type="button" data-filter="upcoming" class="filter-chip px-3 py-1.5 rounded-full text-sm border">Upcoming</button>
        <button type="button" data-filter="done" class="filter-chip px-3 py-1.5 rounded-full text-sm border">
          Done
          <span id="done-count" class="ml-1 text-xs opacity-70"></span>
        </button>
      </div>
    </section>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 flex-1">
        <!-- Calendar -->
        <div class="lg:col-span-2 card-base p-6 rounded-lg">
          <div class="grid grid-cols-7 gap-2 text-center text-sm text-gray-400 mb-4">
            <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
          </div>
          <div class="grid grid-cols-7 gap-2 calendar-grid" id="calendar-grid"></div>

          <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-4 text-xs text-gray-400">
            <div class="flex items-center gap-2"><span class="lesson-dot inline-block bg-indigo-400"></span> Scheduled</div>
            <div class="flex items-center gap-2"><span class="lesson-dot inline-block bg-emerald-500"></span> In progress</div>
            <div class="flex items-center gap-2"><span class="lesson-dot inline-block bg-emerald-400"></span> Completed</div>
            <div class="flex items-center gap-2"><span class="lesson-dot inline-block bg-amber-400"></span> No Show</div>
            <div class="flex items-center gap-2"><span class="lesson-dot inline-block bg-rose-400"></span> Cancelled</div>
            <div class="flex items-center gap-2 text-gray-400">
              <svg width="12" height="12" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-film"></use></svg>
              Recorded
            </div>
          </div>
        </div>

        <!-- Right: Tabs (Lessons / Availability) -->
        <div class="lg:col-span-1 card-base p-6 rounded-lg flex flex-col">
          <div class="flex gap-2 mb-4">
            <button id="tab-lessons" class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold">Lessons</button>
            <button id="tab-availability" class="px-3 py-2 rounded-lg bg-gray-700 text-white text-sm">Availability</button>
          </div>

          <!-- Lessons Panel -->
          <div id="panel-lessons" class="flex-1 flex flex-col ">
            <h3 class="text-lg font-semibold text-white mb-4" id="lesson-list-header">Select a date</h3>
            <div class="space-y-4 flex-1 overflow-auto" id="lesson-list">
              <div class="text-center text-gray-400 py-10">
                <svg class="mx-auto mb-2 text-gray-500" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                Click on a date to view lessons
              </div>
            </div>
          </div>

          <!-- Availability Panel -->
          <div id="panel-availability" class="hidden flex-1 flex flex-col">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Weekly Availability</h3>
            <p class="text-xs text-slate-600 dark:text-gray-400 mb-3">
              Drag across the grid to paint the hours you can teach. Times are in
              <span class="font-semibold"><?= h($tutor_timezone) ?></span>.
            </p>

            <div class="flex flex-wrap items-center gap-1.5 mb-3">
              <label for="avail-start" class="sr-only">Earliest hour shown</label>
              <select id="avail-start" class="av-select">
                <?php for ($h=0; $h<=23; $h++): $t=sprintf('%02d:00',$h); ?>
                  <option value="<?=$t?>" <?=$h===6?'selected':''?>><?=$t?></option>
                <?php endfor; ?>
              </select>
              <span class="text-xs text-slate-600 dark:text-gray-400">to</span>
              <label for="avail-end" class="sr-only">Latest hour shown</label>
              <select id="avail-end" class="av-select">
                <?php for ($h=1; $h<=24; $h++): $t=sprintf('%02d:00',$h); ?>
                  <option value="<?=$t?>" <?=$h===22?'selected':''?>><?=$t===' 24:00'?'24:00':$t?></option>
                <?php endfor; ?>
              </select>
              <label for="avail-block" class="sr-only">Painting step</label>
              <select id="avail-block" class="av-select">
                <option value="60" selected>1 hour</option>
                <option value="30">30 min</option>
              </select>
              <button type="button" id="avail-weekdays" class="av-btn">Weekdays 9-5</button>
              <button type="button" id="avail-copy-mon" class="av-btn" title="Copy Monday to Tuesday through Friday">Copy Mon</button>
              <button type="button" id="avail-clear" class="av-btn">Clear</button>
            </div>

            <!-- Availability that falls outside the hours on screen is still saved.
                 Say so, because the old editor quietly deleted it. -->
            <p id="avail-outside" class="hidden text-xs mb-2 rounded-lg px-3 py-2 bg-amber-50 text-amber-900 border border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20"></p>

            <div class="av-wrap">
              <div id="avail-grid" class="av-grid"></div>
            </div>

            <p id="avail-summary" class="text-xs text-slate-600 dark:text-gray-400 mt-2"></p>

            <div class="flex items-center gap-3 mt-4">
              <!-- emerald-600 under white text is 3.77:1; emerald-700 clears 4.5 -->
              <button id="save-availability" class="bg-emerald-700 hover:bg-emerald-600 text-white font-semibold py-2 px-4 rounded-lg disabled:opacity-60">
                Save Availability
              </button>
              <span id="avail-status" class="text-xs" role="status" aria-live="polite"></span>
            </div>
          </div>
        </div>
      </div>
  </main>

  <!-- Schedule modal (kept) -->
  <div id="schedule-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 modal-backdrop">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-white">Schedule New Lesson</h3>
        <button id="close-schedule-modal" class="text-gray-400 hover:text-white">
          <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
        </button>
      </div>
      <form id="schedule-form" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-300 mb-1">Student</label>
          <select name="student_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
            <option value="">Select a student</option>
            <?php foreach ($assigned_students as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['first_name'].' '.$s['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-300 mb-1">Subject</label>
          <select name="subject_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
            <option value="">Select a subject</option>
            <?php foreach ($tutor_subjects as $sub): ?>
              <option value="<?php echo (int)$sub['id']; ?>"><?php echo h($sub['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-300 mb-1">Date</label>
          <input type="date" name="lesson_date" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Start Time</label>
            <input type="time" name="start_time" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">End Time</label>
            <input type="time" name="end_time" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
          </div>
        </div>
        <div class="flex gap-3 mt-6">
          <button type="button" id="cancel-schedule" class="flex-1 bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-500">Cancel</button>
          <button type="submit" class="flex-1 bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-500">Schedule Lesson</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Lesson detail modal (kept) -->
  <div id="lesson-detail-modal" class="hidden fixed inset-0  z-50 flex items-center justify-center p-4 modal-backdrop">
    <div class="card-base-sub rounded-lg p-6 w-full max-w-md">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Lesson Details</h3>
        <button id="close-lesson-detail-modal" class="text-gray-400 hover:text-gray-900 dark:hover:text-white">
          <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
        </button>
      </div>
      <div id="lesson-detail-content"></div>
    </div>
  </div>

  <script>
    const tutorName = <?php echo json_encode($tutor_name); ?>;

    /* ========== Calendar (unchanged core) ========== */
    const calendarGrid = document.getElementById('calendar-grid');
    const lessonList = document.getElementById('lesson-list');
    const lessonListHeader = document.getElementById('lesson-list-header');
    const currentMonthYear = document.getElementById('month-year');
    const lessonDetailModal = document.getElementById('lesson-detail-modal');

    let currentDate  = new Date();
    let currentMonth = currentDate.getMonth(); // 0..11
    let currentYear  = currentDate.getFullYear();

    // { 'YYYY-MM': {at: epochMs, rows: [...]} }. Held briefly so paging back and
    // forth is instant, but not for the whole session: a lesson booked or finished
    // elsewhere used to stay invisible here until a full reload.
    const cache = {};
    const CACHE_MS = 60 * 1000;

    // Month data comes over the network, so two quick clicks on prev/next can
    // resolve out of order and leave the calendar showing one month and the list
    // another. Every render takes a ticket; only the newest one is allowed to paint.
    let renderToken = 0;
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function ymd(date){ return date.toISOString().slice(0,10); }
    function todayYmd(){ return ymd(new Date()); }

    // Everything below builds markup with innerHTML, and the values are people's
    // names, subject names, notes and homework titles. A student sets their own
    // name in student/settings.php, where the only cleaning is trim(), so an
    // unescaped name there runs script here, in a tutor's session. Escape at the
    // sink rather than the input: it covers notes and topic names too.
    function esc(v){
      return String(v ?? '').replace(/[&<>"']/g, c => (
        { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
      ));
    }

    // Which lessons the calendar and the list are showing. A finished lesson is
    // any lesson that will not happen again, whatever its outcome: the recording
    // that exists in this database belongs to a lesson marked NoShow, and a tutor
    // looking for it does not care what the outcome was.
    const DONE_STATUSES = ['Completed', 'NoShow', 'CancelledByTutor', 'CancelledByStudent'];
    let filter = 'all';
    let selectedDayYmdActive = null;

    function isDone(lesson){ return DONE_STATUSES.includes(lesson.status); }
    function matchesFilter(lesson){
      if (filter === 'done')     return isDone(lesson);
      if (filter === 'upcoming') return !isDone(lesson);
      return true;
    }

    function paintChips(){
      document.querySelectorAll('.filter-chip').forEach(chip => {
        const on = chip.dataset.filter === filter;
        chip.className = 'filter-chip px-3 py-1.5 rounded-full text-sm border ' + (on
          ? 'bg-indigo-600 border-indigo-500 text-white'
          : 'bg-transparent border-gray-700 text-gray-300 hover:border-gray-500');
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function statusDotColor(status){
      if (!status) return 'bg-gray-400';
      if (status === 'Scheduled') return 'bg-indigo-400';
      if (status === 'Completed') return 'bg-emerald-400';
      if (status === 'NoShow')    return 'bg-amber-400';
      if (status === 'InProgress') return 'bg-emerald-500';
      if (status.startsWith('Cancelled')) return 'bg-rose-400';
      return 'bg-gray-400';
    }

    async function loadMonth(y, m /* 0..11 */){
      const key = `${y}-${String(m+1).padStart(2,'0')}`;
      const hit = cache[key];
      if (hit && (Date.now() - hit.at) < CACHE_MS) return hit.rows;
      const res = await fetch(`<?php echo basename(__FILE__); ?>?ajax=1&year=${y}&month=${m+1}`, { credentials:'same-origin' });
      const json = await res.json();
      if (!json.success) return hit ? hit.rows : [];
      cache[key] = { at: Date.now(), rows: json.data || [] };
      return cache[key].rows;
    }

    async function renderCalendar(){
      const ticket = ++renderToken;
      currentMonthYear.textContent = `${monthNames[currentMonth]} ${currentYear}`;
      // Show a skeleton grid while an uncached month loads.
      const ck = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}`;
      if (!cache[ck]) {   // only skeleton on a month never loaded, not on a refresh
        calendarGrid.innerHTML = Array.from({length: 35}).map(() =>
          '<div class="calendar-day skeleton"></div>').join('');
      }
      const monthLessons = await loadMonth(currentYear, currentMonth);
      if (ticket !== renderToken) return;   // a newer month is already on its way

      const tally = document.getElementById('done-count');
      if (tally) tally.textContent = monthLessons.filter(isDone).length || '';

      calendarGrid.innerHTML = '';
      const firstDayOfMonth = new Date(currentYear, currentMonth, 1);
      const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
      const startOffset = ((firstDayOfMonth.getDay() === 0) ? 6 : firstDayOfMonth.getDay() - 1); // Monday first

      for (let i=0;i<startOffset;i++) calendarGrid.innerHTML += `<div></div>`;

      const today = todayYmd();
      for (let day=1; day<=daysInMonth; day++){
        const dateString = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const dayContainer = document.createElement('div');
        dayContainer.dataset.date = dateString;

        let cls = 'calendar-day border border-gray-700 rounded-lg p-2 flex flex-col cursor-pointer hover:border-indigo-500 transition-colors';
        if (dateString === today) cls += ' border-indigo-600 bg-indigo-600/10';
        dayContainer.className = cls;

        const dayLessons = monthLessons.filter(l => l.date === dateString && matchesFilter(l));

        let dots = '';
        if (dayLessons.length > 0) {
          const maxDots = 4;
          const dotsHtml = dayLessons.slice(0, maxDots).map(l =>
            `<span class="lesson-dot inline-block ${statusDotColor(l.status)}"></span>`
          ).join('');
          const extra = dayLessons.length > maxDots ? `<span class="text-xs text-gray-400 ml-1">+${dayLessons.length - maxDots}</span>` : '';
          // A small film mark so a day holding a recording can be picked out without
          // opening anything.
          const rec = dayLessons.some(l => l.has_recording)
            ? `<svg class="text-gray-400 ml-auto" width="12" height="12" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-film"></use></svg>`
            : '';
          dots = `<div class="flex items-center justify-start gap-1 mt-auto">${dotsHtml}${extra}${rec}</div>`;
        }

        dayContainer.innerHTML = `
          <span class="font-semibold text-white">${day}</span>
          ${dots}
        `;
        dayContainer.addEventListener('click', () => handleDayClick(dayContainer, monthLessons));
        calendarGrid.appendChild(dayContainer);
      }

      // The list is painted from the same data, under the same ticket, so the two
      // panels can never settle on different months.
      renderLessonList(monthLessons);
    }

    function handleDayClick(dayEl, monthLessons){
      const already = selectedDayYmdActive === dayEl.dataset.date;
      calendarGrid.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('active'));

      if (already) {
        // Clicking the selected day again goes back to the whole month, which is
        // how you get from one finished lesson to the rest of them.
        selectedDayYmdActive = null;
        renderLessonList(monthLessons);
        return;
      }

      dayEl.classList.add('active');
      selectedDayYmdActive = dayEl.dataset.date;
      renderLessonList(monthLessons);
    }

    // Two views out of one renderer: a single day when one is selected, the whole
    // month otherwise. The month view is the point of this, it is what makes a
    // finished lesson findable without guessing which square to click.
    function renderLessonList(monthLessons){
      const dayYmd = selectedDayYmdActive;

      let lessons = monthLessons.filter(matchesFilter);
      if (dayYmd) lessons = lessons.filter(l => l.date === dayYmd);
      lessons.sort((a, b) => String(a.start_ts || '').localeCompare(String(b.start_ts || '')));
      // Looking back, the most recent lesson is the one you want first.
      if (filter === 'done' && !dayYmd) lessons.reverse();

      if (dayYmd) {
        const d = new Date(dayYmd + 'T00:00:00');
        const opts = { month:'short', day:'numeric', year:'numeric' };
        lessonListHeader.innerHTML =
          `${esc(d.toLocaleDateString('en-US', opts))}` +
          ` <button type="button" id="clear-day" class="ml-2 text-xs font-normal text-indigo-300 hover:text-indigo-200">Show whole month</button>`;
      } else {
        const what = filter === 'done' ? 'Finished lessons' : filter === 'upcoming' ? 'Upcoming lessons' : 'Lessons';
        lessonListHeader.textContent = `${what} in ${monthNames[currentMonth]} ${currentYear}`;
      }

      lessonList.innerHTML = '';

      if (lessons.length === 0){
        const msg = filter === 'done' ? 'No finished lessons here yet.'
                  : dayYmd            ? 'No lessons on this day.'
                  : filter === 'upcoming' ? 'Nothing coming up this month.'
                  : 'No lessons this month.';
        lessonList.innerHTML = `<div class="text-center text-gray-400 py-10">
          <svg class="mx-auto mb-2 text-gray-500" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
          ${esc(msg)}
        </div>`;
        wireClearDay(monthLessons);
        return;
      }

      lessons.forEach((lesson, idx) => {
        const statusTextClass =
          lesson.status === 'Scheduled' ? 'text-indigo-300' :
          lesson.status === 'InProgress' ? 'text-emerald-400' :
          lesson.status === 'Completed' ? 'text-emerald-300' :
          (lesson.status && lesson.status.startsWith('Cancelled')) ? 'text-rose-300' :
          lesson.status === 'NoShow' ? 'text-amber-300' : 'text-gray-300';

        const avatarSrc = lesson.profile_photo
          ? lesson.profile_photo
          : `../assets/avatar.php?s=32&bg=7c3aed&fg=ffffff&text=${encodeURIComponent(lesson.avatar || '')}`;

        // In the month view each row has to say which day it belongs to.
        const dayLabel = dayYmd ? '' :
          `<span class="text-xs text-gray-500">${esc(new Date(lesson.date + 'T00:00:00')
             .toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' }))}</span>`;

        const watchBtn = lesson.has_recording
          ? `<button type="button" class="watch-btn inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-300 hover:text-emerald-200">
               <span class="btn-face inline-flex items-center gap-1.5">
                 <svg width="14" height="14" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-play-circle"></use></svg>
                 Watch
               </span>
               <svg class="btn-spin sched-spin" width="14" height="14" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
             </button>`
          : '';

        const item = document.createElement('div');
        item.className = 'card-base-sub p-4 rounded-lg lesson-item';
        // A short stagger, capped so a busy month does not crawl in.
        item.style.animationDelay = `${Math.min(idx, 8) * 35}ms`;
        item.innerHTML = `
          <div class="flex items-center justify-between">
            <p class="font-semibold text-white">${esc(lesson.title)}</p>
            <span class="text-xs font-medium ${statusTextClass}">${esc(lesson.status)}</span>
          </div>
          <div class="flex items-center justify-between mt-2">
            <span class="text-xs text-indigo-400">${esc(lesson.time)}</span>
            ${dayLabel}
          </div>
          <div class="flex items-center justify-between mt-3">
            <div class="flex items-center gap-2">
              <img src="${esc(avatarSrc)}" class="rounded-full w-8 h-8 object-cover" alt=""
                   data-fallback="../assets/avatar.php?s=32&bg=7c3aed&fg=ffffff&text=${encodeURIComponent(lesson.avatar || '')}">
              <span class="text-sm text-gray-300">${esc(lesson.student)}</span>
            </div>
            <div class="flex items-center gap-3">
              ${watchBtn}
              <button type="button" class="text-sm font-semibold text-white hover:text-indigo-300 lesson-detail-btn">Details</button>
            </div>
          </div>
        `;
        // Stored photo paths still point at the old /tutsys2/ install for some
        // students, so swap in the initials avatar rather than showing a broken image.
        const pic = item.querySelector('img[data-fallback]');
        if (pic) pic.addEventListener('error', () => {
          if (pic.src !== pic.dataset.fallback) pic.src = pic.dataset.fallback;
        }, { once: true });

        item.querySelector('.lesson-detail-btn').addEventListener('click', () => showLessonDetail(lesson));
        const w = item.querySelector('.watch-btn');
        if (w) w.addEventListener('click', () => showLessonDetail(lesson, { watch: true, trigger: w }));
        lessonList.appendChild(item);
      });

      wireClearDay(monthLessons);
    }

    function wireClearDay(monthLessons){
      const btn = document.getElementById('clear-day');
      if (!btn) return;
      btn.addEventListener('click', () => {
        selectedDayYmdActive = null;
        calendarGrid.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('active'));
        renderLessonList(monthLessons);
      });
    }

    // opts.watch jumps to the recording; opts.trigger is the button that asked,
    // which spins while the details load so the click has an answer straight away.
    async function showLessonDetail(lesson, opts = {}){
      const trigger = opts.trigger || null;
      if (trigger) { trigger.classList.add('is-busy'); trigger.setAttribute('aria-busy', 'true'); }
      try {
        await renderLessonDetail(lesson, opts);
      } finally {
        if (trigger) { trigger.classList.remove('is-busy'); trigger.removeAttribute('aria-busy'); }
      }
    }

    async function renderLessonDetail(lesson, opts = {}){
      const content = document.getElementById('lesson-detail-content');
      // Open the modal immediately with a skeleton, then fill it once details load.
      content.innerHTML =
        '<div class="space-y-3">' +
          '<div class="skeleton" style="height:24px;width:66%"></div>' +
          '<div class="skeleton" style="height:16px;width:100%"></div>' +
          '<div class="skeleton" style="height:16px;width:85%"></div>' +
          '<div class="skeleton" style="height:96px;width:100%;margin-top:12px"></div>' +
          '<div class="skeleton" style="height:36px;width:120px;margin-top:12px"></div>' +
        '</div>';
      document.getElementById('lesson-detail-modal').classList.remove('hidden');

      // A recording is only in the detail payload, so anything holding one needs
      // the deep fetch even if it never left Scheduled.
      let needDeep = lesson.status !== 'Scheduled' || !!lesson.has_recording;
      let details = null;
      if (needDeep) {
        try {
          const res = await fetch(`api/lesson_detail_ajax.php?lesson_id=${encodeURIComponent(lesson.id)}`, { credentials:'same-origin' });
          const json = await res.json();
          if (json.success) details = json;
        } catch(_) {}
      }

      const statusBadgeClass =
        lesson.status === 'Scheduled' ? 'bg-indigo-500/10 text-indigo-300' :
        lesson.status === 'InProgress' ? 'bg-emerald-500/20 text-emerald-200' :
        lesson.status === 'Completed' ? 'bg-emerald-500/10 text-emerald-300' :
        (lesson.status && lesson.status.startsWith('Cancelled')) ? 'bg-rose-500/10 text-rose-300' :
        lesson.status === 'NoShow' ? 'bg-amber-500/10 text-amber-300' : 'bg-gray-600/20 text-gray-300';

      const topicChips = details?.topics?.length
        ? `<div class="mt-2 flex flex-wrap gap-2">
             ${details.topics.map(t => `<span class="px-2 py-1 bg-gray-700 rounded text-xs">${esc(t.name)}</span>`).join('')}
           </div>` : '';

      const subtopicChips = details?.subtopics?.length
        ? `<div class="mt-2 flex flex-wrap gap-2">
             ${details.subtopics.map(st => `<span class="px-2 py-1 bg-gray-700 rounded text-xs">${esc(st.topic_name)} → ${esc(st.name)}</span>`).join('')}
           </div>` : '';

      // Round to whole minutes and a short lesson reads as "~0 min", which is how
      // the four second test recording described itself.
      const recDur  = details?.recording?.duration_seconds;
      const recSize = details?.recording?.file_size_bytes;
      const durLabel  = !recDur  ? '' : (recDur < 60 ? `${recDur}s` : `${Math.round(recDur / 60)} min`);
      const sizeLabel = !recSize ? '' : (recSize < 1048576
        ? `${Math.max(1, Math.round(recSize / 1024))} KB`
        : `${Math.round(recSize / 1048576)} MB`);
      const recMeta = [durLabel, sizeLabel].filter(Boolean).join(' • ');

      const recordingBlock = (details?.recording?.file_url)
        ? `<div class="mt-4" id="recording-block">
             <h5 class="text-sm font-semibold text-white mb-2 flex items-center gap-2">
               <svg width="14" height="14" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-film"></use></svg>
               Recording
             </h5>
             <div class="video-wrap" id="video-wrap">
               <video id="lesson-video" src="${esc(details.recording.file_url)}"
                      controls preload="metadata" playsinline class="w-full rounded-lg bg-black">
                 Your browser does not support the video tag.
               </video>
               <div class="video-veil">
                 <svg class="sched-spin" width="18" height="18" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-loader-2"></use></svg>
                 <span>Loading the recording…</span>
               </div>
             </div>
             <p class="text-xs text-gray-500 mt-1">${esc(recMeta)}</p>
           </div>`
        : (isDone(lesson)
            ? `<div class="mt-4 text-xs text-gray-500">No recording was saved for this lesson.</div>`
            : '');

      const homeworkBlock = (details?.homework?.length)
        ? `<div class="mt-4">
             <h5 class="text-sm font-semibold text-white mb-2">Homework Assigned</h5>
             <ul class="list-disc pl-5 text-sm text-gray-300">
               ${details.homework.map(h => `<li>${esc(h.title)}${h.due_date ? ` (due ${esc(new Date(h.due_date).toLocaleString())})` : ''}</li>`).join('')}
             </ul>
           </div>` : '';

      const notesHtml = (details?.lesson?.notes || lesson.notes)
        ? `<div class="mt-2"><strong class="text-gray-300">Notes:</strong>
             <p class="text-gray-400 text-sm mt-1">${esc(details?.lesson?.notes || lesson.notes)}</p>
           </div>` : '';

      // Joining goes to the LiveKit room, which is where lessons actually happen
      // and what records attendance, the whiteboard and the recording. This used
      // to open tutor_details.google_meet_link, a leftover from the era of
      // era: seven of thirteen tutors have none, which disabled the button
      // external meeting links; only six tutors have one set at all.
      //
      // Same window as the dashboard: while it is running, or from fifteen
      // minutes before the start until the end.
      const startsAt = lesson.start_ts ? new Date(lesson.start_ts) : null;
      const endsAt   = lesson.end_ts   ? new Date(lesson.end_ts)   : null;
      const now      = new Date();
      const inWindow = startsAt && endsAt
        && now >= new Date(startsAt.getTime() - 15 * 60 * 1000)
        && now < endsAt;
      const canJoin = lesson.status === 'InProgress' || (lesson.status === 'Scheduled' && inWindow);
      const joinHref = `join_lesson_live.php?lesson_id=${encodeURIComponent(lesson.id)}`;

      content.innerHTML = `
        <div class="space-y-4 lesson-detail-content">
          <div class="flex items-center justify-between">
            <h4 class="text-lg font-semibold text-white">${esc(lesson.title)}</h4>
            <span class="px-2 py-1 rounded text-xs font-medium ${statusBadgeClass}">${esc(lesson.status)}</span>
          </div>
          <div class="text-sm text-gray-300">
            <p><strong>Student:</strong> ${esc(lesson.student)}</p>
            <p><strong>Date:</strong> ${esc(new Date(lesson.date).toLocaleDateString())}</p>
            <p><strong>Time:</strong> ${esc(lesson.time)}</p>
          </div>
          ${notesHtml}
          ${(topicChips || subtopicChips) ? `<div class="pt-2">${topicChips}${subtopicChips}</div>` : ''}
          ${recordingBlock}
          ${homeworkBlock}
          <div class="flex flex-wrap gap-2 pt-2">
            ${canJoin
                ? `<a href="${joinHref}" target="_blank" rel="noopener" id="join-btn"
                      class="inline-block bg-green-600 hover:bg-green-500 text-white px-4 py-2 rounded-lg text-sm">
                     ${lesson.status === 'InProgress' ? 'Return to Lesson' : 'Join Lesson'}
                   </a>`
                : isDone(lesson)
                  // A finished lesson has nothing to join. Offering a dead button
                  // on a lesson from last October is just noise.
                  ? ''
                  : `<button class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm" disabled>Join (opens 15 minutes before the start)</button>`}
          </div>
        </div>
      `;

      // No POST to api/tutor_join.php here any more: the room mints the tutor's
      // token through tutor/api/livekit_token.php, which stamps tutor_join_time
      // itself. Recording a join for a tutor who then went somewhere else was how
      // attendance and the lesson record drifted apart.

      // The recording is fetched through api/recording_url.php, which checks the
      // viewer is a party to the lesson and then redirects to a link that expires
      // in an hour. That round trip is why the veil sits over the player until the
      // browser says it can actually play something.
      const wrap  = document.getElementById('video-wrap');
      const video = document.getElementById('lesson-video');
      if (wrap && video) {
        const reveal = () => wrap.classList.add('ready');
        video.addEventListener('loadeddata', reveal);
        video.addEventListener('canplay', reveal);
        video.addEventListener('error', async () => {
          wrap.classList.add('ready');
          const p = wrap.nextElementSibling;
          if (!p) return;
          p.className = 'text-xs text-amber-300 mt-1';
          p.textContent = 'That recording could not be played.';
          // api/recording_url.php explains itself in plain words when it refuses,
          // and "recorded on the old system, never migrated" is a very different
          // problem from an expired link. Ask it rather than guessing.
          try {
            const probe = await fetch(video.getAttribute('src'), { credentials: 'same-origin' });
            if (!probe.ok) {
              const why = (await probe.text()).trim();
              if (why && why.length < 200) p.textContent = why;
            }
          } catch (_) { /* leave the generic line */ }
        });
        // Metadata alone is enough on some browsers and no further event arrives.
        if (video.readyState >= 2) reveal();

        // Whatever happens, the veil comes off. A spinner that never stops is a
        // worse answer than an honest one.
        setTimeout(() => {
          if (wrap.classList.contains('ready')) return;
          wrap.classList.add('ready');
          const p = wrap.nextElementSibling;
          if (p && !p.textContent.includes('could not')) {
            p.textContent = 'This recording is taking a long time to load. Press play to try it anyway.';
            p.className = 'text-xs text-gray-400 mt-1';
          }
        }, 15000);

        if (opts.watch) {
          wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
          // Allowed to fail: the await above breaks the user-gesture chain in some
          // browsers, and then the viewer simply presses play themselves.
          video.play().catch(() => {});
        }
      }
    }

    // Modal handlers
    document.getElementById('close-lesson-detail-modal').addEventListener('click', () => lessonDetailModal.classList.add('hidden'));
    window.addEventListener('click', (e) => {
      if (e.target === lessonDetailModal) lessonDetailModal.classList.add('hidden');
    });

    // Changing month drops the selected day, so the list falls back to the whole
    // month rather than showing a day from a month you are no longer looking at.
    // Paging through months used to fire one query per click, so holding the arrow
    // down queued half a dozen requests and the calendar sat on a skeleton until the
    // last one came back. The heading moves immediately; only the month you land on
    // is fetched.
    let navTimer = null;
    function goToMonth(delta){
      currentMonth += delta;
      if (currentMonth < 0)  { currentMonth = 11; currentYear--; }
      if (currentMonth > 11) { currentMonth = 0;  currentYear++; }
      selectedDayYmdActive = null;
      currentMonthYear.textContent = `${monthNames[currentMonth]} ${currentYear}`;
      clearTimeout(navTimer);
      navTimer = setTimeout(renderCalendar, 220);
    }
    document.getElementById('prev-month').addEventListener('click', () => goToMonth(-1));
    document.getElementById('next-month').addEventListener('click', () => goToMonth(1));

    document.querySelectorAll('.filter-chip').forEach(chip => {
      chip.addEventListener('click', async () => {
        filter = chip.dataset.filter;
        paintChips();
        await renderCalendar();
      });
    });

    // Initial render
    (async () => {
      paintChips();
      await renderCalendar();
    })();

    // Auto-refresh the calendar when the tab regains focus (new bookings/cancellations
    // made elsewhere show up) — but never while a modal is open, so it can't disrupt
    // a booking in progress.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        const detailOpen = !document.getElementById('lesson-detail-modal').classList.contains('hidden');
        const schedOpen  = !document.getElementById('schedule-modal').classList.contains('hidden');
        if (detailOpen || schedOpen) return;
        const ck = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}`;
        delete cache[ck];
        renderCalendar();
    });

    /* ========== Availability UI ========== */
    const tabLessons = document.getElementById('tab-lessons');
    const tabAvail   = document.getElementById('tab-availability');
    const panelLessons = document.getElementById('panel-lessons');
    const panelAvail   = document.getElementById('panel-availability');
    const availGrid    = document.getElementById('avail-grid');
    const availStartSel= document.getElementById('avail-start');
    const availEndSel  = document.getElementById('avail-end');
    const availBlockSel= document.getElementById('avail-block');
    const saveBtn      = document.getElementById('save-availability');

    tabLessons.addEventListener('click', () => {
      tabLessons.className = "px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold";
      tabAvail.className   = "px-3 py-2 rounded-lg bg-slate-200 text-slate-800 dark:bg-gray-700 dark:text-white text-sm";
      panelLessons.classList.remove('hidden');
      panelAvail.classList.add('hidden');
    });
    let availLoaded = false;
    tabAvail.addEventListener('click', async () => {
      tabAvail.className   = "px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold";
      tabLessons.className = "px-3 py-2 rounded-lg bg-slate-200 text-slate-800 dark:bg-gray-700 dark:text-white text-sm";
      panelAvail.classList.remove('hidden');
      panelLessons.classList.add('hidden');
      // Load once. Rebuilding on every tab click discarded unsaved painting.
      if (!availLoaded) { availLoaded = true; await reloadAvailability(); }
    });

    const DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    // day_of_week is ISO: 1 = Monday .. 7 = Sunday. Keep it that way; the catch-up
    // page writes the same column and used to use 0..6, which is why the table
    // held eight distinct day numbers for a seven day week.
    const SLOTS_PER_DAY = 48;              // half hours, 00:00 .. 24:00
    const availState = {};                 // {1..7: Set(slot index)}
    for (let d = 1; d <= 7; d++) availState[d] = new Set();

    const statusEl  = document.getElementById('avail-status');
    const summaryEl = document.getElementById('avail-summary');
    const outsideEl = document.getElementById('avail-outside');

    const pad2 = n => String(n).padStart(2, '0');
    const slotToHHMM = i => `${pad2(Math.floor(i / 2))}:${pad2((i % 2) * 30)}`;
    const timeToSlot = t => {
      const [H, M] = t.split(':').map(Number);
      return H * 2 + (M >= 30 ? 1 : 0);
    };

    async function loadAvailability(){
      const res = await fetch(`<?php echo basename(__FILE__); ?>?ajax=availability_load`, { credentials:'same-origin' });
      const json = await res.json();
      if (!json.success) return [];
      return json.data || [];
    }

    // Every half hour of every day lives in memory whether or not it is on screen.
    // The old editor only knew about the tiles it had drawn, so saving wiped
    // anything outside the visible hours or finer than the block size.
    let strayRows = 0;

    function rowsIntoState(rows){
      for (let d = 1; d <= 7; d++) availState[d].clear();
      strayRows = 0;
      rows.forEach(r => {
        const d = parseInt(r.day_of_week, 10);
        // day_of_week 0 can only have come from the catch-up page's old 0..6
        // encoding. Say so rather than dropping it silently on the next save.
        if (!availState[d]) { strayRows++; return; }
        const si = timeToSlot(String(r.start_time).slice(0,5));
        let ei = timeToSlot(String(r.end_time).slice(0,5));
        // 00:00 as an end time means midnight at the end of the day.
        if (ei <= si && String(r.end_time).slice(0,5) === '00:00') ei = SLOTS_PER_DAY;
        for (let i = si; i < ei && i < SLOTS_PER_DAY; i++) availState[d].add(i);
      });
    }

    function stateIntoItems(){
      const items = [];
      for (let d = 1; d <= 7; d++) {
        const arr = [...availState[d]].sort((a,b) => a - b);
        let i = 0;
        while (i < arr.length) {
          let j = i;
          while (j + 1 < arr.length && arr[j+1] === arr[j] + 1) j++;
          items.push({
            day: d,
            start: slotToHHMM(arr[i]),
            // A run reaching the last slot ends at 24:00, which the column stores
            // as 00:00; clamp to 23:59 so start < end still holds server-side.
            end: arr[j] + 1 >= SLOTS_PER_DAY ? '23:59' : slotToHHMM(arr[j] + 1),
          });
          i = j + 1;
        }
      }
      return items;
    }

    function viewBounds(){
      const startSlot = timeToSlot(availStartSel.value);
      let endSlot = timeToSlot(availEndSel.value);
      if (availEndSel.value === '24:00') endSlot = SLOTS_PER_DAY;
      return { startSlot, endSlot: Math.max(startSlot + 2, endSlot) };
    }

    function updateSummary(){
      let halves = 0, days = 0;
      for (let d = 1; d <= 7; d++) {
        if (availState[d].size) { days++; halves += availState[d].size; }
      }
      const h = Math.floor(halves / 2), m = (halves % 2) * 30;
      summaryEl.textContent = halves === 0
        ? 'Nothing set yet. Students cannot be offered any slot until you paint some hours.'
        : `${h}h${m ? ' ' + m + 'm' : ''} across ${days} day${days === 1 ? '' : 's'}.`;

      // Warn rather than silently discard, which is what used to happen.
      const { startSlot, endSlot } = viewBounds();
      let hidden = 0;
      for (let d = 1; d <= 7; d++) {
        availState[d].forEach(i => { if (i < startSlot || i >= endSlot) hidden++; });
      }
      const notes = [];
      if (hidden) {
        notes.push(`${hidden} half-hour block${hidden === 1 ? '' : 's'} fall outside the hours shown. `
          + `They are kept when you save; widen the range above to edit them.`);
      }
      if (strayRows) {
        notes.push(`${strayRows} saved row${strayRows === 1 ? ' uses' : 's use'} an old day numbering and cannot be shown. `
          + `Saving here will drop ${strayRows === 1 ? 'it' : 'them'}, so re-paint those hours first.`);
      }
      outsideEl.classList.toggle('hidden', notes.length === 0);
      outsideEl.textContent = notes.join(' ');
    }

    let paint = { on:false, val:false };
    window.addEventListener('mouseup', () => { paint.on = false; });

    function cellSlots(rowSlot, step){
      return step === 2 ? [rowSlot, rowSlot + 1] : [rowSlot];
    }

    function paintCell(d, rowSlot, step, val){
      cellSlots(rowSlot, step).forEach(i => {
        if (i < 0 || i >= SLOTS_PER_DAY) return;
        if (val) availState[d].add(i); else availState[d].delete(i);
      });
    }

    function paintCellEl(el){
      const d = +el.dataset.day, rowSlot = +el.dataset.slot, step = +el.dataset.step;
      const on = cellSlots(rowSlot, step).every(i => availState[d].has(i));
      const some = cellSlots(rowSlot, step).some(i => availState[d].has(i));
      el.classList.toggle('on', on);
      el.classList.toggle('half', !on && some);
      el.setAttribute('aria-pressed', String(on));
    }

    function repaintAll(){
      availGrid.querySelectorAll('.av-cell').forEach(paintCellEl);
      updateSummary();
    }

    function buildAvailabilityGrid(){
      const step = parseInt(availBlockSel.value, 10) === 30 ? 1 : 2;
      const { startSlot, endSlot } = viewBounds();

      availGrid.innerHTML = '';
      availGrid.style.gridTemplateColumns = `3.2rem repeat(7, minmax(0, 1fr))`;

      const corner = document.createElement('div');
      availGrid.appendChild(corner);

      DAYS.forEach((label, idx) => {
        const d = idx + 1;
        const th = document.createElement('button');
        th.type = 'button';
        th.className = 'av-head';
        th.textContent = label;
        th.title = `Toggle all of ${label}`;
        th.addEventListener('click', () => {
          const anyOn = [...availState[d]].some(i => i >= startSlot && i < endSlot);
          for (let i = startSlot; i < endSlot; i++) {
            if (anyOn) availState[d].delete(i); else availState[d].add(i);
          }
          repaintAll();
        });
        availGrid.appendChild(th);
      });

      for (let rowSlot = startSlot; rowSlot < endSlot; rowSlot += step) {
        const time = document.createElement('button');
        time.type = 'button';
        time.className = 'av-time';
        time.textContent = slotToHHMM(rowSlot);
        time.title = 'Toggle this time across the week';
        time.addEventListener('click', () => {
          const anyOn = [1,2,3,4,5,6,7].some(d => cellSlots(rowSlot, step).some(i => availState[d].has(i)));
          [1,2,3,4,5,6,7].forEach(d => paintCell(d, rowSlot, step, !anyOn));
          repaintAll();
        });
        availGrid.appendChild(time);

        for (let d = 1; d <= 7; d++) {
          const cell = document.createElement('button');
          cell.type = 'button';
          cell.className = 'av-cell';
          cell.dataset.day = d;
          cell.dataset.slot = rowSlot;
          cell.dataset.step = step;
          cell.setAttribute('aria-label', `${DAYS[d-1]} ${slotToHHMM(rowSlot)}`);

          cell.addEventListener('mousedown', e => {
            e.preventDefault();
            const on = cellSlots(rowSlot, step).every(i => availState[d].has(i));
            paint = { on:true, val:!on };
            paintCell(d, rowSlot, step, paint.val);
            repaintAll();
          });
          cell.addEventListener('mouseenter', () => {
            if (!paint.on) return;
            paintCell(d, rowSlot, step, paint.val);
            repaintAll();
          });
          // Keyboard users get the same toggle without the drag.
          cell.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            const on = cellSlots(rowSlot, step).every(i => availState[d].has(i));
            paintCell(d, rowSlot, step, !on);
            repaintAll();
          });

          availGrid.appendChild(cell);
        }
      }
      repaintAll();
    }

    async function reloadAvailability(){
      rowsIntoState(await loadAvailability());
      buildAvailabilityGrid();
    }

    availStartSel.addEventListener('change', () => {
      // Keep the range sane rather than rendering nothing.
      if (timeToSlot(availEndSel.value) <= timeToSlot(availStartSel.value)) {
        const next = Math.min(23, Math.floor(timeToSlot(availStartSel.value) / 2) + 1);
        availEndSel.value = `${pad2(next)}:00`;
      }
      buildAvailabilityGrid();
    });
    availEndSel.addEventListener('change', buildAvailabilityGrid);
    availBlockSel.addEventListener('change', buildAvailabilityGrid);

    document.getElementById('avail-clear').addEventListener('click', () => {
      for (let d = 1; d <= 7; d++) availState[d].clear();
      repaintAll();
    });

    document.getElementById('avail-weekdays').addEventListener('click', () => {
      for (let d = 1; d <= 5; d++) {
        availState[d].clear();
        for (let i = timeToSlot('09:00'); i < timeToSlot('17:00'); i++) availState[d].add(i);
      }
      repaintAll();
    });

    document.getElementById('avail-copy-mon').addEventListener('click', () => {
      for (let d = 2; d <= 5; d++) availState[d] = new Set(availState[1]);
      repaintAll();
    });

    saveBtn.addEventListener('click', async () => {
      saveBtn.disabled = true;
      statusEl.textContent = 'Saving…';
      statusEl.className = 'text-xs text-slate-600 dark:text-gray-400';
      try {
        const res = await fetch(`<?php echo basename(__FILE__); ?>?ajax=availability_save`, {
          method:'POST',
          credentials:'same-origin',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ items: stateIntoItems() })
        });
        const json = await res.json();
        if (json.success) {
          statusEl.textContent = 'Saved.';
          statusEl.className = 'text-xs text-emerald-700 dark:text-emerald-400';
          await reloadAvailability();
        } else {
          statusEl.textContent = json.message || 'Could not save.';
          statusEl.className = 'text-xs text-red-700 dark:text-red-400';
        }
      } catch (e) {
        statusEl.textContent = 'Could not reach the server.';
        statusEl.className = 'text-xs text-red-700 dark:text-red-400';
      } finally {
        saveBtn.disabled = false;
        setTimeout(() => { statusEl.textContent = ''; }, 4000);
      }
    });

    // Default to Lessons tab
    tabLessons.click();
  </script>
</body>
</html>
