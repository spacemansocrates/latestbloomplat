<?php
/**
 * utils/secret_box.php
 * Encryption at rest for credentials kept in the database (app_settings).
 *
 * Why this exists: an admin can now paste the Twilio auth token into a settings
 * screen, so it has to live in the database. A plain value there is readable by
 * anyone with a database dump or backup, and the nightly backups sit on disk. The
 * token is encrypted with a key that lives ONLY in the server .env
 * (APP_ENCRYPTION_KEY), so a leaked dump without the .env is useless.
 *
 * Format: "enc:v1:" . base64(iv[12] . tag[16] . ciphertext), AES-256-GCM.
 * GCM authenticates as well as encrypts, so a tampered or truncated value fails to
 * decrypt instead of decrypting to garbage. The setting's own key name is bound in
 * as associated data, so a ciphertext copied into a different setting will not
 * decrypt either.
 *
 * openssl rather than sodium: sodium is not compiled into the XAMPP PHP on the
 * development machine, and openssl is present everywhere this app runs.
 *
 * Generate a key with: php tools/generate_encryption_key.php
 */

declare(strict_types=1);

const SECRET_PREFIX = 'enc:v1:';

/** The 32-byte key from APP_ENCRYPTION_KEY, or null when it is missing or malformed. */
function secret_key(): ?string {
    $raw = getenv('APP_ENCRYPTION_KEY');
    if ($raw === false) $raw = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (str_starts_with($raw, 'base64:')) $raw = substr($raw, 7);
    $key = base64_decode($raw, true);
    return ($key !== false && strlen($key) === 32) ? $key : null;
}

function secret_available(): bool {
    return secret_key() !== null;
}

function secret_is_encrypted(?string $value): bool {
    return $value !== null && str_starts_with($value, SECRET_PREFIX);
}

/**
 * @param string $context  what the secret is for (the app_settings key). Bound in as
 *                         GCM associated data, so the value only decrypts under it.
 * @throws RuntimeException when no key is configured. Refusing is deliberate: the
 *         alternative is silently storing the secret in plain text.
 */
function secret_encrypt(string $plain, string $context): string {
    $key = secret_key();
    if ($key === null) {
        throw new RuntimeException('APP_ENCRYPTION_KEY is not set on the server, so secrets cannot be stored.');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return SECRET_PREFIX . base64_encode($iv . $tag . $ct);
}

/** The plain value, or null when it cannot be decrypted (no key, wrong key, tampered, wrong context). */
function secret_decrypt(string $value, string $context): ?string {
    if (!secret_is_encrypted($value)) return null;
    $key = secret_key();
    if ($key === null) return null;
    $bin = base64_decode(substr($value, strlen(SECRET_PREFIX)), true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) return null;
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
    return $pt === false ? null : $pt;
}

/** "••••1234" for showing that a secret is saved without showing it. */
function secret_mask(?string $plain): string {
    $plain = (string)$plain;
    if ($plain === '') return '';
    return '••••' . (strlen($plain) >= 8 ? substr($plain, -4) : '');
}
