<?php
/**
 * tests/assessment/test_http.php
 * The whole assessment flow over HTTP against the disposable app on 8099:
 * student takes and submits, tutor marks and approves, and the access rules.
 */

declare(strict_types=1);

require_once __DIR__ . '/../whatsapp/fixtures.php';

$pdo = ct_pdo();
$row = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM assessment_attempts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
// http() form-encodes; the API wants JSON, so send it directly.
$json = static function (string $jar, array $body): array {
    $ch = curl_init(WA_BASE . '/student/api/assessment.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $b = (string)curl_exec($ch);
    $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$s, json_decode($b, true) ?: []];
};
$csrfFrom = static fn(string $html): string => preg_match('/const CSRF = "([0-9a-f]+)"/', $html, $m) ? $m[1] : '';

$sam  = login('sam@example.test');
$nina = login('nina@example.test');
$tara = login('tara@example.test');
$otto = login('otto@example.test');

// ---- student: find and open the assessment
[$s, $b, $h] = http($sam, 'GET', '/student/settings.php');
check(str_contains($b, 'href="assessment.php"'), 'student sidebar shows the Assessment link once one is assigned');
[, $b] = http(login('pia@example.test'), 'GET', '/student/settings.php');
check(!str_contains($b, 'href="assessment.php"'), 'a student with no assessment gets no link');
[$s, , $h] = http($sam, 'GET', '/student/assessment.php');
check($s === 302 && ($h['location'] ?? '') === 'assessment.php?id=1', 'with one open assessment, the list redirects straight to it');

check($row(1)['status'] === 'assigned' && $row(1)['started_at'] === null, 'not started before the page is opened');
[$s, $page] = http($sam, 'GET', '/student/assessment.php?id=1');
check($s === 200 && str_contains($page, 'Which number is 1 more than 19?'), 'the test page renders its questions');
check($row(1)['status'] === 'in_progress' && $row(1)['started_at'] !== null, 'opening it starts the clock (in_progress)');
check(!str_contains($page, 'Multiples of 5 end in') && !str_contains($page, 'Accept any capitalisation')
      && !str_contains($page, 'Mentions measuring each one') && !preg_match('/"answer"\s*:/', $page),
      'the page carries no answer key, explanations or rubric');
check(str_contains($page, '>p</span>'), 'the unit is shown next to the answer box');
check(str_contains($page, 'Your tutor will read each question with you &amp; help you start.'), 'the assessment intro (the KS1 read-aloud note) shows above the questions, escaped');
$csrf = $csrfFrom($page);
check(strlen($csrf) === 64, 'page exposes the session CSRF token to its script');
check(str_contains($page, 'id="bc-dialog"') && str_contains($page, 'bloomConfirm(') && !preg_match('/\b(confirm|alert)\(/', str_replace('bloomConfirm(', '', $page)),
      'submitting asks in an in-app dialog, never a browser confirm() or alert()');

[$s] = http($nina, 'GET', '/student/assessment.php?id=1');
check($s === 404, "another student cannot open Sam's assessment");

// ---- autosave and submit through the API
$answers = [
    'q01' => 1, 'q02' => '12', 'q03' => 1, 'q04' => 3, 'q05' => 'Fifteen.', 'q06' => '8',
    'q07' => ' 15 ', 'q08' => '100p', 'q09' => 0, 'q10' => 2, 'q11' => [2, 0],
    'q12' => 'Put them next to each other <b>and</b> see.', 'q99' => 'junk',
];
[$s, $r] = $json($sam, ['csrf_token' => 'nope', 'attempt_id' => 1, 'action' => 'save', 'answers' => $answers]);
check($s === 403, 'save with a forged CSRF token is refused');
[$s, $r] = $json($nina, ['csrf_token' => $csrf, 'attempt_id' => 1, 'action' => 'save', 'answers' => $answers]);
check($s === 403, "Sam's token does not work in Nina's session");
[, $ninaPage] = http($nina, 'GET', '/student/assessment.php?id=2');
[$s] = $json($nina, ['csrf_token' => $csrfFrom($ninaPage), 'attempt_id' => 1, 'action' => 'save', 'answers' => $answers]);
check($s === 404, "Nina with her own valid token still cannot save into Sam's attempt");

