<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// authz.php — central RBAC for admin area
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$db_host=DB_HOST; $db_name=DB_NAME; $db_user=DB_USER; $db_pass=DB_PASSWORD;
try {
  // DB_PORT was left out of this DSN, so the admin area could only ever reach a
  // server on the default 3306 even when .env said otherwise.
  $AUTHZ_PDO = new PDO("mysql:host=$db_host;port=" . DB_PORT . ";dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  die('DB error (authz): '.h($e->getMessage()));
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? ''; // may be "Administrator", "Tutor", etc.

// Pull admin_details roles for this user (including Mini Admin)
$st = $AUTHZ_PDO->prepare("SELECT admin_role FROM admin_details WHERE user_id=?");
$st->execute([$user_id]);
$adminRoles = array_map(fn($r)=>$r['admin_role'], $st->fetchAll());

// Flags
$IS_FULL_ADMIN = ($user_role === 'Administrator')
  || in_array('Super Admin', $adminRoles, true)
  || in_array('Admin', $adminRoles, true)
  || in_array('Academic Coordinator', $adminRoles, true);

$IS_MINI_ADMIN = in_array('Mini Admin', $adminRoles, true);

// Capability map
// Full Admin gets everything via wildcard '*'
$ROLE_CAPS = [
  'FULL' => ['*'],
  'MINI' => [
    'lessons.read', 'lessons.write', 'lessons.delete', // schedule/edit/delete lessons & demos
    'messages.read', 'messages.write',                  // messaging allowed
    'recordings.read', 'attendance.read',               // can see recordings + attendance details
  ],
];

// Resolve the current user’s capabilities
$CAPS = [];
if ($IS_FULL_ADMIN) {
  $CAPS = $ROLE_CAPS['FULL'];
} elseif ($IS_MINI_ADMIN) {
  $CAPS = $ROLE_CAPS['MINI'];
}

// Helpers
function can(string $cap): bool {
  global $CAPS;
  if (in_array('*', $CAPS, true)) return true;
  return in_array($cap, $CAPS, true);
}
function require_cap(string $cap): void {
  if (!can($cap)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#0f172a;color:#e5e7eb;font-family:Inter,sans-serif;"><div style="max-width:720px;margin:10vh auto;padding:24px;border:1px solid #334155;border-radius:12px;background:#0b1220"><h2 style="margin:0 0 8px">Access denied</h2><p style="color:#94a3b8">You do not have permission to view this page.</p></div></body>';
    exit;
  }
}
