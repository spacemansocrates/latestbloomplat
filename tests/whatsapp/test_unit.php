<?php
/**
 * tests/whatsapp/test_unit.php <app dir>
 * The pieces that do not need a web server: encryption, signatures, status
 * ordering, setting resolution and preferences.
 *
 * Loads the code from the disposable app copy, never the repository, because the
 * integration loads the .env next to it and the repository's .env is production.
 */

declare(strict_types=1);

require_once __DIR__ . '/fixtures.php';

$app = $argv[1] ?? '';
if (!is_file($app . '/integrations/twilio_whatsapp.php') || is_file($app . '/.git')) {
    fwrite(STDERR, "usage: php test_unit.php <disposable app dir>\n");
    exit(1);
}

require_once $app . '/utils/env_loader.php';
require_once $app . '/integrations/twilio_whatsapp.php';   // loads the app copy's .env
require_once $app . '/utils/notification_prefs.php';
// That .env sets APP_ENCRYPTION_KEY. Clear it AFTER loading, or loading puts it back,
// so the first checks can prove the refusal without a key.
putenv('APP_ENCRYPTION_KEY'); unset($_ENV['APP_ENCRYPTION_KEY']);

echo "--- secret box ---\n";
check(!secret_available(), 'no key configured: secrets are unavailable');
$threw = false;
try { secret_encrypt('x', 'k'); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'encrypting without a key refuses rather than storing plain text');

putenv('APP_ENCRYPTION_KEY=' . WA_TEST_KEY);
check(secret_available(), 'a 32-byte base64 key is accepted');
$a = secret_encrypt(WA_TOKEN, 'twilio_auth_token');
$b = secret_encrypt(WA_TOKEN, 'twilio_auth_token');
check(str_starts_with($a, 'enc:v1:') && !str_contains($a, WA_TOKEN), 'ciphertext is prefixed and does not contain the secret');
check($a !== $b, 'two encryptions of the same value differ (random IV)');
check(secret_decrypt($a, 'twilio_auth_token') === WA_TOKEN, 'round trip returns the original');
check(secret_decrypt($a, 'twilio_account_sid') === null, 'a ciphertext moved to another setting does not decrypt');
$bin = base64_decode(substr($a, 7));
$bin[strlen($bin) - 1] = chr(ord($bin[strlen($bin) - 1]) ^ 1);
check(secret_decrypt('enc:v1:' . base64_encode($bin), 'twilio_auth_token') === null, 'a tampered ciphertext does not decrypt');
putenv('APP_ENCRYPTION_KEY=base64:' . base64_encode(str_repeat('z', 32)));
check(secret_decrypt($a, 'twilio_auth_token') === null, 'a different key cannot decrypt');
putenv('APP_ENCRYPTION_KEY=base64:c2hvcnQ=');
check(!secret_available(), 'a key of the wrong length is rejected');
putenv('APP_ENCRYPTION_KEY=' . WA_TEST_KEY);
check(secret_mask(WA_TOKEN) === '••••1234', 'mask shows only the last four characters');

echo "--- Twilio signature ---\n";
$url = 'https://portal.example/api/twilio_status.php';
$p = ['MessageSid' => 'SM' . str_repeat('0', 32), 'MessageStatus' => 'delivered', 'To' => 'whatsapp:+265991000001'];
$sig = twilio_sign(WA_TOKEN, $url, $p);
check(twilio_signature_valid(WA_TOKEN, $url, $p, $sig), 'a correctly signed callback verifies');
check(!twilio_signature_valid(WA_TOKEN, $url, ['MessageStatus' => 'read'] + $p, $sig), 'changing a parameter breaks the signature');
check(!twilio_signature_valid(WA_TOKEN, $url . '?x=1', $p, $sig), 'a different URL breaks the signature');
check(!twilio_signature_valid(WA_BADTOK, $url, $p, $sig), 'the wrong token does not verify');
check(!twilio_signature_valid(WA_TOKEN, $url, $p, ''), 'a missing signature does not verify');
check(!twilio_signature_valid(WA_TOKEN, $url, $p + ['x' => ['a']], $sig), 'array parameters are refused');

echo "--- status ordering ---\n";
check(wa_status_rank('read') > wa_status_rank('delivered') && wa_status_rank('delivered') > wa_status_rank('sent'),
      'read > delivered > sent');
