<?php

declare(strict_types=1);

namespace App\Domains\Funding\Support;

/**
 * BUDGET-DISCLAIMER-001 — the ONE financial disclaimer, for every surface that shows a team member ad spend, budgets,
 * balances or FX-converted money: the figures are for operational follow-up from the ad platforms, not an accounting record.
 *
 * The words live in `lang/{ar,en}/funding.php` (`disclaimer`) and nowhere else; PHP surfaces call `text()`, the web app
 * reads the same text from `GET /funding/disclaimer`, so no surface can carry its own wording.
 * `FundingDisclaimerSourceGuardTest` fails when a funding export or mail template stops carrying it.
 */
final class FundingDisclaimer
{
    public static function text(string $lang = 'ar'): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';

        return (string) __('funding.disclaimer', [], $lang);
    }

    /** @return array{ar: string, en: string} */
    public static function both(): array
    {
        return ['ar' => self::text('ar'), 'en' => self::text('en')];
    }

    /** The currency and conversion note of the financial statement. */
    public static function fxNote(string $lang = 'ar'): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';

        return (string) __('funding.fx_note', [], $lang);
    }
}
