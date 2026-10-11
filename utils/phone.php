<?php
/**
 * utils/phone.php
 * Phone-number normalisation to E.164, for WhatsApp/SMS delivery.
 *
 * The users table holds free-text numbers entered over years: "+265 999 12 34 56",
 * "0999123456", "1", "123". Anything we hand to Twilio has to be E.164
 * (+<country><subscriber>, digits only) or the send is rejected and billed as an error.
 *
 * The rule that matters here: NEVER guess a country for a bare national number that
 * has no leading trunk zero. Prefixing the school's country onto an unknown number
 * can deliver a child's lesson schedule to a stranger, so ambiguous input is rejected
 * and reported instead.
 */

declare(strict_types=1);

/**
 * Default country calling code (no '+'), used only for numbers written in local
 * trunk form (leading 0). Malawi (265) matches the school's Africa/Blantyre default.
 */
function phone_default_cc(): string {
    $v = getenv('DEFAULT_COUNTRY_CODE');
    $v = ($v === false) ? '' : preg_replace('/\D/', '', (string)$v);
    return $v !== '' ? $v : '265';
}

/**
 * Normalise a stored phone number to E.164.
 *
 * @return array{ok:bool, e164:?string, reason:?string, assumed:bool}
 *   ok=true  -> e164 is a '+...' string safe to send to. `assumed` is true when the
 *               country code was inferred from a leading trunk zero rather than given
 *               explicitly — right for local numbers, wrong for the handful of users
 *               abroad, so those are worth eyeballing before a first send.
 *   ok=false -> reason is a short machine-ish code: empty, no_digits, too_short,
 *               too_long, ambiguous_no_country, bad_country
 */
function phone_to_e164(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => false, 'e164' => null, 'reason' => 'empty', 'assumed' => false];
    }

    // Keep a leading + if present; drop every other non-digit (spaces, dashes,
    // brackets, and the (0) some people write inside an international number).
    $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    $digits  = preg_replace('/\D/', '', $raw);

    if ($digits === '') {
        return ['ok' => false, 'e164' => null, 'reason' => 'no_digits', 'assumed' => false];
    }

    // 00 is the other way of writing +
    if (!str_starts_with($raw, '+') && str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
        $hasPlus = true;
    }

    if ($hasPlus) {
        // Already international. E.164 allows at most 15 digits including the
        // country code, and no real number is shorter than 8.
        if (strlen($digits) < 8)  return ['ok' => false, 'e164' => null, 'reason' => 'too_short', 'assumed' => false];
        if (strlen($digits) > 15) return ['ok' => false, 'e164' => null, 'reason' => 'too_long', 'assumed' => false];
        if ($digits[0] === '0')   return ['ok' => false, 'e164' => null, 'reason' => 'bad_country', 'assumed' => false];
        return ['ok' => true, 'e164' => '+' . $digits, 'reason' => null, 'assumed' => false];
    }

    // National form with a trunk zero: 0999123456 -> +265999123456
    if (str_starts_with($digits, '0')) {
        $national = ltrim($digits, '0');
        if (strlen($national) < 7)  return ['ok' => false, 'e164' => null, 'reason' => 'too_short', 'assumed' => false];
        $full = phone_default_cc() . $national;
        if (strlen($full) > 15)     return ['ok' => false, 'e164' => null, 'reason' => 'too_long', 'assumed' => false];
        return ['ok' => true, 'e164' => '+' . $full, 'reason' => null, 'assumed' => true];
    }

    // Bare digits, no + and no trunk zero. This is where the junk lives ("1", "123")
    // and also where a genuine national number would be indistinguishable from one
    // that already carries a country code. Guessing here risks messaging a stranger.
    if (strlen($digits) < 8)  return ['ok' => false, 'e164' => null, 'reason' => 'too_short', 'assumed' => false];
    if (strlen($digits) > 15) return ['ok' => false, 'e164' => null, 'reason' => 'too_long', 'assumed' => false];
    return ['ok' => false, 'e164' => null, 'reason' => 'ambiguous_no_country', 'assumed' => false];
}

/** Convenience: the E.164 string, or null when the number is not usable. */
function phone_e164_or_null(?string $raw): ?string {
    $r = phone_to_e164($raw);
    return $r['ok'] ? $r['e164'] : null;
}

/** Human-readable explanation for the settings pages and the coverage report. */
function phone_reason_text(?string $reason): string {
    switch ($reason) {
        case 'empty':                return 'No number saved.';
        case 'no_digits':            return 'That does not look like a phone number.';
        case 'too_short':            return 'Too short to be a real number.';
        case 'too_long':             return 'Too long — more than 15 digits.';
        case 'bad_country':          return 'Starts with 0 after the country code.';
        case 'ambiguous_no_country': return 'Needs a country code, e.g. +265 999 12 34 56.';
        default:                     return 'Not a usable number.';
    }
}
