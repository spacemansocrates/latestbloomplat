<?php
// =============================================================================
// earnings.php (Rewritten for Perfection)
//
// This script provides a tutor-facing earnings dashboard. It is designed to be
// secure, maintainable, and robust.
//
// Pay Rules (Applied consistently across all calculations):
// - Completed & Demo (student_id IS NULL): DEMO_PAY_HOURS * hourly_rate
// - Completed & Normal: (duration_in_minutes / 60) * hourly_rate
// - NoShow: NOSHOW_PAY_HOURS * hourly_rate
// - Any other status: 0
// =============================================================================

 require_once __DIR__ . '/../auth_tutor_only.php';
 
 /**
  * @var PDO $pdo
  */

require_once __DIR__ . '/../../utils/tutor_earnings.php';

// Configuration
$current_page = 'earnings';
$tutor_id     = (int)$_SESSION['user_id'];
$tutor_name   = $_SESSION['user_first_name'] ?? 'Tutor';
date_default_timezone_set('Africa/Blantyre');

// --- 2. HELPERS & DATABASE CONNECTION ---
require_once __DIR__ . '/../../config/db_connect.php';

// --- 3. CORE BUSINESS LOGIC: CENTRALIZED EARNINGS CALCULATION ---

// This SQL snippet is the single source of truth for calculating paid hours.
// Reusing it prevents inconsistencies and makes updating pay rules trivial.
$sqlPaidHoursClause = tutor_paid_hours_sql();

// This snippet calculates the final amount by multiplying hours by the hourly rate.
// The '?' is a placeholder for the prepared statement.
$sqlAmountClause = "({$sqlPaidHoursClause}) * ?";


// --- 4. SETUP & INITIALIZATION ---

// Ensure payout settings table exists.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tutor_payout_settings (
        tutor_id INT UNSIGNED NOT NULL,
        hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'USD',
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tutor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Generate CSRF token if it doesn't exist.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- 5. HANDLE POST REQUESTS (SETTINGS UPDATE) ---
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form_name'] ?? '') === 'earnings') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $flash = ['type' => 'error', 'msg' => 'Invalid request. Please try again.'];
    } else {
        $rate     = trim($_POST['hourly_rate'] ?? '');
        $currency = trim($_POST['currency'] ?? '');

        if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate < 0 || (float)$rate > 99999999.99 || !in_array($currency, ['USD','EUR','GBP','MWK','ZAR','KES','TZS'], true)) {
            $flash = ['type' => 'error', 'msg' => 'Please enter a valid non-negative hourly rate.'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tutor_payout_settings (tutor_id, hourly_rate, currency)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), currency = VALUES(currency)
            ");
            $stmt->execute([$tutor_id, (float)$rate, $currency]);
            $flash = ['type' => 'success', 'msg' => 'Earnings settings updated successfully.'];
        }
    }
}

// --- 6. LOAD DATA ---

// Load tutor's payout settings
$stmt = $pdo->prepare("SELECT hourly_rate, currency FROM tutor_payout_settings WHERE tutor_id = ?");
$stmt->execute([$tutor_id]);
$settings    = $stmt->fetch();
$hourly_rate = (float)($settings['hourly_rate'] ?? 0.00);
$currency    = $settings['currency'] ?? 'USD';

// Currency symbol mapping
$currency_symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'MWK' => 'MK', 'ZAR' => 'R', 'KES' => 'KSh', 'TZS' => 'TSh'];
$currency_symbol  = $currency_symbols[$currency] ?? ($currency . ' ');

// Date range for the view (defaults to the current month)
try {
    $viewMonthParam = $_GET['view_month'] ?? 'now';
    // Validate YYYY-MM format
    if (preg_match('/^\d{4}-\d{2}$/', $viewMonthParam)) {
        $viewStart = new DateTimeImmutable("{$viewMonthParam}-01 00:00:00");
    } else {
        $viewStart = new DateTimeImmutable('first day of this month 00:00:00');
    }
    $viewEnd = $viewStart->modify('first day of next month 00:00:00');
} catch (Exception $e) {
    // Fallback if date parsing fails
    $viewStart = new DateTimeImmutable('first day of this month 00:00:00');
    $viewEnd   = $viewStart->modify('first day of next month 00:00:00');
}

$viewMonthLabel = $viewStart->format('M Y');
$nextPayoutDate = $viewEnd->format('M j, Y');

// Date range for the chart (last 6 months, ending with the selected month)
$chartStart = $viewStart->modify('-5 months');
$chartEnd   = $viewEnd;

// --- 7. HANDLE CSV DOWNLOAD ---

