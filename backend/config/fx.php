<?php

declare(strict_types=1);
use App\Domains\Metrics\Rates\EcbReferenceRates;

/*
|--------------------------------------------------------------------------
| FX-FEED-001 — where a rate comes from
|--------------------------------------------------------------------------
|
| FX-001 and COMMERCE-FX-001 built the ENGINE: money is converted into the
| project's reporting currency at ingest, using a dated rate with a named
| source, and a rate nobody can vouch for withholds the figure instead of
| guessing one. That half is verified.
|
| This file is the other half. Owner decision 2026-11-19: the default source is the European Central Bank's published
| euro reference rates (public, free, no key, official), fetched daily by the `fx:rates` schedule. The Gulf pegs
| (SAR, AED…) stay with `OfficialPegs` and the settings; a currency the ECB does not publish is OMITTED, never guessed.
|
| An operator may still choose another publisher with FX_RATE_DRIVER (a class implementing CurrencyRateSource), or set it
| to `none` to switch the feed off — the honest state is then `awaiting_configuration`, never a rate invented to fill
| the gap. Rates can always be recorded by hand from /admin; a hand-entered rate is stamped with who entered it.
|
*/

return [

    'rates' => [

        /*
         * The class that fetches published rates, resolved from the container.
         *
         * It must implement App\Domains\Metrics\Contracts\CurrencyRateSource. Defaults to the ECB reference
         * rates; `none` (null) means no feed is configured, which the console command and the /admin surface both report as such rather
         * than failing.
         */
        'driver' => ($d = env('FX_RATE_DRIVER')) === 'none' ? null : (is_string($d) && $d !== '' ? $d : EcbReferenceRates::class),

        /*
         * How stale a rate may be before it is flagged ⚠ (Owner order, FX-ENGINE-001: 7 days).
         *
         * Not an expiry: an old rate is still the best evidence available for its own date, and the
         * lookup is nearest-on-or-before by design. This is the age at which an operator should be
         * told the feed has stopped arriving.
         */
        'stale_after_days' => (int) env('FX_RATE_STALE_AFTER_DAYS', 7),

        /*
         * PROJECT-LIFECYCLE-001 §7 — the internal admin alert («fx_rate_expiring») is raised this many days before a rate's
         * `effective_to`. An expired rate is still used (flagged «سعر قديم»), so this is a reminder, not a cliff.
         */
        'expiry_warning_days' => (int) env('FX_RATE_EXPIRY_WARNING_DAYS', 3),
    ],

    /*
     * FX-ENGINE-001 — raise the in-app «currency with no rate» alert the moment an ad account is linked in it (the daily
     * fx:monitor sweep raises it regardless). On by default.
     */
    'alert_on_link' => (bool) env('FX_ALERT_ON_LINK', true),

];
