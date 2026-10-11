<?php
// Tutor Settings - Tabbed Interface
require_once __DIR__ . '/auth_tutor_only.php';
$current_page = 'settings';

// Database connection
require_once __DIR__ . '/../config/db_connect.php';
include_once __DIR__ . '/api/earnings.php';
include_once __DIR__ . '/api/notifications.php';
include_once __DIR__ . '/api/learning_path.php';

// Include timezone helper for proper timezone management
require_once __DIR__ . '/../utils/timezone_helper.php';
require_once __DIR__ . '/../utils/phone.php';
require_once __DIR__ . '/../utils/avatar_upload.php';
ensureTimezoneColumn($pdo);

// The three includes above are standalone pages in their own right, and each sets
// $current_page for itself. Included here they overwrite ours (the last one wins,
// so this page claimed to be "learning_path") and the sidebar highlighted nothing.
// Same for the timezone: api/earnings.php hardcodes Africa/Blantyre, which would
// print every date on this page in Malawi time whatever the tutor's own zone is.
$current_page = 'settings';
date_default_timezone_set($_SESSION['timezone'] ?? 'Africa/Blantyre');

// Helper functions
function h($v) { 
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
}





//=== LOAD TUTOR DATA ===
$stmt = $pdo->prepare("SELECT first_name, last_name, email, password_hash, profile_photo_url, timezone, phone_number FROM users WHERE id = ?");
$stmt->execute([$tutor_id]);
$user = $stmt->fetch();
if (!$user) die("User not found.");

// Get user's timezone from users table (for the robust timezone system)
$userTimezone = $user['timezone'] ?? 'UTC';
if (!isValidTimezone($userTimezone)) {
    $userTimezone = 'UTC';
}

// Tutor details (bio)
$stmt = $pdo->prepare("SELECT bio FROM tutor_details WHERE user_id = ?");
$stmt->execute([$tutor_id]);
$tutor_details = $stmt->fetch() ?: ['bio' => ''];

// Payout settings (currency)
$stmt = $pdo->prepare("SELECT hourly_rate, currency FROM tutor_payout_settings WHERE tutor_id = ?");
$stmt->execute([$tutor_id]);
$payout = $stmt->fetch() ?: ['currency' => 'USD', 'hourly_rate' => 0.00];

// User settings (timezone + toggles)
$stmt = $pdo->prepare("SELECT timezone, notify_new_booking, notify_cancellation, notify_reminder_24h FROM user_settings WHERE user_id = ?");
$stmt->execute([$tutor_id]);
$u_settings = $stmt->fetch() ?: [
    'timezone' => 'Africa/Blantyre',
    'notify_new_booking' => 1,
    'notify_cancellation' => 1,
    'notify_reminder_24h' => 0,
];

//=== CURRENCY & TIMEZONE OPTIONS ===
$currency_symbols = [
    'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'MWK' => 'MK',
    'ZAR' => 'R', 'KES' => 'KSh', 'TZS' => 'TSh',
];

// Use common timezones from the timezone helper
$commonTimezones = getCommonTimezones();
$timezone_options = [];
foreach ($commonTimezones as $tz) {
    $timezone_options[$tz] = getTimezoneLabel($tz);
}

//=== POST HANDLERS ===
// Do NOT reset $flash here. api/earnings.php handles the hourly-rate form and
// sets $flash itself; clearing it meant saving your rate gave no feedback at all,
// success or failure. Only the handlers below may replace it, and only when they
// actually run.
$flash = $flash ?? null;

// The three forms on this page had no CSRF token, so any page a signed-in tutor
// visited could silently change their name, their email or their password. The
// token is the one api/earnings.php already generates for the earnings form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function settings_csrf_ok(): bool {
    return isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

$__posted_form = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['form_name'] ?? null) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $flash = ['type' => 'error', 'msg' => 'The upload exceeded the server limit. Choose a JPG/PNG up to 5 MB.'];
}
if (in_array($__posted_form, ['profile', 'prefs', 'password', 'avatar', 'notif_prefs'], true) && !settings_csrf_ok()) {
    $flash = ['type' => 'error', 'msg' => 'That form expired. Please try again.'];
    $__posted_form = null;
}

if ($__posted_form === 'avatar') {
    try {
        $user['profile_photo_url'] = save_profile_avatar($pdo, $tutor_id, $_FILES['avatar'] ?? []);
        $flash = ['type' => 'success', 'msg' => 'Profile picture updated.'];
    } catch (InvalidArgumentException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[avatar] save failed for tutor ' . $tutor_id . ' (' . get_class($e) . ', code ' . $e->getCode() . ')');
        $flash = ['type' => 'error', 'msg' => 'Could not save your picture. Your existing picture is unchanged. Please try again.'];
    }
}

if ($__posted_form === 'notif_prefs') {
    require_once __DIR__ . '/../utils/notification_prefs.php';
    try {
        np_save_user($pdo, $tutor_id, (array)($_POST['np'] ?? []));
        $flash = ['type' => 'success', 'msg' => 'Reminder choices saved.'];
    } catch (Throwable $e) {
        error_log('[settings] reminder prefs failed for tutor ' . $tutor_id . ': ' . $e->getMessage());
        $flash = ['type' => 'error', 'msg' => 'Could not save your reminder choices. Please try again.'];
    }
}

// a) Profile: name/email/bio
if ($__posted_form === 'profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $phoneRaw = trim($_POST['phone_number'] ?? '');

    $first = $fullName;
    $last = '';
    if (strpos($fullName, ' ') !== false) {
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $first = array_shift($parts);
        $last = implode(' ', $parts);
    }

    // users.email is unique. Without this check the UPDATE below threw, and the
    // handler printed the raw PDO message (table and column names included) into
    // the page.
    $emailTaken = false;
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $chk->execute([$email, $tutor_id]);
        $emailTaken = (bool)$chk->fetchColumn();
    }

    // Stored already normalised to E.164 so the reminder jobs never guess a country.
    $phone = null;
    $phoneErr = null;
    if ($phoneRaw !== '') {
        $r = phone_to_e164($phoneRaw);
        if (!$r['ok']) $phoneErr = 'Mobile number: ' . phone_reason_text($r['reason']);
        else $phone = $r['e164'];
    }

    if ($phoneErr !== null) {
        $flash = ['type' => 'error', 'msg' => $phoneErr];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = ['type' => 'error', 'msg' => 'Please enter a valid email address.'];
    } elseif ($fullName === '') {
        $flash = ['type' => 'error', 'msg' => 'Please enter your name.'];
    } elseif ($emailTaken) {
        $flash = ['type' => 'error', 'msg' => 'That email address is already in use by another account.'];
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ? WHERE id = ?");
            $stmt->execute([$first, $last, $email, $phone, $tutor_id]);

            $stmt = $pdo->prepare("
                INSERT INTO tutor_details (user_id, bio) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE bio = VALUES(bio)
            ");
            $stmt->execute([$tutor_id, $bio]);

            $user['first_name'] = $first;
            $user['last_name'] = $last;
            $user['email'] = $email;
            $user['phone_number'] = $phone;
            $tutor_details['bio'] = $bio;
            // The header and sidebar read both halves of the name from the session,
            // so updating only the first name left the old surname on screen until
            // the tutor signed out and back in.
            $_SESSION['user_first_name'] = $first;
            $_SESSION['user_last_name']  = $last;

            $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
        } catch (PDOException $e) {
            // Never show the driver's message: it names tables and columns.
            error_log('[settings] profile update failed for user ' . $tutor_id . ': ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => 'Could not update your profile. Please try again.'];
        }
    }
}

