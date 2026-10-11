<?php
/**
 * tests/whatsapp/test_http.php <app dir>
 * The feature end to end over real HTTP with real sessions: the admin screen,
 * credential storage, test sends, Twilio's status callbacks, the settings pages,
 * and the reminder cron job, with a stub standing in for Twilio.
 */

declare(strict_types=1);

require_once __DIR__ . '/fixtures.php';

$app = $argv[1] ?? '';
if (!is_file($app . '/cron/send_lesson_reminders.php')) { fwrite(STDERR, "usage: php test_http.php <app dir>\n"); exit(1); }
$pdo = ct_pdo();
$setting = static function (string $k) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ?'); $st->execute([$k]);
    $v = $st->fetchColumn(); return $v === false ? null : $v;
};
$CB_URL = WA_BASE . '/api/twilio_status.php';

$ada  = login('ada@example.test');
$mo   = login('mo@example.test');
$sam  = login('sam@example.test');
$tara = login('tara@example.test');
$anon = tempnam(sys_get_temp_dir(), 'wajar');

echo "--- access ---\n";
[$s, $page] = http($ada, 'GET', '/admin/whatsapp.php');
check($s === 200 && str_contains($page, 'Twilio connection'), 'a full admin can open the WhatsApp page');
check(str_contains($page, 'href="whatsapp.php"'), 'the admin sidebar links to it');
[$s] = http($mo, 'GET', '/admin/whatsapp.php');
check($s === 403, 'a Mini Admin cannot');
[$s] = http($sam, 'GET', '/admin/whatsapp.php');
check($s === 403, 'a student cannot');
[$s] = http($anon, 'GET', '/admin/whatsapp.php');
check($s === 302, 'a signed-out visitor is sent to log in');
$csrf = scrape($page, 'csrf');
check($csrf !== '', 'the page carries a CSRF token');

echo "--- saving the connection ---\n";
$conn = static fn(array $over = []) => $over + [
    'csrf' => $csrf, 'action' => 'save_connection', 'account_sid' => WA_SID, 'auth_token' => WA_TOKEN,
    'sender' => WA_FROM, 'tpl_reminder' => WA_TPL_REM, 'tpl_digest' => WA_TPL_DIG,
];
[, $page] = post_follow($ada, '/admin/whatsapp.php', $conn(['csrf' => 'forged']));
check(str_contains($page, 'form expired') && $setting('twilio_account_sid') === null, 'a forged CSRF token saves nothing');
[, $page] = post_follow($ada, '/admin/whatsapp.php', $conn(['account_sid' => 'SK123']));
check(str_contains($page, 'Account SID should be') && $setting('twilio_auth_token') === null,
      'an invalid SID is refused and nothing else in the form is saved either');
[, $page] = post_follow($ada, '/admin/whatsapp.php', $conn(['tpl_reminder' => 'bloom_lesson_starting']));
check(str_contains($page, 'template ID should be'), 'a template name instead of its HX id is refused');

$before = count(wa_stub_requests());
[$s, $page] = post_follow($ada, '/admin/whatsapp.php', $conn());
check($s === 302, 'saving redirects (post/redirect/get)');
check(str_contains($page, 'Connection settings saved'), 'the connection saves');
check(str_contains($page, 'Connected to Twilio as &quot;Bloom Test Account&quot;'), 'saving new credentials checks them with Twilio straight away');
check(count(wa_stub_requests()) === $before + 1, 'the check made exactly one call to Twilio');
$stored = (string)$setting('twilio_auth_token');
check(str_starts_with($stored, 'enc:v1:') && !str_contains($stored, WA_TOKEN), 'the Auth Token is stored encrypted');
check(!str_contains($page, WA_TOKEN) && str_contains($page, '••••1234'), 'the page never shows the token, only its last four');
check(str_contains($page, 'sandbox number'), 'the sandbox sender gets its warning');
$audit = $pdo->query("SELECT setting_key, action FROM settings_audit")->fetchAll();
check(in_array(['setting_key' => 'twilio_auth_token', 'action' => 'set'], $audit, true), 'the token change is audited');
check(!str_contains(json_encode($pdo->query('SELECT * FROM settings_audit')->fetchAll()), WA_TOKEN), 'the audit never holds the value');

