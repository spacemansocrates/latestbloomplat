<?php
// admin_payments_packages.php — retired 2026-09-08.
//
// Packages, payment confirmation and the revenue chart now live in admin_revenue.php,
// alongside what used to be admin_reporting_analytics.php. This stub only translates
// the old query string so existing bookmarks, and older links, still land somewhere
// useful. Do not add features here.

$q = ['view' => 'payments'];

foreach (['from', 'to'] as $k) {
    if (isset($_GET[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET[$k])) $q[$k] = $_GET[$k];
}
foreach (['tutor_id', 'student_id', 'package_id'] as $k) {
    if (isset($_GET[$k]) && ctype_digit((string)$_GET[$k]) && (int)$_GET[$k] > 0) $q[$k] = (int)$_GET[$k];
}
if (isset($_GET['granularity']) && in_array($_GET['granularity'], ['daily','weekly','monthly','yearly'], true)) {
    // Granularity only drove the revenue chart, which is now on the overview.
    $q['view'] = 'overview';
    $q['granularity'] = $_GET['granularity'];
}

header('Location: admin_revenue.php?' . http_build_query($q), true, 301);
exit();
