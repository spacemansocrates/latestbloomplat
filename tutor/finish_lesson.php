<?php
// finish_lesson.php — redesigned UX for fast, intuitive topic selection
require_once __DIR__.'/auth_tutor_only.php'; 
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../integrations/file_storage.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

$tutor_timezone = getUserTimezone();

// Ensure database connection is available
if ($pdo === null) {
    try {
        $pdo = getDbConnection();
    } catch (Exception $e) {
        die('Database connection failed. Please contact support if this issue persists.');
    }
}

$lesson_id = (int)($_REQUEST['lesson_id'] ?? 0);

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// --- Load lesson + subject + student (ensure ownership) ---
$stmt = $pdo->prepare("
  SELECT l.*, s.id AS subject_id, s.name AS subject_name,
         u.first_name, u.last_name
  FROM lessons l
  JOIN subjects s ON s.id = l.subject_id
  JOIN users    u ON u.id = l.student_id
  WHERE l.id = ? AND l.tutor_id = ?
");
$stmt->execute([$lesson_id, $tutor_id]);
$lesson = $stmt->fetch();
if (!$lesson) { http_response_code(403); exit('Unauthorized'); }

$student_fullname = trim(($lesson['first_name'] ?? '').' '.($lesson['last_name'] ?? ''));

// Attendance is not a question for the tutor. The lesson room stamps student_join_time
// the moment the student opens it, so we already know. Asking again was busywork and
// allowed the tutor's answer and the recorded fact to disagree.
$studentJoinedAt = !empty($lesson['student_join_time']) ? $lesson['student_join_time'] : null;
$tutorJoinedAt   = !empty($lesson['tutor_join_time'])   ? $lesson['tutor_join_time']   : null;
$studentAttended = $studentJoinedAt !== null;

$studentJoinedLocal = $studentJoinedAt
    ? utcToLocal($studentJoinedAt, $tutor_timezone)->format('H:i')
    : null;

// Positive means the student joined after the scheduled start. Older imported lessons
// have start times and join times recorded against different clocks, which produces
// nonsense like "121 min early", so only comment on the timing when it is plausible.
$joinOffsetMin = $studentJoinedAt
    ? (int) round((strtotime($studentJoinedAt) - strtotime($lesson['scheduled_start_time'])) / 60)
    : null;
$joinOffsetTrustworthy = $joinOffsetMin !== null && abs($joinOffsetMin) <= 90;

// Pre-select the outcome that matches what happened. Still changeable below, because
// a lesson can be delivered in ways the room cannot see.
$terminalStatuses = ['Completed', 'NoShow', 'CancelledByTutor', 'CancelledByStudent'];
$defaultStatus = in_array($lesson['status'], $terminalStatuses, true)
    ? $lesson['status']
    : ($studentAttended ? 'Completed' : 'NoShow');

// --- Topics & subtopics for this subject ---
$topics = $pdo->prepare("SELECT id, name FROM topics WHERE subject_id=? ORDER BY name");
$topics->execute([(int)$lesson['subject_id']]);
$topics = $topics->fetchAll();

$subtopics = $pdo->prepare("
  SELECT st.id, st.name, st.topic_id
  FROM subtopics st
  JOIN topics t ON t.id = st.topic_id
  WHERE t.subject_id = ?
  ORDER BY st.name
");
$subtopics->execute([(int)$lesson['subject_id']]);
$subtopics = $subtopics->fetchAll();

// Group subtopics by topic_id for easy rendering
$subsByTopic = [];
foreach ($subtopics as $st) {
  $subsByTopic[(int)$st['topic_id']][] = $st;
}

// Preload any previously saved coverage for this lesson (if editing)
$preTopics = $pdo->prepare("SELECT topic_id FROM lesson_topics_covered WHERE lesson_id=?");
$preTopics->execute([$lesson_id]);
$preTopicIds = array_map('intval', $preTopics->fetchAll(PDO::FETCH_COLUMN));

$preSubs = $pdo->prepare("SELECT subtopic_id FROM lesson_subtopics_covered WHERE lesson_id=?");
$preSubs->execute([$lesson_id]);
$preSubIds = array_map('intval', $preSubs->fetchAll(PDO::FETCH_COLUMN));

// (Optional) show “recently covered” for this student & subject (last 5 subtopics)
$recentStmt = $pdo->prepare("
  SELECT st.id, st.name, t.name AS topic_name
  FROM lesson_subtopics_covered lsc
  JOIN subtopics st ON st.id = lsc.subtopic_id
  JOIN topics t ON t.id = st.topic_id
  JOIN lessons l ON l.id = lsc.lesson_id
  WHERE l.student_id = ? AND l.subject_id = ? AND l.status='Completed'
  ORDER BY l.scheduled_start_time DESC
  LIMIT 5
");
$recentStmt->execute([(int)$lesson['student_id'], (int)$lesson['subject_id']]);
$recentSubs = $recentStmt->fetchAll();

// --- Handle POST (save) ---
$ok = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $postedCsrf = $_POST['csrf'] ?? '';
    $validCsrf  = [];
    if (!empty($_SESSION['csrf'])) $validCsrf[] = $_SESSION['csrf'];
    if (!empty($_SESSION['csrf_prev'])) $validCsrf[] = $_SESSION['csrf_prev'];

    $csrfOk = false;
    foreach ($validCsrf as $tok) {
      if ($tok !== '' && hash_equals($tok, (string)$postedCsrf)) { $csrfOk = true; break; }
    }
    if (!$csrfOk) {
      // rotate token so next submit works without forcing re-login
      $_SESSION['csrf_prev'] = $_SESSION['csrf'] ?? '';
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
      $csrf = $_SESSION['csrf'];
      throw new RuntimeException('Session expired. Please submit again.');
    }

    $status = $_POST['status'] ?? 'Completed';
    $tutor_notes = trim($_POST['tutor_notes'] ?? '');
    
    // Handle custom topics
    $custom_topics = trim($_POST['custom_topics'] ?? '');
    if ($custom_topics !== '') {
      $customTopicsList = array_filter(array_map('trim', explode(',', $custom_topics)));
      if (!empty($customTopicsList)) {
        $customTopicsText = 'Custom Topics: ' . implode(', ', $customTopicsList);
        $tutor_notes = $tutor_notes !== '' 
          ? $tutor_notes . "\n\n" . $customTopicsText 
          : $customTopicsText;
      }
    }

    // Arrays from the fancy UI
    $topicsSel = array_map('intval', $_POST['topics'] ?? []);
    $subsSel   = array_map('intval', $_POST['subtopics'] ?? []);

    // Homework (optional)
    $hw_title = trim($_POST['hw_title'] ?? '');
    $hw_desc  = trim($_POST['hw_desc'] ?? '');
    $hw_due   = $_POST['hw_due'] ?? null;

    // 1) Update core lesson fields
    $pdo->prepare("
      UPDATE lessons
      SET status = ?, tutor_notes = ?
      WHERE id = ? AND tutor_id = ?
    ")->execute([$status, $tutor_notes, $lesson_id, $tutor_id]);

    // 2) Replace coverage only when completed; otherwise clear it
    $pdo->prepare("DELETE FROM lesson_topics_covered WHERE lesson_id=?")->execute([$lesson_id]);
    $pdo->prepare("DELETE FROM lesson_subtopics_covered WHERE lesson_id=?")->execute([$lesson_id]);

    if ($status === 'Completed') {
      if (!empty($topicsSel)) {
        $ins = $pdo->prepare("INSERT IGNORE INTO lesson_topics_covered (lesson_id, topic_id) VALUES (?,?)");
        foreach ($topicsSel as $tid) { $ins->execute([$lesson_id, $tid]); }
      }
      if (!empty($subsSel)) {
        $ins = $pdo->prepare("INSERT IGNORE INTO lesson_subtopics_covered (lesson_id, subtopic_id) VALUES (?,?)");
        foreach ($subsSel as $sid) { $ins->execute([$lesson_id, $sid]); }
      }
    }

    // 3) Optional homework (attach to lesson)
    if ($hw_title !== '') {
      $pdo->prepare("
        INSERT INTO homework (lesson_id, tutor_id, student_id, title, description, due_date)
        VALUES (?,?,?,?,?,?)
      ")->execute([$lesson_id, $tutor_id, $lesson['student_id'], $hw_title, $hw_desc, ($hw_due ?: null)]);

      $homework_id = (int)$pdo->lastInsertId();

      // Attachments
      if (!empty($_FILES['hw_files']['name'][0])) {
        $dir = __DIR__.'/../uploads/homework';
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        foreach ($_FILES['hw_files']['name'] as $i => $orig) {
          if (!($_FILES['hw_files']['tmp_name'][$i] ?? '')) continue;
          $safe = preg_replace('/[^a-zA-Z0-9._-]/','_', $orig);
          $dest = "$dir/{$homework_id}_$safe";
          move_uploaded_file($_FILES['hw_files']['tmp_name'][$i], $dest);
          $url = "../uploads/homework/{$homework_id}_$safe";
          storage_offload($dest, $url); // to bucket; stays local if it fails
          $pdo->prepare("
            INSERT INTO homework_attachments (homework_id, file_name, file_url)
            VALUES (?,?,?)
          ")->execute([$homework_id, $orig, $url]);
        }
      }
    }

    // No recording fetch here. The LiveKit room uploads its own recording when
    // the lesson ends (tutor/api/upload_recording.php), so there is nothing to
    // pull. What used to sit here called the Zoom API despite its name.

    // Redirect back (triggers can decrement lessons_remaining when status becomes Completed)
    header('Location: dashboard.php?done=1');
    exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}


// Pack minimal JS data
$JS = [
  'topics' => array_map(fn($t)=>['id'=>(int)$t['id'],'name'=>$t['name']], $topics),
  'subsByTopic' => array_map(function($topic){
    return array_map(fn($st)=>['id'=>(int)$st['id'],'name'=>$st['name'],'topic_id'=>(int)$st['topic_id']], $topic);
  }, $subsByTopic),
  'preTopicIds' => $preTopicIds,
  'preSubIds'   => $preSubIds,
  'recentSubs'  => $recentSubs,
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Finish Lesson — <?= h($lesson['subject_name']) ?></title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/theme.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;}
    .glass{background:linear-gradient(135deg, rgba(99,102,241,.08), rgba(236,72,153,.07)); border:1px solid rgba(255,255,255,.06);}
    .card{background:#0b1220; border:1px solid #1f2937;}
    .chip{background:#0b1324; border:1px solid #1f2a44;}
    .chip-checked{background:#172554; border-color:#1e3a8a;}
    .input{background:#0b1220; border:1px solid #1f2937; color:#e5e7eb; border-radius:.6rem; padding:.65rem .9rem; width:100%;}
    .input:focus{outline:none; border-color:#6366f1; box-shadow:0 0 0 2px #4f46e5;}
    /* Icon helpers — keep every svg crisp and centered */
    .icon{width:18px; height:18px; display:inline-block; flex:0 0 auto; vertical-align:middle;}
    .icon-16{width:16px; height:16px;}
    .icon-btn{inline-size:28px; block-size:28px; display:inline-flex; align-items:center; justify-content:center;}
    .icon-dim{color:#94a3b8;}
    .input.has-icon{padding-left:2.25rem;}
    .input-icon{position:absolute; left:.65rem; top:50%; transform:translateY(-50%); pointer-events:none;}
    ::-webkit-scrollbar{width:8px;} ::-webkit-scrollbar-thumb{background:#334155; border-radius:4px;}
  </style>
</head>
<body class="min-h-screen">
  <div class="max-w-6xl mx-auto p-6 lg:p-8 space-y-6">
    <!-- Header -->
    <header class="glass rounded-xl p-5">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold">Finish Lesson</h1>
          <p class="text-slate-400 text-sm">
            Student: <span class="text-slate-200 font-medium"><?= h($student_fullname) ?></span> •
            Subject: <span class="text-slate-200 font-medium"><?= h($lesson['subject_name']) ?></span> •
            When: <span class="text-slate-200 font-medium"><?= h(formatLocalDatetime($lesson['scheduled_start_time'], 'M j, Y g:i a', $tutor_timezone)) ?></span>
          </p>
        </div>
        <a href="dashboard.php" class="text-sm text-indigo-300 hover:text-indigo-200">Back to Dashboard</a>
      </div>
    </header>

    <?php if ($err): ?>
      <div class="bg-red-500/10 border border-red-500/30 text-red-200 px-4 py-2 rounded-lg"><?= h($err) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-6" id="finishForm">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="custom_topics" id="customTopicsInput" value="">
      <!-- The selection lives in JS state, not in whichever widgets happen to be on
           screen. Search results are buttons, so without this the whole selection was
           silently dropped on submit. -->
      <div id="selectionInputs" hidden></div>

      <!-- Top controls: attendance + final status + notes -->
      <section class="card rounded-xl p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="rounded-lg border px-3 py-2.5 <?= $studentAttended ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-amber-500/30 bg-amber-500/10' ?>">
            <div class="flex items-start gap-2.5">
              <svg class="mt-0.5 shrink-0" style="width:18px;height:18px" aria-hidden="true">
                <use href="../assets/icons/sprite.svg#i-<?= $studentAttended ? 'user-check' : 'alert-circle' ?>"></use>
              </svg>
              <div class="min-w-0">
                <?php if ($studentAttended): ?>
                  <div class="text-sm font-semibold text-emerald-200">Student joined at <?= h($studentJoinedLocal) ?></div>
                  <div class="text-xs text-emerald-300/70">
                    <?php if (!$joinOffsetTrustworthy): ?>
                      Recorded by the lesson room
                    <?php elseif ($joinOffsetMin > 2): ?>
                      <?= (int)$joinOffsetMin ?> min after the start time
                    <?php elseif ($joinOffsetMin < -2): ?>
                      <?= abs((int)$joinOffsetMin) ?> min early
                    <?php else: ?>
                      On time
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="text-sm font-semibold text-amber-200">Student never opened the room</div>
                  <div class="text-xs text-amber-300/70">Set the outcome yourself if they attended another way.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm text-slate-300 mb-1">Outcome</label>
            <select name="status" class="input">
              <?php
                $opts = ['Completed'=>'Completed', 'NoShow'=>'No Show', 'CancelledByTutor'=>'Cancelled by Tutor', 'CancelledByStudent'=>'Cancelled by Student'];
                foreach ($opts as $v=>$lbl) {
                  $sel = ($defaultStatus === $v) ? 'selected' : '';
                  echo "<option value=\"".h($v)."\" $sel>".h($lbl)."</option>";
                }
              ?>
            </select>
            <p class="text-xs text-slate-500 mt-1">Topics & subtopics are saved only when status is <strong>Completed</strong>.</p>
          </div>

          <div>
            <label class="block text-sm text-slate-300 mb-1">Quick actions</label>
            <div class="flex gap-2">
              <button type="button" id="expandAll"   class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm">Expand all</button>
              <button type="button" id="collapseAll" class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm">Collapse all</button>
              <button type="button" id="clearAll"    class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm">Clear</button>
            </div>
          </div>
        </div>

        <div class="mt-4">
          <label class="block text-sm text-slate-300 mb-1">Tutor Notes</label>
          <textarea name="tutor_notes" rows="3" class="input" placeholder="What was covered, strengths, next steps…"><?= h($lesson['tutor_notes'] ?? '') ?></textarea>
          
          <!-- Custom Topic Quick Buttons -->
          <div class="mt-3">
            <div class="text-xs text-slate-400 mb-2">Quick add to notes:</div>
            <div id="customTopicButtons" class="flex flex-wrap gap-2">
              <button type="button" data-custom-topic="Past Paper" class="px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors">
                Past Paper
              </button>
              <button type="button" data-custom-topic="Homework from School" class="px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors">
                Homework from School
              </button>
              <button type="button" data-custom-topic="Previous Topic" class="px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors">
                Previous Topic
              </button>
              <button type="button" data-custom-topic="Test Revision" class="px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors">
                Test Revision
              </button>
              <button type="button" data-custom-topic="School Work" class="px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors">
                School Work
              </button>
            </div>
            <div id="customTopicsList" class="mt-2 flex flex-wrap gap-2"></div>
          </div>
        </div>
      </section>

      <!-- Topics & Subtopics -->
      <section class="card rounded-xl p-5">
        <!-- What is currently picked, always visible so it can be checked and undone
             without hunting back through the list. -->
        <div id="selectedBar" class="mb-4"></div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
          <div class="relative md:flex-1 min-w-[240px]">
            <input id="topicSearch" type="text" class="input has-icon" placeholder="Type to find a subtopic, e.g. &quot;fractions&quot;">
            <svg class="input-icon icon icon-dim" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </div>
          <div id="selectionSummary" class="text-sm text-slate-400">0 topics • 0 subtopics selected</div>
        </div>

        <!-- Recently covered quick chips -->
        <?php if (!empty($recentSubs)): ?>
          <div class="mb-4">
            <div class="text-xs text-slate-400 mb-2">Recently covered (student + subject):</div>
            <div id="recentChips" class="flex flex-wrap gap-2">
              <?php foreach ($recentSubs as $r): ?>
                <button type="button" data-subid="<?= (int)$r['id'] ?>"
                        class="px-2.5 py-1 rounded-full chip hover:bg-slate-800 text-sm">
                  <?= h($r['topic_name'].' — '.$r['name']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Topics list renders here -->
        <div id="topicsMount" class="divide-y divide-slate-800"></div>
      </section>

      <!-- Homework (optional) -->
      <section class="card rounded-xl p-5">
        <h3 class="text-lg font-semibold mb-3">Assign Homework <span class="text-slate-400 text-sm font-normal">(optional)</span></h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-2">
            <input type="text" name="hw_title" class="input mb-3" placeholder="Title">
            <textarea name="hw_desc" rows="3" class="input" placeholder="Description / instructions"></textarea>
          </div>
          <div>
            <label class="block text-sm text-slate-300 mb-1">Due date</label>
            <input type="datetime-local" name="hw_due" class="input mb-4">
            <label class="block text-sm text-slate-300 mb-1">Attachments</label>
            <input type="file" name="hw_files[]" multiple class="block w-full text-sm text-slate-300">
          </div>
        </div>
      </section>

      <!-- Submit -->
      <div class="flex items-center justify-end gap-2">
        <a href="dashboard.php" class="px-5 py-2 rounded-lg bg-slate-700 hover:bg-slate-600">Cancel</a>
        <button class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 font-semibold">Save & Return</button>
      </div>
    </form>
  </div>

  <script>
    // ----- Data from PHP -----
    const DATA = <?= json_encode($JS, JSON_UNESCAPED_UNICODE) ?>;

    // ----- State -----
    const openState   = new Map();     // topic_id => bool (expanded)
    const topicSel    = new Set(DATA.preTopicIds || []);
    const subSel      = new Set(DATA.preSubIds || []);
    const subsByTopic = new Map(Object.entries(DATA.subsByTopic).map(([k,v])=>[+k, v || []]));
    const customTopics = new Set();    // Custom topics added by tutor

    // ----- DOM helpers -----
    const mount   = document.getElementById('topicsMount');
    const searchI = document.getElementById('topicSearch');
    const summary = document.getElementById('selectionSummary');

    // Fill initial openState (closed by default)
    (DATA.topics || []).forEach(t => openState.set(t.id, false));

    // Expand/Collapse/Clear
    document.getElementById('expandAll')?.addEventListener('click', () => {
      DATA.topics.forEach(t => openState.set(t.id, true)); redraw();
    });
    document.getElementById('collapseAll')?.addEventListener('click', () => {
      DATA.topics.forEach(t => openState.set(t.id, false)); redraw();
    });
    document.getElementById('clearAll')?.addEventListener('click', () => {
      topicSel.clear(); subSel.clear(); redraw();
    });

    // Custom topic buttons
    document.getElementById('customTopicButtons')?.addEventListener('click', e=>{
      const b = e.target.closest('button[data-custom-topic]');
      if (!b) return;
      const customTopic = b.dataset.customTopic;
      if (customTopics.has(customTopic)) {
        // Remove if already added
        customTopics.delete(customTopic);
      } else {
        // Add custom topic
        customTopics.add(customTopic);
      }
      updateCustomTopicsDisplay();
    });

    // Update custom topics display
    function updateCustomTopicsDisplay() {
      const container = document.getElementById('customTopicsList');
      const input = document.getElementById('customTopicsInput');
      if (!container || !input) return;

      container.innerHTML = '';
      if (customTopics.size === 0) {
        input.value = '';
        // Reset all button styles
        document.querySelectorAll('#customTopicButtons button[data-custom-topic]').forEach(btn => {
          btn.className = 'px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors';
        });
        return;
      }

      // Store in hidden input as comma-separated
      input.value = Array.from(customTopics).join(',');

      // Update button styles to show selected state
      document.querySelectorAll('#customTopicButtons button[data-custom-topic]').forEach(btn => {
        const topic = btn.dataset.customTopic;
        if (customTopics.has(topic)) {
          btn.className = 'px-3 py-1.5 rounded-lg bg-indigo-600/40 hover:bg-indigo-600/50 border-2 border-indigo-400 text-indigo-100 text-sm font-medium transition-colors';
        } else {
          btn.className = 'px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-200 text-sm font-medium transition-colors';
        }
      });

      // Display selected custom topics as chips
      customTopics.forEach(topic => {
        const chip = document.createElement('div');
        chip.className = 'inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-600/30 border border-indigo-500/40 text-indigo-200 text-sm';
        chip.innerHTML = `
          <span>${escapeHtml(topic)}</span>
          <button type="button" class="text-indigo-300 hover:text-indigo-100" data-remove-topic="${escapeHtml(topic)}" aria-label="Remove">
            <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
          </button>
        `;
        container.appendChild(chip);
      });

      // Handle remove buttons
      container.querySelectorAll('button[data-remove-topic]').forEach(btn => {
        btn.addEventListener('click', () => {
          const topic = btn.dataset.removeTopic;
          customTopics.delete(topic);
          updateCustomTopicsDisplay();
        });
      });
    }

    // Recently covered quick chips
    document.getElementById('recentChips')?.addEventListener('click', e=>{
      const b = e.target.closest('button[data-subid]');
      if (!b) return;
      const sid = +b.dataset.subid;
      subSel.add(sid);
      // ensure parent topic checked
      // Find this subtopic's topic id
      for (const [tid, list] of subsByTopic.entries()) {
        if (list.some(s=>+s.id===sid)) { topicSel.add(tid); openState.set(tid, true); break; }
      }
      redraw();
    });

    // Search
    let searchTerm = '';
    searchI.addEventListener('input', () => { searchTerm = (searchI.value || '').toLowerCase(); redraw(); });

    // Build a single topic row
    function topicRow(t) {
      const tid = +t.id;
      const subs = subsByTopic.get(tid) || [];
      const totalSubs = subs.length;
      const selectedSubs = subs.filter(s => subSel.has(+s.id)).length;

      const wrapper = document.createElement('div');
      wrapper.className = 'py-3';

      // Header row
      const head = document.createElement('div');
      head.className = 'flex items-center gap-3';

      const caret = document.createElement('button');
      caret.type = 'button';
      caret.className = 'icon-btn rounded hover:bg-slate-800';
      caret.innerHTML = `
        <svg class="icon-16 text-slate-300 transition-transform ${openState.get(tid)?'rotate-90':''}" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg>`;
      caret.onclick = () => { openState.set(tid, !openState.get(tid)); redraw(false); };

      // Topic checkbox
      const ck = document.createElement('input');
      ck.type = 'checkbox';
      ck.value = tid;   // display only; #selectionInputs carries what is submitted
      ck.className = 'accent-indigo-500';
      ck.checked = topicSel.has(tid);
      // Indeterminate when some but not all subtopics are selected
      ck.indeterminate = !topicSel.has(tid) && selectedSubs > 0;

      ck.addEventListener('change', () => {
        if (ck.checked) {
          topicSel.add(tid);
          // select all children
          subs.forEach(s => subSel.add(+s.id));
        } else {
          topicSel.delete(tid);
          // deselect all children
          subs.forEach(s => subSel.delete(+s.id));
        }
        redraw();
      });

      const title = document.createElement('div');
      title.className = 'flex-1 min-w-0';
      title.innerHTML = `
        <div class="font-semibold text-slate-100 truncate">${escapeHtml(t.name)}</div>
        <div class="text-xs text-slate-400">${selectedSubs}/${totalSubs} subtopics selected</div>
      `;

      // “Select all subtopics” quick action
      const allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'text-xs px-2 py-1 rounded bg-slate-800 hover:bg-slate-700';
      allBtn.textContent = selectedSubs === totalSubs ? 'Clear all' : 'Select all';
      allBtn.onclick = () => {
        if (selectedSubs === totalSubs) {
          subs.forEach(s => subSel.delete(+s.id));
          topicSel.delete(tid);
        } else {
          subs.forEach(s => subSel.add(+s.id));
          topicSel.add(tid);
        }
        redraw();
      };

      head.appendChild(caret);
      head.appendChild(ck);
      head.appendChild(title);
      head.appendChild(allBtn);
      wrapper.appendChild(head);

      // Subtopics grid (collapsible)
      const open = !!openState.get(tid);
      if (open && totalSubs) {
        const grid = document.createElement('div');
        grid.className = 'mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2';

        subs.forEach(s => {
          // Filter subtopic by search term as well
          const name = (s.name || '').toLowerCase();
          if (searchTerm && !name.includes(searchTerm) && !(t.name || '').toLowerCase().includes(searchTerm)) return;

          const lab = document.createElement('label');
          lab.className = 'flex items-center gap-2 px-2 py-1.5 rounded chip hover:bg-slate-800/60 cursor-pointer';
          const sub = document.createElement('input');
          sub.type = 'checkbox';
          sub.value = +s.id;   // display only; #selectionInputs carries what is submitted
          sub.className = 'accent-indigo-500';
          sub.checked = subSel.has(+s.id);

          sub.addEventListener('change', () => {
            if (sub.checked) {
              subSel.add(+s.id);
              topicSel.add(tid); // ensure parent ticked
            } else {
              subSel.delete(+s.id);
              // if none left, untick parent (unless other subs still selected)
              const anyLeft = (subsByTopic.get(tid) || []).some(x => subSel.has(+x.id));
              if (!anyLeft) topicSel.delete(tid);
            }
            redraw();
          });

          const nameEl = document.createElement('span');
          nameEl.textContent = s.name;

          lab.appendChild(sub);
          lab.appendChild(nameEl);
          grid.appendChild(lab);
        });

        wrapper.appendChild(grid);
      }

      return wrapper;
    }

    // Topic name for a subtopic id, for the chips and the flat results.
    const topicNameById = new Map((DATA.topics || []).map(t => [+t.id, t.name]));
    function topicOf(subId) {
      for (const [tid, subs] of subsByTopic) {
        if (subs.some(x => +x.id === +subId)) return tid;
      }
      return null;
    }
    function subName(subId) {
      for (const subs of subsByTopic.values()) {
        const hit = subs.find(x => +x.id === +subId);
        if (hit) return hit.name;
      }
      return '';
    }

    // Everything picked so far, as removable chips. Without this the only feedback was
    // a counter, so checking your own answer meant reopening every topic.
    const selectedBar = document.getElementById('selectedBar');
    function renderSelectedChips() {
      if (!selectedBar) return;
      selectedBar.innerHTML = '';
      if (!subSel.size && !topicSel.size) {
        selectedBar.innerHTML =
          '<div class="text-sm text-slate-500 border border-dashed border-slate-700 rounded-lg px-3 py-2.5">' +
          'Nothing picked yet. Search above, or use a recent one below.</div>';
        return;
      }
      const box = document.createElement('div');
      box.className = 'rounded-lg border border-indigo-500/30 bg-indigo-500/5 px-3 py-2.5';
      const head = document.createElement('div');
      head.className = 'text-xs text-slate-400 mb-2';
      head.textContent = 'Covered this lesson';
      box.appendChild(head);

      const wrap = document.createElement('div');
      wrap.className = 'flex flex-wrap gap-2';

      subSel.forEach(sid => {
        const tid = topicOf(sid);
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-indigo-600/25 border border-indigo-500/40 text-indigo-100 text-sm';
        chip.innerHTML =
          '<span>' + escapeHtml((tid ? topicNameById.get(tid) + ' \u2192 ' : '') + subName(sid)) + '</span>' +
          '<svg style="width:14px;height:14px" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>';
        chip.title = 'Remove';
        chip.onclick = () => {
          subSel.delete(+sid);
          if (tid && !(subsByTopic.get(tid) || []).some(x => subSel.has(+x.id))) topicSel.delete(tid);
          redraw();
        };
        wrap.appendChild(chip);
      });

      // A topic ticked with none of its subtopics: still worth showing.
      topicSel.forEach(tid => {
        const subs = subsByTopic.get(+tid) || [];
        if (subs.some(x => subSel.has(+x.id))) return;
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-slate-700/60 border border-slate-600 text-slate-100 text-sm';
        chip.innerHTML =
          '<span>' + escapeHtml(topicNameById.get(+tid) || 'Topic') + ' (whole topic)</span>' +
          '<svg style="width:14px;height:14px" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>';
        chip.onclick = () => { topicSel.delete(+tid); redraw(); };
        wrap.appendChild(chip);
      });

      box.appendChild(wrap);
      selectedBar.appendChild(box);
    }

    // Searching returns the subtopics themselves, not topics to go digging through.
    function flatResults(term) {
      const out = [];
      (DATA.topics || []).forEach(t => {
        const tn = (t.name || '').toLowerCase();
        (subsByTopic.get(+t.id) || []).forEach(sub => {
          const sn = (sub.name || '').toLowerCase();
          if (sn.includes(term) || tn.includes(term)) out.push({ topic: t, sub });
        });
      });
      return out;
    }

    function renderFlatResults(term) {
      const hits = flatResults(term);
      if (!hits.length) {
        const empty = document.createElement('div');
        empty.className = 'text-slate-400 text-sm py-4';
        empty.textContent = 'Nothing matches “' + term + '”. Try a shorter word, or add it as a custom topic above.';
        mount.appendChild(empty);
        return;
      }
      const note = document.createElement('div');
      note.className = 'text-xs text-slate-400 mb-2';
      note.textContent = hits.length + ' match' + (hits.length === 1 ? '' : 'es') + ' \u2014 click to add';
      mount.appendChild(note);

      const grid = document.createElement('div');
      grid.className = 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 pb-2';
      hits.slice(0, 120).forEach(({ topic, sub }) => {
        const on = subSel.has(+sub.id);
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'text-left px-3 py-2 rounded-lg border transition-colors ' +
          (on ? 'bg-indigo-600/25 border-indigo-500/50 text-indigo-100'
              : 'bg-slate-800/40 border-slate-700 hover:border-slate-500 text-slate-200');
        b.innerHTML =
          '<div class="text-sm font-medium">' + escapeHtml(sub.name) + '</div>' +
          '<div class="text-xs text-slate-400 truncate">' + escapeHtml(topic.name) + '</div>';
        b.onclick = () => {
          const tid = +topic.id;
          if (subSel.has(+sub.id)) {
            subSel.delete(+sub.id);
            if (!(subsByTopic.get(tid) || []).some(x => subSel.has(+x.id))) topicSel.delete(tid);
          } else {
            subSel.add(+sub.id);
            topicSel.add(tid);
          }
          redraw();
        };
        grid.appendChild(b);
      });
      mount.appendChild(grid);
      if (hits.length > 120) {
        const more = document.createElement('div');
        more.className = 'text-xs text-slate-500 pt-1';
        more.textContent = 'Showing the first 120. Keep typing to narrow it down.';
        mount.appendChild(more);
      }
    }

    // Render / Re-render
    // Mirror the selection into real form fields. This is what actually gets posted.
    const selectionInputs = document.getElementById('selectionInputs');
    function syncSelectionInputs() {
      if (!selectionInputs) return;
      selectionInputs.innerHTML = '';
      const add = (name, value) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = value;
        selectionInputs.appendChild(i);
      };
      topicSel.forEach(id => add('topics[]', id));
      subSel.forEach(id => add('subtopics[]', id));
    }

    function redraw(rebuild=true) {
      summary.textContent = `${topicSel.size} topics • ${subSel.size} subtopics selected`;
      renderSelectedChips();
      syncSelectionInputs();

      if (rebuild) {
        mount.innerHTML = '';

        // Search goes straight to matching subtopics; browsing keeps the topic list.
        if (searchTerm) { renderFlatResults(searchTerm); return; }

        const list = (DATA.topics || []);
        if (!list.length) {
          const empty = document.createElement('div');
          empty.className = 'text-slate-400 text-sm py-4';
          empty.textContent = 'No topics are set up for this subject yet.';
          mount.appendChild(empty);
          return;
        }
        list.forEach(t => mount.appendChild(topicRow(t)));
      } else {
        // Only update the open toggles + counts without full rebuild
        // Simpler: full rebuild is cheap enough for typical volumes
        redraw(true);
      }
    }

    // Escape helper
    function escapeHtml(s){ return (s??'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

    // Initial render
    redraw(true);

    // Keep parent checkbox indeterminate states on every rebuild
    const observer = new MutationObserver(() => {
      (DATA.topics||[]).forEach(t => {
        const tid = +t.id;
        const subs = subsByTopic.get(tid) || [];
        const total = subs.length;
        const selected = subs.filter(s => subSel.has(+s.id)).length;

        const input = mount.querySelector(`input[type="checkbox"][name="topics[]"][value="${tid}"]`);
        if (input) {
          input.indeterminate = !topicSel.has(tid) && selected > 0 && selected < total;
        }
      });
    });
    observer.observe(mount, {childList:true, subtree:true});
  </script>
</body>
</html>
