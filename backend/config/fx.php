<?php

declare(strict_types=1);

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
| This file is the other half, and it is deliberately EMPTY of vendors.
|
| No published rate source is chosen here. Which publisher a deployment
| trusts is a commercial decision with a contract behind it — a central bank
| feed, a paid API, or a treasury desk's own file — and a default baked into
| this repository would be that decision made silently by whoever typed it.
| Every figure in this product would then carry a provenance nobody chose.
|
| So `driver` is null until an operator sets FX_RATE_DRIVER, and the honest
| state of the feed until then is `awaiting_configuration` — NOT «broken»,
| and never a rate invented to fill the gap.
|
| An operator with no feed is not stuck: rates can be recorded by hand from
| /admin, which is a real and attributable source. A hand-entered rate is
| stamped with who entered it for exactly that reason.
|
*/

return [

    'rates' => [

        /*
         * The class that fetches published rates, resolved from the container.
         *
         * It must implement App\Domains\Metrics\Contracts\CurrencyRateSource. Null means no feed is
         * configured, which the console command and the /admin surface both report as such rather
         * than failing.
         */
        'driver' => env('FX_RATE_DRIVER'),

        /*
         * How stale a rate may be before the coverage surface calls it out.
         *
         * Not an expiry: an old rate is still the best evidence available for its own date, and the
         * lookup is nearest-on-or-before by design. This is the age at which an operator should be
         * told the feed has stopped arriving.
         */
        'stale_after_days' => (int) env('FX_RATE_STALE_AFTER_DAYS', 3),
    ],

];
