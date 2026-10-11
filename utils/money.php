<?php
/**
 * Currency formatting shared by the student pages.
 *
 * This lived as three identical copies (student/settings.php,
 * student/payments_all.php, and a call site in student/packages.php that had no
 * copy at all — which was a fatal the moment anything actually reached that
 * page). Guarded so including it alongside an older local copy is harmless.
 */

if (!function_exists('money_symbol')) {
    function money_symbol($code) {
        static $m = [
            'USD' => '$',  'EUR' => '€',   'GBP' => '£',
            'MWK' => 'MK', 'ZAR' => 'R',   'KES' => 'KSh',
            'TZS' => 'TSh',
        ];
        $code = strtoupper(trim((string)$code));
        return $m[$code] ?? ($code !== '' ? $code . ' ' : '');
    }
}

if (!function_exists('billing_currency_code')) {
    /**
     * The currency prices are quoted in. packages.price has no currency column,
     * so this is a single platform-wide setting; it must match whatever the
     * Stripe/PayChangu checkout actually charges, because the webhook compares
     * the paid amount against the package price as bare numbers.
     *
     * Falls back to the viewer's display preference, then USD.
     */
    function billing_currency_code(PDO $pdo, $viewerPreference = null) {
        $candidates = [];
        try {
            // 'billing_currency' is the key this function was written against, but
            // nothing ever writes it — admin_settings.php stores the platform currency
            // as 'org.default_currency', which is what every admin page reads. Looking
            // only at the former meant the student pages fell through to USD ("$10.00")
            // while the admin side correctly showed GBP for the same package.
            $s = $pdo->prepare("SELECT `key`, `value` FROM app_settings WHERE `key` IN ('billing_currency','org.default_currency')");
            $s->execute();
            $found = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) { $found[$row['key']] = $row['value']; }
            $candidates[] = $found['billing_currency']     ?? null;   // explicit override wins
            $candidates[] = $found['org.default_currency'] ?? null;
        } catch (Throwable $e) {
            // app_settings is optional
        }
        $candidates[] = $viewerPreference;

        foreach ($candidates as $c) {
            $c = strtoupper(trim((string)$c));
            if ($c !== '') return $c;
        }
        return 'USD';
    }
}
