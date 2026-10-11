<?php
/**
 * tests/assessment/test_unit.php
 * Scoring, cleaning and plan rules in utils/assessment.php, against the fixture bank.
 * No database, no HTTP.
 */

declare(strict_types=1);

define('ASM_BANK_DIR', __DIR__ . '/fixtures');
require_once __DIR__ . '/../../utils/assessment.php';
require_once __DIR__ . '/../whatsapp/fixtures.php';   // check() / finish()

$a = asm_load('ks1-maths', 1);
check(is_array($a), 'fixture loads');
check(asm_load('ks1-maths', 2) === null, 'a missing version is null, not an error');
check(asm_load('../etc', 1) === null && asm_load('KS1-maths', 1) === null, 'ids outside [a-z0-9]-[a-z] are refused (no path tricks)');
check(array_keys(asm_list()) === ['ks1-maths'], 'asm_list finds the fixture');
$stages = ['GCSE', 'KS3', 'Other', 'KS1', 'KS2'];
usort($stages, fn($x, $y) => asm_stage_rank($x) <=> asm_stage_rank($y));
check($stages === ['KS1', 'KS2', 'KS3', 'GCSE', 'Other'], 'assessments list in school order, KS1 first and unknown stages last');

// ---- the public view never carries the key
$pub = asm_public_questions($a);
$flat = json_encode($pub);
check(count($pub) === 12, 'public view has every question');
check(!str_contains($flat, '"answer"') && !str_contains($flat, 'explain') && !str_contains($flat, 'rubric'),
      'public view has no answer, explain or rubric fields');
check(!str_contains($flat, 'Multiples of 5 end in'), 'public view has no explanation text');

// ---- cleaning
$clean = asm_clean_answers($a, [
    'q01' => '1', 'q03' => 9, 'q11' => [2, '0', 2, 7, 'x'], 'q02' => '  12 ', 'q12' => '   ',
    'q99' => 'not a question', 'q05' => ['array', 'for', 'a', 'short'],
]);
check($clean['q01'] === 1, 'mcq index is cast to int');
check(!isset($clean['q03']), 'out-of-range mcq index is dropped');
check($clean['q11'] === [0, 2], 'multi: de-duplicated, sorted, out-of-range dropped');
check($clean['q02'] === '12', 'numeric answer is trimmed');
check(!isset($clean['q12']), 'blank written answer is dropped');
check(!isset($clean['q99']) && !isset($clean['q05']), 'unknown ids and wrong shapes are dropped');

// ---- scoring one question at a time
$Q = [];
foreach ($a['questions'] as $q) $Q[$q['id']] = $q;
check(asm_score_question($Q['q05'], 'Fifteen.') === 1, 'short: case and trailing full stop ignored');
check(asm_score_question($Q['q05'], 'fiveteen') === 0, 'short: wrong spelling scores 0');
check(asm_score_question($Q['q08'], '100p') === 1, 'numeric: unit after the number is ignored');
check(asm_score_question($Q['q08'], '£1') === 0, 'numeric: wrong number scores 0');
check(asm_score_question($Q['q08'], 'lots') === 0, 'numeric: no number scores 0');
check(asm_score_question($Q['q11'], [0]) === 0, 'multi: partly right scores 0');
check(asm_score_question($Q['q11'], [2, 0]) === 2, 'multi: exactly right scores full marks');
check(asm_score_question($Q['q12'], 'line them up') === null, 'written: unmarked is pending (null), not 0');
check(asm_score_question($Q['q12'], 'line them up', 5) === 2, 'written: tutor mark is capped at the question marks');
check(asm_score_question($Q['q12'], null) === 0, 'written: no answer scores 0 and needs no marking');
check(asm_parse_number('£1,250.50') === 1250.5 && asm_parse_number("\u{2212}3") === -3.0, 'number parsing: £, commas, unicode minus');
check(asm_parse_number('x = 5') === 5.0 && asm_parse_number('X=-2.5') === -2.5 && asm_parse_number('2x = 10') === 2.0,
      'number parsing: a leading "x =" is dropped, anything else is read as written');

// ---- a whole attempt
$answers = [
    'q01' => 1, 'q02' => '12', 'q03' => 1, 'q04' => 3, 'q05' => 'Fifteen.', 'q06' => '8',
    'q07' => ' 15 ', 'q08' => '100p', 'q09' => 0, 'q10' => 2, 'q11' => [2, 0], 'q12' => 'Put them next to each other.',
];
$r = asm_results($a, $answers);
check($r['pending'] === 1, 'one written answer pending');
check($r['skills']['place_value']['earned'] === 2 && $r['skills']['place_value']['band'] === 'developing', 'place value 2/3 = 67% developing');
check($r['skills']['add_sub']['band'] === 'strong', 'adding 3/3 strong');
check($r['skills']['mult_div']['earned'] === 3 && $r['skills']['mult_div']['pct'] == 75 && $r['skills']['mult_div']['band'] === 'strong',
      'times tables 3/4 = 75% sits exactly on the strong boundary');
