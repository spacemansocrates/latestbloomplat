<?php
// tutor/assessments.php
//
// The tutor reviews a submitted diagnostic assessment: sees every answer against
// the key, marks any written answers, checks the parent-facing wording, and
// approves. Approving emails the report to the parent straight away.
//
// The plan is NOT the tutor's choice: it comes from the overall score and the pace
// the family asked for (asm_recommend_plan). Tutors see the plan name, never prices.

require_once 'auth_tutor_only.php';
$current_page = 'assessments';

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/assessment.php';
require_once __DIR__ . '/../utils/assessment_report.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function asm_csrf_ok(): bool
{
    return is_string($_POST['csrf_token'] ?? null) && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $_POST['csrf_token']);
}

function asm_band_pill(string $band): string
{
    $map = [
        'strong'     => ['Strong', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'],
        'developing' => ['Developing', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
        'gap'        => ['Gap', 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300'],
    ];
    [$label, $cls] = $map[$band] ?? [$band, ''];
    return '<span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold ' . $cls . '">' . h($label) . '</span>';
}

$attempt_id = (int)($_GET['id'] ?? 0);
$flash = $_SESSION['asm_flash'] ?? null;
unset($_SESSION['asm_flash']);

$attempt = null;
$a = null;
if ($attempt_id > 0) {
    $st = $pdo->prepare("SELECT aa.*, u.first_name, u.last_name
                         FROM assessment_attempts aa
                         LEFT JOIN users u ON u.id = aa.student_id
                         WHERE aa.id = ? AND aa.tutor_id = ?");
    $st->execute([$attempt_id, $tutor_id]);
    $attempt = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($attempt) $a = asm_load($attempt['assessment_id'], (int)$attempt['assessment_version']);
}

// ---- POST: mark written answers, or approve ------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'assessments.php' . ($attempt ? '?id=' . (int)$attempt['id'] : '');
    if (!asm_csrf_ok()) {
        $_SESSION['asm_flash'] = ['error', 'That form had expired. Please try again.'];
        header("Location: $back");
        exit();
    }
    if (!$attempt || !$a) {
        $_SESSION['asm_flash'] = ['error', 'Assessment not found.'];
        header('Location: assessments.php');
        exit();
    }
    if ($attempt['status'] !== 'submitted') {
        $_SESSION['asm_flash'] = ['error', 'This assessment can only be changed after the student submits it and before it is approved.'];
        header("Location: $back");
        exit();
    }

    $written = asm_merge_written($a, asm_decode($attempt['written_marks']), $_POST);
    $action = (string)($_POST['action'] ?? '');
    $note = trim(mb_substr((string)($_POST['tutor_note'] ?? ''), 0, 600));

    if ($action === 'approve') {
        // Any final_plan in the POST is ignored on purpose: the plan follows the rule.
        session_write_close();   // do not hold the session lock while talking to the mail server
        $r = asm_approve_attempt($pdo, (int)$attempt['id'], $written, $note, $tutor_id);
        session_start();
        $_SESSION['asm_flash'] = ($r['result'] === 'approved' && !empty($r['sent']))
            ? ['ok', $r['message']]
            : ['error', $r['message'] . ($r['result'] === 'approved' ? ' An admin can resend it from Demo Assessments.' : '')];
    } else {
        $pdo->prepare("UPDATE assessment_attempts SET written_marks = ?, tutor_note = ? WHERE id = ? AND status = 'submitted'")
            ->execute([json_encode($written), $note, $attempt['id']]);
        $_SESSION['asm_flash'] = ['ok', 'Marks saved.'];
    }
    header("Location: $back");
    exit();
}

// ---- GET: list, or one attempt ---------------------------------------------------
$rows = [];
if (!$attempt) {
    $st = $pdo->prepare("SELECT aa.id, aa.assessment_id, aa.assessment_version, aa.status, aa.submitted_at, aa.approved_at, aa.created_at,
                                u.first_name, u.last_name
                         FROM assessment_attempts aa
                         LEFT JOIN users u ON u.id = aa.student_id
                         WHERE aa.tutor_id = ?
                         ORDER BY FIELD(aa.status,'submitted','in_progress','assigned','approved'), aa.id DESC
                         LIMIT 200");
    $st->execute([$tutor_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($attempt && $a) {
    $answers = asm_decode($attempt['answers']);
    $written = asm_decode($attempt['written_marks']);
    $res = $attempt['status'] === 'approved' && $attempt['results']
        ? asm_decode($attempt['results'])
        : asm_results($a, $answers, $written, isset(ASM_PACES[$attempt['pace'] ?? '']) ? $attempt['pace'] : ASM_DEFAULT_PACE);
    $defs = asm_skill_defs($a);
    $passages = asm_passages($a);
    $student_first = trim((string)$attempt['first_name']);
    $plan_key = $attempt['status'] === 'approved' && isset(ASM_PLANS[$attempt['final_plan']]) ? $attempt['final_plan'] : $res['recommended_plan'];
    $plan_reason = asm_plan_reason((int)$res['pct'], $res['pace'] ?? ASM_DEFAULT_PACE, $student_first);
    $editable = $attempt['status'] === 'submitted';
}

$status_label = [
    'assigned'    => 'Not started',
    'in_progress' => 'In progress',
    'submitted'   => 'Ready to review',
    'approved'    => 'Approved',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutor Dashboard - Assessments</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <link rel="stylesheet" href="css/theme.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .asm-pre { white-space: pre-line; }
        .asm-table th, .asm-table td { padding: 10px 12px; text-align: left; vertical-align: top; }
    </style>
</head>
<body class="flex min-h-screen">
<?php
    if (!defined('PAGE_LOADER_INCLUDED')) {
        define('PAGE_LOADER_INCLUDED', true);
        include __DIR__ . '/../components/page_loader.php';
    }
    include 'components/sidebar.php';
?>
<main class="flex-1 min-w-0 p-6 lg:p-8">
<?php
    $header_title = 'Assessments';
    $header_subtitle = 'Review demo assessments, mark written answers, and approve the report for the parent.';
    include 'components/header.php';
?>

<?php if ($flash): ?>
    <div role="status" class="mb-6 px-4 py-3 rounded-lg <?= $flash[0] === 'ok'
        ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-200'
        : 'bg-rose-100 text-rose-900 dark:bg-rose-500/15 dark:text-rose-200' ?>"><?= h($flash[1]) ?></div>
<?php endif; ?>

<?php if ($attempt_id > 0 && (!$attempt || !$a)): ?>
    <div class="card-base p-6 rounded-lg text-slate-700 dark:text-slate-200">
        <?= !$attempt ? 'Assessment not found, or it is assigned to another tutor.' : 'The question bank file for this assessment is missing.' ?>
        <a href="assessments.php" class="underline ml-1">Back to all assessments</a>
    </div>

<?php elseif (!$attempt): ?>
    <div class="card-base rounded-lg overflow-x-auto">
    <?php if (!$rows): ?>
        <p class="p-6 text-slate-600 dark:text-slate-300">No assessments assigned to you yet.</p>
    <?php else: ?>
        <table class="asm-table w-full text-sm">
            <thead class="text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-white/10">
                <tr><th>Student</th><th>Assessment</th><th>Status</th><th>Submitted</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $x = asm_load($r['assessment_id'], (int)$r['assessment_version']);
                $name = trim($r['first_name'] . ' ' . $r['last_name']) ?: 'Unknown student'; ?>
                <tr class="border-b border-slate-100 dark:border-white/5 text-slate-800 dark:text-slate-100">
                    <td class="font-medium"><?= h($name) ?></td>
                    <td><?= h($x ? $x['title'] : $r['assessment_id']) ?></td>
                    <td>
                        <span class="<?= $r['status'] === 'submitted' ? 'font-semibold text-indigo-700 dark:text-indigo-300' : 'text-slate-600 dark:text-slate-300' ?>">
                            <?= h($status_label[$r['status']] ?? $r['status']) ?>
                        </span>
                    </td>
                    <td class="text-slate-600 dark:text-slate-300"><?= $r['submitted_at'] ? h(dtfmt($r['submitted_at'], 'j M Y, g:i a')) : '' ?></td>
                    <td class="text-right">
                        <?php if (in_array($r['status'], ['submitted', 'approved'], true)): ?>
                            <a href="assessments.php?id=<?= (int)$r['id'] ?>" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-medium">
                                <?= $r['status'] === 'submitted' ? 'Review' : 'View' ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

<?php else: /* one attempt */ ?>
    <p class="mb-4"><a href="assessments.php" class="text-sm underline text-slate-600 dark:text-slate-300">All assessments</a></p>

    <?php if (in_array($attempt['status'], ['assigned', 'in_progress'], true)): ?>
        <div class="card-base p-6 rounded-lg text-slate-700 dark:text-slate-200">The student has not submitted this assessment yet.</div>
    <?php else: ?>

    <div class="grid gap-6 lg:grid-cols-3 mb-6">
        <div class="card-base p-6 rounded-lg lg:col-span-2">
            <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100"><?= h(trim($attempt['first_name'] . ' ' . $attempt['last_name'])) ?></h2>
            <p class="text-slate-600 dark:text-slate-300"><?= h($a['title']) ?> (version <?= (int)$attempt['assessment_version'] ?>)</p>
            <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-slate-500 dark:text-slate-400">Parent</dt>
                <dd class="text-slate-800 dark:text-slate-100"><?= h($attempt['parent_name'] ?: 'Not recorded') ?><?= $attempt['parent_email'] ? ' (' . h($attempt['parent_email']) . ')' : '' ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Exam board</dt>
                <dd class="text-slate-800 dark:text-slate-100"><?= h($attempt['exam_board'] ?: 'Not recorded') ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Family's aim</dt>
                <dd class="text-slate-800 dark:text-slate-100"><?= h(ASM_PACES[$res['pace'] ?? ASM_DEFAULT_PACE]['label']) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Submitted</dt>
                <dd class="text-slate-800 dark:text-slate-100"><?= h(dtfmt($attempt['submitted_at'], 'j M Y, g:i a')) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Answered</dt>
                <dd class="text-slate-800 dark:text-slate-100"><?= (int)$res['answered'] ?> of <?= (int)$res['total_questions'] ?> questions</dd>
            </dl>
        </div>
        <div class="card-base p-6 rounded-lg">
            <p class="text-sm text-slate-500 dark:text-slate-400">Overall</p>
            <p class="text-4xl font-extrabold text-slate-800 dark:text-slate-100"><?= (int)$res['pct'] ?>%</p>
            <p class="text-sm text-slate-600 dark:text-slate-300"><?= (int)$res['earned'] ?> of <?= (int)$res['available'] ?> marks<?= $res['pending'] ? ', ' . (int)$res['pending'] . ' written answer' . ($res['pending'] > 1 ? 's' : '') . ' to mark' : '' ?></p>
            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Recommended plan</p>
            <p class="font-semibold text-slate-800 dark:text-slate-100"><?= h(ASM_PLANS[$plan_key]['name']) ?>: <?= (int)ASM_PLANS[$plan_key]['per_week'] ?> lesson<?= ASM_PLANS[$plan_key]['per_week'] > 1 ? 's' : '' ?> a week</p>
        </div>
    </div>

    <div class="card-base rounded-lg overflow-x-auto mb-6">
        <table class="asm-table w-full text-sm">
            <thead class="text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-white/10">
                <tr><th>Skill</th><th>Marks</th><th>Score</th><th>Band</th><th class="w-1/2">What the parent will read</th></tr>
            </thead>
            <tbody>
            <?php foreach ($res['skills'] as $sid => $s):
                $d = $defs[$sid] ?? [];
                $copy = asm_copy((string)($d[$s['band']] ?? ''), $student_first);
                if ($s['band'] !== 'strong' && !empty($d['next_step'])) $copy .= ' ' . $d['next_step']; ?>
                <tr class="border-b border-slate-100 dark:border-white/5 text-slate-800 dark:text-slate-100">
                    <td class="font-medium"><?= h($s['name']) ?></td>
                    <td><?= (int)$s['earned'] ?> / <?= (int)$s['available'] ?><?= $s['pending'] ? ' <span class="text-amber-700 dark:text-amber-300">+ ' . (int)$s['pending'] . ' to mark</span>' : '' ?></td>
                    <td><?= (int)$s['pct'] ?>%</td>
                    <td><?= asm_band_pill($s['band']) ?></td>
                    <td class="text-slate-600 dark:text-slate-300"><?= h($copy) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" action="assessments.php?id=<?= (int)$attempt['id'] ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-3">Every answer</h3>
    <div class="space-y-3 mb-8">
    <?php foreach ($a['questions'] as $n => $q):
        $qid = (string)$q['id'];
        $given = $answers[$qid] ?? null;
        $qr = $res['questions'][$qid] ?? ['marks' => null, 'max' => (int)$q['marks']];
        $full = $qr['marks'] !== null && $qr['marks'] === $qr['max'];
        $none = $qr['marks'] === 0; ?>
        <div class="card-base p-5 rounded-lg">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400 mb-2">
                <span>Question <?= $n + 1 ?> · <?= h($defs[$q['skill']]['name'] ?? $q['skill']) ?> · difficulty <?= (int)$q['difficulty'] ?><?= !empty($q['ref']) ? ' · ' . h($q['ref']) : '' ?></span>
                <span class="font-semibold <?= $full ? 'text-emerald-700 dark:text-emerald-300' : ($none ? 'text-rose-700 dark:text-rose-300' : 'text-amber-700 dark:text-amber-300') ?>">
                    <?= $qr['marks'] === null ? 'To mark' : (int)$qr['marks'] . ' / ' . (int)$qr['max'] ?>
                </span>
            </div>
            <?php if (!empty($q['passage']) && isset($passages[$q['passage']])): ?>
                <details class="mb-2 text-sm"><summary class="cursor-pointer text-slate-600 dark:text-slate-300">Passage: <?= h($passages[$q['passage']]['title']) ?></summary>
                    <div class="asm-pre mt-2 text-slate-700 dark:text-slate-200"><?= h($passages[$q['passage']]['text']) ?></div></details>
            <?php endif; ?>
            <p class="asm-pre text-slate-800 dark:text-slate-100 mb-3"><?= h($q['stem']) ?></p>
            <div class="grid gap-2 sm:grid-cols-2 text-sm">
                <div><span class="text-slate-500 dark:text-slate-400">Student:</span>
                    <span class="asm-pre <?= $given === null ? 'italic text-slate-500 dark:text-slate-400' : 'text-slate-800 dark:text-slate-100' ?>"><?= $given === null ? 'No answer' : h(asm_given_label($q, $given)) ?></span></div>
                <div><span class="text-slate-500 dark:text-slate-400">Answer:</span>
                    <span class="text-slate-800 dark:text-slate-100"><?= h(asm_answer_label($q)) ?></span></div>
            </div>
            <?php if ($q['type'] === 'written'): ?>
                <div class="mt-3 p-3 rounded-lg bg-slate-50 dark:bg-white/5 text-sm">
                    <p class="font-semibold text-slate-700 dark:text-slate-200 mb-1">Mark scheme</p>
                    <ul class="list-disc pl-5 text-slate-600 dark:text-slate-300">
                        <?php foreach ((array)$q['rubric'] as $r): ?><li><?= (int)$r['marks'] ?> mark<?= $r['marks'] > 1 ? 's' : '' ?>: <?= h($r['criterion']) ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($given !== null): ?>
                    <label class="mt-3 flex items-center gap-2 text-slate-700 dark:text-slate-200">
                        Marks awarded
                        <input type="number" name="wm_<?= h($qid) ?>" min="0" max="<?= (int)$q['marks'] ?>" step="1"
                               value="<?= isset($written[$qid]) ? (int)$written[$qid] : '' ?>" <?= $editable ? '' : 'disabled' ?>
                               class="w-20 px-2 py-1 rounded border border-slate-300 dark:border-white/15 bg-white dark:bg-white/5">
                        out of <?= (int)$q['marks'] ?>
                    </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Tutor note: <?= h($q['explain']) ?></p>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="card-base p-6 rounded-lg mb-12">
        <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-4"><?= $editable ? 'Approve the report' : 'Report' ?></h3>
        <div class="mb-5 p-4 rounded-lg bg-slate-50 dark:bg-white/5">
            <p class="text-sm text-slate-500 dark:text-slate-400">Recommended plan (set automatically)</p>
            <p class="text-lg font-semibold text-slate-800 dark:text-slate-100"><?= h(ASM_PLANS[$plan_key]['name']) ?>: <?= (int)ASM_PLANS[$plan_key]['per_week'] ?> lesson<?= ASM_PLANS[$plan_key]['per_week'] > 1 ? 's' : '' ?> a week</p>
            <p class="text-sm text-slate-600 dark:text-slate-300"><?= h($plan_reason) ?></p>
        </div>

        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1" for="tutor_note">A personal note for the parent (optional)</label>
        <textarea id="tutor_note" name="tutor_note" rows="3" maxlength="600" <?= $editable ? '' : 'disabled' ?>
                  placeholder="For example: Sam worked really hard today and picked up the fraction method quickly."
                  class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-white/15 bg-white dark:bg-white/5 text-slate-800 dark:text-slate-100 mb-4"><?= h($attempt['tutor_note'] ?? '') ?></textarea>

        <?php if ($editable): ?>
            <div class="flex flex-wrap gap-3">
                <button type="submit" name="action" value="save" class="px-5 py-2.5 rounded-lg border border-slate-300 dark:border-white/15 text-slate-800 dark:text-slate-100 font-medium">Save marks</button>
                <button type="submit" name="action" value="approve" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold"
                        data-confirm-title="Approve and send this report?"
                        data-confirm="<?= h('The report goes to ' . ($attempt['parent_email'] ?: 'the parent') . ' straight away. You cannot change it afterwards.') ?>"
                        data-confirm-ok="Approve and send">Approve and send report</button>
            </div>
            <?php if ($res['pending'] > 0): ?>
                <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Mark the written answer<?= $res['pending'] > 1 ? 's' : '' ?> above before approving.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Approved <?= h(dtfmt($attempt['approved_at'], 'j M Y, g:i a')) ?>.
                <?= $attempt['report_sent_at'] ? 'Report emailed to the parent ' . h(dtfmt($attempt['report_sent_at'], 'j M Y, g:i a')) . '.' : 'The report has not been emailed yet.' ?>
            </p>
            <?php if (!$attempt['report_sent_at'] && !empty($attempt['report_error'])): ?>
                <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">The last attempt to send it failed. An admin can resend it from Demo Assessments.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </form>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../components/confirm_dialog.php'; ?>
</body>
</html>
