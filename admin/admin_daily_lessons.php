<?php
// admin_daily_lessons.php — the day view now lives inside admin_lessons.php
// (?view=day). See the note in lessona.php. This stub translates the old query
// string so existing links and bookmarks keep working.

$map = [
  'date'                  => 'date',
  'filter_tutor_id'       => 'tutor_id',
  'filter_student_id'     => 'student_id',
  'filter_subject_id'     => 'subject_id',
  'filter_customer_code'  => 'code',
  'filter_year_level'     => 'year',
  'filter_lesson_type'    => 'type',
  'filter_tutor_query'    => 'q',
  'filter_student_query'  => 'q',
];
$q = ['view' => 'day'];
foreach ($map as $from => $to) {
  if (isset($_GET[$from]) && $_GET[$from] !== '' && !isset($q[$to])) $q[$to] = $_GET[$from];
}

header('Location: admin_lessons.php?' . http_build_query($q), true, 302);
exit;
