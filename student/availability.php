<?php
/**
 * Student Catch‑up & Availability (single file)
 * Route: students/catchup_student.php
 * PHP 7.2+ compatible. Dark glass UI.
 *
 * Prereqs (from previous migration answer):
 * - Tables: student_availability, student_availability_rules,
 *           catchup_requests, catchup_proposals (and enum additions on notifications)
 * - Optional lessons.catchup_of_lesson_id INT NULL
 *
 * What this page does:
 * 1) Shows the student's open/active catch‑up requests.
 * 2) For each request, shows current time proposals (with expiry).
 * 3) One‑click Accept for a proposal (server re‑validates, then creates a lesson).
 * 4) If no good options, the student can paint their availability right here.
 * 5) Inline Availability editor (Specific day + General weekly), reusing same API as before.
 *
 * Endpoints (same file):
 *  GET  ?api=list_requests
 *  GET  ?api=list_proposals&request_id=…
 *  POST ?api=accept_proposal (proposal_id)
 *  POST ?api=ask_more (request_id)  → optional hook for admin/service to generate more proposals
 *  GET  ?api=list_slots
 *  POST ?api=create_slots_for_date
 *  GET  ?api=list_rules
 *  POST ?api=save_rules
 *  POST ?api=delete_rule
 */

// ──────────────────────────────────────────────────────────────────────
// 1) Auth
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
  header('Location: ../login.php');
  exit();
}
$student_id = (int)$_SESSION['user_id'];
$current_page = 'availability';

require_once __DIR__ . '/../utils/timezone_helper.php';
// getUserTimezone() reads $_SESSION['timezone'], which authenticate.php
// populates at login and settings.php keeps current, and validates it.
// This used to read $_SESSION['user_timezone'] - a key nothing ever sets,
// so it always fell through to the hardcoded fallback below it.
$user_tz_id = getUserTimezone();

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 3) Helpers
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_out($ok, $data = [], $code = 200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode(['ok'=>$ok]+$data); exit(); }
function toUtcFromLocal($dateYmd, $timeHm, $tz){ try{$dt=new DateTime($dateYmd.' '.$timeHm,new DateTimeZone($tz));$dt->setTimezone(new DateTimeZone('UTC'));return $dt->format('Y-m-d H:i:s');}catch(Exception $e){return null;} }
function utcToIsoLocal($mysqlDt, $tz){ $dt=new DateTime($mysqlDt, new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone($tz)); return $dt->format(DateTime::ATOM); }

// 4) CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

// Everything below only READS the session, and PHP holds an exclusive lock on the
// session file for the whole request. This page fires one AJAX call per catch-up
// request, so with 22 open requests they all queued behind each other and the
// availability editor did not appear for 22 seconds. Releasing the lock here lets
// them actually run at the same time. Nothing after this point writes $_SESSION.
session_write_close();

