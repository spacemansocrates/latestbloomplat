<?php
// students/pay_start.php
// Validates the student_package, records a pending payment row, and redirects to a hosted checkout URL
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php'); exit();
}
$student_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../../config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed');
}
// The Pay Now form carries the session token from student/settings.php. Without
// this, another site could create pending payment rows and bounce a student into
// checkout.
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $_POST['csrf_token'])) {
    http_response_code(403); exit('That form expired. Go back, reload the page and try again.');
}

$sp_id = (int)($_POST['sp_id'] ?? 0);
if ($sp_id <= 0) { http_response_code(400); exit('Bad request'); }

// Load the student_package + package (must belong to student)
$stmt = $pdo->prepare("
  SELECT sp.id AS sp_id, sp.status, p.id AS package_id, p.name, p.price
  FROM student_packages sp
  JOIN packages p ON p.id = sp.package_id
  WHERE sp.id = ? AND sp.student_id = ?
  LIMIT 1
");
$stmt->execute([$sp_id, $student_id]);
$sp = $stmt->fetch();
if (!$sp) { http_response_code(404); exit('Package not found'); }

// Only allow pay if Pending (new purchase) or if you want to allow early renewal on Active, permit here:
$allow_active_renewal = true;
if (!($sp['status'] === 'Pending' || ($allow_active_renewal && $sp['status'] === 'Active'))) {
    http_response_code(400); exit('This package is not payable right now.');
}

// Resolve hosted checkout URL from app_settings:
// Key pattern: checkout_url_pkg_{PACKAGE_ID}
// You can store either a PayChangu or Stripe Checkout URL — we just redirect to it.
$keys = [
  "checkout_url_pkg_" . (int)$sp['package_id'],
  "paychangu_checkout_url_pkg_" . (int)$sp['package_id'],
  "stripe_checkout_url_pkg_" . (int)$sp['package_id'],
];
$checkout_url = null;
$stmt = $pdo->prepare("SELECT `key`, `value` FROM app_settings WHERE `key` IN (?, ?, ?)"); // 3 keys
$stmt->execute($keys);
while ($row = $stmt->fetch()) {
  if (!empty($row['value'])) { $checkout_url = $row['value']; break; }
}

if (!$checkout_url) {
    // Fallback: stop and tell admin to configure it
    // (Or you could send them to a page that programmatically creates a Stripe session.)
    http_response_code(500);
    echo "Checkout URL is not configured for this package. Please ask support to set app_settings key 'checkout_url_pkg_{$sp['package_id']}'.";
    exit();
}

// Create a pending payment record so you can reconcile after callback/webhook
$txn_stub = 'init_' . bin2hex(random_bytes(6));
$is_stripe = (stripos($checkout_url, 'stripe') !== false);
// payments.payment_method is enum('Stripe','PayChangu','Manual','Other').
// 'Hosted' is not a member, so any checkout URL that named neither provider
// (a custom pay domain, say) aborted the whole purchase under
// STRICT_TRANS_TABLES with "Data truncated for column 'payment_method'".
$method_note = (stripos($checkout_url, 'paychangu') !== false) ? 'PayChangu' : ($is_stripe ? 'Stripe' : 'Other');
$stmt = $pdo->prepare("
  INSERT INTO payments (student_package_id, amount, payment_date, payment_method, status, transaction_id, notes)
  VALUES (?, ?, NOW(), ?, 'Pending', ?, ?)
");
$stmt->execute([$sp_id, (float)$sp['price'], $method_note, $txn_stub, 'Created before redirect']);

// Carry our student_package id to the provider so the confirmation can map back:
//  - Stripe Payment Links / Checkout read ?client_reference_id=<sp_id> and echo it
//    back on the checkout.session.completed webhook (see student/api/stripe_webhook.php).
//  - PayChangu hosted links: activation is confirmed via the verify-payment API in
//    payment_callback.php once PAYCHANGU_SECRET_KEY is configured.
if ($is_stripe) {
    $sep = (strpos($checkout_url, '?') === false) ? '?' : '&';
    $checkout_url .= $sep . 'client_reference_id=' . urlencode((string)$sp_id);
}

header('Location: ' . $checkout_url);
exit();
