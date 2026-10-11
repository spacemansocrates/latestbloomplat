<?php
/**
 * utils/notification_prefs.php
 * Who wants which reminder, on which channel.
 *
 * Two layers: the admin default for everyone (app_settings notify.default_prefs,
 * edited on admin/whatsapp.php) and each user's own choice
 * (notification_preferences). A user with no row follows the default, so moving a
 * default reaches everyone who has not chosen for themselves.
 *
 * These preferences only decide whether a person WANTS a channel. Whether WhatsApp
 * is switched on at all is the admin's master switch (whatsapp_enabled()), checked
 * separately, so turning WhatsApp off for the school never rewrites anyone's choice.
 */

declare(strict_types=1);

const NP_KINDS = [
    'lesson_reminder' => ['Lesson starting soon', 'About 15 minutes before each lesson, with a link to join.'],
    'daily_digest'    => ["Today's lessons", 'One message in the morning listing the day, on days you have lessons.'],
];
const NP_CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];

/** Everything on, until an admin decides otherwise. */
function np_builtin_defaults(): array {
    $out = [];
    foreach (NP_KINDS as $k => $_) foreach (NP_CHANNELS as $c => $_l) $out[$k][$c] = true;
    return $out;
}

/** The admin defaults, filled out to every kind and channel. */
function np_defaults(PDO $pdo): array {
    $out = np_builtin_defaults();
    try {
        $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'notify.default_prefs' LIMIT 1");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) {
            foreach ($out as $k => $chs) foreach ($chs as $c => $_) {
                if (isset($j[$k][$c])) $out[$k][$c] = (bool)$j[$k][$c];
            }
        }
    } catch (Throwable $e) { /* unreadable settings: builtin defaults */ }
    return $out;
}

function np_save_defaults(PDO $pdo, array $prefs): void {
    $clean = [];
    foreach (NP_KINDS as $k => $_) foreach (NP_CHANNELS as $c => $_l) $clean[$k][$c] = !empty($prefs[$k][$c]) ? 1 : 0;
    $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('notify.default_prefs', ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([json_encode($clean)]);
}

/**
 * One user's effective preferences: [kind][channel] => bool, plus which ones they
 * chose themselves ([kind][channel] => true in 'own').
 */
function np_for_user(PDO $pdo, int $userId): array {
    $prefs = np_defaults($pdo);
    $own = [];
    try {
        $st = $pdo->prepare("SELECT kind, channel, enabled FROM notification_preferences WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($prefs[$r['kind']][$r['channel']])) continue;
            $prefs[$r['kind']][$r['channel']] = (bool)$r['enabled'];
            $own[$r['kind']][$r['channel']] = true;
        }
    } catch (Throwable $e) { /* table missing before the migration: defaults apply */ }
    return ['prefs' => $prefs, 'own' => $own];
}

/**
 * Does this person want this kind of message on this channel?
 * Cached per request, because the cron jobs ask once per recipient per channel.
 */
function np_allowed(PDO $pdo, int $userId, string $kind, string $channel): bool {
    static $cache = [];
    if (!isset($cache[$userId])) $cache[$userId] = np_for_user($pdo, $userId)['prefs'];
    return $cache[$userId][$kind][$channel] ?? true;
}

/**
 * Save a user's choices from a posted form (np[kind][channel] checkboxes). Every
 * combination is written, so a user who saves once is pinned to what they saw
 * rather than silently following later default changes.
 */
function np_save_user(PDO $pdo, int $userId, array $posted): void {
    $st = $pdo->prepare("INSERT INTO notification_preferences (user_id, kind, channel, enabled)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)");
    foreach (NP_KINDS as $k => $_) foreach (NP_CHANNELS as $c => $_l) {
        $st->execute([$userId, $k, $c, !empty($posted[$k][$c]) ? 1 : 0]);
    }
}
