<?php
/**
 * admin/assessment_report.php?id=N
 * The admin's view of one demo assessment's report.
 *
 *   Submitted (waiting for the tutor): the admin can review the scores, mark any
 *   written answers, add a note, see the email exactly as it will go out, and
 *   approve and send it without waiting for the tutor.
 *   Approved: the email as sent, with a test copy to any address (which never
 *   counts as the parent's send).
 *
 * Approval goes through asm_approve_attempt(), the same function the tutor page
 * uses. The email is shown in a sandboxed iframe so its styles cannot leak.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

require_once __DIR__ . '/authz.php';
require_cap('lessons.write');
require_once __DIR__ . '/../utils/assessment_report.php';

$pdo = $AUTHZ_PDO;
$current_page = 'assessments';
$me = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? 0);
$d = $id > 0 ? asm_report_data($pdo, $id, true) : null;

$flash = $_SESSION['asm_report_flash'] ?? null;
unset($_SESSION['asm_report_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        $flash = ['err', 'That form had expired. Refresh the page and try again.'];
    } elseif (!$d) {
        $flash = ['err', 'This assessment has not been submitted yet.'];
    } elseif ($action === 'test') {
        $to = strtolower(trim((string)($_POST['to'] ?? '')));
        if ($d['row']['status'] !== 'approved') {
            $flash = ['err', 'Approve the report first, then you can send test copies.'];
        } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $flash = ['err', 'Enter a valid email address for the test copy.'];
        } else {
            session_write_close();
            [$ok, $msg] = asm_send_report($pdo, $id, $to);
            session_start();
            $flash = [$ok ? 'ok' : 'err', $ok ? "Test copy sent to $to. The parent's copy is not affected." : $msg];
        }
    } elseif ($action === 'save' || $action === 'approve') {
        $written = asm_merge_written($d['a'], asm_decode($d['row']['written_marks']), $_POST);
        $note = (string)($_POST['tutor_note'] ?? '');
        if ($d['row']['status'] !== 'submitted') {
            $flash = ['err', 'This report has already been approved.'];
        } elseif ($action === 'save') {
            $pdo->prepare("UPDATE assessment_attempts SET written_marks = ?, tutor_note = ? WHERE id = ? AND status = 'submitted'")
                ->execute([json_encode($written), trim(mb_substr($note, 0, 600)), $id]);
            $flash = ['ok', 'Saved. The preview below shows the updated email.'];
        } else {
            session_write_close();
            $r = asm_approve_attempt($pdo, $id, $written, $note, $me);
            session_start();
            $flash = [($r['result'] === 'approved' && !empty($r['sent'])) ? 'ok' : 'err',
                      $r['message'] . ($r['result'] === 'approved' && empty($r['sent']) ? ' Use Resend on Demo Assessments once it is fixed.' : '')];
        }
    } else {
        $flash = ['err', 'Unknown action.'];
    }
    $_SESSION['asm_report_flash'] = $flash;
    header('Location: assessment_report.php?id=' . $id);
    exit();
}

$mail = $d ? asm_report_email($d, true) : null;   // preview: ring inlined so the browser can show it
$isDraft = $d && $d['row']['status'] === 'submitted';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin: Report email</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.6rem .9rem}
    .card{background:#111827;border:1px solid #1f2937;border-radius:.75rem;padding:1.25rem}
    .tbl th,.tbl td{padding:.5rem .75rem;text-align:left;vertical-align:top}
    .pre{white-space:pre-line}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>
  <main class="flex-1 min-w-0 bg-gray-800 p-6 lg:p-8">
    <p class="mb-4"><a href="assessments.php" class="text-sm underline text-gray-300">Demo assessments</a></p>
    <h2 class="text-2xl font-bold text-white mb-1"><?= $isDraft ? 'Review and send report' : 'Report email' ?></h2>

    <?php if ($flash): ?>
      <div role="status" class="my-4 px-4 py-2 rounded-lg border <?= $flash[0] === 'ok' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-red-500/10 border-red-500/30 text-red-300' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <?php if (!$d): ?>
      <div class="card mt-4 text-gray-300">There is no report for this assessment yet. It appears here once the student submits it.</div>
    <?php else:
      $row = $d['row']; $res = $d['res']; $plan = ASM_PLANS[$d['plan']];
      $child = trim($row['s_first'] . ' ' . $row['s_last']); ?>

      <div class="card my-4 grid gap-4 lg:grid-cols-2">
        <div class="text-sm space-y-1">
          <p><span class="text-gray-400">Student:</span> <span class="text-gray-100"><?= h($child) ?></span>
             <span class="text-gray-400">with</span> <span class="text-gray-100"><?= h(trim($row['t_first'] . ' ' . $row['t_last'])) ?></span></p>
          <p><span class="text-gray-400">To:</span> <span class="text-gray-100"><?= h($row['parent_name'] ? $row['parent_name'] . ' <' . $row['parent_email'] . '>' : $row['parent_email']) ?></span></p>
          <p><span class="text-gray-400">Subject:</span> <span class="text-gray-100"><?= h($mail['subject']) ?></span></p>
          <p><span class="text-gray-400">Overall:</span> <span class="text-gray-100"><?= (int)$res['pct'] ?>%</span>
             <span class="text-gray-400">· Plan:</span> <span class="text-gray-100"><?= h($plan['name']) ?>, <?= (int)$plan['per_week'] ?> a week, &pound;<?= (int)$plan['price'] ?> every 30 days</span></p>
          <p><span class="text-gray-400">Family's aim:</span> <span class="text-gray-100"><?= h(ASM_PACES[$d['pace']]['label']) ?></span></p>
          <p><span class="text-gray-400">Status:</span>
            <?php if ($isDraft): ?><span class="text-amber-300">submitted, waiting for approval</span>
            <?php elseif ($row['report_sent_at']): ?><span class="text-emerald-300">approved and emailed <?= h(date('j M Y, g:i a', strtotime((string)$row['report_sent_at']))) ?></span>
            <?php elseif ($row['report_error']): ?><span class="text-red-300">approved, but the last send failed: <?= h($row['report_error']) ?></span>
            <?php else: ?><span class="text-amber-300">approved, not emailed yet</span><?php endif; ?></p>
          <p><span class="text-gray-400">Plan button:</span>
            <?= $d['link'] ? '<span class="text-gray-100">goes to the saved payment link</span>' : '<span class="text-amber-300">no payment link saved for this plan, so it asks the parent to reply</span>' ?></p>
        </div>

        <?php if (!$isDraft): ?>
          <form method="post" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="test">
            <div class="flex-1 min-w-[220px]"><label class="block text-xs text-gray-400 mb-1" for="to">Send a test copy to</label>
              <input class="form-input w-full" id="to" name="to" type="email" required placeholder="you@example.com"></div>
            <button class="px-4 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Send test copy</button>
          </form>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="tbl w-full text-sm">
              <thead class="text-gray-400 border-b border-gray-700"><tr><th>Skill</th><th>Score</th><th>Band</th></tr></thead>
              <tbody>
              <?php foreach ($res['skills'] as $s): ?>
                <tr class="border-b border-gray-700/50"><td class="text-gray-100"><?= h($s['name']) ?></td>
                  <td><?= (int)$s['earned'] ?>/<?= (int)$s['available'] ?> (<?= (int)$s['pct'] ?>%)<?= $s['pending'] ? ' <span class="text-amber-300">+ to mark</span>' : '' ?></td>
                  <td><?= h(ASM_BAND_EMAIL[$s['band']][0] ?? $s['band']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isDraft): ?>
        <form method="post" class="card mb-4 space-y-4">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <?php foreach ($d['a']['questions'] as $n => $q):
            if ($q['type'] !== 'written') continue;
            $given = asm_decode($row['answers'])[$q['id']] ?? null;
            $wm = asm_decode($row['written_marks'])[$q['id']] ?? null; ?>
            <div>
              <p class="text-sm font-semibold text-white">Question <?= $n + 1 ?> (written, <?= (int)$q['marks'] ?> marks)</p>
              <p class="pre text-sm text-gray-300 mb-2"><?= h($q['stem']) ?></p>
              <?php if ($given === null): ?>
                <p class="text-sm italic text-gray-400">No answer given, so it scores 0 and needs no marking.</p>
              <?php else: ?>
                <div class="pre text-sm text-gray-100 bg-gray-900/60 rounded-lg p-3 mb-2"><?= h($given) ?></div>
                <ul class="list-disc pl-5 text-sm text-gray-400 mb-2">
                  <?php foreach ((array)$q['rubric'] as $r): ?><li><?= (int)$r['marks'] ?> mark<?= $r['marks'] > 1 ? 's' : '' ?>: <?= h($r['criterion']) ?></li><?php endforeach; ?>
                </ul>
                <label class="text-sm text-gray-300">Marks awarded
                  <input type="number" name="wm_<?= h($q['id']) ?>" min="0" max="<?= (int)$q['marks'] ?>" step="1" value="<?= $wm !== null ? (int)$wm : '' ?>" class="form-input w-20 ml-2"> out of <?= (int)$q['marks'] ?></label>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div><label class="block text-xs text-gray-400 mb-1" for="tutor_note">A personal note for the parent (optional, shown as a note from the tutor)</label>
            <textarea id="tutor_note" name="tutor_note" rows="3" maxlength="600" class="form-input w-full"><?= h($row['tutor_note'] ?? '') ?></textarea></div>

          <div class="flex flex-wrap gap-3">
            <button type="submit" name="action" value="save" class="px-4 py-2.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white font-semibold">Save and update preview</button>
            <button type="submit" name="action" value="approve" class="px-4 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold"
                    data-confirm-title="Approve and send this report?"
                    data-confirm="<?= h('It goes to ' . $row['parent_email'] . ' straight away, instead of waiting for the tutor. It cannot be changed afterwards.') ?>"
                    data-confirm-ok="Approve and send">Approve and send to parent</button>
          </div>
          <?php if ($res['pending'] > 0): ?><p class="text-sm text-amber-300">Mark the written answer<?= $res['pending'] > 1 ? 's' : '' ?> above before approving.</p><?php endif; ?>
        </form>
      <?php endif; ?>

      <p class="text-xs text-gray-400 mb-2"><?= $isDraft ? 'Preview: the email as it will be sent when you approve.' : 'The email as sent.' ?></p>
      <iframe title="Report email preview" sandbox="" class="w-full rounded-lg bg-white" style="height:1600px;border:0;" srcdoc="<?= htmlspecialchars($mail['html'], ENT_QUOTES, 'UTF-8') ?>"></iframe>
    <?php endif; ?>
  </main>
  <?php $confirm_force_dark = true; include __DIR__ . '/../components/confirm_dialog.php'; ?>
</body>
</html>
