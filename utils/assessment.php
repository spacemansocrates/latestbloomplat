<?php
/**
 * utils/assessment.php
 *
 * Diagnostic assessments: loading the question bank, scoring an attempt, and
 * turning scores into skill bands and a recommended plan.
 *
 * The bank is assessments/bank/<id>-v<version>.json, written to the spec in
 * docs/assessments/BLUEPRINT.md. Answer keys never leave the server: pages that
 * show questions to a student must go through asm_public_questions().
 */

declare(strict_types=1);

// Tests point this at their own fixtures by defining it before the require.
if (!defined('ASM_BANK_DIR')) define('ASM_BANK_DIR', __DIR__ . '/../assessments/bank');

// Plans offered after the assessment. Prices are GBP per 30 days; keep in step
// with bloomlandingv3/lesson-policy.php and the Stripe products.
const ASM_PLANS = [
    'starter'        => ['name' => 'Starter',    'lessons' => 4,  'per_week' => 1, 'price' => 66],
    'core'           => ['name' => 'Core',       'lessons' => 8,  'per_week' => 2, 'price' => 119],
    'intensive'      => ['name' => 'Intensive',  'lessons' => 12, 'per_week' => 3, 'price' => 169],
    'intensive_plus' => ['name' => 'Intensive+', 'lessons' => 16, 'per_week' => 4, 'price' => 211],
];

const ASM_DEFAULT_BANDS = ['strong' => 75, 'developing' => 40];

// How fast the family wants to see progress, asked when the demo is set up.
// 'step' is how many plan tiers it adds on top of what the score alone suggests.
const ASM_PACES = [
    'steady' => ['label' => 'Steady progress over the school year',           'phrase' => 'steady progress over the school year', 'step' => 0],
    'term'   => ['label' => 'Clear progress within a term',                   'phrase' => 'clear progress within a term',         'step' => 1],
    'fast'   => ['label' => 'As fast as possible, for example an exam is soon', 'phrase' => 'progress as quickly as possible',     'step' => 2],
];
const ASM_DEFAULT_PACE = 'steady';

// School order for lists; unknown stages sort last.
const ASM_STAGE_ORDER = ['KS1', 'KS2', 'KS3', 'GCSE', 'A-Level'];

/** Every assessment in the bank, latest version of each, keyed by id, in school order. */
function asm_list(): array
{
    $out = [];
    foreach (glob(ASM_BANK_DIR . '/*-v*.json') ?: [] as $file) {
        if (!preg_match('/^([a-z0-9]+-[a-z]+)-v(\d+)\.json$/', basename($file), $m)) continue;
        $a = asm_load($m[1], (int)$m[2]);
        if (!$a) continue;
        if (!isset($out[$m[1]]) || $out[$m[1]]['version'] < (int)$m[2]) {
            $out[$m[1]] = [
                'id'      => $m[1],
                'version' => (int)$m[2],
                'title'   => (string)$a['title'],
                'subject' => (string)$a['subject'],
                'stage'   => (string)$a['stage'],
                'minutes' => (int)$a['minutes'],
            ];
        }
    }
    uasort($out, fn($x, $y) => [asm_stage_rank($x['stage']), $x['subject']] <=> [asm_stage_rank($y['stage']), $y['subject']]);
    return $out;
}

function asm_stage_rank(string $stage): int
{
    $i = array_search($stage, ASM_STAGE_ORDER, true);
    return $i === false ? PHP_INT_MAX : (int)$i;
}

/** Load one exact version from the bank, or null if it is missing or unreadable. */
function asm_load(string $id, int $version): ?array
{
    static $cache = [];
    if (!preg_match('/^[a-z0-9]+-[a-z]+$/', $id) || $version < 1) return null;
    $key = "$id-v$version";
    if (array_key_exists($key, $cache)) return $cache[$key];

    $file = ASM_BANK_DIR . "/$key.json";
    $a = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($a) || empty($a['questions']) || empty($a['skills'])) $a = null;
    return $cache[$key] = $a;
}

/** Questions as a student may see them: no answers, no explanations, no rubrics. */
function asm_public_questions(array $a): array
{
    $out = [];
    foreach ($a['questions'] as $q) {
        $p = [
            'id'    => (string)$q['id'],
            'type'  => (string)$q['type'],
            'marks' => (int)$q['marks'],
            'stem'  => (string)$q['stem'],
        ];
        if (isset($q['options']) && in_array($q['type'], ['mcq', 'multi'], true)) $p['options'] = array_values(array_map('strval', $q['options']));
        if (isset($q['unit'])) $p['unit'] = (string)$q['unit'];
        if (isset($q['passage'])) $p['passage'] = (string)$q['passage'];
        $out[] = $p;
    }
    return $out;
}

function asm_passages(array $a): array
{
    $out = [];
    foreach ((array)($a['passages'] ?? []) as $p) {
        if (isset($p['id'])) $out[(string)$p['id']] = ['title' => (string)($p['title'] ?? ''), 'text' => (string)($p['text'] ?? '')];
    }
    return $out;
}

