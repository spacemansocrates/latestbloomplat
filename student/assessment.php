<?php
// student/assessment.php
//
// The student takes their diagnostic assessment here, logged in, during the
// free demo session. Answers autosave through student/api/assessment.php and
// are scored server-side only: this page never receives the answer key.

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}
$current_page = 'assessment';
$student_id = (int)$_SESSION['user_id'];
$first_name = trim((string)($_SESSION['user_first_name'] ?? ''));

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/assessment.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$attempt_id = (int)($_GET['id'] ?? 0);

// No id: send the student straight to their open assessment if there is one.
if ($attempt_id <= 0) {
    $st = $pdo->prepare("SELECT id, assessment_id, assessment_version, status, submitted_at
                         FROM assessment_attempts WHERE student_id = ? ORDER BY id DESC");
    $st->execute([$student_id]);
    $attempts = $st->fetchAll(PDO::FETCH_ASSOC);
    $open = array_values(array_filter($attempts, fn($r) => in_array($r['status'], ['assigned', 'in_progress'], true)));
    if (count($open) === 1) {
        header('Location: assessment.php?id=' . (int)$open[0]['id']);
        exit();
    }
    $attempt = null;
} else {
    $st = $pdo->prepare("SELECT * FROM assessment_attempts WHERE id = ? AND student_id = ?");
    $st->execute([$attempt_id, $student_id]);
    $attempt = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$attempt) {
        http_response_code(404);
    }
}

