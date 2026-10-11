<?php
// students/homeworks.php

// 1) Session & auth
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}
$current_page = 'homework';

$student_id   = (int)$_SESSION['user_id'];
$student_name = trim(($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? ''));

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';

// 3) Pagination + fetch homework list
$perPage = 9;
$page    = max(1, (int)($_GET['page'] ?? 1));

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM homework WHERE student_id = ?");
    $countStmt->execute([$student_id]);
    $totalRows = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    die('Error counting homework: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

try {
    $stmt = $pdo->prepare("
        SELECT
            h.id,
            h.tutor_id,
            h.student_id,
            h.title,
            h.description,
            h.due_date,
            h.created_at,
            u.first_name AS tutor_first_name,
            u.last_name  AS tutor_last_name,

            -- student's submission (if any)
            hs.id                AS submission_id,
            hs.status            AS submission_status,
            hs.submission_date   AS submission_date,
            hs.grade             AS grade,
            hs.tutor_feedback    AS tutor_feedback,
            hs.graded_at         AS graded_at,
            hs.content           AS submission_content,

            -- counts (for quick badges)
            (SELECT COUNT(*) FROM homework_attachments ha WHERE ha.homework_id = h.id) AS attachment_count,
            (SELECT COUNT(*) FROM submission_attachments sa WHERE sa.submission_id = hs.id) AS submission_attachment_count
        FROM homework h
        JOIN users u ON u.id = h.tutor_id
        LEFT JOIN homework_submissions hs
            ON hs.homework_id = h.id AND hs.student_id = :sid
        WHERE h.student_id = :sid2
        ORDER BY h.due_date IS NULL, h.due_date ASC, h.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':sid', $student_id, PDO::PARAM_INT);
    $stmt->bindValue(':sid2', $student_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    die('Error fetching homework: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// Helpers
function statusBadge($row) {
    $now = new DateTime();
    $due = $row['due_date'] ? new DateTime($row['due_date']) : null;

    if (!empty($row['submission_status']) && $row['submission_status'] === 'Graded') {
        return ['Graded', 'bg-emerald-500/15 text-emerald-300'];
    }
    if (!empty($row['submission_status']) && $row['submission_status'] === 'Submitted') {
        return ['Submitted', 'bg-indigo-500/15 text-indigo-300'];
    }
    if (!empty($row['submission_status']) && $row['submission_status'] === 'Late') {
        return ['Late', 'bg-amber-500/15 text-amber-300'];
    }

    if ($due) {
        if ($due < $now) return ['Overdue', 'bg-rose-500/15 text-rose-300'];
        $daysLeft = (int)$now->diff($due)->format('%r%a');
        if ($daysLeft <= 2) return ['Due Soon', 'bg-yellow-500/15 text-yellow-300'];
        return ['Pending', 'bg-cyan-500/15 text-cyan-300'];
    }
    return ['Pending', 'bg-cyan-500/15 text-cyan-300'];
}

function shortDate($dt) {
    return $dt ? date('M j, Y', strtotime($dt)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Student – Homework</title>
<link rel="icon" type="image/png" href="../favicon.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>

<script src="https://cdn.tailwindcss.com"></script>
<?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
<style>
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

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <?php
      $header_config = [
          'page_title' => 'Homework',
          'page_subtitle' => 'Submit your work, preview and download files, and track grades.'
      ];
      include __DIR__ . '/components/header.php';
    ?>

    <!-- Filter Tabs -->
    <div class="mb-6 flex flex-col sm:flex-row items-center justify-between w-full gap-4">
      <!-- Desktop search (hidden on mobile) -->
      <div class="relative hidden sm:block">
        <input
          id="search"
          type="text"
          placeholder="Search title or tutor…"
          class="search-input"
        >
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" width="18" height="18" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
      </div>
      
        <!-- Desktop tabs -->
        <div class="tab-group hidden sm:flex">
          <button data-tab="all" class="tab tab-btn active">All</button>
          <button data-tab="due" class="tab tab-btn">Due Soon</button>
          <button data-tab="submitted" class="tab tab-btn">Submitted</button>
          <button data-tab="graded" class="tab tab-btn">Graded</button>
        </div>
  
    
      
      <!-- Mobile layout -->
      <div class="w-full flex gap-2 sm:hidden">
        <!-- Search input (takes 80% width on mobile) -->
        <div class="relative flex-1 w-[90%]">
          <input
            id="search-mobile"
            type="text"
            placeholder="Search title or tutor…"
            class="search-input w-full"
          >
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" width="18" height="18" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
        </div>
        
        <!-- Mobile filter button (takes 20% width on mobile) -->
        <div class="relative w-[10%] min-w-[80px]">
            <button id="mobile-filter-button" class="tab w-full h-full flex items-center justify-center">
                <svg class="w-5 h-5" data-lucide="filter" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-filter"></use></svg>
            </button>

           <!-- Filter dropdown panel -->
          <div id="filter-panel" 
              class="filter-panel mt-1 w-[280px] rounded-xl 
                      hidden
                      absolute right-0 z-50">
              <div>
                  <h4 class="text-lg font-semibold p-2">Filter Homeworks</h4>
              </div>
              <div class="p-2 space-y-1 max-h-80 overflow-y-auto">
                  <button class="filter-option w-full text-left px-4 py-2.5 rounded-lg 
                                flex items-center gap-3 group"
                          data-value="all">
                      <span class="icon-container w-6 h-6 rounded-full flex items-center justify-center">
                          <svg class="w-3.5 h-3.5" data-lucide="list" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-list"></use></svg>
                      </span>
                      <span class="text-sm font-medium">All Homeworks</span>
                  </button>
                  <button class="filter-option w-full text-left px-4 py-2.5 rounded-lg 
                                flex items-center gap-3 group"
                          data-value="due">
                      <span class="icon-container w-6 h-6 rounded-full flex items-center justify-center">
                          <svg class="w-3.5 h-3.5 text-amber-500" data-lucide="alert-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-alert-circle"></use></svg>
                      </span>
                      <span class="text-sm font-medium">Due Soon</span>
                  </button>
                  <button class="filter-option w-full text-left px-4 py-2.5 rounded-lg 
                                flex items-center gap-3 group"
                          data-value="submitted">
                      <span class="icon-container w-6 h-6 rounded-full flex items-center justify-center">
                          <svg class="w-3.5 h-3.5 text-emerald-500" data-lucide="check-circle" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-check-circle"></use></svg>
                      </span>
                      <span class="text-sm font-medium">Submitted</span>
                  </button>
                  <button class="filter-option w-full text-left px-4 py-2.5 rounded-lg 
                                flex items-center gap-3 group"
                          data-value="graded">
                      <span class="icon-container w-6 h-6 rounded-full flex items-center justify-center">
                          <svg class="w-3.5 h-3.5 text-blue-500" data-lucide="star" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-star"></use></svg>
                      </span>
                      <span class="text-sm font-medium">Graded</span>
                  </button>
              </div>
          </div>   
            <!-- Keep the original select for form submission but hide it -->
            <select id="mobile-tab" class="hidden">
                <option value="all">All</option>
                <option value="due">Due Soon</option>
                <option value="submitted">Submitted</option>
                <option value="graded">Graded</option>
            </select>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const filterButton = document.getElementById('mobile-filter-button');
                const filterPanel = document.getElementById('filter-panel');
                const filterOptions = document.querySelectorAll('.filter-option');
                const originalSelect = document.getElementById('mobile-tab');
                const filterSelected = document.getElementById('mobile-filter-selected');

                if (filterButton && filterPanel) {
                    // Toggle filter panel
                    const togglePanel = (e) => {
                        e.stopPropagation();
                        filterPanel.classList.toggle('hidden');
                    };

                    filterButton.addEventListener('click', togglePanel);

                    // Close when clicking outside
                    window.addEventListener('click', (e) => {
                        if (!filterPanel.classList.contains('hidden') && 
                            !filterPanel.contains(e.target) && 
                            !filterButton.contains(e.target)) {
                            filterPanel.classList.add('hidden');
                        }
                    });

                    // Close on escape key
                    window.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape' && !filterPanel.classList.contains('hidden')) {
                            filterPanel.classList.add('hidden');
                        }
                    });

                    // Handle filter selection
                    filterOptions.forEach(option => {
                        option.addEventListener('click', function() {
                            const value = this.getAttribute('data-value');
                            const text = this.querySelector('span').textContent;
                            
                            // Update the hidden select
                            if (originalSelect) {
                                originalSelect.value = value;
                                const event = new Event('change');
                                originalSelect.dispatchEvent(event);
                            }
                            
                            // Update the selected filter display
                            if (filterSelected) {
                                filterSelected.textContent = text;
                            }
                            
                            // Close the panel
                            filterPanel.classList.add('hidden');
                            
                            // Update active state
                            filterOptions.forEach(opt => opt.classList.remove('bg-gray-100', 'dark:bg-gray-800'));
                            this.classList.add('bg-gray-100', 'dark:bg-gray-800');
                        });
                    });

                    // Set initial active state
                    if (originalSelect) {
                        const initialValue = originalSelect.value;
                        const initialOption = document.querySelector(`.filter-option[data-value="${initialValue}"]`);
                        if (initialOption) {
                            initialOption.classList.add('bg-gray-100', 'dark:bg-gray-800');
                        }
                    }
                }
                            });
            </script>
        </div>
        </div>
      </div>
      
    
    </div>

    <!-- List -->
    <div id="hw-list" class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-6">
      <?php if (empty($rows)): ?>
        <div class="col-span-full text-center py-16">
          <svg class="w-12 h-12 text-gray-400 dark:text-gray-500 mx-auto mb-3" data-lucide="notebook-pen" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-notebook-pen"></use></svg>
          <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">No homework yet</h3>
          <p class="text-slate-500 dark:text-slate-400">New assignments from your tutor will appear here.</p>
        </div>
      <?php else: ?>
        <?php $nowTs = time(); ?>
        <?php foreach ($rows as $r): ?>
          <?php
            [$statusText, $statusClass] = statusBadge($r);
            $dueLeftBadge = '';
            if ($r['due_date']) {
                $daysLeft = (int)(new DateTime())->diff(new DateTime($r['due_date']))->format('%r%a');
                if ($daysLeft >= 0) {
                    $dueLeftBadge = "<span class=\"chip bg-fuchsia-500/10 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-300 ml-2\">{$daysLeft}d left</span>";
                }
            }
            $tutorName = htmlspecialchars($r['tutor_first_name'] . ' ' . $r['tutor_last_name'], ENT_QUOTES, 'UTF-8');
            $title     = htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8');
            $desc      = htmlspecialchars(mb_strimwidth((string)$r['description'], 0, 120, '…'), ENT_QUOTES, 'UTF-8');
            $dueShort  = shortDate($r['due_date']);
            $attachCnt = (int)$r['attachment_count'];
            $submitted = !empty($r['submission_id']);
            $cardDataAttr = htmlspecialchars(strtolower($r['title'] . ' ' . $r['tutor_first_name'] . ' ' . $r['tutor_last_name']), ENT_QUOTES, 'UTF-8');

            $createdTs = $r['created_at'] ? strtotime($r['created_at']) : false;
            $isNew     = $createdTs && ($nowTs - $createdTs) <= (3 * 24 * 60 * 60);
            $dueTs     = $r['due_date'] ? strtotime($r['due_date']) : false;
            $hoursLeft = $dueTs ? (($dueTs - $nowTs) / 3600) : null;
            $isUrgent  = $hoursLeft !== null && $hoursLeft >= 0 && $hoursLeft <= 48 && !$submitted;
          ?>
          <div
            class="glass rounded-xl p-5 hover:shadow-lg hover:shadow-indigo-200/40 dark:hover:shadow-indigo-900/10 transition"
            data-search="<?= $cardDataAttr ?>"
            data-status="<?= strtolower($statusText) ?>"
          >
            <div class="flex items-start justify-between">
              <div>
                <div class="text-sm text-sky-700 dark:text-sky-300">Tutor: <?= $tutorName ?></div>
                <h3 class="text-xl font-bold text-slate-900 dark:text-white mt-1"><?= $title ?></h3>
                <?php if ($isNew || $isUrgent): ?>
                  <div class="flex items-center gap-2 mt-2">
                    <?php if ($isNew): ?>
                      <span class="chip bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-100 uppercase tracking-wide">New</span>
                    <?php endif; ?>
                    <?php if ($isUrgent): ?>
                      <span class="chip bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-100 uppercase tracking-wide">Urgent</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <span class="chip <?= $statusClass ?>"><?= $statusText ?></span>
            </div>

            <p class="text-slate-600 dark:text-slate-300 mt-3"><?= $desc ?></p>

            <div class="flex items-center justify-between mt-4 text-sm">
              <div class="text-slate-500 dark:text-slate-400">
                <span class="text-slate-700 dark:text-slate-300 font-medium">Due:</span> <?= $dueShort ?> <?= $dueLeftBadge ?>
              </div>
              <div class="text-slate-500 dark:text-slate-400">
                <span class="text-slate-700 dark:text-slate-300 font-medium">Files:</span> <?= $attachCnt ?>
              </div>
            </div>

            <div class="mt-5 flex gap-2 flex-wrap">
              <button
                class="detail-btn bg-indigo-100 hover:bg-indigo-200 text-indigo-800 dark:bg-indigo-600 dark:hover:bg-indigo-500 dark:text-white font-semibold py-2 px-4 rounded-lg text-sm"
                data-homework-id="<?= (int)$r['id'] ?>"
              >View Details</button>

              <?php if (!$submitted || ($submitted && $r['submission_status'] !== 'Graded')): ?>
                <button
                  class="submit-btn bg-emerald-100 hover:bg-emerald-200 text-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:text-white font-semibold py-2 px-4 rounded-lg text-sm"
                  data-homework-id="<?= (int)$r['id'] ?>"
                >Submit / Update</button>
              <?php endif; ?>

              <?php if ($submitted): ?>
                <button
                  class="submission-btn bg-slate-100 hover:bg-slate-200 text-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-white font-semibold py-2 px-4 rounded-lg text-sm"
                  data-homework-id="<?= (int)$r['id'] ?>"
                >My Submission</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <?php
        $range      = 2;
        $startPage  = max(1, $page - $range);
        $endPage    = min($totalPages, $page + $range);
        $showingMin = $totalRows ? ($offset + 1) : 0;
        $showingMax = $offset + count($rows);
      ?>
      <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
        <p class="text-sm text-slate-600 dark:text-slate-400">
          Showing <?= $showingMin ?>–<?= $showingMax ?> of <?= $totalRows ?> assignments
        </p>
        <nav class="flex items-center gap-1 text-sm">
          <?php $prevDisabled = $page <= 1; ?>
          <a
            href="<?= $prevDisabled ? '#' : '?page=' . ($page - 1) ?>"
            class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 <?= $prevDisabled ? 'text-slate-400 cursor-not-allowed' : 'text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>"
            aria-disabled="<?= $prevDisabled ? 'true' : 'false' ?>"
          >
            Prev
          </a>

          <?php if ($startPage > 1): ?>
            <a href="?page=1" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">1</a>
            <?php if ($startPage > 2): ?>
              <span class="px-2 text-slate-400">…</span>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a
              href="?page=<?= $i ?>"
              class="px-3 py-1.5 rounded-lg border <?= $i === $page
                ? 'border-indigo-500 bg-indigo-600 text-white'
                : 'border-slate-300 dark:border-slate-700 text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>"
            >
              <?= $i ?>
            </a>
          <?php endfor; ?>

          <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
              <span class="px-2 text-slate-400">…</span>
            <?php endif; ?>
            <a href="?page=<?= $totalPages ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800"><?= $totalPages ?></a>
          <?php endif; ?>

          <?php $nextDisabled = $page >= $totalPages; ?>
          <a
            href="<?= $nextDisabled ? '#' : '?page=' . ($page + 1) ?>"
            class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 <?= $nextDisabled ? 'text-slate-400 cursor-not-allowed' : 'text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>"
            aria-disabled="<?= $nextDisabled ? 'true' : 'false' ?>"
          >
            Next
          </a>
        </nav>
      </div>
    <?php endif; ?>
  </main>

  <!-- Modal: Details -->
  <div id="detailsModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="bg-slate-900 rounded-xl w-full max-w-3xl p-6 relative">
      <button class="close-modal absolute top-4 right-4 text-slate-400 hover:text-white">
        <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <h3 id="d-title" class="text-2xl font-bold text-white"></h3>
      <p id="d-tutor" class="text-sky-300 mt-1"></p>
      <p id="d-due" class="text-sm text-slate-400 mt-1"></p>

      <div class="mt-5">
        <h4 class="text-white font-semibold">Instructions</h4>
        <div id="d-desc" class="text-slate-300 mt-2 whitespace-pre-line"></div>
      </div>

      <div id="d-teacher-files-wrap" class="mt-5">
        <h4 class="text-white font-semibold">Attachments from Tutor</h4>
        <div id="d-teacher-files" class="mt-2 space-y-2"></div>
      </div>

      <div id="d-submission-wrap" class="mt-6 border-t border-slate-800 pt-5 hidden">
        <h4 class="text-white font-semibold">Your Submission</h4>
        <div id="d-submission-meta" class="text-slate-400 text-sm mt-1"></div>
        <div id="d-student-files" class="mt-2 space-y-2"></div>
        <div id="d-feedback" class="mt-3 text-emerald-300 text-sm"></div>
      </div>
    </div>
  </div>

  <!-- Modal: Submit -->
  <div id="submitModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="bg-slate-900 rounded-xl w-full max-w-2xl p-6 relative">
      <button class="close-modal absolute top-4 right-4 text-slate-400 hover:text-white">
        <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <h3 id="s-title" class="text-2xl font-bold text-white mb-1">Submit Homework</h3>
      <p id="s-meta" class="text-slate-400 text-sm mb-5"></p>

      <form id="submit-form" class="space-y-5" enctype="multipart/form-data">
        <input type="hidden" name="homework_id" id="s-homework-id" />
        <div>
          <label class="block text-sm font-medium text-white mb-2">Message (optional)</label>
          <textarea name="message" id="s-message" rows="4" class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white" placeholder="Add notes for your tutor..."></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-white mb-2">Attach Files</label>
          <div class="mt-1 flex flex-col items-center justify-center px-6 pt-6 pb-6 border-2 border-dashed border-slate-700 rounded-lg bg-slate-800/40">
            <svg class="w-10 h-10 text-slate-400 mb-2" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-upload"></use></svg>
            <label for="s-files" class="cursor-pointer bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Choose files</label>
            <input id="s-files" name="attachments[]" type="file" multiple class="hidden" />
            <p class="text-xs text-slate-400 mt-2">PDF, DOCX, JPG, PNG · up to 10MB each</p>
          </div>
          <div id="s-file-list" class="mt-2 space-y-1 text-sm text-slate-300"></div>
        </div>
        <div id="s-existing-loading" class="text-sm text-slate-400 hidden">Checking for an existing submission...</div>
        <div id="s-existing" class="hidden border-t border-slate-800 pt-4 space-y-3">
          <div class="flex items-center justify-between">
            <h4 class="text-white font-semibold">Existing Submission</h4>
            <span id="s-existing-status" class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300"></span>
          </div>
          <p id="s-existing-feedback" class="text-xs text-slate-400"></p>
          <div id="s-existing-message" class="text-sm text-slate-300 whitespace-pre-line bg-slate-800/40 p-3 rounded-lg hidden"></div>
          <div id="s-existing-files" class="space-y-2"></div>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="close-modal bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg font-semibold">Cancel</button>
          <button type="submit" id="s-submit-btn" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg font-semibold">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: My Submission (quick view) -->
  <div id="submissionModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">
    <div class="bg-slate-900 rounded-xl w-full max-w-2xl p-6 relative">
      <button class="close-modal absolute top-4 right-4 text-slate-400 hover:text-white">
        <svg width="22" height="22" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
      </button>
      <h3 id="m-title" class="text-2xl font-bold text-white mb-1">My Submission</h3>
      <div id="m-meta" class="text-slate-400 text-sm mb-4"></div>
      <div id="m-files" class="space-y-2"></div>
      <div id="m-feedback" class="text-emerald-300 text-sm mt-3"></div>
    </div>
  </div>

<script>
const el  = (sel) => document.querySelector(sel);
const els = (sel) => document.querySelectorAll(sel);

// Robust JSON fetch (handles 401 + non-JSON)
async function apiGetJson(url) {
  const res = await fetch(url, { credentials: 'same-origin' });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch (e) {
    console.error('Non-JSON response from', url, '\n', text);
    throw new Error('Bad JSON from server');
  }
  if (res.status === 401) {
    window.location = '../login.php';
    return;
  }
  if (!res.ok || data.success === false) {
    throw new Error(data.message || `HTTP ${res.status}`);
  }
  return data;
}

// Tabs
let activeTab = 'all';
els('.tab-btn').forEach(b => {
  b.addEventListener('click', (e) => {
    e.preventDefault();
    activeTab = b.dataset.tab;
    
    // Update active state
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.classList.remove('active');
    });
    b.classList.add('active');
    
    // Update mobile select
    const mobileSelect = document.getElementById('mobile-tab');
    if (mobileSelect) {
      mobileSelect.value = activeTab;
    }
    
    filterRender();
  });
});
el('#mobile-tab')?.addEventListener('change', (e)=>{ activeTab = e.target.value; filterRender(); });
els('#search, #search-mobile').forEach(i => i.addEventListener('input', (e) => {
  // keep the desktop/mobile boxes in step so switching breakpoint keeps the term
  els('#search, #search-mobile').forEach(o => { if (o !== e.target) o.value = e.target.value; });
  filterRender();
}));

