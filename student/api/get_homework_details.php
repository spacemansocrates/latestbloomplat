<?php
// students/get_homework_details.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || (($_SESSION['user_role'] ?? '') !== 'Student')) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'message'=>'Unauthorized']);
    exit;
}

// PHP holds an exclusive lock on the session file for the whole request,
// so concurrent AJAX from one page queues up instead of running in
// parallel. Nothing below writes $_SESSION (reads still work), so the
// lock can go now. Same reasoning as student/availability.php.
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';

require_once __DIR__ . '/../../utils/timezone_helper.php';
// getUserTimezone() reads $_SESSION['timezone'], which authenticate.php
// populates at login and settings.php keeps current, and validates it.
// This used to read $_SESSION['user_timezone'] - a key nothing ever sets,
// so it always fell through to the hardcoded fallback below it.
date_default_timezone_set(getUserTimezone());

$student_id  = (int)$_SESSION['user_id'];
$homework_id = (int)($_GET['homework_id'] ?? 0);
if ($homework_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing homework_id']);
    exit;
}

try {
    // Homework + tutor (ownership enforced)
    $st = $pdo->prepare("
        SELECT h.id, h.title, h.description, h.due_date,
               u.first_name, u.last_name
        FROM homework h
        JOIN users u ON u.id = h.tutor_id
        WHERE h.id = ? AND h.student_id = ?
        LIMIT 1
    ");
    $st->execute([$homework_id, $student_id]);
    $hw = $st->fetch();
    if (!$hw) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Homework not found']);
        exit;
    }

    // Attachments from tutor (DB has file_url, not file_path)
    $a = $pdo->prepare("
        SELECT id, file_name, file_url, uploaded_at
        FROM homework_attachments
        WHERE homework_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $a->execute([$homework_id]);
    $attachments = [];
    while ($row = $a->fetch()) {
        $attachments[] = [
            'id'          => (int)$row['id'],
            'file_name'   => (string)$row['file_name'],
            // route through the downloader; inline so the UI "View" opens in a new tab.
            // The browser resolves this relative to the page (student/homeworks.php),
            // so it needs the api/ prefix: student/ -> api/download.php.
            'file_url'    => "api/download.php?type=homework&id=".(int)$row['id']."&disposition=inline",
            'uploaded_at' => formatLocalDatetime($row['uploaded_at'] ?? null), // UTC column: convert, don't reinterpret
        ];
    }

    // Student's submission (one per (homework_id, student_id) by unique key)
    $s = $pdo->prepare("
        SELECT id, status, submission_date, grade, tutor_feedback, content
        FROM homework_submissions
        WHERE homework_id = ? AND student_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $s->execute([$homework_id, $student_id]);
    $submission = $s->fetch();

    $submissionOut = null;
    if ($submission) {
        $sf = $pdo->prepare("
            SELECT id, file_name, file_url, uploaded_at
            FROM submission_attachments
            WHERE submission_id = ?
            ORDER BY uploaded_at DESC, id DESC
        ");
        $sf->execute([(int)$submission['id']]);
        $sfiles = [];
        while ($row = $sf->fetch()) {
            $sfiles[] = [
                'id'          => (int)$row['id'],
                'file_name'   => (string)$row['file_name'],
                // same idea: inline to "View"; the UI already builds a separate Download link
                'file_url'    => "api/download.php?type=submission&id=".(int)$row['id']."&disposition=inline",
                'uploaded_at' => formatLocalDatetime($row['uploaded_at'] ?? null), // UTC column: convert, don't reinterpret
            ];
        }
        $submissionOut = [
            'id'               => (int)$submission['id'],
            'status'           => (string)$submission['status'],
            'submitted_pretty' => formatLocalDatetime($submission['submission_date'] ?? null), // UTC column
            'grade'            => $submission['grade'],
            'tutor_feedback'   => $submission['tutor_feedback'],
            'content'          => $submission['content'],
            'files'            => $sfiles,
        ];
    }

    echo json_encode([
        'success'     => true,
        'homework'    => [
            'id'          => (int)$hw['id'],
            'title'       => (string)$hw['title'],
            'description' => (string)$hw['description'],
            // Deliberately NOT formatLocalDatetime(): due_date holds the naive
            // local string the tutor's datetime-local input produced, not a UTC
            // instant, so converting it would shift every existing due date.
            'due_pretty'  => $hw['due_date'] ? date('M j, Y g:i A', strtotime($hw['due_date'])) : '—',
            'tutor_name'  => trim($hw['first_name'].' '.$hw['last_name']),
        ],
        'attachments' => $attachments,
        'submission'  => $submissionOut
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error loading homework details.']);
}
