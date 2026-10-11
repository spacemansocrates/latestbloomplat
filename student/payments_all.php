<?php
// students/payments_all.php

// 1) Session & auth
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}
$student_id   = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_first_name'] ?? 'Student';

// 2) DB
require_once __DIR__ . '/../config/db_connect.php';

// 3) Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_symbol($code){
    static $m=['USD'=>'$','EUR'=>'€','GBP'=>'£','MWK'=>'MK','ZAR'=>'R','KES'=>'KSh','TZS'=>'TSh'];
    return $m[$code] ?? ($code.' ');
}
function status_pill($status){
    $status=(string)$status;
    if ($status==='Completed') return ['Completed','bg-emerald-500/10 text-emerald-400'];
    if ($status==='Pending')   return ['Pending','bg-amber-500/10 text-amber-400'];
    if ($status==='Failed')    return ['Failed','bg-rose-500/10 text-rose-400'];
    return [$status,'bg-gray-500/10 text-gray-300'];
}

// 4) Currency from user
$stmt=$pdo->prepare("SELECT currency_code FROM users WHERE id=?");
$stmt->execute([$student_id]);
$currency_code=$stmt->fetchColumn() ?: 'USD';
$C=money_symbol($currency_code);

// 5) Pull *all* payments for this student (with package names)
$stmt=$pdo->prepare("
  SELECT pay.id, pay.amount, pay.payment_date, pay.payment_method, pay.status, pay.transaction_id,
         p.name AS package_name
  FROM payments pay
  JOIN student_packages sp ON sp.id = pay.student_package_id
  JOIN packages p ON p.id = sp.package_id
  WHERE sp.student_id = ?
  ORDER BY pay.payment_date DESC, pay.id DESC
");
$stmt->execute([$student_id]);
$rows=$stmt->fetchAll();

$current_page='payments'; // highlight in sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>All Payments</title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <?php include __DIR__ . '/../components/ui_head_boot.php'; ?>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
<style>
</style>
</head>
<body class="flex min-h-screen">
  <?php 
  // Include centralized page loader component (if not already included by sidebar)
  if (!defined('PAGE_LOADER_INCLUDED')) {
    define('PAGE_LOADER_INCLUDED', true);
    include __DIR__ . '/../components/page_loader.php';
  }
  ?>

  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <main class="flex-1 p-6 lg:p-8">
    <!-- Header Area -->
    <?php
    $header_config = [
        'page_title' => 'Payment History',
        'page_subtitle' => 'A full record of your payments.'
    ];
    include __DIR__ . '/components/header.php';
    ?>

    <div class="card p-6">
      <?php if (empty($rows)): ?>
        <p class="text-slate-400">No payments yet.</p>
      <?php else: ?>
        <div class="grid grid-cols-6 gap-4 text-sm font-semibold text-slate-400 px-4 pb-2 border-b border-slate-800">
          <div class="col-span-2">Package</div>
          <div class="text-right">Amount</div>
          <div class="text-center">Method</div>
          <div class="text-center">Status</div>
          <div class="text-right">Date</div>
        </div>
        <div class="space-y-2 mt-2">
          <?php foreach ($rows as $r):
            [$txt,$cls]=status_pill($r['status']);
            $amt=(float)$r['amount'];
          ?>
          <div class="grid grid-cols-6 gap-4 items-center bg-slate-900/50 p-4 rounded-lg">
            <div class="col-span-2">
              <p class="text-white font-semibold"><?= h($r['package_name']) ?></p>
              <?php if (!empty($r['transaction_id'])): ?>
                <p class="text-xs text-slate-500 mt-1">Txn: <?= h($r['transaction_id']) ?></p>
              <?php endif; ?>
            </div>
            <div class="text-right font-bold text-white"><?= h($C . number_format($amt,2)) ?></div>
            <div class="text-center text-slate-300"><?= h($r['payment_method']) ?></div>
            <div class="text-center">
              <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?= $cls ?>"><?= h($txt) ?></span>
            </div>
            <div class="text-right text-slate-300"><?= (new DateTime($r['payment_date']))->format('M j, Y g:i A') ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