[$s, $r] = $json($sam, ['csrf_token' => $csrf, 'attempt_id' => 1, 'action' => 'save', 'answers' => $answers]);
check($s === 200 && ($r['ok'] ?? false) && $r['saved'] === 12, 'autosave stores the 12 real answers');
$saved = json_decode($row(1)['answers'], true);
check(!isset($saved['q99']) && $saved['q11'] === [0, 2] && $saved['q02'] === '12', 'stored answers are cleaned against the bank');
check(!isset($r['score']) && !isset($r['results']), 'the API never returns a score to the student');

[, $page] = http($sam, 'GET', '/student/assessment.php?id=1');
check(str_contains($page, 'value="Fifteen."') && preg_match('/name="q_q04" value="3" checked/', $page) === 1, 'a reload shows the saved answers');
check(str_contains($page, 'Put them next to each other &lt;b&gt;and&lt;/b&gt; see.'), 'a written answer is escaped when shown again');

[$s, $r] = $json($sam, ['csrf_token' => $csrf, 'attempt_id' => 1, 'action' => 'submit', 'answers' => $answers]);
check($s === 200 && ($r['submitted'] ?? false), 'submit succeeds');
check($row(1)['status'] === 'submitted' && $row(1)['submitted_at'] !== null, 'status is submitted');
[$s] = $json($sam, ['csrf_token' => $csrf, 'attempt_id' => 1, 'action' => 'save', 'answers' => ['q01' => 0]]);
check($s === 409 && json_decode($row(1)['answers'], true)['q01'] === 1, 'answers cannot change after submitting');
[, $page] = http($sam, 'GET', '/student/assessment.php?id=1');
check(str_contains($page, 'All done') && !str_contains($page, 'Which number is 1 more than 19?'), 'after submitting the student sees the thank-you screen, not the questions');
$note = $pdo->query("SELECT user_id, type FROM notifications WHERE related_entity_id = 1 AND type = 'AssessmentSubmitted'")->fetch(PDO::FETCH_ASSOC);
check($note && (int)$note['user_id'] === 1, 'the tutor gets an in-app notification');

// ---- tutor: list, access rules, marking, approval
[$s, $list] = http($tara, 'GET', '/tutor/assessments.php');
check($s === 200 && str_contains($list, 'Sam Student') && str_contains($list, 'Ready to review'), "Tara's list shows Sam ready to review");
check(str_contains($list, 'sidebar-alert-dot" title="Assessment ready to review"'), 'tutor sidebar flags the waiting review');
[, $ottoList] = http($otto, 'GET', '/tutor/assessments.php');
check(!str_contains($ottoList, 'Sam Student'), "Otto's list does not include Tara's student");
[$s, $ottoView] = http($otto, 'GET', '/tutor/assessments.php?id=1');
check(str_contains($ottoView, 'assigned to another tutor') && !str_contains($ottoView, 'pat@example.test'), 'Otto cannot open it');

[$s, $view] = http($tara, 'GET', '/tutor/assessments.php?id=1');
check($s === 200 && str_contains($view, 'pat@example.test'), 'Tara sees the attempt and the parent contact');
check(str_contains($view, 'Sam counts well but is still unsure'), "parent copy is shown with the child's name filled in");
check(str_contains($view, '1 written answer to mark'), 'the pending written answer is flagged');
check(str_contains($view, 'A. 15; C. 30'), 'the correct answers are shown to the tutor');
check(str_contains($view, 'Put them next to each other &lt;b&gt;and&lt;/b&gt; see.'), "the student's written answer is escaped for the tutor");
$tcsrf = scrape($view, 'csrf_token');
check(!preg_match('/£(66|119|169|211)\b|every 30 days/', $view) && !str_contains($view, 'name="final_plan"'),
      'the tutor sees no plan prices and cannot pick the plan');
