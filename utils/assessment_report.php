<?php
/**
 * utils/assessment_report.php
 *
 * The parent's report email: built from an APPROVED attempt's frozen results,
 * sent automatically when the tutor approves, and resendable by an admin.
 *
 * Email HTML is table-based with inline styles, because that is all most mail
 * clients render reliably. Brand colours and footer follow bloom-email-footer.html.
 * The plan button goes to the Stripe payment link saved for that plan in
 * app_settings ("assessment.plan_link.<plan>"); with none saved it becomes a
 * "reply to get started" email link, so the report is never sent with a dead button.
 */

declare(strict_types=1);

require_once __DIR__ . '/assessment.php';

const ASM_SITE_URL   = 'https://bloomeducationonline.com';
const ASM_LOGO_DARK  = ASM_SITE_URL . '/assets/pictures/bloom-logo-mark.png';
const ASM_LOGO_WHITE = ASM_SITE_URL . '/assets/pictures/bloom-logo-white.png';
const ASM_CONTACT    = 'contact@bloomeducationonline.com';

/** Parent-facing band names. "Gap" is the tutor's word; parents read "Focus area". */
const ASM_BAND_EMAIL = [
    'strong'     => ['Strong',     '#E4ECCF', '#3F5213'],
    'developing' => ['Developing', '#FBF0C4', '#6B5300'],
    'gap'        => ['Focus area', '#FBDDEB', '#8A1F4E'],
];