function filterRender(){
  const term = (el('#search')?.value || '').toLowerCase();
  els('#hw-list > div').forEach(card=>{
    const hay = card.getAttribute('data-search') || '';
    const status = card.getAttribute('data-status') || '';
    const matchTerm = hay.includes(term);
    const matchTab =
      activeTab === 'all' ? true :
      (activeTab === 'due' && (status === 'due soon' || status === 'overdue' || status === 'pending')) ||
      (activeTab === 'submitted' && status === 'submitted') ||
      (activeTab === 'graded' && status === 'graded');
    card.style.display = matchTerm && matchTab ? '' : 'none';
  });
}
filterRender();

const submitActionBtn = el('#s-submit-btn');
const submitBtnDefaultLabel = submitActionBtn ? submitActionBtn.textContent : 'Submit';
const existingWrap = el('#s-existing');
const existingLoading = el('#s-existing-loading');
const existingStatus = el('#s-existing-status');
const existingFeedback = el('#s-existing-feedback');
const existingMessage = el('#s-existing-message');
const existingFiles = el('#s-existing-files');

// =============== Modal helpers ===============
function openModal(id){ const m = el(id); if (!m) return; m.classList.remove('hidden'); m.classList.add('flex'); }
function closeModals(){ els('#detailsModal,#submitModal,#submissionModal').forEach(m=> m.classList.add('hidden')); }
els('.close-modal').forEach(btn=> btn.addEventListener('click', closeModals));
window.addEventListener('click', (e)=> {
  ['detailsModal','submitModal','submissionModal'].forEach(id=>{
    const m = el('#'+id);
    if (e.target === m) m.classList.add('hidden');
  });
});

