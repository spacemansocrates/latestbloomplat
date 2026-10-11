<?php
/**
 * Tutor Catch‑ups & Availability (single file)
 * Route: tutors/catchup_tutor.php
 * PHP 7.2+ compatible. Dark glass UI. Tailwind via CDN.
 *
 * What this page provides:
 * 1) Lists your (tutor) open catch‑up requests with each student.
 * 2) Shows current proposals (expiring options) per request.
 * 3) Lets you quickly PROPOSE new times in two ways:
 *      a) Paint specific blocks for a chosen date → create proposals for that request
 *      b) Auto‑suggest 4 options from your weekly availability (with student availability if present)
 * 4) Right panel lets you edit your weekly availability (writes to tutor_availability).
 *
 * Requirements from previous migration:
 *   Tables: catchup_requests, catchup_proposals, student_availability, student_availability_rules
 *   Existing: lessons, tutor_availability, users (timezone), user_settings (optional)
 */

// 1) Auth
require_once 'auth_tutor_only.php';
require_once __DIR__ . '/../utils/timezone_helper.php';
// getUserTimezone() reads $_SESSION['timezone'], which authenticate.php
// populates at login and settings.php keeps current, and validates it.
// This used to read $_SESSION['user_timezone'] - a key nothing ever sets,
// so it always fell through to the hardcoded fallback below it.
$tutor_tz = getUserTimezone();
$current_page = 'catchup';

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 3) Helpers
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_out($ok, $data = [], $code = 200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode(['ok'=>$ok]+$data); exit(); }
function toUtcFromLocalTz($dateYmd, $timeHm, $tz){ try{$dt=new DateTime($dateYmd.' '.$timeHm,new DateTimeZone($tz));$dt->setTimezone(new DateTimeZone('UTC'));return $dt->format('Y-m-d H:i:s');}catch(Exception $e){return null;} }
function utcToIsoLocalTz($mysqlDt, $tz){ $dt=new DateTime($mysqlDt, new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone($tz)); return $dt->format(DateTime::ATOM); }
function clampInt($v,$min,$max){ $v=(int)$v; if($v<$min)return $min; if($v>$max)return $max; return $v; }

// 4) CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

// ──────────────────────────────────────────────────────────────────────
// Small utilities for auto‑suggest
function loadStudentTz(PDO $pdo, $student_id){
  $st = $pdo->prepare("SELECT timezone FROM users WHERE id=? LIMIT 1");
  $st->execute([$student_id]);
  $tz = $st->fetchColumn();
  return $tz ?: 'Africa/Blantyre';
}

function fetchTutorWeekly(PDO $pdo, $tutor_id){
  $st=$pdo->prepare("SELECT day_of_week, start_time, end_time FROM tutor_availability WHERE tutor_id=? ORDER BY day_of_week,start_time");
  $st->execute([$tutor_id]);
  $out=[]; while($r=$st->fetch()){ $d=(int)$r['day_of_week']; $out[$d] = $out[$d] ?? []; $out[$d][] = [$r['start_time'],$r['end_time']]; }
  return $out; // map weekday -> list of [start,end] local times in tutor tz
}

function fetchStudentWeekly(PDO $pdo, $student_id){
  $st=$pdo->prepare("SELECT weekday,start_local,end_local FROM student_availability_rules WHERE student_id=? ORDER BY weekday,start_local");
  $st->execute([$student_id]);
  $out=[]; while($r=$st->fetch()){ $d=(int)$r['weekday']; $out[$d] = $out[$d] ?? []; $out[$d][] = [$r['start_local'],$r['end_local']]; }
  return $out; // map weekday -> list of [start,end] local times in student tz
}

function fetchStudentSpecificUTC(PDO $pdo, $student_id, $startUtc, $endUtc){
  $st=$pdo->prepare("SELECT start_time_utc,end_time_utc FROM student_availability WHERE student_id=? AND NOT (end_time_utc<=? OR start_time_utc>=?) ORDER BY start_time_utc");
  $st->execute([$student_id,$startUtc,$endUtc]);
  return $st->fetchAll(); // list of utc windows
}

function overlapsLessons(PDO $pdo, $user_col, $user_id, $startUtc, $endUtc){
  $st=$pdo->prepare("SELECT 1 FROM lessons WHERE $user_col=? AND NOT (scheduled_end_time<=? OR scheduled_start_time>=?) LIMIT 1");
  $st->execute([$user_id,$startUtc,$endUtc]);
  return (bool)$st->fetchColumn();
}