check(str_contains($view, 'Recommended plan (set automatically)') && str_contains($view, 'Starter: 1 lesson a week')
      && str_contains($view, "Based on Sam&#039;s overall score of"), 'the tutor sees the automatic plan and why');
check(str_contains($view, 'data-confirm-title="Approve and send this report?"') && str_contains($view, 'id="bc-dialog"')
      && !preg_match('/\bconfirm\(/', str_replace('bloomConfirm(', '', $view)), 'approving asks in an in-app dialog, not a browser popup');

[, $b] = post_follow($tara, '/tutor/assessments.php?id=1', ['csrf_token' => $tcsrf, 'action' => 'approve', 'final_plan' => 'core']);
check(str_contains($b, 'mark every written answer') && $row(1)['status'] === 'submitted', 'approval is blocked while a written answer is unmarked');

[, $ottoSettings] = http($otto, 'GET', '/tutor/settings.php');
$ocsrf = scrape($ottoSettings, 'csrf_token');
check(strlen($ocsrf) === 64, "Otto's own CSRF token (so the next check tests ownership, not CSRF)");
[, $b] = post_follow($otto, '/tutor/assessments.php?id=1', ['csrf_token' => $ocsrf, 'action' => 'approve', 'wm_q12' => '2']);
check($row(1)['status'] === 'submitted' && $row(1)['written_marks'] !== '{"q12":2}', 'Otto cannot mark or approve it');

[, $b] = post_follow($tara, '/tutor/assessments.php?id=1', ['csrf_token' => 'forged', 'action' => 'save', 'wm_q12' => '1']);
$wm = json_decode((string)$row(1)['written_marks'], true);
check(str_contains($b, 'expired') && ($wm['q12'] ?? null) !== 1, 'a forged CSRF token saves nothing');

[, $b] = post_follow($tara, '/tutor/assessments.php?id=1', ['csrf_token' => $tcsrf, 'action' => 'save', 'wm_q12' => '9', 'final_plan' => 'core', 'tutor_note' => 'Great effort today.']);
check(json_decode($row(1)['written_marks'], true)['q12'] === 2, 'written mark is capped at the question maximum');
check(str_contains($b, 'Marks saved') && $row(1)['status'] === 'submitted', 'saving marks does not approve');

[, $b] = post_follow($tara, '/tutor/assessments.php?id=1', ['csrf_token' => $tcsrf, 'action' => 'approve', 'wm_q12' => '1', 'final_plan' => 'core', 'tutor_note' => 'Great effort today.']);
$final = $row(1);
check($final['status'] === 'approved' && (int)$final['approved_by'] === 1 && $final['approved_at'] !== null, 'Tara approves it');
check($final['recommended_plan'] === 'starter' && $final['final_plan'] === 'starter', 'the plan follows the score (79%, steady pace): a final_plan in the POST is ignored');
check(str_contains($b, 'Approved, but the email could not be sent') && str_contains($b, 'resend it from Demo Assessments'),
      'the report email is sent on approval, and a failure (no SMTP in this test copy) is reported honestly');
check($final['report_sent_at'] === null && str_contains((string)$final['report_error'], 'configuration is incomplete'), 'the send failure is recorded for the admin');
$res = json_decode($final['results'], true);
check($res['pending'] === 0 && $res['skills']['measure']['earned'] === 3 && $res['skills']['measure']['available'] === 4, 'results snapshot includes the written mark');
check($final['tutor_note'] === 'Great effort today.', 'tutor note is stored');

[, $b] = post_follow($tara, '/tutor/assessments.php?id=1', ['csrf_token' => $tcsrf, 'action' => 'save', 'wm_q12' => '0']);
check(str_contains($b, 'before it is approved') && json_decode($row(1)['written_marks'], true)['q12'] === 1, 'an approved report cannot be changed');
[, $view] = http($tara, 'GET', '/tutor/assessments.php?id=1');
check(str_contains($view, 'has not been emailed yet') && str_contains($view, 'last attempt to send it failed') && str_contains($view, 'disabled'),
      'approved view is read-only and honest that the email failed');

finish('assessment HTTP');