post_follow($ada, '/admin/whatsapp.php', $conn(['auth_token' => '']));
check($setting('twilio_auth_token') === $stored, 'saving with the token left blank keeps the saved token');

[, $page] = post_follow($ada, '/admin/whatsapp.php', $conn(['auth_token' => WA_BADTOK]));
check(str_contains($page, 'Twilio rejected'), 'a wrong token is caught by the automatic check');
post_follow($ada, '/admin/whatsapp.php', $conn());
[, $page] = post_follow($ada, '/admin/whatsapp.php', ['csrf' => $csrf, 'action' => 'verify']);
check(str_contains($page, 'Connected to Twilio') && str_contains($page, 'Working'), 'Check connection reports it working again');

echo "--- master switch ---\n";
[, $page] = post_follow($ada, '/admin/whatsapp.php', ['csrf' => $csrf, 'action' => 'set_enabled', 'enabled' => '1']);
check($setting('whatsapp_enabled') === 'true' && str_contains($page, 'reminders are ON'), 'WhatsApp can be switched on');

echo "--- test messages ---\n";
$test = static fn(array $over) => $over + ['csrf' => $csrf, 'action' => 'send_test', 'type' => 'reminder', 'to' => '+265991000009'];
$n0 = count(wa_stub_messages());
[, $page] = post_follow($ada, '/admin/whatsapp.php', $test([]));
$msgs = wa_stub_messages();
$last = end($msgs);
check(count($msgs) === $n0 + 1 && str_contains($page, 'Twilio accepted the message'), 'a template test is sent');
check(($last['To'] ?? '') === 'whatsapp:+265991000009' && ($last['From'] ?? '') === 'whatsapp:' . WA_FROM, 'to the right number, from the saved sender');
check(($last['ContentSid'] ?? '') === WA_TPL_REM && isset(json_decode($last['ContentVariables'] ?? '', true)['5']),
      'using the reminder template with all five variables');
check(($last['StatusCallback'] ?? '') === $CB_URL, 'asking Twilio to report delivery to our callback');
$row = $pdo->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 1")->fetch();
check($row['kind'] === 'test' && (int)$row['created_by'] === 9 && $row['provider_sid'] === $last['sid'] && $row['status'] === 'queued',
      'and it is in the delivery log as a queued test by the admin');
$testSid = $row['provider_sid'];

[, $page] = http($ada, 'GET', '/admin/whatsapp.php');
check(count(wa_stub_messages()) === $n0 + 1, 'reloading the page after sending does not send again');

[, $page] = post_follow($ada, '/admin/whatsapp.php', $test(['to' => WA_INVALID_TO]));
check(str_contains($page, 'Not sent to') && str_contains($page, 'not a valid phone number'), "Twilio's refusal is shown in plain language");
$row = $pdo->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 1")->fetch();
check($row['status'] === 'failed' && $row['error_code'] === '21211' && $row['provider_sid'] === null && $row['failed_at'] !== null,
      'a refused send is logged as failed with its code');

[, $page] = post_follow($ada, '/admin/whatsapp.php', $test(['to' => '12']));
check(str_contains($page, 'Too short'), 'an unusable number is refused before calling Twilio');

post_follow($ada, '/admin/whatsapp.php', $test(['type' => 'text', 'body' => 'hello from the test']));
$msgs = wa_stub_messages(); $last = end($msgs);
check(($last['Body'] ?? '') === 'hello from the test' && !isset($last['ContentSid']), 'a plain-text test sends a body, not a template');
$textSid = $last['sid'];