/** Lowercase, collapse whitespace, drop one trailing full stop. */
function asm_normalise_text(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s);
    return rtrim($s, '.');
}

/** "£1,250.50", "12 cm", "-3" -> float, or null if there is no number in it. */
function asm_parse_number(string $s): ?float
{
    $s = str_replace([',', '£', ' '], '', trim($s));
    $s = str_replace("\u{2212}", '-', $s); // unicode minus
    $s = preg_replace('/^[a-z]=/i', '', $s); // "x = 5" -> "5"
    if (!preg_match('/^[-+]?(\d+(\.\d*)?|\.\d+)/', $s, $m)) return null;
    return (float)$m[0];
}

/**
 * Clean a student's raw answers down to the shape each question type expects,
 * dropping unknown question ids. Everything that reaches the database goes
 * through here first.
 */
function asm_clean_answers(array $a, array $raw): array
{
    $out = [];
    foreach ($a['questions'] as $q) {
        $id = (string)$q['id'];
        if (!array_key_exists($id, $raw)) continue;
        $v = $raw[$id];
        switch ($q['type']) {
            case 'mcq':
                if (is_numeric($v) && (int)$v >= 0 && (int)$v < count($q['options'])) $out[$id] = (int)$v;
                break;
            case 'multi':
                if (is_array($v)) {
                    $picks = [];
                    foreach ($v as $x) {
                        if (is_numeric($x) && (int)$x >= 0 && (int)$x < count($q['options'])) $picks[(int)$x] = true;
                    }
                    $picks = array_keys($picks);
                    sort($picks);
                    if ($picks) $out[$id] = $picks;
                }
                break;
            case 'numeric':
            case 'short':
                if (is_scalar($v) && trim((string)$v) !== '') $out[$id] = mb_substr(trim((string)$v), 0, 100);
                break;
            case 'written':
                if (is_string($v) && trim($v) !== '') $out[$id] = mb_substr($v, 0, 6000);
                break;
        }
    }
    return $out;
}

/**
 * Marks for one question. Returns null when the tutor has not marked a written
 * question yet, so it can be shown as pending rather than as zero.
 */
function asm_score_question(array $q, $given, ?int $writtenMark = null): ?int
{
    $marks = (int)$q['marks'];
    switch ($q['type']) {
        case 'mcq':
            return ($given !== null && (int)$given === (int)$q['answer']) ? $marks : 0;
        case 'multi':
            if (!is_array($given)) return 0;
            $want = array_map('intval', (array)$q['answer']);
            $got = array_map('intval', $given);
            sort($want);
            sort($got);
            return $want === $got ? $marks : 0;
        case 'numeric':
            $n = is_scalar($given) ? asm_parse_number((string)$given) : null;
            if ($n === null) return 0;
            $tol = (float)($q['tolerance'] ?? 0);
            return abs($n - (float)$q['answer']) <= $tol + 1e-9 ? $marks : 0;
        case 'short':
            if (!is_scalar($given)) return 0;
            $g = asm_normalise_text((string)$given);
            foreach ((array)$q['answer'] as $ok) {
                if ($g === asm_normalise_text((string)$ok)) return $marks;
            }
            return 0;
        case 'written':
            if ($given === null || (is_string($given) && trim($given) === '')) return 0; // blank earns nothing, no marking needed
            if ($writtenMark === null) return null;
            return max(0, min($marks, $writtenMark));
    }
    return 0;
}

function asm_band(float $pct, array $bands): string
{
    if ($pct >= $bands['strong']) return 'strong';
    if ($pct >= $bands['developing']) return 'developing';
    return 'gap';
}

/**
 * Score a whole attempt.
 *
 * Returns per-question marks, per-skill totals with a band, the gaps (weakest
 * first), the recommended plan, and how many written answers still need marking.
 * A skill whose written question is unmarked is scored on what IS marked and
 * flagged 'pending'; the page must not let the tutor approve until pending is 0.
 */
