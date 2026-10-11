<?php
// grade_submission.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Tutor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Use POST']); exit; }
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'That form expired. Refresh and try again.']); exit;
}
// DB
require_once __DIR__ . '/../../config/db_connect.php';

$tutor_id      = (int)$_SESSION['user_id'];
$submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
$grade_raw     = trim($_POST['grade'] ?? '');
$feedback      = trim($_POST['feedback'] ?? '');

if ($submission_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid submission id']);
    exit();
}

try {

// Ensure submission belongs to a homework owned by this tutor
$chk = $pdo->prepare("
    SELECT hs.homework_id, h.tutor_id
    FROM homework_submissions hs
    JOIN homework h ON h.id = hs.homework_id AND h.student_id = hs.student_id
    WHERE hs.id = ?
    LIMIT 1
");
$chk->execute([$submission_id]);
$row = $chk->fetch();
if (!$row || (int)$row['tutor_id'] !== $tutor_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not allowed']);
    exit();
}

// Parse grade into decimal if possible
$grade_val = null;
if ($grade_raw !== '') {
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*$/', $grade_raw, $m)) {
        // x / y → percentage
        $x = (float)$m[1];
        $y = (float)$m[2];
        if ($y > 0) {
            $grade_val = round(($x / $y) * 100, 2);
        }
    } elseif (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $grade_raw, $m)) {
        $grade_val = round((float)$m[1], 2);
    }
    // else: non-numeric like "A+" => leave NULL
}

if ($grade_raw !== '' && ($grade_val === null || $grade_val < 0 || $grade_val > 100)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Enter a grade from 0 to 100, a percentage, or a fraction such as 18/20.']); exit;
}
// Update submission
if ($grade_val !== null) {
    $upd = $pdo->prepare("
        UPDATE homework_submissions
        SET grade = ?, tutor_feedback = ?, status = 'Graded', graded_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $upd->execute([$grade_val, $feedback, $submission_id]);
} else {
    $upd = $pdo->prepare("
        UPDATE homework_submissions
        SET tutor_feedback = ?, status = 'Graded', graded_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $upd->execute([$feedback, $submission_id]);
}

// Your AFTER UPDATE trigger will notify the student when grade transitions NULL → value.
echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('[submission grade] tutor=' . $tutor_id . ' submission=' . $submission_id . ' code=' . $e->getCode());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save this grade. Please try again.']);
    exit();
}