post_follow($ada, '/admin/whatsapp.php', $test(['type' => 'digest']));
// Four recorded so far (reminder, the refused one, text, digest; the too-short number
// never reached Twilio and is not counted). The fifth is still allowed.
[, $page] = post_follow($ada, '/admin/whatsapp.php', $test([]));
check(str_contains($page, 'Twilio accepted'), 'the fifth test in an hour is allowed');
$n5 = count(wa_stub_messages());
[, $page] = post_follow($ada, '/admin/whatsapp.php', $test([]));
check(str_contains($page, 'test messages in the last hour') && count(wa_stub_messages()) === $n5,
      'the sixth test in an hour is refused without calling Twilio');

echo "--- delivery callbacks ---\n";
$cb = static function (array $params, ?string $sig = null) use ($CB_URL) {
    $sig ??= twilio_sign(WA_TOKEN, $CB_URL, $params);
    return http(tempnam(sys_get_temp_dir(), 'wacb'), 'POST', '/api/twilio_status.php', $params, ['X-Twilio-Signature: ' . $sig]);
};
// Returns an empty row rather than false, so one earlier failure is reported as
// failed checks instead of cascading into warnings on every later line.
$statusOf = static function (?string $sid) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_messages WHERE provider_sid = ?'); $st->execute([(string)$sid]);
    return $st->fetch() ?: ['status' => null, 'delivered_at' => null, 'read_at' => null, 'error_code' => null,
                            'error_message' => null, 'kind' => null, 'to_e164' => null];
};
[$s] = $cb(['MessageSid' => $testSid, 'MessageStatus' => 'delivered', 'To' => 'whatsapp:+265991000009']);
$r = $statusOf($testSid);
check($s === 204 && $r['status'] === 'delivered' && $r['delivered_at'] !== null, 'a signed "delivered" callback updates the message');
[$s] = $cb(['MessageSid' => $testSid, 'MessageStatus' => 'sent']);
check($s === 204 && $statusOf($testSid)['status'] === 'delivered', 'a late "sent" does not move it backwards');
[$s] = $cb(['MessageSid' => $testSid, 'MessageStatus' => 'read'], 'forged');
check($s === 403 && $statusOf($testSid)['status'] === 'delivered', 'a forged signature is refused and changes nothing');
[$s] = http($anon, 'POST', '/api/twilio_status.php', ['MessageSid' => $testSid, 'MessageStatus' => 'read']);
check($s === 403, 'an unsigned callback is refused');
[$s] = http($anon, 'GET', '/api/twilio_status.php');
check($s === 405, 'GET is not allowed');
[$s] = $cb(['MessageSid' => $testSid, 'MessageStatus' => 'read']);
$r = $statusOf($testSid);
check($s === 204 && $r['status'] === 'read' && $r['read_at'] !== null, 'then "read" is recorded');
[$s] = $cb(['MessageSid' => $textSid, 'MessageStatus' => 'undelivered', 'ErrorCode' => '63016']);
$r = $statusOf($textSid);
check($r['status'] === 'undelivered' && $r['error_code'] === '63016' && str_contains((string)$r['error_message'], '24 hours'),
      'an undelivered message keeps its reason in plain language');
$events = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_message_events")->fetchColumn();
check($events === 4, 'every accepted callback is kept in the event history (forged ones are not)');
$unknown = 'SM' . str_repeat('9', 32);
$cb(['MessageSid' => $unknown, 'MessageStatus' => 'delivered', 'To' => 'whatsapp:+265991000777']);
$r = $statusOf($unknown);
check($r && $r['kind'] === 'unknown' && $r['to_e164'] === '+265991000777' && $r['status'] === 'delivered',
      'a callback for a message sent elsewhere is still recorded');
[$s] = $cb(['MessageSid' => 'not-a-sid', 'MessageStatus' => 'delivered']);
check($s === 400, 'a malformed callback is rejected');

