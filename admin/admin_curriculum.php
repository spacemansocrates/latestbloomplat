<?php
// admin_curriculum.php — subjects, topics and subtopics.
//
// The important thing about this page: `lessons.subject_id` is ON DELETE CASCADE
// against `subjects`, so deleting a subject deletes every lesson taught in it — and
// each of those cascades again into ratings, recordings, QC reviews and catch-up
// requests. Deleting "Mathematics" would take 256 lessons with it. That button had
// no CSRF token and a confirm() that mentioned only topics and subtopics.

require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id      = (int) ($_SESSION['user_id'] ?? 0);
$user_role    = $_SESSION['user_role'] ?? '';
$current_page = 'curriculum';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

// ---------- DB ----------
// config/db_connect.php already built a pooled connection; the hand-rolled PDO this
// page used duplicated it and dropped DB_PORT from the DSN.
try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    error_log('admin_curriculum DB error: ' . $e->getMessage());
    http_response_code(503);
    die('The curriculum page is temporarily unavailable. Please try again shortly.');
}

// ---------- Admin authorization ----------
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
    $stmt->execute([$user_id]);
    $is_admin = (bool) $stmt->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// ---------- CSRF ----------
// This page had none at all. Nine write actions were forgeable, including the
// subject delete that cascades into the lesson history.
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$ok = []; $err = [];
function add_ok($m)  { global $ok;  $ok[]  = $m; }
function add_err($m) { global $err; $err[] = $m; }

/** Web path for a stored hero image (they are saved as "/uploads/subjects/x.jpg"). */
function hero_src(?string $url): string {
    if (!$url) return '';
    return '../' . ltrim($url, '/');
}

/**
 * Validate and store an uploaded hero image, returning its stored path.
 * Callers must validate everything else BEFORE calling this: the old code ran the
 * upload (and unlinked the previous file) before checking the subject name, so a
 * blank name destroyed the existing image and saved an orphan.
 */
function handle_subject_hero_upload(?array $file): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Hero image upload failed.');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported image type — use JPEG, PNG or WebP.');
    if ($file['size'] > 1_500_000) throw new RuntimeException('Image too large (max 1.5MB).');

    $dir = __DIR__ . '/../uploads/subjects';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the upload directory.');
    }
    $name = 'subj_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Failed to save hero image.');
    }
    return '/uploads/subjects/' . $name;
}

/** Remove a stored hero image from disk, ignoring anything outside uploads/subjects. */
function delete_hero_file(?string $url): void {
    if (!$url || strpos($url, '/uploads/subjects/') !== 0) return;
    $fs = realpath(__DIR__ . '/../' . ltrim($url, '/'));
    $base = realpath(__DIR__ . '/../uploads/subjects');
    if ($fs && $base && strpos($fs, $base) === 0 && is_file($fs)) @unlink($fs);
}

