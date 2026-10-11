<?php
require_once __DIR__ . '/config/db_connect.php'; // unified DB credentials (.env)
session_start(); // Start the session at the very beginning

// Database connection parameters
$host    = DB_HOST;
$db      = DB_NAME;
$user    = DB_USER;
$pass    = DB_PASSWORD;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=" . DB_PORT . ";dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $_SESSION['error_message'] = "Database connection error. Please try again later.";
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

// ---- Inputs ----
$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    $_SESSION['error_message'] = "Please enter both email and password.";
    header("Location: login.php");
    exit();
}

// ---- Fetch user ----
try {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, password_hash, role, is_active, profile_photo_url
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $u = $stmt->fetch();
} catch (Throwable $e) {
    error_log("Auth query failed: " . $e->getMessage());
    $_SESSION['error_message'] = "Something went wrong. Please try again.";
    header("Location: login.php");
    exit();
}

if (!$u) {
    $_SESSION['error_message'] = "Invalid email or password.";
    header("Location: login.php");
    exit();
}

// ---- Verify password ----
if (!password_verify($password, (string)$u['password_hash'])) {
    $_SESSION['error_message'] = "Invalid email or password.";
    header("Location: login.php");
    exit();
}

// ---- Active check ----
if (empty($u['is_active'])) {
    $_SESSION['error_message'] = "Your account is not active. Please contact support.";
    header("Location: login.php");
    exit();
}

// ---- Login successful: discard all previous account state ----
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['profile_photo_url'] = $u['profile_photo_url'] ?? null;
$_SESSION['user_id']         = (int)$u['id'];
$_SESSION['user_role']       = (string)$u['role'];
$_SESSION['user_first_name'] = (string)($u['first_name'] ?? '');
$_SESSION['user_last_name']  = (string)($u['last_name'] ?? '');
$_SESSION['last_login_time'] = time();

// ---- Load user's timezone into session ----
require_once __DIR__ . '/utils/timezone_helper.php';
initializeSessionTimezone($pdo, (int)$u['id']);

// ---- Redirect based on role ----
$role = (string)$u['role'];

if ($role === 'Student') {
    header("Location: student/dashboard.php");
    exit();
} elseif ($role === 'Tutor') {
    header("Location: tutor/dashboard.php");
    exit();
} elseif ($role === 'QualityControl' || $role === 'QC') {
    header("Location: qc/dashboard.php");
    exit();
} elseif ($role === 'Administrator') {
    header("Location: admin/admin_dashboard.php");
    exit();
}

// Unknown role fallback
$_SESSION['error_message'] = "Your account role is not recognized. Please contact support.";
header("Location: login.php");
exit();
?>
