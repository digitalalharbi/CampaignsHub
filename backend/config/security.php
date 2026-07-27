<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Relax rate limits (LOCAL E2E ONLY)
    |---------------------------------------------------------------------------
    | When true, per-IP throttling is skipped so a single-IP local E2E suite does not hit abuse limits.
    | Defaults to false. It is HARD-IGNORED in production and staging (see ConditionalThrottle) — setting it
    | there can never disable the limits. Leave false everywhere except a local machine running the browser
    | suite.
    */
    'relax_rate_limits' => env('E2E_RELAX_RATE_LIMITS', false),
];
