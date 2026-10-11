<?php
// admin_sidebar.php — Compact Grouped Sidebar

if (!isset($current_page)) $current_page = '';
if (!isset($current_subpage)) $current_subpage = '';

if (!function_exists('h')) {
  function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// -- Styling Functions --

// Style for top-level clickable links (Dashboard, Users) AND Parent Dropdown Buttons
function navClass(bool $isActive): string {
  $base = 'flex items-center justify-between w-full px-3 py-2 rounded-lg transition-all duration-200 text-sm ';
  return $base . ($isActive
    ? 'bg-gradient-to-r from-indigo-600/20 to-indigo-500/10 text-white font-semibold shadow-lg shadow-indigo-500/10 ring-1 ring-indigo-500/30'
    : 'text-gray-300 hover:bg-gray-800/60 hover:text-white hover:translate-x-0.5');
}

// Style for the nested children items
function leafClass(bool $isActive): string {
  $base = 'flex items-center gap-3 px-10 py-2 rounded-sm transition-all duration-200 text-sm ';
  return $base . ($isActive
    ? 'bg-indigo-500/10 text-indigo-300 font-medium border-l-2 border-indigo-500' // Highlight active child with color
    : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800/50 hover:translate-x-1');
}

// -- Data & State --

$brand_logo   = '../logo-white.png';
$admin_name   = trim(($a=$_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? '')) ?: 'Admin';
$admin_role   = $_SESSION['user_role'] ?? 'Administrator';
$admin_avatar = $_SESSION['profile_photo_url'] ?? '../assets/avatar.php?s=40&bg=6366f1&fg=ffffff&text=AD';

// -- Group Logic --
// We define arrays of page keys to know which Group should be open
$group_schedule  = ['lessons', 'daily_lessons', 'recordings'];
$group_academics = ['curriculum', 'materials', 'qc_assignment'];
$group_business  = ['revenue', 'banners'];

// Determine if groups are open based on current page
$openSchedule  = in_array($current_page, $group_schedule);
$openAcademics = in_array($current_page, $group_academics);
$openBusiness  = in_array($current_page, $group_business);

?>

<aside class="w-64 flex-shrink-0 bg-gradient-to-b from-gray-900 via-gray-900 to-gray-800 border-r border-gray-800/50 p-6 hidden md:flex flex-col justify-between overflow-y-auto custom-scrollbar sticky top-0 h-screen shadow-2xl">
  <div>
    <a href="admin_dashboard.php" class="flex items-center gap-2.5 mb-6 group">
      <div class="relative flex-shrink-0">
        <img src="<?= h($brand_logo) ?>" alt="Bloom" class="w-10 h-10 rounded-lg object-contain bg-gray-800/60 p-1 ring-2 ring-indigo-500/20 shadow-lg group-hover:ring-indigo-500/40 transition-all" />
        <div class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-emerald-500 rounded-full border-2 border-gray-900"></div>
      </div>
      <div class="min-w-0 flex-1">
        <h1 class="text-sm font-bold text-white tracking-tight truncate">Bloom Admin</h1>
        <p class="text-[10px] text-gray-400 truncate">Control Center</p>
      </div>
    </a>

    <nav class="space-y-1">
      
      <a href="admin_dashboard.php" class="<?= navClass($current_page === 'dashboard') ?> group">
        <span class="flex items-center gap-2.5">
          <svg class="w-4 h-4 flex-shrink-0" data-lucide="layout-dashboard" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-layout-dashboard"></use></svg>
          <span class="truncate">Dashboard</span>
        </span>
      </a>

      <a href="admin_users.php" class="<?= navClass($current_page === 'users') ?> group">
        <span class="flex items-center gap-2.5">
          <svg class="w-4 h-4 flex-shrink-0" data-lucide="users" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
          <span class="truncate">User Management</span>
        </span>
      </a>

      <?php if (!function_exists('can') || can('lessons.write')): ?>
      <a href="assessments.php" class="<?= navClass($current_page === 'assessments') ?> group">
        <span class="flex items-center gap-2.5">
          <svg class="w-4 h-4 flex-shrink-0" data-lucide="clipboard-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clipboard-check"></use></svg>
          <span class="truncate">Demo Assessments</span>
        </span>
      </a>
      <?php endif; ?>

      <?php if (function_exists('can') && can('payments.manage')): ?>
      <a href="subscriptions.php" class="<?= navClass($current_page === 'subscriptions') ?> group">
        <span class="flex items-center gap-2.5">
          <svg class="w-4 h-4 flex-shrink-0" data-lucide="credit-card" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-credit-card"></use></svg>
          <span class="truncate">Subscriptions</span>
        </span>
      </a>
      <?php endif; ?>

      <div class="border-t border-gray-700/30 my-2"></div>

      <div class="pt-2">
        <p class="px-2 text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
          <!-- <span class="w-0.5 h-3 bg-indigo-500 rounded-full flex-shrink-0"></span> -->
          <span class="truncate">Teaching Schedule</span>
        </p>
        <div class="rounded-lg <?= $openSchedule ? 'bg-gray-800/50 ring-1 ring-indigo-500/20' : '' ?> transition-all">
            <button
              type="button"
              class="<?= navClass($openSchedule) ?>"
              data-toggle="dropdown"
              aria-expanded="<?= $openSchedule ? 'true' : 'false' ?>">
              <span class="flex items-center gap-2.5 flex-1 min-w-0">
                  <svg class="w-4 h-4 flex-shrink-0" data-lucide="calendar-range" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar-range"></use></svg>
                  <span class="truncate">Lesson Management</span>
              </span>
              <svg class="w-3.5 h-3.5 flex-shrink-0 transition-transform duration-200 <?= $openSchedule ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
            </button>

            <div class="overflow-hidden transition-[max-height] duration-300 ease-in-out <?= $openSchedule ? 'max-h-[500px]' : 'max-h-0' ?>">
            <div class="mt-0.5 space-y-0.5 mb-1.5">
                <a href="admin_lessons.php?view=calendar" class="<?= leafClass($current_subpage === 'calendar') ?>">
                    <span class="truncate text-xs">Lesson Calendar</span>
                </a>
                <a href="admin_lessons.php" class="<?= leafClass($current_subpage === 'list') ?>">
                    <span class="truncate text-xs">All Lessons</span>
                </a>
                <a href="admin_lessons.php?view=day" class="<?= leafClass($current_subpage === 'day') ?>">
                    <span class="truncate text-xs">Day Schedule</span>
                </a>
                <a href="admin_lessons.php?view=heatmap" class="<?= leafClass($current_subpage === 'heatmap') ?>">
                    <span class="truncate text-xs">Heatmap</span>
                </a>
                <a href="recordings.php" class="<?= leafClass($current_page === 'recordings') ?>">
                    <span class="truncate text-xs">Class Recordings</span>
                </a>
            </div>
            </div>
        </div>
      </div>

      <div class="border-t border-gray-700/30 my-2"></div>

      <div class="pt-2">
         <p class="px-2 text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
           <!-- <span class="w-0.5 h-3 bg-emerald-500 rounded-full flex-shrink-0"></span> -->
           <span class="truncate">Academic Content</span>
         </p>
         <div class="rounded-lg <?= $openAcademics ? 'bg-gray-800/50 ring-1 ring-emerald-500/20' : '' ?> transition-all">
            <button
            type="button"
            class="<?= navClass($openAcademics) ?>"
            data-toggle="dropdown"
            aria-expanded="<?= $openAcademics ? 'true' : 'false' ?>">
            <span class="flex items-center gap-2.5 flex-1 min-w-0">
                <svg class="w-4 h-4 flex-shrink-0" data-lucide="book-open-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open-check"></use></svg>
                <span class="truncate">Content Management</span>
            </span>
            <svg class="w-3.5 h-3.5 flex-shrink-0 transition-transform duration-200 <?= $openAcademics ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
            </button>

            <div class="overflow-hidden transition-[max-height] duration-300 ease-in-out <?= $openAcademics ? 'max-h-[500px]' : 'max-h-0' ?>">
                <div class="mt-0.5 space-y-0.5 mb-1.5">
                    <a href="admin_curriculum.php" class="<?= leafClass($current_page === 'curriculum') ?>">
                        <span class="truncate text-xs">Manage Subjects</span>
                    </a>
                    <a href="materials.php" class="<?= leafClass($current_page === 'materials') ?>">
                        <span class="truncate text-xs">Teaching Resources</span>
                    </a>
                    <a href="qc_assignment.php" class="<?= leafClass($current_page === 'qc_assignment') ?>">
                        <span class="truncate text-xs">Quality Control</span>
                    </a>
                </div>
            </div>
        </div>
      </div>

      <div class="border-t border-gray-700/30 my-2"></div>

      <div class="pt-2">
        <p class="px-2 text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
          <!-- <span class="w-0.5 h-3 bg-amber-500 rounded-full flex-shrink-0"></span> -->
          <span class="truncate">Finance & Operations</span>
        </p>
        <div class="rounded-xl <?= $openBusiness ? 'bg-gray-800/50 ring-1 ring-amber-500/20' : '' ?> transition-all">
        <button
              type="button"
              class="<?= navClass($openBusiness) ?>"
              data-toggle="dropdown"
              aria-expanded="<?= $openBusiness ? 'true' : 'false' ?>">
              <span class="flex items-center gap-2.5 flex-1 min-w-0">
                  <svg class="w-4 h-4 flex-shrink-0" data-lucide="briefcase" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-briefcase"></use></svg>
                  <span class="truncate">Revenue Management</span>
              </span>
              <svg class="w-3.5 h-3.5 flex-shrink-0 transition-transform duration-200 <?= $openBusiness ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
              </button>


            <div class="overflow-hidden transition-[max-height] duration-300 ease-in-out <?= $openBusiness ? 'max-h-[500px]' : 'max-h-0' ?>">
                <div class="mt-1 space-y-0.5 mb-1">
                    <a href="admin_revenue.php" class="<?= leafClass($current_page === 'revenue' && $current_subpage === 'overview') ?>">
                        <span class="truncate text-xs">Overview</span>
                    </a>
                    <a href="admin_revenue.php?view=payments" class="<?= leafClass($current_page === 'revenue' && $current_subpage === 'payments') ?>">
                        <span class="truncate text-xs">Payments</span>
                    </a>
                    <a href="admin_revenue.php?view=packages" class="<?= leafClass($current_page === 'revenue' && $current_subpage === 'packages') ?>">
                        <span class="truncate text-xs">Packages</span>
                    </a>
                    <a href="admin_revenue.php?view=analytics" class="<?= leafClass($current_page === 'revenue' && $current_subpage === 'analytics') ?>">
                        <span class="truncate text-xs">Analytics</span>
                    </a>
                    <a href="admin_banners.php" class="<?= leafClass($current_page === 'banners') ?>">
                        <span class="truncate text-xs">Promotional Banners</span>
                    </a>
                </div>
            </div>
        </div>
      </div>

      <a href="admin_messaging_notifications.php" class="<?= navClass($current_page === 'messaging') ?> mt-2 group">
        <span class="flex items-center gap-3">
          <svg class="w-5 h-5 group-hover:scale-110 transition-transform" data-lucide="message-square" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-message-square"></use></svg>
          <span>Messages</span>
        </span>
      </a>

      <a href="whatsapp.php" class="<?= navClass($current_page === 'whatsapp') ?> group">
        <span class="flex items-center gap-3">
          <svg class="w-5 h-5 group-hover:scale-110 transition-transform" data-lucide="send" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-send"></use></svg>
          <span>WhatsApp</span>
        </span>
      </a>

      <div class="border-t border-gray-700/50 my-4"></div>

      <a href="admin_settings.php" class="<?= navClass($current_page === 'settings') ?> group">
        <span class="flex items-center gap-3">
          <svg class="w-5 h-5 group-hover:scale-110 transition-transform" data-lucide="settings" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-settings"></use></svg>
          <span>Settings</span>
        </span>
      </a>

    </nav>
  </div>


  <div class="border-t border-gray-700/50 pt-4 mt-3 bg-gray-800/30 rounded-lg p-3">
    <div class="flex items-center gap-2.5 mb-2">
      <div class="relative flex-shrink-0">
        <img src="<?= h($admin_avatar) ?>" alt="Admin Avatar" class="rounded-full w-9 h-9 object-cover ring-2 ring-indigo-500/30 shadow-lg">
        <div class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 rounded-full border-2 border-gray-900"></div>
      </div>
      <div class="overflow-hidden flex-1 min-w-0">
        <p class="text-xs font-semibold text-white truncate"><?= h($admin_name) ?></p>
        <p class="text-[10px] text-gray-400 truncate"><?= h($admin_role) ?></p>
      </div>
    </div>
    <a href="../logout.php" class="inline-flex items-center gap-2 text-[11px] font-medium text-gray-400 hover:text-red-400 transition-colors px-2 py-1.5 rounded-md hover:bg-red-500/10 w-full">
      <svg class="w-3.5 h-3.5 flex-shrink-0" data-lucide="log-out" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-log-out"></use></svg>
      <span class="truncate">Sign out</span>
    </a>
  </div>
</aside>

<div id="mobile-nav" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" data-close-nav></div>
  <div class="absolute inset-y-0 right-0 w-72 max-w-full bg-gray-900 shadow-2xl border-l border-gray-800 p-6 overflow-y-auto">
    
    <div class="flex items-center justify-between mb-8">
      <h2 class="text-lg font-bold text-white">Menu</h2>
      <button type="button" data-close-nav class="w-8 h-8 inline-flex items-center justify-center rounded-full bg-gray-800 text-gray-400 hover:text-white border border-gray-700">
        <svg class="w-5 h-5" data-lucide="x" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
    </div>

    <nav class="space-y-2">
      <a href="admin_dashboard.php" class="<?= navClass($current_page === 'dashboard') ?>">
        <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="layout-dashboard" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-layout-dashboard"></use></svg><span>Dashboard</span></span>
      </a>
      
      <a href="admin_users.php" class="<?= navClass($current_page === 'users') ?>">
        <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="users" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg><span>User Management</span></span>
      </a>

      <?php if (!function_exists('can') || can('lessons.write')): ?>
      <a href="assessments.php" class="<?= navClass($current_page === 'assessments') ?>">
        <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="clipboard-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-clipboard-check"></use></svg><span>Demo Assessments</span></span>
      </a>
      <?php endif; ?>

      <?php if (function_exists('can') && can('payments.manage')): ?>
      <a href="subscriptions.php" class="<?= navClass($current_page === 'subscriptions') ?>">
        <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="credit-card" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-credit-card"></use></svg><span>Subscriptions</span></span>
      </a>
      <?php endif; ?>

      <div>
        <button type="button" class="<?= navClass($openSchedule) ?>" data-toggle="dropdown" aria-expanded="<?= $openSchedule ? 'true' : 'false' ?>">
          <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="calendar-range" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar-range"></use></svg><span>Classes</span></span>
          <svg class="w-4 h-4 transition-transform <?= $openSchedule ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
        </button>
        <div class="overflow-hidden transition-[max-height] duration-300 <?= $openSchedule ? 'max-h-96' : 'max-h-0' ?>">
          <div class="mt-1 space-y-1 pl-2 border-l border-gray-800 ml-6">
            <a href="admin_lessons.php?view=calendar" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Calendar</a>
            <a href="admin_lessons.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">All lessons</a>
            <a href="admin_lessons.php?view=heatmap" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Heatmap</a>
            <a href="recordings.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Recordings</a>
          </div>
        </div>
      </div>

       <div>
        <button type="button" class="<?= navClass($openAcademics) ?>" data-toggle="dropdown" aria-expanded="<?= $openAcademics ? 'true' : 'false' ?>">
          <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="book-open-check" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-book-open-check"></use></svg><span>Academics</span></span>
          <svg class="w-4 h-4 transition-transform <?= $openAcademics ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
        </button>
        <div class="overflow-hidden transition-[max-height] duration-300 <?= $openAcademics ? 'max-h-96' : 'max-h-0' ?>">
          <div class="mt-1 space-y-1 pl-2 border-l border-gray-800 ml-6">
            <a href="admin_curriculum.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Subjects</a>
            <a href="materials.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Materials</a>
            <a href="qc_assignment.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">QC Assignment</a>
          </div>
        </div>
      </div>

       <div>
        <button type="button" class="<?= navClass($openBusiness) ?>" data-toggle="dropdown" aria-expanded="<?= $openBusiness ? 'true' : 'false' ?>">
          <span class="flex items-center gap-3"><svg class="w-5 h-5" data-lucide="briefcase" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-briefcase"></use></svg><span>Business</span></span>
          <svg class="w-4 h-4 transition-transform <?= $openBusiness ? 'rotate-180' : '' ?>" data-lucide="chevron-down" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-down"></use></svg>
        </button>
        <div class="overflow-hidden transition-[max-height] duration-300 <?= $openBusiness ? 'max-h-96' : 'max-h-0' ?>">
          <div class="mt-1 space-y-1 pl-2 border-l border-gray-800 ml-6">
            <a href="admin_revenue.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Overview</a>
            <a href="admin_revenue.php?view=payments" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Payments</a>
            <a href="admin_revenue.php?view=packages" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Packages</a>
            <a href="admin_revenue.php?view=analytics" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Analytics</a>
            <a href="admin_banners.php" class="block py-2 px-4 text-sm text-gray-400 hover:text-white">Banners</a>
          </div>
        </div>
      </div>

      <a href="admin_messaging_notifications.php" class="<?= navClass($current_page === 'messaging') ?>">
        <span class="flex items-center gap-2.5"><svg class="w-4 h-4 flex-shrink-0" data-lucide="message-square" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-message-square"></use></svg><span class="truncate text-sm">Messages & Notifications</span></span>
      </a>

      <a href="whatsapp.php" class="<?= navClass($current_page === 'whatsapp') ?>">
        <span class="flex items-center gap-2.5"><svg class="w-4 h-4 flex-shrink-0" data-lucide="send" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-send"></use></svg><span class="truncate text-sm">WhatsApp</span></span>
      </a>

      <a href="admin_settings.php" class="<?= navClass($current_page === 'settings') ?>">
        <span class="flex items-center gap-2.5"><svg class="w-4 h-4 flex-shrink-0" data-lucide="settings" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-settings"></use></svg><span class="truncate text-sm">Settings</span></span>
      </a>
      
      <div class="pt-6 border-t border-gray-800 mt-6">
         <a href="../logout.php" class="flex items-center gap-3 text-gray-400 hover:text-white">
            <svg class="w-5 h-5" data-lucide="log-out" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-log-out"></use></svg>
            <span>Logout</span>
         </a>
      </div>
    </nav>
  </div>
</div>

<?php
// Include centralized page loader component
if (!defined('PAGE_LOADER_INCLUDED')) {
  define('PAGE_LOADER_INCLUDED', true);
  include __DIR__ . '/../components/page_loader.php';
}
?>
<script>
(function(){
  
  // 1. Generic Dropdown Toggle
  // This handles ALL dropdowns (Schedule, Academics, Business) automatically
  document.querySelectorAll('[data-toggle="dropdown"]').forEach(btn => {
    btn.addEventListener('click', () => {
      // Find the next sibling which is the menu container
      const menu = btn.nextElementSibling;
      const chevron = btn.querySelector('[data-lucide="chevron-down"]');
      
      // Toggle state
      const isExpanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', !isExpanded);
      
      // Toggle CSS for height animation
      if (menu) {
         if (isExpanded) {
             menu.style.maxHeight = '0px'; // Close
             if(chevron) chevron.classList.remove('rotate-180');
         } else {
             menu.style.maxHeight = '500px'; // Open (arbitrary large height for animation)
             if(chevron) chevron.classList.add('rotate-180');
         }
      }
    });
  });

  // 2. Mobile Drawer Logic
  const drawer = document.getElementById('mobile-nav');
  // Check for existing mobile toggle button, or create one if missing
  let openBtn = document.getElementById('mobile-nav-open');
  
  if (!openBtn) {
    // If your main layout doesn't provide a button, we inject a floating one for safety
    openBtn = document.createElement('button');
    openBtn.id = 'mobile-nav-open';
    openBtn.type = 'button';
    openBtn.className = 'md:hidden fixed top-4 right-4 z-40 inline-flex items-center justify-center w-10 h-10 rounded-full bg-indigo-600 text-white shadow-lg shadow-indigo-500/30 focus:outline-none';
    openBtn.innerHTML = '<svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-menu"></use></svg>';
    document.body.appendChild(openBtn);
  }

  if (drawer && openBtn) {
      const closeEls = document.querySelectorAll('[data-close-nav]');
      const open = () => { drawer.classList.remove('hidden'); document.body.style.overflow = 'hidden'; };
      const close = () => { drawer.classList.add('hidden'); document.body.style.overflow = ''; };

      openBtn.addEventListener('click', open);
      closeEls.forEach(el => el.addEventListener('click', close));
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  }

})();
</script>