[, $page] = http($ada, 'GET', '/admin/whatsapp.php?status=failed&kind=all');
check(str_contains($page, 'Not delivered') && str_contains($page, '24 hours') && !str_contains($page, $testSid),
      'the log filters to failures and explains them');
[, $page] = http($ada, 'GET', '/admin/whatsapp.php');
check(str_contains($page, '>Read<') && preg_match('/1 read/', $page) === 1, 'the log and 7-day summary show the read message');

echo "--- default choices ---\n";
post_follow($ada, '/admin/whatsapp.php', ['csrf' => $csrf, 'action' => 'save_defaults',
    'np' => ['lesson_reminder' => ['email' => 1, 'whatsapp' => 1], 'daily_digest' => ['email' => 1]]]);
$d = json_decode((string)$setting('notify.default_prefs'), true);
check(($d['daily_digest']['whatsapp'] ?? null) === 0 && ($d['lesson_reminder']['whatsapp'] ?? null) === 1, 'admin defaults save');

echo "--- student and tutor settings ---\n";
[$s, $page] = http($sam, 'GET', '/student/settings.php');
check($s === 200 && str_contains($page, 'Lesson reminders'), 'the student settings page shows the reminder card');
check(preg_match('/name="np\[lesson_reminder\]\[whatsapp\]" value="1"\s+aria-label="[^"]*"\s+checked/', $page) === 1
   && preg_match('/name="np\[daily_digest\]\[whatsapp\]" value="1"\s+aria-label="[^"]*"\s+>/', $page) === 1,
      'it starts from the admin defaults');
$stok = scrape($page, 'csrf_token');
[, $page] = post_follow($sam, '/student/settings.php', ['action' => 'update_notif_prefs', 'csrf_token' => 'forged',
    'np' => ['lesson_reminder' => ['email' => 1]]]);
check((int)$pdo->query('SELECT COUNT(*) FROM notification_preferences WHERE user_id = 2')->fetchColumn() === 0,
      'a forged token saves nothing for the student');
[$s, $page] = http($sam, 'POST', '/student/settings.php', ['action' => 'update_notif_prefs', 'csrf_token' => $stok,
    'np' => ['lesson_reminder' => ['email' => 1], 'daily_digest' => ['email' => 1]]]);
check(str_contains($page, 'Reminder choices saved'), 'the student can save their choices');
$sp = $pdo->query("SELECT CONCAT(kind, ':', channel) k, enabled FROM notification_preferences WHERE user_id = 2")->fetchAll(PDO::FETCH_KEY_PAIR);
check(count($sp) === 4 && $sp['lesson_reminder:whatsapp'] === 0 && $sp['lesson_reminder:email'] === 1, 'WhatsApp reminders off, email on, for Sam');

[$s, $page] = http($tara, 'GET', '/tutor/settings.php');
check($s === 200 && str_contains($page, 'Lesson reminders'), 'the tutor settings page shows the reminder card');
$ttok = scrape($page, 'csrf_token');
[, $page] = post_follow($tara, '/tutor/settings.php', ['form_name' => 'notif_prefs', 'csrf_token' => $ttok,
    'np' => ['lesson_reminder' => ['whatsapp' => 1]]]);
check(str_contains($page, 'Reminder choices saved'), 'the tutor can save their choices');
[, $page] = http($tara, 'GET', '/tutor/settings.php');
check(preg_match('/name="np\[lesson_reminder\]\[email\]" value="1"\s+aria-label="[^"]*"\s+>/', $page) === 1,
      'and the page shows what was saved');
[, $page] = http(login('nina@example.test'), 'GET', '/student/settings.php');
check(str_contains($page, 'cannot send you WhatsApp reminders yet'), 'a student with an unusable number is told why');

echo "--- student settings: forged posts ---\n";
$samName = static fn() => $pdo->query('SELECT first_name FROM users WHERE id = 2')->fetchColumn();
$profile = static fn(string $tok, string $first) => ['action' => 'update_profile', 'csrf_token' => $tok,
    'first_name' => $first, 'last_name' => 'Student', 'email' => 'sam@example.test', 'phone_number' => '+265991000002'];
