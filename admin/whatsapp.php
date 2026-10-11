<?php
/**
 * admin/whatsapp.php
 * Everything about WhatsApp reminders in one place: the Twilio connection, the
 * master switch, a test send, the default reminder choices, and what happened to
 * every message afterwards.
 *
 * Credentials: the auth token is encrypted before it is stored (utils/secret_box.php)
 * and is never sent back to the browser, only its last four characters. A value set
 * in the server .env always wins and is shown read-only here.
 *
 * Full admins only (require_cap is '*' for them and absent for Mini Admins): this
 * page can send messages to any number and change who gets reminded.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

require_once __DIR__ . '/authz.php';
require_cap('settings.whatsapp');
require_once __DIR__ . '/../integrations/twilio_whatsapp.php';
require_once __DIR__ . '/../utils/notification_prefs.php';
require_once __DIR__ . '/../utils/phone.php';

$pdo = $AUTHZ_PDO;
$current_page = 'whatsapp';
$me = (int)$_SESSION['user_id'];

const WA_TEST_LIMIT_PER_HOUR = 5;
const WA_PAGE_SIZE = 50;

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function wa_table_exists(PDO $pdo, string $t): bool {
    try { $st = $pdo->prepare('SHOW TABLES LIKE ?'); $st->execute([$t]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}
$schemaReady = wa_table_exists($pdo, 'whatsapp_messages') && wa_table_exists($pdo, 'settings_audit')
            && wa_table_exists($pdo, 'notification_preferences') && wa_table_exists($pdo, 'app_settings');

function wa_setting_put(PDO $pdo, string $key, ?string $value): void {
    if ($value === null) {
        $pdo->prepare('DELETE FROM app_settings WHERE `key` = ?')->execute([$key]);
        return;
    }
    $pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);
}

function wa_audit(PDO $pdo, string $key, string $action, int $by): void {
    try {
        $pdo->prepare('INSERT INTO settings_audit (setting_key, action, changed_by, ip) VALUES (?, ?, ?, ?)')
            ->execute([$key, $action, $by, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) { error_log('[whatsapp admin] audit failed: ' . $e->getMessage()); }
}

/** Check the effective SID + token and remember the answer for the status card. */
function wa_verify_now(PDO $pdo, int $me): array {
    $sid   = twilio_get_setting($pdo, 'TWILIO_ACCOUNT_SID', 'twilio_account_sid');
    $token = twilio_get_setting($pdo, 'TWILIO_AUTH_TOKEN', 'twilio_auth_token');
    $res = ($sid && $token)
        ? twilio_verify_credentials($sid, $token)
        : ['ok' => false, 'message' => 'Enter both the Account SID and the Auth Token first.'];
    wa_setting_put($pdo, 'whatsapp_verified', json_encode($res + ['at' => gmdate('c')]));
    wa_audit($pdo, 'twilio_credentials', 'verified', $me);
    return $res;
}

