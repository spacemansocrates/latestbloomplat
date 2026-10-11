<?php
// students/submit_homeworks.php
// Creates/updates a homework submission and uploads attachments.
// Returns JSON.

session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$student_id = (int)$_SESSION['user_id'];

// --- DB ---
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../integrations/file_storage.php';

// --- Inputs ---
$homework_id = (int)($_POST['homework_id'] ?? 0);
$message     = trim((string)($_POST['message'] ?? ''));

if ($homework_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid homework']); exit;
}

try {
    // 1) Ensure homework belongs to this student; fetch due_date
    $hw = $pdo->prepare("
        SELECT id, due_date
        FROM homework
        WHERE id = ? AND student_id = ?
        LIMIT 1
    ");
    $hw->execute([$homework_id, $student_id]);
    $homework = $hw->fetch();

    if (!$homework) {
        echo json_encode(['success' => false, 'message' => 'Homework not found']); exit;
    }

    // Existing submission check (protect graded submissions)
    $existingStmt = $pdo->prepare("
        SELECT id, status, grade
        FROM homework_submissions
        WHERE homework_id = ? AND student_id = ?
        LIMIT 1
    ");
    $existingStmt->execute([$homework_id, $student_id]);
    $existingSub = $existingStmt->fetch();
    if ($existingSub && ($existingSub['status'] === 'Graded' || $existingSub['grade'] !== null)) {
        echo json_encode(['success' => false, 'message' => 'This homework has already been graded and can no longer be updated.']); exit;
    }

    // 2) Determine status: Submitted vs Late
    $now = new DateTimeImmutable();
    $due = !empty($homework['due_date']) ? new DateTimeImmutable($homework['due_date']) : null;
    $status = ($due && $now > $due) ? 'Late' : 'Submitted';

    // 3) Upsert submission row (UNIQUE(homework_id, student_id))
    $up = $pdo->prepare("
        INSERT INTO homework_submissions (homework_id, student_id, submission_date, content, status)
        VALUES (?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            submission_date = VALUES(submission_date),
            content         = VALUES(content),
            status          = VALUES(status)
    ");
    $up->execute([$homework_id, $student_id, $message, $status]);

    // 4) Get submission id
    $subq = $pdo->prepare("
        SELECT id
        FROM homework_submissions
        WHERE homework_id = ? AND student_id = ?
        LIMIT 1
    ");
    $subq->execute([$homework_id, $student_id]);
    $submission_id = (int)$subq->fetchColumn();

    // 5) Handle attachments (optional, multi-file)
    $saved = [];
    if (!empty($_FILES['attachments'])) {
        // Normalize to arrays
        $names = (array)($_FILES['attachments']['name'] ?? []);
        $tmps  = (array)($_FILES['attachments']['tmp_name'] ?? []);
        $errs  = (array)($_FILES['attachments']['error'] ?? []);
        $sizes = (array)($_FILES['attachments']['size'] ?? []);

        // Ensure uploads root exists BEFORE realpath
        $uploads_fs = __DIR__ . '/../uploads';
        if (!is_dir($uploads_fs)) { @mkdir($uploads_fs, 0775, true); }
        $uploads_root = realpath($uploads_fs);
        if ($uploads_root === false) {
            echo json_encode(['success' => false, 'message' => 'Uploads folder not found']); exit;
        }

        $dest_dir = $uploads_root . DIRECTORY_SEPARATOR . 'submissions';
        if (!is_dir($dest_dir)) { @mkdir($dest_dir, 0775, true); }

        $max_bytes = 10 * 1024 * 1024; // 10 MB
        $allowed   = ['pdf','doc','docx','png','jpg','jpeg'];

        for ($i = 0, $cnt = count($names); $i < $cnt; $i++) {
            $name = (string)($names[$i] ?? '');
            $tmp  = (string)($tmps[$i]  ?? '');
            $err  = (int)($errs[$i]     ?? UPLOAD_ERR_NO_FILE);
            $size = (int)($sizes[$i]    ?? 0);

            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
            if ($size <= 0 || $size > $max_bytes) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;

            // Sanitize original filename for display
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
            // Store with a random prefix to avoid collisions
            $storedFile = bin2hex(random_bytes(8)) . '_' . $safeName;
            $absPath    = $dest_dir . DIRECTORY_SEPARATOR . $storedFile;

            if (!move_uploaded_file($tmp, $absPath)) continue;

            // IMPORTANT: store a web URL that download.php understands
            // Root-relative, no "/tutsys" prefix here:
            $file_url  = '/uploads/submissions/' . $storedFile;
            storage_offload($absPath, $file_url); // to bucket; stays local if it fails
            $file_name = $safeName;

            $ia = $pdo->prepare("
                INSERT INTO submission_attachments (submission_id, file_name, file_url)
                VALUES (?, ?, ?)
            ");
            $ia->execute([$submission_id, $file_name, $file_url]);

            $saved[] = [
                'file_name' => $file_name,
                'file_url'  => $file_url
            ];
        }
    }

    echo json_encode([
        'success'        => true,
        'submission_id'  => $submission_id,
        'status'         => $status,
        'attachments'    => $saved
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'detail' => $e->getMessage()]);
}