[, $page] = http($sam, 'POST', '/student/settings.php', $profile('forged', 'Hacked'));
check(str_contains($page, 'That form expired') && $samName() === 'Sam', 'a forged profile post changes nothing');
[, $page] = http($sam, 'POST', '/student/settings.php', array_diff_key($profile('', 'Hacked'), ['csrf_token' => 1]));
check($samName() === 'Sam', 'a profile post with no token changes nothing');
[, $page] = http($sam, 'POST', '/student/settings.php', ['action' => 'change_password', 'csrf_token' => 'forged',
    'current_password' => WA_PASSWORD, 'new_password' => 'Hijacked123!', 'confirm_password' => 'Hijacked123!']);
check(str_contains($page, 'That form expired'), 'a forged password change is refused');
[, $page] = http($sam, 'GET', '/student/settings.php');
check(substr_count($page, 'name="csrf_token" value="' . $stok . '"') >= 4, 'every form on the page carries the token');
[, $page] = http($sam, 'POST', '/student/settings.php', $profile($stok, 'Samuel'));
check(str_contains($page, 'Profile updated') && $samName() === 'Samuel', 'the genuine profile form still saves');
http($sam, 'POST', '/student/settings.php', $profile($stok, 'Sam'));

$pdo->exec("INSERT INTO packages (id, name, lessons_per_week, price, duration_days, is_active) VALUES (1, 'Weekly', 1, 20.00, 28, 1)");
$spCount = static fn() => (int)$pdo->query('SELECT COUNT(*) FROM student_packages WHERE student_id = 2')->fetchColumn();
http($sam, 'POST', '/student/packages.php', ['action' => 'start_checkout', 'package_id' => 1, 'csrf_token' => 'forged']);
check($spCount() === 0, 'a forged checkout creates no package');
[, $page] = http($sam, 'POST', '/student/packages.php', ['action' => 'start_checkout', 'package_id' => 1, 'csrf_token' => $stok]);
check($spCount() === 1 && str_contains($page, 'name="csrf_token" value="' . $stok . '"'),
      'the genuine checkout creates the package and hands the token on to payment');
$spId = (int)$pdo->query('SELECT id FROM student_packages WHERE student_id = 2')->fetchColumn();
[$s] = http($sam, 'POST', '/student/api/pay_start.php', ['sp_id' => $spId, 'csrf_token' => 'forged']);
check($s === 403, 'a forged Pay Now is refused');
[$s, $page] = http($sam, 'POST', '/student/api/pay_start.php', ['sp_id' => $spId, 'csrf_token' => $stok]);
check($s !== 403 && str_contains($page, 'Checkout URL is not configured'),
      'the genuine Pay Now gets past the check (and stops at the unconfigured test checkout)');
$pdo->exec('DELETE FROM payments; DELETE FROM student_packages; DELETE FROM packages');

echo "--- the reminder job ---\n";
$cron = static function () use ($app): string {
    $out = []; exec('"' . PHP_BINARY . '" "' . $app . '/cron/send_lesson_reminders.php" 2>&1', $out);
    return implode("\n", $out);
};
$n0 = count(wa_stub_messages());
$out = $cron();
$new = array_slice(wa_stub_messages(), $n0);
// Tara teaches both lessons in the window (501 with Sam, 502 with Nina), so she gets
// one reminder for each. Sam opted out; Nina's number is unusable.
check(count($new) === 2, 'two WhatsApp reminders go out (' . count($new) . ' sent)');
$toTara = array_filter($new, static fn($m) => ($m['To'] ?? '') === 'whatsapp:+265991000001' && ($m['ContentSid'] ?? '') === WA_TPL_REM);
check(count($toTara) === 2, "both are Tara's, on the reminder template");
$links = array_map(static fn($m) => json_decode($m['ContentVariables'] ?? '{}', true), $new);
check(count(array_filter($links, static fn($v) => ($v['1'] ?? '') === 'Tara'
        && str_contains($v['5'] ?? '', '/tutor/join_lesson_live.php?lesson_id=501'))) === 1
   && count(array_filter($links, static fn($v) => str_contains($v['5'] ?? '', 'lesson_id=502'))) === 1,
      'with her name and a link into the tutor side of each room');
