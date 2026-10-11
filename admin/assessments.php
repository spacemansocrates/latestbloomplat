<?php
/**
 * admin/assessments.php
 * Set up a demo: create (or reuse) the student's portal account, assign a tutor,
 * and assign the diagnostic assessment they take in the free session. Also lists
 * every attempt so admins can see where each demo family is.
 *
 * The temporary password is shown once, on the page that created it, and is never
 * stored anywhere but as a bcrypt hash.
 *
 * Capability: lessons.write. Demos are scheduling work, which Mini Admins do too.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

require_once __DIR__ . '/authz.php';
require_cap('lessons.write');
require_once __DIR__ . '/../utils/assessment.php';
require_once __DIR__ . '/../utils/assessment_report.php';
require_once __DIR__ . '/../utils/phone.php';

$pdo = $AUTHZ_PDO;
$current_page = 'assessments';
$me = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

const ASM_EXAM_BOARDS = ['AQA', 'Edexcel', 'OCR', 'WJEC', 'Cambridge IGCSE', 'Edexcel IGCSE', 'Other'];
const ASM_PORTAL_URL  = 'https://portal.bloomeducationonline.com';

function asm_table_ready(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM assessment_attempts LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

/** A readable temporary password, e.g. "Maple-4821-Comet". */
function asm_temp_password(): string {
    $w = ['Maple', 'River', 'Cloud', 'Lemon', 'Tiger', 'Pearl', 'Orbit', 'Comet', 'Cedar', 'Otter',
          'Robin', 'Amber', 'Flint', 'Hazel', 'Storm', 'Willow', 'Spark', 'Coral', 'Birch', 'Lark'];
    return $w[random_int(0, count($w) - 1)] . '-' . random_int(1000, 9999) . '-' . $w[random_int(0, count($w) - 1)];
}

/**
 * Demo families are UK parents, but utils/phone.php reads a leading 0 as Malawi
 * (the school default). Rewrite UK trunk numbers to +44 first so a parent's
 * 07700 900123 does not become a Malawian number.
 */
function asm_parent_phone(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^0(?!0)/', $raw)) $raw = '+44' . substr(preg_replace('/\D/', '', $raw), 1);
    $pr = phone_to_e164($raw);
    if (!$pr['ok']) throw new RuntimeException('Parent phone: ' . phone_reason_text($pr['reason']));
    return $pr['e164'];
}

$ready = asm_table_ready($pdo);
$bank = asm_list();
$tutors = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'Tutor' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