// b) Preferences: currency + timezone + toggles
if ($__posted_form === 'prefs') {
    $currency = $_POST['currency'] ?? $payout['currency'];
    $timezone = $_POST['timezone'] ?? $userTimezone;
    $nb = isset($_POST['notify_new_booking']) ? 1 : 0;
    $nc = isset($_POST['notify_cancellation']) ? 1 : 0;
    $nr = isset($_POST['notify_reminder_24h']) ? 1 : 0;

    if (!array_key_exists($currency, $currency_symbols)) {
        $flash = ['type' => 'error', 'msg' => 'Unsupported currency.'];
    } elseif (!isValidTimezone($timezone)) {
        $flash = ['type' => 'error', 'msg' => 'Unsupported timezone.'];
    } else {
        // Save currency
        $stmt = $pdo->prepare("
            INSERT INTO tutor_payout_settings (tutor_id, currency, hourly_rate)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE currency = VALUES(currency)
        ");
        $stmt->execute([$tutor_id, $currency, $payout['hourly_rate']]);
        $payout['currency'] = $currency;

        // Save timezone to users table (robust timezone system)
        $stmt = $pdo->prepare("UPDATE users SET timezone = ? WHERE id = ?");
        $stmt->execute([$timezone, $tutor_id]);
        $_SESSION['timezone'] = $timezone; // Update session timezone
        $userTimezone = $timezone;

        // Save notification toggles to user_settings
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, timezone, notify_new_booking, notify_cancellation, notify_reminder_24h)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                timezone = VALUES(timezone),
                notify_new_booking = VALUES(notify_new_booking),
                notify_cancellation = VALUES(notify_cancellation),
                notify_reminder_24h = VALUES(notify_reminder_24h)
        ");
        $stmt->execute([$tutor_id, $timezone, $nb, $nc, $nr]);

        $u_settings['timezone'] = $timezone;
        $u_settings['notify_new_booking'] = $nb;
        $u_settings['notify_cancellation'] = $nc;
        $u_settings['notify_reminder_24h'] = $nr;

        $flash = ['type' => 'success', 'msg' => 'Preferences saved.'];
    }
}

// c) Password change
if ($__posted_form === 'password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
    } elseif (strlen($new) < 8) {
        $flash = ['type' => 'error', 'msg' => 'New password must be at least 8 characters.'];
    } elseif ($new !== $confirm) {
        $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
    } elseif ($new === $current) {
        $flash = ['type' => 'error', 'msg' => 'Your new password must be different from your current one.'];
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $tutor_id]);
        // Keep the in-memory copy in step, and give the session a new id so a
        // stolen one stops working the moment the password changes.
        $user['password_hash'] = $hash;
        session_regenerate_id(true);
        $flash = ['type' => 'success', 'msg' => 'Password updated.'];
    }
}

$currency = $payout['currency'];
$currency_symbol = $currency_symbols[$currency] ?? ($currency . ' ');
$_SESSION['profile_photo_url'] = $user['profile_photo_url'] ?? null;