$a = null;
$mode = 'list';
if ($attempt) {
    $a = asm_load($attempt['assessment_id'], (int)$attempt['assessment_version']);
    if (!$a) {
        $mode = 'missing';
    } elseif (in_array($attempt['status'], ['assigned', 'in_progress'], true)) {
        $mode = 'take';
        if ($attempt['status'] === 'assigned') {
            // First open starts the clock. Time is compared SQL-to-SQL (NOW()) because
            // the database and PHP clocks differ on this stack.
            $pdo->prepare("UPDATE assessment_attempts SET status = 'in_progress', started_at = NOW()
                           WHERE id = ? AND status = 'assigned'")->execute([$attempt['id']]);
        }
        $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM assessment_attempts WHERE id = ?");
        $st->execute([$attempt['id']]);
        $elapsed = max(0, (int)$st->fetchColumn());
        $remaining = max(0, (int)$a['minutes'] * 60 - $elapsed);
        $saved = asm_decode($attempt['answers']);
        $questions = asm_public_questions($a);
        $passages = asm_passages($a);
    } else {
        $mode = 'done';
    }
} elseif ($attempt_id > 0) {
    $mode = 'notfound';
}

// Nothing below writes the session; release the lock so autosave requests are not queued behind page loads.
session_write_close();

$titles = [];
if ($mode === 'list') {
    foreach ($attempts as $r) {
        $x = asm_load($r['assessment_id'], (int)$r['assessment_version']);
        $titles[$r['id']] = $x ? $x['title'] : $r['assessment_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Student – Assessment</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
<style>
  .asm-card { border-radius: 16px; }
  .asm-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; cursor: pointer; }
  .asm-opt input { margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0; accent-color: #6d46ff; }
  .asm-letter { font-weight: 700; min-width: 1.2em; }
  .asm-stem { white-space: pre-line; line-height: 1.7; }
  .asm-passage { white-space: pre-line; line-height: 1.8; }
  .asm-timer.is-low { color: #b91c1c; }
  body:not(.theme-light) .asm-timer.is-low { color: #fca5a5; }
</style>
</head>
<body class="flex min-h-screen">
<?php
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  include __DIR__ . '/components/sidebar.php';
?>
<main class="flex-1 min-w-0 p-6 lg:p-8">
<?php
  $header_config = [
      'page_title' => $a ? $a['title'] : 'Assessment',
      'page_subtitle' => $mode === 'take'
          ? 'Take your time and do your best. Your answers save as you go.'
          : 'Your Bloom assessment',
  ];
  include __DIR__ . '/components/header.php';
?>

<?php if ($mode === 'notfound' || $mode === 'missing'): ?>
  <div class="asm-card max-w-2xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-6 text-slate-700 dark:text-slate-200">
    <?= $mode === 'notfound'
        ? 'We could not find that assessment. Please ask your tutor for help.'
        : 'This assessment is not available right now. Please ask your tutor for help.' ?>
  </div>

<?php elseif ($mode === 'list'): ?>
  <div class="max-w-2xl space-y-3">
  <?php if (!$attempts): ?>
    <div class="asm-card bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-6 text-slate-700 dark:text-slate-200">
      You have no assessments right now.
    </div>
  <?php endif; ?>
  <?php foreach ($attempts as $r): $open = in_array($r['status'], ['assigned', 'in_progress'], true); ?>
    <a href="assessment.php?id=<?= (int)$r['id'] ?>" class="asm-card flex items-center justify-between gap-4 bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-5 hover:border-indigo-400">
      <span class="font-semibold text-slate-800 dark:text-slate-100"><?= h($titles[$r['id']]) ?></span>
      <span class="text-sm <?= $open ? 'text-indigo-700 dark:text-indigo-300 font-semibold' : 'text-slate-500 dark:text-slate-400' ?>">
        <?= $open ? ($r['status'] === 'assigned' ? 'Start' : 'Continue') : 'Submitted' ?>
      </span>
    </a>
  <?php endforeach; ?>
  </div>

<?php elseif ($mode === 'done'): ?>
  <div class="asm-card max-w-2xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-8 text-center">
    <svg class="w-12 h-12 mx-auto text-emerald-600 dark:text-emerald-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
    <h2 class="mt-4 text-2xl font-bold text-slate-800 dark:text-slate-100">All done<?= $first_name !== '' ? ', ' . h($first_name) : '' ?>!</h2>
    <p class="mt-2 text-slate-600 dark:text-slate-300">Thank you for working so hard. Your tutor will go through your answers with you now,
      and your parent or guardian will get a full report by email.</p>
  </div>

<?php else: /* take */ ?>
  <div class="max-w-3xl">
    <div class="sticky top-0 z-10 -mx-2 px-2 py-3 mb-4 bg-[var(--ks-bg)] dark:bg-[#262626]">
      <div class="flex items-center justify-between gap-4 text-sm">
        <span class="text-slate-600 dark:text-slate-300"><span id="asm-count">0</span> of <?= count($questions) ?> answered</span>
        <span class="flex items-center gap-3">
          <span id="asm-save" class="text-slate-500 dark:text-slate-400" aria-live="polite"></span>
          <span class="asm-timer font-semibold text-slate-800 dark:text-slate-100" id="asm-timer" aria-live="off"></span>
        </span>
      </div>
      <div class="mt-2 h-2 rounded-full bg-slate-200 dark:bg-white/10 overflow-hidden">
        <div id="asm-bar" class="h-full bg-indigo-600" style="width:0%"></div>
      </div>
      <div id="asm-timeup" class="hidden mt-2 text-sm font-semibold text-red-700 dark:text-red-300">
        Time is up. Finish the question you are on, then press Submit.
      </div>
    </div>

    <?php if (trim((string)($a['intro'] ?? '')) !== ''): ?>
      <p class="asm-card mb-4 bg-indigo-50 dark:bg-white/5 border border-indigo-200 dark:border-white/10 p-4 text-slate-700 dark:text-slate-200"><?= h((string)$a['intro']) ?></p>
    <?php endif; ?>

    <form id="asm-form" autocomplete="off" onsubmit="return false">
    <?php
      $shownPassages = [];
      foreach ($questions as $n => $q):
        $qid = $q['id'];
        $given = $saved[$qid] ?? null;
        if (isset($q['passage']) && isset($passages[$q['passage']]) && !isset($shownPassages[$q['passage']])):
          $shownPassages[$q['passage']] = true;
          $p = $passages[$q['passage']];
    ?>
      <section class="asm-card mb-4 bg-amber-50 dark:bg-white/5 border border-amber-200 dark:border-white/10 p-6">
        <?php if ($p['title'] !== ''): ?><h2 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-3"><?= h($p['title']) ?></h2><?php endif; ?>
        <div class="asm-passage text-slate-800 dark:text-slate-200"><?= h($p['text']) ?></div>
      </section>
    <?php endif; ?>
      <fieldset class="asm-q asm-card mb-4 bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-6" data-qid="<?= h($qid) ?>" data-type="<?= h($q['type']) ?>">
        <legend class="sr-only">Question <?= $n + 1 ?></legend>
        <div class="flex items-start justify-between gap-4 mb-3">
          <span class="text-sm font-semibold text-indigo-700 dark:text-indigo-300">Question <?= $n + 1 ?></span>
          <span class="text-xs text-slate-500 dark:text-slate-400"><?= (int)$q['marks'] ?> mark<?= $q['marks'] > 1 ? 's' : '' ?></span>
        </div>
        <?php if (isset($q['passage']) && isset($passages[$q['passage']]) && $passages[$q['passage']]['title'] !== ''): ?>
          <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">Use the text "<?= h($passages[$q['passage']]['title']) ?>".</p>
        <?php endif; ?>
        <div class="asm-stem text-slate-800 dark:text-slate-100 mb-4"><?= h($q['stem']) ?></div>

        <?php if ($q['type'] === 'mcq' || $q['type'] === 'multi'): $multi = $q['type'] === 'multi'; ?>
          <?php if ($multi): ?><p class="text-sm text-slate-500 dark:text-slate-400 mb-2">Choose all that are correct.</p><?php endif; ?>
          <div class="space-y-2">
          <?php foreach ($q['options'] as $i => $opt):
            $checked = $multi ? (is_array($given) && in_array($i, $given, true)) : ($given !== null && (int)$given === $i); ?>
            <label class="asm-opt border border-slate-200 dark:border-white/10 hover:bg-slate-50 dark:hover:bg-white/5 text-slate-800 dark:text-slate-100">
              <input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="q_<?= h($qid) ?><?= $multi ? '[]' : '' ?>" value="<?= $i ?>" <?= $checked ? 'checked' : '' ?>>
              <span class="asm-letter"><?= chr(65 + $i) ?>.</span>
              <span><?= h($opt) ?></span>
            </label>
          <?php endforeach; ?>
          </div>

        <?php elseif ($q['type'] === 'numeric' || $q['type'] === 'short'): $unit = $q['unit'] ?? ''; ?>
          <div class="flex items-center gap-2">
            <?php if ($unit === '£'): ?><span class="text-slate-700 dark:text-slate-200 font-semibold">£</span><?php endif; ?>
            <input type="text" name="q_<?= h($qid) ?>" value="<?= h(is_scalar($given) ? $given : '') ?>" maxlength="100"
                   <?= $q['type'] === 'numeric' ? 'inputmode="decimal"' : '' ?>
                   aria-label="Your answer to question <?= $n + 1 ?>"
                   class="w-full max-w-xs px-4 py-2.5 rounded-xl border border-slate-300 dark:border-white/15 bg-white dark:bg-white/5 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <?php if ($unit !== '' && $unit !== '£'): ?><span class="text-slate-700 dark:text-slate-200"><?= h($unit) ?></span><?php endif; ?>
          </div>

        <?php else: /* written */ ?>
          <textarea name="q_<?= h($qid) ?>" rows="10" maxlength="6000" aria-label="Your answer to question <?= $n + 1 ?>"
                    class="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-white/15 bg-white dark:bg-white/5 text-slate-800 dark:text-slate-100 leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= h(is_string($given) ? $given : '') ?></textarea>
        <?php endif; ?>
      </fieldset>
    <?php endforeach; ?>

      <div class="asm-card mt-6 mb-16 bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div>
          <p class="text-slate-600 dark:text-slate-300 text-sm" id="asm-left"></p>
          <p id="asm-error" role="alert" class="hidden mt-2 text-sm font-medium text-rose-700 dark:text-rose-300">
            We could not submit just now. Please check your internet and try again.
          </p>
        </div>
        <button type="button" id="asm-submit" class="px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold disabled:opacity-60">
          Submit my answers
        </button>
      </div>
    </form>
  </div>

  <script>
  (function () {
    const ATTEMPT = <?= (int)$attempt['id'] ?>;
    const CSRF = <?= json_encode($csrf) ?>;
    const TOTAL = <?= count($questions) ?>;
    let remaining = <?= (int)$remaining ?>;
    const form = document.getElementById('asm-form');
    const saveEl = document.getElementById('asm-save');
    const btn = document.getElementById('asm-submit');
    let dirty = false, saving = false, timer = null, done = false;

    function collect() {
      const out = {};
      form.querySelectorAll('.asm-q').forEach(fs => {
        const id = fs.dataset.qid, type = fs.dataset.type;
        if (type === 'mcq') {
          const c = fs.querySelector('input:checked');
          if (c) out[id] = parseInt(c.value, 10);
        } else if (type === 'multi') {
          const c = [...fs.querySelectorAll('input:checked')].map(i => parseInt(i.value, 10));
          if (c.length) out[id] = c;
        } else {
          const el = fs.querySelector('input,textarea');
          if (el && el.value.trim() !== '') out[id] = el.value;
        }
      });
      return out;
    }

    function progress() {
      const n = Object.keys(collect()).length;
      document.getElementById('asm-count').textContent = n;
      document.getElementById('asm-bar').style.width = Math.round(100 * n / TOTAL) + '%';
      const left = TOTAL - n;
      document.getElementById('asm-left').textContent = left > 0
        ? left + (left === 1 ? ' question is' : ' questions are') + ' still blank. That is fine if you are not sure.'
        : 'You have answered every question. Well done!';
    }

    async function send(action) {
      const res = await fetch('api/assessment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ csrf_token: CSRF, attempt_id: ATTEMPT, action, answers: collect() }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || 'Could not save');
      return data;
    }

    async function save() {
      if (saving || done) return;
      saving = true; dirty = false;
      saveEl.textContent = 'Saving...';
      try { await send('save'); saveEl.textContent = 'Saved'; }
      catch (e) { dirty = true; saveEl.textContent = 'Not saved yet, retrying'; setTimeout(save, 4000); }
      finally { saving = false; if (dirty) queue(); }
    }
    function queue() { dirty = true; clearTimeout(timer); timer = setTimeout(save, 800); }

    form.addEventListener('input', () => { progress(); queue(); });
    form.addEventListener('change', () => { progress(); queue(); });

    btn.addEventListener('click', async () => {
      const blank = TOTAL - Object.keys(collect()).length;
      const ok = await window.bloomConfirm({
        title: blank > 0 ? 'Submit with ' + blank + ' blank ' + (blank === 1 ? 'question' : 'questions') + '?' : 'Submit your answers?',
        message: 'You cannot change your answers after this.',
        ok: 'Submit', cancel: 'Keep working',
      });
      if (!ok) return;
      document.getElementById('asm-error').classList.add('hidden');
      btn.disabled = true; btn.textContent = 'Submitting...';
      clearTimeout(timer);
      try {
        await send('submit');
        done = true;
        location.reload();
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Submit my answers';
        document.getElementById('asm-error').classList.remove('hidden');
      }
    });

    const tEl = document.getElementById('asm-timer');
    function tick() {
      const m = Math.floor(remaining / 60), s = remaining % 60;
      tEl.textContent = remaining > 0 ? (m + ':' + String(s).padStart(2, '0') + ' left') : 'Time is up';
      tEl.classList.toggle('is-low', remaining <= 120);
      if (remaining <= 0) { document.getElementById('asm-timeup').classList.remove('hidden'); return; }
      remaining--;
      setTimeout(tick, 1000);
    }
    tick();
    progress();

    window.addEventListener('beforeunload', e => { if (dirty && !done) { save(); e.preventDefault(); e.returnValue = ''; } });
  })();
  </script>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../components/confirm_dialog.php'; ?>
</body>
</html>
