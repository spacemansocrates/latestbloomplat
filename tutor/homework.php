<?php
require_once 'auth_tutor_only.php';
$current_page = 'homework';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// 4. DATABASE CONNECTION
require_once __DIR__ . '/../config/db_connect.php';

// Fetch homework assignments created by this tutor with submission details
$stmt = $pdo->prepare("
    SELECT 
        h.id,
        h.title,
        h.description,
        h.due_date,
        h.created_at,
        u.first_name as student_first_name,
        u.last_name as student_last_name,
        u.profile_photo_url,
        hs.id as submission_id,
        hs.status as submission_status,
        hs.submission_date,
        hs.grade,
        hs.tutor_feedback,
        hs.graded_at,
        -- DISTINCT matters: homework_attachments and submission_attachments are two
        -- independent one-to-many joins, so their rows multiply. Three brief files and
        -- two submitted files would report six of each, not three and two.
        COUNT(DISTINCT ha.id) as attachment_count,
        COUNT(DISTINCT sa.id) as submission_attachment_count
    FROM homework h
    JOIN users u ON h.student_id = u.id
    LEFT JOIN homework_submissions hs ON h.id = hs.homework_id
    LEFT JOIN homework_attachments ha ON h.id = ha.homework_id
    LEFT JOIN submission_attachments sa ON hs.id = sa.submission_id
    WHERE h.tutor_id = ?
    GROUP BY h.id, u.id, hs.id
    ORDER BY h.created_at DESC
");
$stmt->execute([$tutor_id]);
$homework_assignments = $stmt->fetchAll();

// Fetch students assigned to this tutor for the create homework modal
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name
    FROM users u
    JOIN tutor_student_assignments tsa ON u.id = tsa.student_id
    WHERE tsa.tutor_id = ? 
    AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE())
    AND u.is_active = 1
    ORDER BY u.first_name, u.last_name
");
$stmt->execute([$tutor_id]);
$assigned_students = $stmt->fetchAll();

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';

// Filter homework based on status
$filtered_homework = [];
foreach ($homework_assignments as $hw) {
    switch ($filter) {
        case 'needs_grading':
            if (in_array($hw['submission_status'], ['Submitted', 'Late'], true)) {
                $filtered_homework[] = $hw;
            }
            break;
        case 'graded':
            if ($hw['submission_status'] === 'Graded') {
                $filtered_homework[] = $hw;
            }
            break;
        default:
            $filtered_homework[] = $hw;
            break;
    }
}

// Helper function to get status display
function getStatusDisplay($hw) {
    // The 400-weight text colours are drawn for a dark row and land around 1.5:1 on
    // a light one, so each pill carries a darker light-mode colour and keeps the
    // original under dark:.
    if (!$hw['submission_id']) {
        return ['text' => 'Not Submitted', 'class' => 'bg-gray-500/10 text-gray-600 dark:text-gray-400'];
    }
    
    switch ($hw['submission_status']) {
        case 'Submitted':
            return ['text' => 'Needs Grading', 'class' => 'bg-yellow-500/10 text-yellow-700 dark:text-yellow-400'];
        case 'Graded':
            return ['text' => 'Graded', 'class' => 'bg-green-500/10 text-green-700 dark:text-green-400'];
        case 'Late':
            return ['text' => 'Late Submission', 'class' => 'bg-red-500/10 text-red-700 dark:text-red-400'];
        default:
            return ['text' => 'Unknown', 'class' => 'bg-gray-500/10 text-gray-600 dark:text-gray-400'];
    }
}

// Helper function to format date
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M j, Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutor Dashboard - Homeworks</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <link rel="stylesheet" href="css/theme.css">
    <!-- Lucide Icons -->