// =============== Button: View Details ===============
els('.detail-btn').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const homeworkId = btn.dataset.homeworkId;
    try {
      const data = await apiGetJson(`api/get_homework_details.php?homework_id=${encodeURIComponent(homeworkId)}`);

      const hw = data.homework;
      el('#d-title').textContent = hw.title;
      el('#d-tutor').textContent = `Tutor: ${hw.tutor_name}`;
      el('#d-due').textContent   = `Due: ${hw.due_pretty}`;
      el('#d-desc').textContent  = hw.description || '';

      // Tutor files
      const tWrap  = el('#d-teacher-files-wrap');
      const tFiles = el('#d-teacher-files');
      tFiles.innerHTML = '';
      if (Array.isArray(data.attachments) && data.attachments.length){
        data.attachments.forEach(f=>{
          const view = document.createElement('a');
          view.href = f.file_url; view.target = '_blank';
          view.className = 'text-indigo-300 hover:text-indigo-200 underline';
          view.textContent = 'View';

          const dl = document.createElement('a');
          dl.href = `api/download.php?type=homework&id=${encodeURIComponent(f.id)}`;
          dl.className = 'text-emerald-300 hover:text-emerald-200 underline ml-3';
          dl.textContent = 'Download';

          const right = document.createElement('div');
          right.append(view, dl);

          const row = document.createElement('div');
          row.className = 'bg-slate-800 hover:bg-slate-700 p-3 rounded-lg flex items-center justify-between';
          row.innerHTML = `<div>
              <div class="text-white font-medium">${f.file_name}</div>
              <div class="text-slate-400 text-xs">${f.uploaded_at || ''}</div>
            </div>`;
          row.appendChild(right);
          tFiles.appendChild(row);
        });
        tWrap.classList.remove('hidden');
      } else {
        tWrap.classList.add('hidden');
      }

      // Student submission
      const sWrap = el('#d-submission-wrap');
      const sMeta = el('#d-submission-meta');
      const sFiles= el('#d-student-files');
      const sFeed = el('#d-feedback');

      if (data.submission) {
        sWrap.classList.remove('hidden');
        sMeta.textContent = `Submitted: ${data.submission.submitted_pretty} · Status: ${data.submission.status}${data.submission.grade ? ' · Grade: '+data.submission.grade : ''}`;
        sFiles.innerHTML = '';

        if (Array.isArray(data.submission.files) && data.submission.files.length) {
          data.submission.files.forEach(f=>{
            const view = document.createElement('a');
            view.href = f.file_url; view.target = '_blank';
            view.className = 'text-indigo-300 hover:text-indigo-200 underline';
            view.textContent = 'View';

            const dl = document.createElement('a');
            dl.href = `api/download.php?type=submission&id=${encodeURIComponent(f.id)}`;
            dl.className = 'text-emerald-300 hover:text-emerald-200 underline ml-3';
            dl.textContent = 'Download';

            const right = document.createElement('div');
            right.append(view, dl);

            const row = document.createElement('div');
            row.className = 'bg-slate-800 hover:bg-slate-700 p-3 rounded-lg flex items-center justify-between';
            row.innerHTML = `<div>
                <div class="text-white font-medium">${f.file_name}</div>
                <div class="text-slate-400 text-xs">${f.uploaded_at || ''}</div>
              </div>`;
            row.appendChild(right);
            sFiles.appendChild(row);
          });
        }
        sFeed.textContent = data.submission.tutor_feedback ? `Tutor feedback: ${data.submission.tutor_feedback}` : '';
      } else {
        sWrap.classList.add('hidden');
      }

      openModal('#detailsModal');
    } catch (err) {
      console.error(err);
      alert('Could not load details. See console for more info.');
    }
  });
});