// ------------------------------------------------------------------ POST
// Post/redirect/get, so refreshing the page can never send a second test message.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['oks' => [], 'errs' => [], 'test' => null];
    $anchor = '';
    $action = (string)($_POST['action'] ?? '');

    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        $flash['errs'][] = 'That form expired. Refresh and try again.';
    } elseif (!$schemaReady) {
        $flash['errs'][] = 'The database update for this page has not been run yet.';
    } else try {

        if ($action === 'save_connection') {
            $anchor = '#connection';
            $in = [
                'twilio_account_sid'             => trim((string)($_POST['account_sid'] ?? '')),
                'twilio_whatsapp_from'           => preg_replace('/\s+/', '', (string)($_POST['sender'] ?? '')),
                'whatsapp_template_reminder_sid' => trim((string)($_POST['tpl_reminder'] ?? '')),
                'whatsapp_template_digest_sid'   => trim((string)($_POST['tpl_digest'] ?? '')),
            ];
            $token = trim((string)($_POST['auth_token'] ?? ''));
            $clearToken = !empty($_POST['clear_token']);
            $bad = [];

            if ($in['twilio_account_sid'] !== '' && !preg_match('/^AC[0-9a-f]{32}$/i', $in['twilio_account_sid']))
                $bad[] = 'The Account SID should be "AC" followed by 32 letters and numbers.';
            $from = $in['twilio_whatsapp_from'];
            if (str_starts_with($from, 'whatsapp:')) $from = substr($from, 9);
            if ($from !== '' && !preg_match('/^MG[0-9a-f]{32}$/i', $from) && !preg_match('/^\+[1-9][0-9]{7,14}$/', $from))
                $bad[] = 'The sender should be your WhatsApp number with country code (e.g. +265999123456), or a Messaging Service SID starting with MG.';
            $in['twilio_whatsapp_from'] = $from;
            foreach (['whatsapp_template_reminder_sid' => 'lesson reminder', 'whatsapp_template_digest_sid' => 'daily summary'] as $k => $label) {
                if ($in[$k] !== '' && !preg_match('/^HX[0-9a-f]{32}$/i', $in[$k]))
                    $bad[] = "The $label template ID should be \"HX\" followed by 32 letters and numbers.";
            }
            if ($token !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $token))
                $bad[] = 'That does not look like a Twilio Auth Token (32 letters and numbers).';
            if ($token !== '' && !secret_available())
                $bad[] = 'The server has no encryption key (APP_ENCRYPTION_KEY), so the token cannot be stored safely. Nothing was saved.';

            if ($bad) {
                $flash['errs'] = $bad;
            } else {
                $changedCreds = false;
                foreach ($in as $key => $value) {
                    if (twilio_setting_source($pdo, $key) === 'env') continue;   // .env wins; the field was read-only
                    $current = twilio_db_value($pdo, $key) ?? '';
                    if ($value === $current) continue;
                    wa_setting_put($pdo, $key, $value === '' ? null : $value);
                    wa_audit($pdo, $key, $value === '' ? 'cleared' : 'set', $me);
                    if ($key === 'twilio_account_sid') $changedCreds = true;
                }
                if (twilio_setting_source($pdo, 'twilio_auth_token') !== 'env') {
                    if ($clearToken) {
                        wa_setting_put($pdo, 'twilio_auth_token', null);
                        wa_audit($pdo, 'twilio_auth_token', 'cleared', $me);
                        $changedCreds = true;
                    } elseif ($token !== '') {
                        wa_setting_put($pdo, 'twilio_auth_token', secret_encrypt($token, 'twilio_auth_token'));
                        wa_audit($pdo, 'twilio_auth_token', 'set', $me);
                        $changedCreds = true;
                    }
                }
                $flash['oks'][] = 'Connection settings saved.';
                if ($changedCreds) {
                    $v = wa_verify_now($pdo, $me);
                    $flash[$v['ok'] ? 'oks' : 'errs'][] = $v['message'];
                }
            }
        }

        elseif ($action === 'verify') {
            $anchor = '#connection';
            $v = wa_verify_now($pdo, $me);
            $flash[$v['ok'] ? 'oks' : 'errs'][] = $v['message'];
        }

        elseif ($action === 'set_enabled') {
            $on = !empty($_POST['enabled']);
            if (twilio_setting_source($pdo, 'whatsapp_enabled') === 'env') {
                $flash['errs'][] = 'WhatsApp on/off is set in the server .env (WHATSAPP_ENABLED), so it cannot be changed here.';
            } elseif ($on && twilio_config($pdo) === null) {
                $flash['errs'][] = 'Add the Account SID, Auth Token and sender before switching WhatsApp on.';
            } else {
                wa_setting_put($pdo, 'whatsapp_enabled', $on ? 'true' : 'false');
                wa_audit($pdo, 'whatsapp_enabled', 'set', $me);
                $flash['oks'][] = $on ? 'WhatsApp reminders are ON.' : 'WhatsApp reminders are OFF. Email reminders carry on as normal.';
                if ($on && (!whatsapp_template_sid($pdo, 'reminder') || !whatsapp_template_sid($pdo, 'digest'))) {
                    $flash['errs'][] = 'Switched on, but a template ID is missing, so that kind of reminder will not go out on WhatsApp until you add it.';
                }
            }
        }

        elseif ($action === 'send_test') {
            $anchor = '#test';
            $type = (string)($_POST['type'] ?? 'reminder');
            $p = phone_to_e164((string)($_POST['to'] ?? ''));
            $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages
                                 WHERE kind = 'test' AND created_by = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
            $st->execute([$me]);
            $used = (int)$st->fetchColumn();

            if ($used >= WA_TEST_LIMIT_PER_HOUR) {
                $flash['errs'][] = 'You have sent ' . WA_TEST_LIMIT_PER_HOUR . ' test messages in the last hour. Please wait a little before sending another.';
            } elseif (!$p['ok']) {
                $flash['errs'][] = 'Test number: ' . phone_reason_text($p['reason']);
            } elseif (twilio_config($pdo) === null) {
                $flash['errs'][] = 'Save the Twilio connection first.';
            } else {
                $meta = ['kind' => 'test', 'created_by' => $me];
                $tz = new DateTimeZone($_SESSION['timezone'] ?? 'Africa/Blantyre');
                $first = (string)($_SESSION['user_first_name'] ?? 'there');
                $site = rtrim((string)(twilio_env('SITE_URL') ?? ''), '/');
                if ($type === 'text') {
                    $body = trim((string)($_POST['body'] ?? ''));
                    if ($body === '') $body = 'Test message from Bloom Education. If you can read this, WhatsApp is working.';
                    $res = whatsapp_send_text($pdo, $p['e164'], mb_substr($body, 0, 1000), $meta);
                } else {
                    $which = $type === 'digest' ? 'digest' : 'reminder';
                    $sid = whatsapp_template_sid($pdo, $which);
                    if ($sid === null) {
                        $res = ['success' => false, 'error' => 'The ' . ($which === 'digest' ? 'daily summary' : 'lesson reminder') . ' template ID is not set.', 'sid' => null];
                    } else {
                        $at = (new DateTimeImmutable('+15 minutes', $tz))->format('H:i T');
                        $vars = $which === 'digest'
                            ? ['1' => $first, '2' => '1', '3' => '09:00 Mathematics with Test Tutor', '4' => $site . '/student/dashboard.php']
                            : ['1' => $first, '2' => 'Mathematics', '3' => 'Test Tutor', '4' => $at, '5' => $site . '/student/dashboard.php'];
                        $res = whatsapp_send_template($pdo, $p['e164'], $sid, $vars, $meta);
                    }
                }
                wa_audit($pdo, 'whatsapp_test', 'test', $me);
                $flash['test'] = [
                    'ok' => !empty($res['success']), 'to' => $p['e164'], 'sid' => $res['sid'] ?? null,
                    'error' => $res['error'] ?? null, 'hint' => wa_error_text($res['error_code'] ?? null),
                    'assumed' => $p['assumed'],
                ];
            }
        }

        elseif ($action === 'save_defaults') {
            $anchor = '#defaults';
            np_save_defaults($pdo, (array)($_POST['np'] ?? []));
            wa_audit($pdo, 'notify.default_prefs', 'set', $me);
            $flash['oks'][] = 'Default reminder choices saved. They apply to everyone who has not chosen for themselves.';
        }

        else {
            $flash['errs'][] = 'Unknown action.';
        }
    } catch (Throwable $e) {
        error_log('[whatsapp admin] ' . $e->getMessage());
        $flash['errs'][] = 'Something went wrong and nothing further was changed. Please try again.';
    }

    $_SESSION['wa_flash'] = $flash;
    header('Location: whatsapp.php' . $anchor);
    exit();
}