// 5) API router
if (isset($_GET['api'])){
  $api = $_GET['api'];

  // Catch‑up requests for this student (only active ones by default)
  if ($api === 'list_requests'){
    $stmt = $pdo->prepare("SELECT cr.id, cr.original_lesson_id, cr.tutor_id, u.first_name AS tutor_first_name, u.last_name AS tutor_last_name, cr.reason, cr.status, cr.created_at
                           FROM catchup_requests cr
                           JOIN users u ON u.id = cr.tutor_id
                           WHERE cr.student_id = ? AND cr.status IN ('Open','Proposed')
                           ORDER BY cr.created_at DESC");
    $stmt->execute([$student_id]);
    json_out(true, ['requests'=>$stmt->fetchAll()]);
  }

  if ($api === 'list_proposals'){
    $rid = (int)($_GET['request_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, request_id, start_time_utc, end_time_utc, status, expires_at
                           FROM catchup_proposals
                           WHERE request_id = ? AND status IN ('Proposed')
                           ORDER BY start_time_utc ASC");
    $stmt->execute([$rid]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r){
      $out[] = [
        'id' => (int)$r['id'],
        'request_id' => (int)$r['request_id'],
        'start_iso' => utcToIsoLocal($r['start_time_utc'], $user_tz_id),
        'end_iso'   => utcToIsoLocal($r['end_time_utc'],   $user_tz_id),
        'status'    => $r['status'],
        'expires_iso'=> utcToIsoLocal($r['expires_at'], $user_tz_id)
      ];
    }
    json_out(true, ['proposals'=>$out]);
  }

  // Student accepts a proposal → server validates and (normally) creates a lesson.
  if ($api === 'accept_proposal' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf'] ?? '') !== $csrf) json_out(false, ['error'=>'Bad token'], 403);
    $proposal_id = (int)($_POST['proposal_id'] ?? 0);

    // Load proposal with request
    $stmt = $pdo->prepare("SELECT cp.*, cr.student_id, cr.tutor_id, cr.original_lesson_id
                           FROM catchup_proposals cp
                           JOIN catchup_requests cr ON cr.id = cp.request_id
                           WHERE cp.id = ? AND cr.student_id = ? LIMIT 1");
    $stmt->execute([$proposal_id, $student_id]);
    $row = $stmt->fetch();
    if (!$row) json_out(false, ['error'=>'Proposal not found'], 404);
    if ($row['status'] !== 'Proposed') json_out(false, ['error'=>'Already handled'], 409);

    // Expired?
    $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    if ($row['expires_at'] < $nowUtc) json_out(false, ['error'=>'This option has expired'], 409);

    $slotStart = $row['start_time_utc'];
    $slotEnd   = $row['end_time_utc'];
    $tutor_id  = (int)$row['tutor_id'];

    // Collision checks with lessons for both parties
    $chk = $pdo->prepare("SELECT 1 FROM lessons l WHERE l.tutor_id=? AND NOT (l.scheduled_end_time <= ? OR l.scheduled_start_time >= ?) LIMIT 1");
    $chk->execute([$tutor_id, $slotStart, $slotEnd]);
    if ($chk->fetchColumn()) json_out(false, ['error'=>'Tutor now busy at this time'], 409);

    $chk = $pdo->prepare("SELECT 1 FROM lessons l WHERE l.student_id=? AND NOT (l.scheduled_end_time <= ? OR l.scheduled_start_time >= ?) LIMIT 1");
    $chk->execute([$student_id, $slotStart, $slotEnd]);
    if ($chk->fetchColumn()) json_out(false, ['error'=>'You are busy at this time'], 409);

    // Create a new lesson (re‑use original subject & package if possible)
    $orig = $pdo->prepare("SELECT subject_id, student_package_id FROM lessons WHERE id=? LIMIT 1");
    $orig->execute([(int)$row['original_lesson_id']]);
    $origL = $orig->fetch();
    if (!$origL) json_out(false, ['error'=>'Original lesson missing'], 409);

    $pdo->beginTransaction();
    try{
      // scheduled_*_utc are NOT NULL with no default and production runs
      // STRICT_TRANS_TABLES, so omitting them made this insert fail outright.
      // The values below are already UTC.
      $ins = $pdo->prepare("INSERT INTO lessons (student_package_id, tutor_id, student_id, subject_id, meeting_url, scheduled_start_time, scheduled_end_time, scheduled_start_time_utc, scheduled_end_time_utc, status, created_at, catchup_of_lesson_id)
                            VALUES (?,?,?,?,NULL,?,?,?,?, 'Scheduled', CURRENT_TIMESTAMP(), ?)");
      $ins->execute([
        $origL['student_package_id'],
        $tutor_id,
        $student_id,
        $origL['subject_id'],
        $slotStart,
        $slotEnd,
        $slotStart,
        $slotEnd,
        (int)$row['original_lesson_id']
      ]);

      // Mark proposal & request
      $pdo->prepare("UPDATE catchup_proposals SET status='Accepted' WHERE id=?")->execute([$proposal_id]);
      $pdo->prepare("UPDATE catchup_requests  SET status='Scheduled' WHERE id=?")->execute([(int)$row['request_id']]);

      // Optional: notifications (student/tutor/admin)
      // $pdo->prepare("INSERT INTO notifications (user_id, type, message, related_entity_id) VALUES (?,?,?,?)")
      //     ->execute([$student_id,'CatchupAccepted','Your catch‑up has been scheduled.', $proposal_id]);

      $pdo->commit();
      json_out(true, ['scheduled'=>true]);
    } catch(Exception $e){
      $pdo->rollBack();
      json_out(false, ['error'=>'Could not schedule'], 500);
    }
  }

  if ($api === 'ask_more' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf'] ?? '') !== $csrf) json_out(false, ['error'=>'Bad token'], 403);
    $rid = (int)($_POST['request_id'] ?? 0);
    // You may enqueue a job or ping admin; here we only flip request to Open to signal the generator.
    $pdo->prepare("UPDATE catchup_requests SET status='Open' WHERE id=? AND student_id=?")->execute([$rid, $student_id]);
    // Optionally delete expired/declined proposals for this request
    $pdo->prepare("DELETE FROM catchup_proposals WHERE request_id=? AND status IN ('Declined','Expired')")->execute([$rid]);
    json_out(true, ['ok'=>true]);
  }

  // Availability APIs (same as earlier page)
  if ($api === 'list_slots'){
    $stmt=$pdo->prepare("SELECT id,start_time_utc,end_time_utc,note FROM student_availability WHERE student_id=? AND start_time_utc BETWEEN DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY) AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 DAY) ORDER BY start_time_utc ASC");
    $stmt->execute([$student_id]);
    $rows=$stmt->fetchAll(); $slots=[]; foreach($rows as $r){ $slots[]=['id'=>(int)$r['id'],'start_iso'=>utcToIsoLocal($r['start_time_utc'],$user_tz_id),'end_iso'=>utcToIsoLocal($r['end_time_utc'],$user_tz_id),'note'=>h($r['note']??'')]; }
    json_out(true,['slots'=>$slots]);
  }

  if ($api === 'create_slots_for_date' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf'] ?? '') !== $csrf) json_out(false, ['error'=>'Bad token'], 403);
    $date = trim($_POST['date'] ?? '');
    $ranges = json_decode($_POST['ranges'] ?? '[]', true);
    $note = trim($_POST['note'] ?? '');
    if (!$date || !is_array($ranges) || !count($ranges)) json_out(false,['error'=>'No blocks selected'],422);

    $ins=$pdo->prepare("INSERT INTO student_availability (student_id,start_time_utc,end_time_utc,note) VALUES (?,?,?,?)");
    $over=$pdo->prepare("SELECT 1 FROM student_availability WHERE student_id=? AND NOT (end_time_utc<=? OR start_time_utc>=?) LIMIT 1");
    $created=0; foreach($ranges as $r){ $s=$r['start']??''; $e=$r['end']??''; $sUtc=toUtcFromLocal($date,$s,$user_tz_id); $eUtc=toUtcFromLocal($date,$e,$user_tz_id); if(!$sUtc||!$eUtc||$eUtc<=$sUtc) continue; $over->execute([$student_id,$sUtc,$eUtc]); if($over->fetchColumn()) continue; $ins->execute([$student_id,$sUtc,$eUtc,$note]); $created++; }
    json_out(true,['created'=>$created]);
  }

  if ($api === 'list_rules'){
    $stmt=$pdo->prepare("SELECT id,weekday,start_local,end_local,note FROM student_availability_rules WHERE student_id=? ORDER BY weekday,start_local");
    $stmt->execute([$student_id]);
    json_out(true,['rules'=>$stmt->fetchAll()]);
  }

  if ($api === 'save_rules' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf'] ?? '') !== $csrf) json_out(false, ['error'=>'Bad token'], 403);
    $payload = json_decode($_POST['rules'] ?? '[]', true);
    if (!is_array($payload)) json_out(false,['error'=>'Bad payload'],422);
    $pdo->beginTransaction();
    try{
      $pdo->prepare("DELETE FROM student_availability_rules WHERE student_id=?")->execute([$student_id]);
      $ins=$pdo->prepare("INSERT INTO student_availability_rules (student_id,weekday,start_local,end_local,note) VALUES (?,?,?,?,?)");
      foreach($payload as $r){ $w=(int)($r['weekday']??-1); $s=$r['start']??''; $e=$r['end']??''; $n=trim($r['note']??''); if($w<0||$w>6||!$s||!$e||$e<=$s) continue; $ins->execute([$student_id,$w,$s,$e,$n]); }
      $pdo->commit(); json_out(true,['saved'=>true]);
    } catch(Exception $e){ $pdo->rollBack(); json_out(false,['error'=>'Save failed'],500); }
  }

  if ($api === 'delete_rule' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf'] ?? '') !== $csrf) json_out(false, ['error'=>'Bad token'], 403);
    $id=(int)($_POST['id'] ?? 0); if($id<=0) json_out(false,['error'=>'Invalid id'],422);
    $pdo->prepare("DELETE FROM student_availability_rules WHERE id=? AND student_id=?")->execute([$id,$student_id]);
    json_out(true,['deleted'=>true]);
  }

  json_out(false,['error'=>'Unknown endpoint'],404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catch‑ups & Availability</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
</head>
<body class="min-h-screen flex">
  <?php include __DIR__ . '/components/sidebar.php'; ?>
  <main class="flex-1 p-6 lg:p-10">
    <?php
      $header_config = [
          'page_title' => 'Catch‑ups & Availability',
          'page_subtitle' => 'Timezone: ' . htmlspecialchars($user_tz_id)
      ];
      include __DIR__ . '/components/header.php';
    ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <!-- Left: Catch-up requests/proposals -->
      <section class="xl:col-span-2 p-5 rounded-2xl glass">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Your Catch‑ups</h2>
          <button id="btn-refresh" class="btn-clear flex items-center gap-1">
            <svg class="w-4 h-4" width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-refresh-cw"></use></svg>
            Refresh
          </button>
        </div>
        <div id="requests" class="space-y-4"></div>
        <div id="empty-requests" class="hidden text-sm text-gray-500 dark:text-gray-400">No active catch‑ups right now.</div>
      </section>

      <!-- Right: Availability editor (compact) -->
      <aside class="p-5 rounded-2xl glass">
        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">Working window: 08:00 – 22:00 (local time).</p>
        <div class="tab-group mb-6">
          <button id="tab-general" class="tab active">
            <svg class="icon" width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar-days"></use></svg>
            Weekly Pattern
          </button>
          <button id="tab-specific" class="tab">
            <svg class="icon" width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
            Specific Date
          </button>
        </div>

        <!-- Weekly editor -->
        <div id="panel-general">
          <div class="flex flex-col md:flex-col gap-2 justify-between mb-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Weekly pattern</h3>
            <div class="flex justify-between mt-2">
              <button id="preset-after6" class="btn btn-primary tab-lg">Weekdays after 18:00</button>
              <button id="gen-clear" class="btn-clear">Clear</button>
            </div>
          </div>
          <div id="gen-grid" class=""></div>
          <button id="gen-save" class="mt-3 mb-2 btn btn-alternative w-full">Save Weekly Rules</button>
          <div id="rules-list" class="mt-3 space-y-2 max-h-[280px] overflow-auto"></div>
        </div>

        <!-- Specific date picker -->
        <div id="panel-specific" class="hidden">
          <label class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-1 block">Date</label>
          <input id="spec-date" type="date" class="search-input mt-0" data-theme="light dark">
          
          <label class="text-sm font-medium text-gray-700 dark:text-gray-200 mt-4 mb-1 block">
            Note <span class="text-gray-500 dark:text-gray-400 font-normal">(optional)</span>
          </label>
          <input id="spec-note" type="text" maxlength="255" class="search-input" data-theme="light dark">
          
          <div class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">Paint 30‑minute blocks:</div>
          <div id="spec-grid" class="mt-2"></div>
          <div class="flex justify-end gap-2 mt-3">
            <button id="spec-clear" class="btn-clear">Clear</button>
            <button id="spec-save"  class="btn btn-alternative">Save Blocks</button>
          </div>
          <div id="spec-msg" class="text-sm mt-2"></div>
        </div>
      </aside>
    </div>
  </main>

<script>
const csrf = <?php echo json_encode($csrf); ?>;
const tzLabel = <?php echo json_encode($user_tz_id); ?>;

// Sync theme with dashboard preference
(function(){
  // Default to dark for students
  const saved = localStorage.getItem('theme') || 'dark';
  const isDark = saved === 'dark';
  document.documentElement.classList.toggle('dark', isDark);
  document.body.classList.toggle('theme-light', !isDark);
})();

// =============== Catch-ups UI ===============
const reqWrap = document.getElementById('requests');
const emptyReq = document.getElementById('empty-requests');
const btnRefresh = document.getElementById('btn-refresh');

async function fetchJSON(url, opts){ const r = await fetch(url, opts||{}); return r.json(); }

async function loadRequests(){
  const data = await fetchJSON('?api=list_requests');
  reqWrap.innerHTML = '';
  if (!data.ok || !data.requests || !data.requests.length){ emptyReq.classList.remove('hidden'); return; }
  emptyReq.classList.add('hidden');
  for (const r of data.requests){
    const card = document.createElement('div');
    card.className = 'rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-sm';
    card.innerHTML = `
      <div class="flex items-start justify-between">
        <div>
          <div class="text-slate-900 dark:text-white font-semibold">Catch‑up with ${escapeHtml(r.tutor_first_name)} ${escapeHtml(r.tutor_last_name)}</div>
          <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">Reason: ${escapeHtml(r.reason)} · Status: ${escapeHtml(r.status)}</div>
        </div>
        <button class="btn refresh-prop bg-gray-100 hover:bg-gray-200 text-slate-800 dark:bg-gray-800 dark:hover:bg-gray-700 dark:text-white border border-gray-200 dark:border-gray-700 px-2 py-1.5 rounded-lg text-xs" data-rid="${r.id}">Reload options</button>
      </div>
      <div class="proposals space-y-2 mt-3" id="props-${r.id}">
        <div class="text-sm text-gray-600 dark:text-gray-400">Loading options…</div>
      </div>
      <div class="mt-3 flex gap-2">
        <button class="btn ask-more bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg text-sm" data-rid="${r.id}">None work – ask for more</button>
      </div>
    `;
    reqWrap.appendChild(card);
    card.querySelector('.refresh-prop').addEventListener('click', ()=> loadProposals(r.id));
    card.querySelector('.ask-more').addEventListener('click', ()=> askMore(r.id));
    loadProposals(r.id);
  }
}

function escapeHtml(s){ const d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }

async function loadProposals(requestId){
  const box = document.getElementById('props-'+requestId);
  box.innerHTML = '<div class="text-sm text-gray-600 dark:text-gray-400">Loading options…</div>';
  const data = await fetchJSON('?api=list_proposals&request_id='+encodeURIComponent(requestId));
  box.innerHTML = '';
  if (!data.ok || !data.proposals || !data.proposals.length){
    box.innerHTML = '<div class="text-sm text-gray-600 dark:text-gray-400">No current options. You can ask for more, or set your availability on the right.</div>';
    return;
  }
  for (const p of data.proposals){
    const row = document.createElement('div');
    row.className = 'flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 shadow-sm';
    const start = new Date(p.start_iso).toLocaleString();
    const end   = new Date(p.end_iso).toLocaleString();
    const exp   = new Date(p.expires_iso).toLocaleString();
    row.innerHTML = `
      <div>
        <div class="text-slate-900 dark:text-white font-medium">${start} → ${end}</div>
        <div class="text-xs text-gray-600 dark:text-gray-400">Expires: ${exp}</div>
      </div>
      <div class="flex gap-2">
        <button class="btn accept bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-lg text-sm" data-id="${p.id}">Accept</button>
      </div>
    `;
    row.querySelector('.accept').addEventListener('click', ()=> acceptProposal(p.id, row));
    box.appendChild(row);
  }
}

async function acceptProposal(id, rowEl){
  rowEl.querySelector('.accept').disabled = true;
  const fd = new FormData(); fd.append('csrf', csrf); fd.append('proposal_id', id);
  const res = await fetch('?api=accept_proposal', {method:'POST', body: fd});
  const j = await res.json();
  if (j.ok){ rowEl.innerHTML = '<div class="text-emerald-400">Booked! Your catch‑up has been scheduled. Check your schedule.</div>'; }
  else {
    rowEl.querySelector('.accept').disabled = false;
    alert(j.error||'Could not schedule.');
  }
}

async function askMore(requestId){
  const fd = new FormData(); fd.append('csrf', csrf); fd.append('request_id', requestId);
  const j = await (await fetch('?api=ask_more', {method:'POST', body:fd})).json();
  if (j.ok){
    // Show hint and optionally reload after a delay
    alert('We\'ll ask for more options. You\'ll see new times soon.');
  }
}

if (btnRefresh) btnRefresh.addEventListener('click', loadRequests);

// =============== Availability (compact painter) ===============
const tabSpecific = document.getElementById('tab-specific');
const tabGeneral  = document.getElementById('tab-general');
const panelSpecific = document.getElementById('panel-specific');
const panelGeneral  = document.getElementById('panel-general');
function activateAvail(tab) {
  if (tab === 'specific') {
    tabSpecific.classList.add('active');
    tabGeneral.classList.remove('active');
    panelSpecific.classList.remove('hidden');
    panelGeneral.classList.add('hidden');
  } else {
    tabGeneral.classList.add('active');
    tabSpecific.classList.remove('active');
    panelGeneral.classList.remove('hidden');
    panelSpecific.classList.add('hidden');
  }
}
if (tabSpecific) tabSpecific.addEventListener('click', ()=> activateAvail('specific'));
if (tabGeneral)  tabGeneral .addEventListener('click', ()=> activateAvail('general'));
activateAvail('general');

// Specific grid
const WORK_START_HOUR = 8;
const WORK_END_HOUR = 22; // exclusive
const SLOT_COUNT = (WORK_END_HOUR - WORK_START_HOUR) * 2;
const HOURS = Array.from({length: WORK_END_HOUR - WORK_START_HOUR}, (_,idx)=>WORK_START_HOUR + idx);
const pad = n=>String(n).padStart(2,'0');
const hm = (h,m)=> `${pad(h)}:${pad(m)}`;
const slotIndexToTime = (idx)=>{
  const minutes = WORK_START_HOUR * 60 + idx * 30;
  return { h: Math.floor(minutes / 60), m: minutes % 60 };
};
const timeToSlotIndex = (h, m, roundUp = false)=>{
  const delta = (h * 60 + m) - WORK_START_HOUR * 60;
  const raw = delta / 30;
  const val = roundUp ? Math.ceil(raw) : Math.floor(raw);
  return Math.min(SLOT_COUNT, Math.max(0, val));
};
let specBits = Array(SLOT_COUNT).fill(false); let dragOn=false, drawVal=false;
const specGrid = document.getElementById('spec-grid');

function renderSpecGrid(){
  specGrid.innerHTML = '';
  const grid = document.createElement('div'); grid.className = 'grid grid-cols-6 gap-2';
  const col0 = document.createElement('div'); col0.className='space-y-1';
  HOURS.forEach(h=>{ const lab=document.createElement('div'); lab.className='text-xs text-gray-400 h-8 flex items-center'; lab.textContent=`${pad(h)}:00`; col0.appendChild(lab); });
  grid.appendChild(col0);
  for (let c=0;c<5;c++){
    const col = document.createElement('div'); col.className='space-y-1';
    for (let i=0;i<SLOT_COUNT;i++){
      const cell=document.createElement('div'); cell.className='cell h-4 md:h-5'; cell.dataset.index=i;
      if (specBits[i]) cell.classList.add('sel');
      cell.addEventListener('mousedown', e=>{ const idx=parseInt(e.currentTarget.dataset.index,10); dragOn=true; drawVal=!specBits[idx]; toggleSpec(idx,drawVal); e.preventDefault(); });
      cell.addEventListener('mouseenter', e=>{ if(dragOn){ const idx=parseInt(e.currentTarget.dataset.index,10); toggleSpec(idx,drawVal); }});
      col.appendChild(cell);
    }
    grid.appendChild(col);
  }
  specGrid.appendChild(grid);
}
function toggleSpec(i,v){
  if (i < 0 || i >= SLOT_COUNT) return;
  specBits[i]=v;
  specGrid.querySelectorAll(`[data-index="${i}"]`).forEach(el=> el.classList.toggle('sel', v));
}
window.addEventListener('mouseup', ()=>{ dragOn=false; });

const specDate = document.getElementById('spec-date');
const specNote = document.getElementById('spec-note');
const specClear= document.getElementById('spec-clear');
const specSave = document.getElementById('spec-save');
const specMsg  = document.getElementById('spec-msg');
renderSpecGrid();
if (specClear) specClear.addEventListener('click', ()=>{ specBits.fill(false); renderSpecGrid(); });
if (specSave) specSave.addEventListener('click', async ()=>{
  const date=specDate.value; specMsg.textContent=''; if(!date){ specMsg.textContent='Pick a date'; specMsg.className='text-sm text-red-400'; return; }
  const ranges = mergeSelectedToRanges(specBits); if(!ranges.length){ specMsg.textContent='Select at least one block'; specMsg.className='text-sm text-red-400'; return; }
  const fd=new FormData(); fd.append('csrf',csrf); fd.append('date',date); fd.append('ranges',JSON.stringify(ranges)); fd.append('note',specNote.value||'');
  const j=await (await fetch('?api=create_slots_for_date',{method:'POST',body:fd})).json();
  if (j.ok){ specMsg.textContent=`Saved ${j.created} slot(s)`; specMsg.className='text-sm text-emerald-400'; }
  else { specMsg.textContent=j.error||'Failed'; specMsg.className='text-sm text-red-400'; }
});

function mergeSelectedToRanges(bits){
  const ranges=[];
  let i=0;
  while(i<bits.length){
    if(!bits[i]){i++; continue;}
    let j=i+1;
    while(j<bits.length && bits[j]) j++;
    const start = slotIndexToTime(i);
    const end = slotIndexToTime(j);
    ranges.push({start:hm(start.h,start.m), end:hm(end.h,end.m)});
    i=j;
  }
  return ranges;
}

// Weekly
const genGrid = document.getElementById('gen-grid');
const genSave = document.getElementById('gen-save');
const genClear= document.getElementById('gen-clear');
const presetAfter6=document.getElementById('preset-after6');
const rulesList=document.getElementById('rules-list');
const weekdays=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
let weeklyBits = Array.from({length:7}, ()=> Array(SLOT_COUNT).fill(false));
let drag = {on:false, val:false};

function renderGenGrid(){
  genGrid.innerHTML = '';
  const wrapper = document.createElement('div'); wrapper.className='grid grid-cols-8 gap-1';
  const corner = document.createElement('div'); corner.className='p-2 text-xs text-gray-400'; corner.textContent='Time'; wrapper.appendChild(corner);
  weekdays.forEach(d=>{ const h=document.createElement('div'); h.className='text-center text-xs text-gray-300 p-2'; h.textContent=d; wrapper.appendChild(h); });
  for (let h=WORK_START_HOUR; h<WORK_END_HOUR; h++){
    const label=document.createElement('div'); label.className='text-xs text-gray-400 p-1'; label.textContent=`${pad(h)}:00`; wrapper.appendChild(label);
    for (let w=0; w<7; w++){
      const cell=document.createElement('div'); cell.className='cell h-4 md:h-5';
      const base=(h - WORK_START_HOUR)*2; const idx1=base, idx2=base+1; const both=weeklyBits[w][idx1]&&weeklyBits[w][idx2]; if(both) cell.classList.add('sel');
      cell.addEventListener('mousedown', e=>{ drag.on=true; const want=!(weeklyBits[w][idx1]&&weeklyBits[w][idx2]); drag.val=want; weeklyBits[w][idx1]=want; weeklyBits[w][idx2]=want; redrawHour(w,h,cell); e.preventDefault(); });
      cell.addEventListener('mouseenter', ()=>{ if(drag.on){ weeklyBits[w][idx1]=drag.val; weeklyBits[w][idx2]=drag.val; redrawHour(w,h,cell); }});
      wrapper.appendChild(cell);
    }
  }
  genGrid.appendChild(wrapper);
}
function redrawHour(w,h,cell){
  const base=(h - WORK_START_HOUR)*2;
  const both=weeklyBits[w][base]&&weeklyBits[w][base+1];
  cell.classList.toggle('sel', both);
}
window.addEventListener('mouseup', ()=>{ drag.on=false; });

function bitsToRules(){ const out=[]; for(let w=0;w<7;w++){ const rs=mergeSelectedToRanges(weeklyBits[w]); rs.forEach(r=>out.push({weekday:w,start:r.start,end:r.end,note:''})); } return out; }

async function loadRules(){
  const j=await (await fetch('?api=list_rules')).json();
  rulesList.innerHTML='';
  weeklyBits=Array.from({length:7},()=>Array(SLOT_COUNT).fill(false));
  if(j.ok){
    (j.rules||[]).forEach(r=>{
      const [sh,sm]=r.start_local.split(':').map(Number);
      const [eh,em]=r.end_local.split(':').map(Number);
      const si=timeToSlotIndex(sh,sm,false);
      const ei=timeToSlotIndex(eh,em,true);
      if (si>=ei) return;
      for(let i=si;i<ei;i++){
        if(i>=0 && i<SLOT_COUNT){
          weeklyBits[r.weekday][i]=true;
        }
      }
    const card = document.createElement('div');
    card.className = 'flex items-center justify-between p-4 rounded-lg rules-card';
    card.innerHTML = `
      <div class="flex-1 min-w-0">
        <div class='font-medium rules-card-text truncate'>${weekdays[r.weekday]} ${r.start_local.slice(0,5)} - ${r.end_local.slice(0,5)}</div>
        ${r.note ? `<div class='rules-card-note truncate'>${escapeHtml(r.note)}</div>` : ''}
      </div>
      <button class='btn btn-danger ml-3 whitespace-nowrap' data-id='${r.id}'>
        Delete
      </button>
    `;
      rulesList.appendChild(card);
    });
  }
  renderGenGrid();
}

// The Delete buttons are rebuilt on every loadRules(), so listen on the list
// rather than on each button. Nothing was listening at all before this, which
// left the delete_rule endpoint unreachable.
if (rulesList) rulesList.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-id]');
  if (!btn) return;
  const id = btn.dataset.id;
  if (!id) return;
  const label = btn.closest('.rules-card')?.querySelector('.rules-card-text')?.textContent?.trim() || 'this rule';
  if (!confirm(`Remove ${label}?`)) return;
  btn.disabled = true;
  const original = btn.textContent;
  btn.textContent = 'Removing…';
  try {
    const fd = new FormData(); fd.append('csrf', csrf); fd.append('id', id);
    const j = await (await fetch('?api=delete_rule', {method:'POST', body:fd})).json();
    if (j.ok) {
      await loadRules();          // also repaints the weekly grid
    } else {
      alert(j.error || 'Could not remove that rule.');
      btn.disabled = false; btn.textContent = original;
    }
  } catch (err) {
    alert('Could not reach the server.');
    btn.disabled = false; btn.textContent = original;
  }
});

if (genSave) genSave.addEventListener('click', async ()=>{ const fd=new FormData(); fd.append('csrf',csrf); fd.append('rules', JSON.stringify(bitsToRules())); const j=await (await fetch('?api=save_rules',{method:'POST', body:fd})).json(); if(j.ok){ await loadRules(); alert('Weekly rules saved.'); }});
if (genClear) genClear.addEventListener('click', ()=>{ weeklyBits=Array.from({length:7},()=>Array(SLOT_COUNT).fill(false)); renderGenGrid(); });
if (presetAfter6) presetAfter6.addEventListener('click', ()=>{
  weeklyBits=Array.from({length:7},()=>Array(SLOT_COUNT).fill(false));
  const si=timeToSlotIndex(18,0,false);
  const ei=timeToSlotIndex(21,0,true);
  for(let w=0;w<5;w++){
    for(let i=si;i<ei;i++){
      if(i>=0 && i<SLOT_COUNT) weeklyBits[w][i]=true;
    }
  }
  renderGenGrid();
});

// Boot. These are independent, so do not make the availability editor wait for
// every catch-up request and its proposals to come back first.
(async function(){
  rulesList.innerHTML = '<div class="text-sm text-gray-600 dark:text-gray-400 p-2">Loading your weekly pattern…</div>';
  await Promise.all([ loadRequests(), loadRules() ]);
})();
</script>
</body>
</html>
