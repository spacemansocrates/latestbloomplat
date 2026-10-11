<?php
// tutors/sidebar.php — Lucide icons + Bloom brand

// Current page key should be set by parent page, e.g. $current_page = 'dashboard'
$current_page = $current_page ?? 'dashboard';

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function navClass(string $key, string $current): string {
  $base = 'flex items-center gap-3 px-4 py-2.5 rounded-lg transition-colors ';
  return $base . ($key === $current
    ? 'nav-active'
    : 'nav-inactive');
}

// Pull user info safely
$fn = $_SESSION['user_first_name'] ?? '';
$ln = $_SESSION['user_last_name'] ?? '';
$sidebar_user_name  = trim($fn . ' ' . $ln) ?: 'Tutor';
$sidebar_profile_photo = resolve_photo($_SESSION['profile_photo_url'] ?? null, $tutor_name);
$sidebar_user_role  = $_SESSION['user_role'] ?? 'Tutor';

// Brand logo (adjust path if your logo lives elsewhere)
$brand_logo = '../logo.png';

$tutor_catchup_alert = false;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../../config/db_connect.php';
  }
  if (isset($pdo) && $pdo instanceof PDO) {
    $st = $pdo->prepare("SELECT 1 FROM catchup_requests WHERE tutor_id = ? AND status IN ('Open','Proposed') LIMIT 1");
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $tutor_catchup_alert = (bool)$st->fetchColumn();
  }
} catch (Throwable $e) {
  $tutor_catchup_alert = false;
}

// A submitted assessment is waiting for this tutor's review and approval.
$tutor_assessment_alert = false;
try {
  if (isset($pdo) && $pdo instanceof PDO) {
    $st = $pdo->prepare("SELECT 1 FROM assessment_attempts WHERE tutor_id = ? AND status = 'submitted' LIMIT 1");
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $tutor_assessment_alert = (bool)$st->fetchColumn();
  }
} catch (Throwable $e) {
  // Table not migrated yet on this database: no alert, no error.
  $tutor_assessment_alert = false;
}
?>
<aside class="sidebar-container w-64 fixed left-0 top-0 h-screen z-40 overflow-y-auto flex-shrink-0 p-6 hidden md:flex flex-col justify-between">
  <div>
    <!-- Brand -->
    <a href="dashboard.php" class="flex items-center gap-3 mb-10">
      <div class="h-12 w-12 rounded-xl ring-1 ring-white/10 shadow-sm overflow-hidden bg-indigo-600/10 p-1.5">
        <img
          src="<?= h($brand_logo) ?>"
          alt="Bloom"
          class="brand-logo block h-full w-full object-contain select-none"
          loading="eager" decoding="async"
          onerror="this.style.display='none';"
        />
      </div>
      <h1 class="logo-text text-2xl font-bold tracking-tight">Bloom Tutor</h1>
    </a>

    <!-- Nav -->
    <nav class="space-y-2">
      <a href="dashboard.php" class="<?= navClass('dashboard', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="layout-dashboard" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-layout-dashboard"></use></svg>
        <span>Dashboard</span>
      </a>

      <a href="schedule.php" class="<?= navClass('schedule', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
        <span>My Schedule</span>
      </a>

      <a href="students.php" class="<?= navClass('students', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="users" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
        <span>Students</span>
      </a>

      <a href="messages.php" class="<?= navClass('messages', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="message-square" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-message-square"></use></svg>
        <span>Messages</span>
      </a>

      <a href="catchup.php" class="<?= navClass('catchup', $current_page) ?> <?= $tutor_catchup_alert ? 'sidebar-alert-glow' : '' ?>">
        <svg class="w-5 h-5" data-lucide="alarm-clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alarm-clock"></use></svg>
        <span class="flex-1">Catch-ups</span>
        <?php if ($tutor_catchup_alert): ?>
          <span class="sidebar-alert-dot" title="Catch-up pending"></span>
        <?php endif; ?>
      </a>

      <a href="assessments.php" class="<?= navClass('assessments', $current_page) ?> <?= $tutor_assessment_alert ? 'sidebar-alert-glow' : '' ?>">
        <svg class="w-5 h-5" data-lucide="clipboard-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clipboard-check"></use></svg>
        <span class="flex-1">Assessments</span>
        <?php if ($tutor_assessment_alert): ?>
          <span class="sidebar-alert-dot" title="Assessment ready to review"></span>
        <?php endif; ?>
      </a>

      <a href="homework.php" class="<?= navClass('homework', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="notebook-pen" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-notebook-pen"></use></svg>
        <span>Homework</span>
      </a>

      <a href="materials.php" class="<?= navClass('materials', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
        <span>Materials</span>
      </a>

      <a href="recordings.php" class="<?= navClass('recordings', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="video" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
        <span>Recordings</span>
      </a>

      <a href="settings.php" class="<?= navClass('settings', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="settings" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-settings"></use></svg>
        <span>Settings</span>
      </a>

      <div class="border-t border-gray-700 my-4"></div>

      <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-400 hover:bg-gray-800 hover:text-white transition-colors">
        <svg class="w-5 h-5" data-lucide="log-out" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-log-out"></use></svg>
        <span>Logout</span>
      </a>
    </nav>
  </div>

  <!-- User -->
  <div class="border-t border-gray-700/50 pt-6">
    <div class="flex items-center gap-3">
      <img src="<?= h($sidebar_profile_photo) ?>" data-fallback="<?= h(photo_fallback($tutor_name)) ?>" onerror="this.onerror=null;this.src=this.dataset.fallback" alt="Tutor Avatar" class="rounded-full w-10 h-10 object-cover border border-gray-700/30">
      <div>
        <p class="text-sm font-semibold text-primary"><?= h($sidebar_user_name) ?></p>
        <p class="text-xs text-secondary"><?= h($sidebar_user_role) ?></p>
      </div>
    </div>
  </div>
