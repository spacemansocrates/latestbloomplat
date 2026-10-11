<?php
// tutor/get_submission_details.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || (($_SESSION['user_role'] ?? '') !== 'Tutor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../utils/timezone_helper.php';
// getUserTimezone() reads $_SESSION['timezone'], which authenticate.php
// populates at login and settings.php keeps current, and validates it.
// This used to read $_SESSION['user_timezone'] - a key nothing ever sets,
// so it always fell through to the hardcoded fallback below it.
date_default_timezone_set(getUserTimezone());

// ---- DB ----
require_once __DIR__ . '/../../config/db_connect.php';

$tutor_id      = (int)$_SESSION['user_id'];
$homework_id   = (int)($_GET['homework_id'] ?? 0);
$submission_id = (int)($_GET['submission_id'] ?? 0);

if ($homework_id <= 0 || $submission_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    // Verify tutor owns this exact homework + submission pair
    $own = $pdo->prepare("
        SELECT 1
        FROM homework h
        JOIN homework_submissions hs ON hs.homework_id = h.id AND hs.student_id = h.student_id
        WHERE h.id = ? AND hs.id = ? AND h.tutor_id = ?
        LIMIT 1
    ");
    $own->execute([$homework_id, $submission_id, $tutor_id]);
    if (!$own->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not allowed']);
        exit;
    }

    // Homework details
    $hw = $pdo->prepare("SELECT id, title, description, due_date FROM homework WHERE id = ? LIMIT 1");
    $hw->execute([$homework_id]);
    $homework = $hw->fetch();
    if (!$homework) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Homework not found']);
        exit;
    }

    // Submission + student
    $sub = $pdo->prepare("
        SELECT
            hs.id, hs.homework_id, hs.student_id, hs.submission_date, hs.content,
            hs.status, hs.grade, hs.tutor_feedback, hs.graded_at,
            u.first_name, u.last_name
        FROM homework_submissions hs
        JOIN users u ON u.id = hs.student_id
        WHERE hs.id = ? AND hs.homework_id = ?
        LIMIT 1
    ");
    $sub->execute([$submission_id, $homework_id]);
    $submission = $sub->fetch();
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Submission not found']);
        exit;
    }

    // Submitted files: link to the shared downloader (it enforces per-lesson
    // permissions and grants the owning tutor access for type=submission).
    // NOTE: this URL is resolved by the browser relative to the PAGE that renders
    // it (tutor/homework.php, i.e. the tutor/ directory) — not this API file — so
    // it is one level up: tutor/ -> ../student/api/download.php.
    $DOWNLOAD_ENDPOINT = '../student/api/download.php';

    $attQ = $pdo->prepare("
        SELECT id, file_name, uploaded_at
        FROM submission_attachments
        WHERE submission_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $attQ->execute([$submission_id]);

    $attachments = [];
    while ($row = $attQ->fetch()) {
        $attachments[] = [
            'id'          => (int)$row['id'],
            'file_name'   => (string)$row['file_name'],
            // View inline in a new tab; downloader enforces permissions
            'file_url'    => $DOWNLOAD_ENDPOINT . '?type=submission&id=' . (int)$row['id'] . '&disposition=inline',
            // ISO-8601 UTC so the browser's new Date() localises it correctly
            'uploaded_at' => $row['uploaded_at'] ? str_replace(' ', 'T', $row['uploaded_at']) . 'Z' : null,
        ];
    }

    // The tutor's OWN files attached to the assignment (so they can preview what
    // they set). download.php grants the owning tutor access for type=homework.
    $haQ = $pdo->prepare("
        SELECT id, file_name, uploaded_at
        FROM homework_attachments
        WHERE homework_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $haQ->execute([$homework_id]);
    $assignment_files = [];
    while ($row = $haQ->fetch()) {
        $assignment_files[] = [
            'id'        => (int)$row['id'],
            'file_name' => (string)$row['file_name'],
            'file_url'  => $DOWNLOAD_ENDPOINT . '?type=homework&id=' . (int)$row['id'] . '&disposition=inline',
        ];
    }

    echo json_encode([
        'success'   => true,
        'homework'  => [
            'id'          => (int)$homework['id'],
            'title'       => (string)$homework['title'],
            'description' => (string)$homework['description'],
            'due_date'    => $homework['due_date'],
        ],
        'submission' => [
            'id'              => (int)$submission['id'],
            'student_id'      => (int)$submission['student_id'],
            'student_name'    => trim(($submission['first_name'] ?? '') . ' ' . ($submission['last_name'] ?? '')),
            'submission_date' => $submission['submission_date'] ? str_replace(' ', 'T', $submission['submission_date']) . 'Z' : null,
            'content'         => $submission['content'],
            'status'          => $submission['status'],
            'grade'           => $submission['grade'],
            'tutor_feedback'  => $submission['tutor_feedback'],
            'graded_at'       => $submission['graded_at'],
        ],
        'attachments' => $attachments,
        'assignment_files' => $assignment_files
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[submission details] tutor=' . $tutor_id . ' homework=' . $homework_id . ' submission=' . $submission_id . ' code=' . $e->getCode());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
