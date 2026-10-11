<?php
// admin_revenue.php — the single Revenue Management page.
//
// Replaces three pages that all queried the same money:
//   admin_payments_packages.php   — packages CRUD + payment confirmation + a revenue chart
//   admin_reporting_analytics.php — a second, differently-defined revenue chart + operations analytics
//   admin_analytics.php           — an earlier draft of the second one, orphaned (nothing linked to it)
// They disagreed on the default window (6 months vs 30 days), on which filters
// applied to revenue, and on the currency symbol (both hardcoded "$" while
// app_settings.org.default_currency is GBP). One page, one set of filters, one
// definition of every number.
//
// Views:  ?view=overview | payments | packages | analytics
// Export: ?view=payments&export=csv   (server-side, honours the active filters)

require_once __DIR__ . '/../config/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$user_id      = (int)($_SESSION['user_id'] ?? 0);
$user_role    = $_SESSION['user_role'] ?? '';
$current_page = 'revenue';

require_once __DIR__ . '/../utils/timezone_helper.php';
// utils/money.php may be newer than a deployed copy of this page; a missing file
// must not turn "revenue page uploaded" into "revenue page fatals".
if (is_file(__DIR__ . '/../utils/money.php')) { require_once __DIR__ . '/../utils/money.php'; }
if (!function_exists('money_symbol')) {
    function money_symbol($code) {
        static $m = ['USD'=>'$','EUR'=>"\u{20AC}",'GBP'=>"\u{00A3}",'MWK'=>'MK','ZAR'=>'R','KES'=>'KSh','TZS'=>'TSh'];
        $code = strtoupper(trim((string)$code));
        return $m[$code] ?? ($code !== '' ? $code . ' ' : '');
    }
}
if (!function_exists('h')) {
    function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// ---------------------------------------------------------------- DB
// Both old pages hand-built a PDO from the DB_* constants and left DB_PORT out of
// the DSN, so any host not on 3306 failed here and nowhere else. They also ran
// date_default_timezone_set('Africa/Blantyre') — a third timezone, agreeing with
// neither the code's DEFAULT_TIMEZONE (Europe/London) nor the platform setting
// (org.default_timezone = Europe/Berlin).
try {
    $pdo = getDbConnection();
    // payments.payment_date is a TIMESTAMP: MySQL renders it in the *session*
    // timezone. Every window below is a UTC instant, so pin the session to UTC
    // rather than hope the host is.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    error_log('admin_revenue DB error: ' . $e->getMessage());
    http_response_code(503);
    die('Revenue is temporarily unavailable. Please try again shortly.');
}

// ------------------------------------------------------------- Authorize
// Same gate as the dashboard. The old analytics page accepted *any* admin_details
// row without checking admin_role, which the payments page did check.
$is_admin = ($user_role === 'Administrator');
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT admin_role FROM admin_details WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    $is_admin = $row && in_array($row['admin_role'], ['Super Admin','Admin','Academic Coordinator'], true);
}
if (!$is_admin) { header('Location: ../login.php'); exit(); }

if (!function_exists('get_setting')) {
    function get_setting(PDO $pdo, string $key, $default = null) {
        $s = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    }
}
if (!function_exists('plural')) {
    function plural(int $n, string $one, ?string $many = null): string {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }
}

// ------------------------------------------------------------- Currency
// Both old pages printed a literal "$" against every figure. The platform bills in
// whatever admin_settings.php stored, which on this install is GBP.
$currency_code = strtoupper(substr(trim((string)get_setting($pdo, 'org.default_currency', 'USD')), 0, 3)) ?: 'USD';
$C     = money_symbol($currency_code);
$money = fn($v) => $C . number_format((float)$v, 2);

// ------------------------------------------------------------- CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ------------------------------------------------------------- Constants
// Mirrors the column definitions; validated server-side so a hand-posted value
// cannot reach an INSERT and fatal under production's STRICT_TRANS_TABLES.
const PAY_METHODS  = ['Stripe','PayChangu','Manual','Other'];
const PAY_STATUSES = ['Completed','Pending','Failed'];
const AMOUNT_MAX   = 99999999.99;   // payments.amount / packages.price are decimal(10,2)
const PER_PAGE     = 40;
const EXPIRY_SOON_DAYS = 14;
const LOW_LESSONS      = 2;
// A demo is a lesson with no student. lesson_type exists but ~25 historical demos
// still read 'Regular', so both halves are needed. The old analytics pages instead
// guessed "a 30-minute block is a demo", which silently dropped every genuine
// 30-minute lesson — and org.lesson_prefs has allow30 = 1, so those are a product.
const DEMO_SQL     = "(l.lesson_type = 'Demo' OR l.student_id IS NULL)";

$adminTz  = getUserTimezone();
$adminTzO = new DateTimeZone($adminTz);
$FMT      = 'Y-m-d H:i:s';

/** A local Y-m-d (start or end of day) as the UTC instant to compare against. */
function dayBoundUtc(string $ymd, bool $endOfDay, string $tz): string {
    $t = $endOfDay ? '23:59:59' : '00:00:00';
    return localToUtc("$ymd $t", $tz)->format('Y-m-d H:i:s');
}
/** Y-m-d if valid, else the fallback. Guards a hand-typed ?from=nonsense. */
function ymd_or(?string $v, string $fallback): string {
    $v = trim((string)$v);
    if ($v === '') return $fallback;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : $fallback;
}

// ------------------------------------------------------------- View + filters
$VIEWS = ['overview','payments','packages','analytics'];
$view  = $_GET['view'] ?? 'overview';
if (!in_array($view, $VIEWS, true)) $view = 'overview';
$current_subpage = $view;   // admin_sidebar.php highlights the active leaf from this

$today_local = (new DateTime('now', $adminTzO))->format('Y-m-d');
$def_from    = (new DateTime('now', $adminTzO))->modify('-89 days')->format('Y-m-d');

$f_from = ymd_or($_GET['from'] ?? null, $def_from);
$f_to   = ymd_or($_GET['to']   ?? null, $today_local);
if ($f_from > $f_to) { $tmp = $f_from; $f_from = $f_to; $f_to = $tmp; }  // swap rather than return nothing

$f_tutor   = max(0, (int)($_GET['tutor_id']   ?? 0));
$f_student = max(0, (int)($_GET['student_id'] ?? 0));
$f_package = max(0, (int)($_GET['package_id'] ?? 0));
$f_subject = max(0, (int)($_GET['subject_id'] ?? 0));
$f_status  = in_array($_GET['status'] ?? '', PAY_STATUSES, true) ? $_GET['status'] : '';
$f_method  = in_array($_GET['method'] ?? '', PAY_METHODS,  true) ? $_GET['method'] : '';
$f_q       = trim((string)($_GET['q'] ?? ''));
if (strlen($f_q) > 120) $f_q = substr($f_q, 0, 120);
$f_flag    = in_array($_GET['flag'] ?? '', ['expiring','low'], true) ? $_GET['flag'] : '';
$include_demos = ($_GET['include_demos'] ?? '') === '1';
$granularity   = in_array($_GET['granularity'] ?? '', ['daily','weekly','monthly','yearly'], true) ? $_GET['granularity'] : 'monthly';
$page_no       = max(1, (int)($_GET['p'] ?? 1));

$fromUtc = dayBoundUtc($f_from, false, $adminTz);
$toUtc   = dayBoundUtc($f_to,   true,  $adminTz);

/** Rebuild the current query string with overrides — keeps filters across tabs. */
function qs(array $over = []): string {
    $base = [
        'view' => $GLOBALS['view'], 'from' => $GLOBALS['f_from'], 'to' => $GLOBALS['f_to'],
        'tutor_id' => $GLOBALS['f_tutor'], 'student_id' => $GLOBALS['f_student'],
        'package_id' => $GLOBALS['f_package'], 'subject_id' => $GLOBALS['f_subject'],
        'status' => $GLOBALS['f_status'], 'method' => $GLOBALS['f_method'], 'q' => $GLOBALS['f_q'],
        'flag' => $GLOBALS['f_flag'], 'granularity' => $GLOBALS['granularity'],
        'include_demos' => $GLOBALS['include_demos'] ? '1' : '',
    ];
    $merged = array_merge($base, $over);
    $merged = array_filter($merged, function ($v) { return $v !== '' && $v !== 0 && $v !== null && $v !== '0'; });
    return 'admin_revenue.php?' . http_build_query($merged);
}

// ============================================================== POST actions
// Every write is Post/Redirect/Get. The old pages rendered the result of the POST
// directly, so a browser refresh re-submitted it — refreshing after confirming a
// payment confirmed it again, and refreshing after "Create Package" made a second
// package. Flash messages survive the redirect in the session.
$ok  = $_SESSION['rev_ok']  ?? [];
$err = $_SESSION['rev_err'] ?? [];
unset($_SESSION['rev_ok'], $_SESSION['rev_err']);

