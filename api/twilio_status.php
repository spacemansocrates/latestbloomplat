<?php
/**
 * api/twilio_status.php
 * Twilio's delivery updates for WhatsApp messages (sent, delivered, read, failed).
 *
 * Twilio calls this, not a browser, so there is no session. The request is trusted
 * only if its X-Twilio-Signature verifies against our auth token and the exact URL
 * we asked Twilio to call (whatsapp_status_callback_url(), the same function that
 * put it in the send), so nobody else can forge a "delivered".
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../integrations/twilio_whatsapp.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit;
}

$cfg = twilio_config($pdo);
$url = whatsapp_status_callback_url();
$sig = (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');

if ($cfg === null || $url === null || !twilio_signature_valid($cfg['token'], $url, $_POST, $sig)) {
    http_response_code(403);
    exit;
}

$sid    = (string)($_POST['MessageSid'] ?? '');
$status = strtolower((string)($_POST['MessageStatus'] ?? ''));
$code   = isset($_POST['ErrorCode']) ? (string)$_POST['ErrorCode'] : null;
$to     = (string)($_POST['To'] ?? '');
$to     = str_starts_with($to, 'whatsapp:') ? substr($to, 9) : $to;

if (!preg_match('/^(SM|MM)[0-9a-f]{32}$/i', $sid) || wa_status_rank($status) === 0) {
    http_response_code(400);
    exit;
}
if ($code !== null && !preg_match('/^\d{1,8}$/', $code)) $code = null;

try {
    wa_apply_status($pdo, $sid, $status, $code, preg_match('/^\+\d{8,15}$/', $to) ? $to : null);
} catch (Throwable $e) {
    error_log('[twilio_status] ' . $e->getMessage());
    http_response_code(500);
    exit;
}
http_response_code(204);