</aside>

<!-- Mobile drawer -->
<div id="mobile-nav" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50" data-close-nav></div>
  <div class="absolute inset-y-0 right-0 w-72 max-w-full bg-gray-900 shadow-xl border-l border-gray-800 p-6 overflow-y-auto">
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <img src="<?= h($sidebar_profile_photo) ?>" data-fallback="<?= h(photo_fallback($tutor_name)) ?>" onerror="this.onerror=null;this.src=this.dataset.fallback" alt="Tutor Avatar" class="rounded-full w-10 h-10 object-cover">
        <div>
          <p class="text-sm font-semibold text-white leading-tight"><?= h($sidebar_user_name) ?></p>
          <p class="text-xs text-gray-400"><?= h($sidebar_user_role) ?></p>
        </div>
      </div>
      <button type="button" data-close-nav class="w-10 h-10 inline-flex items-center justify-center rounded-full bg-gray-800 text-gray-200 border border-gray-700">
        <svg class="w-5 h-5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
    </div>

    <nav class="space-y-2">
      <a href="dashboard.php" class="<?= navClass('dashboard', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="layout-dashboard" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-layout-dashboard"></use></svg>
        <span>Dashboard</span>
      </a>

      <a href="messages.php" class="<?= navClass('messages', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="message-square" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-message-square"></use></svg>
        <span>Messages</span>
      </a>

      <?php
     // Get unread notifications count
     $unreadCount = 0;
     try {
         if (!isset($pdo) || !($pdo instanceof PDO)) {
             require_once __DIR__ . '/../../config/db_connect.php';
         }
         if (isset($pdo) && $pdo instanceof PDO) {
             $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
             $stmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
             $unreadCount = (int)$stmt->fetchColumn();
         }
     } catch (Exception $e) {
         // Silently fail, counter will show 0
     }
     ?>
     <a href="notifications.php" class="<?= navClass('notifications', $current_page) ?> relative">
        <svg class="w-5 h-5" data-lucide="bell" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-bell"></use></svg>
        <span>Notifications</span>
        <?php if ($unreadCount > 0): ?>
            <span id="notifications-count" class="absolute right-4 top-1/2 -translate-y-1/2 flex items-center justify-center h-5 min-w-5 px-1 rounded-full bg-red-500 text-white text-xs font-medium">
                <?= $unreadCount > 9 ? '9+' : $unreadCount ?>
            </span>
        <?php else: ?>
            <span id="notifications-count" class="hidden"></span>
        <?php endif; ?>
     </a>

      <a href="schedule.php" class="<?= navClass('schedule', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
        <span>My Schedule</span>
      </a>

      <a href="recordings.php" class="<?= navClass('recordings', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="video" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-video"></use></svg>
        <span>Recordings</span>
      </a>

      <a href="catchup.php" class="<?= navClass('catchup', $current_page) ?> <?= $tutor_catchup_alert ? 'sidebar-alert-glow' : '' ?>">
        <svg class="w-5 h-5" data-lucide="alarm-clock" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alarm-clock"></use></svg>
        <span class="flex-1">Catch-ups</span>
        <?php if ($tutor_catchup_alert): ?>
          <span class="sidebar-alert-dot" title="Catch-up pending"></span>
        <?php endif; ?>
      </a>

      <a href="students.php" class="<?= navClass('students', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="users" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
        <span>Students</span>
      </a>

      <a href="assessments.php" class="<?= navClass('assessments', $current_page) ?> <?= $tutor_assessment_alert ? 'sidebar-alert-glow' : '' ?>">
        <svg class="w-5 h-5" data-lucide="clipboard-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clipboard-check"></use></svg>
        <span class="flex-1">Assessments</span>
        <?php if ($tutor_assessment_alert): ?>
          <span class="sidebar-alert-dot" title="Assessment ready to review"></span>
        <?php endif; ?>
      </a>

      <a href="homework.php" class="<?= navClass('homework', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="notebook-pen" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-notebook-pen"></use></svg>
        <span>Homework</span>
      </a>

      <a href="learning_path.php" class="<?= navClass('learning_path', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="route" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-route"></use></svg>
        <span>Learning Path</span>
      </a>

      <a href="materials.php" class="<?= navClass('materials', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="book-open" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open"></use></svg>
        <span>Materials</span>
      </a>

      <a href="earnings.php" class="<?= navClass('earnings', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="wallet" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-wallet"></use></svg>
        <span>Earnings</span>
      </a>

      <a href="settings.php" class="<?= navClass('settings', $current_page) ?>">
        <svg class="w-5 h-5" data-lucide="settings" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-settings"></use></svg>
        <span>Settings</span>
      </a>

      <div class="border-t border-gray-700 my-4"></div>

      <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-200 hover:bg-gray-800 hover:text-white transition-colors">
        <svg class="w-5 h-5" data-lucide="log-out" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-log-out"></use></svg>
        <span>Logout</span>
      </a>
    </nav>
  </div>
