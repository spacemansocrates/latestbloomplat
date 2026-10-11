<?php
// students/learning_path.php
// session_start();

$is_tab = true;

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Student') {
  header('Location: ../login.php'); exit();
}
$student_id = (int)$_SESSION['user_id'];
$is_tab = isset($_GET['tab']) && $_GET['tab'] === 'true';

require_once __DIR__ . '/../config/db_connect.php';

// ---------- Pick subject (from lessons with this student) ----------
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$subjects = [];
$st = $pdo->prepare("
  SELECT s.id, s.name, COUNT(*) cnt
  FROM lessons l
  JOIN subjects s ON s.id = l.subject_id
  WHERE l.student_id = ? AND l.status IN ('Scheduled','Completed','NoShow','CancelledByTutor','CancelledByStudent')
  GROUP BY s.id, s.name
  ORDER BY cnt DESC, s.name ASC
");
$st->execute([$student_id]);
$subjects = $st->fetchAll();

if (!$subject_id && !empty($subjects)) $subject_id = (int)$subjects[0]['id'];

// If still empty (no lessons yet), allow browsing all subjects
if (!$subject_id) {
  $all = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
  if (!empty($all)) { $subjects = $all; $subject_id = (int)$all[0]['id']; }
}

// ---------- Pull topic + subtopic structure ----------
$topics = $pdo->prepare("SELECT id, name FROM topics WHERE subject_id=? ORDER BY name");
$topics->execute([$subject_id]);
$topics = $topics->fetchAll();

$subs = $pdo->prepare("
  SELECT st.id, st.name, st.topic_id
  FROM subtopics st
  JOIN topics t ON t.id = st.topic_id
  WHERE t.subject_id=?
  ORDER BY st.name
");
$subs->execute([$subject_id]);
$subs = $subs->fetchAll();

// group subtopics by topic
$subsByTopic = [];
foreach($subs as $s){ $subsByTopic[(int)$s['topic_id']][] = $s; }

// ---------- Coverage for this student in this subject ----------
$coveredSubIds = [];
$st = $pdo->prepare("
  SELECT DISTINCT lsc.subtopic_id
  FROM lesson_subtopics_covered lsc
  JOIN lessons l ON l.id = lsc.lesson_id
  WHERE l.student_id=? AND l.subject_id=? AND l.status='Completed'
");
$st->execute([$student_id, $subject_id]);
$coveredSubIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

// Fallback: topics without subtopics — consider topic coverage
$coveredTopicIds = [];
$st = $pdo->prepare("
  SELECT DISTINCT ltc.topic_id
  FROM lesson_topics_covered ltc
  JOIN lessons l ON l.id = ltc.lesson_id
  WHERE l.student_id=? AND l.subject_id=? AND l.status='Completed'
");
$st->execute([$student_id, $subject_id]);
$coveredTopicIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

// Build per-topic progress rows
$topicRows = [];
$totalSub = 0; $doneSub = 0;
foreach ($topics as $t) {
  $tid = (int)$t['id'];
  $list = $subsByTopic[$tid] ?? [];
  $subTotal = count($list);
  $subDone  = 0;
  if ($subTotal > 0) {
    foreach ($list as $s) if (in_array((int)$s['id'], $coveredSubIds, true)) $subDone++;
  } else {
    // no subtopics: treat topic as a 1-item thing
    $subTotal = 1;
    $subDone  = in_array($tid, $coveredTopicIds, true) ? 1 : 0;
    $list = []; // keep empty; UI will just show the bar
  }
  $pct = $subTotal ? round(100*$subDone/$subTotal,1) : 0.0;
  $topicRows[] = [
    'id'=>$tid,'name'=>$t['name'],
    'sub_total'=>$subTotal,'sub_done'=>$subDone,'pct'=>$pct,
    'subs'=>$list
  ];
  $totalSub += $subTotal; $doneSub += $subDone;
}

// Overall
$overallPct = $totalSub ? round(100*$doneSub/$totalSub,1) : 0.0;

// ---------- Recent lessons timeline ----------
$recent = $pdo->prepare("
  SELECT l.id, l.scheduled_start_time, s.name AS subject_name
  FROM lessons l
  JOIN subjects s ON s.id=l.subject_id
  WHERE l.student_id=? AND l.subject_id=? AND l.status IN ('Completed','Scheduled','NoShow')
  ORDER BY l.scheduled_start_time DESC
  LIMIT 8
");
$recent->execute([$student_id, $subject_id]);
$recentLessons = $recent->fetchAll();

// For each recent completed lesson, pull a few subs covered
$recentDetails = [];
$detailStmt = $pdo->prepare("
  SELECT st.name AS sub_name, t.name AS topic_name
  FROM lesson_subtopics_covered lsc
  JOIN subtopics st ON st.id=lsc.subtopic_id
  JOIN topics t ON t.id=st.topic_id
  WHERE lsc.lesson_id=?
  ORDER BY st.name
  LIMIT 5
");
foreach ($recentLessons as $L) {
  if (!empty($L['id'])) {
    $detailStmt->execute([(int)$L['id']]);
    $recentDetails[(int)$L['id']] = $detailStmt->fetchAll();
  }
}

// ---------- Pace (last 30d) + ETA ----------
$paceRow = $pdo->prepare("
  SELECT COUNT(DISTINCT lsc.subtopic_id) AS done30
  FROM lesson_subtopics_covered lsc
  JOIN lessons l ON l.id=lsc.lesson_id
  WHERE l.student_id=? AND l.subject_id=? AND l.status='Completed'
    AND l.scheduled_start_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$paceRow->execute([$student_id,$subject_id]);
$done30 = (int)($paceRow->fetchColumn() ?: 0);
$pacePerWeek = round($done30 / 4.0, 1);
$remainingSub = max(0, $totalSub - $doneSub);
$weeksToGo = ($pacePerWeek > 0) ? ceil($remainingSub / $pacePerWeek) : null;

// ---------- Next Up (first topic with remaining subtopics) ----------
$nextTopic = null; $nextSub = null;
foreach ($topicRows as $row) {
  if ($row['sub_done'] < $row['sub_total']) {
    $nextTopic = $row;
    if (!empty($row['subs'])) {
      foreach ($row['subs'] as $s) {
        if (!in_array((int)$s['id'], $coveredSubIds, true)) { $nextSub = $s; break; }
      }
    } else {
      // topic-only (no subtopics)
      $nextSub = null;
    }
    break;
  }
}

// ---------- Suggested materials (best-effort) ----------
$suggested = [];
if ($nextTopic) {
  $hasMaterials = tableExists($pdo, 'materials');
  if ($hasMaterials) {
    // Try to detect columns
    $cols = $pdo->query("SHOW COLUMNS FROM materials")->fetchAll();
    $colNames = array_map(fn($c)=>$c['Field'], $cols);
    $cTitle = in_array('title',$colNames) ? 'title' : (in_array('name',$colNames) ? 'name' : null);
    $cUrl   = in_array('url',$colNames)   ? 'url'   : (in_array('link',$colNames) ? 'link' : null);
    $cType  = in_array('type',$colNames)  ? 'type'  : null;

    $where = [];
    $params = [];
    if ($nextSub && in_array('subtopic_id',$colNames)) { $where[] = "subtopic_id=?"; $params[] = (int)$nextSub['id']; }
    if (in_array('topic_id',$colNames))                { $where[] = "topic_id=?";   $params[] = (int)$nextTopic['id']; }
    if (in_array('subject_id',$colNames))              { $where[] = "subject_id=?"; $params[] = (int)$subject_id; }

    if (!empty($where) && $cTitle) {
      $sql = "SELECT id, ". $cTitle ." AS title";
      if ($cUrl)  $sql .= ", $cUrl AS url";
      if ($cType) $sql .= ", $cType AS type";
      $sql .= " FROM materials WHERE ".implode(' OR ', $where)." ORDER BY id DESC LIMIT 6";
      $st = $pdo->prepare($sql); $st->execute($params);
      $suggested = $st->fetchAll();
    }
  }
}

$student_name = trim(($_SESSION['user_first_name'] ?? '').' '.($_SESSION['user_last_name'] ?? ''));
$subject_name = $pdo->prepare("SELECT name FROM subjects WHERE id=?"); $subject_name->execute([$subject_id]); $subject_name = (string)$subject_name->fetchColumn();
?>

