<?php
// students/topic_roadmap.php — improved, student-friendly UI (colorful, now with light + dark theme)
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
  header('Location: ../login.php'); exit();
}
$student_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../config/db_connect.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Subjects this student actually studies (based on lessons)
$subjects = [];
try {
  $q = $pdo->prepare("
    SELECT s.id, s.name
    FROM subjects s
    JOIN lessons l ON l.subject_id = s.id
    WHERE l.student_id = ?
    GROUP BY s.id, s.name
    ORDER BY s.name
  ");
  $q->execute([$student_id]);
  $subjects = $q->fetchAll();
} catch (Throwable $e) {
  $subjects = [];
}

// Build data per subject
$subjectsData = []; // keyed by subject_id
foreach ($subjects as $s) {
  $sid = (int)$s['id'];

  // All topics
  $topicsStmt = $pdo->prepare("SELECT id, name FROM topics WHERE subject_id=? ORDER BY name");
  $topicsStmt->execute([$sid]);
  $topics = $topicsStmt->fetchAll();

  // Covered topics (completed lessons only)
  $coveredStmt = $pdo->prepare("
    SELECT DISTINCT ltc.topic_id
    FROM lesson_topics_covered ltc
    JOIN lessons l ON l.id = ltc.lesson_id
    WHERE l.student_id = ? AND l.subject_id = ? AND l.status = 'Completed'
  ");
  $coveredStmt->execute([$student_id, $sid]);
  $coveredIds = array_map('intval', array_column($coveredStmt->fetchAll(), 'topic_id'));
  $coveredSet = array_flip($coveredIds);

  // Most recent topic covered
  $recent = null;
  $recentStmt = $pdo->prepare("
    SELECT t.id, t.name, l.scheduled_end_time AS ended
    FROM lesson_topics_covered ltc
    JOIN lessons l ON l.id = ltc.lesson_id
    JOIN topics t ON t.id = ltc.topic_id
    WHERE l.student_id = ? AND l.subject_id = ? AND l.status='Completed'
    ORDER BY l.scheduled_end_time DESC
    LIMIT 1
  ");
  $recentStmt->execute([$student_id, $sid]);
  $recent = $recentStmt->fetch() ?: null;

  // Subtopic totals + covered per topic (optional; if you use subtopics)
  $subTotals = [];
  $totStmt = $pdo->prepare("SELECT topic_id, COUNT(*) c FROM subtopics WHERE topic_id IN (SELECT id FROM topics WHERE subject_id=?) GROUP BY topic_id");
  $totStmt->execute([$sid]);
  foreach ($totStmt as $r) $subTotals[(int)$r['topic_id']] = (int)$r['c'];

  // Covered per topic (via lesson_subtopics_covered)
  $subDone = [];
  $doneStmt = $pdo->prepare("
    SELECT st.topic_id, COUNT(DISTINCT lsc.subtopic_id) c
    FROM lesson_subtopics_covered lsc
    JOIN subtopics st ON st.id = lsc.subtopic_id
    JOIN lessons l ON l.id = lsc.lesson_id
    WHERE l.student_id = ? AND l.subject_id = ? AND l.status='Completed'
    GROUP BY st.topic_id
  ");
  $doneStmt->execute([$student_id, $sid]);
  foreach ($doneStmt as $r) $subDone[(int)$r['topic_id']] = (int)$r['c'];

  // Completion
  $totalTopics = count($topics);
  $doneTopics  = count($coveredIds);
  $pct         = $totalTopics ? round($doneTopics / $totalTopics * 100, 1) : 0.0;

  // Next up = first alphabetic pending
  $nextUp = null;
  foreach ($topics as $t) {
    if (!isset($coveredSet[(int)$t['id']])) { $nextUp = $t; break; }
  }

  // Build rows
  $rows = [];
  foreach ($topics as $t) {
    $tid = (int)$t['id'];
    $isDone = isset($coveredSet[$tid]);
    $rows[] = [
      'id' => $tid,
      'name' => $t['name'],
      'status' => $isDone ? 'done' : 'pending',
      'subDone' => $subDone[$tid] ?? 0,
      'subTotal' => $subTotals[$tid] ?? 0,
    ];
  }

  $subjectsData[$sid] = [
    'subject_id' => $sid,
    'subject'    => $s['name'],
    'total'      => $totalTopics,
    'done'       => $doneTopics,
    'pct'        => $pct,
    'recent'     => $recent,
    'next'       => $nextUp,
    'topics'     => $rows,
  ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Student – Topic Roadmap</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">

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
        'page_title' => 'Topic Roadmap',
        'page_subtitle' => 'See progress by subject, pick up where you left off, and find what\'s next.'
    ];
    include __DIR__ . '/components/header.php';
    ?>

    <?php if (empty($subjectsData)): ?>
      <section class="card-ghost rounded-xl p-8">
        <div class="text-lg font-semibold">No topics to show yet</div>
        <p class="text-slate-500 dark:text-slate-400 mt-1">Once your lessons cover topics, your roadmap will appear here.</p>
      </section>
    <?php else: ?>
      <!-- Subject tabs -->
      <div class="flex flex-wrap gap-2 mb-6">
        <?php $firstId = array_key_first($subjectsData); ?>
        <?php foreach ($subjects as $s): $sid=(int)$s['id']; ?>
          <button class="tab <?php echo $sid===$firstId?'active':''; ?>" data-tab-btn data-target="sub-<?php echo $sid; ?>">
            <?php echo h($s['name']); ?>
          </button>
        <?php endforeach; ?>
      </div>

      <?php foreach ($subjects as $s): $sid=(int)$s['id']; $block=$subjectsData[$sid]; ?>
      <section id="sub-<?php echo $sid; ?>" class="<?php echo $sid===$firstId?'':'hidden'; ?>">
        <!-- Top row: KPI cards -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
          <!-- Donut + stats -->
          <div class="card glass rounded-xl p-6">
            <div class="flex items-center justify-between mb-3">
              <div class="font-semibold">Overall Progress</div>
              <div class="text-slate-500 dark:text-slate-400 text-sm"><?php echo (int)$block['done']; ?>/<?php echo (int)$block['total']; ?> topics</div>
            </div>
            <div class="grid grid-cols-5 items-center gap-4">
              <div class="col-span-3 h-36"><canvas id="donut-<?php echo $sid; ?>"></canvas></div>
              <div class="col-span-2">
                <div class="text-4xl font-extrabold"><?php echo number_format((float)$block['pct'],1); ?><span class="text-xl">%</span></div>
                <div class="text-slate-500 dark:text-slate-400 text-sm mt-1">Completed</div>
                <div class="mt-4">
                  <div class="meter"><div style="width: <?php echo (float)$block['pct']; ?>%"></div></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Most recent -->
          <div class="card glass rounded-xl p-6">
            <div class="font-semibold mb-2">Most Recent Topic</div>
            <?php if ($block['recent']): ?>
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium"><?php echo h($block['recent']['name']); ?></div>
                  <div class="text-slate-500 dark:text-slate-400 text-sm">Completed on <?php echo date('M j, Y', strtotime($block['recent']['ended'])); ?></div>
                </div>
                <span class="chip">#<?php echo (int)$block['recent']['id']; ?></span>
              </div>
            <?php else: ?>
              <div class="text-slate-500 dark:text-slate-400 text-sm">No topics completed yet.</div>
            <?php endif; ?>
          </div>

          <!-- Next up -->
          <div class="card glass rounded-xl p-6">
            <div class="font-semibold mb-2">Next Up</div>
            <?php if ($block['next']): ?>
              <div class="flex items-center justify-between">
                <div class="font-medium"><?php echo h($block['next']['name']); ?></div>
                <span class="chip chip-pending">Pending</span>
              </div>
              <p class="text-slate-500 dark:text-slate-400 text-sm mt-2">Start here to keep momentum.</p>
              <!-- Wire this to your “start” flow if you have one -->
              <button class="btn btn-primary mt-4">Review Topic</button>
            <?php else: ?>
              <div class="text-slate-500 dark:text-slate-400">All topics completed in this subject.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Toolbar -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div class="tab-group">
            <button class="tab active" data-filter-btn data-mode="all" data-scope="<?php echo $sid; ?>">
              All
            </button>
            <button class="tab" data-filter-btn data-mode="pending" data-scope="<?php echo $sid; ?>">
              Pending
            </button>
            <button class="tab" data-filter-btn data-mode="done" data-scope="<?php echo $sid; ?>">
              Done
            </button>
          </div>

          <div class="relative">
            <input
              type="text"
               class="search-input"
              placeholder="Search topics…"
              data-search
              scope="<?php echo $sid; ?>"
            >
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none w-5 h-5" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </div>
        </div>

        <!-- Topics grid -->
        <div class="relative">
          <div class="no-results-msg hidden text-center py-8 text-gray-500 dark:text-gray-400">
            No topics found matching your criteria.
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-grid scope="<?php echo $sid; ?>">
          <?php foreach ($block['topics'] as $t): ?>
            <?php
              $status = $t['status']==='done' ? 'done' : 'pending';
              $cls = $status==='done' ? 'topic-done' : 'topic-pending';
            ?>
            <div class="topic <?php echo $cls; ?>" 
                 data-topic 
                 data-status="<?php echo $status; ?>" 
                 data-name="<?php echo strtolower(h($t['name'])); ?>"
                 scope="<?php echo $sid; ?>">
              <div class="flex items-center justify-between">
                <div class="font-medium truncate"><?php echo h($t['name']); ?></div>
                <span class="chip <?php echo $status==='done'?'chip-done':'chip-pending'; ?>">
                  <?php echo $status==='done'?'Done':'Pending'; ?>
                </span>
              </div>
              <?php if (($t['subTotal'] ?? 0) > 0): ?>
                <div class="text-slate-600 dark:text-slate-300 text-sm mt-2">
                  Subtopics: <?php echo (int)$t['subDone']; ?>/<?php echo (int)$t['subTotal']; ?>
                </div>
                <div class="meter mt-2">
                  <div style="width: <?php
                    $pctSub = $t['subTotal'] ? min(100, round($t['subDone'] / $t['subTotal'] * 100)) : 0;
                    echo (int)$pctSub;
                  ?>%"></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

<script>
/* ===== Subject Tabs ===== */
document.querySelectorAll('[data-tab-btn]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const tgt = btn.getAttribute('data-target');
    // toggle buttons
    document.querySelectorAll('[data-tab-btn]').forEach(b=> b.classList.remove('active'));
    btn.classList.add('active');
    // toggle sections
    document.querySelectorAll('section[id^="sub-"]').forEach(sec=>{
      sec.classList.toggle('hidden', sec.id !== tgt);
    });
  });
});

/* ===== Filters & Search (per subject) ===== */
function applyFilters(scope) {
  // Find the active section first
  const activeSection = document.querySelector(`section:not(.hidden) #sub-${scope}`) || 
                       document.querySelector(`#sub-${scope}:not(.hidden)`);
  if (!activeSection) return;

  const activeFilter = activeSection.querySelector(`[data-filter-btn][data-scope="${scope}"].active`);
  const mode = activeFilter ? activeFilter.dataset.mode : 'all';
  const searchInput = activeSection.querySelector(`[data-search][scope="${scope}"]`);
  const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
  const topicGrid = activeSection.querySelector(`[data-grid][scope="${scope}"]`);
  
  if (!topicGrid) return;

  let hasVisibleItems = false;
  const topicItems = topicGrid.querySelectorAll('[data-topic]');
  
  // First, check all items and hide/show them
  topicItems.forEach(card => {
    const status = card.getAttribute('data-status');
    const name = card.getAttribute('data-name') || '';
    const statusOk = (mode === 'all') || (status === mode);
    const textOk = !term || name.includes(term);
    const isVisible = statusOk && textOk;
    
    card.style.display = isVisible ? '' : 'none';
    if (isVisible) hasVisibleItems = true;
  });
  
  // Show/hide no-results message
  const noResultsMsg = topicGrid.parentElement.querySelector('.no-results-msg');
  if (noResultsMsg) {
    noResultsMsg.style.display = hasVisibleItems ? 'none' : 'block';
  }
}

// Initialize filter buttons and search for each subject section
document.querySelectorAll('section[id^="sub-"]').forEach(section => {
  const scope = section.id.replace('sub-', '');
  
  // Set up filter buttons
  section.querySelectorAll('[data-filter-btn]').forEach(btn => {
    // Initial active state is handled by the 'active' class in HTML
    
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      const scope = this.dataset.scope;
      
      // Remove active state from all buttons in this section
      document.querySelectorAll(`[data-filter-btn][data-scope="${scope}"]`).forEach(b => {
        b.classList.remove('active');
      });
      
      // Add active state to clicked button
      this.classList.add('active');
      
      applyFilters(scope);
    });
  });

  // Set up search input
  const searchInput = section.querySelector('[data-search]');
  if (searchInput) {
    let searchTimeout;
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => applyFilters(scope), 200);
    });
  }
});