$flash = $_SESSION['wa_flash'] ?? ['oks' => [], 'errs' => [], 'test' => null];
unset($_SESSION['wa_flash']);

// ------------------------------------------------------------------ view data
$adminTz = new DateTimeZone(in_array($_SESSION['timezone'] ?? '', DateTimeZone::listIdentifiers(), true)
    ? $_SESSION['timezone'] : 'Africa/Blantyre');
/** Database timestamps are UTC on the server; show them on the admin's clock. */
function wa_when(?string $utc, DateTimeZone $tz): string {
    if (!$utc) return '';
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j M, H:i'); }
    catch (Throwable $e) { return (string)$utc; }
}

$src = [];
foreach (array_keys(TWILIO_SETTINGS) as $k) $src[$k] = $schemaReady ? twilio_setting_source($pdo, $k) : null;
$val = [];
foreach (['twilio_account_sid', 'twilio_whatsapp_from', 'whatsapp_template_reminder_sid', 'whatsapp_template_digest_sid'] as $k) {
    $val[$k] = twilio_get_setting($pdo, TWILIO_SETTINGS[$k], $k) ?? '';
}
$tokenMask  = secret_mask(twilio_get_setting($pdo, 'TWILIO_AUTH_TOKEN', 'twilio_auth_token'));
$configured = twilio_config($pdo) !== null;
$enabled    = whatsapp_enabled($pdo);
$enabledFlag = in_array(strtolower((string)(twilio_get_setting($pdo, 'WHATSAPP_ENABLED', 'whatsapp_enabled') ?? '')), ['1', 'true', 'yes', 'on'], true);
$verified   = json_decode((string)(twilio_db_value($pdo, 'whatsapp_verified') ?? ''), true);
$tplCount   = (whatsapp_template_sid($pdo, 'reminder') ? 1 : 0) + (whatsapp_template_sid($pdo, 'digest') ? 1 : 0);
$keyReady   = secret_available();
$defaults   = np_defaults($pdo);
$isSandbox  = str_contains($val['twilio_whatsapp_from'], '14155238886');

$cover = ['Student' => [0, 0], 'Tutor' => [0, 0]];
foreach ($pdo->query("SELECT role, phone_number FROM users WHERE is_active = 1 AND role IN ('Student','Tutor')") as $u) {
    $cover[$u['role']][1]++;
    if (phone_to_e164($u['phone_number'])['ok']) $cover[$u['role']][0]++;
}

$myPhone = (string)($pdo->query('SELECT phone_number FROM users WHERE id = ' . $me)->fetchColumn() ?: '');

$stats = []; $rows = []; $notSent = []; $audit = []; $testsUsed = 0; $total = 0;
$fStatus = (string)($_GET['status'] ?? 'all');
$fKind   = (string)($_GET['kind'] ?? 'all');
$page    = max(1, (int)($_GET['page'] ?? 1));
$STATUS_FILTERS = [
    'all' => 'All', 'pending' => 'Waiting', 'sent' => 'Sent', 'delivered' => 'Delivered',
    'read' => 'Read', 'failed' => 'Failed',
];
$KIND_LABELS = ['lesson_reminder' => 'Lesson reminder', 'daily_digest' => 'Daily summary', 'test' => 'Test', 'unknown' => 'Other'];

