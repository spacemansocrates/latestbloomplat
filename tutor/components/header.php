<?php
// tutor/components/header.php
// Reusable header component for all tutor pages

require_once __DIR__ . '/../../utils/tutor_earnings.php';
$headerEarnings = tutor_lifetime_earnings($pdo ?? null, (int)($_SESSION['user_id'] ?? 0));
$headerEarnedText = $headerEarnings === null ? 'Unavailable' : $headerEarnings['currency'] . ' ' . number_format($headerEarnings['amount'], 2);

// Default configuration & backward compatibility
$header_config = $header_config ?? [];
$page_title = $header_title ?? ($header_config['page_title'] ?? 'Dashboard');
$page_subtitle = $header_subtitle ?? ($header_config['page_subtitle'] ?? '');

// Greeting config (optional)
$show_greeting = $header_config['show_greeting'] ?? false;
$greeting_text = $header_config['greeting_text'] ?? '';
$greeting_name = $header_config['greeting_name'] ?? '';
$date_text = $header_config['date_text'] ?? '';

// Fetch notifications if not already loaded
if (!isset($notifications) || !isset($notification_unread_count)) {
    $notifications = [];
    $notification_unread_count = 0;
    $current_user_id = $_SESSION['user_id'] ?? null;
    
    if (isset($pdo) && $current_user_id) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM notifications 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$current_user_id]);
            $notification_unread_count = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT id, message, created_at, is_read
                FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$current_user_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Silent fail
        }
    }
}
?>

