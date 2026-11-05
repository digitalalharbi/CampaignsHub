<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Models\ExternalAccount;

/**
 * MONEY-CURRENCY-NEVER-ASSUMED-001 — the currency a provider's money is actually in, or null.
 *
 * ## The rule, in the order it is applied
 *
 *  1. the ROW's own currency, when the platform labels each figure;
 *  2. else the AD ACCOUNT's currency, as discovery recorded it;
 *  3. else nothing. Null — never the reporting currency.
 *
 * Step 3 used to be `?? $reporting` in three ingest paths (campaign, ad-set/ad and creative grain). It
 * read as a harmless default and was the whole defect: an account stored without a currency had its
 * money taken as already being in dollars and written at rate 1.0. ChatGPT Ads did exactly that to four
 * riyal accounts (OPENAI-ADS-CURRENCY-001). A null here makes {@see ReportingCurrency::normalise()}
 * withhold the figure — original amount kept, no converted value, no currency claimed — and the sync run
 * says so, which is a state somebody can see and fix instead of a wrong number nobody can.
 *
 * Asking the provider for a missing account currency happens once per sync, before this is read — see
 * `AccountCurrencyLookup`. This class only reads; it never calls anything.
 */
final class SourceCurrency
{
    /**
     * @param  array<string,mixed>  $row
     */
    public static function of(array $row, ExternalAccount $account): ?string
    {
        return self::clean($row['currency'] ?? null) ?? self::clean($account->currency);
    }

    /**
     * An ISO 4217 alphabetic code, upper-cased — or null for anything that is not one.
     *
     * Three letters exactly: the columns are `char(3)`, and a provider answering «N/A», an empty string or
     * a number has not stated a currency. Treating those as unknown is the fail-closed reading.
     */
    public static function clean(mixed $currency): ?string
    {
        if (! is_string($currency)) {
            return null;
        }

        $code = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }
}
