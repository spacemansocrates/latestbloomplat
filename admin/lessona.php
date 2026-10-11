<?php
// lessona.php — the calendar now lives inside admin_lessons.php (?view=calendar).
//
// This page, admin_lessons.php and admin_daily_lessons.php were three screens over
// the same data that disagreed about timezones, what counts as a demo, and which
// statuses exist. They are one page with four views now. This stub keeps old links,
// bookmarks and the sidebar working by translating the query string it used to take.

$map = [
  'tutor_id'   => 'tutor_id',
  'student_id' => 'student_id',
  'subject_id' => 'subject_id',
  'status'     => 'status',
];
$q = ['view' => 'calendar'];
foreach ($map as $from => $to) {
  if (isset($_GET[$from]) && $_GET[$from] !== '') $q[$to] = $_GET[$from];
}
// The old calendar took a visible range as start/end; a month is the closest fit.
if (isset($_GET['start']) && preg_match('/^(\d{4}-\d{2})/', (string)$_GET['start'], $m)) {
  $q['month'] = $m[1];
}
// ?id=N used to deep-link one lesson; open the day it falls on instead.
if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
  require_once __DIR__ . '/../config/db_connect.php';
  require_once __DIR__ . '/../utils/timezone_helper.php';
  session_start();
  try {
    $pdo = getDbConnection();
    $pdo->exec("SET time_zone = '+00:00'");
    $s = $pdo->prepare("SELECT scheduled_start_time FROM lessons WHERE id = ?");
    $s->execute([(int)$_GET['id']]);
    if ($t = $s->fetchColumn()) {
      $q = ['view' => 'day', 'date' => utcToLocal($t, getUserTimezone())->format('Y-m-d')];
    }
  } catch (Throwable $e) { /* fall through to the calendar */ }
}

header('Location: admin_lessons.php?' . http_build_query($q), true, 302);
exit;