// =============== Button: Submit / Update ===============
els('.submit-btn').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const card  = btn.closest('[data-search]');
    const title = card.querySelector('h3').textContent.trim();
    const due   = card.querySelector('.text-slate-500, .text-slate-400')?.textContent || '';
    el('#s-title').textContent = 'Submit Homework';
    el('#s-meta').textContent  = `${title} · ${due}`;
    el('#s-homework-id').value = btn.dataset.homeworkId;
    el('#s-message').value = '';
    el('#s-file-list').innerHTML = '';
    el('#s-files').value = '';

    if (existingWrap) existingWrap.classList.add('hidden');
    if (existingFiles) existingFiles.innerHTML = '';
    if (existingMessage) { existingMessage.textContent = ''; existingMessage.classList.add('hidden'); }
    if (existingFeedback) existingFeedback.textContent = '';
    if (existingStatus) existingStatus.textContent = '';
    if (existingLoading) existingLoading.classList.remove('hidden');

    if (submitActionBtn) {
      submitActionBtn.disabled = false;
      submitActionBtn.textContent = submitBtnDefaultLabel;
      submitActionBtn.classList.remove('opacity-50','cursor-not-allowed');
    }

    openModal('#submitModal');

    try {
      const data = await apiGetJson(`api/get_homework_details.php?homework_id=${encodeURIComponent(btn.dataset.homeworkId)}`);
      if (existingLoading) existingLoading.classList.add('hidden');
      if (data && data.submission) {
        const submission = data.submission;
        if (existingWrap) existingWrap.classList.remove('hidden');
        if (existingStatus) existingStatus.textContent = `${submission.status}${submission.grade ? ' · Grade: ' + submission.grade : ''}`;
        if (existingFeedback) existingFeedback.textContent = submission.submitted_pretty ? `Last submitted ${submission.submitted_pretty}` : '';
        if (submission.content) {
          if (existingMessage) {
            existingMessage.textContent = submission.content;
            existingMessage.classList.remove('hidden');
          }
          el('#s-message').value = submission.content;
        } else if (existingMessage) {
          existingMessage.classList.add('hidden');
        }
        if (existingFiles) {
          existingFiles.innerHTML = '';
          (submission.files || []).forEach(f=>{
            const row = document.createElement('div');
            row.className = 'bg-slate-800 hover:bg-slate-700 p-3 rounded-lg flex items-center justify-between';
            const infoWrap = document.createElement('div');
            const title = document.createElement('div');
            title.className = 'text-white font-medium';
            title.textContent = f.file_name;
            infoWrap.appendChild(title);
            row.appendChild(infoWrap);
            const actions = document.createElement('div');
            const view = document.createElement('a');
            view.href = f.file_url; view.target = '_blank';
            view.className = 'text-indigo-300 hover:text-indigo-200 underline';
            view.textContent = 'View';
            const dl = document.createElement('a');
            dl.href = `api/download.php?type=submission&id=${encodeURIComponent(f.id)}`;
            dl.className = 'text-emerald-300 hover:text-emerald-200 underline ml-3';
            dl.textContent = 'Download';
            actions.append(view, dl);
            row.appendChild(actions);
            existingFiles.appendChild(row);
          });
        }
        if (submission.status === 'Graded' && submitActionBtn) {
          submitActionBtn.disabled = true;
          submitActionBtn.textContent = 'Already graded';
          submitActionBtn.classList.add('opacity-50','cursor-not-allowed');
        }
      } else if (existingWrap) {
        existingWrap.classList.add('hidden');
      }
    } catch (err) {
      console.error(err);
      if (existingLoading) existingLoading.classList.add('hidden');
    }
  });
});

