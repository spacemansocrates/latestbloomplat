<?php
/**
 * Student Packages Controller
 * 
 * Handles package selection and checkout process
 */
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include database connection
require_once __DIR__ . '/../config/db_connect.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit();
}
// Initialize variables
$student_id = (int)$_SESSION['user_id'];
$errors = [];
// money_symbol() had no definition in this file, so reaching this page was an
// instant fatal. It is now shared (the TODO that used to sit here).
require_once __DIR__ . '/../utils/money.php';
$C = money_symbol(billing_currency_code($pdo, $_SESSION['currency'] ?? null));

// Check if we're being included as a tab or standalone
$is_tab = isset($is_tab) ? (bool)$is_tab : false;

// Get user's currency
$currency_code = 'USD';
$C = money_symbol($currency_code);

try {
    $stmt = $pdo->prepare("SELECT COALESCE(currency_code, 'USD') FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $currency_code = $stmt->fetchColumn();
    $C = money_symbol($currency_code);
} catch (PDOException $e) {
    error_log('Failed to fetch user currency: ' . $e->getMessage());
    $errors[] = 'Failed to load your account information. Please try again.';
}

/**
 * Handles the package selection and initiates checkout
 * 
 * @param PDO $pdo Database connection
 * @param int $student_id User ID
 * @param array &$errors Array to collect error messages
 * @return void
 */
function handlePackageSelection(PDO $pdo, int $student_id, array &$errors): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'], $_POST['package_id']) || $_POST['action'] !== 'start_checkout') {
        return;
    }
    // The package cards on student/settings.php carry the session token; without
    // it any site could start a checkout (and a pending package) for a student.
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $_POST['csrf_token'])) {
        $errors[] = 'That form expired. Please reload the page and try again.';
        return;
    }

    $pkg_id = (int)$_POST['package_id'];
    
    try {
        // Load package details
        $stmt = $pdo->prepare("
            SELECT id, name, lessons_per_week, price, duration_days 
            FROM packages 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$pkg_id]);
        $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pkg) {
            $errors[] = 'Selected package is not available.';
            return;
        }

        $pdo->beginTransaction();

        // Check for existing pending package
        $stmt = $pdo->prepare("
            SELECT id 
            FROM student_packages 
            WHERE student_id = ? AND package_id = ? AND status = 'Pending' 
            ORDER BY id DESC 
            LIMIT 1");
        
        $stmt->execute([$student_id, $pkg_id]);
        $sp_id = $stmt->fetchColumn();

        // Create new student package if none exists
        if (!$sp_id) {
            $weeks = (int)ceil(max(1, (int)$pkg['duration_days']) / 7);
            $total_lessons = (int)$pkg['lessons_per_week'] * $weeks;

            // start_date/end_date are NOT NULL with no default. Passing NULL
            // aborted the whole purchase under STRICT_TRANS_TABLES (production),
            // so the package window is set here and simply runs from today.
            $stmt = $pdo->prepare("
                INSERT INTO student_packages (
                    student_id, package_id, start_date, end_date, 
                    total_lessons, lessons_remaining, status, created_at
                ) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, 'Pending', NOW())
            ");
            
            $stmt->execute([
                $student_id, 
                $pkg_id, 
                max(1, (int)$pkg['duration_days']),
                $total_lessons, 
                $total_lessons
            ]);
            
            $sp_id = (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        // Hand off to payment processor
        echo '<form id="handoff" method="post" action="api/pay_start.php">
                <input type="hidden" name="sp_id" value="' . (int)$sp_id . '">
                <input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">
              </form>
              <script>document.getElementById("handoff").submit();</script>';
        exit();
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Package selection failed: ' . $e->getMessage());
        $errors[] = 'Failed to process your request. Please try again.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unexpected error in package selection: ' . $e->getMessage());
        $errors[] = 'An unexpected error occurred. Please try again.';
    }
}

// Handle package selection and checkout
handlePackageSelection($pdo, $student_id, $errors);

/**
 * Fetches all active packages
 * 
 * @param PDO $pdo Database connection
 * @return array List of active packages
 */
function fetchActivePackages(PDO $pdo): array {
    try {
        $stmt = $pdo->query("
            SELECT * 
            FROM packages 
            WHERE is_active = 1 
            ORDER BY price ASC");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Failed to fetch packages: ' . $e->getMessage());
        return [];
    }
}

// Get active packages
$packages = fetchActivePackages($pdo);

$current_page='payments'; // same group as payments
?>
