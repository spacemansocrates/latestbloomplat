<?php
/**
 * Student Settings Controller
 * 
 * Handles account settings, preferences, and related functionality
 */
// 1) Session + Auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}
// Initialize variables
$current_page = 'settings';
$student_id = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_first_name'] ?? 'Student';
// CSRF token for the reminder-preferences form (the same session key the tutor
// settings page uses).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$is_tab = true; // Since we're including this in a tab
// Now include the database connection and payments
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../utils/timezone_helper.php';
require_once __DIR__ . '/../utils/phone.php';
include_once __DIR__ . '/payments.php';
include_once __DIR__ . '/packages.php';
include_once __DIR__ . '/notifications.php';
include_once __DIR__ . '/learning_path.php';

// Helper functions
function h($v) { 
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
}

function money_symbol($code) {
    static $m = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'MWK' => 'MK', 'ZAR' => 'R', 'KES' => 'KSh', 'TZS' => 'TSh'];
    return $m[$code] ?? ($code . ' ');
}


// Initialize message arrays
$errors = [];
$success = [];

// Application constants
define('MAX_AVATAR_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_MIME_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
]);

// Supported currencies and timezones
$supportedCurrencies = ['USD','CAD','EUR','GBP','KES','NGN','ZAR','INR','AUD'];
$timezones = DateTimeZone::listIdentifiers();

/**
 * Fetches the current user's data
 * 
 * @param PDO $pdo Database connection
 * @param int $student_id User ID
 * @return array User data
 */
function fetchUserData(PDO $pdo, int $student_id): array {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, profile_photo_url, 
               phone_number, guardian_phone,
               COALESCE(currency_code,'USD') AS currency_code,
               COALESCE(timezone,'UTC') AS timezone,
               password_hash
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new RuntimeException('Student not found.');
    }
    
    return $user;
}

// 3) Fetch current user
try {
    $user = fetchUserData($pdo, $student_id);
} catch (RuntimeException $e) {
    die($e->getMessage());
}

/**
 * Sanitizes input string
 * 
 * @param mixed $input The input to clean
 * @return string Sanitized string
 */
function clean($input): string {
    return trim((string)$input);
}

/**
 * Returns HTML-escaped string
 * 
 * @param string $value The string to escape
 * @return string HTML-escaped string
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Returns the application's base URL prefix
 * 
 * @return string Base URL prefix (e.g., '' or '/tutsys')
 */
function app_url_base_prefix(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $prefix = rtrim(dirname(dirname($script)), '/\\\\');
    return ($prefix === '/' ? '' : $prefix);
}

/**
 * Gets the base URL for avatar uploads
 * 
 * @return string Base URL for avatars
 */
function avatar_base_url(): string {
    $prefix = app_url_base_prefix();
    $prefix = ($prefix === '/' ? '' : $prefix);
    $prefix = rtrim($prefix, '/');
    return $prefix . '/uploads/avatars/';
}

/**
 * Handles avatar upload and returns the public URL
 * 
 * @param array $file $_FILES array element
 * @param int $uid User ID
 * @param array &$errors Array to collect error messages
 * @return string|null Public URL of the uploaded avatar or null on failure
 */
function handleAvatarUpload(array $file, int $uid, array &$errors): ?string {
    // Check for upload errors
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed. Please try again.';
        return null;
    }

    // Verify it's a valid image
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info['mime'])) {
        $errors[] = 'Invalid image file.';
        return null;
    }

    // Check MIME type
    $mime = strtolower($info['mime']);
    if (!isset(ALLOWED_MIME_TYPES[$mime])) {
        $errors[] = 'Only JPG/PNG images are allowed.';
        return null;
    }

    // Check file size
    if ($file['size'] > MAX_AVATAR_SIZE) {
        $errors[] = 'Image too large (max ' . (MAX_AVATAR_SIZE / 1024 / 1024) . 'MB).';
        return null;
    }

    // Ensure upload directories exist
    $rootDir = dirname(__DIR__);
    $uploads = $rootDir . DIRECTORY_SEPARATOR . 'uploads';
    $avatarDir = $uploads . DIRECTORY_SEPARATOR . 'avatars';
    
    if (!is_dir($uploads) && !@mkdir($uploads, 0755, true)) {
        $errors[] = 'Failed to create uploads directory.';
        return null;
    }
    
    if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0755, true)) {
        $errors[] = 'Failed to create avatars directory.';
        return null;
    }

    // Generate unique filename
    $ext = ALLOWED_MIME_TYPES[$mime];
    $filename = 'student_' . $uid . '_' . time() . '.' . $ext;
    $destPath = $avatarDir . DIRECTORY_SEPARATOR . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $errors[] = 'Failed to save image.';
        return null;
    }

    // Clean up old avatars for this user (keep the 3 most recent)
    $oldAvatars = glob($avatarDir . DIRECTORY_SEPARATOR . 'student_' . $uid . '_*');
    if (count($oldAvatars) > 3) {
        // Sort by modification time (newest first)
        usort($oldAvatars, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        // Remove all but the 3 most recent
        $avatarsToDelete = array_slice($oldAvatars, 3);
        foreach ($avatarsToDelete as $oldAvatar) {
            @unlink($oldAvatar);
        }
    }

    return avatar_base_url() . $filename;
}

/**
 * Handles profile update form submission
 * 
 * @param PDO $pdo Database connection
 * @param array $user Current user data
 * @param array &$errors Array to collect error messages
 * @param array &$success Array to collect success messages
 * @return array Updated user data
 */