// =============== Button: My Submission ===============
els('.submission-btn').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const homeworkId = btn.dataset.homeworkId;
    try {
      const data = await apiGetJson(`api/get_homework_details.php?homework_id=${encodeURIComponent(homeworkId)}`);
      if (!data.submission) { alert('No submission found.'); return; }

      el('#m-title').textContent = data.homework.title;
      el('#m-meta').textContent  = `Submitted: ${data.submission.submitted_pretty} · Status: ${data.submission.status}${data.submission.grade ? ' · Grade: '+data.submission.grade : ''}`;
      const cont = el('#m-files');
      cont.innerHTML = '';

      (data.submission.files || []).forEach(f=>{
        const view = document.createElement('a');
        view.href = f.file_url; view.target = '_blank';
        view.className = 'text-indigo-300 hover:text-indigo-200 underline';
        view.textContent = 'View';

        const dl = document.createElement('a');
        dl.href = `api/download.php?type=submission&id=${encodeURIComponent(f.id)}`;
        dl.className = 'text-emerald-300 hover:text-emerald-200 underline ml-3';
        dl.textContent = 'Download';

        const right = document.createElement('div');
        right.append(view, dl);

        const row = document.createElement('div');
        row.className = 'bg-slate-800 hover:bg-slate-700 p-3 rounded-lg flex items-center justify-between';
        row.innerHTML = `<div class="text-white font-medium">${f.file_name}</div>`;
        row.appendChild(right);

        cont.appendChild(row);
      });

      el('#m-feedback').textContent = data.submission.tutor_feedback ? `Tutor feedback: ${data.submission.tutor_feedback}` : '';
      openModal('#submissionModal');
    } catch (err) {
      console.error(err);
      alert('Could not load details. See console for more info.');
    }
  });
});

