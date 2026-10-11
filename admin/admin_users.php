<?php
// admin_users.php — Admin user management: create, edit, activate, assign tutors,
// reset passwords, and manage a tutor's legacy external meeting link.

require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
require_once __DIR__ . '/../utils/phone.php';
require_once __DIR__ . '/../utils/timezone_helper.php';

// ---------------- Session & Auth ----------------
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? '';

$current_page = 'users';

// Times are stored as UTC instants and read by an admin in their own zone. This
// page used to date_default_timezone_set('Africa/Blantyre') and then hand raw DB
// strings to new DateTime(), so every "joined" date was rendered as though a UTC
// timestamp were Blantyre local time — off by the offset, and inconsistent with
// every other page in the app.
$admin_timezone = getUserTimezone();
$nowLocal       = getNowLocal($admin_timezone);

// ---------------- DB ----------------
// config/db_connect.php already built a pooled connection. Building a second PDO by
// hand here duplicated the connection and dropped DB_PORT from the DSN, so a host
// not on 3306 would fail on this page alone.
try {
  $pdo = getDbConnection();
} catch (Throwable $e) {
  error_log('admin_users DB error: ' . $e->getMessage());
  http_response_code(503);
  die('User management is temporarily unavailable. Please try again shortly.');
}

// ---------------- Admin authorization ----------------
$is_admin = false;
if ($user_role === 'Administrator') {
  $is_admin = true;
} else {
  $stmt = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? AND admin_role IN ('Super Admin','Admin','Academic Coordinator')");
  $stmt->execute([$user_id]);
  $is_admin = (bool)$stmt->fetchColumn();
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

// ---------------- CSRF ----------------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---------------- Helpers ----------------
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
// Same one-line reader admin_settings.php and admin_lessons.php each declare for
// themselves; guarded because those pages are never loaded alongside this one but
// a future include could change that.
if (!function_exists('get_setting')) {
  function get_setting(PDO $pdo, string $key, $default = null) {
    $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key`=?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    if ($v === false || $v === null || $v === '') return $default;
    return $v;
  }
}

$ROLES = ['Administrator','Tutor','Student','QualityControl'];
$ROLE_LABEL = [
  'Administrator' => 'Admin', 'Tutor' => 'Tutor',
  'Student' => 'Student', 'QualityControl' => 'QC',
];

$flash_success = [];
$flash_error   = [];
function add_success($msg){ global $flash_success; $flash_success[] = $msg; }
function add_error($msg){ global $flash_error; $flash_error[] = $msg; }

/**
 * Validate a tutor's external meeting link: https, and a host we recognise.
 *
 * zoom.us is still accepted because two tutors have a zoom.us link saved against
 * them from before the move to the built-in room. Rejecting it would make their
 * records unsaveable. Nothing in the app dials Zoom any more — student/schedule.php
 * only uses this as a join-URL fallback via COALESCE.
 */
function is_valid_meeting_link(string $url): bool {
  if ($url === '') return false;
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $parts  = parse_url($url);
  $scheme = $parts['scheme'] ?? '';
  $host   = $parts['host'] ?? '';
  if ($scheme !== 'https' || $host === '') return false;
  if (preg_match('/(^|\.)zoom\.us$/i', $host)) return true;
  if (strcasecmp($host, 'meet.google.com') === 0) return true;
  return false;
}
// The old message named Google Meet only, while the check also accepts zoom.us.
const MEETING_LINK_HELP = 'Meeting link must be an https Google Meet or Zoom URL.';

/** Number of admins who could still log in if $excludeId were switched off. */
function other_active_admins(PDO $pdo, int $excludeId): int {
  $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='Administrator' AND is_active=1 AND id <> ?");
  $s->execute([$excludeId]);
  return (int)$s->fetchColumn();
}

/** Normalise a submitted phone to E.164, or throw with the reason. */
function normalise_phone(string $phone): ?string {
  if ($phone === '') return null;
  $pr = phone_to_e164($phone);
  if (!$pr['ok']) throw new RuntimeException('Phone: ' . phone_reason_text($pr['reason']));
  return $pr['e164'];
}

// ---------------- POST Actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    // CSRF: every admin write action must carry a valid token.
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
      throw new RuntimeException('Invalid session token. Refresh the page and try again.');
    }

    // ----- Create User -----
    if ($action === 'create_user') {
      $role          = $_POST['role'] ?? '';
      $first         = trim($_POST['first_name'] ?? '');
      $last          = trim($_POST['last_name'] ?? '');
      $email         = trim($_POST['email'] ?? '');
      $phone         = trim($_POST['phone_number'] ?? '');
      $customer_code = trim($_POST['customer_code'] ?? '');
      $password      = $_POST['password'] ?? '';
      $meeting_link  = trim($_POST['meeting_link'] ?? '');

      if (!in_array($role, $ROLES, true))       throw new RuntimeException('Invalid role.');
      if ($first === '' || $last === '' || $email === '' || $password === '') {
        throw new RuntimeException('All required fields must be filled.');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');
      if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters long.');

      // users.email is UNIQUE (uk_email). Without this check a duplicate surfaced as
      // a raw "SQLSTATE[23000] ... Duplicate entry" dumped into the flash message.
      $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
      $s->execute([$email]);
      if ($s->fetchColumn()) throw new RuntimeException('That email address is already registered.');

      // Phone numbers were stored exactly as typed, which is how the users table
      // ended up with entries like "1" and "123" that no reminder can ever be sent
      // to. Normalise to E.164 here, or reject with a reason.
      $phone = normalise_phone($phone);

      if ($role === 'Tutor' && $meeting_link !== '' && !is_valid_meeting_link($meeting_link)) {
        throw new RuntimeException(MEETING_LINK_HELP);
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);

      // currency_code and timezone are NOT NULL with no default. Omitting them
      // passed on the non-strict staging database but failed outright on
      // production (STRICT_TRANS_TABLES): "Field 'currency_code' doesn't have a
      // default value", so creating any user from this page was a 500. The
      // organisation defaults are the same ones admin_settings.php manages.
      $defCurrency = (string)get_setting($pdo, 'org.default_currency', 'USD');
      $defTimezone = (string)get_setting($pdo, 'org.default_timezone', 'Africa/Blantyre');

      $stmt = $pdo->prepare("
        INSERT INTO users
          (first_name,last_name,email,password_hash,phone_number,customer_code,role,
           currency_code,timezone,is_active,created_at,updated_at)
        VALUES
          (?,?,?,?,?,?,?,?,?,1,NOW(),NOW())
      ");
      $stmt->execute([$first,$last,$email,$hash,$phone,$customer_code,$role,$defCurrency,$defTimezone]);

      // trg_after_user_insert_create_tutor creates tutor_details + tutor_metrics for
      // a new Tutor. The INSERT IGNOREs make that a belt-and-braces detail rather
      // than a dependency: without them, a database missing the trigger would accept
      // the meeting link into an UPDATE that matches no row and report success.
      if ($role === 'Tutor') {
        $newUid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO tutor_details (user_id) VALUES (?)")->execute([$newUid]);
        $pdo->prepare("INSERT IGNORE INTO tutor_metrics (tutor_id) VALUES (?)")->execute([$newUid]);
        if ($meeting_link !== '') {
          $pdo->prepare("UPDATE tutor_details SET google_meet_link=? WHERE user_id=?")
              ->execute([$meeting_link, $newUid]);
        }
      }

      add_success('User created successfully.');
    }

    // ----- Edit User -----
    // New. There was previously no way for an admin to correct anyone's name, email,
    // phone or customer code; admin_settings.php only edits the admin's own profile.
    if ($action === 'edit_user') {
      $uid           = (int)($_POST['user_id'] ?? 0);
      $first         = trim($_POST['first_name'] ?? '');
      $last          = trim($_POST['last_name'] ?? '');
      $email         = trim($_POST['email'] ?? '');
      $phone         = trim($_POST['phone_number'] ?? '');
      $customer_code = trim($_POST['customer_code'] ?? '');
      $role          = $_POST['role'] ?? '';
      $meeting_link  = trim($_POST['meeting_link'] ?? '');

      if ($uid <= 0) throw new RuntimeException('Invalid user.');
      if (!in_array($role, $ROLES, true)) throw new RuntimeException('Invalid role.');
      if ($first === '' || $last === '' || $email === '') {
        throw new RuntimeException('Name and email are required.');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

      $s = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
      $s->execute([$uid]);
      $target = $s->fetch();
      if (!$target) throw new RuntimeException('User not found.');

      $s = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
      $s->execute([$email, $uid]);
      if ($s->fetchColumn()) throw new RuntimeException('That email address belongs to another user.');

      $phone = normalise_phone($phone);

      // Demoting the last Administrator locks everyone out of this page.
      if ($target['role'] === 'Administrator' && $role !== 'Administrator'
          && (int)$target['is_active'] === 1 && other_active_admins($pdo, $uid) === 0) {
        throw new RuntimeException('This is the last active Administrator — promote someone else first.');
      }

      if ($meeting_link !== '' && !is_valid_meeting_link($meeting_link)) {
        throw new RuntimeException(MEETING_LINK_HELP);
      }

      $pdo->prepare("
        UPDATE users SET first_name=?, last_name=?, email=?, phone_number=?, customer_code=?, role=?, updated_at=NOW()
        WHERE id=?
      ")->execute([$first,$last,$email,$phone,$customer_code,$role,$uid]);

      // trg_after_user_insert_create_tutor only fires on INSERT, so promoting an
      // existing user to Tutor leaves them with no tutor_details/tutor_metrics row —
      // which the tutor dashboard and schedule both read. Backfill here.
      if ($role === 'Tutor') {
        $pdo->prepare("INSERT IGNORE INTO tutor_details (user_id) VALUES (?)")->execute([$uid]);
        $pdo->prepare("INSERT IGNORE INTO tutor_metrics (tutor_id) VALUES (?)")->execute([$uid]);
        $pdo->prepare("UPDATE tutor_details SET google_meet_link=? WHERE user_id=?")
            ->execute([$meeting_link !== '' ? $meeting_link : null, $uid]);
      }

      add_success('User updated.');
    }

    // ----- Toggle Active -----
    if ($action === 'toggle_active') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $val = (int)($_POST['is_active'] ?? 0) ? 1 : 0;

      $s = $pdo->prepare("SELECT id, role, first_name, last_name FROM users WHERE id=?");
      $s->execute([$uid]);
      $target = $s->fetch();
      // Previously this UPDATE ran unconditionally and reported success even for an
      // id that does not exist.
      if (!$target) throw new RuntimeException('User not found.');

      if ($val === 0) {
        // Two ways this page could lock every admin out of the platform.
        if ($uid === $user_id) {
          throw new RuntimeException('You cannot deactivate your own account.');
        }
        if ($target['role'] === 'Administrator' && other_active_admins($pdo, $uid) === 0) {
          throw new RuntimeException('This is the last active Administrator and cannot be deactivated.');
        }
      }

      $pdo->prepare("UPDATE users SET is_active=?, updated_at=NOW() WHERE id=?")->execute([$val,$uid]);
      add_success(($val ? 'Activated ' : 'Deactivated ') . $target['first_name'] . ' ' . $target['last_name'] . '.');
    }

    // ----- Assign Tutor to Student -----
    if ($action === 'assign_tutor') {
      $tutor_id   = (int)($_POST['tutor_id'] ?? 0);
      $student_id = (int)($_POST['student_id'] ?? 0);
      $start_date = $_POST['start_date'] ?? $nowLocal->format('Y-m-d');

      if ($tutor_id <= 0 || $student_id <= 0) throw new RuntimeException('Select both a tutor and a student.');
      if ($tutor_id === $student_id) throw new RuntimeException('A user cannot be assigned to themselves.');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) throw new RuntimeException('Invalid start date.');

      // The old check only counted that two rows came back — it never confirmed
      // which was which, so a student could be assigned to another student, or the
      // tutor and student fields swapped, and the row was written happily.
      $s = $pdo->prepare("SELECT id, role FROM users WHERE id IN (?,?)");
      $s->execute([$tutor_id, $student_id]);
      $roleById = [];
      foreach ($s as $r) { $roleById[(int)$r['id']] = $r['role']; }
      if (count($roleById) < 2) throw new RuntimeException('Tutor or student not found.');
      if (($roleById[$tutor_id] ?? '') !== 'Tutor')     throw new RuntimeException('The selected tutor is not a Tutor account.');
      if (($roleById[$student_id] ?? '') !== 'Student') throw new RuntimeException('The selected student is not a Student account.');

      $s = $pdo->prepare("SELECT id FROM tutor_student_assignments WHERE tutor_id=? AND student_id=?");
      $s->execute([$tutor_id,$student_id]);
      $existingId = $s->fetchColumn();

      if ($existingId) {
        $pdo->prepare("
          UPDATE tutor_student_assignments
          SET start_date=?, end_date=NULL, assigned_by_admin_id=?
          WHERE id=?
        ")->execute([$start_date,$user_id,$existingId]);
        add_success('Assignment updated (reactivated).');
      } else {
        $pdo->prepare("
          INSERT INTO tutor_student_assignments (tutor_id,student_id,assigned_by_admin_id,start_date,end_date)
          VALUES (?,?,?,?,NULL)
        ")->execute([$tutor_id,$student_id,$user_id,$start_date]);
        add_success('Tutor assigned to student.');
      }
    }

    // ----- End Assignment -----
    if ($action === 'end_assignment') {
      $assign_id = (int)($_POST['assignment_id'] ?? 0);
      $end_date  = $_POST['end_date'] ?? $nowLocal->format('Y-m-d');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) throw new RuntimeException('Invalid end date.');

      $s = $pdo->prepare("SELECT id, end_date FROM tutor_student_assignments WHERE id=?");
      $s->execute([$assign_id]);
      $a = $s->fetch();
      if (!$a) throw new RuntimeException('Assignment not found.');
      if ($a['end_date'] !== null) throw new RuntimeException('That assignment has already ended.');

      $pdo->prepare("UPDATE tutor_student_assignments SET end_date=? WHERE id=?")->execute([$end_date,$assign_id]);
      add_success('Assignment ended.');
    }

    // ----- Save/Update Tutor Meeting Link -----
    if ($action === 'save_meeting_link') {
      $tutor_id     = (int)($_POST['tutor_id'] ?? 0);
      $meeting_link = trim($_POST['meeting_link'] ?? '');

      if ($tutor_id <= 0) throw new RuntimeException('Select a tutor.');
      if ($meeting_link !== '' && !is_valid_meeting_link($meeting_link)) {
        throw new RuntimeException(MEETING_LINK_HELP);
      }

      $s = $pdo->prepare("SELECT role FROM users WHERE id=?");
      $s->execute([$tutor_id]);
      if (($s->fetchColumn() ?: '') !== 'Tutor') throw new RuntimeException('Selected user is not a tutor.');

      $pdo->prepare("INSERT IGNORE INTO tutor_details (user_id) VALUES (?)")->execute([$tutor_id]);
      $pdo->prepare("UPDATE tutor_details SET google_meet_link=? WHERE user_id=?")
          ->execute([$meeting_link !== '' ? $meeting_link : null, $tutor_id]);

      add_success($meeting_link === '' ? 'Meeting link cleared.' : 'Meeting link saved.');
    }

    // ----- Change User Password -----
    if ($action === 'change_user_password') {
      $target_user_id = (int)($_POST['user_id'] ?? 0);
      $new_password   = trim($_POST['new_password'] ?? '');

      if ($target_user_id <= 0) throw new RuntimeException('Invalid user.');
      if (strlen($new_password) < 8) throw new RuntimeException('Password must be at least 8 characters long.');

      // Changing your own password here would skip the current-password check that
      // admin_settings.php enforces, so send the admin there instead.
      if ($target_user_id === $user_id) {
        throw new RuntimeException('To change your own password, use Settings.');
      }

      $s = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id=?");
      $s->execute([$target_user_id]);
      $target_user = $s->fetch();
      if (!$target_user) throw new RuntimeException('User not found.');

      $hash = password_hash($new_password, PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")->execute([$hash, $target_user_id]);

      add_success('Password changed for ' . $target_user['first_name'] . ' ' . $target_user['last_name'] . '.');
    }

  } catch (Throwable $e) {
    add_error($e->getMessage());
  }
}

// ---------------- Filters, Search & Pagination ----------------
$role_filter = $_GET['role'] ?? 'All';
if (!in_array($role_filter, $ROLES, true)) $role_filter = 'All';
$q      = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active','inactive'], true) ? $_GET['status'] : '';
$page   = max(1, (int)($_GET['page'] ?? 1));

// Was hardcoded at 10, which turns 55 users into six pages with no way to widen it.
$PER_PAGE_OPTS = [10, 25, 50, 100];
$per_page = (int)($_GET['pp'] ?? 25);
if (!in_array($per_page, $PER_PAGE_OPTS, true)) $per_page = 25;

$where = [];
$args  = [];
if (in_array($role_filter, $ROLES, true)) { $where[] = "u.role = ?"; $args[] = $role_filter; }
if ($status === 'active')   $where[] = "u.is_active = 1";
if ($status === 'inactive') $where[] = "u.is_active = 0";
if ($q !== '') {
  // Search now also covers phone and customer code — finding a family by their
  // shared code is a common reason to search this page at all.
  $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ? OR u.customer_code LIKE ?)";
  $like = "%$q%";
  array_push($args, $like, $like, $like, $like, $like, $like);
}
$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where_sql");
$count_stmt->execute($args);
$total_records = (int)$count_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $per_page;

$per_page_int = (int)$per_page;
$offset_int   = (int)$offset;
$sql = "
  SELECT
    u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.created_at,
    u.phone_number, u.customer_code,
    COALESCE(td.total_lessons_conducted, 0) AS lessons_conducted,
    COALESCE(td.composite_score, 0.00) AS composite_score,
    td.google_meet_link AS meeting_link,
    (
      SELECT GROUP_CONCAT(CONCAT(t.first_name,' ',t.last_name) SEPARATOR ', ')
      FROM tutor_student_assignments a
      JOIN users t ON t.id=a.tutor_id
      WHERE a.student_id=u.id AND a.end_date IS NULL
    ) AS assigned_tutors
  FROM users u
  LEFT JOIN tutor_details td ON td.user_id = u.id
  $where_sql
  ORDER BY u.created_at DESC
  LIMIT $per_page_int OFFSET $offset_int
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$users = $stmt->fetchAll();

// ---------------- Counts for the tabs ----------------
$total_users  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$active_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$role_counts  = array_fill_keys($ROLES, 0);
foreach ($pdo->query("SELECT role, COUNT(*) c FROM users GROUP BY role") as $r) {
  if (array_key_exists($r['role'], $role_counts)) $role_counts[$r['role']] = (int)$r['c'];
}
$inactive_users = $total_users - $active_users;

// Tutors with no subjects can be handed lessons they are not set up to teach.
$tutors_no_subject = (int)$pdo->query("
  SELECT COUNT(*) FROM users u
  WHERE u.role='Tutor' AND u.is_active=1
    AND NOT EXISTS (SELECT 1 FROM tutor_subjects ts WHERE ts.tutor_id = u.id)
")->fetchColumn();

// Active students with no active tutor are the ones nobody is teaching.
$students_unassigned = (int)$pdo->query("
  SELECT COUNT(*) FROM users u
  WHERE u.role='Student' AND u.is_active=1
    AND NOT EXISTS (
      SELECT 1 FROM tutor_student_assignments a
      WHERE a.student_id = u.id AND a.end_date IS NULL
    )
")->fetchColumn();

// ---------------- Active assignments ----------------
// The header used to print count($active_assignments), which silently reported the
// LIMIT once it was reached. Count separately.
$ASSIGN_LIMIT = 60;
$active_assignments_total = (int)$pdo->query("SELECT COUNT(*) FROM tutor_student_assignments WHERE end_date IS NULL")->fetchColumn();
$active_assignments = $pdo->query("
  SELECT a.id, a.start_date,
         t.first_name AS t_first, t.last_name AS t_last,
         s.first_name AS s_first, s.last_name AS s_last
  FROM tutor_student_assignments a
  JOIN users t ON t.id=a.tutor_id
  JOIN users s ON s.id=a.student_id
  WHERE a.end_date IS NULL
  ORDER BY a.start_date DESC
  LIMIT $ASSIGN_LIMIT
")->fetchAll();

$all_tutors   = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Tutor' AND is_active=1 ORDER BY first_name, last_name")->fetchAll();
$all_students = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Student' AND is_active=1 ORDER BY first_name, last_name")->fetchAll();

/** Build a filter URL preserving the other filters. */
function filter_url(array $overrides = []): string {
  $base = [
    'role'   => $GLOBALS['role_filter'],
    'q'      => $GLOBALS['q'],
    'status' => $GLOBALS['status'],
    'pp'     => $GLOBALS['per_page'],
  ];
  $p = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null && $v !== 'All');
  return '?' . http_build_query($p);
}

// Column count for the empty-state row. Was a hardcoded colspan="10" while the real
// table is 5–7 columns wide, so the empty message sat in a phantom-width cell.
$col_count = 4 // name, contact, role, status
  + (($role_filter === 'Tutor'   || $role_filter === 'All') ? 2 : 0)
  + (($role_filter === 'Student' || $role_filter === 'All') ? 1 : 0)
  + 1; // actions
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin — Users</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #111827; color: #d1d5db; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #1f2937; }
    ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
    .form-input {
      background-color:#374151; border:1px solid #4b5563; color:#f3f4f6;
      border-radius:.5rem; padding:.6rem .9rem;
    }
    .form-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.2); }
    .form-input[readonly] { background-color:#1f2937; color:#9ca3af; }
    .card { background-color:#0f1623; border:1px solid rgba(255,255,255,.06); border-radius:.75rem; }
    .no-scrollbar::-webkit-scrollbar { display:none; }
    .no-scrollbar { -ms-overflow-style:none; scrollbar-width:none; }
  </style>
</head>
<body class="flex min-h-screen">
  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-5 lg:p-7 min-w-0">

    <header class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div>
        <h2 class="text-2xl font-bold text-white">Users</h2>
        <p class="text-gray-400 text-sm mt-0.5">
          <?php echo number_format($total_users); ?> accounts &middot;
          <?php echo number_format($active_users); ?> active<?php if ($inactive_users): ?>, <?php echo number_format($inactive_users); ?> inactive<?php endif; ?>
          &middot; <span title="Joined dates are shown in your timezone"><?php echo h($admin_timezone); ?></span>
        </p>
      </div>
      <div class="flex gap-3">
        <button id="createUserBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-5 rounded-lg transition flex items-center gap-2">
          <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-plus"></use></svg>
          Create User
        </button>
        <button id="assignTutorBtn" class="bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2.5 px-5 rounded-lg transition flex items-center gap-2">
          <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
          Assign Tutor
        </button>
      </div>
    </header>

    <?php if (!empty($flash_success) || !empty($flash_error)): ?>
      <div class="mb-5 space-y-2">
        <?php foreach ($flash_success as $msg): ?>
          <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($msg); ?></div>
        <?php endforeach; ?>
        <?php foreach ($flash_error as $msg): ?>
          <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($msg); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Two things worth acting on that the page never surfaced. -->
    <?php if ($students_unassigned || $tutors_no_subject): ?>
      <div class="flex flex-wrap gap-3 mb-5">
        <?php if ($students_unassigned): ?>
          <a href="<?php echo h(filter_url(['role'=>'Student','status'=>'active','q'=>'','page'=>null])); ?>"
             class="flex items-center gap-3 px-4 py-2.5 rounded-xl ring-1 ring-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 transition-colors">
            <span class="text-xl font-bold text-amber-300 leading-none"><?php echo $students_unassigned; ?></span>
            <span class="text-xs text-gray-200 leading-snug">active students<br>with no tutor assigned</span>
          </a>
        <?php endif; ?>
        <?php if ($tutors_no_subject): ?>
          <a href="admin_curriculum.php"
             class="flex items-center gap-3 px-4 py-2.5 rounded-xl ring-1 ring-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 transition-colors">
            <span class="text-xl font-bold text-sky-300 leading-none"><?php echo $tutors_no_subject; ?></span>
            <span class="text-xs text-gray-200 leading-snug">active tutors<br>with no subjects set</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Users table -->
    <div class="card overflow-hidden mb-5">
      <div class="flex flex-col lg:flex-row justify-between items-center p-3 gap-4 border-b border-gray-800">

        <!-- Role tabs. These carry the counts that used to occupy five large stat
             cards above the table, saying nothing the tabs could not. -->
        <div class="flex items-center gap-1 overflow-x-auto w-full lg:w-auto no-scrollbar">
          <?php
          $tabs = ['All' => $total_users];
          foreach ($ROLES as $r) { $tabs[$r] = $role_counts[$r]; }
          foreach ($tabs as $key => $count):
            $label  = ($key === 'All') ? 'All' : $ROLE_LABEL[$key];
            $active = ($role_filter === $key);
            $cls = $active
              ? 'bg-indigo-500/10 text-indigo-300 ring-1 ring-indigo-500/30'
              : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800/50 ring-1 ring-transparent';
          ?>
            <a href="<?php echo h(filter_url(['role'=>$key,'page'=>null])); ?>"
               class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all flex items-center gap-2 whitespace-nowrap <?php echo $cls; ?>">
              <span><?php echo h($label); ?></span>
              <span class="text-[11px] px-1.5 py-0.5 rounded-md <?php echo $active ? 'bg-indigo-500/20 text-indigo-200' : 'bg-gray-800 text-gray-500'; ?>"><?php echo number_format($count); ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <form method="get" class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
          <input type="hidden" name="role" value="<?php echo h($role_filter); ?>" />
          <div class="relative group flex-1 lg:w-56 lg:flex-none">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-4 w-4 text-gray-500 group-focus-within:text-indigo-400 transition-colors" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
            </div>
            <input type="text" name="q" value="<?php echo h($q); ?>"
                   placeholder="Name, email, phone, code…"
                   class="block w-full pl-10 pr-3 py-2 border border-gray-700 rounded-xl bg-gray-800/50 text-gray-300 placeholder-gray-500 focus:outline-none focus:bg-gray-900 focus:ring-1 focus:ring-indigo-500 sm:text-sm transition-all" />
          </div>
          <select name="status" class="form-input py-2 text-sm" onchange="this.form.submit()">
            <option value="">Any status</option>
            <option value="active"   <?php echo $status==='active'?'selected':''; ?>>Active</option>
            <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
          </select>
          <select name="pp" class="form-input py-2 text-sm" onchange="this.form.submit()" title="Rows per page">
            <?php foreach ($PER_PAGE_OPTS as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo $per_page===$opt?'selected':''; ?>><?php echo $opt; ?> / page</option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="p-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white transition-all active:scale-95" title="Search">
            <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-search"></use></svg>
          </button>
          <a href="admin_users.php" class="p-2 rounded-xl bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white transition-all active:scale-95" title="Reset filters">
            <svg width="20" height="20" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-x"></use></svg>
          </a>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-800/60 border-b border-gray-700/50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Name</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Contact</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Role</th>
              <?php if ($role_filter === 'Tutor' || $role_filter === 'All'): ?>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Lessons</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Score</th>
              <?php endif; ?>
              <?php if ($role_filter === 'Student' || $role_filter === 'All'): ?>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Assigned tutors</th>
              <?php endif; ?>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-300 uppercase tracking-wider">Status</th>
              <th class="px-4 py-3 text-right text-xs font-semibold text-gray-300 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-800/50">
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="<?php echo (int)$col_count; ?>" class="px-4 py-12 text-center text-gray-400">
                  <div class="flex flex-col items-center gap-2">
                    <svg class="text-gray-600" width="48" height="48" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-users"></use></svg>
                    <p class="text-sm">No users found.</p>
                    <p class="text-xs text-gray-500">Try adjusting your filters.</p>
                  </div>
                </td>
              </tr>
            <?php else: foreach ($users as $u):
              $isSelf   = ((int)$u['id'] === $user_id);
              $joined   = utcToLocal($u['created_at'], $admin_timezone);
              $fullName = $u['first_name'] . ' ' . $u['last_name'];
            ?>
              <tr class="hover:bg-gray-800/40 transition-colors">
                <td class="px-4 py-3.5">
                  <div class="flex flex-col">
                    <span class="font-semibold text-white">
                      <?php echo h($fullName); ?>
                      <?php if ($isSelf): ?><span class="text-[10px] font-medium text-indigo-300 ml-1">you</span><?php endif; ?>
                    </span>
                    <span class="text-xs text-gray-500">
                      Joined <?php echo h($joined->format('j M Y')); ?>
                      <?php if ($u['customer_code'] !== null && $u['customer_code'] !== ''): ?>
                        &middot; <span class="text-gray-400"><?php echo h($u['customer_code']); ?></span>
                      <?php endif; ?>
                    </span>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <div class="text-sm text-gray-300 truncate max-w-[16rem]"><?php echo h($u['email']); ?></div>
                  <div class="text-xs text-gray-500">
                    <?php if ($u['phone_number']): ?><?php echo h($u['phone_number']); ?><?php else: ?><span class="text-gray-600">no phone</span><?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-3.5">
                  <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?php
                    echo $u['role']==='Tutor' ? 'bg-indigo-500/20 text-indigo-300'
                      : ($u['role']==='Student' ? 'bg-emerald-500/20 text-emerald-300'
                        : ($u['role']==='QualityControl' ? 'bg-yellow-500/20 text-yellow-300'
                          : 'bg-pink-500/20 text-pink-300'));
                  ?>"><?php echo h($ROLE_LABEL[$u['role']] ?? $u['role']); ?></span>
                </td>
                <?php if ($role_filter === 'Tutor' || $role_filter === 'All'): ?>
                  <td class="px-4 py-3.5 text-sm">
                    <?php if ($u['role'] === 'Tutor'): ?>
                      <span class="font-medium text-white"><?php echo number_format((int)$u['lessons_conducted']); ?></span>
                      <?php if ($u['meeting_link']): ?>
                        <a href="<?php echo h($u['meeting_link']); ?>" target="_blank" rel="noopener noreferrer"
                           class="block text-[10px] text-gray-500 hover:text-indigo-300 truncate max-w-[9rem]"
                           title="<?php echo h($u['meeting_link']); ?>">
                          <?php echo h(parse_url($u['meeting_link'], PHP_URL_HOST) ?: 'external'); ?> link
                        </a>
                      <?php endif; ?>
                    <?php else: ?><span class="text-gray-600">&mdash;</span><?php endif; ?>
                  </td>
                  <td class="px-4 py-3.5 text-sm">
                    <?php if ($u['role'] === 'Tutor'): ?>
                      <span class="font-semibold text-indigo-400"><?php echo number_format((float)$u['composite_score'], 2); ?></span>
                    <?php else: ?><span class="text-gray-600">&mdash;</span><?php endif; ?>
                  </td>
                <?php endif; ?>
                <?php if ($role_filter === 'Student' || $role_filter === 'All'): ?>
                  <td class="px-4 py-3.5 text-sm">
                    <?php if ($u['role'] === 'Student'): ?>
                      <?php if ($u['assigned_tutors']): ?>
                        <span class="text-xs text-gray-300"><?php echo h($u['assigned_tutors']); ?></span>
                      <?php else: ?>
                        <span class="text-xs text-amber-400/80">none</span>
                      <?php endif; ?>
                    <?php else: ?><span class="text-gray-600">&mdash;</span><?php endif; ?>
                  </td>
                <?php endif; ?>
                <td class="px-4 py-3.5">
                  <?php if ((int)$u['is_active']): ?>
                    <span class="text-xs font-medium bg-emerald-500/20 text-emerald-400 px-2.5 py-1 rounded-full">Active</span>
                  <?php else: ?>
                    <span class="text-xs font-medium bg-gray-600/50 text-gray-400 px-2.5 py-1 rounded-full">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button"
                            class="edit-user-btn text-xs font-semibold bg-gray-700/60 hover:bg-gray-600 text-gray-200 py-1.5 px-3 rounded-lg transition"
                            data-user="<?php echo h(json_encode([
                              'id'            => (int)$u['id'],
                              'first_name'    => $u['first_name'],
                              'last_name'     => $u['last_name'],
                              'email'         => $u['email'],
                              'phone_number'  => $u['phone_number'] ?? '',
                              'customer_code' => $u['customer_code'] ?? '',
                              'role'          => $u['role'],
                              'meeting_link'  => $u['meeting_link'] ?? '',
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">Edit</button>

                    <?php if (!$isSelf): ?>
                      <button type="button"
                              class="change-password-btn text-xs font-semibold bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 py-1.5 px-3 rounded-lg transition"
                              data-user-id="<?php echo (int)$u['id']; ?>"
                              data-user-name="<?php echo h($fullName); ?>">Password</button>

                      <form method="post" class="inline js-confirm"
                            data-confirm="<?php echo h(((int)$u['is_active'] ? 'Deactivate ' : 'Activate ') . $fullName . '?'); ?>">
                        <input type="hidden" name="action" value="toggle_active" />
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>" />
                        <input type="hidden" name="is_active" value="<?php echo (int)!$u['is_active']; ?>" />
                        <?php if ((int)$u['is_active']): ?>
                          <button class="text-xs font-semibold bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 py-1.5 px-3 rounded-lg transition">Deactivate</button>
                        <?php else: ?>
                          <button class="text-xs font-semibold bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 py-1.5 px-3 rounded-lg transition">Activate</button>
                        <?php endif; ?>
                      </form>
                    <?php else: ?>
                      <a href="admin_settings.php" class="text-xs font-semibold text-gray-500 hover:text-gray-300 py-1.5 px-3" title="Change your own password in Settings">Settings</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_records > 0): ?>
        <div class="bg-gray-800/40 border-t border-gray-700/50 px-4 py-3">
          <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="text-sm text-gray-400">
              Showing <span class="text-white font-medium"><?php echo number_format($offset + 1); ?></span>&ndash;<span class="text-white font-medium"><?php echo number_format(min($offset + $per_page, $total_records)); ?></span>
              of <span class="text-white font-medium"><?php echo number_format($total_records); ?></span>
              <?php if ($total_pages > 1): ?><span class="text-gray-500 ml-1">(page <?php echo $page; ?> of <?php echo $total_pages; ?>)</span><?php endif; ?>
            </div>

            <?php if ($total_pages > 1):
              $btn     = 'px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-700 hover:bg-gray-600 text-white transition';
              $btnOff  = 'px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-800 text-gray-600 cursor-not-allowed';
              $maxVis  = 7;
              $startPg = max(1, $page - (int)floor($maxVis / 2));
              $endPg   = min($total_pages, $startPg + $maxVis - 1);
              if ($endPg - $startPg < $maxVis - 1) $startPg = max(1, $endPg - $maxVis + 1);
            ?>
              <div class="flex items-center gap-1.5">
                <?php if ($page > 1): ?>
                  <a href="<?php echo h(filter_url(['page'=>$page-1])); ?>" class="<?php echo $btn; ?> inline-flex items-center gap-1">
                    <svg width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-left"></use></svg>Prev</a>
                <?php else: ?>
                  <span class="<?php echo $btnOff; ?>">Prev</span>
                <?php endif; ?>

                <?php if ($startPg > 1): ?>
                  <a href="<?php echo h(filter_url(['page'=>1])); ?>" class="<?php echo $btn; ?>">1</a>
                  <?php if ($startPg > 2): ?><span class="px-1 text-gray-600">&hellip;</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPg; $i <= $endPg; $i++): ?>
                  <?php if ($i === $page): ?>
                    <span class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-indigo-600 text-white"><?php echo $i; ?></span>
                  <?php else: ?>
                    <a href="<?php echo h(filter_url(['page'=>$i])); ?>" class="<?php echo $btn; ?>"><?php echo $i; ?></a>
                  <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPg < $total_pages): ?>
                  <?php if ($endPg < $total_pages - 1): ?><span class="px-1 text-gray-600">&hellip;</span><?php endif; ?>
                  <a href="<?php echo h(filter_url(['page'=>$total_pages])); ?>" class="<?php echo $btn; ?>"><?php echo $total_pages; ?></a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                  <a href="<?php echo h(filter_url(['page'=>$page+1])); ?>" class="<?php echo $btn; ?> inline-flex items-center gap-1">
                    Next<svg width="16" height="16" aria-hidden="true"><use href="../assets/icons/sprite.svg#i-chevron-right"></use></svg></a>
                <?php else: ?>
                  <span class="<?php echo $btnOff; ?>">Next</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Active assignments -->
    <section class="card p-5">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-semibold text-white">Active assignments</h3>
        <span class="text-sm text-gray-400">
          <?php if ($active_assignments_total > count($active_assignments)): ?>
            showing <?php echo count($active_assignments); ?> of <?php echo number_format($active_assignments_total); ?>
          <?php else: ?>
            <?php echo number_format($active_assignments_total); ?> active
          <?php endif; ?>
        </span>
      </div>
      <?php if (empty($active_assignments)): ?>
        <p class="text-gray-400 text-center py-8 text-sm">No active assignments.</p>
      <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          <?php foreach ($active_assignments as $a):
            $tName = trim($a['t_first'] . ' ' . $a['t_last']);
            $sName = trim($a['s_first'] . ' ' . $a['s_last']);
            // These initials were previously echoed unescaped. Names are user-editable
            // (student/settings.php stores them with only trim()), so a name beginning
            // with "<" was a stored-XSS vector on this page.
            $tInit = mb_strtoupper(mb_substr($a['t_first'], 0, 1));
            $sInit = mb_strtoupper(mb_substr($a['s_first'], 0, 1));
          ?>
            <div class="bg-gray-800/50 border border-gray-700/50 rounded-lg p-3.5 hover:bg-gray-800 transition-colors">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0 space-y-2">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="text-indigo-300 text-[11px] font-bold"><?php echo h($tInit); ?></span>
                    </div>
                    <p class="font-semibold text-white text-sm truncate"><?php echo h($tName); ?></p>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="text-emerald-300 text-[11px] font-bold"><?php echo h($sInit); ?></span>
                    </div>
                    <p class="font-semibold text-white text-sm truncate"><?php echo h($sName); ?></p>
                  </div>
                  <p class="text-[11px] text-gray-500">Since <?php echo h((new DateTime($a['start_date']))->format('j M Y')); ?></p>
                </div>
                <form method="post" class="flex-shrink-0 js-confirm"
                      data-confirm="<?php echo h('End the assignment between ' . $tName . ' and ' . $sName . '?'); ?>">
                  <input type="hidden" name="action" value="end_assignment" />
                  <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
                  <input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>" />
                  <input type="hidden" name="end_date" value="<?php echo h($nowLocal->format('Y-m-d')); ?>" />
                  <button class="text-xs font-semibold bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 py-1.5 px-3 rounded-lg transition">End</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <!-- Create User Modal -->
  <div id="createUserModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/70" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-5">
        <h3 class="text-xl font-bold text-white">Create new user</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
      </div>
      <form method="post" id="createUserForm" class="space-y-4">
        <input type="hidden" name="action" value="create_user" />
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">First name *</label><input class="form-input w-full" name="first_name" required /></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Last name *</label><input class="form-input w-full" name="last_name" required /></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Email *</label><input type="email" class="form-input w-full" name="email" required /></div>
          <div>
            <label class="block text-sm text-gray-300 mb-1.5">Phone</label>
            <input class="form-input w-full" name="phone_number" type="tel" inputmode="tel" placeholder="+265 999 12 34 56" />
            <p class="mt-1 text-xs text-gray-500">Include the country code. Needed for WhatsApp reminders.</p>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">Customer code (optional)</label>
          <input class="form-input w-full" name="customer_code" placeholder="e.g. FAMILY123" />
          <p class="text-xs text-gray-500 mt-1">Use the same code to link siblings or related students.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-300 mb-1.5">Role *</label>
            <select class="form-input w-full" name="role" id="createUserRole" required>
              <?php foreach (['Student','Tutor','Administrator','QualityControl'] as $r): ?>
                <option value="<?php echo h($r); ?>"><?php echo h($r === 'QualityControl' ? 'Quality Control' : $r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm text-gray-300 mb-1.5">Temporary password *</label>
            <input type="password" class="form-input w-full" name="password" minlength="8" required />
            <p class="text-xs text-gray-500 mt-1">At least 8 characters. You will need to pass this on yourself.</p>
          </div>
        </div>
        <!-- Restored. This markup was commented out, which left create_user's
             meeting-link branch and the whole save_meeting_link action unreachable,
             while student/schedule.php still falls back to this link to build a join
             URL — so six tutors had links nobody could change or clear. -->
        <div id="meetingLinkRow" class="hidden">
          <label class="block text-sm text-gray-300 mb-1.5">External meeting link (optional)</label>
          <input class="form-input w-full" name="meeting_link" placeholder="https://meet.google.com/..." />
          <p class="text-xs text-gray-500 mt-1">Legacy fallback only &mdash; lessons run in the built-in room. Google Meet or Zoom, https.</p>
        </div>
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-5 py-2.5 rounded-lg transition">Cancel</button>
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-5 py-2.5 rounded-lg transition font-semibold">Create user</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div id="editUserModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/70" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-5">
        <h3 class="text-xl font-bold text-white">Edit user</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
      </div>
      <form method="post" id="editUserForm" class="space-y-4">
        <input type="hidden" name="action" value="edit_user" />
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
        <input type="hidden" name="user_id" id="eu_id" />
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">First name *</label><input class="form-input w-full" name="first_name" id="eu_first" required /></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Last name *</label><input class="form-input w-full" name="last_name" id="eu_last" required /></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Email *</label><input type="email" class="form-input w-full" name="email" id="eu_email" required /></div>
          <div><label class="block text-sm text-gray-300 mb-1.5">Phone</label><input class="form-input w-full" name="phone_number" id="eu_phone" type="tel" inputmode="tel" placeholder="+265 999 12 34 56" /></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-300 mb-1.5">Customer code</label><input class="form-input w-full" name="customer_code" id="eu_code" /></div>
          <div>
            <label class="block text-sm text-gray-300 mb-1.5">Role *</label>
            <select class="form-input w-full" name="role" id="eu_role" required>
              <?php foreach (['Student','Tutor','Administrator','QualityControl'] as $r): ?>
                <option value="<?php echo h($r); ?>"><?php echo h($r === 'QualityControl' ? 'Quality Control' : $r); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">Promoting to Tutor creates their tutor record.</p>
          </div>
        </div>
        <div id="eu_meetingRow" class="hidden">
          <label class="block text-sm text-gray-300 mb-1.5">External meeting link</label>
          <input class="form-input w-full" name="meeting_link" id="eu_meeting" placeholder="https://meet.google.com/..." />
          <p class="text-xs text-gray-500 mt-1">Leave blank to clear. Legacy fallback only &mdash; lessons run in the built-in room.</p>
        </div>
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-5 py-2.5 rounded-lg transition">Cancel</button>
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-5 py-2.5 rounded-lg transition font-semibold">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Assign Tutor Modal -->
  <div id="assignTutorModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/70" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
      <div class="flex items-center justify-between mb-5">
        <h3 class="text-xl font-bold text-white">Assign tutor to student</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
      </div>
      <form method="post" id="assignTutorForm" class="space-y-4">
        <input type="hidden" name="action" value="assign_tutor" />
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">Tutor *</label>
          <select class="form-input w-full" name="tutor_id" required>
            <option value="">Select tutor</option>
            <?php foreach ($all_tutors as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['first_name'].' '.$t['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">Student *</label>
          <select class="form-input w-full" name="student_id" required>
            <option value="">Select student</option>
            <?php foreach ($all_students as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['first_name'].' '.$s['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">Start date *</label>
          <input type="date" class="form-input w-full" name="start_date" value="<?php echo h($nowLocal->format('Y-m-d')); ?>" required />
        </div>
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-700">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-5 py-2.5 rounded-lg transition">Cancel</button>
          <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-5 py-2.5 rounded-lg transition font-semibold">Assign</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Change Password Modal -->
  <div id="changePasswordModal" class="modal fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/70" data-close-modal></div>
    <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">Reset password</h3>
        <button type="button" data-close-modal class="text-gray-400 hover:text-gray-200 text-2xl leading-none">&times;</button>
      </div>
      <form method="post" id="changePasswordForm" class="space-y-4">
        <input type="hidden" name="action" value="change_user_password" />
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>" />
        <input type="hidden" name="user_id" id="cp_user_id" value="" />
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">User</label>
          <input type="text" id="cp_user_name" class="form-input w-full" readonly />
        </div>
        <div>
          <label class="block text-sm text-gray-300 mb-1.5">New password *</label>
          <input type="password" name="new_password" id="cp_new_password" class="form-input w-full" minlength="8" required />
          <p class="text-xs text-gray-500 mt-1">At least 8 characters. The user is not notified &mdash; tell them yourself.</p>
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" data-close-modal class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm px-4 py-2 rounded-lg">Change password</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // One open/close implementation for all four modals. Each previously carried
      // its own near-identical copy, and none of them closed on Escape.
      const open  = (el) => { if (el) { el.classList.remove('hidden'); el.classList.add('flex'); } };
      const close = (el) => {
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        const form = el.querySelector('form');
        if (form && el.dataset.keepValues !== '1') form.reset();
      };
      const closeAll = () => document.querySelectorAll('.modal').forEach(close);

      document.querySelectorAll('[data-close-modal]').forEach(el => {
        el.addEventListener('click', () => close(el.closest('.modal')));
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

      // Confirmations live in a data- attribute so the message never has to survive
      // being escaped into an inline onsubmit string.
      document.querySelectorAll('form.js-confirm').forEach(f => {
        f.addEventListener('submit', (e) => {
          if (!window.confirm(f.dataset.confirm || 'Are you sure?')) e.preventDefault();
        });
      });

      const createModal = document.getElementById('createUserModal');
      const editModal   = document.getElementById('editUserModal');
      const assignModal = document.getElementById('assignTutorModal');
      const pwModal     = document.getElementById('changePasswordModal');

      const createBtn = document.getElementById('createUserBtn');
      if (createBtn) createBtn.addEventListener('click', (e) => { e.preventDefault(); open(createModal); });
      const assignBtn = document.getElementById('assignTutorBtn');
      if (assignBtn) assignBtn.addEventListener('click', (e) => { e.preventDefault(); open(assignModal); });

      // Show the meeting-link field only for tutors, in both create and edit.
      const bindRoleToMeetingRow = (selectId, rowId) => {
        const sel = document.getElementById(selectId), row = document.getElementById(rowId);
        if (!sel || !row) return null;
        const sync = () => row.classList.toggle('hidden', sel.value !== 'Tutor');
        sel.addEventListener('change', sync);
        return sync;
      };
      const syncCreate = bindRoleToMeetingRow('createUserRole', 'meetingLinkRow');
      const syncEdit   = bindRoleToMeetingRow('eu_role', 'eu_meetingRow');
      if (syncCreate) syncCreate();

      document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          let u;
          try { u = JSON.parse(btn.dataset.user); } catch (err) { return; }
          document.getElementById('eu_id').value      = u.id;
          document.getElementById('eu_first').value   = u.first_name || '';
          document.getElementById('eu_last').value    = u.last_name || '';
          document.getElementById('eu_email').value   = u.email || '';
          document.getElementById('eu_phone').value   = u.phone_number || '';
          document.getElementById('eu_code').value    = u.customer_code || '';
          document.getElementById('eu_role').value    = u.role || 'Student';
          document.getElementById('eu_meeting').value = u.meeting_link || '';
          if (syncEdit) syncEdit();
          // Prefilled fields must survive the shared close() reset.
          editModal.dataset.keepValues = '1';
          open(editModal);
        });
      });

      document.querySelectorAll('.change-password-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          document.getElementById('cp_user_id').value   = btn.dataset.userId;
          document.getElementById('cp_user_name').value = btn.dataset.userName;
          document.getElementById('cp_new_password').value = '';
          pwModal.dataset.keepValues = '1';
          open(pwModal);
        });
      });
    });
  </script>
</body>
</html>