/** What a DELETE of this subject would take with it. */
function subjectDependents(PDO $pdo, int $id): array {
    // lessons, topics, tutor_subjects and package_subjects are ON DELETE CASCADE;
    // learning_materials is SET NULL. The lesson count is the one that matters —
    // each lesson cascades again into its ratings, recording, QC and catch-ups.
    $checks = [
        'lesson'           => "SELECT COUNT(*) FROM lessons WHERE subject_id=?",
        'topic'            => "SELECT COUNT(*) FROM topics WHERE subject_id=?",
        'tutor assignment' => "SELECT COUNT(*) FROM tutor_subjects WHERE subject_id=?",
        'package link'     => "SELECT COUNT(*) FROM package_subjects WHERE subject_id=?",
        'learning material'=> "SELECT COUNT(*) FROM learning_materials WHERE subject_id=?",
    ];
    $out = [];
    foreach ($checks as $label => $sql) {
        try {
            $s = $pdo->prepare($sql); $s->execute([$id]);
            $n = (int) $s->fetchColumn();
            if ($n > 0) $out[$label] = $n;
        } catch (Throwable $e) { /* table absent on an older schema */ }
    }
    return $out;
}
function topicDependents(PDO $pdo, int $id): array {
    $out = [];
    foreach (['subtopic' => "SELECT COUNT(*) FROM subtopics WHERE topic_id=?",
              'lesson record' => "SELECT COUNT(*) FROM lesson_topics_covered WHERE topic_id=?"] as $label => $sql) {
        try { $s = $pdo->prepare($sql); $s->execute([$id]); $n = (int)$s->fetchColumn(); if ($n) $out[$label] = $n; }
        catch (Throwable $e) {}
    }
    return $out;
}
function describeDeps(array $deps): string {
    $bits = [];
    foreach ($deps as $label => $n) $bits[] = $n . ' ' . $label . ($n === 1 ? '' : 's');
    return implode(', ', $bits);
}

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Invalid session token. Refresh the page and try again.');
        }

        // ----- Subjects -----
        if ($action === 'create_subject') {
            $name = trim($_POST['name'] ?? '');
            $curr = trim($_POST['curriculum'] ?? '') ?: 'General';
            $year = (int) ($_POST['year_level'] ?? 0);
            $desc = trim($_POST['description'] ?? '');
            $board = trim($_POST['exam_board'] ?? '');
            $hero_alt = trim($_POST['hero_image_alt'] ?? '');

            // Validate BEFORE touching the filesystem.
            if ($name === '') throw new RuntimeException('Subject name is required.');
            if ($year < 0 || $year > 20) throw new RuntimeException('Year level must be 0–20.');
            $dup = $pdo->prepare("SELECT id FROM subjects WHERE name=? AND curriculum=? AND year_level=?");
            $dup->execute([$name, $curr, $year]);
            if ($dup->fetchColumn()) throw new RuntimeException('A subject with that name, curriculum and year already exists.');

            $hero_url = handle_subject_hero_upload($_FILES['hero_image'] ?? null);

            // exam_board was never written by this page, so the column could only ever
            // be set outside the admin UI.
            $pdo->prepare("INSERT INTO subjects (name, curriculum, year_level, description, exam_board, hero_image_url, hero_image_alt) VALUES (?,?,?,?,?,?,?)")
                ->execute([$name, $curr, $year, ($desc ?: null), ($board ?: null), $hero_url, ($hero_alt ?: null)]);
            add_ok('Subject created.');
        }

        if ($action === 'update_subject') {
            $id       = (int) ($_POST['subject_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $curr     = trim($_POST['curriculum'] ?? '') ?: 'General';
            $year     = (int) ($_POST['year_level'] ?? 0);
            $desc     = trim($_POST['description'] ?? '');
            $board    = trim($_POST['exam_board'] ?? '');
            $hero_alt = trim($_POST['hero_image_alt'] ?? '');
            $remove   = !empty($_POST['hero_image_remove']);

            if ($id <= 0)     throw new RuntimeException('Invalid subject.');
            if ($name === '') throw new RuntimeException('Subject name is required.');
            // The old code checked the name only after the upload had already run and
            // the previous image had been unlinked, and never validated the year here.
            if ($year < 0 || $year > 20) throw new RuntimeException('Year level must be 0–20.');

            $cur = $pdo->prepare("SELECT hero_image_url FROM subjects WHERE id=?");
            $cur->execute([$id]);
            $curRow = $cur->fetch();
            if (!$curRow) throw new RuntimeException('Subject not found.');

            $dup = $pdo->prepare("SELECT id FROM subjects WHERE name=? AND curriculum=? AND year_level=? AND id<>?");
            $dup->execute([$name, $curr, $year, $id]);
            if ($dup->fetchColumn()) throw new RuntimeException('Another subject already has that name, curriculum and year.');

            $oldUrl   = $curRow['hero_image_url'] ?: null;
            $uploaded = handle_subject_hero_upload($_FILES['hero_image'] ?? null);
            $newUrl   = $uploaded ?: ($remove ? null : $oldUrl);

            $pdo->prepare("UPDATE subjects SET name=?, curriculum=?, year_level=?, description=?, exam_board=?, hero_image_url=?, hero_image_alt=? WHERE id=?")
                ->execute([$name, $curr, $year, ($desc ?: null), ($board ?: null), $newUrl, ($hero_alt ?: null), $id]);

            // Only bin the old file once the row actually points somewhere else.
            if ($oldUrl && $oldUrl !== $newUrl) delete_hero_file($oldUrl);
            add_ok('Subject updated.');
        }

        if ($action === 'delete_subject') {
            $id = (int) ($_POST['subject_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid subject.');
            $s = $pdo->prepare("SELECT name, hero_image_url FROM subjects WHERE id=?");
            $s->execute([$id]);
            $subj = $s->fetch();
            if (!$subj) throw new RuntimeException('Subject not found.');

            $deps = subjectDependents($pdo, $id);
            // Lessons are never worth losing to a mis-click. Everything else can be
            // confirmed away; a subject that has been taught cannot.
            if (!empty($deps['lesson'])) {
                throw new RuntimeException(
                    '"' . $subj['name'] . '" has ' . $deps['lesson'] . ' lesson' . ($deps['lesson'] === 1 ? '' : 's')
                    . ' recorded against it. Deleting the subject would delete those lessons and everything attached to them '
                    . '(ratings, recordings, QC reviews, catch-up requests). Reassign or remove the lessons first.'
                );
            }
            if ($deps && ($_POST['ack_destructive'] ?? '') !== '1') {
                throw new RuntimeException(
                    'This subject has ' . describeDeps($deps) . ' attached, which deletion would remove. Press Delete again to confirm.'
                );
            }

            // The old code issued four separate deletes with no transaction, so a
            // failure part-way left the curriculum half-removed.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            delete_hero_file($subj['hero_image_url'] ?? null);
            add_ok('Subject deleted' . ($deps ? ', along with ' . describeDeps($deps) . '.' : '.'));
        }

        // ----- Topics -----
        if ($action === 'create_topic') {
            $subject_id = (int) ($_POST['subject_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($name === '') throw new RuntimeException('Topic name is required.');
            // Without this the foreign key threw a raw SQLSTATE at the admin.
            $chk = $pdo->prepare("SELECT id FROM subjects WHERE id=?");
            $chk->execute([$subject_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Choose a subject first.');
            $dup = $pdo->prepare("SELECT id FROM topics WHERE subject_id=? AND name=?");
            $dup->execute([$subject_id, $name]);
            if ($dup->fetchColumn()) throw new RuntimeException('That topic already exists in this subject.');

            $pdo->prepare("INSERT INTO topics (subject_id,name,description) VALUES (?,?,?)")
                ->execute([$subject_id, $name, ($desc ?: null)]);
            add_ok('Topic added.');
        }

        if ($action === 'update_topic') {
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($topic_id <= 0) throw new RuntimeException('Invalid topic.');
            if ($name === '')   throw new RuntimeException('Topic name is required.');
            $chk = $pdo->prepare("SELECT id FROM topics WHERE id=?");
            $chk->execute([$topic_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Topic not found.');

            $pdo->prepare("UPDATE topics SET name=?, description=? WHERE id=?")->execute([$name, ($desc ?: null), $topic_id]);
            add_ok('Topic updated.');
        }

        if ($action === 'delete_topic') {
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            if ($topic_id <= 0) throw new RuntimeException('Invalid topic.');
            $chk = $pdo->prepare("SELECT name FROM topics WHERE id=?");
            $chk->execute([$topic_id]);
            $tname = $chk->fetchColumn();
            if ($tname === false) throw new RuntimeException('Topic not found.');

            $deps = topicDependents($pdo, $topic_id);
            if (!empty($deps['lesson record']) && ($_POST['ack_destructive'] ?? '') !== '1') {
                throw new RuntimeException(
                    '"' . $tname . '" is recorded as covered in ' . $deps['lesson record'] . ' lesson'
                    . ($deps['lesson record'] === 1 ? '' : 's') . '. Deleting it removes that coverage history'
                    . (!empty($deps['subtopic']) ? ' and its ' . $deps['subtopic'] . ' subtopics' : '')
                    . '. Press Delete again to confirm.'
                );
            }
            // subtopics and lesson_topics_covered both cascade from topics.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM topics WHERE id=?")->execute([$topic_id]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            add_ok('Topic deleted' . ($deps ? ', along with ' . describeDeps($deps) . '.' : '.'));
        }

        // ----- Subtopics -----
        if ($action === 'create_subtopic') {
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($name === '') throw new RuntimeException('Subtopic name is required.');
            $chk = $pdo->prepare("SELECT id FROM topics WHERE id=?");
            $chk->execute([$topic_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Topic not found.');
            $dup = $pdo->prepare("SELECT id FROM subtopics WHERE topic_id=? AND name=?");
            $dup->execute([$topic_id, $name]);
            if ($dup->fetchColumn()) throw new RuntimeException('That subtopic already exists in this topic.');

            $pdo->prepare("INSERT INTO subtopics (topic_id,name,description) VALUES (?,?,?)")
                ->execute([$topic_id, $name, ($desc ?: null)]);
            add_ok('Subtopic added.');
        }

        if ($action === 'update_subtopic') {
            $sub_id = (int) ($_POST['subtopic_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($sub_id <= 0) throw new RuntimeException('Invalid subtopic.');
            if ($name === '') throw new RuntimeException('Subtopic name is required.');
            $chk = $pdo->prepare("SELECT id FROM subtopics WHERE id=?");
            $chk->execute([$sub_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Subtopic not found.');

            $pdo->prepare("UPDATE subtopics SET name=?, description=? WHERE id=?")->execute([$name, ($desc ?: null), $sub_id]);
            add_ok('Subtopic updated.');
        }

        if ($action === 'delete_subtopic') {
            $sub_id = (int) ($_POST['subtopic_id'] ?? 0);
            if ($sub_id <= 0) throw new RuntimeException('Invalid subtopic.');
            $chk = $pdo->prepare("SELECT id FROM subtopics WHERE id=?");
            $chk->execute([$sub_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Subtopic not found.');

            $covered = $pdo->prepare("SELECT COUNT(*) FROM lesson_subtopics_covered WHERE subtopic_id=?");
            $covered->execute([$sub_id]);
            $n = (int) $covered->fetchColumn();
            if ($n > 0 && ($_POST['ack_destructive'] ?? '') !== '1') {
                throw new RuntimeException("This subtopic is recorded as covered in {$n} lesson" . ($n === 1 ? '' : 's') . '. Press Delete again to confirm.');
            }
            $pdo->prepare("DELETE FROM subtopics WHERE id=?")->execute([$sub_id]);
            add_ok('Subtopic deleted.');
        }
    } catch (Throwable $e) {
        add_err($e->getMessage());
    }
}

// ---------- AJAX: one topic's subtopics, loaded when it is opened ----------
if (($_GET['ajax'] ?? '') === 'subtopics') {
    header('Content-Type: text/html; charset=utf-8');
    $tid = (int) ($_GET['topic_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM topics WHERE id=?");
    $chk->execute([$tid]);
    if (!$chk->fetchColumn()) { echo '<p class="text-rose-400 text-xs">Topic not found.</p>'; exit; }
    $sq = $pdo->prepare("SELECT id, name, description FROM subtopics WHERE topic_id=? ORDER BY name");
    $sq->execute([$tid]);
    foreach ($sq as $sub) : ?>
      <div class="flex flex-wrap gap-2 items-center bg-gray-900/50 rounded-md px-2 py-1.5">
        <form method="post" class="flex flex-wrap gap-2 items-center flex-1">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="update_subtopic">
          <input type="hidden" name="subtopic_id" value="<?php echo (int)$sub['id']; ?>">
          <input name="name" value="<?php echo h($sub['name']); ?>" class="form-input flex-1 min-w-[8rem] !py-1.5 !text-xs" required>
          <input name="description" value="<?php echo h($sub['description'] ?? ''); ?>" class="form-input flex-1 min-w-[8rem] !py-1.5 !text-xs" placeholder="Description">
          <button class="bg-gray-700 hover:bg-gray-600 text-white text-[11px] font-semibold py-1.5 px-2.5 rounded-md">Save</button>
        </form>
        <form method="post" class="js-confirm" data-confirm="<?php echo h('Delete subtopic “' . $sub['name'] . '”?'); ?>">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="delete_subtopic">
          <input type="hidden" name="subtopic_id" value="<?php echo (int)$sub['id']; ?>">
          <input type="hidden" name="ack_destructive" value="1">
          <button class="bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 text-[11px] font-semibold py-1.5 px-2.5 rounded-md">&times;</button>
        </form>
      </div>
    <?php endforeach; ?>
      <form method="post" class="flex flex-wrap gap-2 items-center mt-2">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="create_subtopic">
        <input type="hidden" name="topic_id" value="<?php echo (int)$tid; ?>">
        <input name="name" class="form-input flex-1 min-w-[8rem] !py-1.5 !text-xs" placeholder="New subtopic…" required>
        <input name="description" class="form-input flex-1 min-w-[8rem] !py-1.5 !text-xs" placeholder="Description (optional)">
        <button class="bg-emerald-600/80 hover:bg-emerald-500 text-white text-[11px] font-semibold py-1.5 px-3 rounded-md">Add</button>
      </form>
    <?php
    exit;
}

// ---------- Filters ----------
$filter_curr = trim($_GET['curriculum'] ?? 'All');
$filter_year = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int) $_GET['year_level'] : -1;
$q           = trim($_GET['q'] ?? '');
$tq          = trim($_GET['tq'] ?? ''); // topic search within the managed subject

$where = []; $args = [];
if ($filter_curr !== '' && $filter_curr !== 'All') { $where[] = "s.curriculum=?"; $args[] = $filter_curr; }
if ($filter_year >= 0) { $where[] = "s.year_level=?"; $args[] = $filter_year; }
if ($q !== '') { $where[] = "(s.name LIKE ? OR s.description LIKE ? OR s.curriculum LIKE ?)"; $like = "%$q%"; array_push($args, $like, $like, $like); }
$subject_where = $where ? "WHERE " . implode(" AND ", $where) : "";

// ---------- Data ----------
// One query with the counts attached. The lesson count is what makes a subject
// dangerous to delete, so it belongs on the card, not hidden behind a confirm().
$SUBJECT_COLS = "s.id, s.name, s.curriculum, s.year_level, s.description, s.exam_board,
    s.hero_image_url, s.hero_image_alt,
    (SELECT COUNT(*) FROM topics t WHERE t.subject_id = s.id) AS n_topics,
    (SELECT COUNT(*) FROM subtopics st JOIN topics t2 ON t2.id = st.topic_id WHERE t2.subject_id = s.id) AS n_subtopics,
    (SELECT COUNT(*) FROM lessons l WHERE l.subject_id = s.id) AS n_lessons,
    (SELECT COUNT(*) FROM tutor_subjects ts WHERE ts.subject_id = s.id) AS n_tutors";

$stmt = $pdo->prepare("SELECT $SUBJECT_COLS FROM subjects s $subject_where ORDER BY s.curriculum, s.year_level, s.name");
$stmt->execute($args);
$filtered_subjects = $stmt->fetchAll();

// The picker needs every subject regardless of the filters above.
$all_subjects = $pdo->query("SELECT id, name, curriculum, year_level FROM subjects ORDER BY curriculum, year_level, name")->fetchAll();

$curr_list = $pdo->query("SELECT DISTINCT curriculum FROM subjects ORDER BY curriculum")->fetchAll(PDO::FETCH_COLUMN);
$year_list = $pdo->query("SELECT DISTINCT year_level FROM subjects ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);

$total_subjects  = (int) $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$total_topics    = (int) $pdo->query("SELECT COUNT(*) FROM topics")->fetchColumn();
$total_subtopics = (int) $pdo->query("SELECT COUNT(*) FROM subtopics")->fetchColumn();
$orphan_topics   = (int) $pdo->query("SELECT COUNT(*) FROM topics t WHERE NOT EXISTS (SELECT 1 FROM subtopics st WHERE st.topic_id = t.id)")->fetchColumn();

$manage_subject_id = isset($_GET['manage_subject_id']) ? (int) $_GET['manage_subject_id'] : 0;
if ($manage_subject_id === 0 && !empty($all_subjects)) $manage_subject_id = (int) $all_subjects[0]['id'];

$manage_subject = null;
foreach ($all_subjects as $s0) if ((int)$s0['id'] === $manage_subject_id) $manage_subject = $s0;

$topics = [];
if ($manage_subject_id > 0) {
    $tWhere = "WHERE t.subject_id = ?"; $tArgs = [$manage_subject_id];
    if ($tq !== '') { $tWhere .= " AND (t.name LIKE ? OR t.description LIKE ?)"; $tArgs[] = "%$tq%"; $tArgs[] = "%$tq%"; }
    // Counts only. Subtopics are fetched when a topic is actually opened — see the
    // ajax=subtopics handler above. Rendering all 286 of Mathematics' subtopics up
    // front, each with two inline forms, produced an 844KB page.
    $st = $pdo->prepare("SELECT t.id, t.name, t.description,
          (SELECT COUNT(*) FROM subtopics st WHERE st.topic_id = t.id) AS n_subtopics,
          (SELECT COUNT(*) FROM lesson_topics_covered c WHERE c.topic_id = t.id) AS n_covered
        FROM topics t $tWhere ORDER BY t.name");
    $st->execute($tArgs);
    $topics = $st->fetchAll();
}

function qs_keep(array $over = []): string {
    $base = [
        'curriculum' => $GLOBALS['filter_curr'] !== 'All' ? $GLOBALS['filter_curr'] : null,
        'year_level' => $GLOBALS['filter_year'] >= 0 ? $GLOBALS['filter_year'] : null,
        'q'          => $GLOBALS['q'] ?: null,
        'tq'         => $GLOBALS['tq'] ?: null,
        'manage_subject_id' => $GLOBALS['manage_subject_id'] ?: null,
    ];
    foreach ($over as $k => $v) $base[$k] = $v;
    return '?' . http_build_query(array_filter($base, fn($v) => $v !== null && $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Curriculum</title>
    <link rel="icon" type="image/png" href="../favicon.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Inter',sans-serif; background:#111827; color:#d1d5db; }
        ::-webkit-scrollbar{width:8px;height:8px} ::-webkit-scrollbar-track{background:#1f2937} ::-webkit-scrollbar-thumb{background:#4b5563;border-radius:4px}
        .form-input{background:#374151;border:1px solid #4b5563;color:#f3f4f6;border-radius:.5rem;padding:.55rem .8rem;width:100%;font-size:.875rem}
        .form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.25)}
        .card{background:#0f1623;border:1px solid rgba(255,255,255,.06);border-radius:.75rem}
        details>summary{list-style:none} details>summary::-webkit-details-marker{display:none}
        .scroll-pane{max-height:66vh;overflow-y:auto}
    </style>
</head>
<body class="flex min-h-screen">
    <?php include 'admin_sidebar.php'; ?>

    <main class="flex-1 bg-gray-800 p-5 lg:p-7 min-w-0">
        <header class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <div>
                <h2 class="text-2xl font-bold text-white">Curriculum &amp; Subjects</h2>
                <p class="text-gray-400 text-sm mt-0.5">
                    <?php echo number_format($total_subjects); ?> subjects &middot;
                    <?php echo number_format($total_topics); ?> topics &middot;
                    <?php echo number_format($total_subtopics); ?> subtopics
                    <?php if ($orphan_topics): ?>
                        &middot; <a class="text-amber-400 hover:text-amber-300" href="<?php echo h(qs_keep()); ?>" title="Topics with no subtopics"><?php echo $orphan_topics; ?> topics have no subtopics</a>
                    <?php endif; ?>
                </p>
            </div>
            <button id="newSubjectBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg text-sm">New subject</button>
        </header>

        <?php if (!empty($ok) || !empty($err)) : ?>
            <div class="mb-5 space-y-2">
                <?php foreach ($ok as $m) : ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div><?php endforeach; ?>
                <?php foreach ($err as $m) : ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-5">

            <!-- LEFT: subjects -->
            <section class="xl:col-span-2 card p-4">
                <form method="get" class="flex flex-wrap gap-2 mb-4">
                    <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search subjects…" class="form-input flex-1 min-w-[9rem]">
                    <select name="curriculum" class="form-input w-auto">
                        <option value="All">All curricula</option>
                        <?php foreach ($curr_list as $c) : ?>
                            <option value="<?php echo h($c); ?>" <?php echo $filter_curr === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="year_level" class="form-input w-auto">
                        <option value="">Any year</option>
                        <?php foreach ($year_list as $y) : ?>
                            <option value="<?php echo (int)$y; ?>" <?php echo $filter_year === (int)$y ? 'selected' : ''; ?>>Year <?php echo (int)$y; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="manage_subject_id" value="<?php echo (int)$manage_subject_id; ?>">
                    <button class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Filter</button>
                    <a href="admin_curriculum.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">Reset</a>
                </form>

                <div class="space-y-3 scroll-pane pr-1">
                    <?php if (empty($filtered_subjects)) : ?>
                        <p class="text-gray-400 text-sm text-center py-10">No subjects match those filters.</p>
                    <?php else : foreach ($filtered_subjects as $s) :
                        $isManaged = ((int)$s['id'] === $manage_subject_id);
                        $deps = [];
                        if ((int)$s['n_lessons'])   $deps[] = (int)$s['n_lessons'] . ' lessons';
                        if ((int)$s['n_topics'])    $deps[] = (int)$s['n_topics'] . ' topics';
                        if ((int)$s['n_subtopics']) $deps[] = (int)$s['n_subtopics'] . ' subtopics';
                    ?>
                        <div class="rounded-lg border <?php echo $isManaged ? 'border-indigo-500/50 bg-indigo-500/[0.05]' : 'border-gray-700/60 bg-gray-800/40'; ?> p-3">
                            <div class="flex items-start gap-3">
                                <?php if (!empty($s['hero_image_url'])) : ?>
                                    <img src="<?php echo h(hero_src($s['hero_image_url'])); ?>" alt="<?php echo h($s['hero_image_alt'] ?? ''); ?>"
                                         class="w-14 h-14 object-cover rounded-md flex-shrink-0 bg-gray-900" loading="lazy">
                                <?php else : ?>
                                    <div class="w-14 h-14 rounded-md flex-shrink-0 bg-gray-900 border border-gray-700 flex items-center justify-center text-gray-600 text-xl">
                                        <?php echo h(mb_strtoupper(mb_substr($s['name'], 0, 1))); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-white truncate"><?php echo h($s['name']); ?></p>
                                    <p class="text-xs text-gray-400">
                                        <?php echo h($s['curriculum']); ?> &middot; Year <?php echo (int)$s['year_level']; ?>
                                        <?php if (!empty($s['exam_board'])) : ?> &middot; <?php echo h($s['exam_board']); ?><?php endif; ?>
                                    </p>
                                    <?php if (!empty($s['description'])) : ?>
                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-2"><?php echo h($s['description']); ?></p>
                                    <?php endif; ?>
                                    <p class="text-[11px] text-gray-500 mt-1.5">
                                        <span class="<?php echo (int)$s['n_lessons'] ? 'text-amber-400/90' : ''; ?>"><?php echo number_format((int)$s['n_lessons']); ?> lessons</span>
                                        &middot; <?php echo number_format((int)$s['n_topics']); ?> topics
                                        &middot; <?php echo number_format((int)$s['n_subtopics']); ?> subtopics
                                        <?php if ((int)$s['n_tutors']) : ?> &middot; <?php echo (int)$s['n_tutors']; ?> tutors<?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 mt-2.5">
                                <a href="<?php echo h(qs_keep(['manage_subject_id' => (int)$s['id'], 'tq' => null])); ?>"
                                   class="text-xs font-semibold <?php echo $isManaged ? 'bg-indigo-500/20 text-indigo-200' : 'bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20'; ?> py-1.5 px-3 rounded-lg">
                                   <?php echo $isManaged ? 'Managing' : 'Manage topics'; ?></a>
                                <button type="button" class="edit-subject-btn text-xs font-semibold bg-gray-700/60 hover:bg-gray-600 text-gray-200 py-1.5 px-3 rounded-lg"
                                    data-id="<?php echo (int)$s['id']; ?>"
                                    data-name="<?php echo h($s['name']); ?>"
                                    data-curriculum="<?php echo h($s['curriculum']); ?>"
                                    data-year="<?php echo (int)$s['year_level']; ?>"
                                    data-description="<?php echo h($s['description'] ?? ''); ?>"
                                    data-board="<?php echo h($s['exam_board'] ?? ''); ?>"
                                    data-alt="<?php echo h($s['hero_image_alt'] ?? ''); ?>"
                                    data-hero="<?php echo h(hero_src($s['hero_image_url'])); ?>">Edit</button>
                                <?php if ((int)$s['n_lessons']) : ?>
                                    <!-- Deleting this would cascade into the lesson history, so the
                                         server refuses it outright. Say so here rather than offering
                                         a red button that only fails once pressed. -->
                                    <span class="text-xs font-semibold bg-gray-800 text-gray-500 py-1.5 px-3 rounded-lg cursor-not-allowed"
                                          title="Cannot delete: <?php echo number_format((int)$s['n_lessons']); ?> lessons are recorded against this subject and would be deleted with it.">Delete&nbsp;&#128274;</span>
                                <?php else : ?>
                                    <form method="post" class="inline js-confirm"
                                          data-confirm="<?php echo h('Delete “' . $s['name'] . '”?' . ($deps ? "\n\nAttached: " . implode(', ', $deps) . '.' : '')); ?>">
                                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                        <input type="hidden" name="action" value="delete_subject">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$s['id']; ?>">
                                        <?php if ($deps) : ?><input type="hidden" name="ack_destructive" value="1"><?php endif; ?>
                                        <button class="text-xs font-semibold bg-rose-500/10 text-rose-400 py-1.5 px-3 rounded-lg hover:bg-rose-500/20">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </section>

            <!-- RIGHT: topics & subtopics -->
            <section class="xl:col-span-3 card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 class="text-base font-semibold text-white">
                        Topics
                        <?php if ($manage_subject) : ?>
                            <span class="text-gray-500 font-normal">— <?php echo h($manage_subject['name']); ?></span>
                        <?php endif; ?>
                    </h3>
                    <form method="get" class="flex flex-wrap items-center gap-2">
                        <select name="manage_subject_id" class="form-input w-auto" onchange="this.form.submit()">
                            <?php foreach ($all_subjects as $ss) : ?>
                                <option value="<?php echo (int)$ss['id']; ?>" <?php echo $manage_subject_id === (int)$ss['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($ss['name']); ?> (<?php echo h($ss['curriculum']); ?> · Y<?php echo (int)$ss['year_level']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="tq" value="<?php echo h($tq); ?>" placeholder="Find a topic…" class="form-input w-auto">
                        <?php if ($q !== '') : ?><input type="hidden" name="q" value="<?php echo h($q); ?>"><?php endif; ?>
                        <?php if ($filter_curr !== 'All') : ?><input type="hidden" name="curriculum" value="<?php echo h($filter_curr); ?>"><?php endif; ?>
                        <?php if ($filter_year >= 0) : ?><input type="hidden" name="year_level" value="<?php echo (int)$filter_year; ?>"><?php endif; ?>
                        <button class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm">Find</button>
                    </form>
                </div>

                <?php if ($manage_subject_id === 0) : ?>
                    <p class="text-gray-400 text-sm py-10 text-center">Create a subject first, then select it to manage topics.</p>
                <?php else : ?>
                    <form method="post" class="flex flex-wrap gap-2 mb-4 bg-gray-800/50 p-2.5 rounded-lg">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="create_topic">
                        <input type="hidden" name="subject_id" value="<?php echo (int)$manage_subject_id; ?>">
                        <input name="name" class="form-input flex-1 min-w-[10rem]" placeholder="New topic name…" required>
                        <input name="description" class="form-input flex-1 min-w-[10rem]" placeholder="Description (optional)">
                        <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 px-4 rounded-lg text-sm">Add topic</button>
                    </form>

                    <?php if (empty($topics)) : ?>
                        <p class="text-gray-400 text-sm py-10 text-center">
                            <?php echo $tq !== '' ? 'No topics match that search.' : 'No topics yet — add one above.'; ?>
                        </p>
                    <?php else : ?>
                        <p class="text-xs text-gray-500 mb-2"><?php echo count($topics); ?> topic<?php echo count($topics) === 1 ? '' : 's'; ?><?php echo $tq !== '' ? ' matching “' . h($tq) . '”' : ''; ?>. Click one to open its subtopics.</p>
                        <div class="space-y-2 scroll-pane pr-1">
                            <?php foreach ($topics as $t) :
                                $nSub = (int)$t['n_subtopics'];
                                $nCov = (int)$t['n_covered'];
                            ?>
                                <details class="topic bg-gray-800/50 rounded-lg border border-gray-700/50" data-topic-id="<?php echo (int)$t['id']; ?>" <?php echo $tq !== '' ? 'open' : ''; ?>>
                                    <summary class="cursor-pointer select-none px-3 py-2.5 flex items-center gap-3 hover:bg-gray-800/80 rounded-lg">
                                        <span class="text-gray-500 text-xs">▸</span>
                                        <span class="flex-1 min-w-0">
                                            <span class="font-semibold text-white text-sm"><?php echo h($t['name']); ?></span>
                                            <?php if (!empty($t['description'])) : ?>
                                                <span class="block text-xs text-gray-500 truncate"><?php echo h($t['description']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-[11px] text-gray-500 flex-shrink-0">
                                            <?php echo $nSub; ?> sub<?php if ($nCov) : ?> &middot; <span class="text-sky-400/80">covered <?php echo $nCov; ?>&times;</span><?php endif; ?>
                                        </span>
                                    </summary>

                                    <div class="px-3 pb-3 pt-1 border-t border-gray-700/50">
                                        <form method="post" class="flex flex-wrap gap-2 items-center mb-3">
                                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                            <input type="hidden" name="action" value="update_topic">
                                            <input type="hidden" name="topic_id" value="<?php echo (int)$t['id']; ?>">
                                            <input name="name" value="<?php echo h($t['name']); ?>" class="form-input flex-1 min-w-[8rem]" required>
                                            <input name="description" value="<?php echo h($t['description'] ?? ''); ?>" class="form-input flex-1 min-w-[8rem]" placeholder="Description">
                                            <button class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-semibold py-2 px-3 rounded-lg">Save</button>
                                    </form>
                                        <form method="post" class="inline js-confirm"
                                              data-confirm="<?php echo h('Delete topic “' . $t['name'] . '”?' . ($nSub ? "\n\n" . $nSub . ' subtopics go with it.' : '') . ($nCov ? "\nIt is recorded as covered in " . $nCov . ' lessons.' : '')); ?>">
                                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                            <input type="hidden" name="action" value="delete_topic">
                                            <input type="hidden" name="topic_id" value="<?php echo (int)$t['id']; ?>">
                                            <?php if ($nCov) : ?><input type="hidden" name="ack_destructive" value="1"><?php endif; ?>
                                            <button class="bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 text-xs font-semibold py-2 px-3 rounded-lg -mt-3 mb-3">Delete topic</button>
                                        </form>

                                        <div class="space-y-1.5 subtopics" data-loaded="0">
                                            <p class="text-xs text-gray-500">Loading subtopics…</p>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- Subject modal (create + edit share it) -->
    <div id="subjectModal" class="fixed inset-0 hidden items-center justify-center z-50">
        <div class="absolute inset-0 bg-black/70" data-close-modal></div>
        <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-5">
                <h3 id="subjectModalTitle" class="text-lg font-semibold text-white">New subject</h3>
                <button type="button" data-close-modal class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
            </div>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" id="sm_action" value="create_subject">
                <input type="hidden" name="subject_id" id="sm_id" value="">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-300 mb-1.5">Name *</label><input name="name" id="sm_name" class="form-input" required></div>
                    <div><label class="block text-sm text-gray-300 mb-1.5">Curriculum</label>
                        <input name="curriculum" id="sm_curriculum" class="form-input" list="currList" placeholder="e.g. GCSE">
                        <datalist id="currList"><?php foreach ($curr_list as $c) : ?><option value="<?php echo h($c); ?>"><?php endforeach; ?></datalist>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-300 mb-1.5">Year level</label><input type="number" name="year_level" id="sm_year" class="form-input" min="0" max="20" value="0"></div>
                    <div><label class="block text-sm text-gray-300 mb-1.5">Exam board</label>
                        <input name="exam_board" id="sm_board" class="form-input" placeholder="e.g. Cambridge">
                        <p class="text-xs text-gray-500 mt-1">Stored, but no page displays it yet.</p></div>
                </div>
                <div><label class="block text-sm text-gray-300 mb-1.5">Description</label><textarea name="description" id="sm_description" rows="2" class="form-input"></textarea></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-300 mb-1.5">Hero image</label>
                        <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" class="form-input !py-1.5">
                        <p class="text-xs text-gray-500 mt-1">JPEG, PNG or WebP, max 1.5MB. Shown on the student dashboard.</p></div>
                    <div><label class="block text-sm text-gray-300 mb-1.5">Image alt text</label><input name="hero_image_alt" id="sm_alt" class="form-input"></div>
                </div>
                <div id="sm_heroCurrent" class="hidden items-center gap-3">
                    <img id="sm_heroImg" src="" alt="" class="w-20 h-20 object-cover rounded-md bg-gray-800">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="hero_image_remove" value="1"> Remove current image
                    </label>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-gray-700">
                    <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-5 py-2.5 rounded-lg">Cancel</button>
                    <button id="sm_submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-5 py-2.5 rounded-lg font-semibold">Create subject</button>
                </div>
            </form>
        </div>
    </div>

<script>
(function () {
    const modal = document.getElementById('subjectModal');
    const open  = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
    document.querySelectorAll('[data-close-modal]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const heroWrap = document.getElementById('sm_heroCurrent');

    document.getElementById('newSubjectBtn').addEventListener('click', () => {
        document.getElementById('subjectModalTitle').textContent = 'New subject';
        document.getElementById('sm_action').value = 'create_subject';
        document.getElementById('sm_submit').textContent = 'Create subject';
        ['sm_id','sm_name','sm_curriculum','sm_description','sm_board','sm_alt'].forEach(i => set(i, ''));
        set('sm_year', '0');
        heroWrap.classList.add('hidden'); heroWrap.classList.remove('flex');
        const cb = heroWrap.querySelector('input[type=checkbox]'); if (cb) cb.checked = false;
        open();
    });

    // The old modal fetched get_subject_details.php on every open — a round trip for
    // data already rendered on the page — and had no .catch(), so any failure left
    // the spinner running forever.
    document.querySelectorAll('.edit-subject-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            document.getElementById('subjectModalTitle').textContent = 'Edit subject';
            document.getElementById('sm_action').value = 'update_subject';
            document.getElementById('sm_submit').textContent = 'Save changes';
            set('sm_id', d.id); set('sm_name', d.name); set('sm_curriculum', d.curriculum);
            set('sm_year', d.year); set('sm_description', d.description);
            set('sm_board', d.board); set('sm_alt', d.alt);
            const cb = heroWrap.querySelector('input[type=checkbox]'); if (cb) cb.checked = false;
            if (d.hero) {
                document.getElementById('sm_heroImg').src = d.hero;
                heroWrap.classList.remove('hidden'); heroWrap.classList.add('flex');
            } else {
                heroWrap.classList.add('hidden'); heroWrap.classList.remove('flex');
            }
            open();
        });
    });

    // Confirmations carry their message in a data- attribute so it never has to
    // survive being escaped into an inline onsubmit string. Delegated, so it also
    // covers the subtopic forms injected below.
    document.addEventListener('submit', e => {
        const f = e.target.closest('form.js-confirm');
        if (f && !window.confirm(f.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });

    // Subtopics load when a topic is first opened. Mathematics has 286 of them;
    // rendering every one up front made this page 844KB.
    document.querySelectorAll('details.topic').forEach(d => {
        const pane = d.querySelector('.subtopics');
        const load = async () => {
            if (!pane || pane.dataset.loaded === '1') return;
            pane.dataset.loaded = '1';
            try {
                const r = await fetch('admin_curriculum.php?ajax=subtopics&topic_id=' + encodeURIComponent(d.dataset.topicId),
                                      { credentials: 'same-origin' });
                if (!r.ok) throw new Error(r.status);
                pane.innerHTML = await r.text();
            } catch (err) {
                pane.dataset.loaded = '0';
                pane.innerHTML = '<p class="text-xs text-rose-400">Could not load subtopics — reopen to retry.</p>';
            }
        };
        d.addEventListener('toggle', () => { if (d.open) load(); });
        if (d.open) load(); // a topic search opens matches immediately
    });
})();
</script>
</body>
</html>