check(wa_status_rank('failed') > wa_status_rank('sent'), 'failed after sent is news');
check(wa_status_rank('nonsense') === 0, 'an unknown status ranks zero');
check(wa_error_text('63016') !== null && wa_error_text('99999') === null, 'known error codes have plain-language text');

echo "--- setting resolution ---\n";
$pdo = ct_pdo();
$pdo->exec("DELETE FROM app_settings WHERE `key` LIKE 'twilio%' OR `key` LIKE 'whatsapp%'");
check(twilio_config($pdo) === null && !whatsapp_enabled($pdo), 'nothing configured: no config, not enabled');
$put = $pdo->prepare('REPLACE INTO app_settings (`key`, `value`) VALUES (?, ?)');
$put->execute(['twilio_account_sid', WA_SID]);
$put->execute(['twilio_auth_token', secret_encrypt(WA_TOKEN, 'twilio_auth_token')]);
$put->execute(['twilio_whatsapp_from', WA_FROM]);
$cfg = twilio_config($pdo);
check($cfg !== null && $cfg['token'] === WA_TOKEN, 'an encrypted token in app_settings is decrypted for use');
check(($cfg['from'] ?? '') === 'whatsapp:' . WA_FROM, 'a bare sender number gets the whatsapp: prefix');
check(twilio_setting_source($pdo, 'twilio_auth_token') === 'db', 'source: db');
check(!whatsapp_enabled($pdo), 'configured but not switched on: still off');
$put->execute(['whatsapp_enabled', 'true']);
check(whatsapp_enabled($pdo), 'configured and switched on: enabled');

putenv('TWILIO_AUTH_TOKEN=' . WA_BADTOK);
check(twilio_config($pdo)['token'] === WA_BADTOK && twilio_setting_source($pdo, 'twilio_auth_token') === 'env',
      'a value in the server .env wins over the database');
putenv('TWILIO_AUTH_TOKEN');

$put->execute(['twilio_auth_token', WA_TOKEN]);
check(twilio_setting_source($pdo, 'twilio_auth_token') === 'db_plain' && twilio_config($pdo)['token'] === WA_TOKEN,
      'a token saved before encryption still works and is flagged for re-saving');
$put->execute(['twilio_auth_token', 'enc:v1:' . base64_encode(random_bytes(40))]);
check(twilio_setting_source($pdo, 'twilio_auth_token') === 'db_unreadable' && twilio_config($pdo) === null,
      'an undecryptable token is reported and treated as missing, never sent garbled');
$pdo->exec("DELETE FROM app_settings WHERE `key` LIKE 'twilio%' OR `key` LIKE 'whatsapp%'");

echo "--- preferences ---\n";
$pdo->exec("DELETE FROM notification_preferences");
$pdo->exec("DELETE FROM app_settings WHERE `key` = 'notify.default_prefs'");
check(np_for_user($pdo, 2)['prefs']['daily_digest']['whatsapp'] === true, 'with no defaults saved, everything is on');
np_save_defaults($pdo, ['lesson_reminder' => ['email' => 1, 'whatsapp' => 1], 'daily_digest' => ['email' => 1]]);
check(np_for_user($pdo, 2)['prefs']['daily_digest']['whatsapp'] === false, 'an admin default reaches users who have not chosen');
np_save_user($pdo, 2, ['daily_digest' => ['whatsapp' => 1]]);
$p2 = np_for_user($pdo, 2);
check($p2['prefs']['daily_digest']['whatsapp'] === true && $p2['prefs']['lesson_reminder']['email'] === false,
      "a user's own choice overrides the default, and unticked means off");
check(count($pdo->query('SELECT * FROM notification_preferences WHERE user_id = 2')->fetchAll()) === 4,
      'saving pins all four combinations');
np_save_defaults($pdo, ['lesson_reminder' => ['email' => 1, 'whatsapp' => 1], 'daily_digest' => ['email' => 1, 'whatsapp' => 1]]);
check(np_for_user($pdo, 2)['prefs']['lesson_reminder']['email'] === false, 'changing a default does not override someone who chose');
check(np_for_user($pdo, 3)['prefs']['lesson_reminder']['email'] === true, 'but does reach someone who did not');
$pdo->exec("DELETE FROM notification_preferences");
$pdo->exec("DELETE FROM app_settings WHERE `key` = 'notify.default_prefs'");

finish('unit');
