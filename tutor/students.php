<?php
require_once 'auth_tutor_only.php';
require_once __DIR__ . '/../config/db_connect.php';
$current_page = 'students';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error: Could not connect to the database. " . $e->getMessage());
}

// Fetch students assigned to this tutor with lesson statistics
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.profile_photo_url,
        tsa.start_date,
        COUNT(CASE WHEN l.status = 'Completed' THEN 1 END) as total_lessons,
        -- A lesson someone has already opened is InProgress, not Scheduled, and it
        -- has still not happened, so it belongs in this count. Measured against the
        -- end time so a lesson running right now is not dropped mid-way through.
        COUNT(CASE WHEN l.status IN ('Scheduled','InProgress')
                    AND l.scheduled_end_time > NOW() THEN 1 END) as upcoming_lessons,
        GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as subjects
    FROM users u
    JOIN tutor_student_assignments tsa ON u.id = tsa.student_id
    LEFT JOIN lessons l ON u.id = l.student_id AND l.tutor_id = ?
    LEFT JOIN subjects s ON l.subject_id = s.id
    WHERE tsa.tutor_id = ? 
    AND (tsa.end_date IS NULL OR tsa.end_date >= CURDATE())
    AND u.is_active = 1
    GROUP BY u.id, u.first_name, u.last_name, u.profile_photo_url, tsa.start_date
    ORDER BY u.first_name, u.last_name
");
$stmt->execute([$tutor_id, $tutor_id]);
$students = $stmt->fetchAll();

// There is deliberately no "students I could add" query here. Tutors cannot assign
// students to themselves (tutor/api/assign_student.php refuses with a 403; only an
// admin can, through admin_users.php). The query that used to sit here fetched every
// active student the tutor was NOT assigned to and rendered their names and email
// addresses into a select, so a tutor with one student was handed a roster of the
// other thirty-three. Do not reinstate it.

