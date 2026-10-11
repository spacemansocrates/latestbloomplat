<?php
/**
 * admin/subscriptions.php
 * Stripe subscriptions (the 30-day plans) as the portal sees them:
 *   - setup: the webhook URL and events to give Stripe, and the signing secret
 *     (stored encrypted with utils/secret_box.php, never shown back);
 *   - every subscription with its student, plan, status and current lessons;
 *   - linking a payment that could not be matched to a student automatically;
 *   - the most recent webhook events, so "did Stripe tell us?" has an answer.
 *
 * Full admins only: this is money, and it shows parents' payment details.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

require_once __DIR__ . '/authz.php';
require_cap('payments.manage');
require_once __DIR__ . '/../integrations/stripe_subscriptions.php';

$pdo = $AUTHZ_PDO;
$current_page = 'subscriptions';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

const SUB_WEBHOOK_PATH = '/student/api/stripe_webhook.php';
const SUB_EVENTS = ['checkout.session.completed', 'invoice.paid', 'invoice.payment_failed',
                    'customer.subscription.updated', 'customer.subscription.deleted'];

$ready = true;
try { $pdo->query('SELECT 1 FROM stripe_subscriptions LIMIT 1'); $pdo->query('SELECT 1 FROM stripe_events LIMIT 1'); }
catch (Throwable $e) { $ready = false; }

$flash = $_SESSION['sub_flash'] ?? null;
unset($_SESSION['sub_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new RuntimeException('That form had expired. Refresh the page and try again.');
        }
        if (!$ready) throw new RuntimeException('Run migrations/2026_09_24c_stripe_subscriptions.sql first.');
        if ($action === 'secret') {
            $secret = trim((string)($_POST['secret'] ?? ''));
            if (!preg_match('#^whsec_[A-Za-z0-9+/=_-]{16,}$#', $secret)) {
                throw new RuntimeException('That does not look like a Stripe signing secret. It starts with whsec_.');
            }
            if (!secret_available()) throw new RuntimeException('The server has no encryption key, so the secret cannot be stored safely. Run php tools/generate_encryption_key.php --write on the server.');
            $pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                ->execute([STRIPE_SECRET_SETTING, secret_encrypt($secret, STRIPE_SECRET_SETTING)]);
            $flash = ['ok', 'Signing secret saved (encrypted). Send a test event from Stripe to check the connection.'];
        } elseif ($action === 'link') {
            $msg = stripe_sub_link($pdo, (int)($_POST['row_id'] ?? 0), (int)($_POST['student_id'] ?? 0), (string)($_POST['plan'] ?? '') ?: null);
            $flash = ['ok', $msg];
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $flash = ['err', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Nothing was saved.'];
        if (!($e instanceof RuntimeException)) error_log('[admin subscriptions] ' . $e->getMessage());
    }
    $_SESSION['sub_flash'] = $flash;
    header('Location: subscriptions.php');
    exit();
}

// ---- secret status, never the value
$secretSource = 'none';
$secretTail = '';
if (getenv('STRIPE_WEBHOOK_SECRET')) { $secretSource = 'env'; }
else {
    $plain = stripe_sub_webhook_secret($pdo);
    if ($plain) { $secretSource = 'saved'; $secretTail = substr($plain, -4); }
}

$rows = $events = $students = [];
if ($ready) {
    $rows = $pdo->query("
        SELECT ss.*, u.first_name, u.last_name
        FROM stripe_subscriptions ss
        LEFT JOIN users u ON u.id = ss.student_id
        ORDER BY (ss.student_id IS NULL) DESC, ss.updated_at DESC
        LIMIT 300")->fetchAll();
    // Payments are matched by bound value rather than joined on the column: the older
    // payments table may use a different collation, and comparing the two columns
    // directly fails with "Illegal mix of collations".
    $paid = [];
    if ($rows) {
        $ids = array_column($rows, 'subscription_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT p.provider_session_id AS sid, p.payment_date, sp.status, sp.lessons_remaining, sp.id AS sp_id
                             FROM payments p JOIN student_packages sp ON sp.id = p.student_package_id
                             WHERE p.payment_method = 'Stripe' AND p.provider_session_id IN ($in)
                             ORDER BY sp.id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) {
            $k = (string)$p['sid'];
            $paid[$k]['last_paid'] = max((string)($paid[$k]['last_paid'] ?? ''), (string)$p['payment_date']);
            if ($p['status'] === 'Active') $paid[$k]['lessons_left'] = (int)$p['lessons_remaining'];
        }
    }
    foreach ($rows as &$r) {
        $r['last_paid'] = $paid[$r['subscription_id']]['last_paid'] ?? null;
        $r['lessons_left'] = $paid[$r['subscription_id']]['lessons_left'] ?? null;
    }
    unset($r);
    $events = $pdo->query('SELECT * FROM stripe_events ORDER BY received_at DESC LIMIT 25')->fetchAll();
    if (array_filter($rows, fn($r) => !$r['student_id'] || !$r['plan'])) {
        $students = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'Student' AND is_active = 1 ORDER BY first_name, last_name LIMIT 2000")->fetchAll();
    }
}
$host = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.bloomeducationonline.com');

function sub_status(array $r): array {
    if ($r['status'] === 'canceled') return ['Cancelled', 'text-gray-400'];
    if ($r['status'] === 'past_due' || $r['status'] === 'unpaid') return ['Payment failed', 'text-red-300'];
    if (!empty($r['cancel_at_period_end'])) return ['Cancels ' . ($r['current_period_end'] ? date('j M', strtotime((string)$r['current_period_end'])) : 'at period end'), 'text-amber-300'];
    return [ucfirst((string)$r['status']), 'text-emerald-300'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin: Subscriptions</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.55rem .8rem}
    .card{background:#111827;border:1px solid #1f2937;border-radius:.75rem;padding:1.25rem}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .tbl th,.tbl td{padding:.55rem .7rem;text-align:left;vertical-align:top}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>
  <main class="flex-1 min-w-0 bg-gray-800 p-6 lg:p-8">
    <header class="mb-6">
      <h2 class="text-2xl font-bold text-white">Subscriptions</h2>
      <p class="text-gray-400">Stripe payments for the 30-day plans. Each payment becomes that period's lessons for the student.</p>
    </header>

    <?php if (!$ready): ?>
      <div class="mb-6 bg-red-500/10 border border-red-500/30 text-red-200 px-4 py-3 rounded-lg">
        The database update for this page has not been run. Run <code class="mono">migrations/2026_09_24c_stripe_subscriptions.sql</code>, then reload.
      </div>
    <?php endif; ?>
    <?php if ($flash): ?>
      <div role="status" class="mb-4 px-4 py-2 rounded-lg border <?= $flash[0] === 'ok' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-red-500/10 border-red-500/30 text-red-300' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <div class="max-w-6xl space-y-8">
      <section class="card" aria-labelledby="setup-title">
        <h3 id="setup-title" class="text-lg font-semibold text-white mb-3">Connection to Stripe</h3>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-300">
          <li>In Stripe, go to <strong>Developers → Webhooks → Add endpoint</strong> and use this URL:
            <div class="mono text-gray-100 bg-gray-900/70 rounded px-3 py-2 mt-1 break-all"><?= h($host . SUB_WEBHOOK_PATH) ?></div></li>
          <li>Select these events: <span class="mono text-gray-100"><?= h(implode(', ', SUB_EVENTS)) ?></span></li>
          <li>Copy the endpoint's <strong>Signing secret</strong> (it starts with <span class="mono">whsec_</span>) and paste it below.</li>
        </ol>
        <div class="mt-4 text-sm">
          <?php if ($secretSource === 'env'): ?>
            <p class="text-emerald-300">Signing secret: set in the server .env.</p>
          <?php elseif ($secretSource === 'saved'): ?>
            <p class="text-emerald-300">Signing secret: saved, ending in <span class="mono">…<?= h($secretTail) ?></span>.</p>
          <?php else: ?>
            <p class="text-amber-300">Signing secret: not set yet, so Stripe's messages are being refused.</p>
          <?php endif; ?>
          <p class="text-gray-400 mt-1">Last message from Stripe:
            <?= $events ? h(date('j M Y, g:i a', strtotime((string)$events[0]['received_at']))) . ' (' . h($events[0]['type']) . ')' : 'none yet' ?></p>
        </div>
        <?php if ($secretSource !== 'env'): ?>
          <form method="post" class="mt-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="secret">
            <div class="flex-1 min-w-[260px]"><label class="block text-xs text-gray-400 mb-1" for="secret"><?= $secretSource === 'saved' ? 'Replace the signing secret' : 'Signing secret' ?></label>
              <input class="form-input w-full mono" id="secret" name="secret" type="password" autocomplete="off" required placeholder="whsec_..."></div>
            <button class="px-4 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Save secret</button>
          </form>
        <?php endif; ?>
      </section>

      <section class="card overflow-x-auto" aria-labelledby="subs-title">
        <h3 id="subs-title" class="text-lg font-semibold text-white mb-3">All subscriptions</h3>
        <?php if (!$rows): ?>
          <p class="text-gray-400">None yet. They appear here as soon as a parent pays through a payment link.</p>
        <?php else: ?>
        <table class="tbl w-full text-sm">
          <thead class="text-gray-400 border-b border-gray-700">
            <tr><th>Student</th><th>Paid by</th><th>Plan</th><th>Status</th><th>Lessons left</th><th>Last payment</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): [$sl, $sc] = sub_status($r); $needs = !$r['student_id'] || !$r['plan']; ?>
            <tr class="border-b border-gray-700/50 <?= $needs ? 'bg-amber-500/5' : '' ?>">
              <td class="text-gray-100">
                <?= $r['student_id'] ? h(trim($r['first_name'] . ' ' . $r['last_name'])) : '<span class="text-amber-300 font-semibold">Not matched to a student</span>' ?>
                <div class="text-xs text-gray-500 mono"><?= h($r['subscription_id']) ?></div>
              </td>
              <td><?= h($r['customer_name'] ?: '') ?><div class="text-xs text-gray-400 mono"><?= h($r['customer_email']) ?></div></td>
              <td><?= $r['plan'] && isset(ASM_PLANS[$r['plan']]) ? h(ASM_PLANS[$r['plan']]['name']) : '<span class="text-amber-300">Unknown</span>' ?></td>
              <td class="<?= $sc ?>"><?= h($sl) ?><?= $r['last_error'] ? '<div class="text-xs text-red-300">' . h($r['last_error']) . '</div>' : '' ?></td>
              <td><?= $r['lessons_left'] !== null ? (int)$r['lessons_left'] : '<span class="text-gray-500">none</span>' ?></td>
              <td class="text-xs text-gray-400"><?= $r['last_paid'] ? h(date('j M Y', strtotime((string)$r['last_paid']))) : ($r['pending_invoice_id'] ? '<span class="text-amber-300">Paid &pound;' . h(number_format((float)$r['pending_amount'], 2)) . ', waiting to be linked</span>' : '') ?></td>
            </tr>
            <?php if ($needs): ?>
            <tr class="border-b border-gray-700/50 bg-amber-500/5"><td colspan="6">
              <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="link">
                <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                <div><label class="block text-xs text-gray-400 mb-1" for="stu_<?= (int)$r['id'] ?>">Student</label>
                  <select class="form-input" id="stu_<?= (int)$r['id'] ?>" name="student_id" required>
                    <option value="">Choose...</option>
                    <?php foreach ($students as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" <?= (int)$r['student_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h(trim($s['first_name'] . ' ' . $s['last_name']) . ' (' . $s['email'] . ')') ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div><label class="block text-xs text-gray-400 mb-1" for="plan_<?= (int)$r['id'] ?>">Plan</label>
                  <select class="form-input" id="plan_<?= (int)$r['id'] ?>" name="plan">
                    <option value=""><?= $r['plan'] ? 'Keep ' . h(ASM_PLANS[$r['plan']]['name'] ?? $r['plan']) : 'Choose...' ?></option>
                    <?php foreach (ASM_PLANS as $pk => $pv): ?><option value="<?= h($pk) ?>"><?= h($pv['name']) ?>: <?= (int)$pv['lessons'] ?> lessons, &pound;<?= (int)$pv['price'] ?></option><?php endforeach; ?>
                  </select></div>
                <button class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm">Link</button>
              </form>
            </td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>

      <section class="card overflow-x-auto" aria-labelledby="events-title">
        <h3 id="events-title" class="text-lg font-semibold text-white mb-3">Recent messages from Stripe</h3>
        <?php if (!$events): ?>
          <p class="text-gray-400">None yet.</p>
        <?php else: ?>
          <table class="tbl w-full text-sm">
            <thead class="text-gray-400 border-b border-gray-700"><tr><th>Received</th><th>Event</th><th>What happened</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
              <tr class="border-b border-gray-700/50"><td class="text-xs text-gray-400 whitespace-nowrap"><?= h(date('j M, g:i:s a', strtotime((string)$ev['received_at']))) ?></td>
                <td class="mono text-xs"><?= h($ev['type']) ?></td><td><?= h($ev['result']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>
    </div>
  </main>
</body>
</html>
