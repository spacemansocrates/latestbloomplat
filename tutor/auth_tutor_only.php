<?php
// tutor/auth_tutor_only.php
require_once __DIR__ . '/../utils/profile_photo.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Tutor') {
    // Check if it's an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    } else {
        header('Location: ../login.php');
        exit();
    }
}

$tutor_id   = (int)$_SESSION['user_id'];
$tutor_name = trim(($_SESSION['user_first_name'] ?? '') . ' ' . ($_SESSION['user_last_name'] ?? ''));
if ($tutor_name === '') $tutor_name = 'Tutor';
$tutor_role = $_SESSION['user_role'] ?? 'Tutor';

// Force tutors to choose their timezone before using the app. Lesson times are
// shown in each user's own zone, so a tutor without one can't be shown correct
// times. Only gate real page navigations (not AJAX/API/JSON), and exempt the
// settings page to avoid a redirect loop.
//
// NOTE: this require MUST be at global scope (not inside a function). require_once
// consumes PHP's include-once guard, so if we required it inside a closure the
// including page's own `require_once config/db_connect.php` would no-op and never
// receive $pdo. At global scope, $pdo is shared with the page as intended.
if (
    strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false
    && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
    && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'settings.php'
) {
    require_once __DIR__ . '/../config/db_connect.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $__tzGateStmt = $pdo->prepare("SELECT timezone FROM users WHERE id = ? LIMIT 1");
            $__tzGateStmt->execute([(int)$_SESSION['user_id']]);
            $__tzGateVal = (string)$__tzGateStmt->fetchColumn();
            if ($__tzGateVal === '' || !in_array($__tzGateVal, DateTimeZone::listIdentifiers(), true)) {
                header('Location: settings.php?need_tz=1');
                exit();
            }
            unset($__tzGateStmt, $__tzGateVal);
        } catch (Throwable $e) { /* never block a page on a DB hiccup */ }
    }
}

if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dtfmt')) {
    function dtfmt($iso, $fmt='M j, Y g:i a') {
        $t = strtotime((string)$iso);
        return $t ? date($fmt, $t) : '';
    }
}