if (isset($_GET['download']) && $_GET['download'] === '1') {
    try {
        $mode = $_GET['mode'] ?? 'month';
        if ($mode === 'range' && !empty($_GET['start_date']) && !empty($_GET['end_date'])) {
            $start = new DateTimeImmutable($_GET['start_date'] . ' 00:00:00');
            $end   = (new DateTimeImmutable($_GET['end_date'] . ' 00:00:00'))->modify('+1 day');
            if ($start > $end) { list($start, $end) = [$end, $start]; } // Swap if inverted
            $filename_suffix = $start->format('Ymd') . '-' . $end->modify('-1 day')->format('Ymd');
        } else {
            // Default to 'month' mode, using either the provided month or the view month
            $csvMonth = !empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : $viewStart->format('Y-m');
            $start = new DateTimeImmutable("{$csvMonth}-01 00:00:00");
            $end   = $start->modify('first day of next month');
            $filename_suffix = $start->format('Ym');
        }
    } catch (Exception $e) {
        die("Invalid date format provided for download.");
    }

    $stmtCsv = $pdo->prepare("
        SELECT
            l.scheduled_start_time, l.status, s.name AS subject_name,
            u.first_name, u.last_name,
            ({$sqlPaidHoursClause}) AS paid_hours,
            ({$sqlAmountClause}) AS amount
        FROM lessons l
        JOIN subjects s ON s.id = l.subject_id
        LEFT JOIN users u ON u.id = l.student_id
        WHERE l.tutor_id = ? AND l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
        ORDER BY l.scheduled_start_time ASC
    ");
    $stmtCsv->execute([$hourly_rate, $tutor_id, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=earnings_report_' . $filename_suffix . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Subject', 'Student', 'Paid Hours', 'Status', "Amount ({$currency})"]);

    while ($row = $stmtCsv->fetch()) {
        $student = (is_null($row['first_name'])) ? 'Demo Lesson' : trim($row['first_name'] . ' ' . $row['last_name']);
        fputcsv($output, [
            (new DateTime($row['scheduled_start_time']))->format('Y-m-d H:i'),
            $row['subject_name'],
            $student,
            number_format((float)$row['paid_hours'], 2),
            $row['status'],
            number_format((float)$row['amount'], 2),
        ]);
    }
    fclose($output);
    exit();
}


// --- 8. FETCH PAGE DATA ---

// Available for Payout (selected month)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM({$sqlAmountClause}), 0) AS total
    FROM lessons l
    WHERE l.tutor_id = ? AND l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
");
$stmt->execute([$hourly_rate, $tutor_id, $viewStart->format('Y-m-d H:i:s'), $viewEnd->format('Y-m-d H:i:s')]);
$available_for_payout = (float)$stmt->fetchColumn();

// Total Earned (all time)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM({$sqlAmountClause}), 0) AS total
    FROM lessons l
    WHERE l.tutor_id = ?
");
$stmt->execute([$hourly_rate, $tutor_id]);
$total_earned = (float)$stmt->fetchColumn();

// Transaction History (selected month)
$stmt = $pdo->prepare("
    SELECT
        l.id, l.scheduled_start_time, l.status, s.name AS subject_name,
        u.first_name, u.last_name, u.profile_photo_url,
        ({$sqlAmountClause}) AS amount
    FROM lessons l
    JOIN subjects s ON s.id = l.subject_id
    LEFT JOIN users u ON u.id = l.student_id
    WHERE l.tutor_id = ? AND l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
    ORDER BY l.scheduled_start_time DESC
    LIMIT 200
");
$stmt->execute([$hourly_rate, $tutor_id, $viewStart->format('Y-m-d H:i:s'), $viewEnd->format('Y-m-d H:i:s')]);
$transactions = $stmt->fetchAll();

// Monthly earnings data for chart (last 6 months)
$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(l.scheduled_start_time, '%Y-%m') AS yearmonth,
        COALESCE(SUM({$sqlAmountClause}), 0) AS total
    FROM lessons l
    WHERE l.tutor_id = ? AND l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
    GROUP BY yearmonth
    ORDER BY yearmonth ASC
");
$stmt->execute([$hourly_rate, $tutor_id, $chartStart->format('Y-m-d H:i:s'), $chartEnd->format('Y-m-d H:i:s')]);
$monthlyTotals = array_column($stmt->fetchAll(), 'total', 'yearmonth');

// Prepare chart data, filling in zero for months with no earnings
$chartLabels = [];
$chartData   = [];
$interval = new DateInterval('P1M');
$period = new DatePeriod($chartStart, $interval, 6);
foreach ($period as $date) {
    $key = $date->format('Y-m');
    $chartLabels[] = $date->format('M');
    $chartData[]   = round((float)($monthlyTotals[$key] ?? 0.0), 2);
}

// Mini-stats for the selected month
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN l.status = 'Completed' AND l.student_id IS NULL THEN 1 ELSE 0 END), 0) AS demos,
        COALESCE(SUM(CASE WHEN l.status = 'Completed' AND l.student_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS regulars,
        COALESCE(SUM(CASE WHEN l.status = 'NoShow' THEN 1 ELSE 0 END), 0) AS noshows,
        COALESCE(SUM({$sqlPaidHoursClause}), 0) AS paid_hours,
        COALESCE(SUM({$sqlAmountClause}), 0) AS paid_amount
    FROM lessons l
    WHERE l.tutor_id = ? AND l.scheduled_start_time >= ? AND l.scheduled_start_time < ?
");
$stmt->execute([$hourly_rate, $tutor_id, $viewStart->format('Y-m-d H:i:s'), $viewEnd->format('Y-m-d H:i:s')]);
$mini_stats = $stmt->fetch() ?: ['demos' => 0, 'regulars' => 0, 'noshows' => 0, 'paid_hours' => 0, 'paid_amount' => 0];

// --- 9. UI HELPERS ---

function statusPill(string $status): array
{
    return match ($status) {
        'Completed' => ['Paid', 'bg-green-500/10 text-green-400'],
        'NoShow'    => ['No Show (Paid)', 'bg-yellow-500/10 text-yellow-400'],
        default     => [$status, 'bg-gray-500/10 text-gray-300'],
    };
}
