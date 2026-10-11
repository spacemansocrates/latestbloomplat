<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// admin_settings.php
session_start();

// --- Auth: Admins only ---
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? '';
$current_page = 'settings';

$db_host=DB_HOST; $db_name=DB_NAME; $db_user=DB_USER; $db_pass=DB_PASSWORD;

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) { die('DB error: '.h($e->getMessage())); }

// --- Determine admin access (either role='Administrator' or listed in admin_details) ---
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
  $st = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=?");
  $st->execute([$user_id]);
  $is_admin = (bool)$st->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// --- Ensure app_settings table exists (org-wide settings) ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 ;
");

// --- Add useful columns to admin_details if missing (per-admin notification prefs) ---
function ensureCol(PDO $pdo, $table, $col, $def) {
  $c = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $c->execute([$col]);
  if (!$c->fetch()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}
ensureCol($pdo,'admin_details','notify_new_message',"TINYINT(1) DEFAULT 1");
ensureCol($pdo,'admin_details','notify_payments',"TINYINT(1) DEFAULT 1");
ensureCol($pdo,'admin_details','notify_package_events',"TINYINT(1) DEFAULT 1");
ensureCol($pdo,'admin_details','notify_schedule_changes',"TINYINT(1) DEFAULT 1");

// --- Ensure admin_details row exists for this admin (so toggles can save) ---
$st = $pdo->prepare("SELECT * FROM admin_details WHERE user_id=?");
$st->execute([$user_id]);
$admin_row = $st->fetch();
if (!$admin_row) {
  // default role Admin if not present (you can change in DB to 'Super Admin' if needed)
  $pdo->prepare("INSERT INTO admin_details (user_id, admin_role) VALUES (?, 'Admin')")->execute([$user_id]);
  $st->execute([$user_id]);
  $admin_row = $st->fetch();
}

// --- Helpers to get/set app settings ---
function get_setting(PDO $pdo, string $key, $default=null) {
  $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key`=?");
  $s->execute([$key]);
  $v = $s->fetchColumn();
  if ($v === false || $v === null) return $default;
  return $v;
}
function set_setting(PDO $pdo, string $key, $value) {
  $s = $pdo->prepare("INSERT INTO app_settings (`key`,`value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $s->execute([$key, $value]);
}

// --- Ensure timezone column exists and load timezone helper ---
require_once __DIR__ . '/../utils/timezone_helper.php';
ensureTimezoneColumn($pdo);

// --- Load current user ---
$st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->execute([$user_id]);
$user = $st->fetch();
if (!$user) { die('User not found.'); }

// Get user's timezone (for personal settings)
$userTimezone = $user['timezone'] ?? 'UTC';
if (!isValidTimezone($userTimezone)) {
    $userTimezone = 'UTC';
}

// --- Defaults ---
$CURRENCIES = [
  'USD'=>'USD — US Dollar','CAD'=>'CAD — Canadian Dollar','EUR'=>'EUR — Euro','GBP'=>'GBP — British Pound',
  'MWK'=>'MWK — Malawian Kwacha','ZAR'=>'ZAR — South African Rand','KES'=>'KES — Kenyan Shilling',
  'TZS'=>'TZS — Tanzanian Shilling','NGN'=>'NGN — Nigerian Naira'
];
$tzPref = get_setting($pdo,'org.default_timezone','Africa/Blantyre');
$curPref = get_setting($pdo,'org.default_currency','USD');

// lesson durations and demo policy stored as JSON in app_settings
$lessonCfg = json_decode(get_setting($pdo,'org.lesson_prefs',''), true);
if (!is_array($lessonCfg)) $lessonCfg = ['allow30'=>1,'allow60'=>1,'allow_demo_without_student'=>1];

// payout config as JSON
$payoutCfg = json_decode(get_setting($pdo,'org.payout_cfg',''), true);
if (!is_array($payoutCfg)) $payoutCfg = ['cycle'=>'monthly','day'=>'1','min_threshold'=>'0.00','currency'=>$curPref];

// per-admin toggles
$adm_notify = [
  'new_message'      => (int)($admin_row['notify_new_message'] ?? 1),
  'payments'         => (int)($admin_row['notify_payments'] ?? 1),
  'package_events'   => (int)($admin_row['notify_package_events'] ?? 1),
  'schedule_changes' => (int)($admin_row['notify_schedule_changes'] ?? 1),
];

// --- CSRF ---
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$oks=[]; $errs=[];
function ok($m){ global $oks; $oks[]=$m; }
function er($m){ global $errs; $errs[]=$m; }

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf'],$token)) {
    $errs[]='Invalid session token. Refresh and try again.';
  } else try {

    if ($action === 'save_profile') {
      $fullName = trim($_POST['fullName'] ?? '');
      $email    = trim($_POST['email'] ?? '');
      $newTimezone = trim($_POST['user_timezone'] ?? '');
      
      if ($fullName==='' || $email==='') throw new RuntimeException('Name and email are required.');

      $parts = preg_split('/\s+/', $fullName);
      $first = array_shift($parts);
      $last  = trim(implode(' ',$parts) ?: ($user['last_name'] ?? ''));

      // email uniqueness (ignore self)
      $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
      $chk->execute([$email,$user_id]);
      if ($chk->fetch()) throw new RuntimeException('Email is already in use.');

      // Validate and save timezone
      if ($newTimezone !== '' && isValidTimezone($newTimezone)) {
        $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, timezone=? WHERE id=?")
            ->execute([$first, $last, $email, $newTimezone, $user_id]);
        $_SESSION['timezone'] = $newTimezone; // Update session timezone
        $userTimezone = $newTimezone;
      } else {
        $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?")
            ->execute([$first,$last,$email,$user_id]);
      }
      
      $_SESSION['user_first_name'] = $first;
      ok('Profile updated.');
    }

    if ($action === 'save_org_prefs') {
      $currency = strtoupper(substr(trim($_POST['currency'] ?? 'USD'),0,3));
      if (!isset($CURRENCIES[$currency])) $currency='USD';
      $timezone = trim($_POST['timezone'] ?? 'Africa/Blantyre');
      if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) $timezone='Africa/Blantyre';

      // lesson prefs
      $allow30 = isset($_POST['allow30']) ? 1 : 0;
      $allow60 = isset($_POST['allow60']) ? 1 : 0;
      if (!$allow30 && !$allow60) $allow60 = 1; // guarantee at least one
      $allow_demo = isset($_POST['allow_demo_without_student']) ? 1 : 0;

      set_setting($pdo,'org.default_currency',$currency);
      set_setting($pdo,'org.default_timezone',$timezone);
      set_setting($pdo,'org.lesson_prefs', json_encode([
        'allow30'=>$allow30,'allow60'=>$allow60,'allow_demo_without_student'=>$allow_demo
      ], JSON_UNESCAPED_SLASHES));
      ok('Organization preferences saved.');
      // refresh locals
      $curPref = $currency; $tzPref = $timezone; $lessonCfg = ['allow30'=>$allow30,'allow60'=>$allow60,'allow_demo_without_student'=>$allow_demo];
    }

    if ($action === 'save_payout') {
      $cycle = $_POST['cycle'] === 'weekly' ? 'weekly' : 'monthly';
      $day   = $cycle==='weekly'
              ? (in_array($_POST['day_week'] ?? 'Monday',['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],true) ? $_POST['day_week'] : 'Monday')
              : (string)max(1, min(28, (int)($_POST['day_month'] ?? 1)));
      $min   = number_format(max(0,(float)($_POST['min_threshold'] ?? 0)),2,'.','');
      $pcurr = strtoupper(substr(trim($_POST['payout_currency'] ?? $curPref),0,3));
      if (!isset($CURRENCIES[$pcurr])) $pcurr = $curPref;

      set_setting($pdo,'org.payout_cfg', json_encode([
        'cycle'=>$cycle,'day'=>$day,'min_threshold'=>$min,'currency'=>$pcurr
      ], JSON_UNESCAPED_SLASHES));
      ok('Payout settings saved.');
      $payoutCfg = ['cycle'=>$cycle,'day'=>$day,'min_threshold'=>$min,'currency'=>$pcurr];
    }

    if ($action === 'save_admin_notifications') {
      $nm = isset($_POST['notify_new_message']) ? 1 : 0;
      $np = isset($_POST['notify_payments']) ? 1 : 0;
      $nr = isset($_POST['notify_package_events']) ? 1 : 0;
      $ns = isset($_POST['notify_schedule_changes']) ? 1 : 0;

      $pdo->prepare("
        UPDATE admin_details SET 
          notify_new_message=?, notify_payments=?, notify_package_events=?, notify_schedule_changes=?
        WHERE user_id=?
      ")->execute([$nm,$np,$nr,$ns,$user_id]);

      ok('Notification preferences saved.');
      $adm_notify = ['new_message'=>$nm,'payments'=>$np,'package_events'=>$nr,'schedule_changes'=>$ns];
    }

    if ($action === 'save_password') {
      $current = (string)($_POST['currentPassword'] ?? '');
      $new     = (string)($_POST['newPassword'] ?? '');
      $confirm = (string)($_POST['confirmPassword'] ?? '');
      if ($new==='' || $confirm==='') throw new RuntimeException('Please enter and confirm your new password.');
      if ($new !== $confirm) throw new RuntimeException('New passwords do not match.');
      if (strlen($new) < 8)  throw new RuntimeException('Password must be at least 8 characters.');

      if (!password_verify($current, $user['password_hash'])) throw new RuntimeException('Current password is incorrect.');

      $hash = password_hash($new, PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$user_id]);
      ok('Password updated.');
    }

    // refresh user snapshot
    $st->execute([$user_id]);
    $user = $st->fetch();

  } catch (Throwable $e) { $errs[]=$e->getMessage(); }
}

// UI helpers
$fullName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin — Settings</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Inter',sans-serif;background:#111827;color:#d1d5db}
    .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.75rem 1rem;width:100%}
    .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px #4f46e5}
    ::-webkit-scrollbar{width:8px} ::-webkit-scrollbar-track{background:#1f2937} ::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
    .sidebar-icon{stroke-width:1.5}
  </style>
</head>
<body class="flex min-h-screen">

  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-6 lg:p-8">
    <header class="mb-8">
      <h2 class="text-2xl font-bold text-white">Settings</h2>
      <p class="text-gray-400">Organization preferences, payout configuration, and admin profile.</p>
    </header>

    <?php if (!empty($oks) || !empty($errs)): ?>
      <div class="mb-6 space-y-2">
        <?php foreach ($oks as $m): ?>
          <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2 rounded-lg"><?php echo h($m); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errs as $m): ?>
          <div class="bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2 rounded-lg"><?php echo h($m); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="max-w-5xl mx-auto space-y-10">
      <!-- Profile -->
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Admin Profile</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">Update your personal details.</p>
        <form method="post" class="space-y-6">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_profile">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Full Name</label>
              <input type="text" name="fullName" class="form-input" required value="<?php echo h($fullName); ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Email Address</label>
              <input type="email" name="email" class="form-input" required value="<?php echo h($user['email'] ?? ''); ?>">
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Your Timezone</label>
            <p class="text-xs text-gray-400 mb-2">Lesson times will be displayed in your local timezone. Scheduling inputs will be interpreted based on the student's timezone context.</p>
            <select name="user_timezone" class="form-input">
              <?php
              // Common timezones first
              $commonTzs = getCommonTimezones();
              $allTzs = DateTimeZone::listIdentifiers();
              
              echo '<optgroup label="Common Timezones">';
              foreach ($commonTzs as $tz) {
                $selected = ($userTimezone === $tz) ? 'selected' : '';
                $label = getTimezoneLabel($tz);
                echo '<option value="' . h($tz) . '" ' . $selected . '>' . h($label) . '</option>';
              }
              echo '</optgroup>';
              
              echo '<optgroup label="All Timezones">';
              foreach ($allTzs as $tz) {
                if (!in_array($tz, $commonTzs, true)) {
                  $selected = ($userTimezone === $tz) ? 'selected' : '';
                  echo '<option value="' . h($tz) . '" ' . $selected . '>' . h($tz) . '</option>';
                }
              }
              echo '</optgroup>';
              ?>
            </select>
          </div>
          <div class="flex justify-end">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save Profile</button>
          </div>
        </form>
      </section>

      <!-- Org Preferences -->
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Organization Preferences</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">Defaults applied across the platform.</p>
        <form method="post" class="space-y-6">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_org_prefs">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Default Currency</label>
              <select name="currency" class="form-input">
                <?php foreach ($CURRENCIES as $code=>$label): ?>
                  <option value="<?php echo h($code); ?>" <?php echo $curPref===$code?'selected':''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-300 mb-2">Default Timezone</label>
              <select name="timezone" class="form-input">
                <?php
                $tzList = array_values(array_unique(array_merge(
                  ['Africa/Blantyre','UTC','Europe/London','Africa/Johannesburg','Africa/Nairobi','America/New_York','America/Los_Angeles'],
                  DateTimeZone::listIdentifiers()
                )));
                foreach ($tzList as $tz): ?>
                  <option value="<?php echo h($tz); ?>" <?php echo $tzPref===$tz?'selected':''; ?>><?php echo h($tz); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="space-y-3">
              <p class="text-sm font-medium text-gray-300">Allowed Lesson Durations</p>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="allow30" <?php echo $lessonCfg['allow30']? 'checked':''; ?>> 30 minutes (Demo)
              </label><br/>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="allow60" <?php echo $lessonCfg['allow60']? 'checked':''; ?>> 60 minutes
              </label>
            </div>
            <div class="md:col-span-2 flex items-center justify-between bg-gray-800 p-4 rounded-lg">
              <div>
                <p class="text-sm font-medium text-white">Allow demo lessons without a student</p>
                <p class="text-xs text-gray-400">Admins can create 30-min demos with no `student_id` attached.</p>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" name="allow_demo_without_student" class="sr-only" <?php echo $lessonCfg['allow_demo_without_student']? 'checked':''; ?>>
                <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
              </label>
            </div>
          </div>
          <div class="flex justify-end">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save Preferences</button>
          </div>
        </form>
      </section>

      <!-- Payout Settings -->
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Payout Settings</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">Controls when and how earnings are paid out.</p>
        <form method="post" class="space-y-6" id="payoutForm">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_payout">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Cycle</label>
              <select name="cycle" class="form-input" id="cycleSel">
                <option value="weekly"  <?php echo $payoutCfg['cycle']==='weekly' ? 'selected':''; ?>>Weekly</option>
                <option value="monthly" <?php echo $payoutCfg['cycle']==='monthly'? 'selected':''; ?>>Monthly</option>
              </select>
            </div>
            <div id="weeklyDayWrap" class="<?php echo $payoutCfg['cycle']==='weekly' ? '' : 'hidden'; ?>">
              <label class="block text-sm font-medium text-gray-300 mb-2">Weekday</label>
              <select name="day_week" class="form-input">
                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $wd): ?>
                  <option value="<?php echo $wd; ?>" <?php echo ($payoutCfg['cycle']==='weekly' && $payoutCfg['day']===$wd)?'selected':''; ?>><?php echo $wd; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="monthlyDayWrap" class="<?php echo $payoutCfg['cycle']==='monthly' ? '' : 'hidden'; ?>">
              <label class="block text-sm font-medium text-gray-300 mb-2">Day of Month</label>
              <select name="day_month" class="form-input">
                <?php for($i=1;$i<=28;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ($payoutCfg['cycle']==='monthly' && (int)$payoutCfg['day']===$i)?'selected':''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Minimum Threshold</label>
              <input type="number" step="0.01" min="0" name="min_threshold" class="form-input" value="<?php echo h($payoutCfg['min_threshold']); ?>">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Payout Currency</label>
              <select name="payout_currency" class="form-input">
                <?php foreach ($CURRENCIES as $code=>$label): ?>
                  <option value="<?php echo h($code); ?>" <?php echo $payoutCfg['currency']===$code?'selected':''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="flex justify-end">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save Payout Settings</button>
          </div>
        </form>
      </section>

      <!-- Admin Notifications (per-admin) -->
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Admin Notifications</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">Choose which alerts you receive.</p>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_admin_notifications">

          <div class="flex items-center justify-between">
            <span class="text-gray-300">New messages</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="notify_new_message" class="sr-only" <?php echo $adm_notify['new_message']?'checked':''; ?>>
              <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            </label>
          </div>

          <div class="flex items-center justify-between">
            <span class="text-gray-300">Payments</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="notify_payments" class="sr-only" <?php echo $adm_notify['payments']?'checked':''; ?>>
              <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            </label>
          </div>

          <div class="flex items-center justify-between">
            <span class="text-gray-300">Package renewals / expirations</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="notify_package_events" class="sr-only" <?php echo $adm_notify['package_events']?'checked':''; ?>>
              <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            </label>
          </div>

          <div class="flex items-center justify-between">
            <span class="text-gray-300">Schedule changes</span>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" name="notify_schedule_changes" class="sr-only" <?php echo $adm_notify['schedule_changes']?'checked':''; ?>>
              <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            </label>
          </div>

          <div class="flex justify-end pt-2">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Save Notification Settings</button>
          </div>
        </form>
      </section>

      <!-- Change Password -->
      <section class="bg-gray-900 p-6 rounded-lg">
        <h3 class="text-lg font-semibold text-white">Change Password</h3>
        <p class="text-sm text-gray-400 mt-1 mb-6">Keep your account secure.</p>
        <form method="post" class="space-y-6" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_password">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Current Password</label>
            <input type="password" name="currentPassword" class="form-input" required>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
              <input type="password" name="newPassword" class="form-input" minlength="8" required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Confirm New Password</label>
              <input type="password" name="confirmPassword" class="form-input" minlength="8" required>
            </div>
          </div>
          <div class="flex justify-end">
            <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg">Update Password</button>
          </div>
        </form>
      </section>
    </div>
  </main>

  <script>
    // Toggle weekly/monthly day picker
    const cycleSel = document.getElementById('cycleSel');
    const weeklyWrap = document.getElementById('weeklyDayWrap');
    const monthlyWrap = document.getElementById('monthlyDayWrap');
    if (cycleSel) {
      cycleSel.addEventListener('change', () => {
        if (cycleSel.value === 'weekly') { weeklyWrap.classList.remove('hidden'); monthlyWrap.classList.add('hidden'); }
        else { monthlyWrap.classList.remove('hidden'); weeklyWrap.classList.add('hidden'); }
      });
    }
  </script>
</body>
</html>