<!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        /* ---- Light-mode colour remap for this dark-first page ----
           This page was written entirely in fixed dark utilities: 25 text-white,
           21 text-gray-400, bg-gray-800 rows and so on, with no dark: variants and
           no light handling at all, so in light mode it rendered dark navy rows
           inside a white card with headers at 2.5:1. Remapped rather than rewritten,
           the same approach the dashboard takes, but keyed off body.theme-light,
           which the theme toggle actually updates. Scoped to main and the two modals
           so the sidebar and header keep their own theming. */
        body.theme-light main .bg-gray-900,
        body.theme-light #createHomeworkModal .bg-gray-900,
        body.theme-light #gradeHomeworkModal .bg-gray-900 { background-color:#ffffff !important; }
        body.theme-light main .bg-gray-800,
        body.theme-light #createHomeworkModal .bg-gray-800,
        body.theme-light #gradeHomeworkModal .bg-gray-800 { background-color:#f8fafc !important; }
        body.theme-light main .bg-gray-700 { background-color:#e2e8f0 !important; }
        body.theme-light main .bg-gray-600 { background-color:#cbd5e1 !important; }
        body.theme-light main .hover\:bg-gray-700\/50:hover { background-color:#eef2f7 !important; }
        body.theme-light main .border-gray-700,
        body.theme-light main .border-gray-600,
        body.theme-light #createHomeworkModal .border-gray-600,
        body.theme-light #gradeHomeworkModal .border-gray-600 { border-color:#e2e8f0 !important; }

        body.theme-light main .text-white,
        body.theme-light #createHomeworkModal .text-white,
        body.theme-light #gradeHomeworkModal .text-white { color:#111827 !important; }
        body.theme-light main .text-gray-300,
        body.theme-light #createHomeworkModal .text-gray-300,
        body.theme-light #gradeHomeworkModal .text-gray-300 { color:#374151 !important; }
        /* gray-400 on white is 2.5:1, so it lands on gray-600 rather than a lighter grey */
        body.theme-light main .text-gray-400,
        body.theme-light #createHomeworkModal .text-gray-400,
        body.theme-light #gradeHomeworkModal .text-gray-400 { color:#4b5563 !important; }
        body.theme-light main .text-gray-500,
        body.theme-light #createHomeworkModal .text-gray-500,
        body.theme-light #gradeHomeworkModal .text-gray-500 { color:#6b7280 !important; }
        /* Accent links are indigo-400, which is 2.9:1 on the light row. */
        body.theme-light main .text-indigo-400,
        body.theme-light #createHomeworkModal .text-indigo-400,
        body.theme-light #gradeHomeworkModal .text-indigo-400 { color:#4338ca !important; }

        /* Coloured buttons keep white text: the remap above would otherwise darken it. */
        body.theme-light .bg-indigo-600.text-white,
        body.theme-light .bg-indigo-500.text-white,
        body.theme-light .bg-green-600.text-white,
        body.theme-light .bg-red-600.text-white { color:#ffffff !important; }
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        .sidebar-icon {
            stroke-width: 1.5;
        }
        .form-input {
            background-color: #374151;
            border: 1px solid #4b5563;
            color: #f3f4f6;
            border-radius: 0.5rem;
            padding: 0.65rem 1rem;
            width: 100%;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 2px #4f46e5;
        }
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

    <!-- Include Sidebar -->
    <?php include 'components/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 lg:p-8">
        <?php 
        $header_title = "Homeworks";
        $header_subtitle = "Create, assign, and review homework for your students.";
        include 'components/header.php'; 
        ?>

        <div class="card-base p-6 rounded-lg">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-2">
                    <a href="?filter=all" class="text-sm font-semibold py-1.5 px-4 rounded-md <?= $filter === 'all' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:text-white' ?>">All</a>
                    <a href="?filter=needs_grading" class="text-sm py-1.5 px-4 rounded-md <?= $filter === 'needs_grading' ? 'bg-gray-700 text-white font-semibold' : 'text-gray-400 hover:text-white' ?>">Needs Grading</a>
                    <a href="?filter=graded" class="text-sm py-1.5 px-4 rounded-md <?= $filter === 'graded' ? 'bg-gray-700 text-white font-semibold' : 'text-gray-400 hover:text-white' ?>">Graded</a>
                </div>
                <div class="relative">
                    <input type="text" id="homework-search" placeholder="Search by title or student..." class="input-field">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
                </div>
            </div>

            <div class="space-y-3" id="homework-list">
                <div class="grid grid-cols-5 gap-4 text-sm font-semibold text-gray-400 px-4 pb-2 border-b border-gray-700 hidden md:grid">
                    <div>Assignment</div>
                    <div class="text-center">Due Date</div>
                    <div class="text-center">Status</div>
                    <div class="text-center">Submissions</div>
                    <div class="text-right">Actions</div>
                </div>
                
                <?php if (empty($filtered_homework)): ?>
                    <div class="text-center py-12">
                        <svg class="mx-auto mb-4 text-gray-500" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-file-text"></use></svg>
                        <h3 class="text-lg font-semibold text-white mb-2">No Homework Assignments</h3>
                        <p class="text-gray-400 mb-4">
                            <?php if ($filter === 'needs_grading'): ?>
                                No assignments need grading at the moment.
                            <?php elseif ($filter === 'graded'): ?>
                                No graded assignments found.
                            <?php else: ?>
                                You haven't created any homework assignments yet.
                            <?php endif; ?>
                        </p>
                        <?php if ($filter === 'all'): ?>
                            <button id="create-first-homework-btn" class="bg-indigo-600 text-white font-semibold py-2.5 px-5 rounded-lg hover:bg-indigo-500 transition-colors">Create Your First Homework</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($filtered_homework as $hw): ?>
                        <?php
                            $initials = strtoupper(substr($hw['student_first_name'], 0, 1) . substr($hw['student_last_name'], 0, 1));
                            $avatar_colors = ['c4b5fd/1e1b4b', 'a5f3fc/1e1b4b', 'f9a8d4/1e1b4b', 'a5b4fc/1e1b4b', 'fcd34d/1e1b4b', '86efac/1e1b4b'];
                            // Keyed off the name so a student keeps the same colour between page loads.
                            $color = $avatar_colors[crc32($hw['student_first_name'] . $hw['student_last_name']) % count($avatar_colors)];
                            $profile_photo = resolve_photo($hw['profile_photo_url'], $hw['student_first_name'].' '.$hw['student_last_name']);
                            $status = getStatusDisplay($hw);
                            $student_name = $hw['student_first_name'] . ' ' . $hw['student_last_name'];
                        ?>
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-center bg-gray-800 p-4 rounded-lg hover:bg-gray-700/50 transition-colors homework-item" 
                             data-title="<?= htmlspecialchars(strtolower($hw['title']), ENT_QUOTES, 'UTF-8') ?>"
                             data-student="<?= htmlspecialchars(strtolower($student_name), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="flex items-center gap-3">
                                <?php // Stored photo paths point at earlier installs and 404. ?>
                                <img src="<?= htmlspecialchars($profile_photo) ?>" alt=""
                                     class="rounded-full flex-shrink-0 w-10 h-10 object-cover"
                                     onerror="this.onerror=null;this.src='../assets/avatar.php?s=40&amp;bg=<?= htmlspecialchars($color) ?>&amp;text=<?= urlencode($initials) ?>'">
                                <div>
                                    <p class="font-semibold text-white"><?= htmlspecialchars($hw['title']) ?></p>
                                    <p class="text-sm text-gray-400"><?= htmlspecialchars($student_name) ?></p>
                                </div>
                            </div>
                            <p class="text-sm text-gray-300 text-left md:text-center">
                                <span class="md:hidden font-semibold text-gray-400">Due: </span>
                                <?= formatDate($hw['due_date']) ?>
                            </p>
                            <div class="flex md:justify-center">
                                <span class="text-xs font-medium py-1 px-3 rounded-full <?= $status['class'] ?>">
                                    <?= $status['text'] ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-300 text-left md:text-center">
                                <span class="md:hidden font-semibold text-gray-400">Submissions: </span>
                                <?= $hw['submission_id'] ? '1 / 1' : '0 / 1' ?>
                            </p>
                            <div class="flex justify-start md:justify-end">
                                <?php if ($hw['submission_id']): ?>
                                    <button class="font-semibold text-indigo-400 hover:text-indigo-300 view-submission-btn" 
                                            data-homework-id="<?= $hw['id'] ?>"
                                            data-submission-id="<?= $hw['submission_id'] ?>">
                                        View Submission
                                    </button>
                                <?php else: ?>
                                    <?php // gray-500 sits at 3:1 on the dark row, so it lifts a step there. ?>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">No submission</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($filtered_homework)): ?>
                <div class="flex items-center justify-between mt-8 pt-4 border-t border-gray-700">
                    <p class="text-sm text-gray-400">Showing <?= count($filtered_homework) ?> results</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal 1: Create New Homework -->
    <div id="createHomeworkModal" class="fixed inset-0 bg-black/70 flex items-center justify-center p-4 z-50 hidden modal-backdrop">
        <div class="bg-gray-900 rounded-xl shadow-xl w-full max-w-2xl p-8 relative">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white modal-close-btn">
                <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <h3 class="text-xl font-bold text-white mb-2">Create New Homework</h3>
            <p class="text-gray-400 mb-6">Fill out the details to assign a new homework.</p>
            <form id="create-homework-form" class="space-y-5" enctype="multipart/form-data">
                <div>
                    <label for="hwTitle" class="block text-sm font-medium text-gray-300 mb-2">Assignment Title</label>
                    <input type="text" id="hwTitle" name="title" placeholder="e.g., Algebra Chapter 5 Problems" class="form-input" required>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="hwStudent" class="block text-sm font-medium text-gray-300 mb-2">Assign to Student</label>
                        <select id="hwStudent" name="student_id" class="form-input" required>
                            <option value="">Select a student...</option>
                            <?php foreach ($assigned_students as $student): ?>
                                <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="hwDueDate" class="block text-sm font-medium text-gray-300 mb-2">Due Date</label>
                        <input type="datetime-local" id="hwDueDate" name="due_date" class="form-input" required>
                    </div>
                </div>
                <div>
                    <label for="hwInstructions" class="block text-sm font-medium text-gray-300 mb-2">Instructions</label>
                    <textarea id="hwInstructions" name="description" rows="4" class="form-input" placeholder="Describe the homework, including any specific requirements..." required></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Attach Files (optional)</label>
                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-600 border-dashed rounded-md">
                        <div class="space-y-1 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-upload"></use></svg>
                            <div class="flex text-sm text-gray-500">
                                <label for="file-upload" class="relative cursor-pointer bg-gray-900 rounded-md font-medium text-indigo-400 hover:text-indigo-300">
                                    <span>Upload files</span>
                                    <input id="file-upload" name="attachments[]" type="file" multiple class="sr-only">
                                </label>
                                <p class="pl-1">or drag and drop</p>
                            </div>
                            <p class="text-xs text-gray-500">PDF, DOCX, PNG, JPG up to 10MB each</p>
                        </div>
                    </div>
                    <div id="file-list" class="mt-2 space-y-1"></div>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" class="py-2.5 px-5 bg-gray-700 text-white font-semibold rounded-lg hover:bg-gray-600 modal-close-btn">Cancel</button>
                    <button type="submit" class="py-2.5 px-5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500">Assign Homework</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal 2: View & Grade Submission -->
    <div id="gradeHomeworkModal" class="fixed inset-0 bg-black/70 flex items-center justify-center p-4 z-50 hidden modal-backdrop">
        <div class="card-base-sub rounded-xl shadow-xl w-full max-w-4xl p-4 md:p-8 relative" role="dialog" aria-modal="true" aria-label="Submission viewer" style="max-height:90vh;overflow-y:auto">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white modal-close-btn">
                <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <p id="submission-load-status" role="status" class="my-4"></p>
            <div id="submission-loaded-content">
            <h3 id="grade-title" class="text-xl font-bold text-white mb-1">Grade: Assignment Title</h3>
            <p id="grade-student" class="text-gray-400 mb-6">Submission by <span class="font-semibold text-white">Student Name</span></p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Left: Submission Details -->
                <div class="md:col-span-2 space-y-6">
                    <div>
                        <h4 class="font-semibold text-white mb-3">Assignment Details</h4>
                        <div id="assignment-description" class="bg-gray-800 p-4 rounded-lg text-gray-300 whitespace-pre-wrap break-words">
                            <!-- Assignment description will be loaded here -->
                        </div>
                    </div>
                    <div id="assignment-files-section" class="hidden">
                        <h4 class="font-semibold text-white mb-3">Assignment Files <span class="text-xs font-normal text-gray-400">(what you attached)</span></h4>
                        <div id="assignment-files">
                            <!-- Tutor's own attached files loaded here -->
                        </div>
                    </div>
                    <div id="submitted-files-section">
                        <h4 class="font-semibold text-white mb-3">Submitted Files</h4>
                        <div id="submitted-files">
                            <!-- Files will be loaded here -->
                        </div>
                    </div>
                    <div id="student-comments-section">
                        <h4 class="font-semibold text-white mb-2">Student's Comments</h4>
                        <div id="student-comments" class="bg-gray-800 p-4 rounded-lg text-gray-300 italic whitespace-pre-wrap break-words">
                            <!-- Student comments will be loaded here -->
                        </div>
                    </div>
                </div>

                <!-- Right: Grading Form -->
                <div class="md:col-span-1 bg-gray-800 p-5 rounded-lg">
                    <form id="grade-submission-form" class="space-y-5">
                        <input type="hidden" id="submission-id" name="submission_id">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <div>
                            <label for="hwGrade" class="block text-sm font-medium text-gray-300 mb-2">Grade</label>
                            <input type="text" id="hwGrade" name="grade" placeholder="e.g., 0, 95%, or 18/20" class="form-input">
                        </div>
                        <div>
                            <label for="hwFeedback" class="block text-sm font-medium text-gray-300 mb-2">Feedback for Student</label>
                            <textarea id="hwFeedback" name="feedback" rows="8" class="form-input" placeholder="Provide constructive feedback..."></textarea>
                        </div>
                    </form>
                </div>
            </div>
            
            </div>
            <div class="flex justify-end gap-3 pt-6 mt-6 border-t border-gray-700">
                <button type="button" class="py-2.5 px-5 bg-gray-700 text-white font-semibold rounded-lg hover:bg-gray-600 modal-close-btn">Cancel</button>
                <button id="save-grade-btn" type="button" class="py-2.5 px-5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500">Save Grade</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const createHomeworkBtn = document.getElementById('create-homework-btn');
            const createFirstHomeworkBtn = document.getElementById('create-first-homework-btn');
            const viewSubmissionBtns = document.querySelectorAll('.view-submission-btn');
            
            const createModal = document.getElementById('createHomeworkModal');
            const gradeModal = document.getElementById('gradeHomeworkModal');
            
            const allModals = document.querySelectorAll('.modal-backdrop');
            const closeModalBtns = document.querySelectorAll('.modal-close-btn');

            // Search functionality
            const searchInput = document.getElementById('homework-search');
            const homeworkItems = document.querySelectorAll('.homework-item');

            const openModal = (modalEl) => modalEl.classList.remove('hidden');
            const closeModal = (modalEl) => { modalEl.classList.add('hidden'); if (modalEl === gradeModal) { loadVersion++; document.getElementById('save-grade-btn').disabled = true; } };
            let loadVersion = 0;
            document.addEventListener('keydown', e => { if (e.key === 'Escape') allModals.forEach(closeModal); });

            // Create homework button handlers
            if (createHomeworkBtn) {
                createHomeworkBtn.addEventListener('click', () => openModal(createModal));
            }
            if (createFirstHomeworkBtn) {
                createFirstHomeworkBtn.addEventListener('click', () => openModal(createModal));
            }

            // View submission buttons
            viewSubmissionBtns.forEach(btn => {
                btn.addEventListener('click', async () => {
                    const homeworkId = btn.dataset.homeworkId;
                    const submissionId = btn.dataset.submissionId;
                    await loadSubmissionDetails(homeworkId, submissionId);
                });
            });

            // Close modal handlers
            closeModalBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal-backdrop');
                    if (modal) closeModal(modal);
                });
            });

            // Click outside modal to close
            allModals.forEach(modal => {
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) closeModal(modal);
                });
            });

            // Search functionality
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const searchTerm = e.target.value.toLowerCase();
                    
                    homeworkItems.forEach(item => {
                        const title = item.dataset.title;
                        const student = item.dataset.student;
                        if (title.includes(searchTerm) || student.includes(searchTerm)) {
                            item.style.display = 'grid';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }

            // File upload handling
            const fileInput = document.getElementById('file-upload');
            const fileList = document.getElementById('file-list');
            
            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    fileList.innerHTML = '';
                    Array.from(e.target.files).forEach(file => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'flex items-center gap-2 text-sm text-gray-300';
                        fileItem.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                        fileList.appendChild(fileItem);
                    });
                });
            }

            // Open immediately with a loader; stale or failed requests never expose the grade form.
            async function loadSubmissionDetails(homeworkId, submissionId) {
                const version = ++loadVersion;
                const loaded = document.getElementById('submission-loaded-content');
                const status = document.getElementById('submission-load-status');
                const save = document.getElementById('save-grade-btn');
                document.getElementById('grade-submission-form').reset();
                document.getElementById('submission-id').value = '';
                loaded.hidden = true;
                save.disabled = true;
                status.textContent = 'Loading submission...';
                openModal(gradeModal);
                try {
                    const response = await fetch(`api/get_submission_details.php?homework_id=${encodeURIComponent(homeworkId)}&submission_id=${encodeURIComponent(submissionId)}`, {credentials:'same-origin'});
                    const data = await response.json();
                    if (version !== loadVersion) return;
                    if (!response.ok || !data.success) throw new Error(data.message || 'Could not load submission.');
                    const {homework, submission} = data;
                    document.getElementById('grade-title').textContent = `Grade: ${homework.title}`;
                    document.getElementById('grade-student').textContent = `Submission by ${submission.student_name} - ${submission.status}`;
                    document.getElementById('assignment-description').textContent = homework.description || 'No instructions provided';
                    document.getElementById('student-comments').textContent = submission.content || 'No text submitted';
                    document.getElementById('submission-id').value = submission.id;
                    document.getElementById('hwGrade').value = submission.grade ?? '';
                    document.getElementById('hwFeedback').value = submission.tutor_feedback ?? '';
                    const renderFiles = (id, files, empty) => {
                        const box = document.getElementById(id);
                        box.replaceChildren();
                        if (!files.length) box.textContent = empty;
                        files.forEach(file => {
                            const link = document.createElement('a');
                            link.href = file.file_url;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            link.className = 'block rounded-lg p-3 mb-2 bg-indigo-500/10 hover:bg-indigo-500/20 break-words';
                            link.textContent = file.file_name + ' - Open file';
                            box.append(link);
                        });
                    };
                    renderFiles('submitted-files', data.attachments || [], 'No files submitted (text-only submission).');
                    renderFiles('assignment-files', data.assignment_files || [], 'No assignment files.');
                    document.getElementById('assignment-files-section').classList.remove('hidden');
                    status.textContent = 'Files open in a new tab. If a file is unavailable, the download page will explain the failure.';
                    loaded.hidden = false;
                    save.disabled = false;
                } catch (e) {
                    if (version !== loadVersion) return;
                    status.textContent = (e instanceof SyntaxError ? 'Invalid server response.' : e.message) + ' Close this window and try again.';
                }
            }
            const selection = new URLSearchParams(location.search);
            if (selection.has('homework_id') || selection.has('submission_id')) {
                loadSubmissionDetails(selection.get('homework_id') || '', selection.get('submission_id') || '');
            }

            // Form submissions
            document.getElementById('create-homework-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const formData = new FormData(e.target);
                
                try {
                    const response = await fetch('api/create_homework.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // The endpoint still saves the homework when a file fails
                        // validation, so say which ones did not attach.
                        alert(result.warning
                            ? 'Homework created.\n\n' + result.warning
                            : 'Homework created successfully!');
                        closeModal(createModal);
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (error) {
                    alert('Error creating homework. Please try again.');
                }
            });

            // Save grade
            document.getElementById('save-grade-btn').addEventListener('click', async () => {
                const save = document.getElementById('save-grade-btn');
                if (save.disabled || !document.getElementById('submission-id').value) return;
                save.disabled = true;
                const formData = new FormData(document.getElementById('grade-submission-form'));
                
                try {
                    const response = await fetch('api/grade_submission.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        alert('Grade saved successfully!');
                        closeModal(gradeModal);
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (error) {
                    alert('Error saving grade. Please try again.');
                } finally { save.disabled = false; }
            });
        });
    </script>
</body>
</html>