check($r['skills']['measure']['pending'] === 1 && $r['skills']['measure']['available'] === 2, 'pending written marks are left out of the total, not counted as 0');

$r = asm_results($a, $answers, ['q12' => 0]);
check($r['pending'] === 0 && $r['skills']['measure']['pct'] == 50 && $r['skills']['measure']['band'] === 'developing', 'measure 2/4 once marked 0 = developing');
check($r['gaps'] === [] && $r['pct'] === 71, 'no gaps, 10 of 14 marks = 71% overall');
check($r['recommended_plan'] === 'core' && $r['pace'] === 'steady', '71% at a steady pace: Core (the score alone decides the base tier)');
check(asm_results($a, $answers, ['q12' => 0], 'term')['recommended_plan'] === 'intensive', 'progress within a term moves Core up to Intensive');
check(asm_results($a, $answers, ['q12' => 0], 'fast')['recommended_plan'] === 'intensive_plus', 'as fast as possible moves Core up two, to Intensive+');
check(asm_results($a, $answers, ['q12' => 0], 'nonsense')['pace'] === 'steady', 'an unknown pace falls back to steady');

$blank = asm_results($a, []);
check(count($blank['gaps']) === 4 && $blank['recommended_plan'] === 'intensive', 'everything blank: 0%, Intensive');
check(asm_results($a, [], [], 'fast')['recommended_plan'] === 'intensive_plus', 'the pace never goes past Intensive+');
check($blank['pending'] === 0, 'a blank written answer does not hold up approval');

check(asm_recommend_plan(100, 'steady') === 'starter' && asm_recommend_plan(75, 'steady') === 'starter'
      && asm_recommend_plan(74, 'steady') === 'core' && asm_recommend_plan(50, 'steady') === 'core'
      && asm_recommend_plan(49, 'steady') === 'intensive' && asm_recommend_plan(0, 'steady') === 'intensive',
      'score thresholds: 75%+ Starter, 50-74% Core, below 50% Intensive');
check(asm_recommend_plan(80, 'term') === 'core' && asm_recommend_plan(80, 'fast') === 'intensive' && asm_recommend_plan(60, 'fast') === 'intensive_plus',
      'pace adds one tier for a term, two for as fast as possible');
check(asm_plan_reason(62, 'term', 'Sam') === "Based on Sam's overall score of 6.2 out of 10 and your aim of clear progress within a term.", 'plan reason sentence');
check(asm_plan_reason(40, 'fast', '') === "Based on your child's overall score of 4.0 out of 10 and your aim of progress as quickly as possible.", 'plan reason without a name');
check(asm_plan_reason(100, 'steady', 'Sam') === "Based on Sam's overall score of 10 out of 10 and your aim of steady progress over the school year.", 'a perfect score reads "10 out of 10"');

// Gaps are ordered weakest first.
$two = asm_results($a, ['q01' => 1, 'q04' => 3]);   // place value 1/3, measure 1/4, others 0
check($two['gaps'][0] === 'add_sub' || $two['gaps'][0] === 'mult_div', 'weakest skill (0%) comes first in gaps');
check(end($two['gaps']) === 'place_value', 'strongest of the gaps comes last');

// ---- labels and copy
check(asm_answer_label($Q['q11']) === 'A. 15; C. 30', 'multi answer label');
check(asm_answer_label($Q['q08']) === '100 p', 'numeric answer label with unit');
check(asm_copy('{name} did well.', '') === 'Your child did well.', 'copy falls back to "Your child" with no name');

// ---- the report email (built from data, no database or mail server)
require_once __DIR__ . '/../../utils/assessment_report.php';
$res = asm_results($a, $answers, ['q12' => 0], 'term');   // 71%, pace term -> Intensive
$d = [
    'row'  => ['parent_name' => 'Jo Park', 'parent_email' => 'jo@example.test', 'tutor_note' => "Great effort <b>today</b>.\nSee you soon."],
    'a'    => $a, 'res' => $res, 'plan' => $res['recommended_plan'], 'pace' => 'term',
    'link' => null, 'child' => 'Lucy', 'tutor' => 'Tara',
];
$m = asm_report_email($d);
check($m['subject'] === "Lucy's KS1 Maths diagnostic (test fixture) assessment results", 'subject names the child and the assessment');
check(str_contains($m['html'], 'Hi Jo,') && str_contains($m['html'], 'session with Tara'), 'greets the parent by first name and names the tutor');
check(str_contains($m['html'], 'Overall score 7.1 out of 10') && str_contains($m['html'], 'Lucy scored 7.1 out of 10')
      && str_contains($m['html'], '>6.7</span><span') && str_contains($m['html'], ' of 10</span>'), 'scores are shown out of 10: overall and per skill');
check(str_contains($m['html'], 'src="cid:bloom-score-ring"') && $m['images'][0]['cid'] === 'bloom-score-ring'
      && ($sz = getimagesizefromstring($m['images'][0]['data'])) && $sz[0] === 720 && $sz[2] === IMAGETYPE_PNG,
      'the score ring travels as an inline 720px PNG');