// Apply initial filters when page loads
document.addEventListener('DOMContentLoaded', () => {
  // Apply filters for each subject section
  document.querySelectorAll('section[id^="sub-"]').forEach(section => {
    const scope = section.id.replace('sub-', '');
    applyFilters(scope);
  });
});

/* ===== Donuts ===== */
const donutData = <?php echo json_encode(array_values($subjectsData), JSON_UNESCAPED_SLASHES); ?>;

donutData.forEach(d => {
  const el = document.getElementById('donut-'+d.subject_id);
  if (!el) return;
  const done = Math.max(0, +d.done);
  const todo = Math.max(0, (+d.total - done));
  // Create container for the donut chart
  const container = document.createElement('div');
  container.className = 'donut-container';
  el.parentNode.insertBefore(container, el);
  
  // Create canvas element for the chart
  const canvas = document.createElement('canvas');
  container.appendChild(canvas);
  
  // Get current theme colors
  const style = getComputedStyle(document.documentElement);
  const completedColor = style.getPropertyValue('--donut-completed').trim() || '#0ea5e9';
  const remainingColor = style.getPropertyValue('--donut-remaining').trim() || '#e5e7eb';
  
  // Initialize the chart with theme-aware colors
  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: ['Completed', 'Remaining'],
      datasets: [{
        data: [done, todo],
        backgroundColor: [
          completedColor,
          remainingColor
        ],
        borderColor: 'transparent',
        borderWidth: 0,
        borderRadius: 6,
        spacing: 4,
        cutout: '75%',
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '75%',
      radius: '90%',
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15, 23, 42, 0.95)',
          titleColor: '#fff',
          bodyColor: '#e5e7eb',
          borderColor: 'rgba(255, 255, 255, 0.08)',
          borderWidth: 1,
          padding: 12,
          boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)'
        }
      },
      animation: {
        animateScale: true,
        animateRotate: true,
        duration: 1000,
        easing: 'easeOutQuart'
      },
      layout: {
        padding: 10
      }
    }
  });
});

