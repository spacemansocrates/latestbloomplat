<?php
/**
 * tests/assessment/test_admin.php
 * admin/assessments.php over HTTP: who may use it, creating a demo student and
 * attempt, the sibling guard, reuse of the same child, validation, and removal.
 * Runs after test_http.php, so attempt 1 is already approved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../whatsapp/fixtures.php';

$pdo = ct_pdo();
$one = static function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$count = static fn(string $sql, array $args = []): int => (int)$one("SELECT COUNT(*) AS n FROM ($sql) x", $args)['n'];
$loginWith = static function (string $email, string $password): array {
    $jar = tempnam(sys_get_temp_dir(), 'asmjar');
    [$s, , $h] = http($jar, 'POST', '/authenticate.php', ['email' => $email, 'password' => $password]);
    return [$s, $jar, $h['location'] ?? ''];
};

$ada  = login('ada@example.test');    // full admin
$mo   = login('mo@example.test');     // Mini Admin
$tara = login('tara@example.test');   // tutor

// ---- access
[$s] = http($tara, 'GET', '/admin/assessments.php');
check($s === 403, 'a tutor cannot open the admin page');
[$s, $page] = http($mo, 'GET', '/admin/assessments.php');
check($s === 200 && str_contains($page, 'New demo'), 'a Mini Admin can (demos are scheduling work)');
[$s, $page] = http($ada, 'GET', '/admin/assessments.php');
check($s === 200 && str_contains($page, 'value="ks1-maths"') && str_contains($page, 'Tara Tutor'), 'the form lists the bank and the active tutors');
check(str_contains($page, 'href="assessments.php"') && str_contains($page, 'Demo Assessments'), 'admin sidebar has the Demo Assessments link');
check(str_contains($page, 'Sam Student') && str_contains($page, 'Approved'), 'the list shows the approved attempt from the flow tests');
$csrf = scrape($page, 'csrf');

$form = [
    'csrf' => $csrf, 'action' => 'create',
    'first_name' => 'Lucy', 'last_name' => 'Park', 'parent_name' => 'Jo Park',
    'parent_email' => 'Jo.Park@Example.test', 'parent_phone' => '07700 900123', 'login_email' => '',
    'assessment' => 'ks1-maths', 'tutor_id' => '1', 'exam_board' => 'AQA', 'pace' => 'fast',
];

// ---- validation, nothing written
$users0 = $count('SELECT id FROM users');
$att0 = $count('SELECT id FROM assessment_attempts');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['csrf' => 'forged']));
check(str_contains($b, 'form had expired'), 'forged CSRF token is refused');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['parent_email' => '']));
check(str_contains($b, "Enter the parent&#039;s email"), 'parent email is required');
check(str_contains($b, 'value="Lucy"') && str_contains($b, 'value="07700 900123"'), 'a failed submit keeps what was typed');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['tutor_id' => '2']));
check(str_contains($b, 'Choose the tutor'), 'a student id cannot be passed off as the tutor');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['assessment' => 'gcse-maths']));
check(str_contains($b, 'Choose an assessment'), 'an assessment that is not in the bank is refused');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['parent_phone' => '12']));
check(str_contains($b, 'Parent phone:'), 'a junk phone number is refused with the reason');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['pace' => '']));
check(str_contains($b, 'how fast the family wants'), 'the pace question must be answered');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['exam_board' => 'Hogwarts']));
check(str_contains($b, 'exam board from the list'), 'exam board must come from the list');
check($count('SELECT id FROM users') === $users0 && $count('SELECT id FROM assessment_attempts') === $att0, 'no failed submit wrote anything');

// ---- create
[, $b] = post_follow($ada, '/admin/assessments.php', $form);
check(str_contains($b, 'Demo set up for Lucy Park') && str_contains($b, 'A new portal account was created'), 'demo is set up');
check(preg_match('/id="temp-pw">([A-Za-z]+-\d{4}-[A-Za-z]+)</', $b, $m) === 1, 'a readable temporary password is shown');
$tempPw = $m[1] ?? '';
$lucy = $one("SELECT * FROM users WHERE email = 'jo.park@example.test'");
check($lucy && $lucy['role'] === 'Student' && $lucy['first_name'] === 'Lucy', 'student account created with the parent email as login (lowercased)');
check($lucy && $lucy['currency_code'] === 'GBP' && $lucy['timezone'] === 'Europe/London', 'demo student defaults to GBP and Europe/London');
check($lucy && $lucy['guardian_phone'] === '+447700900123' && $lucy['guardian_name'] === 'Jo Park', 'UK phone 07700 900123 is stored as +447700900123, not as a Malawi number');
check($lucy && password_verify($tempPw, $lucy['password_hash']) && !str_contains((string)$lucy['password_hash'], $tempPw), 'only a hash of the temporary password is stored');
$att = $one('SELECT * FROM assessment_attempts WHERE student_id = ?', [$lucy['id'] ?? 0]);
check($att && $att['status'] === 'assigned' && (int)$att['tutor_id'] === 1 && $att['exam_board'] === 'AQA' && $att['pace'] === 'fast' && (int)$att['exam_soon'] === 1
      && $att['parent_email'] === 'jo.park@example.test' && (int)$att['assigned_by'] === 9, 'attempt assigned with tutor, board, exam flag and parent email');
check((bool)$one('SELECT id FROM tutor_student_assignments WHERE tutor_id = 1 AND student_id = ? AND end_date IS NULL', [$lucy['id'] ?? 0]),
      'tutor is assigned to the new student');
check(str_contains($b, '/student/assessment.php?id=' . ($att['id'] ?? 'x')), 'the direct assessment link is shown');

[, $b] = http($ada, 'GET', '/admin/assessments.php');
check(!str_contains($b, $tempPw) && !str_contains($b, 'id="temp-pw"'), 'the temporary password is gone after a reload');

[$s, $lucyJar, $loc] = $loginWith('jo.park@example.test', $tempPw);
check($s === 302 && !str_contains($loc, 'login.php'), 'the family can log in with the temporary password');
[$s, , $h] = http($lucyJar, 'GET', '/student/assessment.php');
check($s === 302 && ($h['location'] ?? '') === 'assessment.php?id=' . $att['id'], 'and lands straight on their assessment');

// ---- sibling with the same parent email is refused
$users1 = $count('SELECT id FROM users');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['first_name' => 'Max']));
check(str_contains($b, 'already belongs to Lucy Park') && str_contains($b, 'own login email'), 'a sibling sharing the parent email is refused, naming the other child');
check($count('SELECT id FROM users') === $users1 && $count('SELECT id FROM assessment_attempts WHERE student_id = ?', [$lucy['id']]) === 1,
      'and nothing is attached to the wrong child');
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['first_name' => 'Max', 'login_email' => 'max.park@example.test']));
check(str_contains($b, 'Demo set up for Max Park'), 'the sibling works with their own login email');
$max = $one("SELECT id, guardian_phone FROM users WHERE email = 'max.park@example.test'");
check($max && $one('SELECT parent_email FROM assessment_attempts WHERE student_id = ?', [$max['id']])['parent_email'] === 'jo.park@example.test',
      "the sibling's report still goes to the parent");

// ---- the same child again reuses the account and leaves the password alone
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['first_name' => 'LUCY', 'pace' => 'steady']));
check(str_contains($b, 'already had a portal account') && !str_contains($b, 'id="temp-pw"'), 'same child: account reused, no new password shown');
[$s, , $loc] = $loginWith('jo.park@example.test', $tempPw);
check($s === 302 && !str_contains($loc, 'login.php'), 'their original password still works');
check($count('SELECT id FROM assessment_attempts WHERE student_id = ?', [$lucy['id']]) === 2, 'a second assessment was added to the same account');

// ---- a login email that belongs to a tutor is refused
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, ['login_email' => 'tara@example.test']));
check(str_contains($b, 'already belongs to another account'), "a staff member's email cannot become a student login");

// ---- output is escaped
[, $b] = post_follow($ada, '/admin/assessments.php', array_merge($form, [
    'first_name' => 'Zed', 'last_name' => "O'Neil<script>x()</script>", 'login_email' => 'zed@example.test']));
check(!str_contains($b, '<script>x()') && str_contains($b, 'O&#039;Neil&lt;script&gt;'), 'names are escaped in the page');
check(!str_contains($b, "</script>x()") && str_contains($b, '<script>'), 'and in the copy-to-clipboard script');

// ---- in-app confirmations, not browser popups
[, $page] = http($ada, 'GET', '/admin/assessments.php');
check(str_contains($page, 'data-confirm-title="Remove this assessment?"') && str_contains($page, 'id="bc-dialog"')
      && !preg_match('/\bconfirm\(/', str_replace('bloomConfirm(', '', $page)), 'admin confirmations use the in-app dialog');

// ---- payment links: full admins only
[, $moPage] = http($mo, 'GET', '/admin/assessments.php');
check(!str_contains($moPage, 'Payment links in the report email'), 'a Mini Admin does not see the payment links form');
[, $b] = post_follow($mo, '/admin/assessments.php', ['csrf' => scrape($moPage, 'csrf'), 'action' => 'plan_links', 'link_starter' => 'https://buy.stripe.com/evil']);
check(str_contains($b, 'Only a full admin') && !$one("SELECT 1 AS x FROM app_settings WHERE `key` = 'assessment.plan_link.starter'"), 'and cannot save one');
check(str_contains($page, 'Payment links in the report email'), 'a full admin sees the payment links form');
[, $b] = post_follow($ada, '/admin/assessments.php', ['csrf' => $csrf, 'action' => 'plan_links', 'link_starter' => 'javascript:alert(1)']);
check(str_contains($b, 'must be a full https:// address'), 'a non-https link is refused');
[, $b] = post_follow($ada, '/admin/assessments.php', ['csrf' => $csrf, 'action' => 'plan_links',
    'link_starter' => 'https://buy.stripe.com/test_starter', 'link_core' => 'https://buy.stripe.com/test_core', 'link_intensive' => '', 'link_intensive_plus' => '']);
check(str_contains($b, 'Payment links saved') && $one("SELECT `value` AS v FROM app_settings WHERE `key` = 'assessment.plan_link.starter'")['v'] === 'https://buy.stripe.com/test_starter',
      'a full admin saves the Stripe links');
check(str_contains($b, 'value="https://buy.stripe.com/test_core"'), 'saved links are shown back in the form');

// ---- the report email: preview, test copy, send, resend
[$s] = http($tara, 'GET', '/admin/assessment_report.php?id=1');
check($s === 403, 'a tutor cannot open the email preview (it shows prices)');
[$s, $b] = http($ada, 'GET', '/admin/assessment_report.php?id=1');
check($s === 200 && str_contains($b, 'Sam&#039;s KS1 Maths diagnostic (test fixture) assessment results') && str_contains($b, 'srcdoc="'),
      'the admin previews the exact email for an approved assessment');
check(str_contains($b, 'goes to the saved payment link') && str_contains($b, 'https://buy.stripe.com/test_starter'), "the preview's button uses the saved link for the recommended plan");
check(str_contains($b, 'last send failed'), 'the preview shows that the automatic send failed');
[, $b] = http($ada, 'GET', '/admin/assessment_report.php?id=' . $one("SELECT id FROM assessment_attempts WHERE status = 'assigned' ORDER BY id DESC LIMIT 1")['id']);
check(str_contains($b, 'no report for this assessment yet'), 'no preview before approval');

$errBefore = $one('SELECT report_error FROM assessment_attempts WHERE id = 1')['report_error'];
[, $b] = post_follow($ada, '/admin/assessment_report.php?id=1', ['csrf' => $csrf, 'action' => 'test', 'to' => 'me@example.test']);
check(str_contains($b, 'could not be sent'), 'a test copy is attempted (it fails here only because this copy has no SMTP)');
check($one('SELECT report_error FROM assessment_attempts WHERE id = 1')['report_error'] === $errBefore
      && $one('SELECT report_sent_at FROM assessment_attempts WHERE id = 1')['report_sent_at'] === null, "a test copy never counts as the parent's send");
[, $b] = post_follow($ada, '/admin/assessments.php', ['csrf' => $csrf, 'action' => 'send_report', 'attempt_id' => '1']);
check(str_contains($b, 'could not be sent') && str_contains($b, 'Email failed:'), 'resending reports the failure and the list shows it');

// A working mail server, simulated: the same function the pages call, with the sender swapped.
define('ASM_BANK_DIR', __DIR__ . '/fixtures');
require_once __DIR__ . '/../../utils/assessment_report.php';
$sent = [];
[$ok, $msg] = asm_send_report($pdo, 1, null, function ($to, $name, $subject, $html, $text) use (&$sent) {
    $sent[] = compact('to', 'name', 'subject', 'html', 'text');
    return ['success' => true, 'message' => 'ok'];
});
$row1 = $one('SELECT report_sent_at, report_error FROM assessment_attempts WHERE id = 1');
check($ok && $msg === 'Report emailed to pat@example.test.' && count($sent) === 1 && $sent[0]['to'] === 'pat@example.test' && $sent[0]['name'] === 'Pat Parent',
      'a successful send goes to the parent on the attempt');
check($row1['report_sent_at'] !== null && $row1['report_error'] === null, 'a successful send records the time and clears the old error');
check(str_contains($sent[0]['html'], 'href="https://buy.stripe.com/test_starter?client_reference_id=asm-1&amp;prefilled_email=pat%40example.test"') && str_contains($sent[0]['html'], 'Start the Starter plan')
      && str_contains($sent[0]['html'], 'Great effort today.'), 'the email carries the plan link and the tutor note');
[, $b] = http($ada, 'GET', '/admin/assessments.php');
check(str_contains($b, 'Report emailed') && str_contains($b, '>Resend<'), 'the list shows it was emailed and offers a resend');

// ---- the admin approves and sends without the tutor (attempt 3: submitted, written answer unmarked)
[, $b] = http($ada, 'GET', '/admin/assessments.php');
check(str_contains($b, 'href="assessment_report.php?id=3"') && str_contains($b, 'Review and send') && str_contains($b, 'Waiting for approval'),
      'a submitted assessment offers "Review and send" in the admin list');
[$s] = http($tara, 'GET', '/admin/assessment_report.php?id=3');
check($s === 403, 'a tutor cannot use the admin review page');
[$s, $b] = http($ada, 'GET', '/admin/assessment_report.php?id=3');
check($s === 200 && str_contains($b, 'Review and send report') && str_contains($b, 'submitted, waiting for approval'), 'the admin opens the review page');
check(str_contains($b, 'Line them up &lt;script&gt;x()&lt;/script&gt; and use a ruler.') && !str_contains($b, '<script>x()'), "the written answer is shown, escaped");
check(str_contains($b, 'name="wm_q12"') && str_contains($b, 'Mark the written answer above before approving'), 'the unmarked written answer is flagged');
check(str_contains($b, 'srcdoc="') && str_contains($b, 'Preview: the email as it will be sent'), 'the admin previews the email before approving');
check(str_contains($b, 'Clear progress within a term') && str_contains($b, 'Core, 2 a week'), 'the plan comes from the score and the pace (12/12 marked so far, term: Core)');

[, $b] = post_follow($ada, '/admin/assessment_report.php?id=3', ['csrf' => $csrf, 'action' => 'approve', 'tutor_note' => 'Lovely work.']);
check(str_contains($b, 'mark every written answer') && $one('SELECT status FROM assessment_attempts WHERE id = 3')['status'] === 'submitted',
      'the admin cannot approve with a written answer unmarked either');
[, $b] = post_follow($ada, '/admin/assessment_report.php?id=3', ['csrf' => 'forged', 'action' => 'approve', 'wm_q12' => '2']);
check(str_contains($b, 'expired') && $one('SELECT status FROM assessment_attempts WHERE id = 3')['status'] === 'submitted', 'a forged token approves nothing');
[, $b] = post_follow($ada, '/admin/assessment_report.php?id=3', ['csrf' => $csrf, 'action' => 'save', 'wm_q12' => '2', 'tutor_note' => 'Lovely work.']);
check(str_contains($b, 'Saved. The preview below') && str_contains($b, 'Lovely work.') && $one('SELECT status FROM assessment_attempts WHERE id = 3')['status'] === 'submitted',
      'saving marks and a note updates the preview without approving');
check(str_contains($b, 'data-confirm-title="Approve and send this report?"'), 'approving asks in the in-app dialog');

[, $b] = post_follow($ada, '/admin/assessment_report.php?id=3', ['csrf' => $csrf, 'action' => 'approve', 'wm_q12' => '2', 'tutor_note' => 'Lovely work.']);
$r3 = $one('SELECT * FROM assessment_attempts WHERE id = 3');
check($r3['status'] === 'approved' && (int)$r3['approved_by'] === 9 && $r3['final_plan'] === 'core' && $r3['tutor_note'] === 'Lovely work.',
      'the admin approves it: 100% at a term pace = Core, approved by the admin');
check(str_contains($b, 'Approved, but the email could not be sent') && str_contains((string)$r3['report_error'], 'configuration is incomplete'),
      'approving sends the email straight away (it fails here only because this copy has no SMTP)');
[, $b] = post_follow($ada, '/admin/assessment_report.php?id=3', ['csrf' => $csrf, 'action' => 'approve', 'wm_q12' => '0']);
check(str_contains($b, 'already been approved') && json_decode($one('SELECT written_marks FROM assessment_attempts WHERE id = 3')['written_marks'], true)['q12'] === 2,
      'an approved report cannot be changed from the admin page');
[, $tv] = http($tara, 'GET', '/tutor/assessments.php?id=3');
check(str_contains($tv, 'Approved') && str_contains($tv, 'disabled') && !str_contains($tv, 'Approve and send report</button>'), "the tutor sees the admin's approval, read-only");

// ---- removal
$lucyAttempt = (int)$att['id'];
[, $b] = post_follow($ada, '/admin/assessments.php', ['csrf' => $csrf, 'action' => 'remove', 'attempt_id' => '1']);
check(str_contains($b, 'has not been submitted can be removed') && $one('SELECT id FROM assessment_attempts WHERE id = 1'), 'an approved attempt cannot be removed');
[, $b] = post_follow($ada, '/admin/assessments.php', ['csrf' => $csrf, 'action' => 'remove', 'attempt_id' => (string)$lucyAttempt]);
check(str_contains($b, 'Assessment removed') && !$one('SELECT id FROM assessment_attempts WHERE id = ?', [$lucyAttempt])
      && $one('SELECT id FROM users WHERE id = ?', [$lucy['id']]), 'an unstarted attempt is removed and the account kept');

finish('assessment admin');