function addMinutes($iso, $mins){ $dt=new DateTime($iso); $dt->modify("+{$mins} minutes"); return $dt->format('Y-m-d H:i:s'); }

// Intersect two local windows [s1,e1] and [s2,e2] (time strings HH:MM)
function intersectLocal($a,$b){
  list($s1,$e1)=$a; list($s2,$e2)=$b; if($e1<=$s2||$e2<=$s1) return null; $s=max($s1,$s2); $e=min($e1,$e2); return [$s,$e];
}

// ──────────────────────────────────────────────────────────────────────
// 5) API router
if (isset($_GET['api'])){
  $api = $_GET['api'];

  if ($api==='list_requests'){
    $st=$pdo->prepare("SELECT cr.id, cr.original_lesson_id, cr.student_id, cr.status, cr.reason, cr.created_at,
                              u.first_name, u.last_name, u.timezone AS student_tz
                       FROM catchup_requests cr
                       JOIN users u ON u.id = cr.student_id
                       WHERE cr.tutor_id=? AND cr.status IN ('Open','Proposed')
                       ORDER BY cr.created_at DESC");
    $st->execute([$tutor_id]);
    json_out(true, ['requests'=>$st->fetchAll()]);
  }

  if ($api==='list_proposals'){
    $rid=(int)($_GET['request_id']??0);
    $st=$pdo->prepare("SELECT id,request_id,start_time_utc,end_time_utc,status,expires_at FROM catchup_proposals WHERE request_id=? AND status='Proposed' ORDER BY start_time_utc");
    $st->execute([$rid]);
    $rows=$st->fetchAll(); $out=[]; foreach($rows as $r){ $out[]=[
      'id'=>(int)$r['id'], 'request_id'=>(int)$r['request_id'],
      'start_iso'=>utcToIsoLocalTz($r['start_time_utc'],$tutor_tz),
      'end_iso'=>utcToIsoLocalTz($r['end_time_utc'],$tutor_tz),
      'expires_iso'=>utcToIsoLocalTz($r['expires_at'],$tutor_tz),
      'status'=>$r['status']
    ]; }
    json_out(true,['proposals'=>$out]);
  }

  // Create proposals by painting specific date blocks (local tutor tz)
  if ($api==='create_proposals_for_date' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf']??'')!==$csrf) json_out(false,['error'=>'Bad token'],403);
    $rid=(int)($_POST['request_id']??0);
    $date=trim($_POST['date']??'');
    $ranges=json_decode($_POST['ranges']??'[]',true);
    $expiryHours=clampInt($_POST['expiry_hours']??24,1,168);
    if(!$rid||!$date||!is_array($ranges)||!count($ranges)) json_out(false,['error'=>'Missing data'],422);

    // Resolve student id
    $cr=$pdo->prepare("SELECT student_id FROM catchup_requests WHERE id=? AND tutor_id=? LIMIT 1");
    $cr->execute([$rid,$tutor_id]); $student_id=(int)$cr->fetchColumn();
    if(!$student_id) json_out(false,['error'=>'Request not found'],404);

    $student_tz = loadStudentTz($pdo, $student_id);

    $ins=$pdo->prepare("INSERT INTO catchup_proposals (request_id,start_time_utc,end_time_utc,status,expires_at) VALUES (?,?,?,?,?)");
    $dup=$pdo->prepare("SELECT 1 FROM catchup_proposals WHERE request_id=? AND start_time_utc=? AND end_time_utc=? LIMIT 1");
    $created=0; $skipped=0;

    foreach($ranges as $r){
      $s=$r['start']??''; $e=$r['end']??''; if(!$s||!$e||$e<=$s){$skipped++; continue;}
      $startUtc=toUtcFromLocalTz($date,$s,$tutor_tz);
      $endUtc  =toUtcFromLocalTz($date,$e,$tutor_tz);
      if(!$startUtc||!$endUtc){$skipped++; continue;}

      // Collision checks
      if (overlapsLessons($pdo,'tutor_id',$tutor_id,$startUtc,$endUtc)) { $skipped++; continue; }
      if (overlapsLessons($pdo,'student_id',$student_id,$startUtc,$endUtc)) { $skipped++; continue; }

      // Duplicate check
      $dup->execute([$rid,$startUtc,$endUtc]); if($dup->fetchColumn()){ $skipped++; continue; }

      $exp=(new DateTime($startUtc,new DateTimeZone('UTC'))); $exp->modify("+{$expiryHours} hours"); $expiresUtc=$exp->format('Y-m-d H:i:s');
      $ins->execute([$rid,$startUtc,$endUtc,'Proposed',$expiresUtc]);
      $created++;
    }
    // Optional: flip request to Proposed if any created
    if($created>0){ $pdo->prepare("UPDATE catchup_requests SET status='Proposed' WHERE id=?")->execute([$rid]); }
    json_out(true,['created'=>$created,'skipped'=>$skipped]);
  }

  // Auto‑suggest N options intersecting tutor weekly availability with student's availability (if any)
  if ($api==='auto_suggest' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf']??'')!==$csrf) json_out(false,['error'=>'Bad token'],403);
    $rid=(int)($_POST['request_id']??0); $count=clampInt($_POST['count']??4,1,8); $dur=clampInt($_POST['duration_minutes']??60,30,180);
    if(!$rid) json_out(false,['error'=>'Missing request_id'],422);

    $q=$pdo->prepare("SELECT student_id FROM catchup_requests WHERE id=? AND tutor_id=? LIMIT 1");
    $q->execute([$rid,$tutor_id]); $student_id=(int)$q->fetchColumn(); if(!$student_id) json_out(false,['error'=>'Request not found'],404);

    $tutorWeek = fetchTutorWeekly($pdo,$tutor_id); // in tutor local tz
    $studentWeek= fetchStudentWeekly($pdo,$student_id); // in student local tz
    $studentTz  = loadStudentTz($pdo,$student_id);

    $created=0; $horizonDays=14; $step=30; // minutes

    $ins=$pdo->prepare("INSERT INTO catchup_proposals (request_id,start_time_utc,end_time_utc,status,expires_at) VALUES (?,?,?,?,DATE_ADD(?, INTERVAL 24 HOUR))");
    $dup=$pdo->prepare("SELECT 1 FROM catchup_proposals WHERE request_id=? AND start_time_utc=? AND end_time_utc=? LIMIT 1");

    $today = new DateTime('now', new DateTimeZone($tutor_tz)); $today->setTime(0,0,0);

    for($d=0; $d<$horizonDays && $created<$count; $d++){
      $dayTutor = clone $today; $dayTutor->modify("+{$d} day");
      // ISO day: 1 = Monday .. 7 = Sunday, matching tutor_availability.day_of_week
      // as written by tutor/schedule.php. This used to subtract one, so every day
      // of the tutor's availability was read one day off and Sunday never matched.
      $weekday = (int)$dayTutor->format('N');

      if (empty($tutorWeek[$weekday])) continue; // tutor off

      foreach($tutorWeek[$weekday] as $tw){
        list($ts,$te) = $tw; // HH:MM in tutor tz
        // Walk this window in steps
        for($min=0; $min + $dur <= ((strtotime($te)-strtotime($ts))/60); $min += $step){
          $startLocal = (clone $dayTutor)->modify("+{$min} minutes");
          list($sh,$sm) = array_map('intval', explode(':',$ts));
          $startLocal->setTime($sh, $sm); $startLocal->modify("+{$min} minutes");
          $endLocal   = (clone $startLocal)->modify("+{$dur} minutes");

          // Convert start/end (tutor tz) → UTC strings
          $sUtc = (clone $startLocal); $sUtc->setTimezone(new DateTimeZone('UTC')); $startUtc=$sUtc->format('Y-m-d H:i:s');
          $eUtc = (clone $endLocal);   $eUtc->setTimezone(new DateTimeZone('UTC')); $endUtc  =$eUtc->format('Y-m-d H:i:s');

          // Check collisions
          if (overlapsLessons($pdo,'tutor_id',$tutor_id,$startUtc,$endUtc)) continue;
          if (overlapsLessons($pdo,'student_id',$student_id,$startUtc,$endUtc)) continue;

          // If student has explicit weekly rules, ensure the corresponding LOCAL student time fits at least one student window that day
          if (!empty($studentWeek)){
            $studentLocalStart = new DateTime($startUtc, new DateTimeZone('UTC')); $studentLocalStart->setTimezone(new DateTimeZone($studentTz));
            $studentLocalEnd   = new DateTime($endUtc,   new DateTimeZone('UTC')); $studentLocalEnd->setTimezone(new DateTimeZone($studentTz));
            $w = ((int)$studentLocalStart->format('N'))-1;
            $ok=false; if(!empty($studentWeek[$w])){
              $sl = $studentLocalStart->format('H:i'); $el=$studentLocalEnd->format('H:i');
              foreach($studentWeek[$w] as $sw){ $int = intersectLocal([$sl,$el], $sw); if($int){ $ok=true; break; } }
            }
            if(!$ok) continue; // outside student's weekly windows
          }

          // Avoid duplicate proposals
          $dup->execute([$rid,$startUtc,$endUtc]); if($dup->fetchColumn()) continue;

          // Insert proposal; expire 24h after start
          $ins->execute([$rid,$startUtc,$endUtc,'Proposed',$startUtc]);
          $created++;
          if($created>=$count) break 2;
        }
      }
    }

    // If nothing created and student has specific slots, we could fallback here (kept simple).
    if($created>0){ $pdo->prepare("UPDATE catchup_requests SET status='Proposed' WHERE id=?")->execute([$rid]); }
    json_out(true,['created'=>$created]);
  }

  // Weekly availability: list & save (overwrites tutor_availability for this tutor)
  if ($api==='list_weekly'){
    $st=$pdo->prepare("SELECT day_of_week,start_time,end_time FROM tutor_availability WHERE tutor_id=? ORDER BY day_of_week,start_time");
    $st->execute([$tutor_id]);
    json_out(true,['rows'=>$st->fetchAll()]);
  }
  if ($api==='save_weekly' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (($_POST['csrf']??'')!==$csrf) json_out(false,['error'=>'Bad token'],403);
    $rules=json_decode($_POST['rules']??'[]',true); if(!is_array($rules)) json_out(false,['error'=>'Bad payload'],422);
    $pdo->beginTransaction();
    try{
      $pdo->prepare("DELETE FROM tutor_availability WHERE tutor_id=?")->execute([$tutor_id]);
      $ins=$pdo->prepare("INSERT INTO tutor_availability (tutor_id,day_of_week,start_time,end_time) VALUES (?,?,?,?)");
      // 1..7 to match tutor/schedule.php, which writes the same column.
      foreach($rules as $r){ $w=(int)$r['weekday']; $s=$r['start']??''; $e=$r['end']??''; if($w<1||$w>7||!$s||!$e||$e<=$s) continue; $ins->execute([$tutor_id,$w,$s,$e]); }
      $pdo->commit(); json_out(true,['saved'=>true]);
    } catch(Exception $e){ $pdo->rollBack(); json_out(false,['error'=>'Save failed'],500); }
  }

  json_out(false,['error'=>'Unknown endpoint'],404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tutor – Catch‑ups & Availability</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
  <link rel="stylesheet" href="css/theme.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;}
    .glass{background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.04));backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.08)}
    .btn{transition:transform .08s ease}.btn:active{transform:translateY(1px)}
    /* .cell{user-select:none}
    .cell.sel{background:#4f46e522;border-color:#6366f1}
    .cell:hover{border-color:#6366f1} */
    .tab{cursor:pointer}
    .tab.active{background:#11183a;color:#fff;border-color:#4f46e5}
  </style>
</head>
<body class="min-h-screen flex">
  <?php include 'components/sidebar.php'; ?>
  <main class="flex-1 p-6 lg:p-10">

     <?php
      $header_config = [
          'page_title' => 'Catch‑ups & Availability',
          'page_subtitle' => 'Timezone: ' . htmlspecialchars($tutor_tz)
      ];
      include __DIR__ . '/components/header.php';
    ?>
    <div class="flex justify-end mb-6">
      <a href="schedule.php" class="btn px-4 py-2 rounded-xl card-base text-white hover:bg-white/10">Back to Schedule</a>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <!-- Left: requests/proposals -->
      <section class="xl:col-span-2 p-5 rounded-2xl card-base">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-white">Open Catch‑ups</h2>
          <button id="btn-refresh" class="btn bg-[#0f1530] hover:bg-white/10 border border-white/10 rounded-lg px-3 py-1.5">Refresh</button>
        </div>
        <div id="requests" class="space-y-4"></div>
        <div id="empty-requests" class="hidden text-sm text-gray-400">No active catch‑ups.</div>
      </section>

      <!-- Right: Weekly availability editor -->
      <aside class="p-5 rounded-2xl card-base">
        <h3 class="font-semibold text-white mb-2">Weekly Availability</h3>
        <div class="text-xs text-gray-400 mb-2">Click hours to toggle. Save to update your weekly slots.</div>
        <div id="week-grid"></div>
        <div class="flex gap-2 mt-3">
          <button id="week-clear" class="btn bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg">Clear</button>
          <button id="week-save"  class="btn bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-2 rounded-lg">Save Weekly</button>
        </div>
      </aside>
    </div>
  </main>

<script>
const csrf = <?php echo json_encode($csrf); ?>;
const tutorTz = <?php echo json_encode($tutor_tz); ?>;

// ===== Requests/Proposals =====
const reqWrap = document.getElementById('requests');
const emptyReq = document.getElementById('empty-requests');
const btnRefresh = document.getElementById('btn-refresh');

async function j(url, opts){ const r=await fetch(url, opts||{}); return r.json(); }
function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

async function loadRequests(){
  const data = await j('?api=list_requests');
  reqWrap.innerHTML='';
  if(!data.ok || !data.requests || !data.requests.length){ emptyReq.classList.remove('hidden'); return; }
  emptyReq.classList.add('hidden');
  for(const r of data.requests){
    const card=document.createElement('div');
    card.className='rounded-xl border border-white/10 bg-[#0f1530] p-4';
    card.innerHTML=`
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-white font-semibold">Catch‑up with ${esc(r.first_name)} ${esc(r.last_name)}</div>
          <div class="text-xs text-gray-400 mt-0.5">Reason: ${esc(r.reason)} · Status: ${esc(r.status)}</div>
        </div>
        <div class="flex gap-2">
          <button class="btn reload bg-gray-700 hover:bg-gray-600 text-white px-2 py-1.5 rounded-lg text-xs" data-rid="${r.id}">Reload</button>
        </div>
      </div>

      <div class="mt-3 grid md:grid-cols-2 gap-3">
        <div>
          <h4 class="text-sm text-white font-semibold mb-1">Current proposals</h4>
          <div class="proposals space-y-2" id="props-${r.id}"><div class='text-xs text-gray-400'>Loading…</div></div>
          <div class="flex flex-wrap gap-2 mt-2">
            <button class="btn auto bg-indigo-700 hover:bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs" data-rid="${r.id}">Auto‑suggest 4</button>
          </div>
        </div>
        <div>
          <h4 class="text-sm text-white font-semibold mb-1">Propose by painting</h4>
          <div class="grid grid-cols-2 gap-2">
            <input type="date" class="date w-full bg-[#0b1430] border border-white/10 rounded-lg px-2 py-1.5 text-sm" aria-label="Date">
            <select class="expiry w-full bg-[#0b1430] border border-white/10 rounded-lg px-2 py-1.5 text-sm">
              <option value="24">Expire in 24h</option>
              <option value="12">Expire in 12h</option>
              <option value="48">Expire in 48h</option>
            </select>
          </div>
          <div class="mt-2 text-xs text-gray-400">Paint 30‑minute blocks (your local time)</div>
          <div class="grid grid-cols-6 gap-2 mt-2 painter" data-rid="${r.id}"></div>
          <div class="flex gap-2 mt-2">
            <button class="btn clear bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded-lg text-xs" data-rid="${r.id}">Clear</button>
            <button class="btn save  bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-lg text-xs" data-rid="${r.id}">Create Proposals</button>
          </div>
          <div class="msg text-xs mt-1 text-gray-400"></div>
        </div>
      </div>
    `;
    reqWrap.appendChild(card);

    // Wire
    card.querySelector('.reload').addEventListener('click', ()=> loadProposals(r.id));
    card.querySelector('.auto').addEventListener('click', ()=> autoSuggest(r.id, card));

    const painter = card.querySelector('.painter');
    const msg = card.querySelector('.msg');
    const dateEl = card.querySelector('.date');
    const expiryEl = card.querySelector('.expiry');

    const HOURS = Array.from({length:24},(_,h)=>h);
    const HALF = Array.from({length:48},(_,i)=>({h:Math.floor(i/2),m:i%2?30:0}));
    const pad = n=>String(n).padStart(2,'0');
    const hm=(h,m)=>`${pad(h)}:${pad(m)}`;
    let bits=Array(48).fill(false); let drag=false, want=false;

    function renderPainter(){
      painter.innerHTML='';
      const col0=document.createElement('div'); col0.className='space-y-1';
      HOURS.forEach(h=>{ const lab=document.createElement('div'); lab.className='text-[10px] text-gray-400 h-4 md:h-5 flex items-center'; lab.textContent=`${pad(h)}:00`; col0.appendChild(lab); });
      painter.appendChild(col0);
      for(let c=0;c<5;c++){
        const col=document.createElement('div'); col.className='space-y-1';
        HALF.forEach((t,i)=>{ const cell=document.createElement('div'); cell.className='cell h-4 md:h-5 border border-white/10 rounded'; cell.dataset.i=i; if(bits[i]) cell.classList.add('sel');
          cell.addEventListener('mousedown', e=>{ const idx=parseInt(e.currentTarget.dataset.i,10); drag=true; want=!bits[idx]; toggle(idx,want); e.preventDefault(); });
          cell.addEventListener('mouseenter', e=>{ if(drag){ const idx=parseInt(e.currentTarget.dataset.i,10); toggle(idx,want); }});
          col.appendChild(cell);
        });
        painter.appendChild(col);
      }
    }
    function toggle(i,v){ bits[i]=v; const el=painter.querySelector(`[data-i="${i}"]`); if(el) el.classList.toggle('sel', v); }
    window.addEventListener('mouseup', ()=>{ drag=false; });
    renderPainter();

    card.querySelector('.clear').addEventListener('click', ()=>{ bits.fill(false); renderPainter(); msg.textContent=''; });
    card.querySelector('.save').addEventListener('click', async ()=>{
      msg.textContent=''; const date=dateEl.value; if(!date){ msg.textContent='Pick a date'; msg.className='msg text-xs mt-1 text-red-400'; return; }
      const ranges=merge(bits,HALF,hm); if(!ranges.length){ msg.textContent='Select blocks'; msg.className='msg text-xs mt-1 text-red-400'; return; }
      const fd=new FormData(); fd.append('csrf',csrf); fd.append('request_id', r.id); fd.append('date',date); fd.append('ranges',JSON.stringify(ranges)); fd.append('expiry_hours', expiryEl.value);
      const res=await j('?api=create_proposals_for_date',{method:'POST', body:fd});
      if(res.ok){ msg.textContent=`Created ${res.created} proposal(s) ${res.skipped?`· ${res.skipped} skipped`:''}`; msg.className='msg text-xs mt-1 text-emerald-400'; loadProposals(r.id); }
      else { msg.textContent=res.error||'Failed'; msg.className='msg text-xs mt-1 text-red-400'; }
    });

    loadProposals(r.id);
  }
}

function merge(bits,HALF,hm){ const out=[]; let i=0; while(i<bits.length){ if(!bits[i]){i++; continue;} let j=i+1; while(j<bits.length && bits[j]) j++; const s=HALF[i], e=HALF[j]||{h:24,m:0}; out.push({start:hm(s.h,s.m), end:hm(e.h,e.m)}); i=j; } return out; }

async function loadProposals(rid){
  const box=document.getElementById('props-'+rid); if(!box) return; box.innerHTML='<div class="text-xs text-gray-400">Loading…</div>';
  const data=await j('?api=list_proposals&request_id='+encodeURIComponent(rid)); box.innerHTML='';
  if(!data.ok||!data.proposals||!data.proposals.length){ box.innerHTML='<div class="text-xs text-gray-400">No current proposals.</div>'; return; }
  for(const p of data.proposals){
    const row=document.createElement('div'); row.className='flex items-center justify-between rounded-lg border border-white/10 bg-[#0b1430] p-3';
    row.innerHTML=`<div><div class='text-white font-medium'>${new Date(p.start_iso).toLocaleString()} → ${new Date(p.end_iso).toLocaleString()}</div><div class='text-[11px] text-gray-400'>Expires: ${new Date(p.expires_iso).toLocaleString()}</div></div>`;
    box.appendChild(row);
  }
}

async function autoSuggest(rid, card){
  const btn=card.querySelector('.auto'); btn.disabled=true; btn.textContent='Generating…';
  const fd=new FormData(); fd.append('csrf',csrf); fd.append('request_id',rid); fd.append('count','4'); fd.append('duration_minutes','60');
  const res=await j('?api=auto_suggest',{method:'POST', body:fd});
  btn.disabled=false; btn.textContent='Auto‑suggest 4';
  if(res.ok){ loadProposals(rid); if(res.created===0) alert('No overlapping free time found in the next 14 days. Try painting specific times.'); }
  else alert(res.error||'Failed');
}

if (btnRefresh) btnRefresh.addEventListener('click', loadRequests);

// ===== Weekly availability editor =====
const weekGrid = document.getElementById('week-grid');
const weekSave = document.getElementById('week-save');
const weekClear= document.getElementById('week-clear');
const weekdays=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
let weeklyBits = Array.from({length:7}, ()=> Array(48).fill(false));
let drag = {on:false, val:false};

function renderWeekGrid(){
  weekGrid.innerHTML='';
  const wrapper=document.createElement('div'); wrapper.className='grid grid-cols-8 gap-1';
  const corner=document.createElement('div'); corner.className='p-2 text-xs text-gray-400'; corner.textContent='Time'; wrapper.appendChild(corner);
  weekdays.forEach(d=>{ const h=document.createElement('div'); h.className='text-center text-xs text-gray-300 p-2'; h.textContent=d; wrapper.appendChild(h); });
  for(let h=0; h<24; h++){
    const label=document.createElement('div'); label.className='text-xs text-gray-400 p-1'; label.textContent=`${String(h).padStart(2,'0')}:00`; wrapper.appendChild(label);
    for(let w=0; w<7; w++){
      const cell=document.createElement('div'); cell.className='cell h-4 md:h-5 border border-white/10 rounded';
      const i=h*2; const on=weeklyBits[w][i]&&weeklyBits[w][i+1]; if(on) cell.classList.add('sel');
      cell.addEventListener('mousedown', e=>{ drag.on=true; const want=!(weeklyBits[w][i]&&weeklyBits[w][i+1]); drag.val=want; weeklyBits[w][i]=want; weeklyBits[w][i+1]=want; cell.classList.toggle('sel',want); e.preventDefault(); });
      cell.addEventListener('mouseenter', ()=>{ if(drag.on){ const want=drag.val; weeklyBits[w][i]=want; weeklyBits[w][i+1]=want; cell.classList.toggle('sel',want); }});
      wrapper.appendChild(cell);
    }
  }
  weekGrid.appendChild(wrapper);
}
window.addEventListener('mouseup', ()=>{ drag.on=false; });

function bitsToRules(){ const out=[]; for(let w=0; w<7; w++){ let i=0; while(i<48){ if(!weeklyBits[w][i]){i++; continue;} let j=i+1; while(j<48 && weeklyBits[w][j]) j++; const sh=Math.floor(i/2), sm=(i%2)*30; const eh=Math.floor(j/2), em=(j%2)*30; out.push({weekday:w+1, start:`${String(sh).padStart(2,'0')}:${String(sm).padStart(2,'0')}`, end:`${String(eh).padStart(2,'0')}:${String(em).padStart(2,'0')}`}); i=j; } } return out; }

async function loadWeekly(){
  weeklyBits = Array.from({length:7}, ()=> Array(48).fill(false));
  const data = await j('?api=list_weekly');
  if(data.ok && data.rows){
    for(const r of data.rows){
      // day_of_week is 1..7 (Mon..Sun); weeklyBits is indexed 0..6.
      const w = parseInt(r.day_of_week,10) - 1;
      if (w < 0 || w > 6) continue;
      const [sh,sm] = r.start_time.split(':').map(Number);
      const [eh,em] = r.end_time.split(':').map(Number);
      const si = sh*2 + (sm>=30?1:0);
      const ei = eh*2 + (em>=30?1:0);
      for(let i=si; i<ei; i++) weeklyBits[w][i]=true;
    }
  }
  renderWeekGrid();
}

if (weekSave) weekSave.addEventListener('click', async ()=>{
  const fd=new FormData(); fd.append('csrf',csrf); fd.append('rules', JSON.stringify(bitsToRules()));
  const res=await j('?api=save_weekly',{method:'POST', body:fd});
  if(res.ok) alert('Weekly availability saved.'); else alert(res.error||'Save failed');
});
if (weekClear) weekClear.addEventListener('click', ()=>{ weeklyBits=Array.from({length:7},()=>Array(48).fill(false)); renderWeekGrid(); });

// Boot
(async function(){
  await loadRequests();
  await loadWeekly();
})();
</script>
</body>
</html>