$flash = $_SESSION['asm_admin_flash'] ?? ['ok' => [], 'err' => [], 'created' => null];
unset($_SESSION['asm_admin_flash']);
$old = $_SESSION['asm_admin_old'] ?? [];
unset($_SESSION['asm_admin_old']);

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $out = ['ok' => [], 'err' => [], 'created' => null];
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new RuntimeException('That form had expired. Refresh the page and try again.');
        }
        if (!$ready) throw new RuntimeException('Run migrations/2026_09_24_assessments.sql first.');

        if ($action === 'create') {
            $first   = trim((string)($_POST['first_name'] ?? ''));
            $last    = trim((string)($_POST['last_name'] ?? ''));
            $login   = strtolower(trim((string)($_POST['login_email'] ?? '')));
            $pName   = trim((string)($_POST['parent_name'] ?? ''));
            $pEmail  = strtolower(trim((string)($_POST['parent_email'] ?? '')));
            $pPhone  = (string)($_POST['parent_phone'] ?? '');
            $asmId   = (string)($_POST['assessment'] ?? '');
            $tutorId = (int)($_POST['tutor_id'] ?? 0);
            $board   = (string)($_POST['exam_board'] ?? '');
            $pace    = (string)($_POST['pace'] ?? '');
            if ($login === '') $login = $pEmail;

            $_SESSION['asm_admin_old'] = $_POST;   // refill the form if anything below fails
            unset($_SESSION['asm_admin_old']['csrf']);

            if ($first === '' || $last === '') throw new RuntimeException("Enter the student's first and last name.");
            if (!filter_var($pEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Enter the parent's email address. The report is sent there.");
            if (!filter_var($login, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The student login email is not a valid address.');
            if (!isset($bank[$asmId])) throw new RuntimeException('Choose an assessment.');
            if ($board !== '' && !in_array($board, ASM_EXAM_BOARDS, true)) throw new RuntimeException('Choose an exam board from the list.');
            if (!isset(ASM_PACES[$pace])) throw new RuntimeException('Choose how fast the family wants to see progress. It decides the recommended plan.');
            $tutorIds = array_map(fn($t) => (int)$t['id'], $tutors);
            if (!in_array($tutorId, $tutorIds, true)) throw new RuntimeException('Choose the tutor who will run the demo.');
            $phone = asm_parent_phone($pPhone);

            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id, first_name, last_name, role FROM users WHERE email = ? FOR UPDATE');
            $st->execute([$login]);
            $existing = $st->fetch();

            $tempPassword = null;
            if ($existing) {
                // Reuse only when it is clearly the same child. Siblings often share a
                // parent's email, and attaching a test to the brother's account would
                // email his parent a report about the wrong child.
                $same = $existing['role'] === 'Student'
                    && strcasecmp(trim($existing['first_name']), $first) === 0
                    && strcasecmp(trim($existing['last_name']), $last) === 0;
                if (!$same) {
                    $who = $existing['role'] === 'Student' ? trim($existing['first_name'] . ' ' . $existing['last_name']) : 'another account';
                    throw new RuntimeException("The login email $login already belongs to $who. Give this child their own login email.");
                }
                $studentId = (int)$existing['id'];
            } else {
                $tempPassword = asm_temp_password();
                // Same columns admin_users.php writes, which production's strict mode
                // accepts. Demo families are in the UK, hence GBP and Europe/London.
                $pdo->prepare("INSERT INTO users
                        (first_name, last_name, email, password_hash, phone_number, customer_code, role,
                         currency_code, timezone, guardian_name, guardian_phone, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NULL, '', 'Student', 'GBP', 'Europe/London', ?, ?, 1, NOW(), NOW())")
                    ->execute([$first, $last, $login, password_hash($tempPassword, PASSWORD_DEFAULT), $pName !== '' ? $pName : null, $phone]);
                $studentId = (int)$pdo->lastInsertId();
            }

            // The tutor needs an active assignment to see the student anywhere else in the portal.
            $st = $pdo->prepare('SELECT id FROM tutor_student_assignments WHERE tutor_id = ? AND student_id = ?');
            $st->execute([$tutorId, $studentId]);
            if ($aid = $st->fetchColumn()) {
                $pdo->prepare('UPDATE tutor_student_assignments SET end_date = NULL WHERE id = ?')->execute([$aid]);
            } else {
                $pdo->prepare('INSERT INTO tutor_student_assignments (tutor_id, student_id, assigned_by_admin_id, start_date, end_date)
                               VALUES (?, ?, ?, CURDATE(), NULL)')->execute([$tutorId, $studentId, $me]);
            }

            $pdo->prepare("INSERT INTO assessment_attempts
                    (student_id, tutor_id, assessment_id, assessment_version, status, parent_name, parent_email, exam_board, exam_soon, pace, assigned_by)
                VALUES (?, ?, ?, ?, 'assigned', ?, ?, ?, ?, ?, ?)")
                ->execute([$studentId, $tutorId, $asmId, $bank[$asmId]['version'], $pName !== '' ? $pName : null, $pEmail,
                           $board !== '' ? $board : null, $pace === 'fast' ? 1 : 0, $pace, $me]);
            $attemptId = (int)$pdo->lastInsertId();
            $pdo->commit();
            unset($_SESSION['asm_admin_old']);

            $tutorName = '';
            foreach ($tutors as $t) if ((int)$t['id'] === $tutorId) $tutorName = trim($t['first_name'] . ' ' . $t['last_name']);
            $out['created'] = [
                'student'   => "$first $last",
                'login'     => $login,
                'password'  => $tempPassword,
                'reused'    => $existing ? true : false,
                'tutor'     => $tutorName,
                'title'     => $bank[$asmId]['title'],
                'link'      => ASM_PORTAL_URL . '/student/assessment.php?id=' . $attemptId,
            ];
        } elseif ($action === 'send_report') {
            $id = (int)($_POST['attempt_id'] ?? 0);
            session_write_close();   // do not hold the session lock while talking to the mail server
            [$sent, $msg] = asm_send_report($pdo, $id);
            session_start();
            if (!$sent) throw new RuntimeException($msg);
            $out['ok'][] = $msg;
        } elseif ($action === 'plan_links') {
            if (!can('settings.payments')) throw new RuntimeException('Only a full admin can change payment links.');
            $put = $pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $del = $pdo->prepare('DELETE FROM app_settings WHERE `key` = ?');
            foreach (array_keys(ASM_PLANS) as $plan) {
                $url = trim((string)($_POST['link_' . $plan] ?? ''));
                if ($url === '') { $del->execute(['assessment.plan_link.' . $plan]); continue; }
                if (!preg_match('#^https://[^\s<>"]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('The ' . ASM_PLANS[$plan]['name'] . ' link must be a full https:// address, like https://buy.stripe.com/...');
                }
                $put->execute(['assessment.plan_link.' . $plan, $url]);
            }
            $out['ok'][] = 'Payment links saved. New report emails will use them.';
        } elseif ($action === 'remove') {
            $id = (int)($_POST['attempt_id'] ?? 0);
            $del = $pdo->prepare("DELETE FROM assessment_attempts WHERE id = ? AND status IN ('assigned','in_progress')");
            $del->execute([$id]);
            if ($del->rowCount() !== 1) throw new RuntimeException('Only an assessment that has not been submitted can be removed.');
            $out['ok'][] = 'Assessment removed. The student account was kept.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $out['err'][] = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Nothing was saved.';
        if (!($e instanceof RuntimeException)) error_log('[admin assessments] ' . $e->getMessage());
    }
    $_SESSION['asm_admin_flash'] = $out;
    header('Location: assessments.php');
    exit();
}

// ---------------- GET ----------------
$rows = [];
if ($ready) {
    $rows = $pdo->query("
        SELECT aa.id, aa.assessment_id, aa.assessment_version, aa.status, aa.parent_email, aa.exam_board,
               aa.created_at, aa.submitted_at, aa.approved_at, aa.report_sent_at, aa.report_error, aa.final_plan, aa.pace,
               s.first_name AS s_first, s.last_name AS s_last, s.email AS s_email,
               t.first_name AS t_first, t.last_name AS t_last
        FROM assessment_attempts aa
        LEFT JOIN users s ON s.id = aa.student_id
        LEFT JOIN users t ON t.id = aa.tutor_id
        ORDER BY aa.id DESC
        LIMIT 300")->fetchAll();
}
$status_label = [
    'assigned'    => ['Not started', 'text-gray-300'],
    'in_progress' => ['In progress', 'text-sky-300'],
    'submitted'   => ['Waiting for approval', 'text-amber-300'],
    'approved'    => ['Approved', 'text-emerald-300'],
];
$v = static fn(string $k, string $d = ''): string => h((string)($old[$k] ?? $d));
$canLinks = can('settings.payments');
$links = [];
foreach (array_keys(ASM_PLANS) as $plan) $links[$plan] = (string)asm_plan_link($pdo, $plan);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin: Demo assessments</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.65rem .9rem;width:100%}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px #4f46e5}
    .card{background:#111827;border:1px solid #1f2937;border-radius:.75rem;padding:1.25rem}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    label.lbl{display:block;font-size:.8rem;color:#9ca3af;margin-bottom:.3rem}
    .tbl th,.tbl td{padding:.6rem .75rem;text-align:left;vertical-align:top}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 min-w-0 bg-gray-800 p-6 lg:p-8">
    <header class="mb-6">
      <h2 class="text-2xl font-bold text-white">Demo assessments</h2>
      <p class="text-gray-400">Set up a free demo: the student's account, their tutor, and the assessment they take in the session.</p>
    </header>

    <?php if (!$ready): ?>
      <div class="mb-6 bg-red-500/10 border border-red-500/30 text-red-200 px-4 py-3 rounded-lg">
        The database update for this page has not been run. Run <code class="mono">migrations/2026_09_24_assessments.sql</code>, then reload.
      </div>
    <?php endif; ?>
    <?php if (!$bank): ?>
      <div class="mb-6 bg-amber-500/10 border border-amber-500/30 text-amber-200 px-4 py-3 rounded-lg">
        There are no assessments in <code class="mono">assessments/bank/</code> yet, so nothing can be assigned.
      </div>
    <?php endif; ?>
    <?php foreach ($flash['ok'] as $m): ?>
      <div class="mb-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2 rounded-lg"><?= h($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($flash['err'] as $m): ?>
      <div class="mb-3 bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2 rounded-lg" role="alert"><?= h($m) ?></div>
    <?php endforeach; ?>

    <?php if ($c = $flash['created']): ?>
      <section class="card mb-8 border-emerald-500/40" aria-labelledby="created-title">
        <h3 id="created-title" class="text-lg font-semibold text-emerald-300 mb-1">Demo set up for <?= h($c['student']) ?></h3>
        <p class="text-sm text-gray-400 mb-4"><?= h($c['title']) ?> with <?= h($c['tutor']) ?>.
          <?= $c['reused'] ? 'They already had a portal account, so it was reused and their password was not changed.' : 'A new portal account was created.' ?></p>
        <div class="grid gap-3 sm:grid-cols-2 max-w-3xl [&>div]:min-w-0" id="login-box">
          <div><p class="text-xs text-gray-400">Login page</p><p class="mono text-gray-100 break-all"><?= h(ASM_PORTAL_URL) ?>/login.php</p></div>
          <div><p class="text-xs text-gray-400">Assessment link</p><p class="mono text-gray-100 break-all"><?= h($c['link']) ?></p></div>
          <div><p class="text-xs text-gray-400">Login email</p><p class="mono text-gray-100 break-all"><?= h($c['login']) ?></p></div>
          <?php if ($c['password']): ?>
            <div><p class="text-xs text-gray-400">Temporary password</p><p class="mono text-gray-100 text-lg" id="temp-pw"><?= h($c['password']) ?></p></div>
          <?php endif; ?>
        </div>
        <?php if ($c['password']): ?>
          <p class="mt-4 text-sm text-amber-200">Copy these now and send them to the family before the session. The password is shown only this once.</p>
        <?php endif; ?>
        <button type="button" id="copy-login" class="mt-4 px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-100 text-sm">Copy login details</button>
      </section>
      <script>
        document.getElementById('copy-login').addEventListener('click', function () {
          const text = <?= json_encode("Bloom login for {$c['student']}\nLogin page: " . ASM_PORTAL_URL . "/login.php\nEmail: {$c['login']}" . ($c['password'] ? "\nTemporary password: {$c['password']}" : '') . "\nAssessment: {$c['link']}", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          navigator.clipboard.writeText(text).then(() => { this.textContent = 'Copied'; });
        });
      </script>
    <?php endif; ?>

    <div class="max-w-6xl space-y-8">
      <section class="card" aria-labelledby="new-title">
        <h3 id="new-title" class="text-lg font-semibold text-white mb-4">New demo</h3>
        <form method="post" class="grid gap-4 md:grid-cols-2">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="create">

          <div><label class="lbl" for="first_name">Student first name</label>
            <input class="form-input" id="first_name" name="first_name" required maxlength="100" value="<?= $v('first_name') ?>"></div>
          <div><label class="lbl" for="last_name">Student last name</label>
            <input class="form-input" id="last_name" name="last_name" required maxlength="100" value="<?= $v('last_name') ?>"></div>

          <div><label class="lbl" for="parent_name">Parent or guardian name</label>
            <input class="form-input" id="parent_name" name="parent_name" maxlength="120" value="<?= $v('parent_name') ?>"></div>
          <div><label class="lbl" for="parent_email">Parent email (the report goes here)</label>
            <input class="form-input" id="parent_email" name="parent_email" type="email" required maxlength="190" value="<?= $v('parent_email') ?>"></div>

          <div><label class="lbl" for="parent_phone">Parent phone (optional)</label>
            <input class="form-input" id="parent_phone" name="parent_phone" maxlength="40" placeholder="07700 900123" value="<?= $v('parent_phone') ?>"></div>
          <div><label class="lbl" for="login_email">Student login email (leave blank to use the parent email)</label>
            <input class="form-input" id="login_email" name="login_email" type="email" maxlength="190" value="<?= $v('login_email') ?>">
            <p class="text-xs text-gray-500 mt-1">Brothers and sisters each need their own login email.</p></div>

          <div><label class="lbl" for="assessment">Assessment</label>
            <select class="form-input" id="assessment" name="assessment" required>
              <option value="">Choose...</option>
              <?php foreach ($bank as $b): ?>
                <option value="<?= h($b['id']) ?>" <?= ($old['assessment'] ?? '') === $b['id'] ? 'selected' : '' ?>><?= h($b['title']) ?> (<?= (int)$b['minutes'] ?> min)</option>
              <?php endforeach; ?>
            </select></div>
          <div><label class="lbl" for="tutor_id">Tutor running the demo</label>
            <select class="form-input" id="tutor_id" name="tutor_id" required>
              <option value="">Choose...</option>
              <?php foreach ($tutors as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)($old['tutor_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h(trim($t['first_name'] . ' ' . $t['last_name'])) ?></option>
              <?php endforeach; ?>
            </select></div>

          <div><label class="lbl" for="exam_board">Exam board (GCSE and IGCSE)</label>
            <select class="form-input" id="exam_board" name="exam_board">
              <option value="">Not applicable or not known</option>
              <?php foreach (ASM_EXAM_BOARDS as $eb): ?>
                <option <?= ($old['exam_board'] ?? '') === $eb ? 'selected' : '' ?>><?= h($eb) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label class="lbl" for="pace">How fast does the family want to see progress?</label>
            <select class="form-input" id="pace" name="pace" required>
              <option value="">Choose...</option>
              <?php foreach (ASM_PACES as $pk => $pv): ?>
                <option value="<?= h($pk) ?>" <?= ($old['pace'] ?? '') === $pk ? 'selected' : '' ?>><?= h($pv['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">With the score, this sets the plan in the report. Ask the parent when you book the demo.</p></div>

          <div class="md:col-span-2">
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold" <?= !$ready || !$bank ? 'disabled' : '' ?>>Set up demo</button>
          </div>
        </form>
      </section>

      <section class="card overflow-x-auto" aria-labelledby="all-title">
        <h3 id="all-title" class="text-lg font-semibold text-white mb-4">All demo assessments</h3>
        <?php if (!$rows): ?>
          <p class="text-gray-400">None yet.</p>
        <?php else: ?>
        <table class="tbl w-full text-sm">
          <thead class="text-gray-400 border-b border-gray-700">
            <tr><th>Student</th><th>Assessment</th><th>Tutor</th><th>Parent email</th><th>Status</th><th>Set up</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $x = asm_load($r['assessment_id'], (int)$r['assessment_version']);
            [$sl, $sc] = $status_label[$r['status']] ?? [$r['status'], 'text-gray-300']; ?>
            <tr class="border-b border-gray-700/50">
              <td class="text-gray-100"><?= h(trim($r['s_first'] . ' ' . $r['s_last']) ?: 'Deleted student') ?><div class="text-xs text-gray-500 mono"><?= h($r['s_email']) ?></div></td>
              <td><?= h($x ? $x['title'] : $r['assessment_id']) ?><?= $r['exam_board'] ? '<div class="text-xs text-gray-500">' . h($r['exam_board']) . '</div>' : '' ?></td>
              <td><?= h(trim($r['t_first'] . ' ' . $r['t_last'])) ?></td>
              <td class="mono text-xs"><?= h($r['parent_email']) ?></td>
              <td class="<?= $sc ?>"><?= h($sl) ?>
                <?php if ($r['status'] === 'approved'): ?>
                  <div class="text-xs text-gray-400"><?= $r['final_plan'] && isset(ASM_PLANS[$r['final_plan']]) ? h(ASM_PLANS[$r['final_plan']]['name']) . ' plan' : '' ?></div>
                  <?php if ($r['report_sent_at']): ?>
                    <div class="text-xs text-emerald-300">Report emailed <?= h(date('j M, g:i a', strtotime((string)$r['report_sent_at']))) ?></div>
                  <?php elseif ($r['report_error']): ?>
                    <div class="text-xs text-red-300" title="<?= h($r['report_error']) ?>">Email failed: <?= h(mb_strimwidth((string)$r['report_error'], 0, 70, '...')) ?></div>
                  <?php else: ?>
                    <div class="text-xs text-amber-300">Report not emailed yet</div>
                  <?php endif; ?>
                <?php endif; ?></td>
              <td class="text-xs text-gray-400"><?= h(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
              <td class="text-right whitespace-nowrap">
                <?php if (in_array($r['status'], ['assigned', 'in_progress'], true)): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
                    <button class="text-xs text-red-300 hover:text-red-200 underline"
                            data-confirm-title="Remove this assessment?" data-confirm="The student account stays, so you can assign a new assessment later."
                            data-confirm-ok="Remove" data-confirm-danger="1">Remove</button>
                  </form>
                <?php elseif ($r['status'] === 'submitted'): ?>
                  <a href="assessment_report.php?id=<?= (int)$r['id'] ?>" class="text-xs px-2.5 py-1.5 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white font-semibold no-underline">Review and send</a>
                <?php elseif ($r['status'] === 'approved'): ?>
                  <a href="assessment_report.php?id=<?= (int)$r['id'] ?>" class="text-xs text-indigo-300 hover:text-indigo-200 underline">Preview email</a>
                  <form method="post" class="inline ml-3">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="send_report">
                    <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
                    <button class="text-xs text-gray-300 hover:text-white underline"
                            data-confirm-title="<?= $r['report_sent_at'] ? 'Send the report again?' : 'Send the report now?' ?>"
                            data-confirm="<?= h('It goes to ' . $r['parent_email'] . '.') ?>"
                            data-confirm-ok="Send"><?= $r['report_sent_at'] ? 'Resend' : 'Send now' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>

      <?php if ($canLinks): ?>
      <section class="card" aria-labelledby="links-title">
        <h3 id="links-title" class="text-lg font-semibold text-white mb-1">Payment links in the report email</h3>
        <p class="text-sm text-gray-400 mb-4">Paste the Stripe payment link for each plan. The report's button takes the parent straight to the link for the plan we recommend.
          Without a link, the button asks the parent to reply to the email instead.</p>
        <form method="post" class="grid gap-4 md:grid-cols-2">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="plan_links">
          <?php foreach (ASM_PLANS as $pk => $pv): ?>
            <div><label class="lbl" for="link_<?= h($pk) ?>"><?= h($pv['name']) ?>: <?= (int)$pv['per_week'] ?> a week, &pound;<?= (int)$pv['price'] ?> every 30 days</label>
              <input class="form-input mono text-sm" id="link_<?= h($pk) ?>" name="link_<?= h($pk) ?>" type="url" placeholder="https://buy.stripe.com/..." value="<?= h($links[$pk]) ?>"></div>
          <?php endforeach; ?>
          <div class="md:col-span-2"><button type="submit" class="px-5 py-2.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white font-semibold">Save payment links</button></div>
        </form>
      </section>
      <?php endif; ?>
    </div>
  </main>
  <?php $confirm_force_dark = true; include __DIR__ . '/../components/confirm_dialog.php'; ?>
</body>
</html>