//=== UI DATA ===
$full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$email = $user['email'] ?? '';
$bio_val = $tutor_details['bio'] ?? '';
$currency_val = $payout['currency'] ?? 'USD';
$tz_val = $userTimezone; // Use the timezone from users table
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutor Dashboard - Settings</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="flex min-h-screen">
    <?php 
    // Include centralized page loader component (if not already included by sidebar)
    if (!defined('PAGE_LOADER_INCLUDED')) {
      define('PAGE_LOADER_INCLUDED', true);
      include __DIR__ . '/../components/page_loader.php';
    }
    ?>

    <?php include 'components/sidebar.php'; ?>

    <main class="flex-1 p-6 lg:p-8">
        <?php 
            $header_title = "Settings";
            $header_subtitle = "Manage your account, preferences, and notification settings.";
            include 'components/header.php';
        ?>

        <?php if (isset($_GET['need_tz'])): ?>
            <div class="mb-6 p-4 rounded-lg bg-amber-50 text-amber-900 border border-amber-300 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/30">
                <strong>Please set your timezone.</strong> Lesson times are shown in your local time, so we need to know where you are. Choose your timezone under <em>Preferences</em> below and save — then you'll be able to use the rest of the app.
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $flash['type']==='success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20' : 'bg-red-50 text-red-800 border border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20'; ?>">
                <?php echo htmlspecialchars($flash['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="mb-6 border-b border-slate-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Tabs">
                <button type="button" data-tab="profile" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-indigo-500 text-indigo-600 dark:text-indigo-400 flex items-center">
                    <svg class="w-4 h-4 mr-2" data-lucide="user" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-user"></use></svg>
                    Profile
                </button>
                <button type="button" data-tab="earnings" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-slate-600 dark:text-gray-400 hover:text-slate-900 dark:hover:text-gray-300 hover:border-gray-400 flex items-center">
                    <svg class="w-4 h-4 mr-2" data-lucide="dollar-sign" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-dollar-sign"></use></svg>
                    Earnings
                </button>
                <button type="button" data-tab="learning-path" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-slate-600 dark:text-gray-400 hover:text-slate-900 dark:hover:text-gray-300 hover:border-gray-400 flex items-center">
                    <svg class="w-4 h-4 mr-2" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
                    Learning Path
                </button>
                <button type="button" data-tab="notifications" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-slate-600 dark:text-gray-400 hover:text-slate-900 dark:hover:text-gray-300 hover:border-gray-400 flex items-center">
                    <svg class="w-4 h-4 mr-2" data-lucide="bell" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-bell"></use></svg>
                    Notifications
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div id="tab-content">
            <!-- Profile Tab (default) -->
            <div id="profile-tab" class="tab-pane">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- Profile -->
                    <section class="lg:col-span-2 glass rounded-xl p-6">
                        <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Profile</h2>
                        <p class="text-slate-600 dark:text-slate-300 mb-5">Update your personal details and public profile.</p>

                        <form method="post" enctype="multipart/form-data" action="settings.php#profile" class="mb-6 space-y-3" id="avatar-form">
                            <input type="hidden" name="form_name" value="avatar">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <h3 class="font-semibold">Profile picture</h3>
                            <div class="flex flex-wrap items-center gap-5">
                                <div><p class="text-sm mb-2">Current picture</p><?= photo_img($user['profile_photo_url'], $full_name, 'w-20 h-20 rounded-full object-cover') ?></div>
                                <div id="avatar-preview-wrap" hidden><p class="text-sm mb-2">Selected replacement</p><img id="avatar-preview" alt="Selected picture preview" class="w-20 h-20 rounded-full object-cover"></div>
                            </div>
                            <label for="avatar-file" class="block text-sm font-medium">Upload profile picture (JPG or PNG, up to 5 MB)</label>
                            <input type="file" name="avatar" id="avatar-file" accept="image/jpeg,image/png" required class="input-field">
                            <p id="avatar-preview-error" role="status" class="text-sm text-red-600 dark:text-red-300"></p>
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg">Save picture</button>
                        </form>
                        <form method="post" class="space-y-6">
                            <input type="hidden" name="form_name" value="profile">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <div class="md:col-span-2">
                                <label for="fullName" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Full Name</label>
                                <input type="text" id="fullName" name="full_name" value="<?php echo htmlspecialchars($full_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="input-field" required>
                            </div>
                            <div class="md:col-span-2">
                                <label for="email" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="input-field" required>
                            </div>
                            <div class="md:col-span-2">
                                <label for="phoneNumber" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Mobile Number (WhatsApp)</label>
                                <input type="tel" id="phoneNumber" name="phone_number" inputmode="tel"
                                       value="<?php echo htmlspecialchars($user['phone_number'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                       class="input-field" placeholder="+265 999 12 34 56">
                                <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">
                                    Start with your country code, e.g. <code>+265</code>. Used for lesson reminders on WhatsApp.
                                </p>
                            </div>
                            <div>
                                <label for="bio" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Profile Bio / Headline</label>
                                <textarea id="bio" name="bio" rows="3" class="input-field" placeholder="e.g., Passionate math tutor with 5+ years of experience..."><?php echo htmlspecialchars($bio_val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="bg-indigo-600 text-white font-semibold py-2.5 px-5 rounded-lg hover:bg-indigo-500 transition-colors">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Preferences -->
                    <section class="glass rounded-xl p-6">
                        <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Preferences</h2>
                        <p class="text-slate-600 dark:text-slate-300 mb-5">Customize your dashboard experience.</p>

                        <form method="post" class="space-y-6">
                            <input type="hidden" name="form_name" value="prefs">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                            <div>
                                <label id="currency-label" class="text-base font-medium text-slate-900 dark:text-white">Currency</label>
                                <p class="text-sm text-slate-600 dark:text-gray-400 mt-1 mb-2">Select the currency for your earnings display.</p>
                                <div class="dropdown-container relative">
                                  <button type="button" class="dropdown-trigger ui-button w-full justify-between text-left bg-white dark:bg-gray-800 border-slate-300 dark:border-gray-600 hover:bg-slate-200 dark:hover:bg-gray-700">
                                    <span class="truncate">
                                      <?php
                                      $displayText = '';
                                      foreach ($currency_symbols as $code => $sym) {
                                        if ($currency_val === $code) {
                                          $displayText = htmlspecialchars($code . ' - ' . $sym);
                                          break;
                                        }
                                      }
                                      echo $displayText ?: htmlspecialchars($currency_val . ' - ' . ($currency_symbols[$currency_val] ?? $currency_val));
                                      ?>
                                    </span>
                                    <svg class="w-4 h-4 ml-2 transition-transform" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                                  </button>
                                  <input type="hidden" name="currency" value="<?= htmlspecialchars($currency_val) ?>">
                                  <div class="dropdown-panel absolute top-full left-0 right-0 mt-1 z-50 hidden">
                                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 max-h-48 overflow-y-auto">
                                      <?php foreach ($currency_symbols as $code => $sym): ?>
                                        <button type="button"
                                                class="dropdown-option w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors first:rounded-t-lg last:rounded-b-lg"
                                                data-value="<?= htmlspecialchars($code) ?>"
                                                data-target="currency">
                                          <span class="text-sm text-gray-900 dark:text-white font-medium">
                                            <?= htmlspecialchars($code . ' - ' . $sym) ?>
                                          </span>
                                        </button>
                                      <?php endforeach; ?>
                                    </div>
                                  </div>
                                </div>
                            </div>

                            <div>
                                <label id="timezone-label" class="text-base font-medium text-slate-900 dark:text-white">Your Timezone</label>
                                <p class="text-sm text-slate-600 dark:text-gray-400 mt-1 mb-2">Lesson times will be displayed in your local timezone. When you schedule lessons, times are interpreted as your local time.</p>
                                <div class="dropdown-container relative">
                                  <button type="button" class="dropdown-trigger ui-button w-full justify-between text-left bg-white dark:bg-gray-800 border-slate-300 dark:border-gray-600 hover:bg-slate-200 dark:hover:bg-gray-700">
                                    <span class="truncate">
                                      <?php
                                      $displayText = isset($timezone_options[$tz_val]) ? $timezone_options[$tz_val] : $tz_val;
                                      echo htmlspecialchars($displayText);
                                      ?>
                                    </span>
                                    <svg class="w-4 h-4 ml-2 transition-transform" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                                  </button>
                                  <input type="hidden" name="timezone" value="<?= htmlspecialchars($tz_val) ?>">
                                  <div class="dropdown-panel absolute top-full left-0 right-0 mt-1 z-50 hidden">
                                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 max-h-64 overflow-y-auto">
                                      <!-- 419 timezones is a lot to scroll past. -->
                                      <div class="sticky top-0 p-2 bg-white dark:bg-gray-800 border-b border-slate-200 dark:border-gray-700">
                                        <label for="tz-search" class="sr-only">Search timezones</label>
                                        <input id="tz-search" type="search" autocomplete="off"
                                               placeholder="Search, e.g. Johannesburg"
                                               class="w-full text-sm rounded-md px-3 py-2 bg-slate-50 dark:bg-white/5 text-slate-800 dark:text-slate-100 border border-slate-200 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                                        <button type="button" id="tz-detect"
                                                class="mt-2 w-full text-xs font-semibold px-3 py-1.5 rounded-md bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-500/25 transition-colors">
                                          Use the timezone from this device
                                        </button>
                                      </div>
                                      <p id="tz-empty" class="hidden px-4 py-6 text-center text-sm text-slate-600 dark:text-slate-300">No timezone matches that.</p>
                                      <!-- Common Timezones -->
                                      <div class="tz-group px-3 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wide">Common Timezones</span>
                                      </div>
                                      <?php foreach ($timezone_options as $tz => $label): ?>
                                        <button type="button"
                                                class="dropdown-option w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                                data-value="<?= htmlspecialchars($tz) ?>"
                                                data-target="timezone">
                                          <span class="text-sm text-gray-900 dark:text-white font-medium">
                                            <?= htmlspecialchars($label) ?>
                                          </span>
                                        </button>
                                      <?php endforeach; ?>

                                      <!-- All Timezones -->
                                      <div class="tz-group px-3 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 border-t">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wide">All Timezones</span>
                                      </div>
                                      <?php
                                      $allTzs = DateTimeZone::listIdentifiers();
                                      foreach ($allTzs as $tz):
                                        if (!array_key_exists($tz, $timezone_options)):
                                      ?>
                                        <button type="button"
                                                class="dropdown-option w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                                data-value="<?= htmlspecialchars($tz) ?>"
                                                data-target="timezone">
                                          <span class="text-sm text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($tz) ?>
                                          </span>
                                        </button>
                                      <?php
                                        endif;
                                      endforeach;
                                      ?>
                                    </div>
                                  </div>
                                </div>
                            </div>

                            <hr class="border-slate-200 dark:border-gray-700">

                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-700 dark:text-gray-300">New lesson booking</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="notify_new_booking" class="sr-only peer" <?php echo $u_settings['notify_new_booking'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-slate-300 dark:bg-gray-600 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-700 dark:text-gray-300">Lesson cancellation</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="notify_cancellation" class="sr-only peer" <?php echo $u_settings['notify_cancellation'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-slate-300 dark:bg-gray-600 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-700 dark:text-gray-300">Upcoming lesson reminder (24 hours prior)</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="notify_reminder_24h" class="sr-only peer" <?php echo $u_settings['notify_reminder_24h'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-slate-300 dark:bg-gray-600 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                            </div>

                            <div class="flex justify-end pt-2">
                                <button type="submit" class="bg-indigo-600 text-white font-semibold py-2.5 px-5 rounded-lg hover:bg-indigo-500 transition-colors">
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Password -->
                    <section class="lg:col-span-3 glass rounded-xl p-6">
                        <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Change Password</h2>
                        <p class="text-slate-600 dark:text-slate-300 mb-5">For your security, we recommend using a strong password.</p>

                        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4" autocomplete="off">
                            <input type="hidden" name="form_name" value="password">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <div>
                                <label for="currentPassword" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Current Password</label>
                                <input type="password" id="currentPassword" name="current_password" class="input-field" required>
                            </div>
                            <div>
                                <label for="newPassword" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">New Password</label>
                                <input type="password" id="newPassword" name="new_password" class="input-field" minlength="8" required>
                            </div>
                            <div>
                                <label for="confirmPassword" class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">Confirm New Password</label>
                                <input type="password" id="confirmPassword" name="confirm_password" class="input-field" minlength="8" required>
                            </div>
                            <div class="md:col-span-3 pt-2">
                                <button type="submit" class="bg-indigo-600 text-white font-semibold py-2.5 px-5 rounded-lg hover:bg-indigo-500 transition-colors">
                                    Update Password
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            <!-- Earnings Tab -->
            <div id="earnings-tab" class="tab-pane hidden">
                <div class="rounded-xl p-8">
                   <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-8">
                      <!-- Earnings Header -->
                      <div class="flex items-center">
                          <div>
                              <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Earnings</h2>
                              <p class="text-slate-600 dark:text-slate-300">Track your income and transaction history</p>
                          </div>
                      </div>

                          <!-- Month Selector and Rate Form Container -->
                          <div class="w-full md:w-auto">
                            <section class="flex flex-col md:flex-row items-stretch gap-3">
                              <!-- Month Selector -->
                              <form method="get" class="w-full md:w-auto">
                                  <div class="relative">
                                      <input id="view_month" name="view_month" type="month"
                                          value="<?= h($viewStart->format('Y-m')) ?>"
                                          class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-xl text-sm font-medium px-4 py-3 pr-10 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                                          onchange="this.form.submit()">
                                      <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-600 dark:text-slate-300">
                                          <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                                      </div>
                                  </div>
                              </form>

                              <!-- Hourly Rate Form -->
                              <form method="post" class="flex-1 flex flex-col md:flex-row items-stretch gap-3">
                                  <input type="hidden" name="form_name" value="earnings">
                                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                  
                                  <!-- Hourly Rate Input -->
                                  <div class="relative flex-1">
                                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-600 dark:text-slate-300">
                                          <span class="text-sm font-medium"><?= h($currency_symbol) ?></span>
                                      </div>
                                      <input id="hourly_rate" name="hourly_rate" type="number" min="0" step="0.01" required
                                          value="<?= h(number_format($hourly_rate, 2, '.', '')) ?>"
                                          class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-xl text-sm font-medium px-10 py-3 text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                  </div>

                                  <!-- Currency Select -->
                                  <div class="relative flex-1">
                                      <select id="currency" name="currency" 
                                          class="w-full card-base-sub border border-slate-200 dark:border-white/10 rounded-xl text-sm font-medium px-4 py-3 pr-10 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer outline-none appearance-none">
                                          <?php foreach ($currency_symbols as $code => $sym): ?>
                                              <option value="<?= h($code) ?>" <?= $currency === $code ? 'selected' : '' ?>>
                                                  <?= h($code) ?> - <?= h($sym) ?>
                                              </option>
                                          <?php endforeach; ?>
                                      </select>
                                      <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-600 dark:text-slate-300">
                                          <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                                      </div>
                                  </div>

                                  <!-- Submit Button -->
                                  <button type="submit" 
                                      class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 px-6 rounded-xl transition-all shadow-md shadow-indigo-600/10 active:scale-95 whitespace-nowrap">
                                      Save
                                  </button>
                              </form>
                            </section>
                          </div>
                  </div>
                </div>

                    <?php if ($flash): ?>
                        <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-500/10 text-green-300' : 'bg-red-500/10 text-red-300' ?>">
                            <?= h($flash['msg']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                        <!-- Left Column: Stats & Transactions -->
                        <div class="xl:col-span-2 space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="card-base p-6 rounded-lg">
                                    <p class="text-sm text-slate-600 dark:text-gray-400">Available for Payout</p>
                                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
                                        <?= h($currency_symbol . number_format($available_for_payout, 2)) ?>
                                    </p>
                                <p class="text-xs text-slate-600 dark:text-gray-400 mt-1">For <?= h($viewMonthLabel) ?></p>
                                </div>
                                <div class="card-base p-6 rounded-lg">
                                    <p class="text-sm text-slate-600 dark:text-gray-400">Next Payout Date</p>
                                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?= h($nextPayoutDate) ?></p>
                                    <p class="text-xs text-slate-600 dark:text-gray-400 mt-1">1st business day of the month</p>
                                </div>
                                <div class="card-base p-6 rounded-lg">
                                    <p class="text-sm text-slate-600 dark:text-gray-400">Total Earned</p>
                                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
                                        <?= h($currency_symbol . number_format($total_earned, 2)) ?>
                                    </p>
                                    <p class="text-xs text-slate-600 dark:text-gray-400 mt-1">All time</p>
                                </div>
                            </div>

                            <!-- Transactions -->
                            <div class="card-base p-6 rounded-lg">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Transaction History</h3>
                                    <span class="text-xs text-slate-600 dark:text-gray-400">For <?= h($viewMonthLabel) ?></span>
                                </div>
                                <div class="space-y-3">
                                    <div class="grid grid-cols-4 gap-4 text-sm font-semibold text-slate-600 dark:text-gray-400 px-4 pb-2 border-b border-slate-200 dark:border-gray-700">
                                        <div>Lesson / Student</div><div class="text-center">Date</div><div class="text-right">Amount</div><div class="text-right">Status</div>
                                    </div>
                                    <?php if (empty($transactions)): ?>
                                        <p class="text-slate-600 dark:text-gray-400 p-4 text-center">No transactions for this period.</p>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $t):
                                            [$pillText, $pillClass] = statusPill($t['status']);
                                            $studentLabel = is_null($t['first_name']) ? '— Demo —' : trim($t['first_name'] . ' ' . $t['last_name']);
                                            $initials = is_null($t['first_name']) ? 'DM' : strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1));
                                        ?>
                                        <div class="grid grid-cols-4 gap-4 items-center bg-indigo-500/10 p-4 rounded-lg hover:bg-indigo-500/20 transition-colors">
                                            <div class="flex items-center gap-3">
                                                <?= photo_img($t['profile_photo_url'] ?? null, $studentLabel) ?>
                                                <div>
                                                    <p class="font-semibold text-slate-900 dark:text-white"><?= h($t['subject_name']) ?></p>
                                                    <p class="text-sm text-slate-600 dark:text-gray-400"><?= h($studentLabel) ?></p>
                                                </div>
                                            </div>
                                            <p class="text-sm text-slate-700 dark:text-gray-300 text-center"><?= h((new DateTime($t['scheduled_start_time']))->format('M j, Y')) ?></p>
                                            <p class="font-semibold text-slate-900 dark:text-white text-right"><?= h($currency_symbol . number_format((float)$t['amount'], 2)) ?></p>
                                            <div class="flex justify-end">
                                                <span class="text-xs font-medium py-1 px-3 rounded-full <?= $pillClass ?>"><?= h($pillText) ?></span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Chart, Stats & Downloads -->
                        <div class="xl:col-span-1 space-y-4">
                            <div class="card-base p-6 rounded-lg">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Monthly Earnings (ending <?= h($viewMonthLabel) ?>)</h3>
                                <div class="h-80"><canvas id="monthlyEarningsChart"></canvas></div>
                            </div>
                            <div class="card-base p-6 rounded-lg grid grid-cols-2 gap-4">
                                <h3 class="col-span-2 text-lg font-semibold text-slate-900 dark:text-white">This Month's Summary</h3>
                                <div class="bg-indigo-500/10 p-3 rounded-lg"><p class="text-xs text-slate-600 dark:text-gray-400">Paid Hours</p><p class="text-xl font-bold text-slate-900 dark:text-white"><?= h(number_format((float)$mini_stats['paid_hours'], 2)) ?>h</p></div>
                                <div class="bg-indigo-500/10 p-3 rounded-lg"><p class="text-xs text-slate-600 dark:text-gray-400">Paid Amount</p><p class="text-xl font-bold text-slate-900 dark:text-white"><?= h($currency_symbol . number_format((float)$mini_stats['paid_amount'], 2)) ?></p></div>
                                <div class="bg-indigo-500/10 p-3 rounded-lg"><p class="text-xs text-slate-600 dark:text-gray-400">Regular Classes</p><p class="text-xl font-bold text-slate-900 dark:text-white"><?= h((int)$mini_stats['regulars']) ?></p></div>
                                <div class="bg-indigo-500/10 p-3 rounded-lg"><p class="text-xs text-slate-600 dark:text-gray-400">Demos / No-Shows</p><p class="text-xl font-bold text-slate-900 dark:text-white"><?= h((int)$mini_stats['demos']) ?> / <?= h((int)$mini_stats['noshows']) ?></p></div>
                            </div>
                            <div class="card-base p-6 rounded-lg space-y-4">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Download Reports</h3>
                                <!-- Month Report Form -->
                                <form method="get" class="flex items-end gap-3">
                                    <input type="hidden" name="download" value="1"><input type="hidden" name="mode" value="month">
                                    <div class="flex-grow"><label class="block text-xs text-slate-600 dark:text-gray-400 mb-1" for="month">Month</label><input id="month" name="month" type="month" required value="<?= h($viewStart->format('Y-m')) ?>" class="w-full bg-indigo-500/10 text-slate-900 dark:text-white rounded-lg py-2 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                                    <button type="submit" class="bg-slate-100 dark:bg-gray-700 hover:bg-slate-200 dark:hover:bg-gray-600 text-slate-800 dark:text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center gap-2"><svg width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-download"></use></svg><span>Month</span></button>
                                </form>
                                <!-- Custom Range Report Form -->
                                <form method="get" class="flex items-end gap-3">
                                    <input type="hidden" name="download" value="1"><input type="hidden" name="mode" value="range">
                                    <div class="flex-grow"><label class="block text-xs text-slate-600 dark:text-gray-400 mb-1" for="start_date">Start</label><input id="start_date" name="start_date" type="date" required value="<?= h((new DateTimeImmutable('first day of this month'))->format('Y-m-d')) ?>" class="w-full bg-indigo-500/10 text-slate-900 dark:text-white rounded-lg py-2 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                                    <div class="flex-grow"><label class="block text-xs text-slate-600 dark:text-gray-400 mb-1" for="end_date">End</label><input id="end_date" name="end_date" type="date" required value="<?= h((new DateTimeImmutable())->format('Y-m-d')) ?>" class="w-full bg-indigo-500/10 text-slate-900 dark:text-white rounded-lg py-2 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                                    <button type="submit" class="bg-slate-100 dark:bg-gray-700 hover:bg-slate-200 dark:hover:bg-gray-600 text-slate-800 dark:text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center gap-2"><svg width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-download"></use></svg><span>Range</span></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Learning Path Tab -->
            <div id="learning-path-tab" class="tab-pane hidden">
                <div class="rounded-xl p-8">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-6">
    <!-- Learning Path Header -->
    <div class="flex items-center gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Learning Path</h2>
            <p class="text-slate-600 dark:text-slate-300">Track student progress and curriculum coverage</p>
        </div>
    </div>
    <!-- Student and Subject Selector -->
    <form method="get" class="flex-1 max-w-2xl">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="relative">
                    <select name="student_id" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-xl text-sm font-medium px-4 py-3 pr-10 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer appearance-none">
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $sel_student===(int)$s['id']?'selected':''; ?>>
                                <?= h($s['first_name'].' '.$s['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-600 dark:text-slate-300">
                        <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                    </div>
                </div>
                <div class="relative">
                    <select name="subject_id" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-xl text-sm font-medium px-4 py-3 pr-10 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer appearance-none">
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $sel_subject===(int)$s['id']?'selected':''; ?>>
                                <?= h($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-600 dark:text-slate-300">
                        <svg class="w-4 h-4" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                    </div>
                </div>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 px-6 rounded-xl transition-all shadow-md shadow-indigo-600/10 active:scale-95 whitespace-nowrap">
                Apply
            </button>
        </div>
    </form>
</div>
<?php if ($has_selection): ?>
    <!-- Coverage and Stats -->
    <div class="grid grid-cols-1 xl:grid-cols-4 gap-4 mb-4">
        <!-- Coverage Card -->
        <div class="card-base p-6 xl:col-span-2">
            <div class="flex flex-col md:flex-row items-center gap-6">
                
                <div class="flex-1 w-full">
                    <div class="text-lg font-semibold text-slate-900 dark:text-white">
                        <?= h($subject['name']) ?>
                        <?php if ($subject['curriculum']): ?><span class="chip ml-2 font-normal"><?= h($subject['curriculum']) ?></span><?php endif; ?>
                        <?php if ($subject['year_level']): ?><span class="chip ml-1 font-normal">Year <?= (int)$subject['year_level'] ?></span><?php endif; ?>
                    </div>
                    <p class="text-slate-500 dark:text-slate-300 mt-1"><?= (int)$covered_topics ?>/<?= (int)$total_topics ?> topics covered</p>
                    
                    <div class="flex flex-col md:flex-row items-center gap-6 w-full">
                      <!-- Stats Grid -->
                      <div class="grid grid-cols-2 gap-3 flex-1 w-full">
                          <div class="card-base p-4">
                              <div class="text-xs font-medium text-slate-500 dark:text-slate-300 mb-1">Lessons / week</div>
                              <div class="text-xl font-bold text-slate-900 dark:text-white"><?= number_format($lessons_per_week, 2) ?></div>
                          </div>
                          <div class="card-base p-4">
                              <div class="text-xs font-medium text-slate-500 dark:text-slate-300 mb-1">Topics / lesson</div>
                              <div class="text-xl font-bold text-slate-900 dark:text-white"><?= number_format($topics_per_lesson, 2) ?></div>
                          </div>
                          <div class="card-base p-4">
                              <div class="text-xs font-medium text-slate-500 dark:text-slate-300 mb-1">Topics / week</div>
                              <div class="text-xl font-bold text-slate-900 dark:text-white"><?= number_format($topics_per_week, 2) ?></div>
                          </div>
                          <div class="card-base p-4">
                              <div class="text-xs font-medium text-slate-500 dark:text-slate-300 mb-1">Completion ETA</div>
                              <div class="text-xl font-bold text-slate-900 dark:text-white">
                                  <?= $weeks_remaining ? h((new DateTime())->modify("+{$weeks_remaining} weeks")->format('M j, Y')) : '—' ?>
                              </div>
                          </div>
                      </div>

                      <!-- Radial Progress -->
                      <div class="relative w-32 h-32 flex-shrink-0">
                          <div class="w-full h-full rounded-full flex items-center justify-center bg-slate-50 dark:bg-slate-800/50 p-1">
                              <div class="w-full h-full rounded-full flex flex-col items-center justify-center bg-white dark:bg-slate-900 shadow-inner">
                                  <span class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= number_format($coverage_pct, 1) ?>%</span>
                                  <span class="text-xs font-medium text-slate-500 dark:text-slate-300">Coverage</span>
                              </div>
                          </div>
                          <svg class="absolute top-0 left-0 w-full h-full" viewBox="0 0 36 36">
                              <path
                                  d="M18 2.0845
                                      a 15.9155 15.9155 0 0 1 0 31.831
                                      a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none"
                                  stroke="url(#gradient)"
                                  stroke-width="3"
                                  stroke-dasharray="<?= $coverage_pct ?>, 100"
                                  stroke-linecap="round"
                              />
                              <defs>
                                  <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                      <stop offset="0%" stop-color="#6366f1" />
                                      <stop offset="100%" stop-color="#8b5cf6" />
                                  </linearGradient>
                              </defs>
                          </svg>
                      </div>
                  </div>
                </div>
            </div>
        </div>
        <!-- Next Topic Card -->
        <div class="card-base p-6">
            <div class="flex items-center gap-3 mb-4">
                <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" data-lucide="arrow-right" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-arrow-right"></use></svg>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Next Topic</h3>
            </div>
            <?php if ($next_topic): ?>
                <div class="text-lg font-bold text-slate-900 dark:text-white"><?= h($next_topic['name']) ?></div>
                <?php if ($next_subtopics): ?>
                    <div class="mt-3">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">Suggested subtopics:</p>
                        <ul class="space-y-2">
                            <?php foreach ($next_subtopics as $st): ?>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 mt-1 text-green-500 flex-shrink-0" data-lucide="check-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
                                    <span class="text-sm text-slate-700 dark:text-slate-300"><?= h($st['name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-300">No subtopics listed.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-slate-500 dark:text-slate-300">All topics completed or not defined.</p>
            <?php endif; ?>
        </div>
        <!-- Upcoming Card -->
        <div class="card-base p-6">
            <div class="flex items-center gap-3 mb-4">
                <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Upcoming</h3>
            </div>
            <?php if ($upcoming): ?>
                <div class="space-y-4">
                    <div>
                        <div class="text-lg font-bold text-slate-900 dark:text-white"><?= dtfmt($upcoming['scheduled_start_time']) ?></div>
                        <span class="inline-block px-2 py-1 text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-100 rounded-full mt-1">
                            Scheduled
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0">
                            <img src="<?= h(resolve_photo($upcoming['student_photo_url'] ?? '', $upcoming['student_name'])) ?>"
                                 alt="<?= h($upcoming['student_name']) ?>"
                                 class="w-10 h-10 rounded-full object-cover"
                                 onerror="this.onerror=null; this.src='../assets/avatar.php?s=40&bg=1f2937&fg=ffffff&text='+encodeURIComponent('<?= h(initials($upcoming['student_name'])) ?>')">
                        </div>
                        <div>
                            <div class="font-medium text-slate-900 dark:text-white"><?= h($upcoming['student_name']) ?></div>
                            <div class="text-sm text-slate-500 dark:text-slate-300"><?= h($upcoming['subject_name']) ?></div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-slate-500 dark:text-slate-300">No upcoming sessions scheduled.</p>
            <?php endif; ?>
        </div>
    </div>

<!-- Middle: Heatmap + Suggestions -->
<section class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-4">
    <!-- Heatmap Card -->
    <div class="card-base p-6 xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" data-lucide="activity" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-activity"></use></svg>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Activity Heatmap</h3>
            </div>
            <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-300">
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-slate-100 dark:bg-slate-700/50"></span> Less</span>
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-indigo-400"></span> More</span>
            </div>
        </div>
        
        <?php
        // Build 13 columns (weeks), each with 7 days
        $cols = 13; 
        $rows = 7;
        // Start from today's start-of-day, go back to the Sunday 12 weeks ago
        $today = new DateTime('today');
        $start = clone $today;
        $start->modify('-'.(7*($cols-1)).' days');
        // Align start to last Sunday to make columns aligned; GitHub starts on Sunday
        $dow = (int)$start->format('w'); // 0=Sun ... 6=Sat
        $start->modify('-'.$dow.' days');
        
        // Function to determine heat level
        function getHeatLevel($n) {
            if ($n <= 0) return 'bg-slate-100 dark:bg-slate-700/50';
            if ($n <= 2) return 'bg-indigo-100 dark:bg-indigo-900/50';
            if ($n <= 4) return 'bg-indigo-300 dark:bg-indigo-700';
            return 'bg-indigo-500 dark:bg-indigo-500';
        }
        ?>
        
        <div class="overflow-x-auto pb-2 -mx-1">
            <div class="flex gap-1 min-w-max px-1">
                <?php
                $iter = clone $start;
                for ($c = 0; $c < $cols; $c++):
                    $weekStart = clone $iter;
                    $weekEnd = (clone $iter)->modify('+6 days');
                    $weekLabel = $weekStart->format('M j') . ' - ' . $weekEnd->format('M j');
                ?>
                    <div class="flex flex-col gap-1">
                        <div class="text-[10px] text-center text-slate-500 dark:text-slate-300 h-4 mb-1">
                            <?= $c % 2 === 0 ? $weekLabel : '' ?>
                        </div>
                        <?php 
                        for ($r = 0; $r < $rows; $r++):
                            $dstr = $iter->format('Y-m-d');
                            $cnt = $counts_by_day[$dstr] ?? 0;
                            $heatLevel = getHeatLevel($cnt);
                            $isToday = $dstr === $today->format('Y-m-d');
                            $title = $iter->format('M j, Y') . ' • ' . $cnt . ' completed';
                        ?>
                            <div 
                                class="w-3 h-3 rounded-sm <?= $heatLevel ?> <?= $isToday ? 'ring-2 ring-offset-1 ring-indigo-400' : '' ?>" 
                                title="<?= h($title) ?>"
                                data-tooltip="<?= h($title) ?>"
                            ></div>
                        <?php 
                            $iter->modify('+1 day'); 
                        endfor; 
                        ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        
        <div class="mt-4 flex items-center justify-between text-xs text-slate-500 dark:text-slate-300">
            <span>12 weeks ago</span>
            <span>This week</span>
        </div>
    </div>

    <!-- Suggested Materials Card -->
    <div class="card-base p-6">
        <div class="flex items-center gap-3 mb-4">
            <svg class="w-5 h-5 text-amber-400" data-lucide="lightbulb" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-lightbulb"></use></svg>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Suggested Materials</h3>
        </div>
        
        <?php if ($suggest_materials): ?>
            <div class="space-y-4">
                <?php foreach ($suggest_materials as $mat): 
                    $type = $mat['material_type'] ?: 'Material';
                    $badgeCls = [
                        'Book' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
                        'Worksheet' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                        'PastPaper' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
                        'PPT' => 'bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-300',
                        'Video' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300',
                        'Audio' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
                    ][$type] ?? 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-300';
                    
                    $isFile = !empty($mat['file_url']);
                    $url = $isFile ? ($materials_base . $mat['file_url']) : ($mat['external_url'] ?? '');
                ?>
                    <div class="group relative overflow-hidden rounded-xl p-4 transition-all hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-slate-900 dark:text-white truncate"><?= h($mat['title']) ?></h4>
                                
                                <?php if (!empty($mat['description'])): ?>
                                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300 line-clamp-2">
                                        <?= h($mat['description']) ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="px-2 py-1 rounded-full font-medium <?= $badgeCls ?>">
                                        <?= h($type) ?>
                                    </span>
                                    
                                    <?php if ($mat['curriculum']): ?>
                                        <span class="text-slate-500 dark:text-slate-300">
                                            <?= h($mat['curriculum']) ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($mat['grade_level'] !== null): ?>
                                        <span class="text-slate-500 dark:text-slate-300">
                                            Grade <?= (int)$mat['grade_level'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($url): ?>
                                <a href="<?= h($url) ?>" 
                                   <?= $isFile ? 'download' : 'target="_blank"' ?>
                                   class="flex-shrink-0 p-2 text-slate-600 dark:text-slate-300 hover:text-indigo-500 transition-colors">
                                    <svg class="w-4 h-4" data-lucide="<?= $isFile ? 'download' : 'external-link' ?>" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-<?= $isFile ? 'download' : 'external-link' ?>"></use></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <div class="mx-auto w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-600 dark:text-slate-300 mb-3">
                    <svg class="w-5 h-5" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
                </div>
                <p class="text-slate-500 dark:text-slate-300 text-sm">
                    No matching materials found. Visit the 
                    <a href="materials.php" class="text-indigo-600 dark:text-indigo-300 font-medium hover:underline">
                        materials library
                    </a> 
                    to explore resources.
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>

                        <!-- Bottom: Topic tree + Recent lessons -->
                        <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                          <!-- Topic tree -->
                          <div class="card-base p-6 xl:col-span-2">
                            <div class="flex flex-wrap items-center justify-between mb-4 gap-2">
                              <h3 class="text-lg font-bold text-slate-900 dark:text-white">Topics & Subtopics</h3>
                              <div class="text-sm font-medium text-slate-500 dark:text-slate-300"><?= (int)$covered_topics ?> / <?= (int)$total_topics ?> done</div>
                            </div>

                            <?php if (!$topics): ?>
                              <p class="text-slate-600 dark:text-slate-300">No topics defined for this subject.</p>
                            <?php else: ?>
                              <div class="space-y-2">
                                <?php foreach ($topics as $t): 
                                  $is_done = isset($covered_set[(int)$t['id']]);
                                  $subs = $sub_by_topic[(int)$t['id']] ?? [];
                                ?>
                                  <details class="card p-0 overflow-hidden group" <?= $next_topic && $next_topic['id']==$t['id'] ? 'open' : '' ?>>
                                    <summary class="summary-row px-4 py-3 flex items-center justify-between hover:bg-slate-900/50">
                                      <div class="flex items-center gap-3">
                                        <span class="w-2.5 h-2.5 rounded-full <?= $is_done ? 'bg-emerald-400' : 'bg-slate-400 dark:bg-slate-600' ?>"></span>
                                        <span class="font-semibold <?= $is_done ? 'text-slate-900 dark:text-white' : 'text-slate-700 dark:text-slate-200' ?>"><?= h($t['name']) ?></span>
                                        <?php if ($next_topic && $next_topic['id']==$t['id']): ?>
                                          <span class="chip border-emerald-500/40">Next</span>
                                        <?php endif; ?>
                                      </div>
                                      <!-- chevron -->
                                      <svg class="w-4 h-4 text-slate-600 dark:text-slate-300 transition-transform group-open:rotate-180" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                                    </summary>
                                    <?php if ($subs): ?>
                                      <div class="px-4 pb-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <ul class="grid md:grid-cols-2 gap-2">
                                          <?php foreach ($subs as $s): ?>
                                            <li class="text-sm text-slate-600 dark:text-slate-300 flex items-center gap-2">
                                              <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-600 dark:text-slate-300 dark:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                <circle cx="12" cy="12" r="2"></circle>
                                              </svg>
                                              <?= h($s['name']) ?>
                                            </li>
                                          <?php endforeach; ?>
                                        </ul>
                                      </div>
                                    <?php else: ?>
                                      <div class="px-4 pb-3 pt-2 border-t border-slate-100 dark:border-slate-800 text-sm text-slate-500 dark:text-slate-300">No subtopics listed.</div>
                                    <?php endif; ?>
                                  </details>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                          </div>

                          <!-- Recent lessons -->
                          <div class="card-base p-6">
                            <h3 class="text-lg font-semibold mb-4">Recent Lessons</h3>
                            <?php if (!$recent_lessons): ?>
                              <p class="text-slate-500 dark:text-slate-300">No lessons yet.</p>
                            <?php else: ?>
                              <div class="space-y-3">
                                <?php foreach ($recent_lessons as $L): ?>
                                  <div class="card p-3 rounded-lg flex items-center justify-between shadow-sm">
                                    <div>
                                      <div class="font-semibold text-slate-900 dark:text-white"><?= dtfmt($L['scheduled_start_time']) ?></div>
                                      <div class="text-xs text-slate-500 dark:text-slate-300"><?= h($L['status']) ?></div>
                                    </div>
                                    <a href="schedule.php" class="text-xs bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 px-3 py-1 rounded-lg text-slate-700 dark:text-slate-300 transition-colors">Open Schedule</a>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </section>
                        <?php else: ?>
                          <div class="card-base p-8 text-center">
                            <h2 class="text-xl font-bold mb-2">No Student or Subject Selected</h2>
                            <p class="text-slate-600 dark:text-slate-300">Please use the filter above to select a student and subject to view their learning path progress.</p>
                          </div>
                        <?php endif; ?>

                      </section>
                </div>
            </div>

            <!-- Notifications Tab -->
            <div id="notifications-tab" class="tab-pane hidden">
                <div class="rounded-xl p-6">
                    <div class="flex items-center gap-2 mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Notifications</h2>
                            <p class="text-slate-600 dark:text-slate-300">View your recent alerts and updates</p>
                        </div>
                    </div>
                    <?php
                      $np_pdo = $pdo; $np_user_id = $tutor_id; $np_phone = $user['phone_number'] ?? null;
                      $np_action = 'settings.php#notifications';
                      $np_hidden = ['form_name' => 'notif_prefs', 'csrf_token' => $csrf];
                      include __DIR__ . '/../components/notification_prefs_card.php';
                    ?>
                    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-lg p-2">
                      <?php if (empty($notifications)): ?>
                        <div class="glass p-10 text-center text-slate-600 dark:text-slate-300">
                          <p>No notifications yet.</p>
                        </div>
                      <?php else: ?>
                          <div class="space-y-2">
                              <?php foreach ($notifications as $n):
                                  $unread = (int)$n['is_read'] === 0;
                                  $actionUrl = notification_action_url($n);
                              ?>
                                  <div class="notif-card <?= $unread ? 'unread' : '' ?> rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                      <div class="flex items-start gap-2">
                                          <div class="mt-1">
                                              <?php if ($unread): ?>
                                                  <span class="badge-dot inline-block"></span>
                                              <?php else: ?>
                                                  <span class="w-2.5 h-2.5 rounded-full bg-slate-600 inline-block"></span>
                                              <?php endif; ?>
                                          </div>
                                          <div>
                                              <p class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-300"><?= h(notification_label($n['type'] ?? '')) ?></p>
                                              <p class="text-sm text-slate-900 dark:text-white mt-1"><?= h($n['message']) ?></p>
                                              <p class="text-xs text-slate-600 dark:text-slate-300 mt-1"><?= dtfmt($n['created_at']) ?></p>
                                          </div>
                                      </div>
                                      <div class="flex items-center gap-2">
                                          <button onclick="showNotificationPopup(<?= htmlspecialchars(json_encode($n), ENT_QUOTES, 'UTF-8') ?>)" 
                                                  class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 hover:text-indigo-900 dark:hover:text-indigo-200 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/30 hover:bg-indigo-500/20 transition-colors">
                                              Open
                                          </button>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
            integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab switching logic (EXACT match from student settings)
        const tabs = document.querySelectorAll('.tab-button');
        const panes = document.querySelectorAll('.tab-pane');

        function switchTab(tabName) {
            panes.forEach(pane => {
                pane.classList.add('hidden');
            });

            const targetPane = document.getElementById(tabName + '-tab');
            if (targetPane) {
                targetPane.classList.remove('hidden');
            }

            // These class names are repainted here on every switch, so they need
            // the light/dark pair too: the markup conversion could not reach them,
            // and the active tab came back as indigo-400 on white at 2.79:1.
            const ACTIVE   = ['border-indigo-500', 'text-indigo-600', 'dark:text-indigo-400'];
            const INACTIVE = ['border-transparent', 'text-slate-600', 'dark:text-gray-400'];

            tabs.forEach(t => {
                t.classList.remove(...ACTIVE);
                t.classList.add(...INACTIVE);
                t.setAttribute('aria-selected', 'false');
            });

            const tab = document.querySelector(`.tab-button[data-tab="${tabName}"]`);
            if (tab) {
                tab.classList.remove(...INACTIVE);
                tab.classList.add(...ACTIVE);
                tab.setAttribute('aria-selected', 'true');
            }

            history.replaceState(null, '', '#' + tabName);
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', function () {
                switchTab(this.dataset.tab);
            });
        });

        // A form post reloads the page, and the fragment never reaches the server,
        // so saving anything used to drop you back on Profile. Remember the tab.
        const REMEMBERED = 'tutor-settings-tab';
        let startTab = location.hash.replace('#', '');
        if (!startTab) {
            try { startTab = sessionStorage.getItem(REMEMBERED) || ''; } catch (e) {}
        }
        if (!document.querySelector(`.tab-button[data-tab="${startTab}"]`)) {
            startTab = tabs[0].dataset.tab;
        }
        switchTab(startTab);
        tabs.forEach(t => t.addEventListener('click', () => {
            try { sessionStorage.setItem(REMEMBERED, t.dataset.tab); } catch (e) {}
        }));
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                const pane = form.closest('.tab-pane');
                if (!pane) return;
                try { sessionStorage.setItem(REMEMBERED, pane.id.replace(/-tab$/, '')); } catch (e) {}
            });
        });

        // Filter the timezone list as you type, and offer the browser's own guess.
        (function () {
            const search = document.getElementById('tz-search');
            if (!search) return;
            const options = [...document.querySelectorAll('.dropdown-option[data-target="timezone"]')];
            const groups  = [...document.querySelectorAll('.tz-group')];
            const empty   = document.getElementById('tz-empty');

            search.addEventListener('input', () => {
                const q = search.value.trim().toLowerCase();
                let shown = 0;
                options.forEach(o => {
                    const hit = !q || o.textContent.toLowerCase().includes(q)
                                   || (o.dataset.value || '').toLowerCase().includes(q);
                    o.classList.toggle('hidden', !hit);
                    if (hit) shown++;
                });
                // The group headings only make sense when nothing is filtered out.
                groups.forEach(g => g.classList.toggle('hidden', !!q));
                empty.classList.toggle('hidden', shown !== 0);
            });
            search.addEventListener('click', e => e.stopPropagation());
            search.addEventListener('keydown', e => e.stopPropagation());

            const detect = document.getElementById('tz-detect');
            detect.addEventListener('click', e => {
                e.stopPropagation();
                let guess = '';
                try { guess = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (err) {}
                const match = options.find(o => o.dataset.value === guess);
                if (match) {
                    match.click();
                } else {
                    detect.textContent = guess
                        ? 'This device says ' + guess + ', which is not in the list'
                        : 'This device did not report a timezone';
                }
            });
        })();

        // Custom dropdown functionality
        function initializeDropdowns() {
            const dropdowns = document.querySelectorAll('.dropdown-container');

            dropdowns.forEach(container => {
                const trigger = container.querySelector('.dropdown-trigger');
                const panel = container.querySelector('.dropdown-panel');
                const hiddenInput = container.querySelector('input[type="hidden"]');

                // Toggle dropdown on trigger click
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-panel').forEach(p => {
                        if (p !== panel) {
                            p.classList.add('hidden');
                            p.closest('.dropdown-container').querySelector('.dropdown-trigger').classList.remove('open');
                        }
                    });

                    // Toggle current dropdown
                    panel.classList.toggle('hidden');
                    trigger.classList.toggle('open');
                });

                // Handle option selection
                panel.addEventListener('click', function(e) {
                    const option = e.target.closest('.dropdown-option');
                    if (option) {
                        e.preventDefault();
                        e.stopPropagation();

                        const value = option.dataset.value;
                        const target = option.dataset.target;
                        const displayText = option.querySelector('span').textContent.trim();

                        // Update hidden input
                        if (hiddenInput && hiddenInput.name === target) {
                            hiddenInput.value = value;
                        }

                        // Update trigger display
                        const triggerSpan = trigger.querySelector('span');
                        if (triggerSpan) {
                            triggerSpan.textContent = displayText;
                        }

                        // Close dropdown
                        panel.classList.add('hidden');
                        trigger.classList.remove('open');
                    }
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dropdown-container')) {
                    document.querySelectorAll('.dropdown-panel').forEach(panel => {
                        panel.classList.add('hidden');
                        panel.closest('.dropdown-container').querySelector('.dropdown-trigger').classList.remove('open');
                    });
                }
            });

            // Close dropdowns on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dropdown-panel').forEach(panel => {
                        panel.classList.add('hidden');
                        panel.closest('.dropdown-container').querySelector('.dropdown-trigger').classList.remove('open');
                    });
                }
            });
        }

        initializeDropdowns();
    });
    </script>
    <!-- Notification Popup -->
    <div id="notification-popup" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col border border-slate-200 dark:border-gray-700">
            <div class="p-4 border-b border-slate-200 dark:border-gray-700 flex justify-between items-center">
                <h3 id="notification-title" class="text-lg font-semibold text-slate-900 dark:text-white"></h3>
                <button onclick="closeNotificationPopup()" class="text-slate-600 dark:text-gray-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-5 h-5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
                </button>
            </div>
            <div class="p-4 overflow-y-auto flex-1">
                <p id="notification-message" class="text-slate-700 dark:text-gray-300 mb-4"></p>
                <div id="notification-data" class="space-y-2 text-sm text-slate-600 dark:text-gray-400">
                    <!-- Dynamic data will be inserted here -->
                </div>
            </div>
            <div class="p-4 border-t border-slate-200 dark:border-gray-700 flex justify-end gap-3">
                <a id="notification-action" href="#" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 transition-colors text-sm font-medium">
                    View Details
                </a>
                <button onclick="closeNotificationPopup()" class="px-4 py-2 bg-slate-100 dark:bg-gray-700 text-slate-700 dark:text-gray-300 rounded-lg hover:bg-slate-200 dark:hover:bg-gray-600 transition-colors text-sm font-medium">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
    // Show notification popup with details
    function showNotificationPopup(notification) {
        const popup = document.getElementById('notification-popup');
        const title = document.getElementById('notification-title');
        const message = document.getElementById('notification-message');
        const dataContainer = document.getElementById('notification-data');
        const actionBtn = document.getElementById('notification-action');
        
        // Set title and message
        title.textContent = notification.type ? notification.type.split('_').map(word => 
            word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
        ).join(' ') : 'Notification';
        
        message.textContent = notification.message || 'No message content';
        
        // Clear previous data
        dataContainer.innerHTML = '';
        
        // Add timestamp
        const timeElement = document.createElement('div');
        timeElement.className = 'text-xs text-gray-500 mb-3';
        timeElement.textContent = new Date(notification.created_at).toLocaleString();
        dataContainer.appendChild(timeElement);
        
        // Parse and display additional data if available
        try {
            const data = notification.data ? JSON.parse(notification.data) : {};
            for (const [key, value] of Object.entries(data)) {
                if (value && typeof value === 'string') {
                    // textContent, not innerHTML: both the key and the value come
                    // out of notifications.data, which is JSON written elsewhere in
                    // the app, and this was injecting it into the page as markup.
                    const dataItem = document.createElement('div');
                    dataItem.className = 'flex';
                    const k = document.createElement('span');
                    k.className = 'font-medium text-slate-700 dark:text-gray-300 w-24 flex-shrink-0';
                    k.textContent = key + ':';
                    const v = document.createElement('span');
                    v.className = 'flex-1';
                    v.textContent = value;
                    dataItem.append(k, v);
                    dataContainer.appendChild(dataItem);
                }
            }
        } catch (e) {
            console.error('Error parsing notification data:', e);
        }
        
        // Set up action button
        // There is no tutor/lesson.php; that link was a 404. A lesson notification
        // is worth opening on the schedule, which is where the lesson actually lives.
        if (notification.related_entity_id && notification.related_entity_type === 'lesson') {
            actionBtn.href = `schedule.php#lesson-${encodeURIComponent(notification.related_entity_id)}`;
            actionBtn.textContent = 'Open in schedule';
            actionBtn.style.display = 'inline-flex';
        } else {
            actionBtn.style.display = 'none';
        }
        
        // Show the popup
        popup.classList.remove('hidden');
        popup.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    
    // Close notification popup
    function closeNotificationPopup() {
        const popup = document.getElementById('notification-popup');
        popup.classList.add('hidden');
        popup.classList.remove('flex');
        document.body.style.overflow = '';
    }
    
    // Close when clicking outside the popup
    document.getElementById('notification-popup').addEventListener('click', function(e) {
        if (e.target === this) {
            closeNotificationPopup();
        }
    });
    
    // Close with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeNotificationPopup();
        }
    });
    </script>
<?php include __DIR__ . '/components/earnings_chart.php'; ?>
<script src="js/avatar-upload.js"></script>
</body>
</html>