check(count(array_filter($new, static fn($m) => ($m['StatusCallback'] ?? '') === $CB_URL)) === 2, 'reminders ask for delivery reports too');
$wm = $pdo->query("SELECT provider_sid, dispatch_key, user_id FROM whatsapp_messages WHERE kind = 'lesson_reminder' ORDER BY provider_sid")->fetchAll();
$dl = $pdo->query("SELECT provider_ref, dedupe_key FROM notification_dispatch_log WHERE channel = 'whatsapp' AND status = 'sent' ORDER BY provider_ref")->fetchAll();
check(count($wm) === 2 && count($dl) === 2
      && array_column($wm, 'provider_sid') === array_column($dl, 'provider_ref')
      && array_column($wm, 'dispatch_key') === array_column($dl, 'dedupe_key')
      && array_unique(array_column($wm, 'user_id')) === [1],
      'the delivery log and the dispatch ledger point at the same messages');
$samWa = (int)$pdo->query("SELECT COUNT(*) FROM notification_dispatch_log WHERE user_id = 2 AND channel = 'whatsapp'")->fetchColumn();
check($samWa === 0, 'Sam opted out of WhatsApp and was not messaged or logged');
$taraEmail = (int)$pdo->query("SELECT COUNT(*) FROM notification_dispatch_log WHERE user_id = 1 AND channel = 'email'")->fetchColumn();
check($taraEmail === 0, 'Tara opted out of email and no email was attempted');
$samEmail = $pdo->query("SELECT status FROM notification_dispatch_log WHERE user_id = 2 AND channel = 'email'")->fetchColumn();
check($samEmail !== false, "Sam's email was still attempted (it fails here only because this copy has no SMTP)");
$nina = $pdo->query("SELECT status, detail FROM notification_dispatch_log WHERE user_id = 3 AND channel = 'whatsapp'")->fetch();
check($nina && $nina['status'] === 'skipped' && str_contains($nina['detail'], 'Unusable number'), "Nina's junk number is skipped with a reason");
check(str_contains($out, 'opted-out='), 'the job reports opt-outs in its summary');

$n1 = count(wa_stub_messages());
$cron();
check(count(wa_stub_messages()) === $n1, 'running the job again sends nothing twice');

[, $page] = http($ada, 'GET', '/admin/whatsapp.php');
check(str_contains($page, 'Reminders we could not send') && str_contains($page, 'Nina'), 'the admin page lists the reminder that could not go out');

echo "--- switching it off ---\n";
post_follow($ada, '/admin/whatsapp.php', ['csrf' => $csrf, 'action' => 'set_enabled']);
check($setting('whatsapp_enabled') === 'false', 'WhatsApp can be switched off');
$pdo->prepare("INSERT INTO lessons (id, tutor_id, student_id, subject_id, scheduled_start_time, scheduled_end_time, status)
               VALUES (503, 1, 2, 1, ?, ?, 'Scheduled')")->execute([gmdate('Y-m-d H:i:s', time() + 480), gmdate('Y-m-d H:i:s', time() + 4080)]);
$n2 = count(wa_stub_messages());
$out = $cron();
check(count(wa_stub_messages()) === $n2 && str_contains($out, 'whatsapp-off='), 'with the switch off, the job sends no WhatsApp at all');
[, $page] = post_follow($ada, '/admin/whatsapp.php', $test([]));
check(str_contains($page, 'test messages in the last hour') || str_contains($page, 'Twilio accepted'),
      'tests still work (or are rate limited) while reminders are off');

finish('http');