// Fetch subjects that the tutor teaches
$stmt = $pdo->prepare("
    SELECT s.id, s.name 
    FROM subjects s
    JOIN tutor_subjects ts ON s.id = ts.subject_id
    WHERE ts.tutor_id = ?
    ORDER BY s.name
");
$stmt->execute([$tutor_id]);
$tutor_subjects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutor Dashboard - Students</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
    <link rel="stylesheet" href="css/theme.css">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #1f2937; }
        ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        .sidebar-icon { stroke-width: 1.5; }
        .form-input {
            background-color: #374151; border: 1px solid #4b5563; color: #f3f4f6; border-radius: 0.5rem;
            padding: 0.65rem 1rem; width: 100%; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px #4f46e5; }
    </style>
</head>
<body class="flex min-h-screen">

    <!-- Include Sidebar -->
    <?php include 'components/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 lg:p-8">
        <?php 
        $header_title = "My Students";
        $header_subtitle = "Manage your student profiles and track their progress.";
        include 'components/header.php'; 
        ?>
        
        <?php if (count($students) > 3): ?>   <!-- a search box over three cards is silly -->
          <!-- The filter behind this input was already written and running; only the
               input itself had gone missing, so a tutor with two dozen students had
               no way to reach it. -->
          <div class="mb-6 max-w-sm">
            <div class="relative">
              <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" width="18" height="18" aria-hidden="true">
                <use href="../assets/icons/sprite.svg#i-search"></use>
              </svg>
              <label for="student-search" class="sr-only">Search your students</label>
              <input id="student-search" type="search" autocomplete="off"
                     class="form-input" style="padding-left:2.5rem"
                     placeholder="Search your students by name">
            </div>
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6" id="students-grid">
            <?php foreach ($students as $i => $student): ?>
                <?php
                    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                    $avatar_colors = ['c4b5fd/1e1b4b', 'a5f3fc/1e1b4b', 'f9a8d4/1e1b4b', 'a5b4fc/1e1b4b', 'fcd34d/1e1b4b', '86efac/1e1b4b'];
                    // Keyed off the name so a student keeps the same colour between page loads.
                    $color = $avatar_colors[crc32($student['first_name'] . $student['last_name']) % count($avatar_colors)];
                    $profile_photo = resolve_photo($student['profile_photo_url'], $student['first_name'].' '.$student['last_name']);
                    $subjects_display = $student['subjects'] ?: 'No subjects yet';
                ?>
                <div class="card-base rounded-lg p-6 text-center flex flex-col student-card card fade-in-up stagger" style="--stagger: <?= $i ?>" data-student-name="<?= htmlspecialchars(strtolower($student['first_name'] . ' ' . $student['last_name']), ENT_QUOTES, 'UTF-8') ?>">
                    <?php // Some stored photo paths still point at the old /tutsys2/ install and
                          // 404, so fall back to the initials avatar rather than a broken image. ?>
                    <img src="<?= htmlspecialchars($profile_photo) ?>" loading="lazy" alt=""
                         class="rounded-full mx-auto w-20 h-20 object-cover"
                         onerror="this.onerror=null; this.src='../assets/avatar.php?s=80&amp;bg=<?= htmlspecialchars($color) ?>&amp;text=<?= urlencode($initials) ?>';">
                    <h3 class="mt-4 text-lg font-semibold"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h3>
                    <p class="text-sm text-indigo-400"><?= htmlspecialchars($subjects_display) ?></p>
                    <div class="mt-4 flex justify-around border-t border-b border-gray-700 py-3">
                        <div>
                            <p class="text-xl font-bold"><?= $student['total_lessons'] ?></p>
                            <p class="text-xs text-gray-400">Total Lessons</p>
                        </div>
                        <div>
                            <p class="text-xl font-bold"><?= $student['upcoming_lessons'] ?></p>
                            <p class="text-xs text-gray-400">Upcoming</p>
                        </div>
                    </div>
                    <div class="mt-auto pt-4 flex gap-3">
                        <button class="w-full bg-gray-700 text-white font-semibold py-2 rounded-lg hover:bg-gray-600 transition-colors message-btn" data-student-id="<?= $student['id'] ?>" data-student-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>">Message</button>
                        <button class="w-full bg-gray-800 border border-gray-700 text-white font-semibold py-2 rounded-lg hover:bg-gray-700 transition-colors view-profile-btn" data-student-id="<?= $student['id'] ?>">Profile</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="search-empty" class="hidden text-center py-12 text-gray-400">
            <svg class="mx-auto mb-3 text-gray-500" width="40" height="40" aria-hidden="true">
              <use href="../assets/icons/sprite.svg#i-search"></use>
            </svg>
            <p>No student of yours matches that.</p>
        </div>

        <?php if (empty($students)): ?>
            <div class="text-center py-12">
                <svg class="mx-auto mb-4 text-gray-500" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
                <h3 class="text-lg font-semibold text-white mb-2">No Students Assigned</h3>
                <p class="text-gray-400 mb-4">You don't have any students assigned to you yet. An administrator assigns students to tutors &mdash; please contact your admin to be assigned.</p>
            </div>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/components/student_profile.php'; ?>

    <!-- Modal 3: Send Message -->
    <div id="sendMessageModal" class="fixed inset-0 bg-black/70 flex items-center justify-center p-4 z-50 hidden modal-backdrop" aria-hidden="true">
        <div class="card-base-sub rounded-xl shadow-xl w-full max-w-lg p-8 relative modal-panel" role="dialog" aria-modal="true">
            <button class="absolute top-4 right-4 text-gray-400 hover:text-white modal-close-btn" aria-label="Close">
                <svg width="24" height="24" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
            </button>
            <h3 class="text-xl font-bold mb-2">Send Message</h3>
            <p class="text-gray-400 mb-6">To: <span id="message-recipient" class="font-semibold"></span></p>
            <form id="send-message-form" class="space-y-5">
                <input type="hidden" id="message-student-id" name="student_id" value="">
                <div>
                    <label for="messageSubject" class="block text-sm font-medium text-gray-300 mb-2">Subject</label>
                    <input type="text" id="messageSubject" name="subject" class="input-field" placeholder="e.g., Next Lesson Prep" required>
                </div>
                <div>
                    <label for="messageBody" class="block text-sm font-medium text-gray-300 mb-2">Message</label>
                    <textarea id="messageBody" name="message" rows="5" class="input-field" placeholder="Write your message here..." required></textarea>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" class="py-2.5 px-5 bg-gray-700 text-white font-semibold rounded-lg hover:bg-gray-600 modal-close-btn">Cancel</button>
                    <button id="send-message-submit" type="submit" class="py-2.5 px-5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500 inline-flex items-center gap-2">
                        <span class="btn-label">Send Message</span>
                        <span class="spinner hidden" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // studentsData used to be declared here and never read, which put every
        // assigned student's email address into the page for nothing. The cards
        // already carry what the page needs, in data attributes.
        const tutorId = <?= json_encode($tutor_id) ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // ===== Utilities =====
            const openModal = (modalEl) => {
                modalEl.setAttribute('data-state','opening');
                modalEl.classList.remove('hidden');
                requestAnimationFrame(() => {
                    modalEl.setAttribute('data-state','open');
                    const panel = modalEl.querySelector('.modal-panel');
                    if (panel) { panel.setAttribute('data-state', 'opening'); requestAnimationFrame(()=> panel.setAttribute('data-state','open')); }
                });
            };
            const closeModal = (modalEl) => { modalEl.classList.add('hidden'); modalEl.removeAttribute('data-state'); const panel = modalEl.querySelector('.modal-panel'); if (panel) panel.removeAttribute('data-state'); };

            const setButtonLoading = (btn, isLoading, loadingText) => {
                if (!btn) return;
                const label = btn.querySelector('.btn-label');
                const spin = btn.querySelector('.spinner');
                if (isLoading) {
                    btn.disabled = true; btn.classList.add('opacity-80');
                    if (label && loadingText) label.textContent = loadingText;
                    if (spin) spin.classList.remove('hidden');
                } else {
                    btn.disabled = false; btn.classList.remove('opacity-80');
                    if (label) label.textContent = btn.dataset.defaultLabel || label.textContent;
                    if (spin) spin.classList.add('hidden');
                }
            };

            // Store default labels
            document.querySelectorAll('button .btn-label').forEach(lbl => {
                const btn = lbl.closest('button');
                if (btn) btn.dataset.defaultLabel = lbl.textContent;
            });

            // Modal elements
            const messageBtns = document.querySelectorAll('.message-btn');
            
            const sendMessageModal = document.getElementById('sendMessageModal');
            
            const allModals = document.querySelectorAll('#sendMessageModal');
            const closeModalBtns = document.querySelectorAll('#sendMessageModal .modal-close-btn');

            // Search functionality
            const searchInput = document.getElementById('student-search');
            const studentCards = document.querySelectorAll('.student-card');

            // Message buttons
            messageBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const studentId = btn.dataset.studentId;
                    const studentName = btn.dataset.studentName;
                    
                    document.getElementById('message-student-id').value = studentId;
                    document.getElementById('message-recipient').textContent = studentName;
                    
                    openModal(sendMessageModal);
                });
            });

            // Close modal handlers
            closeModalBtns.forEach(btn => {
                btn.addEventListener('click', () => { const modal = btn.closest('.modal-backdrop'); if (modal) closeModal(modal); });
            });

            // Click outside modal to close
            allModals.forEach(modal => { modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(modal); }); });

            // Search filtering with tiny delay for smoother typing
            let searchDebounce;
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    clearTimeout(searchDebounce);
                    const value = e.target.value.toLowerCase();
                    searchDebounce = setTimeout(() => {
                        let anyVisible = false;
                        studentCards.forEach(card => {
                            const studentName = card.dataset.studentName || '';
                            const show = studentName.includes(value);
                            card.style.display = show ? 'flex' : 'none';
                            if (show) anyVisible = true;
                        });
                        // anyVisible was being tracked and then thrown away, so a
                        // search that matched nothing just emptied the page.
                        const empty = document.getElementById('search-empty');
                        if (empty) empty.classList.toggle('hidden', anyVisible);
                    }, 60);
                });
            }

            // Subtle stagger-in for cards (already rendered server-side)
            document.querySelectorAll('.student-card').forEach((el, idx) => {
                el.style.setProperty('--stagger', idx);
                el.classList.add('fade-in-up');
            });

            // Form submissions with button loaders
            document.getElementById('send-message-form')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = document.getElementById('send-message-submit');
                setButtonLoading(btn, true, 'Sending…');
                const formData = new FormData(e.target);
                try {
                    const response = await fetch('api/send_message.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        closeModal(sendMessageModal);
                        e.target.reset();
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (error) {
                    alert('Error sending message. Please try again.');
                } finally { setButtonLoading(btn, false); }
            });

        });
    </script>
</body>
</html>