function handleProfileUpdate(PDO $pdo, array $user, array &$errors, array &$success): array {
    $first = clean($_POST['first_name'] ?? '');
    $last = clean($_POST['last_name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $phoneRaw    = clean($_POST['phone_number'] ?? '');
    $guardianRaw = clean($_POST['guardian_phone'] ?? '');

    // Validation
    if ($first === '' || $last === '') {
        $errors[] = 'First and last name are required.';
    }

    // Numbers are stored already normalised to E.164, so the reminder jobs never
    // have to guess a country at send time. Blank stays blank -- a number is
    // optional, but a number we cannot dial is worse than none at all.
    $phone = null;
    if ($phoneRaw !== '') {
        $r = phone_to_e164($phoneRaw);
        if (!$r['ok']) $errors[] = 'Mobile number: ' . phone_reason_text($r['reason']);
        else $phone = $r['e164'];
    }
    $guardian = null;
    if ($guardianRaw !== '') {
        $r = phone_to_e164($guardianRaw);
        if (!$r['ok']) $errors[] = 'Parent/guardian number: ' . phone_reason_text($r['reason']);
        else $guardian = $r['e164'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Check for duplicate email
    if ($email !== $user['email']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $errors[] = 'That email is already in use.';
        }
    }

    // Handle avatar upload
    $newAvatarUrl = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newAvatarUrl = handleAvatarUpload($_FILES['avatar'], $user['id'], $errors);
    }

    // If no errors, update the database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            if ($newAvatarUrl) {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = ?, 
                        last_name = ?, 
                        email = ?, 
                        phone_number = ?,
                        guardian_phone = ?,
                        profile_photo_url = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$first, $last, $email, $phone, $guardian, $newAvatarUrl, $user['id']]);
                $user['profile_photo_url'] = $newAvatarUrl;
                $_SESSION['profile_photo_url'] = $newAvatarUrl;
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = ?, 
                        last_name = ?, 
                        email = ?,
                        phone_number = ?,
                        guardian_phone = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$first, $last, $email, $phone, $guardian, $user['id']]);
            }
            
            // Update session data
            $user['first_name'] = $first;
            $user['last_name'] = $last;
            $user['email'] = $email;
            $user['phone_number'] = $phone;
            $user['guardian_phone'] = $guardian;
            
            $_SESSION['user_first_name'] = $first;
            $_SESSION['user_last_name'] = $last;
            
            $pdo->commit();
            $success[] = 'Profile updated successfully.';
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Profile update failed: ' . $e->getMessage());
            $errors[] = 'An error occurred while updating your profile. Please try again.';
        }
    }
    
    return $user;
}

/**
 * Handles preferences update form submission
 * 
 * @param PDO $pdo Database connection
 * @param array $user Current user data
 * @param array &$success Array to collect success messages
 * @return array Updated user data
 */
function handlePreferencesUpdate(PDO $pdo, array $user, array &$success): array {
    global $supportedCurrencies;
    
    $currency = strtoupper(clean($_POST['currency_code'] ?? 'USD'));
    $timezone = clean($_POST['timezone'] ?? 'UTC');
    
    // Validate inputs
    if (!in_array($currency, $supportedCurrencies, true)) {
        $currency = 'USD';
    }
    
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = 'UTC';
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update users table
        $stmt = $pdo->prepare("
            UPDATE users 
            SET currency_code = ?, 
                timezone = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$currency, $timezone, $user['id']]);
        
        // Update or insert into user_settings
        $upsert = $pdo->prepare("
            INSERT INTO user_settings (user_id, timezone, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                timezone = VALUES(timezone), 
                updated_at = NOW()
        ");
        $upsert->execute([$user['id'], $timezone]);
        
        // Update user data
        $user['currency_code'] = $currency;
        $user['timezone'] = $timezone;
        $_SESSION['timezone'] = $timezone; // Update session timezone for immediate effect
        
        $pdo->commit();
        $success[] = 'Preferences saved successfully.';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Preferences update failed: ' . $e->getMessage());
        $errors[] = 'An error occurred while saving your preferences.';
    }
    
    return $user;
}

/**
 * Handles password change form submission
 * 
 * @param PDO $pdo Database connection
 * @param array $user Current user data
 * @param array &$errors Array to collect error messages
 * @param array &$success Array to collect success messages
 * @return void
 */
function handlePasswordChange(PDO $pdo, array $user, array &$errors, array &$success): void {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($current) || empty($new) || empty($confirm)) {
        $errors[] = 'All password fields are required.';
        return;
    }
    
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
        return;
    }
    
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
        return;
    }
    
    // Verify current password
    if (!password_verify($current, $user['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
        return;
    }
    
    // Update password
    try {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);
        $success[] = 'Password changed successfully.';
    } catch (PDOException $e) {
        error_log('Password change failed: ' . $e->getMessage());
        $errors[] = 'An error occurred while changing your password.';
    }
}

// 5) Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Every form on this page carries the session token. Without it, any site a
    // signed-in student visited could post here and change their name, email,
    // phone numbers or timezone. A file over post_max_size arrives with $_POST
    // empty, so it would look like a missing token; say what actually happened.
    $csrfOk = is_string($_POST['csrf_token'] ?? null) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The upload was larger than the server allows. Choose a JPG or PNG up to 5 MB.';
        $action = '';
    } elseif (!$csrfOk) {
        $errors[] = 'That form expired. Please try again.';
        $action = '';
    }

    if ($action !== '') try {
        switch ($action) {
            case 'update_profile':
                $user = handleProfileUpdate($pdo, $user, $errors, $success);
                break;
                
            case 'update_prefs':
                $user = handlePreferencesUpdate($pdo, $user, $success);
                break;
                
            case 'change_password':
                handlePasswordChange($pdo, $user, $errors, $success);
                break;

            case 'update_notif_prefs':
                require_once __DIR__ . '/../utils/notification_prefs.php';
                np_save_user($pdo, $student_id, (array)($_POST['np'] ?? []));
                $success[] = 'Reminder choices saved.';
                break;

            default:
                $errors[] = 'Invalid action.';
                break;
        }
    } catch (Exception $e) {
        error_log('Settings error: ' . $e->getMessage());
        $errors[] = 'An unexpected error occurred. Please try again.';
    }

}

