<?php
// students/sidebar.php

$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');

$fn = $_SESSION['user_first_name'] ?? '';
$ln = $_SESSION['user_last_name'] ?? '';
$sidebar_user_name  = trim($fn . ' ' . $ln) ?: 'Student';
$sidebar_profile_photo = $_SESSION['profile_photo_url'] ?? '../assets/avatar.php?s=40&bg=7c3aed&fg=ffffff&text=S';
$sidebar_user_role  = $_SESSION['user_role'] ?? 'Student';

// Point this to your Bloom logo path
$brand_logo = '../logo.png';

$student_catchup_alert = false;
try {
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../config/db_connect.php';
  }
  if (isset($pdo) && $pdo instanceof PDO) {
    $st = $pdo->prepare("SELECT 1 FROM catchup_requests WHERE student_id = ? AND status IN ('Open','Proposed') LIMIT 1");
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $student_catchup_alert = (bool)$st->fetchColumn();
  }
} catch (Throwable $e) {
  $student_catchup_alert = false;
}

// Demo students take their diagnostic assessment here. The link only appears
// once one has been assigned, and glows while it is still to be done.
$student_assessment_state = null;
try {
  if (isset($pdo) && $pdo instanceof PDO) {
    $st = $pdo->prepare("SELECT MAX(status IN ('assigned','in_progress')) FROM assessment_attempts WHERE student_id = ?");
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $v = $st->fetchColumn();
    $student_assessment_state = $v === null ? null : ((int)$v === 1 ? 'open' : 'done');
  }
} catch (Throwable $e) {
  $student_assessment_state = null;
}

$nav_links = [
  ['href' => 'dashboard.php',     'icon' => 'layout-dashboard',    'label' => 'Dashboard',     'key' => 'dashboard'],
  ['href' => 'assessment.php',    'icon' => 'clipboard-check', 'label' => 'Assessment',    'key' => 'assessment', 'alert' => $student_assessment_state === 'open'],
  ['href' => 'schedule.php',      'icon' => 'calendar',       'label' => 'Schedule',      'key' => 'schedule'],
  ['href' => 'availability.php',  'icon' => 'clock-8',        'label' => 'Availability',  'key' => 'availability', 'alert' => $student_catchup_alert],
  ['href' => 'messages.php',      'icon' => 'message-square', 'label' => 'Messages',      'key' => 'messages'],
  ['href' => 'topic_roadmap.php', 'icon' => 'route',          'label' => 'Topic Roadmap', 'key' => 'topic_roadmap'],
  ['href' => 'homeworks.php',     'icon' => 'notebook-pen',      'label' => 'Homework',      'key' => 'homework'],
  ['href' => 'recordings.php',    'icon' => 'video',         'label' => 'Recordings',    'key' => 'recordings'],
  ['href' => 'settings.php',      'icon' => 'settings',       'label' => 'Settings',      'key' => 'settings'],
  ['href' => '../logout.php',     'icon' => 'log-out',        'label' => 'Logout',        'key' => 'logout'],
];
if ($student_assessment_state === null) {
  $nav_links = array_values(array_filter($nav_links, fn($l) => $l['key'] !== 'assessment'));
}
?>

