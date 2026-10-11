<?php
/**
 * Validate a diagnostic assessment file against docs/assessments/BLUEPRINT.md.
 *
 *   php tools/validate_assessment.php assessments/bank/gcse-maths-v1.json [more files...]
 *
 * Prints ERRORs (the file is unusable or breaks a hard rule) and WARNINGs (it
 * works but goes against the blueprint). Exits 1 if any file has an error.
 * It cannot check that an answer key is correct; solving every question again is
 * still the author's job.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const STAGES = [
    'KS1'  => ['q' => [12, 16], 'years' => [1, 2]],
    'KS2'  => ['q' => [16, 20], 'years' => [3, 4, 5, 6]],
    'KS3'  => ['q' => [18, 24], 'years' => [7, 8, 9]],
    'GCSE' => ['q' => [20, 24], 'years' => [10, 11]],
];
const SUBJECTS = ['maths', 'english', 'science'];
const TYPES    = ['mcq', 'multi', 'numeric', 'short', 'written'];
const COPY     = ['why', 'strong', 'developing', 'gap', 'next_step'];

function validate_assessment(array $a, string $file): array
{
    $err = [];
    $warn = [];
    $E = function (string $m) use (&$err) { $err[] = $m; };
    $W = function (string $m) use (&$warn) { $warn[] = $m; };

    foreach (['id', 'version', 'subject', 'stage', 'years', 'title', 'minutes', 'skills', 'questions'] as $k) {
        if (!array_key_exists($k, $a)) $E("missing top-level field '$k'");
    }
    if ($err) return [$err, $warn];

    $stage = (string)$a['stage'];
    $subject = (string)$a['subject'];
    if (!isset(STAGES[$stage])) $E("stage '$stage' must be one of " . implode(', ', array_keys(STAGES)));
    if (!in_array($subject, SUBJECTS, true)) $E("subject '$subject' must be one of " . implode(', ', SUBJECTS));
    if (!is_int($a['version']) || $a['version'] < 1) $E('version must be a positive integer');

    $expectId = strtolower($stage) . '-' . $subject;
    if ($a['id'] !== $expectId) $E("id should be '$expectId', got '{$a['id']}'");
    $expectFile = $expectId . '-v' . $a['version'] . '.json';
    if (basename($file) !== $expectFile) $W("file name should be '$expectFile'");

    if (isset($a['intro'])) {
        $intro = $a['intro'];
        if (!is_string($intro) || trim($intro) === '') $E('intro must be a non-empty string when present');
        elseif (str_contains($intro, "\u{2014}")) $E('intro contains an em dash');
        elseif (mb_strlen($intro) > 200) $W('intro is ' . mb_strlen($intro) . ' characters (keep it under 200)');
    }

    if (isset($a['bands'])) {
        $s = $a['bands']['strong'] ?? null;
        $d = $a['bands']['developing'] ?? null;
        if (!is_numeric($s) || !is_numeric($d) || $d >= $s || $s > 100 || $d < 0) {
            $E('bands must have 0 <= developing < strong <= 100');
        }
    }

    // Skills
    $skills = [];
    if (!is_array($a['skills']) || count($a['skills']) < 4 || count($a['skills']) > 6) {
        $E('need 4 to 6 skills, found ' . (is_array($a['skills']) ? count($a['skills']) : 0));
    }
    foreach ((array)$a['skills'] as $i => $s) {
        $sid = $s['id'] ?? null;
        if (!$sid) { $E("skill #$i has no id"); continue; }
        if (isset($skills[$sid])) $E("duplicate skill id '$sid'");
        $skills[$sid] = ['q' => 0, 'marks' => 0, 'low' => false, 'high' => false];
        if (empty($s['name'])) $E("skill '$sid' has no name");
        foreach (COPY as $f) {
            $t = (string)($s[$f] ?? '');
            if (trim($t) === '') { $E("skill '$sid' is missing parent copy '$f'"); continue; }
            if (str_contains($t, "\u{2014}")) $E("skill '$sid' $f contains an em dash");
            if (preg_match_all('/[.!?](\s|$)/u', trim($t)) > 2) $W("skill '$sid' $f is longer than 2 sentences");
        }
        foreach (['strong', 'developing', 'gap'] as $f) {
            if (isset($s[$f]) && !str_contains((string)$s[$f], '{name}')) $W("skill '$sid' $f does not use {name}");
        }
    }

    // Passages
    $passages = [];
    foreach ((array)($a['passages'] ?? []) as $i => $p) {
        $pid = $p['id'] ?? null;
        if (!$pid || empty($p['text'])) { $E("passage #$i needs id and text"); continue; }
        $passages[$pid] = 0;
        $words = str_word_count(strip_tags((string)$p['text']));
        if ($words < 120 || $words > 350) $W("passage '$pid' is $words words (aim for 120 to 350)");
    }

    // Questions
    $qs = (array)$a['questions'];
    if (isset(STAGES[$stage])) {
        [$lo, $hi] = STAGES[$stage]['q'];
        if (count($qs) < $lo || count($qs) > $hi) $W("$stage should have $lo to $hi questions, found " . count($qs));
    }
    $ids = [];
    $total = 0;
    $writtenMarks = 0;
    $writtenCount = 0;
    $mcqPos = [0, 0, 0, 0];
    $mcqCount = 0;
    $prevSkill = null;
    $run = 0;
    $prevDiff = 0;
    $drops = 0;

    foreach ($qs as $i => $q) {
        $qid = $q['id'] ?? "#$i";
        $L = "question '$qid'";
        if (!isset($q['id'])) $E("question #$i has no id");
        elseif (isset($ids[$q['id']])) $E("duplicate question id '{$q['id']}'");
        $ids[$q['id'] ?? $i] = true;

        foreach (['skill', 'difficulty', 'type', 'marks', 'stem', 'explain'] as $k) {
            if (!isset($q[$k]) || $q[$k] === '') $E("$L missing '$k'");
        }
        $type = $q['type'] ?? '';
        $marks = $q['marks'] ?? 0;
        if (!in_array($type, TYPES, true)) { $E("$L has unknown type '$type'"); continue; }
        if (!is_int($marks) || $marks < 1) $E("$L marks must be a positive integer");
        $marks = (int)$marks;
        $total += $marks;

        $sid = $q['skill'] ?? '';
        if (!isset($skills[$sid])) $E("$L points at unknown skill '$sid'");
        $d = $q['difficulty'] ?? 0;
        if (!is_int($d) || $d < 1 || $d > 5) $E("$L difficulty must be 1 to 5");

        if (isset($skills[$sid]) && is_int($d)) {
            $skills[$sid]['q']++;
            $skills[$sid]['marks'] += $marks;
            if ($d <= 2) $skills[$sid]['low'] = true;
            if ($d >= 4) $skills[$sid]['high'] = true;
        }
        if (is_int($d)) {
            if ($d < $prevDiff - 1) $drops++;
            $prevDiff = $d;
        }
        $run = ($sid === $prevSkill) ? $run + 1 : 1;
        if ($run === 3) $W("$L is the third question in a row from skill '$sid'");
        $prevSkill = $sid;

        if (isset($q['passage']) && !array_key_exists($q['passage'], $passages)) {
            $E("$L points at unknown passage '{$q['passage']}'");
        } elseif (isset($q['passage'])) {
            $passages[$q['passage']]++;
        }

        $opts = $q['options'] ?? null;
        $ans = $q['answer'] ?? null;
        switch ($type) {
            case 'mcq':
                if (!is_array($opts) || count($opts) !== 4) { $E("$L mcq needs exactly 4 options"); break; }
                if (!is_int($ans) || $ans < 0 || $ans > 3) { $E("$L mcq answer must be an index 0 to 3"); break; }
                if (count(array_unique(array_map('strval', $opts))) !== 4) $E("$L has duplicate options");
                foreach ($opts as $o) {
                    if (preg_match('/\b(all|none) of the above\b/i', (string)$o)) $E("$L uses '" . $o . "'");
                }
                $mcqPos[$ans]++;
                $mcqCount++;
                break;
            case 'multi':
                if (!is_array($opts) || count($opts) < 4 || count($opts) > 6) $E("$L multi needs 4 to 6 options");
                if (!is_array($ans) || !$ans) { $E("$L multi answer must be a non-empty array"); break; }
                foreach ($ans as $x) {
                    if (!is_int($x) || !is_array($opts) || $x < 0 || $x >= count($opts)) $E("$L multi answer index $x out of range");
                }
                if (count(array_unique($ans)) !== count($ans)) $E("$L multi answer has duplicates");
                break;
            case 'numeric':
                if (!is_int($ans) && !is_float($ans)) $E("$L numeric answer must be a number");
                if (isset($q['tolerance']) && (!is_numeric($q['tolerance']) || $q['tolerance'] < 0)) $E("$L tolerance must be >= 0");
                if ($opts) $W("$L numeric question has options; they will be ignored");
                break;
            case 'short':
                if (!is_array($ans) || !$ans) { $E("$L short answer must be a non-empty array of accepted strings"); break; }
                foreach ($ans as $x) if (!is_string($x) || trim($x) === '') $E("$L short answer contains a blank or non-string");
                break;
            case 'written':
                $writtenCount++;
                $writtenMarks += $marks;
                $rub = $q['rubric'] ?? null;
                if (!is_array($rub) || !$rub) { $E("$L written needs a rubric"); break; }
                $sum = 0;
                foreach ($rub as $r) {
                    if (!isset($r['marks'], $r['criterion']) || !is_int($r['marks'])) $E("$L rubric rows need integer marks and a criterion");
                    $sum += (int)($r['marks'] ?? 0);
                }
                if ($sum !== $marks) $E("$L rubric marks add to $sum, but the question is worth $marks");
                if ($ans !== null) $W("$L written question has an answer field; it will be ignored");
                break;
        }

        foreach (['stem', 'explain'] as $f) {
            if (isset($q[$f]) && preg_match('/<img|<svg|\.(png|jpe?g|gif|svg)\b/i', (string)$q[$f])) {
                $E("$L $f references an image; v1 is text only");
            }
        }
    }

    foreach ($skills as $sid => $s) {
        if ($s['q'] < 3) $E("skill '$sid' has {$s['q']} questions (need at least 3)");
        if ($s['marks'] < 3) $E("skill '$sid' has {$s['marks']} marks (need at least 3)");
        if ($s['q'] && !$s['low']) $E("skill '$sid' has no question at difficulty 1 or 2");
        if ($s['q'] && !$s['high']) $E("skill '$sid' has no question at difficulty 4 or 5");
    }
    foreach ($passages as $pid => $n) if ($n === 0) $W("passage '$pid' is not used by any question");

    if ($writtenCount > 2) $E("$writtenCount written questions (max 2)");
    if ($total > 0 && $writtenMarks / $total > 0.25) $E('written questions carry ' . round(100 * $writtenMarks / $total) . '% of marks (max 25%)');
    if ($mcqCount >= 6) {
        foreach ($mcqPos as $pos => $n) {
            if ($n / $mcqCount > 0.4) $W('correct option is ' . chr(65 + $pos) . " in $n of $mcqCount mcqs; spread the positions");
        }
        foreach ($mcqPos as $pos => $n) {
            if ($n === 0) $W('correct option is never ' . chr(65 + $pos));
        }
    }
    if ($drops > 3) $W("difficulty drops sharply $drops times; order questions by rising difficulty");

    return [$err, $warn];
}

$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "usage: php tools/validate_assessment.php <file.json> [...]\n");
    exit(2);
}

$failed = false;
foreach ($files as $file) {
    echo "== $file\n";
    $raw = @file_get_contents($file);
    if ($raw === false) { echo "  ERROR cannot read file\n"; $failed = true; continue; }
    $a = json_decode($raw, true);
    if (!is_array($a)) { echo '  ERROR invalid JSON: ' . json_last_error_msg() . "\n"; $failed = true; continue; }

    [$err, $warn] = validate_assessment($a, $file);
    foreach ($err as $m) echo "  ERROR   $m\n";
    foreach ($warn as $m) echo "  WARNING $m\n";

    $n = count((array)($a['questions'] ?? []));
    $marks = array_sum(array_map(fn($q) => (int)($q['marks'] ?? 0), (array)($a['questions'] ?? [])));
    echo $err ? "  FAIL" : "  PASS", " ($n questions, $marks marks, " . count($warn) . " warnings)\n";
    if ($err) $failed = true;
}
exit($failed ? 1 : 0);