function asm_plan_link(PDO $pdo, string $plan): ?string
{
    try {
        $st = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ?');
        $st->execute(['assessment.plan_link.' . $plan]);
        $v = trim((string)$st->fetchColumn());
        return preg_match('#^https://#i', $v) ? $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

function asm_attempt_pace(array $row): string
{
    return isset(ASM_PACES[$row['pace'] ?? '']) ? (string)$row['pace'] : ASM_DEFAULT_PACE;
}

/** Apply posted "wm_<qid>" marks for written questions, clamped to each question's marks. */
function asm_merge_written(array $a, array $existing, array $post): array
{
    foreach ($a['questions'] as $q) {
        if ($q['type'] !== 'written') continue;
        $v = $post['wm_' . $q['id']] ?? null;
        if ($v !== null && $v !== '' && is_numeric($v)) {
            $existing[$q['id']] = max(0, min((int)$q['marks'], (int)$v));
        }
    }
    return $existing;
}

/**
 * Approve a submitted attempt and (by default) email the parent. Shared by the tutor
 * page and the admin review page so the two can never drift apart. The caller is
 * responsible for checking the approver may act on this attempt.
 *
 * @return array{result:string, message:string, sent?:bool} result is approved | pending | not_submitted | missing
 *         On 'approved', 'sent' says whether the parent email went; callers add how to resend.
 */
function asm_approve_attempt(PDO $pdo, int $attemptId, array $written, string $note, int $approverId, ?callable $sender = null): array
{
    $st = $pdo->prepare('SELECT * FROM assessment_attempts WHERE id = ?');
    $st->execute([$attemptId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['result' => 'missing', 'message' => 'Assessment not found.'];
    $a = asm_load($row['assessment_id'], (int)$row['assessment_version']);
    if (!$a) return ['result' => 'missing', 'message' => 'The question bank file for this assessment is missing.'];
    if ($row['status'] !== 'submitted') {
        return ['result' => 'not_submitted', 'message' => $row['status'] === 'approved'
            ? 'This assessment was already approved.'
            : 'The student has not submitted this assessment yet.'];
    }
    $note = trim(mb_substr($note, 0, 600));
    $res = asm_results($a, asm_decode($row['answers']), $written, asm_attempt_pace($row));
    if ($res['pending'] > 0) {
        $pdo->prepare('UPDATE assessment_attempts SET written_marks = ?, tutor_note = ? WHERE id = ?')
            ->execute([json_encode($written), $note, $attemptId]);
        return ['result' => 'pending', 'message' => 'Please mark every written answer before approving.'];
    }
    $up = $pdo->prepare("UPDATE assessment_attempts
                         SET written_marks = ?, results = ?, recommended_plan = ?, final_plan = ?, tutor_note = ?,
                             status = 'approved', approved_by = ?, approved_at = NOW()
                         WHERE id = ? AND status = 'submitted'");
    $up->execute([json_encode($written), json_encode($res), $res['recommended_plan'], $res['recommended_plan'], $note, $approverId, $attemptId]);
    if ($up->rowCount() !== 1) return ['result' => 'not_submitted', 'message' => 'This assessment was already approved.'];

    [$sent, $msg] = asm_send_report($pdo, $attemptId, null, $sender);
    return ['result' => 'approved', 'sent' => $sent, 'message' => $sent ? 'Approved. ' . $msg : 'Approved, but ' . lcfirst($msg)];
}

/**
 * Everything the email needs about one attempt, or null if it cannot be reported on.
 * With $allowDraft, a submitted-but-not-approved attempt is scored live, so an admin
 * can see the email before approving it.
 */
function asm_report_data(PDO $pdo, int $attemptId, bool $allowDraft = false): ?array
{
    $st = $pdo->prepare("SELECT aa.*, s.first_name AS s_first, s.last_name AS s_last, t.first_name AS t_first, t.last_name AS t_last
                         FROM assessment_attempts aa
                         LEFT JOIN users s ON s.id = aa.student_id
                         LEFT JOIN users t ON t.id = aa.tutor_id
                         WHERE aa.id = ?");
    $st->execute([$attemptId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $a = asm_load($row['assessment_id'], (int)$row['assessment_version']);
    if (!$a) return null;
    $pace = asm_attempt_pace($row);
    if ($row['status'] === 'approved' && $row['results']) {
        $res = asm_decode($row['results']);
        $plan = isset(ASM_PLANS[$row['final_plan']]) ? $row['final_plan'] : ($res['recommended_plan'] ?? 'starter');
    } elseif ($allowDraft && $row['status'] === 'submitted') {
        $res = asm_results($a, asm_decode($row['answers']), asm_decode($row['written_marks']), $pace);
        $plan = $res['recommended_plan'];
    } else {
        return null;
    }
    return [
        'row'   => $row,
        'a'     => $a,
        'res'   => $res,
        'plan'  => $plan,
        'pace'  => $pace,
        'link'  => asm_plan_link($pdo, $plan),
        'child' => trim((string)$row['s_first']),
        'tutor' => trim((string)$row['t_first']),
    ];
}

function asm_report_subject(array $d): string
{
    $title = preg_replace('/\s+diagnostic$/i', '', (string)$d['a']['title']);
    return ($d['child'] !== '' ? $d['child'] . "'s " : 'Your child\'s ') . $title . ' assessment results';
}

/**
 * The report email, laid out like the health-score dashboard Paul chose: the score
 * dial, a black plan pill with round icon buttons, a pink tutor card with a large
 * soft heart, and a compact skills list (icon in a pastel circle, thick bar, "8.3 of
 * 10"), then the recommendation and the footer.
 *
 * Every picture (the dial and the icons) is an inline image: cid: for the mailer, or a
 * data: URI with $preview so a browser can show it on the admin preview page.
 *
 * @return array{subject:string, html:string, text:string, images:array}
 */
function asm_report_email(array $d, bool $preview = false): array
{
    require_once __DIR__ . '/assessment_ring.php';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $F = "-apple-system,BlinkMacSystemFont,'Helvetica Neue',Helvetica,Arial,sans-serif";
    $INK = '#191B18';
    $MUTED = '#6E6F66';
    $row = $d['row'];
    $res = $d['res'];
    $child = $d['child'] !== '' ? $d['child'] : 'your child';
    $Child = $d['child'] !== '' ? $d['child'] : 'Your child';
    $parentFirst = trim(explode(' ', trim((string)$row['parent_name']))[0] ?? '');
    $greeting = $parentFirst !== '' ? "Hi $parentFirst," : 'Hello,';
    $tutor = $d['tutor'] !== '' ? $d['tutor'] : 'your tutor';
    $title = preg_replace('/\s+diagnostic$/i', '', (string)$d['a']['title']);
    $defs = asm_skill_defs($d['a']);
    $plan = ASM_PLANS[$d['plan']];
    $perLesson = number_format($plan['price'] / $plan['lessons'], 2);
    $reason = asm_plan_reason((int)$res['pct'], $d['pace'], $d['child']);
    $score = asm_score10((int)$res['pct']);
    $skills = $res['skills'];
    $nSkills = count($skills);

    // ---- inline images, each added once
    $images = [];
    $src = function (string $cid, string $png) use (&$images, $preview): string {
        if ($preview) return 'data:image/png;base64,' . base64_encode($png);
        $images[$cid] = ['cid' => $cid, 'data' => $png, 'name' => $cid . '.png', 'type' => 'image/png'];
        return 'cid:' . $cid;
    };
    $icon = function (string $name) use ($src): string {
        $png = (string)@file_get_contents(asm_icon_path($name));
        return $src('bloom-icon-' . $name, $png);
    };

    // ---- the pink card's headline: strongest and weakest skill
    $byPct = array_values($skills);
    usort($byPct, fn($x, $y) => $y['pct'] <=> $x['pct']);
    $best = $byPct[0] ?? null;
    $worst = $byPct ? $byPct[count($byPct) - 1] : null;
    $scoreHtml = '<span style="color:#C4577A;">' . $score . ' out of 10</span>';
    if ($best && $worst && $best['pct'] - $worst['pct'] >= 10) {
        $headlineText = "$Child scored $score out of 10. Strongest in " . $best['name'] . ', and the biggest opportunity is ' . $worst['name'] . '.';
        $headlineHtml = $e($Child) . ' scored ' . $scoreHtml . '. Strongest in ' . $e($best['name']) . ', and the biggest opportunity is ' . $e($worst['name']) . '.';
    } elseif ($best && $best['band'] === 'strong') {
        $headlineText = "$Child scored $score out of 10, and is strong across the board.";
        $headlineHtml = $e($Child) . ' scored ' . $scoreHtml . ', and is strong across the board.';
    } else {
        $headlineText = "$Child scored $score out of 10, with room to grow in every area we looked at.";
        $headlineHtml = $e($Child) . ' scored ' . $scoreHtml . ', with room to grow in every area we looked at.';
    }

    if ($d['link']) {
        // client_reference_id ties the Stripe payment back to this child's assessment
        // (integrations/stripe_subscriptions.php), which matters for siblings who share
        // a parent email. prefilled_email saves the parent typing it.
        $params = [];
        if (!empty($row['id'])) $params['client_reference_id'] = 'asm-' . (int)$row['id'];
        if (filter_var((string)($row['parent_email'] ?? ''), FILTER_VALIDATE_EMAIL)) $params['prefilled_email'] = (string)$row['parent_email'];
        $ctaHref = $d['link'] . ($params ? (str_contains($d['link'], '?') ? '&' : '?') . http_build_query($params) : '');
        $ctaText = 'Start the ' . $plan['name'] . ' plan';
    } else {
        $ctaHref = 'mailto:' . ASM_CONTACT . '?subject=' . rawurlencode('Starting the ' . $plan['name'] . ' plan for ' . $Child);
        $ctaText = 'Reply to get started';
    }
    $discussHref = 'mailto:' . ASM_CONTACT . '?subject=' . rawurlencode("$Child's $title results");
    $plansHref = ASM_SITE_URL . '/lesson-policy';
    $loginHref = 'https://portal.bloomeducationonline.com/login.php';

    // ---- the dial
    $iconNames = [];
    foreach ($skills as $sid => $s) $iconNames[] = asm_skill_icon((string)$sid, $defs[$sid] ?? []);
    $ringSrc = $src('bloom-score-ring', asm_ring_png($skills, (int)$res['pct'], $iconNames));

    $round = fn(string $href, string $iconName, string $label) =>
        '<td style="padding:0 4px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="44" height="44" align="center" valign="middle" style="width:44px;height:44px;border-radius:22px;background:#EFECDF;">'
        . '<a href="' . $e($href) . '" title="' . $e($label) . '" style="display:block;line-height:0;"><img src="' . $icon($iconName) . '" width="22" height="22" alt="' . $e($label) . '" style="display:block;border:0;margin:0 auto;"></a>'
        . '</td></tr></table></td>';

    // ---- skills list
    $skillRows = '';
    $skillText = '';
    $i = 0;
    foreach ($skills as $sid => $s) {
        $def = $defs[$sid] ?? [];
        $colour = ASM_RING_COLOURS[$i % count(ASM_RING_COLOURS)];
        [$label, $chipBg, $chipFg] = ASM_BAND_EMAIL[$s['band']] ?? ASM_BAND_EMAIL['developing'];
        $copy = asm_copy((string)($def[$s['band']] ?? ''), $d['child']);
        $next = $s['band'] !== 'strong' ? asm_copy((string)($def['next_step'] ?? ''), $d['child']) : '';
        $pct = max(0, min(100, (int)$s['pct']));
        $fill = max($pct, 4);
        $s10 = asm_score10($pct);
        $iconSrc = $icon($iconNames[$i]);
        $i++;
        $skillRows .= '
      <tr><td style="padding:14px 0 16px;border-bottom:1px solid #ECE6D6;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
          <td width="44" valign="middle" style="padding-right:12px;">
            <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="42" height="42" align="center" valign="middle" style="width:42px;height:42px;border-radius:21px;background:' . $colour . ';">'
              . '<img src="' . $iconSrc . '" width="24" height="24" alt="" style="display:block;border:0;margin:0 auto;"></td></tr></table>
          </td>
          <td valign="middle" style="font-family:' . $F . ';">
            <p style="margin:0 0 8px;font-size:15px;font-weight:600;color:' . $INK . ';">' . $e($s['name']) . '</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:10px;background:#EFEBDC;"><tr>
              <td width="' . $fill . '%" style="height:14px;background:' . $colour . ';border-radius:10px;font-size:0;line-height:0;">&nbsp;</td>
              ' . ($fill < 100 ? '<td style="height:14px;font-size:0;line-height:0;">&nbsp;</td>' : '') . '
            </tr></table>
          </td>
          <td width="86" align="right" valign="bottom" style="padding-left:12px;font-family:' . $F . ';white-space:nowrap;">
            <span style="font-size:26px;font-weight:600;color:' . $INK . ';letter-spacing:-0.6px;">' . $s10 . '</span><span style="font-size:11px;font-weight:500;color:' . $INK . ';"> of 10</span>
          </td>
        </tr></table>
        <p style="margin:12px 0 0 56px;font-family:' . $F . ';font-size:13.5px;line-height:1.6;color:' . $MUTED . ';">
          <span style="display:inline-block;padding:2px 9px;margin-right:6px;border-radius:999px;background:' . $chipBg . ';color:' . $chipFg . ';font-size:11px;font-weight:700;">' . $e($label) . '</span>'
          . $e($copy) . ($next !== '' ? ' ' . $e($next) : '') . '</p>
      </td></tr>';
        $skillText .= "\n$i. " . $s['name'] . ": $s10 of 10 ($label)\n" . $copy . ($next !== '' ? ' ' . $next : '') . "\n";
    }

    $note = trim((string)($row['tutor_note'] ?? ''));
    $subject = asm_report_subject($d);
    $cardLabel = $d['tutor'] !== '' ? "From $tutor, $child's tutor" : 'From your Bloom tutor';
    $heartPng = (string)@file_get_contents(asm_icon_path('heart-deco'));
    $heartSrc = $heartPng !== '' ? $src('bloom-heart', $heartPng) : '';

    $html = '<!DOCTYPE html>
<html lang="en-GB"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>
<body style="margin:0;padding:0;background:#FAF5E7;">
<div style="display:none;max-height:0;overflow:hidden;">' . $e("$Child scored $score out of 10. See every skill and our recommended plan.") . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF5E7;">
<tr><td align="center" style="padding:22px 14px 30px;">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;">

  <tr><td style="padding:0 2px 6px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td valign="middle">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
          <td style="padding-right:8px;"><img src="' . ASM_LOGO_DARK . '" width="32" height="32" alt="Bloom Education" style="display:block;border:0;"></td>
          <td style="font-family:' . $F . ';font-size:21px;font-weight:700;color:' . $INK . ';letter-spacing:-0.5px;">Bloom</td>
        </tr></table>
      </td>
      <td align="right" valign="middle"><span style="display:inline-block;padding:7px 14px;border:1.2px dashed #A6A69A;border-radius:999px;font-family:' . $F . ';font-size:12px;font-weight:600;color:' . $INK . ';">Assessment report</span></td>
    </tr></table>
  </td></tr>

  <tr><td align="center" style="padding:16px 8px 0;font-family:' . $F . ';">
    <h1 style="margin:0 0 6px;font-size:22px;line-height:1.25;font-weight:600;color:' . $INK . ';letter-spacing:-0.4px;">' . $e("$Child's $title results") . '</h1>
    <p style="margin:0;font-size:14.5px;line-height:1.6;color:' . $MUTED . ';">' . $e("$greeting thank you for bringing $child to their free assessment session with $tutor. Here is what we found.") . '</p>
  </td></tr>

  <tr><td align="center" style="padding:8px 0 0;">
    <img src="' . $ringSrc . '" width="340" height="340" alt="' . $e("Overall score $score out of 10") . '" style="display:block;width:340px;max-width:100%;height:auto;border:0;">
  </td></tr>

  <tr><td align="center" style="padding:2px 0 24px;">
    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
      <td style="padding:0 4px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:999px;background:' . $INK . ';">
        <a href="' . $e($ctaHref) . '" style="display:inline-block;padding:12px 20px;font-family:' . $F . ';font-size:14px;font-weight:500;color:#FAF5E7;text-decoration:none;border-radius:999px;white-space:nowrap;">' . $e($ctaText) . '</a>
      </td></tr></table></td>'
      . $round($plansHref, 'calendar', 'All plans')
      . $round($discussHref, 'chat', 'Message us')
      . $round($loginHref, 'user', 'Log in to Bloom') . '
    </tr></table>
  </td></tr>

  <tr><td style="padding:0 0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3BBDF;border-radius:20px;"><tr>
      <td valign="top" style="padding:18px 6px 18px 20px;font-family:' . $F . ';">
        <p style="margin:0 0 7px;font-size:11px;font-weight:650;color:#4C4943;">' . $e($cardLabel) . '</p>
        <p style="margin:0 0 ' . ($note !== '' ? '8' : '14') . 'px;font-size:16px;line-height:1.4;font-weight:600;color:' . $INK . ';letter-spacing:-0.2px;">' . $headlineHtml . '</p>'
        . ($note !== '' ? '<p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:#3A2A32;">' . nl2br($e($note)) . '</p>' : '') . '
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:999px;background:' . $INK . ';">
          <a href="' . $e($discussHref) . '" style="display:inline-block;padding:8px 16px;font-family:' . $F . ';font-size:13px;font-weight:600;color:#FAF5E7;text-decoration:none;border-radius:999px;">Let\'s discuss</a>
        </td></tr></table>
      </td>
      ' . ($heartSrc !== '' ? '<td width="104" valign="bottom" align="right" style="padding:0 14px 14px 0;"><img src="' . $heartSrc . '" width="96" height="86" alt="" style="display:block;border:0;"></td>' : '') . '
    </tr></table>
  </td></tr>

  <tr><td style="padding:0 2px 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td style="font-family:' . $F . ';font-size:22px;font-weight:600;color:' . $INK . ';letter-spacing:-0.4px;">Skills</td>
      <td align="right"><span style="display:inline-block;padding:7px 14px;background:' . $INK . ';border-radius:999px;font-family:' . $F . ';font-size:11px;font-weight:600;color:#FAF5E7;">' . $nSkills . ' areas</span></td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:0 2px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $skillRows . '
    </table>
  </td></tr>

  <tr><td style="padding:26px 0 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#171A17;border-radius:22px;"><tr><td style="padding:26px 24px 24px;font-family:' . $F . ';">
      <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#E5ACCD;">Our recommendation</p>
      <p style="margin:0 0 6px;font-size:26px;font-weight:600;color:#FAF5E7;letter-spacing:-0.5px;">' . $e($plan['name']) . ' plan</p>
      <p style="margin:0 0 12px;font-size:15px;color:#E3E0D8;">' . (int)$plan['per_week'] . ' lesson' . ($plan['per_week'] > 1 ? 's' : '') . ' a week &middot; &pound;' . (int)$plan['price'] . ' every 30 days (&pound;' . $perLesson . ' a lesson)</p>
      <p style="margin:0 0 20px;font-size:13.5px;line-height:1.6;color:#A9A59C;">' . $e($reason) . '</p>
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:999px;background:#F3BBDF;">
        <a href="' . $e($ctaHref) . '" style="display:inline-block;padding:13px 24px;font-family:' . $F . ';font-size:15px;font-weight:600;color:' . $INK . ';text-decoration:none;border-radius:999px;">' . $e($ctaText) . '</a>
      </td></tr></table>
      <p style="margin:18px 0 0;font-size:12.5px;line-height:1.6;color:#8E8A82;">No contract. Cancel anytime. <a href="' . $plansHref . '" style="color:#E3E0D8;">See all plans and how they work</a>.</p>
    </td></tr></table>
  </td></tr>

  <tr><td align="center" style="padding:16px 20px 20px;font-family:' . $F . ';font-size:14px;line-height:1.6;color:' . $MUTED . ';">
    Any questions at all? Just reply to this email and a real person will get back to you.
  </td></tr>

  <tr><td>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#171A17;border-radius:22px;"><tr><td align="center" style="padding:26px 24px 22px;font-family:' . $F . ';">
      <table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr>
        <td style="padding-right:8px;"><img src="' . ASM_LOGO_WHITE . '" width="30" height="30" alt="Bloom Education" style="display:block;border:0;"></td>
        <td style="font-family:' . $F . ';font-size:21px;font-weight:700;color:#FAF5E7;letter-spacing:-0.5px;">Bloom</td>
      </tr></table>
      <p style="margin:6px 0 14px;font-size:12px;color:#8E8A82;">Online tutoring for the UK curriculum</p>
      <p style="margin:0 0 6px;font-size:14px;font-weight:600;color:#E5ACCD;">Get in Touch</p>
      <p style="margin:0;font-size:13.5px;line-height:1.8;"><a href="mailto:' . ASM_CONTACT . '" style="color:#FAF5E7;text-decoration:none;">' . ASM_CONTACT . '</a><br><a href="' . ASM_SITE_URL . '" style="color:#FAF5E7;text-decoration:none;">bloomeducationonline.com</a></p>
      <p style="margin:16px 0 0;padding-top:12px;border-top:1px solid #2C2F2B;font-size:11.5px;line-height:1.7;color:#7C786F;"><strong>Bloom Education LLC</strong><br>1500 N Grant Street, Suite R, Denver, CO 80203, USA</p>
    </td></tr></table>
  </td></tr>

</table>
</td></tr></table>
</body></html>';

    $text = "$greeting\n\n"
        . "Thank you for bringing $child to their free assessment session with $tutor. Here is what we found.\n\n"
        . "Overall score: $score out of 10.\n$headlineText\n"
        . ($note !== '' ? "\nA note from $tutor:\n$note\n" : '')
        . "\nSKILLS\n" . $skillText
        . "\nOUR RECOMMENDATION: the {$plan['name']} plan\n"
        . "{$plan['per_week']} lesson" . ($plan['per_week'] > 1 ? 's' : '') . " a week, £{$plan['price']} every 30 days (£$perLesson a lesson).\n"
        . "$reason\n\n"
        . ($d['link'] ? "Start the plan: $ctaHref\n" : "To get started, just reply to this email.\n")
        . "No contract. Cancel anytime. All plans: $plansHref\n\n"
        . "Bloom Education\n" . ASM_CONTACT . "\n" . ASM_SITE_URL . "\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text, 'images' => array_values($images)];
}

/**
 * Send the report for an approved attempt to the parent (or to $overrideTo for a
 * test copy, which does not touch report_sent_at). Returns [ok, message].
 * $sender is injectable so tests never need a mail server.
 */
function asm_send_report(PDO $pdo, int $attemptId, ?string $overrideTo = null, ?callable $sender = null): array
{
    $d = asm_report_data($pdo, $attemptId);
    if (!$d) return [false, 'This report is not approved yet, or its assessment file is missing.'];
    $to = $overrideTo ?? (string)$d['row']['parent_email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'There is no valid parent email address on this assessment.'];

    $mail = asm_report_email($d);
    if ($sender === null) {
        require_once __DIR__ . '/email_helper.php';
        $sender = fn($to, $name, $subject, $html, $text, $images = []) => send_email($to, $name, $subject, $html, $text, $images);
    }
    try {
        $r = $sender($to, $overrideTo ? '' : (string)$d['row']['parent_name'], $mail['subject'], $mail['html'], $mail['text'], $mail['images']);
        $ok = is_array($r) ? !empty($r['success']) : (bool)$r;
        $msg = is_array($r) ? (string)($r['message'] ?? '') : '';
    } catch (Throwable $e) {
        $ok = false;
        $msg = $e->getMessage();
    }

    if ($overrideTo === null) {
        if ($ok) {
            $pdo->prepare('UPDATE assessment_attempts SET report_sent_at = NOW(), report_error = NULL WHERE id = ?')->execute([$attemptId]);
        } else {
            $pdo->prepare('UPDATE assessment_attempts SET report_error = ? WHERE id = ?')->execute([mb_substr($msg ?: 'Unknown error', 0, 500), $attemptId]);
        }
    }
    if (!$ok) error_log("[assessment report] attempt $attemptId to $to failed: $msg");
    return [$ok, $ok ? "Report emailed to $to." : 'The email could not be sent: ' . ($msg ?: 'unknown error') . '.'];
}