<header class="mb-8 relative">
    <!-- Mobile: Top row with menu and icons -->
    <div class="flex md:hidden items-center justify-between mb-4">
        <!-- Mobile menu button - Left -->
        <button id="mobile-nav-open" type="button" class="ui-button">
            <svg class="w-5 h-5" data-lucide="menu" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-menu"></use></svg>
        </button>

        <!-- Mobile icons - Right -->
        <div class="flex items-center gap-2">
            <a href="settings.php#earnings" class="header-earnings rounded-lg px-3 py-2 text-right text-slate-800 dark:text-slate-100 bg-indigo-500/10 hover:bg-indigo-500/20" aria-label="Total earned: <?= htmlspecialchars($headerEarnedText, ENT_QUOTES, 'UTF-8') ?>">
                <span class="block text-xs text-slate-600 dark:text-slate-300">Total earned</span>
                <span class="block text-sm font-semibold break-words"><?= htmlspecialchars($headerEarnedText, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <!-- Theme toggle icon -->
            <button id="theme-toggle-mobile" type="button" class="ui-button theme-toggle" aria-label="Toggle dark mode">
                <svg class="w-5 h-5" data-lucide="moon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-moon"></use></svg>
            </button>

            <!-- Notifications icon -->
            <div class="relative">
                <button id="notif-btn-mobile" class="ui-button">
                    <svg class="w-5 h-5" data-lucide="bell" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-bell"></use></svg>
                    <?php if ($notification_unread_count > 0): ?>
                        <span class="ui-notification-badge">
                            <?= $notification_unread_count > 99 ? '99+' : (int)$notification_unread_count ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile: Page title row -->
    <div class="md:hidden mb-4">
        <?php if ($show_greeting && $greeting_text && $greeting_name): ?>
            <h1 class="text-2xl font-extrabold leading-tight 
                       bg-gradient-to-r from-indigo-600 via-sky-500 to-fuchsia-600
                       dark:from-indigo-300 dark:via-sky-300 dark:to-fuchsia-300
                       bg-clip-text text-transparent">
                <?= htmlspecialchars($greeting_text) ?>, <?= htmlspecialchars($greeting_name) ?> 👋
            </h1>
            <?php if ($date_text): ?>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3 h-3" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                        <?= htmlspecialchars($date_text) ?>
                    </span>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <h1 class="page-title text-2xl font-bold tracking-tight">
                <?= htmlspecialchars($page_title) ?>
            </h1>
            <?php if ($page_subtitle): ?>
                <p class="text-gray-600 dark:text-gray-400 mt-1 text-sm">
                    <?= htmlspecialchars($page_subtitle) ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Desktop: Single row layout -->
    <div class="hidden md:flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div>
                <?php if ($show_greeting && $greeting_text && $greeting_name): ?>
                    <!-- Dashboard greeting style -->
                    <h1 class="text-3xl md:text-4xl font-extrabold leading-tight 
                               bg-gradient-to-r from-indigo-600 via-sky-500 to-fuchsia-600
                               dark:from-indigo-300 dark:via-sky-300 dark:to-fuchsia-300
                               bg-clip-text text-transparent">
                        <?= htmlspecialchars($greeting_text) ?>, <?= htmlspecialchars($greeting_name) ?>
                    </h1>
                    
                    <?php if ($date_text): ?>
                        <p class="mt-1 text-sm md:text-base text-gray-600 dark:text-gray-400 flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center gap-1">
                                <svg class="w-4 h-4" data-lucide="calendar" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-calendar"></use></svg>
                                <?= htmlspecialchars($date_text) ?>
                            </span>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Standard page title style -->
                    <h1 class="page-title text-3xl font-bold tracking-tight">
                        <?= htmlspecialchars($page_title) ?>
                    </h1>
                    <?php if ($page_subtitle): ?>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                            <?= htmlspecialchars($page_subtitle) ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center sm:gap-4">
            <a href="settings.php#earnings" class="header-earnings rounded-lg px-3 py-2 text-right text-slate-800 dark:text-slate-100 bg-indigo-500/10 hover:bg-indigo-500/20" aria-label="Total earned: <?= htmlspecialchars($headerEarnedText, ENT_QUOTES, 'UTF-8') ?>">
                <span class="block text-xs text-slate-600 dark:text-slate-300">Total earned</span>
                <span class="block text-sm font-semibold break-words"><?= htmlspecialchars($headerEarnedText, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <!-- Theme toggle -->
            <button id="theme-toggle" type="button" 
                    class="ui-button theme-toggle"
                    aria-label="Toggle dark mode">
                <svg class="w-5 h-5" data-lucide="moon" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-moon"></use></svg>
            </button>

            <!-- Notifications -->
            <div class="relative">
                <button id="notif-btn" 
                        class="ui-button">
                    <svg class="w-5 h-5" data-lucide="bell" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-bell"></use></svg>
                    <?php if ($notification_unread_count > 0): ?>
                        <span class="ui-notification-badge">
                            <?= $notification_unread_count > 99 ? '99+' : (int)$notification_unread_count ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Notification Panel (Shared) -->
            <div id="notif-panel" 
                    class="notif-panel mt-2 w-[400px] rounded-xl 
                        absolute right-0 top-full z-50
                        shadow-xl dark:shadow-2xl
                        hidden
                        bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800
                        animate-in fade-in slide-in-from-top-2 duration-200">
                <div class="p-4 border-b border-gray-200 dark:border-gray-800">
                    <h4 class="notif-title font-semibold text-[#1C1E4F] dark:text-white">Notifications</h4>
                </div>
                <div class="p-2 space-y-1 max-h-80 overflow-y-auto">
                    <?php if (empty($notifications)): ?>
                        <p class="notif-empty text-center p-4 text-gray-500 dark:text-gray-400">No new notifications.</p>
                    <?php else: foreach ($notifications as $n): ?>
                        <div class="notif-row flex items-start gap-3 p-3 rounded-lg 
                                    hover:bg-gray-50 dark:hover:bg-gray-800 
                                    transition-colors duration-150 cursor-pointer">
                            <div class="notif-icon-wrap w-8 h-8 flex items-center justify-center rounded-full 
                                        bg-indigo-50 dark:bg-indigo-500/10 
                                        text-indigo-600 dark:text-indigo-400">
                                <svg width="18" height="18" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file-text"></use></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="notif-message text-sm text-slate-700 dark:text-gray-300 line-clamp-2">
                                    <?= htmlspecialchars($n['message']) ?>
                                </p>
                                <p class="notif-meta text-xs mt-1 text-gray-500 dark:text-gray-400">
                                    <?= (new DateTime($n['created_at']))->format('M j, g:i a') ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="notif-footer p-3 border-t border-gray-200 dark:border-gray-800 text-center">
                    <a href="notifications.php" 
                        class="notif-viewall text-sm font-semibold text-indigo-600 dark:text-indigo-400 
                                hover:text-indigo-700 dark:hover:text-indigo-300 
                                transition-colors">
                        View All
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    
// Notifications dropdown (if not already initialized)
if (!window.notifInitialized) {
    window.notifInitialized = true;
    (function(){
        const btn = document.getElementById('notif-btn');
        const btnMobile = document.getElementById('notif-btn-mobile');
        const panel = document.getElementById('notif-panel');
        
        if (panel) {
            const togglePanel = (e) => {
                e.stopPropagation();
                panel.classList.toggle('hidden');
            };
            
            if (btn) btn.addEventListener('click', togglePanel);
            if (btnMobile) btnMobile.addEventListener('click', togglePanel);
            
            window.addEventListener('click', (e) => {
                if (!panel.classList.contains('hidden') && 
                    !panel.contains(e.target) && 
                    !btn?.contains(e.target) && 
                    !btnMobile?.contains(e.target)) {
                    panel.classList.add('hidden');
                }
            });
            
            // Close on escape key
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
                    panel.classList.add('hidden');
                }
            });
        }
    })();
}