// 6) UI Data
$initials = strtoupper(substr($user['first_name'] ?? 'S',0,1) . substr($user['last_name'] ?? 'T',0,1));
$avatarUrl = $user['profile_photo_url'] ?: (avatar_base_url() . '_none_'); // fallback below
if (!$user['profile_photo_url']) {
    // pretty placeholder
    $avatarUrl = '../assets/avatar.php?s=80&bg=22d3ee&fg=0f172a&text=' . $initials;
}
$curr = $user['currency_code'];
// $C is the symbol the package/payment blocks print. It was never assigned at
// all, so every price rendered as a bare number.
require_once __DIR__ . '/../utils/money.php';
$C = money_symbol(billing_currency_code($pdo, $curr));
$tz   = $user['timezone'];

$tzShort = [
  'Africa/Blantyre',      // ✅ Malawi (Blantyre)
  'Europe/London',        // ✅ UK (London)
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Los_Angeles',
  'Europe/Berlin',
  'Africa/Nairobi',
  'Africa/Johannesburg',
  'Asia/Kolkata',
  'Australia/Sydney',
];

$currencies = ['USD'=>'USD','CAD'=>'CAD','EUR'=>'EUR','GBP'=>'GBP','KES'=>'KES','NGN'=>'NGN','ZAR'=>'ZAR','INR'=>'INR','AUD'=>'AUD'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Student – Settings</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
        integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
</head>
<body class="flex min-h-screen">
  <?php 
  // Include centralized page loader component (if not already included by sidebar)
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  ?>

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <?php
    $header_config = [
        'page_title' => 'Settings',
        'user_name' => $user['first_name'] . ' ' . $user['last_name'],
        'user_avatar' => !empty($user['profile_photo_url']) ? $user['profile_photo_url'] : '../assets/avatar.php?s=80&bg=22d3ee&fg=0f172a&text=' . urlencode($initials)
    ];
    include __DIR__ . '/components/header.php';
    ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-lg text-red-200">
        <h3 class="font-medium text-red-300 mb-1">Please fix these errors:</h3>
        <ul class="list-disc pl-5 space-y-0.5">
          <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 rounded-lg text-green-200">
        <?php foreach ($success as $message): ?>
          <p class="font-medium text-green-300"><?= htmlspecialchars($message) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    
  <!-- Tabs Navigation -->
  <div class="mb-6 border-b border-gray-700">
    <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Tabs">
        <button type="button" data-tab="account" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-indigo-500 text-indigo-400 flex items-center">
            <svg class="w-4 h-4 mr-2" data-lucide="user" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-user"></use></svg>
            Account
        </button>
        <button type="button" data-tab="packages" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-400 flex items-center">
            <svg class="w-4 h-4 mr-2" data-lucide="package" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-package"></use></svg>
            Packages
        </button>
        <button type="button" data-tab="payments" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-400 flex items-center">
            <svg class="w-4 h-4 mr-2" data-lucide="credit-card" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-credit-card"></use></svg>
            Payments
        </button>
        <button type="button" data-tab="learning-path" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-400 flex items-center">
            <svg class="w-4 h-4 mr-2" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
            Learning Path
        </button>
        <button type="button" data-tab="notifications" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-400 flex items-center relative">
            <svg class="w-4 h-4 mr-2" data-lucide="bell" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-bell"></use></svg>
            Notifications
            <span id="unread-count" class="hidden absolute -top-1 -right-2 bg-red-500 text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center">0</span>
        </button>
    </nav>
  </div>
    
    <!-- Tab Content -->
    <div id="tab-content">
      <!-- Account Tab (default) -->
      <div id="account-tab" class="tab-pane">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <!-- Profile -->
          <section class="lg:col-span-2 glass rounded-xl p-6">
            <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Profile</h2>
            <p class="text-slate-600 dark:text-slate-400 mb-5">Your public details and avatar.</p>

            <form method="post" enctype="multipart/form-data" class="space-y-5">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <div class="flex items-center gap-4">
                <img src="<?= htmlspecialchars($avatarUrl) ?>" class="w-16 h-16 rounded-full object-cover border border-slate-300 dark:border-slate-700" alt="Avatar">
                <div>
                  <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Change photo (JPG/PNG, max 5MB)</label>
                  <input type="file" name="avatar" accept="image/png,image/jpeg" class="form-input max-w-xs">
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">First name</label>
                  <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                </div>
                <div>
                  <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Last name</label>
                  <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                </div>
              </div>

              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Email</label>
                <input type="email" name="email" class="form-input max-w-lg" value="<?= htmlspecialchars($user['email']) ?>" required>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Mobile number (WhatsApp)</label>
                  <input type="tel" name="phone_number" class="form-input" inputmode="tel"
                         placeholder="+265 999 12 34 56"
                         value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>">
                  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Start with your country code, e.g. <code>+265</code>. Used for lesson reminders on WhatsApp.
                  </p>
                </div>
                <div>
                  <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Parent / guardian number <span class="text-slate-400">(optional)</span></label>
                  <input type="tel" name="guardian_phone" class="form-input" inputmode="tel"
                         placeholder="+265 999 12 34 56"
                         value="<?= htmlspecialchars($user['guardian_phone'] ?? '') ?>">
                  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Somewhere else to send reminders if the student has no phone.
                  </p>
                </div>
              </div>

              <div class="pt-2">
                <button class="btn btn-primary">
                  Save Profile
                </button>
              </div>
            </form>
          </section>

          <!-- Preferences -->
          <section class="glass rounded-xl p-6">
            <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Preferences</h2>
            <p class="text-slate-600 dark:text-slate-400 mb-5">Currency and timezone used across your dashboard.</p>

            <form method="post" class="space-y-4">
              <input type="hidden" name="action" value="update_prefs">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Currency</label>
                <div class="dropdown-container relative">
                  <button type="button" class="dropdown-trigger ui-button w-full justify-between text-left">
                    <span class="truncate">
                      <?php
                      $currencies = ['USD'=>'USD','CAD'=>'CAD','EUR'=>'EUR','GBP'=>'GBP','KES'=>'KES','NGN'=>'NGN','ZAR'=>'ZAR','INR'=>'INR','AUD'=>'AUD'];
                      echo htmlspecialchars($user['currency_code'] ?? 'USD');
                      ?>
                    </span>
                    <svg class="w-4 h-4 ml-2 transition-transform" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                  </button>
                  <input type="hidden" name="currency_code" value="<?= htmlspecialchars($user['currency_code'] ?? 'USD') ?>">
                  <div class="dropdown-panel absolute top-full left-0 right-0 mt-1 z-50 hidden">
                    <div class="notif-panel rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 max-h-48 overflow-y-auto">
                      <?php foreach ($currencies as $code => $label): ?>
                        <button type="button"
                                class="dropdown-option w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors first:rounded-t-lg last:rounded-b-lg"
                                data-value="<?= htmlspecialchars($code) ?>"
                                data-target="currency_code">
                          <span class="text-sm "><?= htmlspecialchars($label) ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Timezone</label>
                <div class="dropdown-container relative">
                  <button type="button" class="dropdown-trigger ui-button w-full justify-between text-left">
                    <span class="truncate">
                      <?php
                      $allTimezones = DateTimeZone::listIdentifiers();
                      $currentTz = $user['timezone'] ?? 'UTC';
                      echo htmlspecialchars($currentTz);
                      ?>
                    </span>
                    <svg class="w-4 h-4 ml-2 transition-transform" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
                  </button>
                  <input type="hidden" name="timezone" value="<?= htmlspecialchars($currentTz) ?>">
                  <div class="dropdown-panel absolute top-full left-0 right-0 mt-1 z-50 hidden">
                    <div class="notif-panel rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 max-h-48 overflow-y-auto">
                      <?php foreach ($allTimezones as $tz): ?>
                        <button type="button"
                                class="dropdown-option w-full text-left px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                data-value="<?= htmlspecialchars($tz) ?>"
                                data-target="timezone">
                          <span class="text-sm font-medium">
                            <?= htmlspecialchars($tz) ?>
                            <?php if ($tz === 'UTC'): ?>
                              <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">(Coordinated Universal Time)</span>
                            <?php elseif ($tz === 'Africa/Blantyre'): ?>
                              <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">(Malawi)</span>
                            <?php endif; ?>
                          </span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="pt-2">
                <button class="btn btn-primary">
                  Save Preferences
                </button>
              </div>
            </form>
          </section>

          <!-- Password -->
          <section class="lg:col-span-3 glass rounded-xl p-6">
            <h2 class="text-lg font-bold mb-1 text-slate-900 dark:text-white">Change Password</h2>
            <p class="text-slate-600 dark:text-slate-400 mb-5">Use a strong, unique password. Minimum 8 characters.</p>

            <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <input type="hidden" name="action" value="change_password">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Current password</label>
                <input type="password" name="current_password" class="form-input" required>
              </div>
              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">New password</label>
                <input type="password" name="new_password" class="form-input" required>
              </div>
              <div>
                <label class="block text-sm text-slate-700 dark:text-slate-300 mb-1">Confirm new password</label>
                <input type="password" name="confirm_password" class="form-input" required>
              </div>

              <div class="md:col-span-3 pt-2">
                <button class="btn btn-primary">
                  Update Password
                </button>
              </div>
            </form>
          </section>
        </div>
      </div> <!-- End Account Tab -->
      
      <!-- Packages Tab -->
      <div id="packages-tab" class="tab-pane hidden">
       <div class="space-y-6">
          <?php /* Errors already appear once at the top of the page; this second
                   copy inside the tab made every failure show twice. */ ?>
          
          <header class="flex items-center justify-between mb-8">
              <div>
                  <h1 class="text-2xl font-semibold">Browse Packages</h1>
                  <p class="text-slate-400">Pick the plan that fits your study rhythm.</p>
              </div>
              <!-- <a href="payments.php" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 px-5 rounded-lg">Back</a> -->
          </header>

          <?php /* No $error variable is ever set here: it was the leaked loop
                   variable from `foreach ($errors as $error)` above, which made a
                   single failure render a third time. */ ?>

          <?php if (empty($packages)): ?>
            <div class="card p-8 text-center">
              <p class="text-slate-400">No packages available right now. Please check back later.</p>
            </div>
          <?php else: ?>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
              <?php if (empty($packages)): ?>
                  <div class="md:col-span-2 lg:col-span-3 py-12 text-center">
                      <svg class="mx-auto h-12 w-12 text-slate-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-package"></use></svg>
                      <h3 class="mt-2 text-sm font-medium text-slate-900 dark:text-white">No packages available</h3>
                      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">There are currently no packages available for purchase. Please check back later.</p>
                  </div>
              <?php else: ?>
                  <?php foreach ($packages as $package): ?>
                      <?php
                      $per_week = (float)$package['price'] / max(1, (int)$package['duration_days'] / 7);
                      $is_popular = isset($package['is_popular']) && $package['is_popular'];
                      ?>
                      <div class="relative glass rounded-xl shadow-lg overflow-hidden border border-slate-200 dark:border-slate-700 hover:shadow-xl transition-shadow duration-200">
                          <?php if ($is_popular): ?>
                              <div class="absolute top-0 right-0 bg-indigo-600 text-white text-xs font-bold px-3 py-1 transform translate-x-2 -translate-y-2 rounded-bl-lg">
                                  Popular
                              </div>
                          <?php endif; ?>
                          
                          <div class="p-6">
                              <div class="flex items-center justify-between mb-4">
                                  <h3 class="text-xl font-bold text-slate-900 dark:text-white">
                                      <?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8') ?>
                                  </h3>
                                  <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300">
                                      <?= $C . number_format((float)$package['price'], 2) ?>
                                  </span>
                              </div>
                              
                              <?php if (!empty($package['description'])): ?>
                                  <p class="text-slate-600 dark:text-slate-400 mb-6">
                                      <?= htmlspecialchars($package['description'], ENT_QUOTES, 'UTF-8') ?>
                                  </p>
                              <?php endif; ?>
                              
                              <?php
                                $days      = max(1, (int)$package['duration_days']);
                                $weeks     = max(1, (int)ceil($days / 7));
                                $perWeek   = (int)$package['lessons_per_week'];
                                $totalLess = $perWeek * $weeks;
                                $runsFor   = $days % 7 === 0
                                    ? ($weeks === 1 ? '1 week' : $weeks . ' weeks')
                                    : $days . ' days';
                              ?>
                              <div class="space-y-3 mb-6">
                                  <div class="flex items-center">
                                      <svg class="h-5 w-5 text-green-500 mr-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg>
                                      <span class="text-slate-700 dark:text-slate-300">
                                          <strong><?= $totalLess ?></strong> lesson<?= $totalLess === 1 ? '' : 's' ?> in total
                                      </span>
                                  </div>
                                  <div class="flex items-center">
                                      <svg class="h-5 w-5 text-green-500 mr-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg>
                                      <span class="text-slate-700 dark:text-slate-300">
                                          <?= $perWeek ?> per week, over <?= $runsFor ?>
                                      </span>
                                  </div>
                                  <?php if ($weeks > 1): /* on a one-week plan this just repeats the price */ ?>
                                  <div class="flex items-center">
                                      <svg class="h-5 w-5 text-green-500 mr-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check"></use></svg>
                                      <span class="text-slate-700 dark:text-slate-300">
                                          ~<?= $C . number_format($per_week, 2) ?> per week
                                      </span>
                                  </div>
                                  <?php endif; ?>
                              </div>
                              
                              <!-- start_checkout is handled by packages.php, not by this page:
                                   posting to self fell through to "Invalid action." -->
                              <form method="post" action="packages.php" class="mt-6">
                                  <input type="hidden" name="action" value="start_checkout">
                                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                  <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                                  <button type="submit" 
                                          class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium btn-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                                          <?= $package['is_available'] ?? true ? '' : 'disabled title="Currently unavailable"' ?>>
                                      <?= $package['is_available'] ?? true ? 'Get Started' : 'Coming Soon' ?>
                                  </button>
                              </form>
                          </div>
                      </div>
                  <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Payments Tab -->
      <div id="payments-tab" class="tab-pane hidden">
         <!-- Header -->
        <div class="glass rounded-2xl p-5 mb-4">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-lg bg-indigo-500/20 flex items-center justify-center">
                <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-receipt"></use></svg>
              </div>
              <div>
                <p class="text-sm text-slate-400">Next Payment</p>
                <p class="text-xl font-bold text-white">
                  <?php if ($current): ?>
                    <?= htmlspecialchars($current['package_name']) ?> · <?= htmlspecialchars($C . number_format((float)$current['price'], 2)) ?>
                    <?php else: ?>No active package<?php endif; ?>
                  </p>
                  <p class="text-sm text-slate-400"><?= $current ? htmlspecialchars($due_label) : 'Choose a package to begin' ?></p>
                </div>
              </div>

              <div class="flex items-stretch justify-center gap-2">
                <?php if ($current && $can_pay_now): ?>
                  <form method="post" action="api/pay_start.php" class="flex items-stretch">
                    <input type="hidden" name="sp_id" value="<?= (int)$current['sp_id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button class="btn btn-alternative font-semibold h-full">Pay Now</button>
                  </form>
                <?php else: ?>
                <button class="btn btn-primary font-semibold cursor-not-allowed h-[44px]" title="No payment due right now">Pay Now</button>
                <?php endif; ?>
                <button type="button" data-tab="packages" data-tab-cta class="tab-button btn btn-primary font-semibold h-[44px]">Browse Packages</button>
              </div>
            </div>
          </div>
          
          <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <!-- Left: Summary & Current Package -->
            <div class="xl:col-span-2 space-y-4">
              <!-- Current Package Details -->
              <div class="glass rounded-2xl p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white">Current Package</h3>
                <?php if ($current): ?>
                  <span class="text-xs font-medium px-2.5 py-1 rounded-full
                    <?= $current['sp_status']==='Active' ? 'bg-emerald-500/10 text-emerald-300' : 
                      ($current['sp_status']==='Pending' ? 'bg-amber-500/10 text-amber-300' : 'bg-slate-500/10 text-slate-300') ?>">
                    <?= htmlspecialchars($current['sp_status']) ?>
                  </span>
                <?php endif; ?>
              </div>

              <?php if ($current): 
                $used = max(0, (int)$current['total_lessons'] - (int)$current['lessons_remaining']);
                $pct = (int)$current['total_lessons'] > 0 ? round(($used / (int)$current['total_lessons']) * 100) : 0;
              ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div>
                    <p class="text-sm text-slate-400">Package</p>
                    <p class="text-white font-semibold"><?= htmlspecialchars($current['package_name']) ?></p>
                    <p class="text-slate-400 text-sm mt-1">
                      <?= htmlspecialchars($current['lessons_per_week']) ?> lessons/week · 
                      <?= (int)$current['duration_days'] ?> days
                    </p>
                  </div>
                  <div>
                    <p class="text-sm text-slate-400">Period</p>
                    <p class="text-white font-semibold">
                      <?= $current['start_date'] ? (new DateTime($current['start_date']))->format('M j, Y') : '—' ?>
                      –
                      <?= $current['end_date'] ? (new DateTime($current['end_date']))->format('M j, Y') : '—' ?>
                    </p>
                  </div>
                </div>

                <div class="mt-6">
                  <div class="w-full bg-slate-800 rounded-full h-3 overflow-hidden">
                    <div class="bg-indigo-500 h-3" style="width: <?= $pct ?>%"></div>
                  </div>
                  <p class="text-sm text-slate-400 mt-2">
                    <?= $used ?>/<?= (int)$current['total_lessons'] ?> lessons used · 
                    <?= (int)$current['lessons_remaining'] ?> remaining
                  </p>
                </div>
              <?php else: ?>
                <p class="text-slate-400">You don't have an active or pending package yet.</p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right: Payment History -->
          <div class="xl:col-span-1">
            <div class="glass rounded-2xl p-6 h-full">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white">Payment History</h3>
                <a href="payments_all.php" class="text-sm font-semibold text-indigo-400 hover:text-indigo-300">View All</a>
              </div>

              <?php if (empty($history)): ?>
                <p class="text-slate-400">No payments yet.</p>
              <?php else: ?>
                <div class="space-y-3">
                  <?php foreach ($history as $row): 
                    [$txt, $cls] = status_pill($row['status']);
                    $amt = (float)$row['amount'];
                  ?>
                  <div class="row-item p-4 rounded-lg">
                    <div class="flex items-center justify-between">
                      <div>
                        <p class="text-white font-semibold"><?= htmlspecialchars($row['package_name']) ?></p>
                        <p class="text-xs text-slate-400">
                          <?= (new DateTime($row['payment_date']))->format('M j, Y g:i A') ?> · 
                          <?= htmlspecialchars($row['payment_method']) ?>
                        </p>
                        <?php if (!empty($row['transaction_id'])): ?>
                          <p class="text-xs text-slate-500 mt-1">Txn: <?= htmlspecialchars($row['transaction_id']) ?></p>
                        <?php endif; ?>
                      </div>
                      <div class="text-right">
                        <p class="font-bold text-white"><?= htmlspecialchars($C . number_format($amt, 2)) ?></p>
                        <span class="inline-block text-xs font-medium px-2.5 py-1 rounded-full <?= $cls ?>">
                          <?= htmlspecialchars($txt) ?>
                        </span>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Learning Path Tab -->
      <div id="learning-path-tab" class="tab-pane hidden">
       <div class="space-y-4">

          <!-- Header -->
          <header class="glass rounded-2xl p-5 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div>
                <h1 class="text-2xl font-bold">My Learning Path</h1>
                <p class="text-slate-400 text-sm">
                  Student: <span class="text-slate-200 font-medium"><?= h($student_name ?: 'Me') ?></span>
                </p>
              </div>
              <form method="get" class="flex items-center gap-2">
                <label class="text-sm text-slate-300">Subject</label>
                <select name="subject_id" class="search-input">
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $subject_id===(int)$s['id']?'selected':''; ?>>
                      <?= h($s['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500">View</button>
              </form>
            </div>
          </header>

          <!-- KPI & overall -->
          <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="glass rounded-2xl p-6">
              <p class="text-sm text-slate-400">Overall Coverage</p>
              <div class="h-40 relative mt-2">
                <canvas id="overallDonut"></canvas>
                <div id="donutCenter" class="absolute inset-0 flex items-center justify-center pointer-events-none">
                  <div class="text-center">
                    <div class="text-3xl font-extrabold text-white"><?= number_format($overallPct,1) ?>%</div>
                    <div class="text-xs text-slate-400"><?= (int)$doneSub ?>/<?= (int)$totalSub ?> subtopics</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="glass rounded-2xl p-6">
              <p class="text-sm text-slate-400">Pace (last 30 days)</p>
              <div class="mt-2">
                <div class="text-3xl font-extrabold text-white"><?= number_format($pacePerWeek,1) ?>/wk</div>
                <p class="text-xs text-slate-400 mt-1"><?= (int)$done30 ?> unique subtopics completed in the last 30 days.</p>
                <div class="h-20 mt-3"><canvas id="spark"></canvas></div>
              </div>
            </div>

            <div class="glass rounded-2xl p-6">
              <p class="text-sm text-slate-400">Estimated Time to Finish</p>
              <div class="mt-2">
                <?php if ($weeksToGo !== null): ?>
                  <div class="text-3xl font-extrabold text-white"><?= (int)$weeksToGo ?> wk</div>
                  <p class="text-xs text-slate-400 mt-1"><?= (int)$remainingSub ?> subtopics remaining at current pace.</p>
                <?php else: ?>
                  <div class="text-3xl font-extrabold text-white">—</div>
                  <p class="text-xs text-slate-400 mt-1">Build a bit more history to estimate your pace.</p>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <!-- Next Up + Suggestions -->
          <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
            <div class="lg:col-span-2 glass rounded-2xl p-6">
              <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-semibold">Roadmap — <?= h($subject_name) ?></h3>
                <div class="text-sm text-slate-400"><?= count($topicRows) ?> topics</div>
              </div>

              <div class="space-y-3 max-h-[420px] overflow-auto pr-2">
                <?php if (empty($topicRows)): ?>
                  <p class="text-slate-400">No topics configured yet.</p>
                <?php else: foreach ($topicRows as $row):
                  $pct  = (float)$row['pct'];
                  $done = (int)$row['sub_done'];
                  $tot  = (int)$row['sub_total'];
                ?>
                  <div class="p-4 rounded-xl row-item">
                    <div class="flex items-center justify-between gap-3">
                      <div class="min-w-0">
                        <div class="font-semibold text-white truncate"><?= h($row['name']) ?></div>
                        <div class="text-xs text-slate-400"><?= $done ?>/<?= $tot ?> subtopics</div>
                      </div>
                      <div class="text-sm font-semibold"><?= number_format($pct,1) ?>%</div>
                    </div>
                    <div class="bar-bg w-full h-2 rounded-full mt-2">
                      <div class="bar-fg h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                    </div>

                    <?php if (!empty($row['subs'])): ?>
                      <div class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ($row['subs'] as $s):
                          $isDone = in_array((int)$s['id'], $coveredSubIds, true);
                        ?>
                          <span class="px-2 py-1 rounded-full text-xs <?= $isDone ? 'chip-done' : 'chip' ?>">
                            <?= h($s['name']) ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>

            <div class="glass rounded-2xl p-6">
              <h3 class="text-lg font-semibold mb-3">Next Up</h3>
              <?php if (!$nextTopic): ?>
                <p class="text-slate-400">You’re all caught up in this subject!</p>
              <?php else: ?>
                <div class="row-item p-4 rounded-xl pill mb-4">
                  <div class="text-sm text-slate-300">Topic</div>
                  <div class="font-semibold text-white"><?= h($nextTopic['name']) ?></div>
                  <?php if ($nextSub): ?>
                    <div class="mt-2 text-sm text-slate-300">Subtopic</div>
                    <div class="font-medium"><?= h($nextSub['name']) ?></div>
                  <?php endif; ?>
                </div>

                <div>
                  <h4 class="text-sm font-semibold text-slate-300 mb-2">Suggested Materials</h4>
                  <?php if (!empty($suggested)): ?>
                    <ul class="space-y-2">
                      <?php foreach ($suggested as $m): ?>
                        <li class="flex items-center justify-between gap-3 bg-[#0c162a] border border-[#102043] rounded-lg px-3 py-2">
                          <div class="min-w-0">
                            <div class="font-medium truncate"><?= h($m['title'] ?? 'Material') ?></div>
                            <?php if (!empty($m['type'])): ?>
                              <div class="text-xs text-slate-400"><?= h($m['type']) ?></div>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($m['url'])): ?>
                            <a href="<?= h($m['url']) ?>" target="_blank"
                              class="text-xs px-3 py-1 rounded bg-indigo-600 hover:bg-indigo-500">Open</a>
                          <?php else: ?>
                            <span class="text-xs text-slate-500">No link</span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <p class="text-slate-400 text-sm">No direct materials found. Try the library:</p>
                    <a href="materials.php?subject_id=<?= (int)$subject_id ?><?= $nextTopic ? '&topic_id='.(int)$nextTopic['id'] : '' ?><?= $nextSub ? '&subtopic_id='.(int)$nextSub['id'] : '' ?>"
                      class="inline-block mt-2 px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-sm">
                      Browse Materials
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <!-- Recent activity -->
          <section class="glass rounded-2xl p-6">
            <h3 class="text-lg font-semibold mb-4">Recent Activity</h3>
            <?php if (empty($recentLessons)): ?>
              <p class="text-slate-400">No recent lessons yet.</p>
            <?php else: ?>
              <ol class="relative border-l border-[#1f2a44] ml-2">
                <?php foreach ($recentLessons as $L):
                  $dt = new DateTime($L['scheduled_start_time']);
                  $detail = $recentDetails[(int)$L['id']] ?? [];
                ?>
                  <li class="mb-6 ml-6">
                    <span class="absolute -left-1.5 flex h-3 w-3">
                      <span class="relative inline-flex rounded-full h-3 w-3 bg-indigo-500"></span>
                    </span>
                    <div class="text-sm text-slate-400"><?= h($dt->format('M j, Y • g:i a')) ?></div>
                    <div class="font-medium text-white"><?= h($L['subject_name']) ?></div>
                    <?php if (!empty($detail)): ?>
                      <div class="mt-2 flex flex-wrap gap-2">
                        <?php foreach ($detail as $d): ?>
                          <span class="px-2 py-1 rounded-full chip text-xs">
                            <?= h($d['topic_name'].' — '.$d['sub_name']) ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </section>
        </div>
      </div>
      
      <!-- Notifications Tab -->
      <div id="notifications-tab" class="tab-pane hidden">
         <div class="space-y-6">
        <?php
          $np_pdo = $pdo; $np_user_id = $student_id; $np_phone = $user['phone_number'] ?? null;
          $np_action = 'settings.php#notifications';
          $np_hidden = ['action' => 'update_notif_prefs', 'csrf_token' => $_SESSION['csrf_token']];
          include __DIR__ . '/../components/notification_prefs_card.php';
        ?>
        <?php if (!$is_tab): ?>
            <div class="flex items-center justify-between mb-8">
                <div>
                    <!-- <p class="text-sm text-slate-400 ">Updates</p> -->
                    <h1 class="text-3xl font-bold text-white uppercase tracking-wide">Updates</h1>
                    <p class="text-slate-400 mt-2">Stay on top of lesson changes, homework reminders, and more.</p>
                </div>
                <!-- <a href="dashboard.php" class="px-4 py-2 rounded-lg bg-gray-800 text-sm font-semibold hover:bg-gray-700">Back to Dashboard</a> -->
            </div>
        <?php endif; ?>

        <?php if (empty($notifications)): ?>
            <div class="glass p-10 text-center text-slate-400">
                <p>No notifications yet.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($notifications as $n):
                    $unread = (int)$n['is_read'] === 0;
                    $actionUrl = notification_action_url($n);
                ?>
                    <div class="glass <?= $unread ? 'unread' : '' ?> rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-1">
                                <?php if ($unread): ?>
                                    <span class="badge-dot inline-block"></span>
                                <?php else: ?>
                                    <span class="w-2.5 h-2.5 rounded-full bg-slate-600 inline-block"></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-slate-400"><?= h(notification_label($n['type'] ?? null)) ?></p>
                                <p class="text-sm text-white mt-1"><?= h($n['message']) ?></p>
                                <p class="text-xs text-slate-500 mt-1"><?= dtfmt($n['created_at']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="<?= h($actionUrl) ?>" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/30">Open</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
      </div>
    </div>
  </main>

<script>
  document.addEventListener('DOMContentLoaded', function () {
      console.log('Tabs script loaded');

      const tabs = document.querySelectorAll('.tab-button');
      const tabPanes = document.querySelectorAll('.tab-pane');

      console.log('Tabs found:', tabs.length);
      console.log('Panes found:', tabPanes.length);

      if (!tabs.length || !tabPanes.length) {
          console.error('Tabs or panes not found — stopping');
          return;
      }

      function switchTab(tabName) {
          console.log('Switching to:', tabName);

          tabPanes.forEach(pane => pane.classList.add('hidden'));

          tabs.forEach(tab => {
              // Some buttons only borrow .tab-button for the click handler (the
              // "Browse Packages" CTA). Styling those as inactive tab headers put
              // grey text on a navy primary button: 3.22:1.
              if (tab.hasAttribute('data-tab-cta')) return;
              tab.classList.remove('border-indigo-500','text-indigo-400');
              tab.classList.add('border-transparent','text-gray-400');
          });

          const pane = document.getElementById(tabName + '-tab');
          const tab  = document.querySelector('[data-tab="' + tabName + '"]');

          if (!pane || !tab) {
              console.error('Pane or tab missing for:', tabName);
              return;
          }

          pane.classList.remove('hidden');
          tab.classList.remove('border-transparent','text-gray-400');
          tab.classList.add('border-indigo-500','text-indigo-400');

          history.replaceState(null, '', '#' + tabName);
      }

      tabs.forEach(tab => {
          tab.addEventListener('click', function () {
              switchTab(this.dataset.tab);
          });
      });

      switchTab(location.hash.replace('#','') || tabs[0].dataset.tab);

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

// Overall donut
(function(){
  const ctx = document.getElementById('overallDonut');
  if (!ctx) return;
  
  const done = <?= (int)$doneSub ?>, total = <?= (int)$totalSub ?>;
  const chart = new Chart(ctx, {
    type: 'doughnut',
    data: { 
      labels: ['Done', 'Remaining'], 
      datasets: [{
        data: [done, Math.max(0, total - done)],
        backgroundColor: ['#22d3ee', '#0b1b33'], // Default colors
        borderColor: 'transparent',
        borderWidth: 0,
        borderRadius: 6,
        spacing: 4,
        hoverOffset: 6
      }]
    },
    options: { 
      responsive: true, 
      maintainAspectRatio: false, 
      cutout: '72%', 
      plugins: {
        legend: { display: false }
      },
      animation: {
        animateScale: true,
        animateRotate: true,
        duration: 1000,
        easing: 'easeOutQuart'
      }
    }
  });

  // Function to update chart colors based on theme
  function updateChartColors() {
    const style = getComputedStyle(document.documentElement);
    const completedColor = style.getPropertyValue('--donut-completed').trim();
    const remainingColor = style.getPropertyValue('--donut-remaining').trim();
    
    chart.data.datasets[0].backgroundColor = [completedColor, remainingColor];
    chart.update();
  }

  // Initial update
  updateChartColors();

  // Listen for theme changes
  const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      if (mutation.attributeName === 'class') {
        setTimeout(updateChartColors, 10);
      }
    });
  });

  // Observe both html and body for theme changes
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class']
  });
  observer.observe(document.body, {
    attributes: true,
    attributeFilter: ['class']
  });
})();

    // Minimal sparkline from recent lessons count by day (client-side fake from PHP list)
    (function(){
      const ctx = document.getElementById('spark'); if (!ctx) return;
      // Build 14-day spark from recent lessons timestamps
      const recent = <?= json_encode($recentLessons) ?>;
      const map = new Map();
      recent.forEach(r=>{
        const d = new Date(r.scheduled_start_time.replace(' ','T'));
        const key = d.toISOString().slice(0,10);
        map.set(key, (map.get(key)||0)+1);
      });
      const days = [];
      for (let i=13;i>=0;i--){
        const d = new Date(); d.setDate(d.getDate()-i);
        const key = d.toISOString().slice(0,10);
        days.push({label:key, val:(map.get(key)||0)});
      }
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: days.map(d=>d.label.slice(5)),
          datasets: [{ data: days.map(d=>d.val), tension: .35, fill: true,
            backgroundColor: 'rgba(129,140,248,.18)', borderColor:'#818cf8', pointRadius: 0 }]
        },
        options: {
          responsive:true, maintainAspectRatio:false,
          scales:{x:{display:false}, y:{display:false, beginAtZero:true}},
          plugins:{legend:{display:false}, tooltip:{enabled:false}}
        }
      });
    })();
</script>

</body>
</html>