<!-- Desktop sidebar -->
<aside id="sidebar" class="w-64 flex-shrink-0 bg-white border-r border-gray-200 p-6 hidden md:flex flex-col justify-between text-slate-800">
  <div>
    <a href="dashboard.php" class="flex items-center gap-3 mb-10">
      <img
        src="<?= htmlspecialchars($brand_logo) ?>"
        alt="Bloom"
        class="brand-logo w-10 h-10 object-contain"
      />
      <h1 class="brand-title text-xl font-bold text-slate-900 tracking-tight">Bloom</h1>
    </a>

    <nav class="space-y-2">
      <?php foreach ($nav_links as $link): ?>
        <?php
          $active = ($current_page === $link['key']);
          $alert  = $link['alert'] ?? false;
        ?>
        <a href="<?= htmlspecialchars($link['href']) ?>"
           class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg transition-colors <?= $active ? 'active' : '' ?> <?= $alert ? 'sidebar-alert-glow' : '' ?>">
          <svg class="w-5 h-5" data-lucide="<?= htmlspecialchars($link['icon']) ?>" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-<?= htmlspecialchars($link['icon']) ?>"></use></svg>
          <span class="flex-1"><?= htmlspecialchars($link['label']) ?></span>
          <?php if ($alert): ?><span class="sidebar-alert-dot" title="Catch-up pending"></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="border-t border-gray-200 pt-6">
    <div class="flex items-center gap-3">
      <img src="<?= htmlspecialchars($sidebar_profile_photo) ?>" alt="Avatar" class="rounded-full w-10 h-10 object-cover">
      <div>
        <p class="text-sm font-bold"><?= htmlspecialchars($sidebar_user_name) ?></p>
        <p class="text-xs font-thin"><?= htmlspecialchars($sidebar_user_role) ?></p>
      </div>
    </div>
  </div>
</aside>

<!-- Mobile drawer -->
<div id="mobile-nav" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50" data-close-nav></div>
  <div class="absolute inset-y-0 right-0 w-72 max-w-full bg-white shadow-2xl border-l border-gray-200 p-6 overflow-y-auto text-slate-800">
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <img src="<?= htmlspecialchars($sidebar_profile_photo) ?>" alt="Avatar" class="rounded-full w-10 h-10 object-cover">
        <div>
          <p class="text-sm font-semibold text-slate-900 leading-tight"><?= htmlspecialchars($sidebar_user_name) ?></p>
          <p class="text-xs text-slate-500"><?= htmlspecialchars($sidebar_user_role) ?></p>
        </div>
      </div>
      <button type="button" data-close-nav class="w-10 h-10 inline-flex items-center justify-center rounded-full bg-gray-100 text-slate-700 border border-gray-200">
        <svg class="w-5 h-5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
    </div>

    <nav class="space-y-2">
      <?php foreach ($nav_links as $link): ?>
        <?php
          $active = ($current_page === $link['key']);
          $alert  = $link['alert'] ?? false;
        ?>
        <a href="<?= htmlspecialchars($link['href']) ?>"
           class="nav-link flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?= $active ? 'active' : '' ?> <?= $alert ? 'sidebar-alert-glow' : '' ?>">
          <svg class="w-5 h-5" data-lucide="<?= htmlspecialchars($link['icon']) ?>" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-<?= htmlspecialchars($link['icon']) ?>"></use></svg>
          <span class="flex-1"><?= htmlspecialchars($link['label']) ?></span>
          <?php if ($alert): ?><span class="sidebar-alert-dot" title="Catch-up pending"></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>



<script>
document.addEventListener('DOMContentLoaded', () => {
  const drawer = document.getElementById('mobile-nav');
  let openBtn = document.getElementById('mobile-nav-open');
  const closeEls = document.querySelectorAll('[data-close-nav]');
  if (!drawer) return;

  // Fallback: if the current page didn't render a trigger, inject a small mobile button in the top-right
  if (!openBtn) {
    openBtn = document.createElement('button');
    openBtn.id = 'mobile-nav-open';
    openBtn.type = 'button';
    openBtn.className = 'md:hidden fixed top-4 right-4 z-40 inline-flex items-center justify-center w-11 h-11 rounded-full bg-[#1C1E4F] text-white shadow-lg shadow-[#1C1E4F]/30 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#FDBE35] focus:ring-offset-white';
    openBtn.innerHTML = '<svg class="w-5 h-5" data-lucide="menu" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-menu"></use></svg>';
    document.body.appendChild(openBtn);
    }
  if (!openBtn) return;

  const open = () => drawer.classList.remove('hidden');
  const close = () => drawer.classList.add('hidden');

  openBtn.addEventListener('click', open);
  closeEls.forEach(el => el.addEventListener('click', close));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
});
</script>

<?php
// Include centralized page loader component
if (!defined('PAGE_LOADER_INCLUDED')) {
  define('PAGE_LOADER_INCLUDED', true);
  include __DIR__ . '/../../components/page_loader.php';
}
?>