</div>

<?php if (!defined('SIDEBAR_ALERT_STYLES')): define('SIDEBAR_ALERT_STYLES', true); ?>
<style>
.sidebar-alert-glow {
  border: 1px solid rgba(248,113,113,0.7);
  box-shadow: 0 0 14px rgba(248,113,113,0.65);
  color: #fecaca !important;
}
.sidebar-alert-glow i { color: #fecaca !important; }
.sidebar-alert-glow span { color: inherit; }
/* The glow colours above are drawn for a dark sidebar. On a white one that pale red
   lands at about 1.4:1 and the item may as well not be there. The student sidebar
   already had this override; the tutor one never got it. */
body.theme-light .sidebar-alert-glow,
body.theme-light .sidebar-alert-glow i { color: #b91c1c !important; }
body.theme-light .sidebar-alert-glow {
  border-color: rgba(220,38,38,0.55);
  box-shadow: 0 0 14px rgba(220,38,38,0.35);
}
/* Logout is gray-400, which is fine on the dark sidebar and 2.5:1 on the light one. */
body.theme-light #sidebar a[href$="logout.php"],
body.theme-light aside a[href$="logout.php"] { color: #4b5563; }
body.theme-light #sidebar a[href$="logout.php"]:hover,
body.theme-light aside a[href$="logout.php"]:hover { background-color: rgba(99,102,241,0.06); color: #111827; }
.sidebar-alert-dot {
  width: 10px;
  height: 10px;
  border-radius: 9999px;
  background: #f87171;
  box-shadow: 0 0 8px rgba(248,113,113,0.9);
  animation: sidebar-alert-pulse 1.2s ease-in-out infinite;
}
@keyframes sidebar-alert-pulse {
  0%,100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(0.7); opacity: 0.5; }
}
</style>
<?php endif; ?>

<!-- Lucide (real icons) — load once, then hydrate -->
<script>
(function(){
  })();

document.addEventListener('DOMContentLoaded', () => {
  const drawer = document.getElementById('mobile-nav');
  let openBtn = document.getElementById('mobile-nav-open');
  const closeEls = document.querySelectorAll('[data-close-nav]');
  if (!drawer) return;

  // Fallback: if a page does not render a trigger, inject a small mobile button in the top-right.
  if (!openBtn) {
    openBtn = document.createElement('button');
    openBtn.id = 'mobile-nav-open';
    openBtn.type = 'button';
    openBtn.className = 'md:hidden fixed top-4 right-4 z-40 inline-flex items-center justify-center w-11 h-11 rounded-full bg-indigo-600 text-white shadow-lg shadow-indigo-500/30 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-400 focus:ring-offset-gray-900';
    openBtn.innerHTML = '<svg class="w-5 h-5" data-lucide="menu" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-menu"></use></svg>';
    document.body.appendChild(openBtn);
    }
  if (!openBtn) return;

  const open = () => drawer.classList.remove('hidden');
  const close = () => drawer.classList.add('hidden');

  openBtn.addEventListener('click', open);
  closeEls.forEach(el => el.addEventListener('click', close));

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
});
</script>

<?php
// Include centralized page loader component
if (!defined('PAGE_LOADER_INCLUDED')) {
  define('PAGE_LOADER_INCLUDED', true);
  include __DIR__ . '/../../components/page_loader.php';
}
?>