function asm_results(array $a, array $answers, array $writtenMarks = [], string $pace = ASM_DEFAULT_PACE): array
{
    $bands = array_merge(ASM_DEFAULT_BANDS, (array)($a['bands'] ?? []));
    $skills = [];
    foreach ($a['skills'] as $s) {
        $skills[(string)$s['id']] = ['id' => (string)$s['id'], 'name' => (string)$s['name'], 'earned' => 0, 'available' => 0, 'pending' => 0];
    }

    $questions = [];
    $pending = 0;
    $answered = 0;
    foreach ($a['questions'] as $q) {
        $id = (string)$q['id'];
        $given = $answers[$id] ?? null;
        if ($given !== null) $answered++;
        $wm = isset($writtenMarks[$id]) && is_numeric($writtenMarks[$id]) ? (int)$writtenMarks[$id] : null;
        $got = asm_score_question($q, $given, $wm);
        $sid = (string)$q['skill'];
        if ($got === null) {
            $pending++;
            $skills[$sid]['pending']++;
        } else {
            $skills[$sid]['earned'] += $got;
            $skills[$sid]['available'] += (int)$q['marks'];
        }
        $questions[$id] = ['marks' => $got, 'max' => (int)$q['marks'], 'answered' => $given !== null];
    }

    $earned = 0;
    $available = 0;
    foreach ($skills as &$s) {
        $s['pct'] = $s['available'] ? round(100 * $s['earned'] / $s['available']) : 0;
        $s['band'] = asm_band((float)$s['pct'], $bands);
        $earned += $s['earned'];
        $available += $s['available'];
    }
    unset($s);

    $gaps = array_values(array_filter($skills, fn($s) => $s['band'] === 'gap'));
    usort($gaps, fn($x, $y) => $x['pct'] <=> $y['pct']);

    $pct = $available ? (int)round(100 * $earned / $available) : 0;

    return [
        'skills'           => $skills,
        'questions'        => $questions,
        'earned'           => $earned,
        'available'        => $available,
        'pct'              => $pct,
        'answered'         => $answered,
        'total_questions'  => count($a['questions']),
        'pending'          => $pending,
        'gaps'             => array_column($gaps, 'id'),
        'pace'             => isset(ASM_PACES[$pace]) ? $pace : ASM_DEFAULT_PACE,
        'recommended_plan' => asm_recommend_plan($pct, $pace),
    ];
}

/**
 * The plan comes from two things only: the overall score and how fast the family
 * wants to improve. The tutor cannot change it.
 *   Score 75%+ : Starter (1 a week).  50-74% : Core (2 a week).  Below 50% : Intensive (3 a week).
 *   Then the pace adds 0, 1 or 2 tiers, up to Intensive+ (4 a week).
 */
function asm_recommend_plan(int $overallPct, string $pace): string
{
    $order = array_keys(ASM_PLANS);
    $i = $overallPct >= 75 ? 0 : ($overallPct >= 50 ? 1 : 2);
    $i += ASM_PACES[$pace]['step'] ?? 0;
    return $order[min($i, count($order) - 1)];
}

/** One sentence explaining the recommendation, for the tutor screen and the parent email. */
function asm_plan_reason(int $overallPct, string $pace, string $firstName): string
{
    $name = $firstName !== '' ? $firstName . "'s" : "your child's";
    $phrase = ASM_PACES[$pace]['phrase'] ?? ASM_PACES[ASM_DEFAULT_PACE]['phrase'];
    $v = round(max(0, min(100, $overallPct)) / 10, 1);
    $score = $v == 10.0 ? '10' : number_format($v, 1);
    return "Based on $name overall score of $score out of 10 and your aim of $phrase.";
}

/** Parent-facing skill copy with the child's first name filled in. */
function asm_copy(string $template, string $firstName): string
{
    return str_replace('{name}', $firstName !== '' ? $firstName : 'Your child', $template);
}

/** Skill definitions from the bank, keyed by id. */
function asm_skill_defs(array $a): array
{
    $out = [];
    foreach ($a['skills'] as $s) $out[(string)$s['id']] = $s;
    return $out;
}

/** Human-readable form of the correct answer, for the tutor's review screen. */
function asm_answer_label(array $q): string
{
    switch ($q['type']) {
        case 'mcq':
            return chr(65 + (int)$q['answer']) . '. ' . (string)$q['options'][(int)$q['answer']];
        case 'multi':
            return implode('; ', array_map(fn($i) => chr(65 + (int)$i) . '. ' . (string)$q['options'][(int)$i], (array)$q['answer']));
        case 'numeric':
            $t = (float)($q['tolerance'] ?? 0);
            $unit = (string)($q['unit'] ?? '');
            $n = rtrim(rtrim(number_format((float)$q['answer'], 4, '.', ''), '0'), '.');
            $n = $unit === '£' ? "£$n" : trim("$n $unit");
            return $t > 0 ? "$n (± $t)" : $n;
        case 'short':
            return implode(' / ', (array)$q['answer']);
        case 'written':
            return 'Marked by the tutor';
    }
    return '';
}

/** Human-readable form of what the student gave. */
function asm_given_label(array $q, $given): string
{
    if ($given === null) return '';
    switch ($q['type']) {
        case 'mcq':
            return chr(65 + (int)$given) . '. ' . (string)($q['options'][(int)$given] ?? '');
        case 'multi':
            return implode('; ', array_map(fn($i) => chr(65 + (int)$i) . '. ' . (string)($q['options'][(int)$i] ?? ''), (array)$given));
        default:
            return (string)$given;
    }
}

function asm_decode(?string $json): array
{
    $v = $json ? json_decode($json, true) : null;
    return is_array($v) ? $v : [];
}