$cids = array_column($m['images'], 'cid');
check(count($cids) === count(array_unique($cids)), 'each inline image is attached once, even when used twice');
$missing = array_filter($m['images'], fn($im) => !getimagesizefromstring($im['data']));
check(!$missing, 'every inline image is a real PNG');
foreach ($cids as $cid) {
    if (!str_contains($m['html'], 'src="cid:' . $cid . '"')) { $unused = $cid; break; }
}
check(empty($unused) && str_contains($m['html'], 'cid:bloom-heart'), 'every attached image is used in the email (dial, skill icons, buttons, heart)');
// The fixture's skills map to their own icons, never to the fallback.
foreach (['place_value' => 'hash', 'add_sub' => 'plusminus', 'mult_div' => 'times', 'measure' => 'ruler'] as $sid => $want) {
    if (asm_skill_icon($sid) !== $want) { $badIcon = $sid; }
}
check(empty($badIcon) && asm_skill_icon('no_such_skill') === 'book' && asm_skill_icon('number', ['icon' => '../../etc/passwd']) === 'book',
      'skills map to icons; unknown or unsafe icon names fall back to the book');
$realBank = glob(__DIR__ . '/../../assessments/bank/*-v*.json') ?: [];
foreach ($realBank as $file) {
    $bank = json_decode((string)file_get_contents($file), true);
    $used = [];
    foreach ($bank['skills'] as $sk) {
        $icon = asm_skill_icon($sk['id'], $sk);
        if ($icon === 'book') $noIcon[] = basename($file) . "/{$sk['id']}";
        if (isset($used[$icon])) $sharedIcon[] = basename($file) . "/$icon";
        $used[$icon] = true;
    }
}
check(count($realBank) >= 11 && empty($noIcon), 'every skill in the ' . count($realBank) . ' real assessments has its own icon' . (empty($noIcon) ? '' : ': missing ' . implode(', ', $noIcon)));
check(empty($sharedIcon), 'no two skills in one assessment share an icon, so the dial stays readable' . (empty($sharedIcon) ? '' : ': ' . implode(', ', $sharedIcon)));
$pm = asm_report_email($d, true);
check(str_contains($pm['html'], 'src="data:image/png;base64,') && $pm['images'] === [], 'the admin preview inlines the ring so a browser can show it');
check(str_contains($m['html'], 'Strongest in') && str_contains($m['html'], "Let's discuss"), 'the pink card sums up the strongest and weakest skill, with a reply button');
check(str_contains($m['html'], 'Intensive plan') && str_contains($m['html'], '3 lessons a week')
      && str_contains($m['html'], '&pound;169 every 30 days (&pound;14.08 a lesson)'), 'overall score, recommended plan and its price are in the email');
check(str_contains($m['html'], 'clear progress within a term'), 'the reason for the plan is in the email');
check(str_contains($m['html'], 'Lucy counts well but is still unsure'), "skill wording uses the child's name");
check(str_contains($m['html'], '>Strong<') && str_contains($m['html'], '>Developing<') && !str_contains($m['html'], '>Gap<'),
      'parents see Strong / Developing / Focus area, never the word "Gap"');
check(str_contains($m['html'], 'Great effort &lt;b&gt;today&lt;/b&gt;.<br />'), "the tutor's note is escaped and keeps its line breaks");
check(str_contains($m['html'], 'href="mailto:contact@bloomeducationonline.com?subject=') && str_contains($m['html'], 'Reply to get started'),
      'with no payment link saved, the button asks the parent to reply');
check(str_contains($m['html'], 'Bloom Education LLC') && str_contains($m['html'], 'Online tutoring for the UK curriculum'), 'branded footer is included');
check(!str_contains($m['html'], "\u{2014}") && !str_contains($m['text'], "\u{2014}"), 'no em dashes in the email');
check(str_contains($m['text'], 'OUR RECOMMENDATION: the Intensive plan') && str_contains($m['text'], 'To get started, just reply'), 'plain-text version carries the same content');
$d['link'] = 'https://buy.stripe.com/test_abc';
$m = asm_report_email($d);
check(str_contains($m['html'], 'href="https://buy.stripe.com/test_abc?prefilled_email=jo%40example.test"') && str_contains($m['html'], 'Start the Intensive plan'),
      'with a payment link saved, the button goes to it, with the parent email pre-filled');
$d['row']['id'] = 7;
$m = asm_report_email($d);
check(str_contains($m['html'], 'href="https://buy.stripe.com/test_abc?client_reference_id=asm-7&amp;prefilled_email=jo%40example.test"')
      && str_contains($m['text'], 'https://buy.stripe.com/test_abc?client_reference_id=asm-7&prefilled_email=jo%40example.test'),
      "the button carries this assessment's reference, so the payment matches the right child");
$d['row']['parent_name'] = '';
$d['child'] = '';
$m = asm_report_email($d);
check(str_contains($m['html'], 'Hello,') && str_contains($m['subject'], "Your child's"), 'no names: a neutral greeting and subject');

finish('assessment unit');