/** Positive money within the decimal(10,2) range the column can actually hold. */
function money_in(string $raw, string $label): float {
    $v = (float)str_replace(',', '', trim($raw));
    if (!is_finite($v) || $v <= 0)        throw new RuntimeException("$label must be a positive amount.");
    if ($v > AMOUNT_MAX)                  throw new RuntimeException("$label is larger than the {$GLOBALS['currency_code']} 99,999,999.99 this system can store.");
    return round($v, 2);
}
/** Stored checkout links must at least be http(s) URLs — a typo here sends buyers nowhere. */
function checkout_url_in(string $raw, string $label): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (strlen($raw) > 500) throw new RuntimeException("$label is too long (max 500 characters).");
    if (!preg_match('~^https?://~i', $raw) || !filter_var($raw, FILTER_VALIDATE_URL)) {
        throw new RuntimeException("$label must be a full http:// or https:// URL.");
    }
    return $raw;
}
function setPkgLink(PDO $pdo, int $pkg_id, string $which, string $url): void {
    $map = ['generic'=>"checkout_url_pkg_$pkg_id", 'stripe'=>"stripe_checkout_url_pkg_$pkg_id", 'paychangu'=>"paychangu_checkout_url_pkg_$pkg_id"];
    if (!isset($map[$which])) return;
    if ($url === '') { $pdo->prepare("DELETE FROM app_settings WHERE `key`=?")->execute([$map[$which]]); }
    else             { $pdo->prepare("REPLACE INTO app_settings(`key`,`value`) VALUES(?,?)")->execute([$map[$which], $url]); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $redirect = $_POST['redirect'] ?? qs();
    if (!preg_match('~^admin_revenue\.php(\?|$)~', $redirect)) $redirect = qs();  // never bounce off-site

    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Invalid session token. Refresh the page and try again.');
        }

        // ---------------------------------------------------- Packages
        if ($action === 'create_package' || $action === 'update_package') {
            $isUpdate = ($action === 'update_package');
            $pkg_id   = (int)($_POST['package_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $lpw      = (int)($_POST['lessons_per_week'] ?? 0);
            $dur      = (int)($_POST['duration_days'] ?? 0);
            $active   = isset($_POST['is_active']) ? 1 : 0;
            $subj_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['subject_ids'] ?? [])))));

            if ($name === '')                 throw new RuntimeException('Give the package a name.');
            if (mb_strlen($name) > 255)       throw new RuntimeException('The package name is longer than 255 characters.');
            if ($lpw < 1 || $lpw > 21)        throw new RuntimeException('Lessons per week must be between 1 and 21.');
            if ($dur < 1 || $dur > 3650)      throw new RuntimeException('Duration must be between 1 and 3650 days.');
            $price = money_in((string)($_POST['price'] ?? ''), 'Price');

            // Validate the links before writing anything, so a typo in the third URL
            // does not leave a half-saved package behind.
            $links = [
                'generic'   => checkout_url_in((string)($_POST['checkout_url_generic']   ?? ''), 'Generic checkout URL'),
                'stripe'    => checkout_url_in((string)($_POST['checkout_url_stripe']    ?? ''), 'Stripe Payment Link'),
                'paychangu' => checkout_url_in((string)($_POST['checkout_url_paychangu'] ?? ''), 'PayChangu checkout URL'),
            ];
            if ($subj_ids) {
                $in = implode(',', array_fill(0, count($subj_ids), '?'));
                $s  = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE id IN ($in)");
                $s->execute($subj_ids);
                if ((int)$s->fetchColumn() !== count($subj_ids)) throw new RuntimeException('One of the selected subjects no longer exists.');
            }

            // The old page ran the package INSERT and the package_subjects INSERTs as
            // separate statements: a failure on the second left a package with no
            // subjects and no error the admin could act on.
            $pdo->beginTransaction();
            try {
                if ($isUpdate) {
                    $cur = $pdo->prepare("SELECT id FROM packages WHERE id=?");
                    $cur->execute([$pkg_id]);
                    if (!$cur->fetchColumn()) throw new RuntimeException('That package no longer exists.');
                    $pdo->prepare("UPDATE packages SET name=?, description=?, lessons_per_week=?, price=?, duration_days=?, is_active=? WHERE id=?")
                        ->execute([$name, $desc, $lpw, $price, $dur, $active, $pkg_id]);
                    $pdo->prepare("DELETE FROM package_subjects WHERE package_id=?")->execute([$pkg_id]);
                } else {
                    $pdo->prepare("INSERT INTO packages (name,description,lessons_per_week,price,duration_days,is_active) VALUES (?,?,?,?,?,?)")
                        ->execute([$name, $desc, $lpw, $price, $dur, $active]);
                    $pkg_id = (int)$pdo->lastInsertId();
                }
                if ($subj_ids) {
                    $ins = $pdo->prepare("INSERT INTO package_subjects (package_id, subject_id) VALUES (?,?)");
                    foreach ($subj_ids as $sid) $ins->execute([$pkg_id, $sid]);
                }
                foreach ($links as $which => $url) setPkgLink($pdo, $pkg_id, $which, $url);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            $ok[] = $isUpdate ? 'Package updated.' : 'Package created.';
        }

        if ($action === 'delete_package') {
            $pkg_id = (int)($_POST['package_id'] ?? 0);
            $p = $pdo->prepare("SELECT name FROM packages WHERE id=?");
            $p->execute([$pkg_id]);
            $pname = $p->fetchColumn();
            if ($pname === false) throw new RuntimeException('That package no longer exists.');

            // student_packages.package_id is ON DELETE CASCADE, and payments.student_package_id
            // cascades again from there. Deleting a package in use would therefore erase
            // every payment ever taken for it — the money, not just the plan.
            $c = $pdo->prepare("
                SELECT COUNT(DISTINCT sp.id) sps, COUNT(pay.id) pays, COALESCE(SUM(pay.amount),0) amt
                FROM student_packages sp
                LEFT JOIN payments pay ON pay.student_package_id = sp.id
                WHERE sp.package_id = ?");
            $c->execute([$pkg_id]);
            $dep = $c->fetch();
            if ((int)$dep['sps'] > 0) {
                throw new RuntimeException(
                    '"' . $pname . '" is held by ' . plural((int)$dep['sps'], 'student') . '. Deleting it would also delete '
                    . plural((int)$dep['pays'], 'payment') . ' worth ' . $GLOBALS['money']($dep['amt'])
                    . ', because student packages and their payments cascade from the package. Deactivate it instead.'
                );
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM package_subjects WHERE package_id=?")->execute([$pkg_id]);
                $pdo->prepare("DELETE FROM packages WHERE id=?")->execute([$pkg_id]);
                foreach (['generic','stripe','paychangu'] as $w) setPkgLink($pdo, $pkg_id, $w, '');
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            $ok[] = 'Package "' . $pname . '" deleted.';
        }

        // ---------------------------------------------- Student packages
        if ($action === 'assign_student_package') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $package_id = (int)($_POST['package_id'] ?? 0);
            $start_date = ymd_or($_POST['start_date'] ?? '', '');
            if ($start_date === '') throw new RuntimeException('Give a valid start date (YYYY-MM-DD).');

            // The old page trusted both ids: assigning to a deleted or non-Student
            // user reported success and then failed on the foreign key.
            $s = $pdo->prepare("SELECT first_name, last_name, role, is_active FROM users WHERE id=?");
            $s->execute([$student_id]);
            $stu = $s->fetch();
            if (!$stu)                        throw new RuntimeException('That student no longer exists.');
            if ($stu['role'] !== 'Student')   throw new RuntimeException('The selected user is not a Student account.');

            $s = $pdo->prepare("SELECT name, lessons_per_week, duration_days, price, is_active FROM packages WHERE id=?");
            $s->execute([$package_id]);
            $pk = $s->fetch();
            if (!$pk)                    throw new RuntimeException('That package no longer exists.');
            if (!(int)$pk['is_active'])  throw new RuntimeException('"' . $pk['name'] . '" is inactive. Re-activate it before assigning it.');

            $start = DateTime::createFromFormat('Y-m-d', $start_date);
            $dur   = max(1, (int)$pk['duration_days']);
            $end   = (clone $start)->modify('+' . ($dur - 1) . ' days');
            $total = max(1, (int)$pk['lessons_per_week'] * (int)ceil($dur / 7));

            $makePayment = isset($_POST['create_payment']);
            $amount = $makePayment
                ? money_in((string)(trim((string)($_POST['payment_amount'] ?? '')) !== '' ? $_POST['payment_amount'] : $pk['price']), 'Payment amount')
                : 0.0;
            $method = in_array($_POST['payment_method'] ?? '', PAY_METHODS, true) ? $_POST['payment_method'] : 'Manual';
            $txid   = mb_substr(trim((string)($_POST['transaction_id'] ?? '')), 0, 255);
            $prov   = mb_substr(trim((string)($_POST['provider_session_id'] ?? '')), 0, 255);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO student_packages (student_id, package_id, start_date, end_date, total_lessons, lessons_remaining, status, created_at)
                               VALUES (?,?,?,?,?,?, 'Pending', UTC_TIMESTAMP())")
                    ->execute([$student_id, $package_id, $start->format('Y-m-d'), $end->format('Y-m-d'), $total, $total]);
                $sp_id = (int)$pdo->lastInsertId();
                if ($makePayment) {
                    $pdo->prepare("INSERT INTO payments (student_package_id, amount, payment_date, payment_method, status, transaction_id, provider_session_id, notes, confirmed_by_admin_id)
                                   VALUES (?,?,UTC_TIMESTAMP(),?, 'Pending', ?, ?, ?, NULL)")
                        ->execute([$sp_id, $amount, $method, $txid, $prov, 'Initial invoice']);
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            $ok[] = $stu['first_name'] . ' ' . $stu['last_name'] . ' assigned "' . $pk['name'] . '" ('
                  . plural($total, 'lesson') . ', ' . $start->format('M j') . ' to ' . $end->format('M j, Y')
                  . '). It stays Pending until a payment is confirmed.';
        }

        if ($action === 'cancel_student_package') {
            $sp_id = (int)($_POST['student_package_id'] ?? 0);
            $s = $pdo->prepare("SELECT sp.status, u.first_name, u.last_name, pk.name
                                FROM student_packages sp JOIN users u ON u.id=sp.student_id JOIN packages pk ON pk.id=sp.package_id
                                WHERE sp.id=?");
            $s->execute([$sp_id]);
            $sp = $s->fetch();
            if (!$sp) throw new RuntimeException('That student package no longer exists.');
            if ($sp['status'] === 'Cancelled') throw new RuntimeException('That package is already cancelled.');
            $pdo->prepare("UPDATE student_packages SET status='Cancelled' WHERE id=?")->execute([$sp_id]);
            $ok[] = '"' . $sp['name'] . '" for ' . $sp['first_name'] . ' ' . $sp['last_name'] . ' is now Cancelled. Its payments are untouched.';
        }

        // --------------------------------------------------- Payments
        if ($action === 'confirm_payment' || $action === 'fail_payment') {
            $pay_id = (int)($_POST['payment_id'] ?? 0);
            // The old page issued the UPDATE blind: confirming a payment id that did
            // not exist, or one already Completed, both reported "Payment confirmed".
            $s = $pdo->prepare("SELECT pay.status, pay.amount, pay.student_package_id, u.first_name, u.last_name
                                FROM payments pay
                                JOIN student_packages sp ON sp.id = pay.student_package_id
                                JOIN users u ON u.id = sp.student_id
                                WHERE pay.id = ?");
            $s->execute([$pay_id]);
            $pay = $s->fetch();
            if (!$pay) throw new RuntimeException('That payment no longer exists.');
            $who = $pay['first_name'] . ' ' . $pay['last_name'];

            if ($action === 'confirm_payment') {
                if ($pay['status'] === 'Completed') throw new RuntimeException('That payment is already confirmed.');
                $method = in_array($_POST['payment_method'] ?? '', PAY_METHODS, true) ? $_POST['payment_method'] : null;
                if ($method === null) throw new RuntimeException('Choose a payment method (Stripe, PayChangu, Manual or Other).');
                $txid = mb_substr(trim((string)($_POST['transaction_id'] ?? '')), 0, 255);
                $prov = mb_substr(trim((string)($_POST['provider_session_id'] ?? '')), 0, 255);

                // Confirming money and activating the package the money bought are one
                // change: the old page ran them as two loose statements, so a failure
                // between them left a paid student on a Pending package.
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE payments
                                   SET status='Completed', payment_method=?, transaction_id=?, provider_session_id=?,
                                       confirmed_by_admin_id=?, payment_date=COALESCE(payment_date, UTC_TIMESTAMP())
                                   WHERE id=?")
                        ->execute([$method, $txid, $prov, $user_id, $pay_id]);
                    $act = $pdo->prepare("UPDATE student_packages SET status='Active' WHERE id=? AND status='Pending'");
                    $act->execute([(int)$pay['student_package_id']]);
                    $activated = $act->rowCount() > 0;
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                $ok[] = $GLOBALS['money']($pay['amount']) . ' from ' . $who . ' confirmed'
                      . ($activated ? ' — their package is now Active.' : '. Their package was already ' . 'past Pending, so its status is unchanged.');
            } else {
                if ($pay['status'] === 'Failed') throw new RuntimeException('That payment is already marked Failed.');
                $wasCompleted = ($pay['status'] === 'Completed');
                $pdo->prepare("UPDATE payments SET status='Failed' WHERE id=?")->execute([$pay_id]);
                $ok[] = $GLOBALS['money']($pay['amount']) . ' from ' . $who . ' marked Failed.'
                      . ($wasCompleted ? ' It was previously Completed, so it has left the revenue figures.' : '');
            }
        }

        if ($action === 'add_manual_payment') {
            $sp_id  = (int)($_POST['student_package_id'] ?? 0);
            $amount = money_in((string)($_POST['amount'] ?? ''), 'Amount');
            $method = in_array($_POST['payment_method'] ?? '', PAY_METHODS,  true) ? $_POST['payment_method'] : 'Manual';
            $status = in_array($_POST['status'] ?? '',         PAY_STATUSES, true) ? $_POST['status'] : 'Pending';
            $txid   = mb_substr(trim((string)($_POST['transaction_id'] ?? '')), 0, 255);
            $prov   = mb_substr(trim((string)($_POST['provider_session_id'] ?? '')), 0, 255);
            $when   = ymd_or($_POST['paid_on'] ?? '', $today_local);

            $s = $pdo->prepare("SELECT u.first_name, u.last_name, pk.name FROM student_packages sp
                                JOIN users u ON u.id=sp.student_id JOIN packages pk ON pk.id=sp.package_id WHERE sp.id=?");
            $s->execute([$sp_id]);
            $sp = $s->fetch();
            if (!$sp) throw new RuntimeException('Choose an existing student package to record the payment against.');

            // Recorded against the chosen date at noon local, so the row lands on the
            // day the admin picked in their own calendar rather than on whichever UTC
            // date happens to contain their midnight.
            $paidUtc = localToUtc("$when 12:00:00", $adminTz)->format('Y-m-d H:i:s');

            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO payments (student_package_id,amount,payment_date,payment_method,status,transaction_id,provider_session_id,notes,confirmed_by_admin_id)
                               VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$sp_id, $amount, $paidUtc, $method, $status, $txid, $prov,
                               'Recorded by admin', $status === 'Completed' ? $user_id : null]);
                $activated = false;
                if ($status === 'Completed') {
                    $act = $pdo->prepare("UPDATE student_packages SET status='Active' WHERE id=? AND status='Pending'");
                    $act->execute([$sp_id]);
                    $activated = $act->rowCount() > 0;
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            $ok[] = $GLOBALS['money']($amount) . ' recorded as ' . $status . ' for ' . $sp['first_name'] . ' ' . $sp['last_name']
                  . ($activated ? ' — their package is now Active.' : '.');
        }

    } catch (Throwable $e) {
        $err[] = $e->getMessage();
    }

    $_SESSION['rev_ok']  = $ok;
    $_SESSION['rev_err'] = $err;
    header('Location: ' . $redirect, true, 303);
    exit();
}

// ============================================================== Reference data
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
$packages = $pdo->query("SELECT * FROM packages ORDER BY is_active DESC, name")->fetchAll();
$tutors   = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Tutor'   AND is_active=1 ORDER BY first_name, last_name")->fetchAll();
$students = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='Student' AND is_active=1 ORDER BY first_name, last_name")->fetchAll();

// ============================================================ Payment filters
// One builder for every view, so the ledger, the chart and the KPI strip can never
// again disagree about which payments are "in the window".
$PAY_FROM = "FROM payments pay
             JOIN student_packages sp ON sp.id = pay.student_package_id
             JOIN users u  ON u.id  = sp.student_id
             JOIN packages pk ON pk.id = sp.package_id";

/** @return array{0:string,1:array} WHERE fragment + bound args. */
function payFilter(bool $applyStatus): array {
    $w = ['pay.payment_date BETWEEN ? AND ?'];
    $a = [$GLOBALS['fromUtc'], $GLOBALS['toUtc']];
    if ($applyStatus && $GLOBALS['f_status'] !== '') { $w[] = 'pay.status = ?';         $a[] = $GLOBALS['f_status']; }
    if ($GLOBALS['f_method']  !== '') { $w[] = 'pay.payment_method = ?'; $a[] = $GLOBALS['f_method']; }
    if ($GLOBALS['f_student']  >  0)  { $w[] = 'sp.student_id = ?';      $a[] = $GLOBALS['f_student']; }
    if ($GLOBALS['f_package']  >  0)  { $w[] = 'sp.package_id = ?';      $a[] = $GLOBALS['f_package']; }
    if ($GLOBALS['f_q'] !== '') {
        $w[] = "(pay.transaction_id LIKE ? OR pay.provider_session_id LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
        $like = '%' . str_replace(['\\','%','_'], ['\\\\','\%','\_'], $GLOBALS['f_q']) . '%';
        $a[] = $like; $a[] = $like; $a[] = $like;
    }
    return [implode(' AND ', $w), $a];
}
list($payWhereSql, $payArgs) = payFilter(true);

// The tutor filter has no path to a payment: lessons.student_package_id is what
// would link a tutor's work to the money, and it is NULL on every lesson (see the
// integrity check below). Rather than silently return unfiltered money, the UI
// says so and the filter is applied only where it is meaningful.
$tutorFilterIgnoredOnMoney = ($f_tutor > 0);

/** Sum + count for one payment status inside the active filters. */
function payTotals(PDO $pdo, string $fromSql, string $whereSql, array $args, ?string $status): array {
    $sql = "SELECT COUNT(*) n, COALESCE(SUM(pay.amount),0) amt $fromSql WHERE $whereSql";
    if ($status !== null) { $sql .= ' AND pay.status = ?'; $args[] = $status; }
    $s = $pdo->prepare($sql); $s->execute($args);
    $r = $s->fetch();
    return ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
}

// The KPI strip and the charts deliberately ignore the ledger's status filter —
// "collected vs outstanding vs failed" is only meaningful when all three are counted.
list($baseWhereSql, $baseArgs) = payFilter(false);

$kCollected = payTotals($pdo, $PAY_FROM, $baseWhereSql, $baseArgs, 'Completed');
$kPending   = payTotals($pdo, $PAY_FROM, $baseWhereSql, $baseArgs, 'Pending');
$kFailed    = payTotals($pdo, $PAY_FROM, $baseWhereSql, $baseArgs, 'Failed');
$attempted  = $kCollected['amt'] + $kPending['amt'] + $kFailed['amt'];
$collectionRate = $attempted > 0 ? round(100 * $kCollected['amt'] / $attempted, 1) : null;

// Outstanding is deliberately *not* windowed: money owed is owed whatever month
// the invoice was raised in, and that is the number an admin acts on.
$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(pay.amount),0) amt $PAY_FROM WHERE pay.status='Pending'");
$s->execute();
$outstandingAll = $s->fetch();

// Paying students in the window (for ARPU)
$s = $pdo->prepare("SELECT COUNT(DISTINCT sp.student_id) $PAY_FROM WHERE $baseWhereSql AND pay.status='Completed'");
$s->execute($baseArgs);
$payingStudents = (int)$s->fetchColumn();
$arpu = $payingStudents > 0 ? $kCollected['amt'] / $payingStudents : null;

// ============================================================ Revenue series
// Bucketed in PHP against the admin's calendar. GROUP BY DATE()/YEARWEEK() buckets
// by the *UTC* date, which quietly files an evening payment under the next day for
// any admin east of UTC — and both old pages did exactly that.
$s = $pdo->prepare("SELECT pay.payment_date, pay.amount $PAY_FROM WHERE $baseWhereSql AND pay.status='Completed' ORDER BY pay.payment_date");
$s->execute($baseArgs);
$buckets = [];
foreach ($s as $row) {
    $local = utcToLocal($row['payment_date'], $adminTz);
    switch ($granularity) {
        case 'daily':   $key = $local->format('Y-m-d');            $label = $local->format('M j, Y'); break;
        case 'weekly':  $wk = (clone $local)->modify('monday this week');
                        $key = $wk->format('Y-m-d');               $label = 'Week of ' . $wk->format('M j'); break;
        case 'yearly':  $key = $local->format('Y');                $label = $local->format('Y'); break;
        default:        $key = $local->format('Y-m');              $label = $local->format('M Y');
    }
    if (!isset($buckets[$key])) $buckets[$key] = ['label' => $label, 'amt' => 0.0, 'n' => 0];
    $buckets[$key]['amt'] += (float)$row['amount'];
    $buckets[$key]['n']++;
}
// Zero-fill the empty periods. GROUP BY only ever returns periods that contain a
// row, so both old charts drew non-consecutive months as adjacent bars — a quiet
// month simply vanished and the trend read as unbroken. Skipped past a sane bar
// count (e.g. daily over three years) so the axis stays readable.
$span = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month', 'yearly' => '+1 year'][$granularity];
$cursor = new DateTime($f_from, $adminTzO);
if ($granularity === 'weekly')  $cursor->modify('monday this week');
if ($granularity === 'monthly') $cursor->modify('first day of this month');
if ($granularity === 'yearly')  $cursor->modify('first day of January this year');
$stop = new DateTime($f_to, $adminTzO);
$filled = []; $guard = 0;
while ($cursor <= $stop && $guard++ < 400) {
    switch ($granularity) {
        case 'daily':   $key = $cursor->format('Y-m-d'); $label = $cursor->format('M j, Y'); break;
        case 'weekly':  $key = $cursor->format('Y-m-d'); $label = 'Week of ' . $cursor->format('M j'); break;
        case 'yearly':  $key = $cursor->format('Y');     $label = $cursor->format('Y'); break;
        default:        $key = $cursor->format('Y-m');   $label = $cursor->format('M Y');
    }
    $filled[$key] = $buckets[$key] ?? ['label' => $label, 'amt' => 0.0, 'n' => 0];
    $cursor->modify($span);
}
// Anything the walk missed (a payment outside the swept periods) is kept rather than dropped.
$buckets = ($guard < 400) ? ($filled + $buckets) : $buckets;
ksort($buckets);
$revLabels = array_column($buckets, 'label');
$revValues = array_map(fn($b) => round($b['amt'], 2), array_values($buckets));
$revCounts = array_column($buckets, 'n');

// ---- Revenue by package / by student (both honour the active filters)
$s = $pdo->prepare("SELECT pk.id, pk.name, COALESCE(SUM(pay.amount),0) amt, COUNT(*) n
                    $PAY_FROM WHERE $baseWhereSql AND pay.status='Completed'
                    GROUP BY pk.id, pk.name ORDER BY amt DESC");
$s->execute($baseArgs);
$revByPackage = $s->fetchAll();

$s = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, COALESCE(SUM(pay.amount),0) amt, COUNT(*) n,
                           MAX(pay.payment_date) last_paid
                    $PAY_FROM WHERE $baseWhereSql AND pay.status='Completed'
                    GROUP BY u.id, u.first_name, u.last_name ORDER BY amt DESC LIMIT 15");
$s->execute($baseArgs);
$revByStudent = $s->fetchAll();

// ============================================== Packages & renewal watch
// The dashboard has "Packages expiring" and "Packages nearly used up" chips that
// linked to the old payments page, which had no such list — the click landed on a
// page with no way to see what it had just counted. ?flag= gives them a target.
$SP_SELECT = "SELECT sp.*, u.first_name, u.last_name, u.email, pk.name AS package_name, pk.price,
                     (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.student_package_id=sp.id AND p2.status='Completed') paid,
                     (SELECT COUNT(*)  FROM payments p3 WHERE p3.student_package_id=sp.id AND p3.status='Pending')   pending_n
              FROM student_packages sp
              JOIN users u    ON u.id  = sp.student_id
              JOIN packages pk ON pk.id = sp.package_id";

$spWhere = ['1=1']; $spArgs = [];
if ($f_student > 0) { $spWhere[] = 'sp.student_id = ?'; $spArgs[] = $f_student; }
if ($f_package > 0) { $spWhere[] = 'sp.package_id = ?'; $spArgs[] = $f_package; }
if ($f_flag === 'expiring') { $spWhere[] = "sp.status='Active' AND sp.end_date BETWEEN ? AND ?"; $spArgs[] = $today_local; $spArgs[] = (new DateTime($today_local))->modify('+' . EXPIRY_SOON_DAYS . ' days')->format('Y-m-d'); }
if ($f_flag === 'low')      { $spWhere[] = "sp.status='Active' AND sp.lessons_remaining <= " . LOW_LESSONS; }
$s = $pdo->prepare("$SP_SELECT WHERE " . implode(' AND ', $spWhere) . " ORDER BY sp.created_at DESC LIMIT 300");
$s->execute($spArgs);
$studentPackages = $s->fetchAll();

// Counts for the renewal rail (unfiltered — these are alerts, not a report)
$soon = (new DateTime($today_local))->modify('+' . EXPIRY_SOON_DAYS . ' days')->format('Y-m-d');
$s = $pdo->prepare("SELECT COUNT(*) FROM student_packages WHERE status='Active' AND end_date BETWEEN ? AND ?");
$s->execute([$today_local, $soon]);
$cntExpiring = (int)$s->fetchColumn();
$cntLow = (int)$pdo->query("SELECT COUNT(*) FROM student_packages WHERE status='Active' AND lessons_remaining <= " . LOW_LESSONS)->fetchColumn();

// Deferred delivery: lessons paid for but not yet taught, and what they are worth.
$deferred = $pdo->query("
    SELECT COALESCE(SUM(sp.lessons_remaining),0) lessons,
           COALESCE(SUM(pk.price * sp.lessons_remaining / NULLIF(sp.total_lessons,0)),0) value,
           COUNT(*) n
    FROM student_packages sp JOIN packages pk ON pk.id=sp.package_id
    WHERE sp.status='Active'")->fetch();

// Packages with the count of students on each, in one query (the old page ran a
// separate subjects lookup inside the render loop — one query per package).
$pkgSubjects = [];
foreach ($pdo->query("SELECT ps.package_id, s.id AS subject_id, s.name FROM package_subjects ps JOIN subjects s ON s.id=ps.subject_id ORDER BY s.name") as $r) {
    $pkgSubjects[(int)$r['package_id']][] = $r;
}
$pkgUsage = [];
foreach ($pdo->query("SELECT package_id, COUNT(*) n, SUM(status='Active') active_n FROM student_packages GROUP BY package_id") as $r) {
    $pkgUsage[(int)$r['package_id']] = ['n' => (int)$r['n'], 'active' => (int)$r['active_n']];
}
$pkgLinks = [];
foreach ($pdo->query("SELECT `key`,`value` FROM app_settings WHERE `key` LIKE 'checkout_url_pkg_%' OR `key` LIKE 'stripe_checkout_url_pkg_%' OR `key` LIKE 'paychangu_checkout_url_pkg_%'") as $r) {
    if (preg_match('/^(checkout_url_pkg_|stripe_checkout_url_pkg_|paychangu_checkout_url_pkg_)(\d+)$/', $r['key'], $m)) {
        $type = $m[1] === 'checkout_url_pkg_' ? 'generic' : ($m[1] === 'stripe_checkout_url_pkg_' ? 'stripe' : 'paychangu');
        $pkgLinks[(int)$m[2]][$type] = $r['value'];
    }
}

// ====================================================== Integrity check
// lessons.student_package_id is the only join between a lesson and the money that
// paid for it. When it is never written, two things silently stop working:
//   - trg_after_lesson_completed cannot decrement lessons_remaining, so every
//     student shows 0% progress and "lessons delivered" stays at zero;
//   - per-tutor revenue attribution has nothing to join on and returns no rows,
//     which the old page rendered as a confident 0.00 in every tutor's Revenue column.
$lnk = $pdo->query("SELECT COUNT(*) total, SUM(student_package_id IS NOT NULL) linked FROM lessons")->fetch();
$lessonsTotal  = (int)$lnk['total'];
$lessonsLinked = (int)$lnk['linked'];
$attributionPossible = $lessonsLinked > 0;

// ================================================================ Ledger
$ledger = []; $ledgerTotal = 0; $ledgerPages = 1;
if ($view === 'payments' || ($_GET['export'] ?? '') === 'csv') {
    $s = $pdo->prepare("SELECT COUNT(*) $PAY_FROM WHERE $payWhereSql");
    $s->execute($payArgs);
    $ledgerTotal = (int)$s->fetchColumn();
    $ledgerPages = max(1, (int)ceil($ledgerTotal / PER_PAGE));
    if ($page_no > $ledgerPages) $page_no = $ledgerPages;

    $sql = "SELECT pay.*, sp.student_id, sp.start_date, sp.end_date, sp.status AS sp_status,
                   u.first_name, u.last_name, pk.name AS package_name,
                   a.first_name AS admin_first, a.last_name AS admin_last
            $PAY_FROM
            LEFT JOIN users a ON a.id = pay.confirmed_by_admin_id
            WHERE $payWhereSql
            ORDER BY pay.payment_date DESC, pay.id DESC";
    if (($_GET['export'] ?? '') !== 'csv') $sql .= ' LIMIT ' . PER_PAGE . ' OFFSET ' . (($page_no - 1) * PER_PAGE);
    $s = $pdo->prepare($sql);
    $s->execute($payArgs);
    $ledger = $s->fetchAll();
}

// -------- CSV export. Server-side, so it exports every filtered row rather than
// the page you happen to be looking at; the old export was a client-side dump of
// the 3-column summary table only.
if (($_GET['export'] ?? '') === 'csv') {
    $name = 'payments_' . $f_from . '_to_' . $f_to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads the currency symbols
    fputcsv($out, ['Payment ID','Paid on (' . $adminTz . ')','Student','Student ID','Package','Amount','Currency','Status','Method','Transaction ID','Provider session','Confirmed by','Notes']);
    foreach ($ledger as $r) {
        fputcsv($out, [
            $r['id'],
            $r['payment_date'] ? utcToLocal($r['payment_date'], $adminTz)->format('Y-m-d H:i') : '',
            $r['first_name'] . ' ' . $r['last_name'],
            $r['student_id'],
            $r['package_name'],
            number_format((float)$r['amount'], 2, '.', ''),
            $currency_code,
            $r['status'],
            $r['payment_method'] !== '' ? $r['payment_method'] : '(none recorded)',
            $r['transaction_id'],
            $r['provider_session_id'],
            $r['admin_first'] ? $r['admin_first'] . ' ' . $r['admin_last'] : '',
            $r['notes'],
        ]);
    }
    fclose($out);
    exit();
}

// ============================================================== Analytics
$an = null;
if ($view === 'analytics' || $view === 'overview') {
    $lw   = ['l.scheduled_start_time BETWEEN ? AND ?'];
    $lArgs = [$fromUtc, $toUtc];
    if (!$include_demos) { $lw[] = 'NOT ' . DEMO_SQL; }
    if ($f_tutor   > 0) { $lw[] = 'l.tutor_id = ?';   $lArgs[] = $f_tutor; }
    if ($f_student > 0) { $lw[] = 'l.student_id = ?'; $lArgs[] = $f_student; }
    if ($f_subject > 0) { $lw[] = 'l.subject_id = ?'; $lArgs[] = $f_subject; }
    $lWhere = implode(' AND ', $lw);

    $q = function (string $sql, array $args = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s; };

    $an = [];
    $an['completed'] = (int)$q("SELECT COUNT(*) FROM lessons l WHERE $lWhere AND l.status='Completed'", $lArgs)->fetchColumn();
    $r = $q("SELECT AVG(r.rating) a, COUNT(*) n FROM lesson_ratings r JOIN lessons l ON l.id=r.lesson_id WHERE $lWhere", $lArgs)->fetch();
    $an['avgRating']  = $r['a'] !== null ? round((float)$r['a'], 2) : null;
    $an['ratingsN']   = (int)$r['n'];

    // Attendance across every status the enum can hold, so a status nobody thought
    // of is visible instead of vanishing.
    $an['attendance'] = [];
    foreach ($q("SELECT l.status, COUNT(*) c FROM lessons l WHERE $lWhere GROUP BY l.status", $lArgs) as $row) {
        $an['attendance'][$row['status']] = (int)$row['c'];
    }
    $an['lessonsTotalWindow'] = array_sum($an['attendance']);

    $an['bySubject'] = $q("SELECT s.name label, COUNT(*) c FROM lessons l JOIN subjects s ON s.id=l.subject_id
                           WHERE $lWhere AND l.status='Completed' GROUP BY s.id, s.name ORDER BY c DESC LIMIT 10", $lArgs)->fetchAll();

    // ---- Tutor performance
    $tp = [];
    foreach ($q("SELECT l.tutor_id id, u.first_name, u.last_name,
                        SUM(l.status='Completed') completed,
                        SUM(l.status='NoShow') noshow,
                        SUM(l.status='CancelledByTutor') cancelled,
                        COUNT(*) total
                 FROM lessons l JOIN users u ON u.id=l.tutor_id
                 WHERE $lWhere GROUP BY l.tutor_id, u.first_name, u.last_name", $lArgs) as $row) {
        $tp[(int)$row['id']] = [
            'id' => (int)$row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'completed' => (int)$row['completed'], 'noshow' => (int)$row['noshow'],
            'cancelled' => (int)$row['cancelled'], 'total' => (int)$row['total'],
            'rating' => null, 'ratingsN' => 0, 'resp' => null, 'revenue' => null,
        ];
    }
    foreach ($q("SELECT l.tutor_id id, AVG(r.rating) a, COUNT(*) n FROM lesson_ratings r JOIN lessons l ON l.id=r.lesson_id
                 WHERE $lWhere GROUP BY l.tutor_id", $lArgs) as $row) {
        if (isset($tp[(int)$row['id']])) { $tp[(int)$row['id']]['rating'] = round((float)$row['a'], 2); $tp[(int)$row['id']]['ratingsN'] = (int)$row['n']; }
    }
    // Grading turnaround, keyed on when the grading happened (not on the lesson window).
    $rw = ["hs.status='Graded'", 'hs.graded_at IS NOT NULL', 'hs.submission_date IS NOT NULL', 'hs.graded_at BETWEEN ? AND ?'];
    $rArgs = [$fromUtc, $toUtc];
    if ($f_tutor > 0) { $rw[] = 'h.tutor_id = ?'; $rArgs[] = $f_tutor; }
    foreach ($q("SELECT h.tutor_id id, AVG(TIMESTAMPDIFF(HOUR, hs.submission_date, hs.graded_at)) hrs, COUNT(*) n
                 FROM homework_submissions hs JOIN homework h ON h.id=hs.homework_id
                 WHERE " . implode(' AND ', $rw) . " GROUP BY h.tutor_id", $rArgs) as $row) {
        if (!isset($tp[(int)$row['id']])) continue;
        $tp[(int)$row['id']]['resp'] = round((float)$row['hrs'], 1);
    }
    // Per-tutor revenue share, only where a lesson is actually linked to the package
    // that was paid for. The old query joined on lessons.student_package_id
    // unconditionally and so returned no rows at all — every tutor read 0.00.
    if ($attributionPossible) {
        $sqlRev = "SELECT l.tutor_id id, SUM(p.amount * l.cnt / NULLIF(t.tot,0)) amt
                   FROM payments p
                   JOIN (SELECT student_package_id, tutor_id, COUNT(*) cnt FROM lessons l
                         WHERE $lWhere AND l.status='Completed' AND l.student_package_id IS NOT NULL
                         GROUP BY student_package_id, tutor_id) l ON l.student_package_id = p.student_package_id
                   JOIN (SELECT student_package_id, COUNT(*) tot FROM lessons l
                         WHERE $lWhere AND l.status='Completed' AND l.student_package_id IS NOT NULL
                         GROUP BY student_package_id) t ON t.student_package_id = p.student_package_id
                   WHERE p.status='Completed' AND p.payment_date BETWEEN ? AND ?
                   GROUP BY l.tutor_id";
        foreach ($q($sqlRev, array_merge($lArgs, $lArgs, [$fromUtc, $toUtc])) as $row) {
            if (isset($tp[(int)$row['id']])) $tp[(int)$row['id']]['revenue'] = (float)$row['amt'];
        }
    }
    usort($tp, fn($a, $b) => $b['completed'] <=> $a['completed']);
    $an['tutors'] = $tp;

    // ---- Homework: assigned vs submitted, both counted by the week the homework
    // was *assigned*, so a late submission still lands against the work it answers.
    // Counting submissions by their own week (as the old page did) let a catch-up
    // week show over 100%.
    $hwFrom = (clone (new DateTime($f_to, $adminTzO)))->modify('monday this week')->modify('-11 weeks');
    $hwFromUtc = dayBoundUtc($hwFrom->format('Y-m-d'), false, $adminTz);
    $hw = ['l.scheduled_start_time BETWEEN ? AND ?'];
    $hwArgs = [$hwFromUtc, $toUtc];
    if ($f_tutor   > 0) { $hw[] = 'l.tutor_id = ?';   $hwArgs[] = $f_tutor; }
    if ($f_student > 0) { $hw[] = 'l.student_id = ?'; $hwArgs[] = $f_student; }
    if ($f_subject > 0) { $hw[] = 'l.subject_id = ?'; $hwArgs[] = $f_subject; }
    $weeks = [];
    $cur = clone $hwFrom;
    for ($i = 0; $i < 12; $i++) { $weeks[$cur->format('Y-m-d')] = ['label' => $cur->format('M j'), 'a' => 0, 's' => 0]; $cur->modify('+1 week'); }
    foreach ($q("SELECT l.scheduled_start_time t, (SELECT COUNT(*) FROM homework_submissions hs WHERE hs.homework_id=h.id) subs
                 FROM homework h JOIN lessons l ON l.id=h.lesson_id
                 WHERE " . implode(' AND ', $hw), $hwArgs) as $row) {
        $k = utcToLocal($row['t'], $adminTz)->modify('monday this week')->format('Y-m-d');
        if (!isset($weeks[$k])) continue;
        $weeks[$k]['a']++;
        if ((int)$row['subs'] > 0) $weeks[$k]['s']++;
    }
    $an['hwLabels'] = array_column($weeks, 'label');
    $an['hwRate']   = array_map(fn($w) => $w['a'] > 0 ? round(100 * $w['s'] / $w['a']) : null, array_values($weeks));
    $an['hwAssigned'] = array_column($weeks, 'a');

    // ---- Activity heatmap, 52 weeks back from the window's end. Bucketed in PHP
    // against the admin's calendar; the old version built its keys with JavaScript's
    // toISOString(), which is UTC, so every cell was a day out for viewers east of UTC.
    $heatEnd   = new DateTime($f_to . ' 23:59:59', $adminTzO);
    $heatStart = (clone $heatEnd)->modify('-364 days')->modify('monday this week');
    $hsUtc = localToUtc($heatStart->format('Y-m-d') . ' 00:00:00', $adminTz)->format('Y-m-d H:i:s');
    $heUtc = localToUtc($f_to . ' 23:59:59', $adminTz)->format('Y-m-d H:i:s');
    $counts = [];
    $bump = function ($utc) use (&$counts, $adminTz) {
        $d = utcToLocal($utc, $adminTz)->format('Y-m-d');
        $counts[$d] = ($counts[$d] ?? 0) + 1;
    };
    $hl = ['l.scheduled_start_time BETWEEN ? AND ?']; $hlArgs = [$hsUtc, $heUtc];
    if (!$include_demos) $hl[] = 'NOT ' . DEMO_SQL;
    if ($f_tutor   > 0) { $hl[] = 'l.tutor_id = ?';   $hlArgs[] = $f_tutor; }
    if ($f_student > 0) { $hl[] = 'l.student_id = ?'; $hlArgs[] = $f_student; }
    if ($f_subject > 0) { $hl[] = 'l.subject_id = ?'; $hlArgs[] = $f_subject; }
    foreach ($q("SELECT l.scheduled_start_time t FROM lessons l WHERE " . implode(' AND ', $hl), $hlArgs) as $row) $bump($row['t']);
    foreach ($q("SELECT hs.submission_date t FROM homework_submissions hs
                 JOIN homework h ON h.id=hs.homework_id JOIN lessons l ON l.id=h.lesson_id
                 WHERE hs.submission_date BETWEEN ? AND ?"
                 . ($f_tutor   > 0 ? ' AND l.tutor_id = ?'   : '')
                 . ($f_student > 0 ? ' AND l.student_id = ?' : '')
                 . ($f_subject > 0 ? ' AND l.subject_id = ?' : ''),
                 array_merge([$hsUtc, $heUtc], array_values(array_filter([$f_tutor ?: null, $f_student ?: null, $f_subject ?: null])))) as $row) $bump($row['t']);
    foreach ($q("SELECT pay.payment_date t $PAY_FROM WHERE pay.status='Completed' AND pay.payment_date BETWEEN ? AND ?"
                 . ($f_student > 0 ? ' AND sp.student_id = ?' : ''),
                 array_merge([$hsUtc, $heUtc], $f_student > 0 ? [$f_student] : [])) as $row) $bump($row['t']);

    $heat = [];
    $cur = clone $heatStart;
    $endYmd = $heatEnd->format('Y-m-d');
    while ($cur->format('Y-m-d') <= $endYmd) {
        $d = $cur->format('Y-m-d');
        $heat[] = ['d' => $d, 'n' => $counts[$d] ?? 0, 'dow' => (int)$cur->format('N')];
        $cur->modify('+1 day');
    }
    $an['heat'] = $heat;
    $an['heatMax'] = $heat ? max(array_column($heat, 'n')) : 0;
}

$VIEW_META = [
    'overview'  => ['Overview',  'Money in, money owed, money still to be delivered.'],
    'payments'  => ['Payments',  'Every transaction, filterable and exportable.'],
    'packages'  => ['Packages',  'What you sell, who holds one, and what renews next.'],
    'analytics' => ['Analytics', 'What the platform actually delivered for that money.'],
];
$windowLabel = (new DateTime($f_from))->format('M j, Y') . ' to ' . (new DateTime($f_to))->format('M j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Revenue &mdash; <?php echo h($VIEW_META[$view][0]); ?></title>
  <link rel="icon" type="image/png" href="../favicon.png" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
          integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
          crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #111827; color: #d1d5db; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #1f2937; }
    ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
    .card { background-color: #0f1623; border: 1px solid rgba(255,255,255,.06); border-radius: .75rem; }
    .form-input { background-color:#374151; border:1px solid #4b5563; color:#f3f4f6; border-radius:.5rem; padding:.5rem .75rem; font-size:.875rem; width:100%; }
    .form-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(79,70,229,.4); }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .tab { padding:.5rem 1rem; border-radius:.5rem; font-size:.875rem; font-weight:600; white-space:nowrap; }
    .tab-on  { background:#4f46e5; color:#fff; }
    .tab-off { color:#9ca3af; }
    .tab-off:hover { background:#1f2937; color:#e5e7eb; }
    .heat-grid { display:grid; grid-auto-flow:column; grid-auto-columns:11px; grid-template-rows:repeat(7,11px); gap:3px; }
    .heat-cell { width:11px; height:11px; border-radius:2px; }
    table.ledger th { position:sticky; top:0; background:#0b1220; z-index:1; }
  </style>
</head>
<body class="flex min-h-screen">

  <?php include 'admin_sidebar.php'; ?>

  <main class="flex-1 bg-gray-800 p-5 lg:p-8 min-w-0">

    <header class="mb-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-2xl font-bold text-white">Revenue &mdash; <?php echo h($VIEW_META[$view][0]); ?></h2>
          <p class="text-gray-400 text-sm mt-0.5"><?php echo h($VIEW_META[$view][1]); ?></p>
        </div>
        <div class="text-right">
          <p class="text-xs text-gray-500 uppercase tracking-wide">Window</p>
          <p class="text-sm text-gray-300 font-semibold"><?php echo h($windowLabel); ?></p>
          <p class="text-xs text-gray-500"><?php echo h($currency_code); ?> &middot; times shown in <?php echo h($adminTz); ?></p>
        </div>
      </div>

      <nav class="flex flex-wrap gap-1 mt-4 border-b border-gray-700/60 pb-3">
        <?php foreach ($VIEW_META as $k => $meta): ?>
          <a href="<?php echo h(qs(['view' => $k, 'p' => null])); ?>" class="tab <?php echo $view === $k ? 'tab-on' : 'tab-off'; ?>"><?php echo h($meta[0]); ?></a>
        <?php endforeach; ?>
        <span class="flex-1"></span>
        <?php if ($cntExpiring > 0): ?>
          <a href="<?php echo h(qs(['view'=>'packages','flag'=>'expiring'])); ?>" class="tab tab-off text-amber-300 hover:text-amber-200">
            <?php echo plural($cntExpiring, 'package'); ?> expiring
          </a>
        <?php endif; ?>
        <?php if ((int)$outstandingAll['n'] > 0): ?>
          <a href="<?php echo h(qs(['view'=>'payments','status'=>'Pending','p'=>null])); ?>" class="tab tab-off text-violet-300 hover:text-violet-200">
            <?php echo $money($outstandingAll['amt']); ?> outstanding
          </a>
        <?php endif; ?>
      </nav>
    </header>

    <?php foreach ($ok as $m): ?>
      <div class="mb-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($err as $m): ?>
      <div class="mb-3 bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2.5 rounded-lg text-sm"><?php echo h($m); ?></div>
    <?php endforeach; ?>

    <!-- ------------------------------------------------------ Filter bar -->
    <form method="get" class="card p-4 mb-6">
      <input type="hidden" name="view" value="<?php echo h($view); ?>">
      <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3">
        <div><label class="text-xs text-gray-400">From</label><input type="date" name="from" value="<?php echo h($f_from); ?>" class="form-input"></div>
        <div><label class="text-xs text-gray-400">To</label><input type="date" name="to" value="<?php echo h($f_to); ?>" class="form-input"></div>
        <div>
          <label class="text-xs text-gray-400">Student</label>
          <select name="student_id" class="form-input">
            <option value="0">All students</option>
            <?php foreach ($students as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo $f_student === (int)$s['id'] ? 'selected' : ''; ?>><?php echo h($s['first_name'] . ' ' . $s['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="text-xs text-gray-400">Package</label>
          <select name="package_id" class="form-input">
            <option value="0">All packages</option>
            <?php foreach ($packages as $p): ?>
              <option value="<?php echo (int)$p['id']; ?>" <?php echo $f_package === (int)$p['id'] ? 'selected' : ''; ?>><?php echo h($p['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($view === 'payments'): ?>
          <div>
            <label class="text-xs text-gray-400">Status</label>
            <select name="status" class="form-input">
              <option value="">Any status</option>
              <?php foreach (PAY_STATUSES as $st): ?>
                <option value="<?php echo h($st); ?>" <?php echo $f_status === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs text-gray-400">Method</label>
            <select name="method" class="form-input">
              <option value="">Any method</option>
              <?php foreach (PAY_METHODS as $mth): ?>
                <option value="<?php echo h($mth); ?>" <?php echo $f_method === $mth ? 'selected' : ''; ?>><?php echo h($mth); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label class="text-xs text-gray-400">Search</label><input name="q" value="<?php echo h($f_q); ?>" placeholder="name or transaction id" class="form-input"></div>
        <?php elseif ($view === 'analytics'): ?>
          <div>
            <label class="text-xs text-gray-400">Tutor</label>
            <select name="tutor_id" class="form-input">
              <option value="0">All tutors</option>
              <?php foreach ($tutors as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo $f_tutor === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['first_name'] . ' ' . $t['last_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs text-gray-400">Subject</label>
            <select name="subject_id" class="form-input">
              <option value="0">Any subject</option>
              <?php foreach ($subjects as $sub): ?>
                <option value="<?php echo (int)$sub['id']; ?>" <?php echo $f_subject === (int)$sub['id'] ? 'selected' : ''; ?>><?php echo h($sub['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <label class="flex items-end gap-2 pb-2 text-sm text-gray-300">
            <input type="checkbox" name="include_demos" value="1" class="w-4 h-4 rounded" <?php echo $include_demos ? 'checked' : ''; ?>>
            <span>Include demos</span>
          </label>
        <?php else: ?>
          <div>
            <label class="text-xs text-gray-400">Group by</label>
            <select name="granularity" class="form-input">
              <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $g => $lbl): ?>
                <option value="<?php echo $g; ?>" <?php echo $granularity === $g ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="flex items-end gap-2">
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 px-4 rounded-lg text-sm">Apply</button>
          <a href="admin_revenue.php?view=<?php echo h($view); ?>" class="text-sm text-gray-400 hover:text-gray-200 py-2">Reset</a>
        </div>
      </div>
      <?php
        $presets = [
          'This month' => [(new DateTime('first day of this month', $adminTzO))->format('Y-m-d'), $today_local],
          'Last month' => [(new DateTime('first day of last month', $adminTzO))->format('Y-m-d'), (new DateTime('last day of last month', $adminTzO))->format('Y-m-d')],
          'Last 90 days' => [$def_from, $today_local],
          'This year'  => [(new DateTime('first day of January this year', $adminTzO))->format('Y-m-d'), $today_local],
        ];
      ?>
      <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-gray-700/50">
        <span class="text-xs text-gray-500">Quick range:</span>
        <?php foreach ($presets as $lbl => $r): $on = ($f_from === $r[0] && $f_to === $r[1]); ?>
          <a href="<?php echo h(qs(['from'=>$r[0], 'to'=>$r[1], 'p'=>null])); ?>"
             class="text-xs px-2.5 py-1 rounded-full <?php echo $on ? 'bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/40' : 'bg-gray-800 text-gray-400 hover:text-gray-200'; ?>"><?php echo h($lbl); ?></a>
        <?php endforeach; ?>
      </div>
    </form>

    <?php if ($tutorFilterIgnoredOnMoney && $view !== 'analytics'): ?>
      <div class="mb-6 bg-amber-500/10 border border-amber-500/30 text-amber-200 px-4 py-2.5 rounded-lg text-sm">
        A tutor filter is set, but it does not apply to money on this tab &mdash; a payment is made against a package, not against a tutor.
        Tutor-level figures live on the <a class="underline" href="<?php echo h(qs(['view'=>'analytics'])); ?>">Analytics</a> tab.
      </div>
    <?php endif; ?>

<?php if ($view === 'overview'): ?>

  <?php
    $kpis = [
      ['Collected',      $money($kCollected['amt']), plural($kCollected['n'], 'payment') . ' in window',       'emerald', qs(['view'=>'payments','status'=>'Completed','p'=>null])],
      ['Outstanding',    $money($outstandingAll['amt']), plural((int)$outstandingAll['n'], 'payment') . ' pending, all time', 'violet', qs(['view'=>'payments','status'=>'Pending','p'=>null])],
      ['Failed',         $money($kFailed['amt']),    plural($kFailed['n'], 'payment') . ' in window',          'red',     qs(['view'=>'payments','status'=>'Failed','p'=>null])],
      ['Collection rate',$collectionRate === null ? '—' : $collectionRate . '%', $attempted > 0 ? 'of ' . $money($attempted) . ' attempted' : 'nothing attempted yet', 'sky', null],
      ['Undelivered',    $money($deferred['value']), plural((int)$deferred['lessons'], 'lesson') . ' still owed to students', 'amber', qs(['view'=>'packages'])],
      ['Revenue / student', $arpu === null ? '—' : $money($arpu), $payingStudents > 0 ? 'across ' . plural($payingStudents, 'paying student') : 'no payers in window', 'indigo', null],
    ];
    $tone = [
      'emerald'=>'text-emerald-400','violet'=>'text-violet-400','red'=>'text-red-400',
      'sky'=>'text-sky-400','amber'=>'text-amber-400','indigo'=>'text-indigo-400',
    ];
  ?>
  <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    <?php foreach ($kpis as $k): ?>
      <?php $inner = '<p class="text-xs text-gray-400">' . h($k[0]) . '</p>'
                   . '<p class="text-2xl font-extrabold ' . $tone[$k[3]] . ' mt-1 leading-tight">' . h($k[1]) . '</p>'
                   . '<p class="text-xs text-gray-500 mt-1">' . h($k[2]) . '</p>'; ?>
      <?php if ($k[4]): ?>
        <a href="<?php echo h($k[4]); ?>" class="card p-4 block hover:ring-1 hover:ring-indigo-500/40 transition"><?php echo $inner; ?></a>
      <?php else: ?>
        <div class="card p-4"><?php echo $inner; ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <?php if (!$attributionPossible && $lessonsTotal > 0): ?>
    <div class="card p-4 mb-6 border-amber-500/30 bg-amber-500/5">
      <p class="text-sm text-amber-200 font-semibold">None of the <?php echo number_format($lessonsTotal); ?> lessons on record is linked to a student package.</p>
      <p class="text-xs text-amber-200/70 mt-1 leading-relaxed">
        <span class="mono">lessons.student_package_id</span> is the only join between a lesson and the money that paid for it, and it is NULL on every row.
        Two things depend on it: the database trigger that decrements <span class="mono">lessons_remaining</span> when a lesson completes (so student
        progress reads 0% for everyone and &ldquo;Undelivered&rdquo; above never falls), and per-tutor revenue attribution (so the Analytics tab cannot split
        revenue by tutor). This page reports that honestly rather than printing zeros &mdash; the old page rendered the same missing data as a confident
        <?php echo h($C); ?>0.00 in every tutor&rsquo;s revenue column.
      </p>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 space-y-6">

      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-lg font-semibold text-white">Collected revenue</h3>
            <p class="text-xs text-gray-500"><?php echo ucfirst($granularity); ?>, in <?php echo h($adminTz); ?></p>
          </div>
          <div class="flex gap-1">
            <?php foreach (['daily'=>'D','weekly'=>'W','monthly'=>'M','yearly'=>'Y'] as $g => $lbl): ?>
              <a href="<?php echo h(qs(['granularity'=>$g])); ?>"
                 class="text-xs w-8 h-8 grid place-items-center rounded-lg font-semibold <?php echo $granularity === $g ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (!$revValues): ?>
          <div class="h-64 grid place-items-center text-center">
            <div>
              <p class="text-gray-400">No confirmed payments between <?php echo h($windowLabel); ?>.</p>
              <?php if ((int)$outstandingAll['n'] > 0): ?>
                <a href="<?php echo h(qs(['view'=>'payments','status'=>'Pending','p'=>null])); ?>" class="text-indigo-400 text-sm hover:text-indigo-300">
                  There <?php echo (int)$outstandingAll['n'] === 1 ? 'is' : 'are'; ?> <?php echo plural((int)$outstandingAll['n'], 'payment'); ?> waiting to be confirmed &rarr;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="h-64"><canvas id="revChart"></canvas></div>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-white">Waiting on you</h3>
          <a href="<?php echo h(qs(['view'=>'payments','status'=>'Pending','p'=>null])); ?>" class="text-sm text-indigo-400 hover:text-indigo-300 font-semibold">All pending &rarr;</a>
        </div>
        <?php
          $s = $pdo->prepare("SELECT pay.id, pay.amount, pay.payment_date, pay.payment_method, pay.transaction_id, pay.provider_session_id,
                                     u.first_name, u.last_name, pk.name AS package_name
                              $PAY_FROM WHERE pay.status='Pending' ORDER BY pay.payment_date ASC LIMIT 6");
          $s->execute();
          $pendingTop = $s->fetchAll();
        ?>
        <?php if (!$pendingTop): ?>
          <p class="text-gray-400 text-sm">Nothing pending. Every payment on record is confirmed or closed.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($pendingTop as $pp):
                $age = $pp['payment_date'] ? (int)(new DateTime('now'))->diff(new DateTime($pp['payment_date'] . ' UTC'))->days : 0; ?>
              <div class="flex flex-wrap items-center gap-3 bg-gray-800/60 p-3 rounded-lg">
                <div class="flex-1 min-w-[10rem]">
                  <p class="font-semibold text-white text-sm"><?php echo h($pp['first_name'] . ' ' . $pp['last_name']); ?></p>
                  <p class="text-xs text-gray-400"><?php echo h($pp['package_name']); ?>
                    <?php if ($pp['payment_date']): ?><span class="text-gray-600">&middot;</span> raised <?php echo $age === 0 ? 'today' : plural($age, 'day') . ' ago'; ?><?php endif; ?>
                  </p>
                </div>
                <p class="font-bold text-white"><?php echo $money($pp['amount']); ?></p>
                <button type="button"
                        class="open-confirm text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white py-1.5 px-3 rounded-lg"
                        data-id="<?php echo (int)$pp['id']; ?>"
                        data-who="<?php echo h($pp['first_name'] . ' ' . $pp['last_name']); ?>"
                        data-amount="<?php echo h($money($pp['amount'])); ?>"
                        data-method="<?php echo h($pp['payment_method']); ?>"
                        data-tx="<?php echo h((string)$pp['transaction_id']); ?>"
                        data-session="<?php echo h((string)$pp['provider_session_id']); ?>">Confirm</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="space-y-6">
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-4">By package</h3>
        <?php if (!$revByPackage): ?>
          <p class="text-gray-400 text-sm">Nothing collected in this window.</p>
        <?php else: ?>
          <div class="h-56"><canvas id="pkgChart"></canvas></div>
          <div class="mt-4 space-y-1.5">
            <?php foreach ($revByPackage as $rp): ?>
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-300 truncate pr-2"><?php echo h($rp['name']); ?></span>
                <span class="text-white font-semibold whitespace-nowrap"><?php echo $money($rp['amt']); ?>
                  <span class="text-gray-500 font-normal text-xs">&times;<?php echo (int)$rp['n']; ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Top students</h3>
        <?php if (!$revByStudent): ?>
          <p class="text-gray-400 text-sm">No confirmed payments in this window.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php $top = (float)$revByStudent[0]['amt']; foreach ($revByStudent as $rs): ?>
              <a href="<?php echo h(qs(['view'=>'payments','student_id'=>(int)$rs['id'],'p'=>null])); ?>" class="block group">
                <div class="flex items-center justify-between text-sm">
                  <span class="text-gray-300 group-hover:text-white truncate pr-2"><?php echo h($rs['first_name'] . ' ' . $rs['last_name']); ?></span>
                  <span class="text-white font-semibold whitespace-nowrap"><?php echo $money($rs['amt']); ?></span>
                </div>
                <div class="w-full bg-gray-800 rounded-full h-1.5 mt-1">
                  <div class="h-1.5 bg-emerald-500 rounded-full" style="width: <?php echo $top > 0 ? round(100 * (float)$rs['amt'] / $top, 1) : 0; ?>%"></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-1">Renewals</h3>
        <p class="text-xs text-gray-500 mb-4">Active packages needing attention</p>
        <div class="space-y-2">
          <a href="<?php echo h(qs(['view'=>'packages','flag'=>'expiring'])); ?>" class="flex items-center justify-between bg-gray-800/60 hover:bg-gray-800 p-3 rounded-lg">
            <span class="text-sm text-gray-300">Ending within <?php echo EXPIRY_SOON_DAYS; ?> days</span>
            <span class="text-lg font-bold <?php echo $cntExpiring ? 'text-amber-400' : 'text-gray-600'; ?>"><?php echo $cntExpiring; ?></span>
          </a>
          <a href="<?php echo h(qs(['view'=>'packages','flag'=>'low'])); ?>" class="flex items-center justify-between bg-gray-800/60 hover:bg-gray-800 p-3 rounded-lg">
            <span class="text-sm text-gray-300"><?php echo LOW_LESSONS; ?> lessons or fewer left</span>
            <span class="text-lg font-bold <?php echo $cntLow ? 'text-amber-400' : 'text-gray-600'; ?>"><?php echo $cntLow; ?></span>
          </a>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php if ($view === 'payments'): ?>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="card p-4"><p class="text-xs text-gray-400">Collected</p><p class="text-xl font-extrabold text-emerald-400 mt-1"><?php echo $money($kCollected['amt']); ?></p><p class="text-xs text-gray-500"><?php echo plural($kCollected['n'], 'payment'); ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Pending</p><p class="text-xl font-extrabold text-violet-400 mt-1"><?php echo $money($kPending['amt']); ?></p><p class="text-xs text-gray-500"><?php echo plural($kPending['n'], 'payment'); ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Failed</p><p class="text-xl font-extrabold text-red-400 mt-1"><?php echo $money($kFailed['amt']); ?></p><p class="text-xs text-gray-500"><?php echo plural($kFailed['n'], 'payment'); ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Matching your filters</p><p class="text-xl font-extrabold text-white mt-1"><?php echo number_format($ledgerTotal); ?></p><p class="text-xs text-gray-500">page <?php echo $page_no; ?> of <?php echo $ledgerPages; ?></p></div>
  </div>

  <div class="card mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-gray-700/50">
      <h3 class="text-lg font-semibold text-white">Ledger</h3>
      <div class="flex items-center gap-2">
        <a href="<?php echo h(qs(['export' => 'csv'])); ?>" class="text-xs font-semibold bg-gray-700 hover:bg-gray-600 text-white py-2 px-3 rounded-lg">Export CSV</a>
        <button type="button" id="openRecord" class="text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white py-2 px-3 rounded-lg">Record a payment</button>
      </div>
    </div>

    <?php if (!$ledger): ?>
      <p class="text-gray-400 text-sm p-6">No payments match these filters. <a class="text-indigo-400 hover:text-indigo-300" href="admin_revenue.php?view=payments">Clear them</a>.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="ledger w-full text-sm">
          <thead>
            <tr class="text-gray-400 text-xs uppercase tracking-wide border-b border-gray-700">
              <th class="text-left px-4 py-3 font-semibold">Student &amp; package</th>
              <th class="text-left px-4 py-3 font-semibold">Paid on</th>
              <th class="text-right px-4 py-3 font-semibold">Amount</th>
              <th class="text-left px-4 py-3 font-semibold">Status</th>
              <th class="text-left px-4 py-3 font-semibold">Method</th>
              <th class="text-left px-4 py-3 font-semibold">Reference</th>
              <th class="text-right px-4 py-3 font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ledger as $r):
              $badge = ['Completed'=>'bg-emerald-500/10 text-emerald-400','Pending'=>'bg-violet-500/10 text-violet-300','Failed'=>'bg-red-500/10 text-red-400'][$r['status']] ?? 'bg-gray-700 text-gray-300';
            ?>
              <tr class="border-b border-gray-800/70 hover:bg-gray-800/40 align-top">
                <td class="px-4 py-3">
                  <a href="<?php echo h(qs(['student_id'=>(int)$r['student_id'],'p'=>null])); ?>" class="font-semibold text-white hover:text-indigo-300"><?php echo h($r['first_name'] . ' ' . $r['last_name']); ?></a>
                  <p class="text-xs text-gray-400"><?php echo h($r['package_name']); ?>
                    <span class="text-gray-600">&middot;</span> <?php echo h($r['sp_status']); ?></p>
                </td>
                <td class="px-4 py-3 text-gray-300 whitespace-nowrap">
                  <?php echo $r['payment_date'] ? h(utcToLocal($r['payment_date'], $adminTz)->format('M j, Y')) : '<span class="text-gray-600">not set</span>'; ?>
                  <?php if ($r['payment_date']): ?><p class="text-xs text-gray-500"><?php echo h(utcToLocal($r['payment_date'], $adminTz)->format('H:i')); ?></p><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right font-semibold text-white whitespace-nowrap"><?php echo $money($r['amount']); ?></td>
                <td class="px-4 py-3">
                  <span class="text-xs font-medium py-1 px-2.5 rounded-full <?php echo $badge; ?>"><?php echo h($r['status']); ?></span>
                  <?php if ($r['admin_first']): ?><p class="text-xs text-gray-500 mt-1">by <?php echo h($r['admin_first']); ?></p><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-300">
                  <?php echo $r['payment_method'] !== '' ? h($r['payment_method']) : '<span class="text-amber-400/70 text-xs">none recorded</span>'; ?>
                </td>
                <td class="px-4 py-3 mono text-xs text-gray-400 break-all max-w-[14rem]">
                  <?php echo $r['transaction_id'] !== null && $r['transaction_id'] !== '' ? h($r['transaction_id']) : '<span class="text-gray-600">&mdash;</span>'; ?>
                  <?php if (!empty($r['provider_session_id'])): ?><p class="text-gray-600"><?php echo h($r['provider_session_id']); ?></p><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                  <?php if ($r['status'] !== 'Completed'): ?>
                    <button type="button"
                            class="open-confirm text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white py-1.5 px-2.5 rounded-lg"
                            data-id="<?php echo (int)$r['id']; ?>"
                            data-who="<?php echo h($r['first_name'] . ' ' . $r['last_name']); ?>"
                            data-amount="<?php echo h($money($r['amount'])); ?>"
                            data-method="<?php echo h($r['payment_method']); ?>"
                            data-tx="<?php echo h((string)$r['transaction_id']); ?>"
                            data-session="<?php echo h((string)$r['provider_session_id']); ?>">Confirm</button>
                  <?php endif; ?>
                  <?php if ($r['status'] !== 'Failed'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('Mark <?php echo h(addslashes($money($r['amount']))); ?> from <?php echo h(addslashes($r['first_name'] . ' ' . $r['last_name'])); ?> as Failed?<?php echo $r['status'] === 'Completed' ? ' It is currently Completed, so this removes it from revenue.' : ''; ?>');">
                      <input type="hidden" name="action" value="fail_payment">
                      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="redirect" value="<?php echo h(qs(['p' => $page_no])); ?>">
                      <input type="hidden" name="payment_id" value="<?php echo (int)$r['id']; ?>">
                      <button class="text-xs font-semibold bg-red-500/10 text-red-400 py-1.5 px-2.5 rounded-lg hover:bg-red-500/20">Fail</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($ledgerPages > 1): ?>
        <div class="flex items-center justify-between p-4 border-t border-gray-700/50 text-sm">
          <?php if ($page_no > 1): ?>
            <a href="<?php echo h(qs(['p' => $page_no - 1])); ?>" class="text-indigo-400 hover:text-indigo-300 font-semibold">&larr; Newer</a>
          <?php else: ?><span></span><?php endif; ?>
          <span class="text-gray-500">Page <?php echo $page_no; ?> of <?php echo $ledgerPages; ?> &middot; <?php echo number_format($ledgerTotal); ?> payments</span>
          <?php if ($page_no < $ledgerPages): ?>
            <a href="<?php echo h(qs(['p' => $page_no + 1])); ?>" class="text-indigo-400 hover:text-indigo-300 font-semibold">Older &rarr;</a>
          <?php else: ?><span></span><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Record a payment -->
  <div id="recordModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="modal-backdrop absolute inset-0 bg-black/70"></div>
    <div class="relative card w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg">Record a payment</h3>
        <button type="button" class="modal-close text-gray-400 hover:text-white text-xl leading-none">&times;</button>
      </div>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="add_manual_payment">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="redirect" value="<?php echo h(qs(['p' => $page_no])); ?>">
        <div>
          <label class="text-xs text-gray-400">Student package</label>
          <select name="student_package_id" class="form-input" required>
            <option value="">Select...</option>
            <?php foreach ($studentPackages as $sp): ?>
              <option value="<?php echo (int)$sp['id']; ?>">
                <?php echo h($sp['first_name'] . ' ' . $sp['last_name'] . ' — ' . $sp['package_name'] . ' (' . $sp['start_date'] . ' to ' . $sp['end_date'] . ', ' . $sp['status'] . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$studentPackages): ?>
            <p class="text-xs text-amber-400 mt-1">No student packages exist yet — assign one on the <a class="underline" href="<?php echo h(qs(['view'=>'packages'])); ?>">Packages</a> tab first.</p>
          <?php endif; ?>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div><label class="text-xs text-gray-400">Amount (<?php echo h($currency_code); ?>)</label><input type="number" min="0.01" step="0.01" name="amount" class="form-input" required></div>
          <div><label class="text-xs text-gray-400">Paid on</label><input type="date" name="paid_on" value="<?php echo h($today_local); ?>" class="form-input"></div>
          <div><label class="text-xs text-gray-400">Method</label>
            <select name="payment_method" class="form-input">
              <?php foreach (PAY_METHODS as $m): ?><option <?php echo $m === 'Manual' ? 'selected' : ''; ?>><?php echo h($m); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><label class="text-xs text-gray-400">Status</label>
            <select name="status" class="form-input">
              <?php foreach (PAY_STATUSES as $s): ?><option <?php echo $s === 'Completed' ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div><label class="text-xs text-gray-400">Transaction ID (optional)</label><input name="transaction_id" class="form-input" placeholder="bank reference, Stripe id..."></div>
          <div><label class="text-xs text-gray-400">Provider session (optional)</label><input name="provider_session_id" class="form-input" placeholder="checkout.session id"></div>
        </div>
        <p class="text-xs text-gray-500">Recording a <strong>Completed</strong> payment also activates the student&rsquo;s package if it is still Pending.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="modal-close bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">Record payment</button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php if ($view === 'packages'): ?>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 space-y-6">

      <!-- ---------------------------------------------- Student packages -->
      <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-gray-700/50">
          <div>
            <h3 class="text-lg font-semibold text-white">Who holds a package</h3>
            <p class="text-xs text-gray-500"><?php echo plural(count($studentPackages), 'record'); ?><?php echo count($studentPackages) === 300 ? ' (showing the most recent 300)' : ''; ?></p>
          </div>
          <div class="flex flex-wrap gap-1">
            <?php
              $flags = ['' => 'All', 'expiring' => 'Expiring (' . $cntExpiring . ')', 'low' => 'Nearly used up (' . $cntLow . ')'];
              foreach ($flags as $fk => $flabel):
            ?>
              <a href="<?php echo h(qs(['flag' => $fk])); ?>"
                 class="text-xs px-3 py-1.5 rounded-lg font-semibold <?php echo $f_flag === $fk ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'; ?>"><?php echo h($flabel); ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (!$studentPackages): ?>
          <p class="text-gray-400 text-sm p-6">
            <?php echo $f_flag !== '' ? 'Nothing matches that flag right now.' : 'No student has been assigned a package yet.'; ?>
          </p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-gray-400 text-xs uppercase tracking-wide border-b border-gray-700">
                  <th class="text-left px-4 py-3 font-semibold">Student</th>
                  <th class="text-left px-4 py-3 font-semibold">Package</th>
                  <th class="text-left px-4 py-3 font-semibold">Runs</th>
                  <th class="text-center px-4 py-3 font-semibold">Lessons</th>
                  <th class="text-right px-4 py-3 font-semibold">Paid</th>
                  <th class="text-left px-4 py-3 font-semibold">Status</th>
                  <th class="text-right px-4 py-3 font-semibold"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($studentPackages as $sp):
                  $endsIn  = (int)(new DateTime($today_local))->diff(new DateTime($sp['end_date']))->format('%r%a');
                  $expiring = $sp['status'] === 'Active' && $endsIn >= 0 && $endsIn <= EXPIRY_SOON_DAYS;
                  $low      = $sp['status'] === 'Active' && (int)$sp['lessons_remaining'] <= LOW_LESSONS;
                  $used     = max(0, (int)$sp['total_lessons'] - (int)$sp['lessons_remaining']);
                  $owed     = max(0, (float)$sp['price'] - (float)$sp['paid']);
                  $badge = ['Active'=>'bg-emerald-500/10 text-emerald-400','Pending'=>'bg-violet-500/10 text-violet-300',
                            'Expired'=>'bg-gray-700 text-gray-300','Cancelled'=>'bg-red-500/10 text-red-400'][$sp['status']] ?? 'bg-gray-700 text-gray-300';
                ?>
                  <tr class="border-b border-gray-800/70 hover:bg-gray-800/40 align-top">
                    <td class="px-4 py-3">
                      <p class="font-semibold text-white"><?php echo h($sp['first_name'] . ' ' . $sp['last_name']); ?></p>
                      <p class="text-xs text-gray-500"><?php echo h((string)$sp['email']); ?></p>
                    </td>
                    <td class="px-4 py-3 text-gray-300"><?php echo h($sp['package_name']); ?></td>
                    <td class="px-4 py-3 text-gray-300 whitespace-nowrap text-xs">
                      <?php echo h((new DateTime($sp['start_date']))->format('M j')); ?> &ndash; <?php echo h((new DateTime($sp['end_date']))->format('M j, Y')); ?>
                      <?php if ($expiring): ?>
                        <p class="text-amber-400 font-semibold"><?php echo $endsIn === 0 ? 'ends today' : 'ends in ' . plural($endsIn, 'day'); ?></p>
                      <?php elseif ($sp['status'] === 'Active' && $endsIn < 0): ?>
                        <p class="text-gray-500">ended <?php echo plural(abs($endsIn), 'day'); ?> ago</p>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                      <span class="<?php echo $low ? 'text-amber-400 font-semibold' : 'text-gray-300'; ?>"><?php echo (int)$sp['lessons_remaining']; ?></span>
                      <span class="text-gray-600">/ <?php echo (int)$sp['total_lessons']; ?></span>
                      <?php if ($used === 0 && $sp['status'] === 'Active' && !$attributionPossible): ?>
                        <p class="text-xs text-gray-600" title="lessons.student_package_id is NULL on every lesson, so the trigger that decrements this never fires">not tracked</p>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                      <span class="text-white font-semibold"><?php echo $money($sp['paid']); ?></span>
                      <p class="text-xs text-gray-500">of <?php echo $money($sp['price']); ?></p>
                      <?php if ($owed > 0.004): ?><p class="text-xs text-violet-300"><?php echo $money($owed); ?> owed</p><?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                      <span class="text-xs font-medium py-1 px-2.5 rounded-full <?php echo $badge; ?>"><?php echo h($sp['status']); ?></span>
                      <?php if ((int)$sp['pending_n'] > 0): ?>
                        <p class="text-xs text-violet-300 mt-1"><?php echo plural((int)$sp['pending_n'], 'payment'); ?> pending</p>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                      <a href="<?php echo h(qs(['view'=>'payments','student_id'=>(int)$sp['student_id'],'p'=>null,'status'=>''])); ?>" class="text-xs text-indigo-400 hover:text-indigo-300 font-semibold">Payments</a>
                      <?php if ($sp['status'] !== 'Cancelled'): ?>
                        <form method="post" class="inline ml-1" onsubmit="return confirm('Cancel this package for <?php echo h(addslashes($sp['first_name'] . ' ' . $sp['last_name'])); ?>? Payments already recorded are left alone.');">
                          <input type="hidden" name="action" value="cancel_student_package">
                          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
                          <input type="hidden" name="student_package_id" value="<?php echo (int)$sp['id']; ?>">
                          <button class="text-xs text-gray-500 hover:text-red-400">Cancel</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- ---------------------------------------------------- Packages -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-white">Packages you sell</h3>
          <button type="button" class="open-pkg text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white py-2 px-3 rounded-lg" data-mode="create">New package</button>
        </div>
        <?php if (!$packages): ?>
          <p class="text-gray-400 text-sm">No packages defined. Create one before assigning anything to a student.</p>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($packages as $p):
              $pid   = (int)$p['id'];
              $subs  = $pkgSubjects[$pid] ?? [];
              $use   = $pkgUsage[$pid] ?? ['n'=>0,'active'=>0];
              $links = ($pkgLinks[$pid] ?? []) + ['generic'=>'','stripe'=>'','paychangu'=>''];
              $weeks = (int)ceil(max(1,(int)$p['duration_days']) / 7);
              $lessons = (int)$p['lessons_per_week'] * $weeks;
              $perLesson = $lessons > 0 ? (float)$p['price'] / $lessons : null;
            ?>
              <div class="bg-gray-800/50 rounded-lg p-4 border <?php echo $p['is_active'] ? 'border-gray-700' : 'border-gray-800 opacity-70'; ?>">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="font-semibold text-white truncate"><?php echo h($p['name']); ?></p>
                    <?php if ($p['description']): ?><p class="text-xs text-gray-400 mt-0.5"><?php echo h($p['description']); ?></p><?php endif; ?>
                  </div>
                  <span class="text-xs px-2 py-0.5 rounded-full whitespace-nowrap <?php echo $p['is_active'] ? 'bg-emerald-500/10 text-emerald-400' : 'bg-gray-700 text-gray-400'; ?>">
                    <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?></span>
                </div>

                <div class="flex items-baseline gap-2 mt-3">
                  <span class="text-2xl font-extrabold text-white"><?php echo $money($p['price']); ?></span>
                  <?php if ($perLesson !== null): ?><span class="text-xs text-gray-500"><?php echo $money($perLesson); ?> / lesson</span><?php endif; ?>
                </div>
                <p class="text-xs text-gray-400 mt-1">
                  <?php echo (int)$p['lessons_per_week']; ?> / week &middot; <?php echo plural((int)$p['duration_days'], 'day'); ?>
                  <span class="text-gray-600">&middot;</span> <?php echo plural($lessons, 'lesson'); ?> total
                </p>
                <?php if ($subs): ?>
                  <div class="flex flex-wrap gap-1 mt-2">
                    <?php foreach ($subs as $sb): ?><span class="text-xs bg-gray-700/60 text-gray-300 px-2 py-0.5 rounded"><?php echo h($sb['name']); ?></span><?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="text-xs text-gray-600 mt-2">No subjects attached</p>
                <?php endif; ?>

                <div class="flex flex-wrap gap-1.5 mt-3">
                  <?php foreach (['stripe'=>'Stripe','paychangu'=>'PayChangu','generic'=>'Generic'] as $lk => $lname): ?>
                    <?php if (!empty($links[$lk])): ?>
                      <span class="text-xs bg-sky-500/10 text-sky-300 px-2 py-0.5 rounded" title="<?php echo h($links[$lk]); ?>"><?php echo $lname; ?> link set</span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <?php if (empty($links['stripe']) && empty($links['paychangu']) && empty($links['generic'])): ?>
                    <span class="text-xs bg-amber-500/10 text-amber-300 px-2 py-0.5 rounded" title="student/api/pay_start.php refuses to start a checkout without one">No checkout link &mdash; students cannot buy this</span>
                  <?php endif; ?>
                </div>

                <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-700/60">
                  <p class="text-xs text-gray-500"><?php echo plural($use['n'], 'holder'); ?><?php echo $use['active'] ? ', ' . $use['active'] . ' active' : ''; ?></p>
                  <div class="flex items-center gap-2">
                    <button type="button" class="open-pkg text-xs font-semibold text-gray-300 hover:text-white"
                      data-mode="edit"
                      data-id="<?php echo $pid; ?>"
                      data-name="<?php echo h($p['name']); ?>"
                      data-desc="<?php echo h((string)$p['description']); ?>"
                      data-lpw="<?php echo (int)$p['lessons_per_week']; ?>"
                      data-price="<?php echo h($p['price']); ?>"
                      data-days="<?php echo (int)$p['duration_days']; ?>"
                      data-active="<?php echo (int)$p['is_active']; ?>"
                      data-generic="<?php echo h($links['generic']); ?>"
                      data-stripe="<?php echo h($links['stripe']); ?>"
                      data-paychangu="<?php echo h($links['paychangu']); ?>"
                      data-subjects="<?php echo h(implode(',', array_column($subs, 'subject_id'))); ?>">Edit</button>
                    <?php if ($use['n'] === 0): ?>
                      <form method="post" class="inline" onsubmit="return confirm('Delete the package &quot;<?php echo h(addslashes($p['name'])); ?>&quot;? No student holds it, so nothing else is affected.');">
                        <input type="hidden" name="action" value="delete_package">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
                        <input type="hidden" name="package_id" value="<?php echo $pid; ?>">
                        <button class="text-xs text-gray-500 hover:text-red-400">Delete</button>
                      </form>
                    <?php else: ?>
                      <span class="text-xs text-gray-600" title="Deleting it would cascade through student_packages into payments and erase <?php echo plural($use['n'], 'student package'); ?> and their payment history">Locked</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ------------------------------------------------- Assign sidebar -->
    <div class="space-y-6">
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-1">Assign a package</h3>
        <p class="text-xs text-gray-500 mb-4">Creates the package as <strong>Pending</strong>; confirming a payment activates it.</p>
        <form method="post" class="space-y-3">
          <input type="hidden" name="action" value="assign_student_package">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
          <div>
            <label class="text-xs text-gray-400">Student</label>
            <select name="student_id" class="form-input" required>
              <option value="">Select student</option>
              <?php foreach ($students as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['first_name'] . ' ' . $s['last_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs text-gray-400">Package</label>
            <select name="package_id" class="form-input" required>
              <option value="">Select package</option>
              <?php foreach ($packages as $p): if (!$p['is_active']) continue; ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?> &mdash; <?php echo $money($p['price']); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!array_filter($packages, fn($p) => (int)$p['is_active'])): ?>
              <p class="text-xs text-amber-400 mt-1">Every package is inactive — activate one first.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="text-xs text-gray-400">Start date</label>
            <input type="date" name="start_date" value="<?php echo h($today_local); ?>" class="form-input" required>
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-300 pt-1">
            <input type="checkbox" name="create_payment" value="1" class="w-4 h-4 rounded" checked>
            <span>Also raise an invoice</span>
          </label>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="text-xs text-gray-400">Amount</label><input type="number" step="0.01" min="0" name="payment_amount" class="form-input" placeholder="package price"></div>
            <div><label class="text-xs text-gray-400">Method</label>
              <select name="payment_method" class="form-input">
                <?php foreach (PAY_METHODS as $m): ?><option <?php echo $m === 'Manual' ? 'selected' : ''; ?>><?php echo h($m); ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <button class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2.5 rounded-lg text-sm">Assign package</button>
        </form>
      </div>

      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-1">Undelivered lessons</h3>
        <p class="text-2xl font-extrabold text-amber-400 mt-2"><?php echo $money($deferred['value']); ?></p>
        <p class="text-xs text-gray-500 mt-1">
          <?php echo plural((int)$deferred['lessons'], 'lesson'); ?> across <?php echo plural((int)$deferred['n'], 'active package'); ?>,
          valued pro-rata at the package price.
        </p>
        <?php if (!$attributionPossible): ?>
          <p class="text-xs text-amber-300/70 mt-2">This figure only falls when a completed lesson is linked to its package, which is not happening yet &mdash; see the note on the Overview tab.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Package modal (create + edit share one form) -->
  <div id="pkgModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="modal-backdrop absolute inset-0 bg-black/70"></div>
    <div class="relative card w-full max-w-3xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-white font-semibold text-lg" id="pkgTitle">New package</h3>
        <button type="button" class="modal-close text-gray-400 hover:text-white text-xl leading-none">&times;</button>
      </div>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" id="pkgAction" value="create_package">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="redirect" value="<?php echo h(qs()); ?>">
        <input type="hidden" name="package_id" id="pkgId" value="">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div><label class="text-xs text-gray-400">Name</label><input name="name" id="pkgName" class="form-input" required maxlength="255"></div>
          <div><label class="text-xs text-gray-400">Description</label><input name="description" id="pkgDesc" class="form-input"></div>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div><label class="text-xs text-gray-400">Lessons / week</label><input type="number" min="1" max="21" name="lessons_per_week" id="pkgLpw" class="form-input" required></div>
          <div><label class="text-xs text-gray-400">Price (<?php echo h($currency_code); ?>)</label><input type="number" step="0.01" min="0.01" name="price" id="pkgPrice" class="form-input" required></div>
          <div><label class="text-xs text-gray-400">Duration (days)</label><input type="number" min="1" max="3650" name="duration_days" id="pkgDays" class="form-input" required></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div><label class="text-xs text-gray-400">Stripe Payment Link</label><input name="checkout_url_stripe" id="pkgStripe" class="form-input" placeholder="https://buy.stripe.com/..."></div>
          <div><label class="text-xs text-gray-400">PayChangu checkout</label><input name="checkout_url_paychangu" id="pkgPaychangu" class="form-input" placeholder="https://..."></div>
          <div><label class="text-xs text-gray-400">Generic checkout</label><input name="checkout_url_generic" id="pkgGeneric" class="form-input" placeholder="https://..."></div>
        </div>
        <p class="text-xs text-gray-500">A student cannot buy a package with no checkout link. Clear a field to remove that link.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="text-xs text-gray-400">Subjects covered</label>
            <select name="subject_ids[]" id="pkgSubjects" class="form-input" multiple size="7">
              <?php foreach ($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?></option><?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">Ctrl / Cmd to multi-select.</p>
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-300 mt-6 self-start">
            <input type="checkbox" name="is_active" id="pkgActive" class="w-4 h-4 rounded" checked>
            <span>Active &mdash; can be assigned and bought</span>
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="modal-close bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold px-4 py-2 rounded-lg" id="pkgSubmit">Create package</button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php if ($view === 'analytics'): ?>

  <?php
    $att = $an['attendance'];
    $done = $att['Completed'] ?? 0;
    $noShow = $att['NoShow'] ?? 0;
    $cancelled = ($att['CancelledByTutor'] ?? 0) + ($att['CancelledByStudent'] ?? 0);
    $held = $an['lessonsTotalWindow'] - ($att['Scheduled'] ?? 0) - ($att['InProgress'] ?? 0);
    $showUpRate = $held > 0 ? round(100 * $done / $held, 1) : null;
  ?>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card p-4"><p class="text-xs text-gray-400">Lessons completed</p><p class="text-2xl font-extrabold text-white mt-1"><?php echo number_format($an['completed']); ?></p><p class="text-xs text-gray-500"><?php echo $include_demos ? 'demos included' : 'demos excluded'; ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Show-up rate</p><p class="text-2xl font-extrabold <?php echo $showUpRate === null ? 'text-gray-500' : ($showUpRate >= 85 ? 'text-emerald-400' : 'text-amber-400'); ?> mt-1"><?php echo $showUpRate === null ? '&mdash;' : $showUpRate . '%'; ?></p><p class="text-xs text-gray-500"><?php echo plural($noShow, 'no-show'); ?>, <?php echo plural($cancelled, 'cancellation'); ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Average rating</p><p class="text-2xl font-extrabold text-white mt-1"><?php echo $an['avgRating'] === null ? '&mdash;' : number_format($an['avgRating'], 2); ?></p><p class="text-xs text-gray-500"><?php echo $an['ratingsN'] ? 'from ' . plural($an['ratingsN'], 'rating') : 'no ratings in window'; ?></p></div>
    <div class="card p-4"><p class="text-xs text-gray-400">Collected</p><p class="text-2xl font-extrabold text-emerald-400 mt-1"><?php echo $money($kCollected['amt']); ?></p><p class="text-xs text-gray-500"><?php echo $an['completed'] > 0 ? $money($kCollected['amt'] / $an['completed']) . ' per lesson delivered' : 'no lessons delivered'; ?></p></div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 space-y-6">

      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-white">Tutor performance</h3>
          <span class="text-xs text-gray-500"><?php echo h($windowLabel); ?></span>
        </div>
        <?php if (!$an['tutors']): ?>
          <p class="text-gray-400 text-sm">No lessons in this window<?php echo $f_tutor ? ' for that tutor' : ''; ?>.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-gray-400 text-xs uppercase tracking-wide border-b border-gray-700">
                  <th class="text-left px-2 py-2 font-semibold">Tutor</th>
                  <th class="text-right px-2 py-2 font-semibold">Completed</th>
                  <th class="text-right px-2 py-2 font-semibold">No-shows</th>
                  <th class="text-right px-2 py-2 font-semibold">Rating</th>
                  <th class="text-right px-2 py-2 font-semibold">Grading</th>
                  <th class="text-right px-2 py-2 font-semibold">Revenue share</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($an['tutors'] as $t): ?>
                  <tr class="border-b border-gray-800/70 hover:bg-gray-800/40">
                    <td class="px-2 py-2.5"><span class="font-semibold text-white"><?php echo h($t['name']); ?></span></td>
                    <td class="px-2 py-2.5 text-right text-white"><?php echo $t['completed']; ?><span class="text-gray-600 text-xs"> / <?php echo $t['total']; ?></span></td>
                    <td class="px-2 py-2.5 text-right <?php echo $t['noshow'] > 0 ? 'text-amber-400' : 'text-gray-500'; ?>"><?php echo $t['noshow']; ?></td>
                    <td class="px-2 py-2.5 text-right text-white">
                      <?php echo $t['rating'] === null ? '<span class="text-gray-600">&mdash;</span>' : number_format($t['rating'], 2); ?>
                      <?php if ($t['ratingsN']): ?><span class="text-gray-600 text-xs"> (<?php echo $t['ratingsN']; ?>)</span><?php endif; ?>
                    </td>
                    <td class="px-2 py-2.5 text-right text-gray-300"><?php echo $t['resp'] === null ? '<span class="text-gray-600">&mdash;</span>' : number_format($t['resp'], 1) . 'h'; ?></td>
                    <td class="px-2 py-2.5 text-right">
                      <?php if (!$attributionPossible): ?>
                        <span class="text-gray-600 text-xs" title="Needs lessons.student_package_id, which is NULL on every lesson">not attributable</span>
                      <?php else: ?>
                        <span class="text-white font-semibold"><?php echo $money($t['revenue'] ?? 0); ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            Grading is the mean hours between a homework submission and its grade. Revenue share splits each package&rsquo;s confirmed payments
            across the tutors who taught its lessons&nbsp;&mdash; it needs a lesson to be linked to the package that paid for it.
          </p>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
          <h3 class="text-lg font-semibold text-white mb-4">How lessons ended</h3>
          <?php if (!$an['lessonsTotalWindow']): ?>
            <p class="text-gray-400 text-sm">No lessons in this window.</p>
          <?php else: ?>
            <div class="h-56"><canvas id="attChart"></canvas></div>
          <?php endif; ?>
        </div>
        <div class="card p-6">
          <h3 class="text-lg font-semibold text-white mb-4">Completed by subject</h3>
          <?php if (!$an['bySubject']): ?>
            <p class="text-gray-400 text-sm">No completed lessons in this window.</p>
          <?php else: ?>
            <div class="h-56"><canvas id="subChart"></canvas></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card p-6">
        <div class="flex items-start justify-between mb-2">
          <div>
            <h3 class="text-lg font-semibold text-white">Homework turned in</h3>
            <p class="text-xs text-gray-500">Percent of homework assigned in each week that has at least one submission</p>
          </div>
          <span class="text-xs text-gray-500">12 weeks to <?php echo h($f_to); ?></span>
        </div>
        <?php if (array_sum($an['hwAssigned']) === 0): ?>
          <p class="text-gray-400 text-sm">No homework was assigned in these 12 weeks<?php echo ($f_tutor || $f_student || $f_subject) ? ' under the current filters' : ''; ?>.</p>
        <?php else: ?>
          <div class="h-56"><canvas id="hwChart"></canvas></div>
          <p class="text-xs text-gray-500 mt-2">Weeks with nothing assigned are left blank rather than drawn as 0%.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="space-y-6">
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-1">Activity</h3>
        <p class="text-xs text-gray-500 mb-4">Lessons, homework and payments per day &middot; 52 weeks to <?php echo h($f_to); ?></p>
        <?php if ($an['heatMax'] === 0): ?>
          <p class="text-gray-400 text-sm">Nothing recorded in the last 52 weeks.</p>
        <?php else: ?>
          <div class="overflow-x-auto pb-2">
            <div class="heat-grid" id="heatGrid">
              <?php
                // Leading blanks so the first column starts on the right weekday.
                for ($i = 1; $i < $an['heat'][0]['dow']; $i++) echo '<div></div>';
                foreach ($an['heat'] as $cell) {
                    $q = $an['heatMax'] > 0 ? $cell['n'] / $an['heatMax'] : 0;
                    $bg = $cell['n'] === 0 ? '#1f2937' : ($q > .75 ? '#10b981' : ($q > .5 ? '#059669' : ($q > .25 ? '#065f46' : '#064e3b')));
                    printf('<div class="heat-cell" style="background:%s" title="%s on %s"></div>',
                        $bg, h(plural($cell['n'], 'activity', 'activities')), h((new DateTime($cell['d']))->format('D, M j Y')));
                }
              ?>
            </div>
          </div>
          <div class="flex items-center justify-end gap-1.5 text-xs text-gray-500 mt-3">
            Less
            <?php foreach (['#1f2937','#064e3b','#065f46','#059669','#10b981'] as $c): ?>
              <span class="heat-cell" style="background:<?php echo $c; ?>"></span>
            <?php endforeach; ?>
            More <span class="text-gray-600 ml-1">(peak <?php echo (int)$an['heatMax']; ?>/day)</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Revenue in this window</h3>
        <?php if (!$revByPackage): ?>
          <p class="text-gray-400 text-sm">Nothing collected.</p>
        <?php else: ?>
          <p class="text-2xl font-extrabold text-emerald-400"><?php echo $money($kCollected['amt']); ?></p>
          <div class="mt-4 space-y-1.5">
            <?php foreach ($revByPackage as $rp): ?>
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-300 truncate pr-2"><?php echo h($rp['name']); ?></span>
                <span class="text-white font-semibold whitespace-nowrap"><?php echo $money($rp['amt']); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <a href="<?php echo h(qs(['view'=>'overview'])); ?>" class="inline-block mt-4 text-sm text-indigo-400 hover:text-indigo-300 font-semibold">Revenue detail &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

  <!-- ------------------------------------------ Confirm payment (shared) -->
  <div id="confirmModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="modal-backdrop absolute inset-0 bg-black/70"></div>
    <div class="relative card w-full max-w-md mx-4 p-6">
      <div class="flex items-center justify-between mb-1">
        <h3 class="text-white font-semibold text-lg">Confirm payment</h3>
        <button type="button" class="modal-close text-gray-400 hover:text-white text-xl leading-none">&times;</button>
      </div>
      <p class="text-sm text-gray-400 mb-4" id="cfSummary"></p>
      <form method="post" class="space-y-3" id="confirmForm">
        <input type="hidden" name="action" value="confirm_payment">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="redirect" value="<?php echo h(qs(['p' => $page_no])); ?>">
        <input type="hidden" name="payment_id" id="cfId" value="">
        <div>
          <label class="text-xs text-gray-400">Method</label>
          <select name="payment_method" id="cfMethod" class="form-input">
            <?php foreach (PAY_METHODS as $m): ?><option><?php echo h($m); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label class="text-xs text-gray-400">Transaction ID</label><input name="transaction_id" id="cfTx" class="form-input" placeholder="bank reference, Stripe id..."></div>
        <div><label class="text-xs text-gray-400">Provider session</label><input name="provider_session_id" id="cfSession" class="form-input" placeholder="checkout.session id"></div>
        <p class="text-xs text-gray-500">This also activates the student&rsquo;s package if it is still Pending.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="modal-close bg-gray-700 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded-lg">Cancel</button>
          <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">Confirm payment</button>
        </div>
      </form>
    </div>
  </div>

  </main>

<script>
(function () {
  'use strict';

  // ---- Modals. The old page bound its backdrop click to the modal element while
  // a full-size overlay div sat on top of it, so clicking outside never closed
  // anything; the class is on the overlay itself now, and Escape works.
  function open(el)  { el.classList.remove('hidden'); el.classList.add('flex'); document.body.style.overflow = 'hidden'; }
  function close(el) { el.classList.add('hidden');    el.classList.remove('flex'); document.body.style.overflow = ''; }
  function closeAll(){ document.querySelectorAll('.fixed.inset-0').forEach(function (m) { if (!m.classList.contains('hidden')) close(m); }); }

  document.querySelectorAll('.modal-close').forEach(function (b) {
    b.addEventListener('click', function () { close(b.closest('.fixed.inset-0')); });
  });
  document.querySelectorAll('.modal-backdrop').forEach(function (b) {
    b.addEventListener('click', function () { close(b.closest('.fixed.inset-0')); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

  // ---- Confirm payment
  var cm = document.getElementById('confirmModal');
  document.querySelectorAll('.open-confirm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = btn.dataset;
      document.getElementById('cfId').value = d.id || '';
      document.getElementById('cfTx').value = d.tx || '';
      document.getElementById('cfSession').value = d.session || '';
      var sel = document.getElementById('cfMethod');
      sel.value = (d.method && [].slice.call(sel.options).some(function (o) { return o.value === d.method; })) ? d.method : 'Manual';
      document.getElementById('cfSummary').textContent = (d.amount || '') + ' from ' + (d.who || 'this student') + '.';
      open(cm);
    });
  });

  // ---- Package create / edit share one form
  var pm = document.getElementById('pkgModal');
  document.querySelectorAll('.open-pkg').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = btn.dataset, edit = d.mode === 'edit';
      document.getElementById('pkgAction').value = edit ? 'update_package' : 'create_package';
      document.getElementById('pkgTitle').textContent = edit ? 'Edit package' : 'New package';
      document.getElementById('pkgSubmit').textContent = edit ? 'Save changes' : 'Create package';
      document.getElementById('pkgId').value        = edit ? d.id : '';
      document.getElementById('pkgName').value      = edit ? d.name : '';
      document.getElementById('pkgDesc').value      = edit ? d.desc : '';
      document.getElementById('pkgLpw').value       = edit ? d.lpw : '';
      document.getElementById('pkgPrice').value     = edit ? d.price : '';
      document.getElementById('pkgDays').value      = edit ? d.days : '';
      document.getElementById('pkgStripe').value    = edit ? d.stripe : '';
      document.getElementById('pkgPaychangu').value = edit ? d.paychangu : '';
      document.getElementById('pkgGeneric').value   = edit ? d.generic : '';
      document.getElementById('pkgActive').checked  = edit ? d.active === '1' : true;
      var want = edit && d.subjects ? d.subjects.split(',') : [];
      [].forEach.call(document.getElementById('pkgSubjects').options, function (o) { o.selected = want.indexOf(o.value) !== -1; });
      open(pm);
    });
  });

  var rec = document.getElementById('openRecord');
  if (rec) rec.addEventListener('click', function () { open(document.getElementById('recordModal')); });

  // Submitting any form once is enough — a double click used to post twice.
  document.querySelectorAll('form[method="post"]').forEach(function (f) {
    f.addEventListener('submit', function () {
      f.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (b) {
        setTimeout(function () { b.disabled = true; b.style.opacity = '.6'; }, 0);
      });
    });
  });

  // ---- Charts
  var GRID = 'rgba(255,255,255,.08)', TICK = '#9ca3af';
  var CUR = <?php echo json_encode($C); ?>;
  function fmt(v) { return CUR + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  var el = document.getElementById('revChart');
  if (el && window.Chart) {
    new Chart(el, {
      type: <?php echo json_encode($granularity === 'daily' ? 'line' : 'bar'); ?>,
      data: {
        labels: <?php echo json_encode($revLabels, JSON_UNESCAPED_SLASHES); ?>,
        datasets: [{
          label: 'Collected', data: <?php echo json_encode($revValues); ?>,
          backgroundColor: 'rgba(16,185,129,.55)', borderColor: '#10b981', borderWidth: 2,
          borderRadius: 4, tension: .3, fill: true, pointRadius: 3
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return fmt(c.parsed.y) + '  (' + <?php echo json_encode($revCounts); ?>[c.dataIndex] + ' payments)'; } } }
        },
        scales: {
          y: { beginAtZero: true, grid: { color: GRID }, ticks: { color: TICK, callback: function (v) { return CUR + v; } } },
          x: { grid: { display: false }, ticks: { color: TICK } }
        }
      }
    });
  }

  el = document.getElementById('pkgChart');
  if (el && window.Chart) {
    new Chart(el, {
      type: 'doughnut',
      data: {
        labels: <?php echo json_encode(array_column($revByPackage, 'name'), JSON_UNESCAPED_SLASHES); ?>,
        datasets: [{
          data: <?php echo json_encode(array_map(fn($r) => round((float)$r['amt'], 2), $revByPackage)); ?>,
          backgroundColor: ['#34d399','#60a5fa','#a78bfa','#f472b6','#fbbf24','#22d3ee','#93c5fd','#86efac'],
          borderColor: '#0f1623', borderWidth: 3
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + fmt(c.parsed); } } }
        }
      }
    });
  }

  el = document.getElementById('attChart');
  if (el && window.Chart) {
    var att = <?php echo json_encode($view === 'analytics' ? $an['attendance'] : new stdClass()); ?>;
    var COL = { Completed: '#10b981', NoShow: '#f59e0b', CancelledByTutor: '#ef4444', CancelledByStudent: '#fb7185', Scheduled: '#64748b', InProgress: '#6366f1' };
    var keys = Object.keys(att);
    new Chart(el, {
      type: 'doughnut',
      data: { labels: keys, datasets: [{ data: keys.map(function (k) { return att[k]; }), backgroundColor: keys.map(function (k) { return COL[k] || '#94a3b8'; }), borderColor: '#0f1623', borderWidth: 3 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1', boxWidth: 10, font: { size: 11 } } } } }
    });
  }

  el = document.getElementById('subChart');
  if (el && window.Chart) {
    new Chart(el, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($view === 'analytics' ? array_column($an['bySubject'], 'label') : [], JSON_UNESCAPED_SLASHES); ?>,
        datasets: [{ data: <?php echo json_encode($view === 'analytics' ? array_map('intval', array_column($an['bySubject'], 'c')) : []); ?>, backgroundColor: 'rgba(96,165,250,.75)', borderRadius: 4 }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, grid: { color: GRID }, ticks: { color: TICK, precision: 0 } }, y: { grid: { display: false }, ticks: { color: TICK } } }
      }
    });
  }

  el = document.getElementById('hwChart');
  if (el && window.Chart) {
    new Chart(el, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($view === 'analytics' ? $an['hwLabels'] : [], JSON_UNESCAPED_SLASHES); ?>,
        datasets: [{ data: <?php echo json_encode($view === 'analytics' ? $an['hwRate'] : []); ?>, borderColor: '#60a5fa', backgroundColor: 'rgba(96,165,250,.12)', borderWidth: 2, tension: .3, pointRadius: 3, fill: true, spanGaps: false }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.parsed.y + '% of ' + <?php echo json_encode($view === 'analytics' ? $an['hwAssigned'] : []); ?>[c.dataIndex] + ' assigned'; } } } },
        scales: { y: { beginAtZero: true, max: 100, grid: { color: GRID }, ticks: { color: TICK, callback: function (v) { return v + '%'; } } }, x: { grid: { display: false }, ticks: { color: TICK } } }
      }
    });
  }
})();
</script>
</body>
</html>
