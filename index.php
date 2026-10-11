<?php
// index.php — role router for Bloom
session_start();
date_default_timezone_set('Africa/Blantyre');

// If not logged in, go to login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['user_role'] ?? ''; // 'Administrator' | 'Tutor' | 'Student' | (future) 'MiniAdmin'

// Default destinations (edit if your filenames differ)
$DEST = [
    'admin'   => 'admin/admin_dashboard.php',
    'tutor'   => 'tutor/dashboard.php',
    'student' => 'students/dashboard.php',
];

// Determine admin access:
//  - Direct role: 'Administrator'
//  - Future role label: 'MiniAdmin' (if you add it)
//  - Or presence in admin_details (Super Admin / Admin / Academic Coordinator)
$is_admin = false;
if ($user_role === 'Administrator' || $user_role === 'MiniAdmin') {
    $is_admin = true;
} else {
    // Optional DB check: treat anyone in admin_details as admin entry point
    $db_host='127.0.0.1'; $db_name='systemic'; $db_user='root'; $db_pass='';
    try {
        $pdo = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
        $st = $pdo->prepare("SELECT 1 FROM admin_details WHERE user_id=? LIMIT 1");
        $st->execute([$user_id]);
        $is_admin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // If DB fails here, we’ll just fall back to session role
        $is_admin = ($user_role === 'Administrator');
    }
}

// Route
if ($is_admin) {
    header('Location: '.$DEST['admin']);
    exit();
}

if ($user_role === 'Tutor') {
    header('Location: '.$DEST['tutor']);
    exit();
}

if ($user_role === 'Student') {
    header('Location: '.$DEST['student']);
    exit();
}

// Fallback: unknown role → logout or login
header('Location: logout.php'); // or: header('Location: login.php');
exit();