// Deep link: dashboard links here as homeworks.php?submit=<id> to open that
// homework's submit modal straight away.
(() => {
  const wanted = new URLSearchParams(location.search).get('submit');
  if (!wanted) return;
  const btn = document.querySelector(`.submit-btn[data-homework-id="${CSS.escape(wanted)}"]`);
  if (btn) btn.click();
})();

// File chooser preview
el('#s-files')?.addEventListener('change', e=>{
  const list = el('#s-file-list');
  list.innerHTML = '';
  for (const f of e.target.files) {
    const row = document.createElement('div');
    row.textContent = `${f.name} (${(f.size/1024/1024).toFixed(2)} MB)`;
    list.appendChild(row);
  }
});

// Submit form
el('#submit-form')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const res = await fetch('api/submit_homeworks.php', { method:'POST', body: fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Submit failed'); return; }
    alert('Submission saved!');
    closeModals();
    location.reload();
  } catch(err) {
    alert('Network error while submitting.');
  }
});

/* ===== Sync theme with dashboard preference (same as other pages) ===== */
(function(){
  // Default to dark for students
  const saved = localStorage.getItem('theme') || 'dark';
  const isDark = saved === 'dark';
  document.documentElement.classList.toggle('dark', isDark);
  document.body.classList.toggle('theme-light', !isDark);
})();
</script>
</body>
</html>
