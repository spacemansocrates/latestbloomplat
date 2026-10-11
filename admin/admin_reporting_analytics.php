<?php
// admin_reporting_analytics.php — retired 2026-09-08.
//
// Its charts, tutor table and activity heatmap are the Analytics view of
// admin_revenue.php, which shares one filter set with the money it reports on.
// This stub only translates the old query string. Do not add features here.

$q = ['view' => 'analytics'];

foreach (['from', 'to'] as $k) {
    if (isset($_GET[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET[$k])) $q[$k] = $_GET[$k];
}
foreach (['tutor_id', 'student_id', 'subject_id'] as $k) {
    if (isset($_GET[$k]) && ctype_digit((string)$_GET[$k]) && (int)$_GET[$k] > 0) $q[$k] = (int)$_GET[$k];
}
if (($_GET['include_demos'] ?? '') === '1') $q['include_demos'] = '1';

header('Location: admin_revenue.php?' . http_build_query($q), true, 301);
exit();