if ($schemaReady) {
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM whatsapp_messages
                          WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY status") as $r) {
        $stats[$r['status']] = (int)$r['n'];
    }
    $where = []; $args = [];
    if ($fStatus === 'pending')       $where[] = "m.status IN ('queued','accepted','scheduled','sending')";
    elseif ($fStatus === 'failed')    $where[] = "m.status IN ('failed','undelivered')";
    elseif (isset($STATUS_FILTERS[$fStatus]) && $fStatus !== 'all') { $where[] = 'm.status = ?'; $args[] = $fStatus; }
    if (isset($KIND_LABELS[$fKind]))  { $where[] = 'm.kind = ?'; $args[] = $fKind; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages m $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT m.*, u.first_name, u.last_name, u.role, c.first_name AS by_first
                         FROM whatsapp_messages m
                         LEFT JOIN users u ON u.id = m.user_id
                         LEFT JOIN users c ON c.id = m.created_by
                         $w ORDER BY m.id DESC LIMIT " . WA_PAGE_SIZE . ' OFFSET ' . (($page - 1) * WA_PAGE_SIZE));
    $st->execute($args);
    $rows = $st->fetchAll();

    if (wa_table_exists($pdo, 'notification_dispatch_log')) {
        $notSent = $pdo->query("SELECT d.created_at, d.kind, d.status, d.detail, d.lesson_id, u.first_name, u.last_name, u.role
                                FROM notification_dispatch_log d LEFT JOIN users u ON u.id = d.user_id
                                WHERE d.channel = 'whatsapp' AND d.status IN ('skipped','failed')
                                  AND d.created_at >= NOW() - INTERVAL 7 DAY
                                ORDER BY d.id DESC LIMIT 20")->fetchAll();
    }
    $audit = $pdo->query("SELECT a.*, u.first_name, u.last_name FROM settings_audit a
                          LEFT JOIN users u ON u.id = a.changed_by ORDER BY a.id DESC LIMIT 10")->fetchAll();
    $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE kind = 'test' AND created_by = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $st->execute([$me]);
    $testsUsed = (int)$st->fetchColumn();
}

function wa_pill(string $status): string {
    $map = [
        'read' => ['Read', 'bg-sky-500/15 text-sky-300 border-sky-500/30'],
        'delivered' => ['Delivered', 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'],
        'sent' => ['Sent', 'bg-gray-500/20 text-gray-200 border-gray-500/30'],
        'failed' => ['Failed', 'bg-red-500/15 text-red-300 border-red-500/30'],
        'undelivered' => ['Not delivered', 'bg-red-500/15 text-red-300 border-red-500/30'],
    ];
    [$label, $cls] = $map[$status] ?? ['Waiting', 'bg-amber-500/15 text-amber-300 border-amber-500/30'];
    return '<span class="inline-block text-xs font-semibold px-2 py-0.5 rounded-full border ' . $cls . '">' . h($label) . '</span>';
}
function wa_src_note(?string $s): string {
    return match ($s) {
        'env' => '<p class="text-xs text-amber-300 mt-1">Set in the server .env, so it cannot be changed here.</p>',
        'db_unreadable' => '<p class="text-xs text-red-300 mt-1">Saved, but cannot be decrypted: the server encryption key is missing or has changed. Enter it again.</p>',
        'db_plain' => '<p class="text-xs text-amber-300 mt-1">Saved before encryption was added. Enter it again to store it encrypted.</p>',
        default => '',
    };
}
function wa_qs(array $over): string {
    return 'whatsapp.php?' . http_build_query(array_merge(
        ['status' => $_GET['status'] ?? 'all', 'kind' => $_GET['kind'] ?? 'all'], $over)) . '#log';
}
$field = 'form-input';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin: WhatsApp</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.65rem .9rem;width:100%}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px #4f46e5}
    .form-input[readonly]{opacity:.7;cursor:not-allowed}
    .card{background:#111827;border:1px solid #1f2937;border-radius:.75rem;padding:1rem}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 min-w-0 bg-gray-800 p-6 lg:p-8">
    <header class="mb-6">
      <h2 class="text-2xl font-bold text-white">WhatsApp</h2>
      <p class="text-gray-400">Lesson reminders on WhatsApp: connection, test messages, default choices and delivery.</p>
    </header>

    <?php if (!$schemaReady): ?>
      <div class="mb-6 bg-red-500/10 border border-red-500/30 text-red-200 px-4 py-3 rounded-lg">
        The database update for this page has not been run. Run <code class="mono">migrations/2026_09_14_whatsapp_admin.sql</code>, then reload.
      </div>
    <?php endif; ?>
    <?php if (!$keyReady): ?>
      <div class="mb-6 bg-amber-500/10 border border-amber-500/30 text-amber-200 px-4 py-3 rounded-lg">
        The server has no encryption key yet, so the Auth Token cannot be saved here.
        On the server, run <code class="mono">php tools/generate_encryption_key.php --write</code>.
      </div>
    <?php endif; ?>

    <?php foreach ($flash['oks'] as $m): ?>
      <div class="mb-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2 rounded-lg"><?= h($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($flash['errs'] as $m): ?>
      <div class="mb-3 bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2 rounded-lg" role="alert"><?= h($m) ?></div>
    <?php endforeach; ?>

    <div class="max-w-6xl mx-auto space-y-8">

      <!-- Status -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="card">
          <p class="text-xs uppercase tracking-wide text-gray-400">Reminders on WhatsApp</p>
          <p class="text-xl font-bold mt-1 <?= $enabled ? 'text-emerald-300' : 'text-gray-200' ?>"><?= $enabled ? 'On' : 'Off' ?></p>
          <?php if ($src['whatsapp_enabled'] === 'env'): ?>
            <p class="text-xs text-amber-300 mt-2">Controlled by the server .env.</p>
          <?php else: ?>
            <form method="post" class="mt-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="set_enabled">
              <input type="hidden" name="enabled" value="<?= $enabledFlag ? '' : '1' ?>">
              <button class="text-sm font-semibold px-3 py-1.5 rounded-lg <?= $enabledFlag ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-emerald-600 hover:bg-emerald-500 text-white' ?>"
                      <?= (!$enabledFlag && !$configured) ? 'disabled title="Save the connection first" style="opacity:.5;cursor:not-allowed"' : '' ?>>
                <?= $enabledFlag ? 'Switch off' : 'Switch on' ?>
              </button>
            </form>
            <?php if ($enabledFlag && !$configured): ?>
              <p class="text-xs text-red-300 mt-2">Switched on, but the connection is incomplete, so nothing is sending.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="card">
          <p class="text-xs uppercase tracking-wide text-gray-400">Twilio connection</p>
          <?php if (!$configured): ?>
            <p class="text-xl font-bold mt-1 text-gray-200">Not set up</p>
          <?php elseif (!empty($verified['ok'])): ?>
            <p class="text-xl font-bold mt-1 text-emerald-300">Working</p>
            <p class="text-xs text-gray-400 mt-1">Checked <?= h(wa_when(str_replace(['T', '+00:00'], [' ', ''], (string)$verified['at']), $adminTz)) ?></p>
          <?php elseif (is_array($verified)): ?>
            <p class="text-xl font-bold mt-1 text-red-300">Problem</p>
            <p class="text-xs text-red-300 mt-1"><?= h($verified['message'] ?? '') ?></p>
          <?php else: ?>
            <p class="text-xl font-bold mt-1 text-gray-200">Not checked</p>
          <?php endif; ?>
          <?php if ($configured): ?>
            <form method="post" class="mt-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="verify">
              <button class="text-sm font-semibold px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white">Check connection</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card">
          <p class="text-xs uppercase tracking-wide text-gray-400">Templates</p>
          <p class="text-xl font-bold mt-1 <?= $tplCount === 2 ? 'text-emerald-300' : 'text-amber-300' ?>"><?= $tplCount ?> of 2 set</p>
          <p class="text-xs text-gray-400 mt-1">Reminders only go out once their template ID is saved.</p>
        </div>
        <div class="card">
          <p class="text-xs uppercase tracking-wide text-gray-400">Numbers we can reach</p>
          <p class="text-sm mt-2 text-gray-200"><strong class="text-white"><?= $cover['Student'][0] ?></strong> of <?= $cover['Student'][1] ?> students</p>
          <p class="text-sm text-gray-200"><strong class="text-white"><?= $cover['Tutor'][0] ?></strong> of <?= $cover['Tutor'][1] ?> tutors</p>
          <p class="text-xs text-gray-400 mt-1">Others get email only until they add a number.</p>
        </div>
      </section>

      <!-- Connection -->
      <section id="connection" class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Twilio connection</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">
          From the Twilio Console. The Auth Token is encrypted before it is stored and is never shown again, only its last four characters.
        </p>
        <form method="post" class="space-y-5" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_connection">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label for="account_sid" class="block text-sm font-medium text-gray-300 mb-1.5">Account SID</label>
              <input id="account_sid" name="account_sid" class="<?= $field ?> mono" placeholder="AC…" spellcheck="false"
                     value="<?= h($val['twilio_account_sid']) ?>" <?= $src['twilio_account_sid'] === 'env' ? 'readonly' : '' ?>>
              <?= wa_src_note($src['twilio_account_sid']) ?>
            </div>
            <div>
              <label for="auth_token" class="block text-sm font-medium text-gray-300 mb-1.5">Auth Token</label>
              <input id="auth_token" name="auth_token" type="password" class="<?= $field ?> mono" spellcheck="false" autocomplete="new-password"
                     placeholder="<?= $tokenMask !== '' ? h($tokenMask . '  saved. Leave blank to keep it.') : 'Paste the Auth Token' ?>"
                     <?= ($src['twilio_auth_token'] === 'env' || !$keyReady) ? 'readonly' : '' ?>>
              <?= wa_src_note($src['twilio_auth_token']) ?>
              <?php if ($tokenMask !== '' && $src['twilio_auth_token'] !== 'env'): ?>
                <label class="inline-flex items-center gap-2 text-xs text-gray-400 mt-2">
                  <input type="checkbox" name="clear_token" value="1" id="clear_token"> Remove the saved token
                </label>
              <?php endif; ?>
            </div>
            <div>
              <label for="sender" class="block text-sm font-medium text-gray-300 mb-1.5">WhatsApp sender</label>
              <input id="sender" name="sender" class="<?= $field ?> mono" placeholder="+265… or MG…" spellcheck="false"
                     value="<?= h(str_replace('whatsapp:', '', $val['twilio_whatsapp_from'])) ?>" <?= $src['twilio_whatsapp_from'] === 'env' ? 'readonly' : '' ?>>
              <?= wa_src_note($src['twilio_whatsapp_from']) ?>
              <?php if ($isSandbox): ?>
                <p class="text-xs text-amber-300 mt-1">This is Twilio's sandbox number. Only people who have sent it the join code will receive messages.</p>
              <?php endif; ?>
            </div>
            <div></div>
            <div>
              <label for="tpl_reminder" class="block text-sm font-medium text-gray-300 mb-1.5">Lesson reminder template ID <span class="text-gray-500 font-normal">(bloom_lesson_starting)</span></label>
              <input id="tpl_reminder" name="tpl_reminder" class="<?= $field ?> mono" placeholder="HX…" spellcheck="false"
                     value="<?= h($val['whatsapp_template_reminder_sid']) ?>" <?= $src['whatsapp_template_reminder_sid'] === 'env' ? 'readonly' : '' ?>>
              <?= wa_src_note($src['whatsapp_template_reminder_sid']) ?>
            </div>
            <div>
              <label for="tpl_digest" class="block text-sm font-medium text-gray-300 mb-1.5">Daily summary template ID <span class="text-gray-500 font-normal">(bloom_daily_lessons)</span></label>
              <input id="tpl_digest" name="tpl_digest" class="<?= $field ?> mono" placeholder="HX…" spellcheck="false"
                     value="<?= h($val['whatsapp_template_digest_sid']) ?>" <?= $src['whatsapp_template_digest_sid'] === 'env' ? 'readonly' : '' ?>>
              <?= wa_src_note($src['whatsapp_template_digest_sid']) ?>
            </div>
          </div>
          <div class="flex justify-end">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save connection</button>
          </div>
        </form>
      </section>

      <!-- Test -->
      <section id="test" class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Send a test message</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">
          Works whether reminders are on or off. Uses sample lesson details. <?= $testsUsed ?> of <?= WA_TEST_LIMIT_PER_HOUR ?> test messages used in the last hour.
        </p>

        <?php if ($flash['test']): $t = $flash['test']; ?>
          <div class="mb-5 px-4 py-3 rounded-lg border <?= $t['ok'] ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-200' : 'bg-red-500/10 border-red-500/30 text-red-200' ?>" role="status">
            <?php if ($t['ok']): ?>
              Twilio accepted the message to <strong class="mono"><?= h($t['to']) ?></strong>.
              It shows in the delivery log below, and changes to Delivered or Read once your phone receives it.
              <span class="block text-xs mt-1 mono opacity-80"><?= h($t['sid']) ?></span>
            <?php else: ?>
              Not sent to <strong class="mono"><?= h($t['to']) ?></strong>: <?= h($t['error']) ?>
              <?php if ($t['hint']): ?><span class="block mt-1"><?= h($t['hint']) ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($t['assumed']): ?>
              <span class="block text-xs mt-1 opacity-80">The country code was assumed from the leading 0. Include it to be sure.</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-5 items-start">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="send_test">
          <div>
            <label for="to" class="block text-sm font-medium text-gray-300 mb-1.5">Send to</label>
            <input id="to" name="to" type="tel" inputmode="tel" class="<?= $field ?> mono" placeholder="+265999123456" required
                   value="<?= h($myPhone) ?>">
          </div>
          <div>
            <label for="type" class="block text-sm font-medium text-gray-300 mb-1.5">Message</label>
            <select id="type" name="type" class="<?= $field ?>">
              <option value="reminder">Lesson reminder (template)</option>
              <option value="digest">Daily summary (template)</option>
              <option value="text">Plain text</option>
            </select>
          </div>
          <div class="md:pt-7">
            <button class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg"
                    <?= (!$configured || $testsUsed >= WA_TEST_LIMIT_PER_HOUR) ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>
              Send test
            </button>
          </div>
          <div id="bodyWrap" class="md:col-span-3 hidden">
            <label for="body" class="block text-sm font-medium text-gray-300 mb-1.5">Text</label>
            <textarea id="body" name="body" rows="2" maxlength="1000" class="<?= $field ?>"
                      placeholder="Test message from Bloom Education. If you can read this, WhatsApp is working."></textarea>
            <p class="text-xs text-gray-400 mt-1">WhatsApp only delivers plain text if this number has messaged your sender in the last 24 hours. Otherwise use a template.</p>
          </div>
        </form>
      </section>

      <!-- Defaults -->
      <section id="defaults" class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Default reminder choices</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">
          What students and tutors get unless they change it on their own settings page. Changing a default does not override anyone who has already chosen.
        </p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_defaults">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead><tr class="text-left text-gray-400">
                <th class="py-2 pr-4 font-medium">Reminder</th>
                <?php foreach (NP_CHANNELS as $label): ?><th class="py-2 px-3 font-medium text-center w-28"><?= h($label) ?></th><?php endforeach; ?>
              </tr></thead>
              <tbody>
                <?php foreach (NP_KINDS as $kind => [$title, $desc]): ?>
                  <tr class="border-t border-gray-800">
                    <td class="py-3 pr-4"><div class="text-white font-medium"><?= h($title) ?></div><div class="text-xs text-gray-400"><?= h($desc) ?></div></td>
                    <?php foreach (NP_CHANNELS as $ch => $label): ?>
                      <td class="py-3 px-3 text-center">
                        <input type="checkbox" class="h-5 w-5 accent-indigo-500" name="np[<?= h($kind) ?>][<?= h($ch) ?>]" value="1"
                               aria-label="<?= h($label . ': ' . $title) ?>" <?= !empty($defaults[$kind][$ch]) ? 'checked' : '' ?>>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="flex justify-end mt-4">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save defaults</button>
          </div>
        </form>
      </section>

      <!-- Delivery log -->
      <section id="log" class="bg-gray-900 p-6 rounded-lg">
        <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
          <div>
            <h3 class="text-lg font-semibold text-white">Delivery log</h3>
            <p class="text-sm text-gray-400 mt-1">Last 7 days:
              <?php
                $pending = ($stats['queued'] ?? 0) + ($stats['accepted'] ?? 0) + ($stats['sending'] ?? 0) + ($stats['scheduled'] ?? 0);
                $failedN = ($stats['failed'] ?? 0) + ($stats['undelivered'] ?? 0);
              ?>
              <span class="text-sky-300"><?= (int)($stats['read'] ?? 0) ?> read</span> ·
              <span class="text-emerald-300"><?= (int)($stats['delivered'] ?? 0) ?> delivered</span> ·
              <span class="text-gray-200"><?= (int)($stats['sent'] ?? 0) ?> sent</span> ·
              <span class="text-amber-300"><?= $pending ?> waiting</span> ·
              <span class="text-red-300"><?= $failedN ?> failed</span>
            </p>
          </div>
          <form method="get" class="flex flex-wrap gap-3 items-end">
            <div>
              <label for="f_status" class="block text-xs text-gray-400 mb-1">Status</label>
              <select id="f_status" name="status" class="form-input" onchange="this.form.submit()">
                <?php foreach ($STATUS_FILTERS as $k => $l): ?><option value="<?= h($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="f_kind" class="block text-xs text-gray-400 mb-1">Type</label>
              <select id="f_kind" name="kind" class="form-input" onchange="this.form.submit()">
                <option value="all">All</option>
                <?php foreach ($KIND_LABELS as $k => $l): ?><option value="<?= h($k) ?>" <?= $fKind === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <noscript><button class="bg-gray-700 text-white px-3 py-2 rounded-lg">Filter</button></noscript>
          </form>
        </div>

        <?php if (!$rows): ?>
          <p class="text-gray-400 text-sm py-8 text-center">No WhatsApp messages<?= ($fStatus !== 'all' || $fKind !== 'all') ? ' match these filters' : ' yet' ?>.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead><tr class="text-left text-gray-400 border-b border-gray-800">
                <th class="py-2 pr-3 font-medium">When</th>
                <th class="py-2 pr-3 font-medium">To</th>
                <th class="py-2 pr-3 font-medium">Type</th>
                <th class="py-2 pr-3 font-medium">Status</th>
                <th class="py-2 font-medium">Detail</th>
              </tr></thead>
              <tbody>
                <?php foreach ($rows as $r):
                  $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                  $isFail = in_array($r['status'], ['failed', 'undelivered'], true);
                  $detail = $isFail ? ($r['error_message'] ?: ('Twilio error ' . $r['error_code'])) : '';
                  if ($isFail && $r['error_code'] && ($hint = wa_error_text($r['error_code'])) && $hint !== $detail) $detail .= ' ' . $hint;
                  $stamp = $r['read_at'] ? 'Read ' . wa_when($r['read_at'], $adminTz)
                         : ($r['delivered_at'] ? 'Delivered ' . wa_when($r['delivered_at'], $adminTz) : '');
                ?>
                  <tr class="border-b border-gray-800 align-top">
                    <td class="py-2.5 pr-3 whitespace-nowrap text-gray-300"><?= h(wa_when($r['created_at'], $adminTz)) ?></td>
                    <td class="py-2.5 pr-3">
                      <?php if ($who !== ''): ?><div class="text-white"><?= h($who) ?> <span class="text-xs text-gray-500"><?= h($r['role'] ?? '') ?></span></div><?php endif; ?>
                      <div class="mono text-xs text-gray-400"><?= h($r['to_e164']) ?></div>
                    </td>
                    <td class="py-2.5 pr-3 text-gray-300">
                      <?= h($KIND_LABELS[$r['kind']] ?? $r['kind']) ?>
                      <?php if ($r['kind'] === 'test' && $r['by_first']): ?><div class="text-xs text-gray-500">by <?= h($r['by_first']) ?></div><?php endif; ?>
                    </td>
                    <td class="py-2.5 pr-3 whitespace-nowrap"><?= wa_pill((string)$r['status']) ?></td>
                    <td class="py-2.5 text-gray-300">
                      <?php if ($detail !== ''): ?><div class="text-red-300"><?= h($detail) ?></div><?php endif; ?>
                      <?php if ($stamp !== ''): ?><div class="text-xs text-gray-400"><?= h($stamp) ?></div><?php endif; ?>
                      <?php if ($r['provider_sid']): ?><div class="mono text-[11px] text-gray-500"><?= h($r['provider_sid']) ?></div><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php $pages = (int)ceil($total / WA_PAGE_SIZE); if ($pages > 1): ?>
            <div class="flex items-center justify-between mt-4 text-sm text-gray-400">
              <span>Page <?= $page ?> of <?= $pages ?> (<?= $total ?> messages)</span>
              <span class="flex gap-2">
                <?php if ($page > 1): ?><a class="px-3 py-1.5 rounded-lg bg-gray-700 text-white" href="<?= h(wa_qs(['page' => $page - 1])) ?>">Newer</a><?php endif; ?>
                <?php if ($page < $pages): ?><a class="px-3 py-1.5 rounded-lg bg-gray-700 text-white" href="<?= h(wa_qs(['page' => $page + 1])) ?>">Older</a><?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($notSent): ?>
          <h4 class="text-sm font-semibold text-white mt-8 mb-1">Reminders we could not send (last 7 days)</h4>
          <p class="text-xs text-gray-400 mb-3">These never reached Twilio, usually because the person has no usable number. They still got the email.</p>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <tbody>
                <?php foreach ($notSent as $n): ?>
                  <tr class="border-b border-gray-800">
                    <td class="py-2 pr-3 whitespace-nowrap text-gray-400"><?= h(wa_when($n['created_at'], $adminTz)) ?></td>
                    <td class="py-2 pr-3 text-white"><?= h(trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: 'Unknown user') ?> <span class="text-xs text-gray-500"><?= h($n['role'] ?? '') ?></span></td>
                    <td class="py-2 pr-3 text-gray-300"><?= h($KIND_LABELS[$n['kind']] ?? $n['kind']) ?><?= $n['lesson_id'] ? ' · lesson #' . (int)$n['lesson_id'] : '' ?></td>
                    <td class="py-2 text-gray-300"><?= h($n['detail'] ?? $n['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Audit -->
      <?php if ($audit): ?>
        <section class="bg-gray-900 p-6 rounded-lg">
          <h3 class="text-lg font-semibold text-white mb-3">Recent changes</h3>
          <ul class="text-sm space-y-1.5">
            <?php
              $AUDIT_LABELS = [
                'twilio_account_sid' => 'Account SID', 'twilio_auth_token' => 'Auth Token', 'twilio_whatsapp_from' => 'sender',
                'whatsapp_template_reminder_sid' => 'lesson reminder template', 'whatsapp_template_digest_sid' => 'daily summary template',
                'whatsapp_enabled' => 'on/off switch', 'notify.default_prefs' => 'default reminder choices',
                'twilio_credentials' => 'connection', 'whatsapp_test' => 'test message',
              ];
              $VERBS = ['set' => 'changed the', 'cleared' => 'removed the', 'verified' => 'checked the', 'test' => 'sent a'];
              foreach ($audit as $a): ?>
              <li class="text-gray-300">
                <span class="text-gray-500"><?= h(wa_when($a['created_at'], $adminTz)) ?></span>
                <?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'Someone') ?>
                <?= h($VERBS[$a['action']] ?? $a['action']) ?> <?= h($AUDIT_LABELS[$a['setting_key']] ?? $a['setting_key']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </div>
  </main>

  <script>
    (function () {
      var type = document.getElementById('type'), wrap = document.getElementById('bodyWrap');
      if (type && wrap) {
        var sync = function () { wrap.classList.toggle('hidden', type.value !== 'text'); };
        type.addEventListener('change', sync); sync();
      }
      var clear = document.getElementById('clear_token');
      if (clear) clear.closest('form').addEventListener('submit', function (e) {
        if (clear.checked && !confirm('Remove the saved Auth Token? WhatsApp stops sending until a new one is entered.')) e.preventDefault();
      });
    })();
  </script>
</body>
</html>
