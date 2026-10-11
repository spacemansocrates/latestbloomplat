<?php
// student/api/assessment.php
//
// Autosave and submit for student/assessment.php. JSON in, JSON out.
//   { csrf_token, attempt_id, action: "save" | "submit", answers: {qid: answer} }
// Answers are cleaned against the bank before they are stored, and only while the
// attempt is still open. Nothing here returns scores or the answer key.

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

function asm_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') asm_reply(405, ['ok' => false, 'error' => 'POST only']);
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') asm_reply(403, ['ok' => false, 'error' => 'Please log in again']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) asm_reply(400, ['ok' => false, 'error' => 'Bad request']);

$token = $in['csrf_token'] ?? null;
if (!is_string($token) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    asm_reply(403, ['ok' => false, 'error' => 'Your session has expired. Please refresh the page.']);
}
$student_id = (int)$_SESSION['user_id'];
session_write_close();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../utils/assessment.php';

$attempt_id = (int)($in['attempt_id'] ?? 0);
$action = (string)($in['action'] ?? '');
if (!in_array($action, ['save', 'submit'], true)) asm_reply(400, ['ok' => false, 'error' => 'Unknown action']);

$st = $pdo->prepare("SELECT id, tutor_id, assessment_id, assessment_version, status
                     FROM assessment_attempts WHERE id = ? AND student_id = ?");
$st->execute([$attempt_id, $student_id]);
$attempt = $st->fetch(PDO::FETCH_ASSOC);
if (!$attempt) asm_reply(404, ['ok' => false, 'error' => 'Assessment not found']);
if (!in_array($attempt['status'], ['assigned', 'in_progress'], true)) {
    asm_reply(409, ['ok' => false, 'error' => 'This assessment has already been submitted']);
}

$a = asm_load($attempt['assessment_id'], (int)$attempt['assessment_version']);
if (!$a) asm_reply(500, ['ok' => false, 'error' => 'Assessment unavailable']);

$answers = asm_clean_answers($a, is_array($in['answers'] ?? null) ? $in['answers'] : []);
$json = json_encode($answers, JSON_UNESCAPED_UNICODE);

if ($action === 'save') {
    // The status guard in the WHERE makes a save that races a submit a no-op
    // instead of rewriting answers the tutor may already be reading.
    $pdo->prepare("UPDATE assessment_attempts
                   SET answers = ?, status = 'in_progress', started_at = COALESCE(started_at, NOW())
                   WHERE id = ? AND status IN ('assigned','in_progress')")
        ->execute([$json, $attempt_id]);
    asm_reply(200, ['ok' => true, 'saved' => count($answers)]);
}

$up = $pdo->prepare("UPDATE assessment_attempts
                     SET answers = ?, status = 'submitted', submitted_at = NOW(), started_at = COALESCE(started_at, NOW())
                     WHERE id = ? AND status IN ('assigned','in_progress')");
$up->execute([$json, $attempt_id]);
if ($up->rowCount() !== 1) asm_reply(409, ['ok' => false, 'error' => 'This assessment has already been submitted']);

// Let the tutor know it is ready to review. Never block the submit on this.
if (!empty($attempt['tutor_id'])) {
    try {
        $name = trim(($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? ''));
        $pdo->prepare("INSERT INTO notifications (user_id, type, message, related_entity_id, is_read, created_at)
                       VALUES (?, 'AssessmentSubmitted', ?, ?, 0, UTC_TIMESTAMP())")
            ->execute([(int)$attempt['tutor_id'], ($name !== '' ? $name : 'A student') . ' submitted the ' . $a['title'] . '. It is ready for you to review.', $attempt_id]);
    } catch (Throwable $e) {
        error_log('assessment submit notification: ' . $e->getMessage());
    }
}

asm_reply(200, ['ok' => true, 'submitted' => true]);
