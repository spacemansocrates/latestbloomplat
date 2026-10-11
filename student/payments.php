<?php
// payments.php
// Check if we're being included as a tab or standalone
$is_tab = isset($is_tab) ? $is_tab : false;
// Include database connection if not already included
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db_connect.php'; 
}
// Make sure we have a student_id
if (!isset($student_id) && isset($_SESSION['user_id'])) {
    $student_id = (int)$_SESSION['user_id'];
}
// Only proceed if we have a valid student_id
if (!isset($student_id)) {
    if (!$is_tab) {
        // If this is a standalone page, redirect to login
        header('Location: ../login.php');
        exit();
    }
    // If included as a tab, just return without doing anything
    return;
}
// Rest of your payments.php code...

// 3) Helpers
function status_pill($status){
    $status = (string)$status;
    if ($status === 'Completed') return ['Completed','bg-emerald-500/10 text-emerald-400'];
    if ($status === 'Pending')   return ['Pending','bg-amber-500/10 text-amber-400'];
    if ($status === 'Failed')    return ['Failed','bg-rose-500/10 text-rose-400'];
    return [$status,'bg-gray-500/10 text-gray-300'];
}

function human_due($dt){
    if (!$dt) return '—';
    $now = new DateTime(); $due = new DateTime($dt);
    $diff = (int)$now->diff($due)->format('%r%a');
    if ($diff === 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff === -1) return 'Yesterday';
    return $diff > 0 ? ("In {$diff} days") : (abs($diff) . ' days ago');
}

// 4) Get student currency
$stmt = $pdo->prepare("SELECT currency_code FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$currency_code = $stmt->fetchColumn() ?: 'USD';
$C = money_symbol($currency_code);

// 5) Find most relevant package for payment
$stmt = $pdo->prepare("
  SELECT sp.id AS sp_id, sp.status AS sp_status,
         sp.start_date, sp.end_date,
         sp.total_lessons, sp.lessons_remaining,
         p.id AS package_id, p.name AS package_name,
         p.description AS package_desc, p.lessons_per_week,
         p.price, p.duration_days
  FROM student_packages sp
  JOIN packages p ON p.id = sp.package_id
  WHERE sp.student_id = ?
  ORDER BY FIELD(sp.status,'Pending','Active','Expired','Cancelled'), sp.start_date DESC
  LIMIT 1
");
$stmt->execute([$student_id]);
$current = $stmt->fetch();

// 6) Determine next payment due info
$next_due_date = null; $next_amount = null; $due_label = '—'; $can_pay_now = false;
if ($current) {
    $next_amount = (float)$current['price'];
    if ($current['sp_status'] === 'Pending') {
        $next_due_date = (new DateTime())->format('Y-m-d');
        $due_label = 'Due now';
        $can_pay_now = true;
    } elseif ($current['sp_status'] === 'Active') {
        $next_due_date = $current['end_date'];
        $due_label = human_due($next_due_date) . ' · ' . (new DateTime($next_due_date))->format('M j, Y');
        if ($current['end_date']) {
            $days = (int)(new DateTime())->diff(new DateTime($current['end_date']))->format('%r%a');
            $can_pay_now = ($days <= 7);
        }
    }
}

// 7) Load recent payments
$stmt = $pdo->prepare("
  SELECT pay.id, pay.amount, pay.payment_date, pay.payment_method, 
         pay.status, pay.transaction_id, p.name AS package_name
  FROM payments pay
  JOIN student_packages sp ON sp.id = pay.student_package_id
  JOIN packages p ON p.id = sp.package_id
  WHERE sp.student_id = ?
  ORDER BY pay.payment_date DESC, pay.id DESC
  LIMIT 30
");
$stmt->execute([$student_id]);
$history = $stmt->fetchAll();

$current_page = 'payments';
