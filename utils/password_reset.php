<?php
/**
 * Password reset tokens and the email that carries them.
 *
 * Design notes, because these choices are easy to undo by accident:
 *  - The token is 32 random bytes. Only its SHA-256 hash is stored, so a leak of the
 *    password_resets table cannot be turned into account access.
 *  - Tokens expire after an hour and are single use.
 *  - Requesting a new link invalidates any earlier unused ones for that account.
 *  - Nothing here ever reveals whether an email address is registered. The caller must
 *    show the same response either way.
 */

declare(strict_types=1);

const PR_TOKEN_BYTES   = 32;
const PR_TTL_MINUTES   = 60;
const PR_MAX_PER_HOUR  = 5;   // per account, and separately per IP
const PR_MIN_PASSWORD  = 10;

/**
 * Has this account (or this IP) asked for too many links in the last hour?
 */
function pr_rate_limited(PDO $pdo, ?int $userId, string $ip): bool
{
    if ($userId !== null) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_resets
                             WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() >= PR_MAX_PER_HOUR) return true;
    }
    if ($ip !== '') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_resets
                             WHERE requested_ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= PR_MAX_PER_HOUR * 3) return true;
    }
    return false;
}

/**
 * Issue a token for a user and return the raw value to email. Any earlier unused token
 * for that user is retired first, so an old link in an old inbox stops working.
 *
 * @return string raw token (64 hex chars)
 */
function pr_issue_token(PDO $pdo, int $userId, string $ip): string
{
    $pdo->prepare('UPDATE password_resets SET used_at = NOW()
                   WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);

    $raw  = bin2hex(random_bytes(PR_TOKEN_BYTES));
    $hash = hash('sha256', $raw);

    $st = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
                         VALUES (?, ?, (NOW() + INTERVAL ' . PR_TTL_MINUTES . ' MINUTE), ?)');
    $st->execute([$userId, $hash, $ip !== '' ? substr($ip, 0, 45) : null]);

    return $raw;
}

/**
 * Look up a token. Returns the joined reset + user row, or null when the token is
 * unknown, already used, expired, or the account has since been deactivated.
 */
function pr_lookup(PDO $pdo, string $rawToken): ?array
{
    if ($rawToken === '' || !preg_match('/^[0-9a-f]{' . (PR_TOKEN_BYTES * 2) . '}$/', $rawToken)) {
        return null;
    }
    $st = $pdo->prepare('SELECT r.id AS reset_id, r.user_id, r.expires_at,
                                u.first_name, u.last_name, u.email, u.role, u.is_active
                         FROM password_resets r
                         JOIN users u ON u.id = r.user_id
                         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
                         LIMIT 1');
    $st->execute([hash('sha256', $rawToken)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['is_active'])) return null;
    return $row;
}

/**
 * Set the new password and burn the token. Also retires that user's other tokens.
 */
function pr_complete(PDO $pdo, array $reset, string $newPassword): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW()
                       WHERE user_id = ? AND used_at IS NULL')
            ->execute([(int) $reset['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Why a password was rejected, or '' when it is acceptable.
 */
function pr_password_problem(string $pw, string $confirm): string
{
    if ($pw === '' || $confirm === '')            return 'Enter your new password twice.';
    if ($pw !== $confirm)                          return 'Those two passwords do not match.';
    if (mb_strlen($pw) < PR_MIN_PASSWORD)          return 'Use at least ' . PR_MIN_PASSWORD . ' characters.';
    if (mb_strlen($pw) > 200)                      return 'That password is too long.';
    if (preg_match('/^\s|\s$/', $pw))              return 'Remove the space at the start or end.';
    return '';
}

function pr_reset_url(string $rawToken): string
{
    $config = require __DIR__ . '/../config/email_config.php';
    return rtrim($config['site_url'], '/') . '/reset_password.php?token=' . urlencode($rawToken);
}

function pr_build_email_html(string $name, string $url): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $mins     = PR_TTL_MINUTES;

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f4f4f4;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:20px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
        <tr>
          <td style="background:linear-gradient(135deg,#6d46ff 0%,#a690ff 100%);padding:18px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:22px;">Reset your Bloom password</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:30px;">
            <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 18px;">Hi <strong>{$safeName}</strong>,</p>
            <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 24px;">
              Someone asked to reset the password for your Bloom account. Click the button below to choose a new one.
              This link works once and expires in {$mins} minutes.
            </p>
            <table cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
              <tr><td style="border-radius:6px;background:#6d46ff;">
                <a href="{$safeUrl}" style="display:inline-block;padding:12px 26px;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;">Choose a new password</a>
              </td></tr>
            </table>
            <p style="color:#666;font-size:13px;line-height:1.6;margin:0 0 8px;">
              If the button does not work, paste this into your browser:
            </p>
            <p style="color:#6d46ff;font-size:12px;line-height:1.5;margin:0 0 24px;word-break:break-all;">{$safeUrl}</p>
            <p style="color:#666;font-size:13px;line-height:1.6;margin:0;">
              <strong>If you did not ask for this, you can ignore this email.</strong>
              Your password stays as it is, and nobody can use this link without opening it from your inbox.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#fafafa;padding:16px;text-align:center;color:#999;font-size:12px;">
            Bloom Education
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function pr_build_email_plain(string $name, string $url): string
{
    $mins = PR_TTL_MINUTES;
    return "Hi {$name},\n\n"
         . "Someone asked to reset the password for your Bloom account.\n"
         . "Open this link to choose a new one. It works once and expires in {$mins} minutes:\n\n"
         . "{$url}\n\n"
         . "If you did not ask for this, ignore this email. Your password stays as it is.\n\n"
         . "Bloom Education\n";
}
