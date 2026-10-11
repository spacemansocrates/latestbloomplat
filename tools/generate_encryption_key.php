<?php
/**
 * tools/generate_encryption_key.php
 * Create the APP_ENCRYPTION_KEY that utils/secret_box.php encrypts credentials with.
 *
 *   php tools/generate_encryption_key.php          print a new key, change nothing
 *   php tools/generate_encryption_key.php --write  add it to .env, unless one is there
 *
 * --write never replaces an existing key: every secret already stored in the
 * database was encrypted with it, and a new key would make them all unreadable.
 * If you really do need to rotate it, re-enter the credentials in the admin screen
 * afterwards.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$key  = 'base64:' . base64_encode(random_bytes(32));
$env  = dirname(__DIR__) . '/.env';

if (!in_array('--write', $argv, true)) {
    echo "APP_ENCRYPTION_KEY=$key\n";
    echo "(not written; run with --write to add it to .env)\n";
    exit(0);
}

$current = is_file($env) ? (string)file_get_contents($env) : '';
if (preg_match('/^\s*APP_ENCRYPTION_KEY\s*=\s*\S+/m', $current)) {
    fwrite(STDERR, ".env already has an APP_ENCRYPTION_KEY. Leaving it alone.\n");
    exit(1);
}

$line = (strlen($current) && !str_ends_with($current, "\n") ? "\n" : '')
      . "# Encrypts credentials stored in app_settings. Never change or lose this.\n"
      . "APP_ENCRYPTION_KEY=$key\n";
if (file_put_contents($env, $line, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Could not write to $env\n");
    exit(1);
}
echo "APP_ENCRYPTION_KEY added to $env\n";