// Initialize filters for each subject section
Object.keys(<?php echo json_encode($subjectsData ? array_flip(array_map(fn($s)=>$s['subject_id'], $subjectsData)) : []); ?>).forEach(scope => applyFilters(scope));

// Function to update chart colors when theme changes
window.updateChartsTheme = function() {
  // Get the current theme colors from CSS variables
  const style = getComputedStyle(document.documentElement);
  const completedColor = style.getPropertyValue('--donut-completed').trim();
  const remainingColor = style.getPropertyValue('--donut-remaining').trim();
  
  // Update all charts
  if (typeof Chart !== 'undefined') {
    Chart.helpers.each(Chart.instances, function(chart) {
      if (chart.config.type === 'doughnut') {
        chart.data.datasets[0].backgroundColor = [completedColor, remainingColor];
        chart.update();
      }
    });
  }
};

// Listen for theme changes
const observer = new MutationObserver(function(mutations) {
  mutations.forEach(function(mutation) {
    if (mutation.attributeName === 'class') {
      // Small delay to ensure theme classes are applied
      setTimeout(updateChartsTheme, 10);
    }
  });
});

// Start observing the documentElement (html) for class changes
observer.observe(document.documentElement, {
  attributes: true,
  attributeFilter: ['class']
});

// Also observe the body for theme class changes
observer.observe(document.body, {
  attributes: true,
  attributeFilter: ['class']
});

// Initial theme setup - with a small delay to ensure CSS is loaded
setTimeout(updateChartsTheme, 50);
</script>
</body>
</html>