// Theme toggle (if not already initialized)
if (!window.themeInitialized) {
    window.themeInitialized = true;
    (function(){
        const body = document.body;
        const btn = document.getElementById('theme-toggle');
        const btnMobile = document.getElementById('theme-toggle-mobile');
        
        const updateIcons = (mode) => {
            const iconName = mode === 'dark' ? 'sun' : 'moon';
            
            // Update desktop button
            if (btn) {
                const icon = btn.querySelector('i') || btn.querySelector('svg');
                const labelSpan = btn.querySelector('span');
                
                if (icon) {
                    icon.outerHTML = '<svg class="w-5 h-5" data-lucide="' + iconName + '" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-' + iconName + '"></use></svg>';
                }
            }
            
            // Update mobile button
            if (btnMobile) {
                const iconMobile = btnMobile.querySelector('i') || btnMobile.querySelector('svg');
                if (iconMobile) {
                    iconMobile.outerHTML = '<svg class="w-5 h-5" data-lucide="' + iconName + '" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-' + iconName + '"></use></svg>';
                }
            }
            
            // Re-render icons
            };

        const isDark = () => !body.classList.contains('theme-light');
        const setMode = (mode) => {
            const isDark = mode === 'dark';
            document.documentElement.classList.toggle('dark', isDark);
            // ui_head_boot.php stamps data-theme on <html> at load, and pages key
            // light-mode colour remaps off it. The toggle never updated it, so those
            // remaps stayed stuck at whatever the page loaded as: switching the
            // dashboard to dark left its !important light colours in force.
            document.documentElement.setAttribute('data-theme', mode);
            body.classList.toggle('theme-light', !isDark);
            
            localStorage.setItem('theme', mode);
            updateIcons(mode);
            
            // Update common charts if they exist
            if (window.earningsChart) {
                const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';
                const tickColor = isDark ? '#9ca3af' : '#4b5563';
                
                if (window.earningsChart.options?.scales?.x) {
                    window.earningsChart.options.scales.x.grid.color = gridColor;
                    window.earningsChart.options.scales.x.ticks.color = tickColor;
                }
                if (window.earningsChart.options?.scales?.y) {
                    window.earningsChart.options.scales.y.grid.color = gridColor;
                    window.earningsChart.options.scales.y.ticks.color = tickColor;
                }
                window.earningsChart.update();
            }
        };

        // Initialize with saved theme
        const saved = localStorage.getItem('theme') || 'dark';
        
        // Ensure initial sync
        if (saved === 'dark') {
            document.documentElement.classList.add('dark');
            body.classList.remove('theme-light');
        } else {
            document.documentElement.classList.remove('dark');
            body.classList.add('theme-light');
        }
        updateIcons(saved);

        const toggleTheme = () => {
            setMode(isDark() ? 'light' : 'dark');
        };
        
        if (btn) btn.addEventListener('click', toggleTheme);
        if (btnMobile) btnMobile.addEventListener('click', toggleTheme);
    })();
}

// Initialize all Lucide icons after everything is set up
}); // End DOMContentLoaded
</script>
